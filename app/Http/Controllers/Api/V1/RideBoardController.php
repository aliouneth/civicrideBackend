<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ride;
use App\Models\RideStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Dispatch board, grouped for scanning. Grouping happens server-side because the
 * board is cursor-paginated: the client only holds one page, so grouping a
 * partial page client-side would mislabel anything outside it.
 */
class RideBoardController extends Controller
{
    private const GROUPINGS = ['day', 'series', 'passenger', 'driver', 'none'];
    private const MAX_RIDES = 200;

    /**
     * Bucket for rides that belong to no series, so they never read as one.
     * Parentheses cannot appear in a ULID (Crockford base32), so this can never
     * collide with a real series_key.
     */
    private const ONE_OFF = "(one-off)";

    public function index(Request $request)
    {
        $data = $request->validate([
            'by' => ['nullable', 'in:'.implode(',', self::GROUPINGS)],
            'days' => ['nullable', 'integer', 'min:1', 'max:120'],
            'past_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_RIDES],
            'q' => ['nullable', 'string', 'max:120'],
            'unassigned' => ['nullable', 'boolean'],
            'series_only' => ['nullable', 'boolean'],
            'include_completed' => ['nullable', 'boolean'],
            // Explicit date range (inclusive, Y-m-d) — overrides days/past_days
            // for whichever bound is present.
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        // validate() checks types but does not cast them: an `integer` rule still yields
        // the raw string, which Carbon's addDays() rejects.
        $by = $data['by'] ?? 'day';
        $days = (int) ($data['days'] ?? 14);
        $pastDays = (int) ($data['past_days'] ?? 1);
        $limit = (int) ($data['limit'] ?? self::MAX_RIDES);
        $q = trim((string) ($data['q'] ?? ''));
        $unassigned = (bool) ($data['unassigned'] ?? false);
        $seriesOnly = (bool) ($data['series_only'] ?? false);
        $includeCompleted = (bool) ($data['include_completed'] ?? false);
        $startDate = $data['start_date'] ?? null;
        $endDate = $data['end_date'] ?? null;

        $openIds = RideStatus::whereIn('code', ['pending', 'assigned', 'en_route', 'picked_up'])->pluck('id');

        $statusIds = $includeCompleted
            ? RideStatus::pluck('id')
            : $openIds;

        // A user-selected date range bounds exactly what they asked for; the
        // days/past_days window only fills in whichever bound they left blank.
        $from = $startDate ? \Illuminate\Support\Carbon::parse($startDate)->startOfDay() : now()->subDays($pastDays);
        $to = $endDate ? \Illuminate\Support\Carbon::parse($endDate)->endOfDay() : now()->addDays($days);

        $rides = Ride::with(['status', 'activeAssignment.driver.user'])
            ->whereIn('status_id', $statusIds)
            ->whereBetween('scheduled_pickup_at', [$from, $to])
            ->when($q !== '', fn (Builder $b) => $b->where(fn (Builder $inner) => $this->match($inner, $q)))
            ->when($unassigned, fn (Builder $b) => $b->whereDoesntHave(
                'assignments', fn ($a) => $a->whereNull('unassigned_at')
            ))
            ->when($seriesOnly, fn (Builder $b) => $b->whereNotNull('series_key'))
            ->orderBy('scheduled_pickup_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'by' => $by,
            'window_days' => $days,
            'past_days' => $pastDays,
            'total' => $rides->count(),
            'truncated' => $rides->count() >= $limit,
            'query' => ['q' => $q ?: null, 'unassigned' => $unassigned, 'series_only' => $seriesOnly, 'include_completed' => $includeCompleted],
            'groups' => $this->group($rides, $by)->values(),
        ]);
    }

    /**
     * Free-text match over the things a dispatcher actually has to hand.
     *
     * A bare number is treated as an identifier, not as text: searching "1" for
     * ride #1 must not return every street containing that digit. Phone is only
     * substring-matched from 4 digits up, since short digit runs match nearly
     * every phone number.
     *
     * LIKE metacharacters in the query are escaped so a literal "%" searches for
     * a percent sign instead of matching every row.
     */
    private function match(Builder $query, string $q): Builder
    {
        $escaped = addcslashes($q, '%_\\');

        if (ctype_digit($q)) {
            return $query->where('id', (int) $q)
                ->when(
                    strlen($q) >= 4,
                    fn (Builder $b) => $b->orWhereRaw("LOWER(passenger_info->>'$.phone') LIKE ?", ['%'.mb_strtolower($escaped).'%'])
                );
        }

        $like = '%'.$escaped.'%';
        // utf8mb4 columns already compare case-insensitively, but JSON extracts
        // come back utf8mb4_bin — so the name needs LOWER() on both sides.
        $jsonLike = '%'.mb_strtolower($like).'%';

        return $query
            ->where('pickup_address', 'like', $like)
            ->orWhere('dropoff_address', 'like', $like)
            ->orWhere('series_label', 'like', $like)
            ->orWhereRaw("LOWER(passenger_info->>'$.name') LIKE ?", [$jsonLike]);
    }

    private function group($rides, string $by)
    {
        if ($by === 'none') {
            return collect([[
                'key' => 'all',
                'label' => 'Open rides',
                'count' => $rides->count(),
                'rides' => $rides,
            ]]);
        }

        $keyed = $rides->groupBy(fn (Ride $r) => $this->keyFor($r, $by));
        $out = [];

        foreach ($keyed as $key => $group) {
            $out[] = [
                'key' => $key,
                'label' => $this->labelFor($group->first(), $by),
                'count' => $group->count(),
                // Series groups surface which occurrence this is (2 of 8).
                'meta' => $this->metaFor($group, $by),
                'rides' => $group->values(),
            ];
        }

        return collect($out);
    }

    private function keyFor(Ride $ride, string $by): string
    {
        return match ($by) {
            'day' => $ride->scheduled_pickup_at->format('Y-m-d'),
            'series' => $ride->series_key ?? self::ONE_OFF,
            'passenger' => strtolower(trim($ride->passenger_info['name'] ?? '')).'|'.($ride->passenger_info['phone'] ?? ''),
            'driver' => (string) ($ride->activeAssignment?->driver?->id ?? 0),
        };
    }

    private function labelFor(Ride $ride, string $by): string
    {
        return match ($by) {
            'day' => $ride->scheduled_pickup_at->format('D, M j'),
            'series' => $ride->series_key ? ($ride->series_label ?: 'Unnamed series') : 'One-off rides',
            'passenger' => $ride->passenger_info['name'] ?? 'Unknown passenger',
            'driver' => $ride->activeAssignment?->driver?->user?->name ?? 'Unassigned',
        };
    }

    /** Per-group detail the UI shows alongside the count. */
    private function metaFor($group, string $by): array
    {
        if ($by === 'series') {
            $first = $group->first();
            if (! $first->series_key) {
                // Ad-hoc bookings, deliberately not presented as a series.
                return [];
            }

            $indices = $group->pluck('occurrence_index')->filter(fn ($i) => $i !== null);

            return [
                'series_key' => $first->series_key,
                'occurrence_of' => $indices->isEmpty() ? null : (int) $indices->max() + 1,
                'next_pickup_at' => $group->min('scheduled_pickup_at')?->toIso8601String(),
            ];
        }

        if ($by === 'passenger') {
            return [
                'phone' => $group->first()->passenger_info['phone'] ?? null,
                'series_count' => $group->pluck('series_key')->filter()->unique()->count(),
            ];
        }

        if ($by === 'driver') {
            return [
                'series_count' => $group->pluck('series_key')->filter()->unique()->count(),
            ];
        }

        return [];
    }
}