<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ride;
use App\Models\RideStatus;
use App\Services\RideTransitionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recurring-series administration. Occurrences share a `series_key`, so every
 * action here is a bulk action over that set — each occurrence still goes through
 * the normal lifecycle (audit rows, locks, broadcast) via RideTransitionService.
 */
class RideSeriesController extends Controller
{
    public function __construct(private RideTransitionService $transitions) {}

    /** Statuses that have not finished yet and can still be changed in bulk. */
    private const OPEN_CODES = ['pending', 'assigned', 'en_route', 'picked_up'];

    /** Everything a groups list needs, in one grouped query (no N+1). */
    public function index()
    {
        $statusIds = RideStatus::whereIn('code', self::OPEN_CODES)->pluck('id', 'code');

        $series = Ride::query()
            ->whereNotNull('series_key')
            ->selectRaw('series_key, MIN(series_label) AS series_label, MIN(recurrence) AS recurrence')
            ->selectRaw('COUNT(*) AS occurrences')
            ->selectRaw('MIN(scheduled_pickup_at) AS first_pickup_at')
            ->selectRaw('MAX(scheduled_pickup_at) AS last_pickup_at')
            ->selectRaw('SUM(CASE WHEN status_id IN (?, ?, ?, ?) THEN 1 ELSE 0 END) AS open_count',
                [($statusIds['pending']), ($statusIds['assigned']), ($statusIds['en_route']), ($statusIds['picked_up'])])
            ->selectRaw('COALESCE(SUM(price_cents), 0) AS total_price_cents')
            ->groupBy('series_key')
            // Aggregate expressions only: under only_full_group_by an ORDER BY on
            // the bare `series_label` column is rejected, even though it is
            // aliased to MIN(...) in the SELECT list.
            ->orderByRaw('MIN(series_label) IS NULL ASC, MIN(series_label) ASC')
            ->orderByRaw('MIN(scheduled_pickup_at) ASC')
            ->get();

        // Next still-open pickup per series, in one extra query rather than N.
        $nextOpen = Ride::query()
            ->whereNotNull('series_key')
            ->whereIn('status_id', $statusIds->values())
            ->selectRaw('series_key, MIN(scheduled_pickup_at) AS next_pickup_at')
            ->groupBy('series_key')
            ->pluck('next_pickup_at', 'series_key');

        return response()->json([
            'data' => $series->map(fn ($s) => [
                'series_key' => $s->series_key,
                'label' => $s->series_label,
                'recurrence' => $this->decodeRule($s->recurrence),
                'occurrences' => (int) $s->occurrences,
                'open_count' => (int) $s->open_count,
                'first_pickup_at' => $s->first_pickup_at,
                'last_pickup_at' => $s->last_pickup_at,
                'next_pickup_at' => $nextOpen[$s->series_key] ?? null,
                'total_price_cents' => (int) $s->total_price_cents,
            ])->values(),
        ]);
    }

    /** One series with every occurrence, oldest first. */
    public function show(string $seriesKey)
    {
        $rides = $this->seriesQuery($seriesKey)->get();
        $this->abortIfMissing($rides);

        return response()->json([
            'series_key' => $seriesKey,
            'label' => $rides->first()->series_label,
            'recurrence' => $rides->first()->recurrence,
            'occurrences' => $rides->count(),
            'rides' => $rides,
        ]);
    }

    /**
     * Cancel every occurrence that has not reached a terminal state. Occurrences
     * already completed or cancelled are left alone and reported back as skipped,
     * so the dispatcher sees exactly what the action did.
     */
    public function cancel(Request $request, string $seriesKey)
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $rides = $this->seriesQuery($seriesKey)->get();
        $this->abortIfMissing($rides);

        [$cancelled, $skipped] = DB::transaction(function () use ($rides, $request, $data, $seriesKey) {
            $cancelled = 0;
            $skipped = 0;

            foreach ($rides as $ride) {
                // en_route/picked_up can be cancelled per the lifecycle graph, but
                // a ride already completed or cancelled cannot.
                if (in_array($ride->status->code, ['completed', 'cancelled'], true)) {
                    $skipped++;

                    continue;
                }

                $this->transitions->transition($ride, 'cancelled', $request->user(), [
                    'reason' => $data['reason'] ?? 'Cancelled with the rest of the series.',
                    'series_key' => $seriesKey,
                ]);
                $cancelled++;
            }

            return [$cancelled, $skipped];
        });

        return response()->json([
            'series_key' => $seriesKey,
            'cancelled' => $cancelled,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Shift the whole series by a number of days, preserving the spacing between
     * occurrences. Only occurrences not yet in motion move: an en_route or
     * picked_up ride has a driver committed to its current time.
     */
    public function shift(Request $request, string $seriesKey)
    {
        $data = $request->validate([
            'days' => ['required', 'integer', 'min:-365', 'max:365'],
        ]);

        $rides = $this->seriesQuery($seriesKey)->get();
        $this->abortIfMissing($rides);

        $days = (int) $data['days'];
        if ($days === 0) {
            throw ValidationException::withMessages(['days' => ['Enter a non-zero number of days.']]);
        }

        [$shifted, $skipped] = DB::transaction(function () use ($rides, $days) {
            $shifted = 0;
            $skipped = 0;

            foreach ($rides as $ride) {
                if (in_array($ride->status->code, ['en_route', 'picked_up', 'completed', 'cancelled'], true)) {
                    $skipped++;

                    continue;
                }

                $ride->scheduled_pickup_at = $ride->scheduled_pickup_at->copy()->addDays($days);
                // A roundtrip occurrence keeps its own return gap: move the
                // return time by the same shift so the pair stays consistent.
                if ($ride->return_pickup_at !== null) {
                    $ride->return_pickup_at = $ride->return_pickup_at->copy()->addDays($days);
                }
                $ride->save();
                $shifted++;
            }

            return [$shifted, $skipped];
        });

        return response()->json([
            'series_key' => $seriesKey,
            'days' => $days,
            'shifted' => $shifted,
            'skipped' => $skipped,
        ]);
    }

    /** Rename a series. Applied to every occurrence so the label stays consistent. */
    public function update(Request $request, string $seriesKey)
    {
        $data = $request->validate([
            'label' => ['present', 'nullable', 'string', 'max:120'],
        ]);

        $rides = $this->seriesQuery($seriesKey)->get();
        $this->abortIfMissing($rides);

        Ride::whereIn('id', $rides->pluck('id'))->update(['series_label' => $data['label']]);

        return response()->json(['series_key' => $seriesKey, 'label' => $data['label']]);
    }

    private function seriesQuery(string $seriesKey): Builder
    {
        // Ride is tenant-scoped, so a foreign series key simply matches nothing.
        return Ride::with(['status', 'activeAssignment.driver.user'])
            ->where('series_key', $seriesKey)
            ->orderBy('scheduled_pickup_at')
            ->orderBy('occurrence_index');
    }

    private function abortIfMissing($rides): void
    {
        abort_if($rides->isEmpty(), 404, 'No such ride series in this agency.');
    }

    /**
     * MIN() over a JSON column comes back already decoded on some drivers and as
     * raw JSON on others, so accept either rather than assuming a string.
     */
    private function decodeRule(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        return is_string($value) ? json_decode($value, true) : null;
    }
}