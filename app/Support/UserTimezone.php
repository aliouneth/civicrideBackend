<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The clock the requester is looking at.
 *
 * Users may be anywhere in the US or Canada while the agency (and the server)
 * sit in a different zone. Every date/time decision therefore resolves through
 * here: an explicit X-Timezone header (the browser / phone zone), the agency's
 * configured zone, or UTC as the last resort.
 *
 * Ride timestamps are stored as UTC instants, so day and month windows are
 * computed in the requester's zone and converted back to UTC for querying.
 */
final class UserTimezone
{
    public const HEADER = 'X-Timezone';

    /** Resolve the request's zone: header -> agency setting -> UTC. */
    public static function fromRequest(Request $request): string
    {
        $candidate = $request->header(self::HEADER);

        if (self::isValid($candidate)) {
            return trim((string) $candidate);
        }

        return self::tenantDefault();
    }

    /** The agency's configured zone (jobs and other request-less contexts). */
    public static function tenantDefault(): string
    {
        $tenant = app()->bound('tenant') ? app('tenant') : null;
        $zone = $tenant?->settings?->timezone;

        return self::isValid($zone) ? (string) $zone : 'UTC';
    }

    public static function isValid(mixed $timezone): bool
    {
        return is_string($timezone)
            && $timezone !== ''
            && in_array($timezone, timezone_identifiers_list(), true);
    }

    /**
     * The local day that contains $moment, as a UTC [start, end) pair.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function dayBounds(string $timezone, ?CarbonInterface $moment = null): array
    {
        $start = ($moment !== null ? $moment->copy() : Carbon::now())
            ->setTimezone($timezone)
            ->startOfDay();

        return [
            $start->copy()->utc(),
            $start->copy()->addDay()->utc(),
        ];
    }

    /**
     * A YYYY-MM calendar month in the requester's zone, as a UTC [start, end).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function monthBounds(string $timezone, string $month): array
    {
        [$year, $monthNumber] = array_map('intval', explode('-', $month));

        $start = Carbon::create($year, $monthNumber, 1, 0, 0, 0, $timezone);

        return [
            $start->copy()->utc(),
            $start->copy()->addMonthNoOverflow()->utc(),
        ];
    }

    /**
     * Normalize an inbound date/time value to a UTC instant.
     *
     * An explicit offset (ISO 8601 with Z/+hh:mm) wins. A naive value comes
     * from a datetime-local input, i.e. the requester's wall clock, so it is
     * interpreted in their zone before being stored as UTC.
     */
    public static function toUtc(?string $value, string $timezone): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $value) === 1) {
            return Carbon::parse($value)->utc()->toDateTimeString();
        }

        $zone = self::isValid($timezone) ? $timezone : 'UTC';

        return Carbon::parse($value, $zone)->utc()->toDateTimeString();
    }
}
