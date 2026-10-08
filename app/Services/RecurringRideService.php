<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Expands a recurring ride request into concrete pickup datetimes.
 *
 * Deliberately pure: it takes the first pickup instant and a rule and returns
 * the *subsequent* instants. Persistence and tenancy belong to the caller, so
 * this stays trivially testable and cannot leak a tenant_id into the rule maths.
 */
class RecurringRideService
{
    /** Hard ceiling per series — a scheduler form must not be able to create 10k rides. */
    public const MAX_OCCURRENCES = 30;

    private const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    /**
     * @param  array{frequency: string, interval: int, count?: int|null, until?: string|null}  $rule
     * @return list<CarbonImmutable> subsequent instants, in ascending order (may be empty)
     */
    public function expand(CarbonImmutable $first, array $rule): array
    {
        $frequency = $rule['frequency'];
        if (! in_array($frequency, self::FREQUENCIES, true)) {
            throw new \InvalidArgumentException("Unsupported recurrence frequency [{$frequency}].");
        }

        $interval = max(1, (int) $rule['interval']);
        $limit = min($this->resolveLimit($first, $rule), self::MAX_OCCURRENCES);
        if ($limit <= 1) {
            return [];
        }

        $bound = ! empty($rule['until']) ? CarbonImmutable::parse($rule['until'])->endOfDay() : null;

        $instants = [];
        for ($i = 1; $i < $limit; $i++) {
            // Always advance from the anchor, never from the previous occurrence:
            // chaining would pin a 31 Jan monthly series to the 28th for good once
            // it passes through February. addMonthsNoOverflow instead gives
            // 31 Jan -> 28 Feb -> 31 Mar -> 30 Apr.
            $at = $this->advance($first, $frequency, $interval * $i);

            if ($bound !== null && $at->gt($bound)) {
                break;
            }

            $instants[] = $at;
        }

        return $instants;
    }

    /** A fresh series key; every occurrence of one series shares it. */
    public function seriesKey(): string
    {
        return (string) Str::ulid();
    }

    /** Total occurrences requested, from either an explicit count or an end date. */
    private function resolveLimit(CarbonImmutable $first, array $rule): int
    {
        if (! empty($rule['count'])) {
            return (int) $rule['count'];
        }

        if (! empty($rule['until'])) {
            $last = CarbonImmutable::parse($rule['until'])->endOfDay();
            $interval = max(1, (int) $rule['interval']);
            $days = (int) abs($first->diffInDays($last));

            // Bound by the *shortest* span (daily) so "daily until next year"
            // cannot fan out to hundreds of rides before the cap trims it.
            $span = match ($rule['frequency']) {
                'weekly' => intdiv($days, 7 * $interval),
                'monthly' => (int) abs($first->diffInMonths($last)) / $interval,
                default => intdiv($days, $interval),
            };

            return min((int) $span + 1, self::MAX_OCCURRENCES);
        }

        return 1;
    }

    private function advance(CarbonImmutable $anchor, string $frequency, int $steps): CarbonImmutable
    {
        return match ($frequency) {
            'daily' => $anchor->addDays($steps),
            'weekly' => $anchor->addWeeks($steps),
            'monthly' => $anchor->addMonthsNoOverflow($steps),
        };
    }
}