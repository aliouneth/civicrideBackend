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
     * special-casing. Responds with the first occurrence plus series_size.
     */
    public function store(StoreRideRequest $request)
    {
        $rule = $request->recurrenceRule();
        $data = $request->validated();
        unset($data['recurrence']);

        $statusId = RideStatus::where('code', 'pending')->firstOrFail()->id;
        $seriesKey = $rule ? $this->recurring->seriesKey() : null;

        // A series is all-or-nothing: a half-written recurring booking would sit
        // on the board missing its later occurrences, so commit or roll back.
        [$first, $size] = DB::transaction(function () use ($request, $data, $rule, $statusId, $seriesKey) {
            $base = [
                ...$data,
                'status_id' => $statusId,
                'requested_by' => $request->user()->id,
                'series_key' => $seriesKey,
                'series_label' => $rule['label'] ?? null,
                'recurrence' => $rule,
            ];

            $first = Ride::create([...$base, 'occurrence_index' => $rule ? 0 : null]);

            $rest = $rule
                ? $this->recurring->expand(CarbonImmutable::parse($data['scheduled_pickup_at']), $rule)
                : [];

            foreach ($rest as $index => $at) {
                Ride::create([...$base, 'occurrence_index' => $index + 1, 'scheduled_pickup_at' => $at]);
            }

            return [$first, 1 + count($rest)];
        });

        return response()->json([
            ...$first->fresh(['status'])->toArray(),
            'series_size' => $size,
        ], 201);
    }

    public function show(Ride $ride)
    {
        // Implicit binding is already tenant-scoped → foreign-tenant ids 404.
        return response()->json($ride->load([
            'status', 'requester', 'assignments.driver.user', 'events.toStatus', 'events.actor',
        ]));
    }

    /**
     * Correct a booking's details. Refused once the ride is in motion or finished:
     * the route and time are committed by then, and the driver may already be
     * driving them. Status, assignments and series membership are not editable
     * here — they go through the lifecycle/assignment endpoints so audit rows
     * are always written.
     */
    public function update(UpdateRideRequest $request, Ride $ride)
    {
        if (! in_array($ride->status->code, UpdateRideRequest::EDITABLE_STATUSES, true)) {
            return response()->json([
                'message' => "A {$ride->status->code} ride can no longer be edited.",
            ], 409);
        }

        $ride->fill($request->validated())->save();

        return response()->json($ride->fresh(['status', 'activeAssignment']));
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