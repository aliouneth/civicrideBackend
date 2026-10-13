<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRideRequest;
use App\Http\Requests\TransitionRideRequest;
use App\Http\Requests\UpdateRideRequest;
use App\Models\Ride;
use App\Models\RideStatus;
use App\Services\RecurringRideService;
use App\Services\RideTransitionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RideController extends Controller
{
    public function __construct(
        private RideTransitionService $transitions,
        private RecurringRideService $recurring,
    ) {}

    /** Dispatch board feed: filterable, cursor-paginated, N+1-free. */
    public function index(Request $request)
    {
        $query = Ride::with(['status', 'activeAssignment.driver.user'])
            ->when($request->status, fn ($q, $code) => $q->whereHas('status', fn ($s) => $s->where('code', $code)))
            ->when($request->driver_profile_id, fn ($q, $id) => $q->whereHas(
                'assignments', fn ($a) => $a->where('driver_profile_id', $id)->whereNull('unassigned_at')
            ))
            ->when($request->date, fn ($q, $date) => $q->whereDate('scheduled_pickup_at', $date))
            ->when($request->series_key, fn ($q, $key) => $q->where('series_key', $key))
            ->orderBy('scheduled_pickup_at')
            ->orderBy('id');

        return response()->json($query->cursorPaginate(25));
    }

    /**
     * Create a ride. With `recurrence`, expands into a series of concrete rides
     * sharing a series_key so the board, assignment and driver flow need no
     * special-casing. A `roundtrip` ride either keeps its return on the one ride
     * (`return_pickup_at`) or — with `split_return` — gains a linked return leg
     * with the route swapped. Responds with the first occurrence plus
     * series_size, and the return leg when one was created.
     */
    public function store(StoreRideRequest $request)
    {
        $rule = $request->recurrenceRule();
        $data = $request->validated();
        unset($data['recurrence']);

        $split = (bool) ($data['split_return'] ?? false);
        unset($data['split_return']);
        $data['trip_type'] ??= 'one_way';

        $statusId = RideStatus::where('code', 'pending')->firstOrFail()->id;
        $seriesKey = $rule ? $this->recurring->seriesKey() : null;

        // A series (or an outbound + return pair) is all-or-nothing: a
        // half-written booking would sit on the board missing its other half,
        // so commit or roll back.
        [$first, $size, $return] = DB::transaction(function () use ($request, $data, $rule, $statusId, $seriesKey, $split) {
            $base = [
                ...$data,
                'status_id' => $statusId,
                'requested_by' => $request->user()->id,
                'series_key' => $seriesKey,
                'series_label' => $rule['label'] ?? null,
                'recurrence' => $rule,
            ];

            $first = Ride::create([...$base, 'occurrence_index' => $rule ? 0 : null]);

            $pickupAt = CarbonImmutable::parse($data['scheduled_pickup_at']);
            $rest = $rule
                ? $this->recurring->expand($pickupAt, $rule)
                : [];

            // A recurring roundtrip keeps one return per occurrence, offset from
            // its own pickup by the same gap as the first ride.
            $returnOffset = isset($data['return_pickup_at'])
                ? CarbonImmutable::parse($data['scheduled_pickup_at'])->diffInSeconds(CarbonImmutable::parse($data['return_pickup_at']), true)
                : null;

            foreach ($rest as $index => $at) {
                Ride::create([
                    ...$base,
                    'occurrence_index' => $index + 1,
                    'scheduled_pickup_at' => $at,
                    'return_pickup_at' => $returnOffset !== null ? $at->addSeconds($returnOffset)->toDateTimeString() : null,
                ]);
            }

            $return = $split ? $this->createReturnLeg($first) : null;

            return [$first, 1 + count($rest), $return];
        });

        return response()->json([
            ...$first->fresh(['status'])->toArray(),
            'series_size' => $size,
            'return_ride' => $return?->fresh(['status'])->toArray(),
        ], 201);
    }

    /**
     * The return half of a split roundtrip: same booking details, route swapped,
     * picked up at the outbound's return time. An ordinary one-way ride otherwise.
     */
    private function createReturnLeg(Ride $outbound): Ride
    {
        // Pull DB defaults (priority 0, currency) onto the model: the in-memory
        // outbound never had them set, and the return leg copies them verbatim.
        $outbound->refresh();        $return = Ride::create([
            'status_id' => $outbound->status_id,
            'requested_by' => $outbound->requested_by,
            'passenger_info' => $outbound->passenger_info,
            'pickup_address' => $outbound->dropoff_address,
            'pickup_lat' => $outbound->dropoff_lat,
            'pickup_lng' => $outbound->dropoff_lng,
            'dropoff_address' => $outbound->pickup_address,
            'dropoff_lat' => $outbound->pickup_lat,
            'dropoff_lng' => $outbound->pickup_lng,
            'scheduled_pickup_at' => $outbound->return_pickup_at,
            'priority' => $outbound->priority,
            'notes' => $outbound->notes,
            'currency' => $outbound->currency,
            'trip_type' => 'one_way',
        ]);

        $outbound->update(['return_ride_id' => $return->id]);

        return $return;
    }

    public function show(Ride $ride)
    {
        // Implicit binding is already tenant-scoped → foreign-tenant ids 404.
        return response()->json($ride->load([
            'status', 'requester', 'assignments.driver.user', 'events.toStatus', 'events.actor',
            'returnRide.status', 'outboundRide.status',
        ]));
    }

    /**
     * Correct a booking's details. Refused once the ride is in motion or finished:
     * the route and time are committed by then, and the driver may already be
     * driving them. Status, assignments and series membership are not editable
     * here — they go through the lifecycle/assignment endpoints so audit rows
     * are always written.
     *
     * A moved return time moves the linked return leg with it (and vice versa),
     * so the pair can never disagree. Refused when the linked ride is already
     * in motion — change one half of a started trip would strand the driver.
     */
    public function update(UpdateRideRequest $request, Ride $ride)
    {
        if (! in_array($ride->status->code, UpdateRideRequest::EDITABLE_STATUSES, true)) {
            return response()->json([
                'message' => "A {$ride->status->code} ride can no longer be edited.",
            ], 409);
        }

        $data = $request->validated();
        $returnRide = $ride->returnRide;
        $outboundRide = $ride->outboundRide;

        if (isset($data['return_pickup_at']) && $returnRide !== null
            && ! $this->isEditable($returnRide)
            && ! $this->sameInstant($returnRide->scheduled_pickup_at, $data['return_pickup_at'])) {
            return response()->json([
                'message' => "The return trip (#{$returnRide->id}) is already {$returnRide->status->code} and keeps its time.",
            ], 409);
        }

        if (isset($data['scheduled_pickup_at']) && $outboundRide !== null
            && ! $this->isEditable($outboundRide)
            && ! $this->sameInstant($outboundRide->return_pickup_at, $data['scheduled_pickup_at'])) {
            return response()->json([
                'message' => "The outbound ride (#{$outboundRide->id}) is already {$outboundRide->status->code} and keeps its return time.",
            ], 409);
        }

        DB::transaction(function () use ($ride, $data, $returnRide, $outboundRide) {
            $ride->fill($data)->save();

            if (isset($data['return_pickup_at']) && $returnRide !== null) {
                $returnRide->update(['scheduled_pickup_at' => $data['return_pickup_at']]);
            }

            if (isset($data['scheduled_pickup_at']) && $outboundRide !== null) {
                $outboundRide->update(['return_pickup_at' => $data['scheduled_pickup_at']]);
            }
        });

        return response()->json($ride->fresh(['status', 'activeAssignment', 'returnRide.status']));
    }

    private function isEditable(Ride $ride): bool
    {
        return in_array($ride->status->code, UpdateRideRequest::EDITABLE_STATUSES, true);
    }

    private function sameInstant(mixed $current, mixed $incoming): bool
    {
        if ($current === null || $incoming === null) {
            return $current === $incoming;
        }

        return CarbonImmutable::parse($current)->eq(CarbonImmutable::parse($incoming));
    }

    /** Execute a lifecycle step (start ride, pickup, drop-off, cancel). */
    public function transition(TransitionRideRequest $request, Ride $ride)
    {
        $data = $request->validated();

        // Cancelling is a dispatcher privilege even though drivers execute rides.
        if (($data['to_code'] ?? null) === 'cancelled' && ! $request->user()->canDo('rides.cancel')) {
            return response()->json(['message' => 'Only dispatchers can cancel rides.'], 403);
        }

        $updated = $this->transitions->transition($ride, $data['to_code'], $request->user(), [
            'note' => $data['note'] ?? null,
            'incident' => $data['incident'] ?? null,
            'reason' => $data['reason'] ?? null,
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
        ]);

        return response()->json($updated);
    }
}