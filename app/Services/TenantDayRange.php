<?php

namespace App\Services;

use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Calendar-day boundaries in the tenant's own timezone.
 *
 * "Today" belongs to the agency, not to the server: a ride at 23:30 local is
 * that agency's today even when the server is already on the next UTC day. The
 * boundaries come back in UTC because scheduled_pickup_at is stored as a UTC
 * wall-clock.
 */
class TenantDayRange
{
    public function __construct(private readonly string $timezone) {}

    public static function for(?Tenant $tenant): self
    {
        $timezone = $tenant?->settings?->timezone ?: 'UTC';

        return new self(in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC');
    }

    /**
     * Start (inclusive) and end (exclusive) of the tenant's local day that
     * contains $moment.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    public function bounds(?CarbonInterface $moment = null): array
    {
        $localDay = ($moment ? $moment->copy() : Carbon::now())
            ->setTimezone($this->timezone)
            ->startOfDay();

        return [
            $localDay->copy()->setTimezone('UTC'),
            $localDay->copy()->addDay()->setTimezone('UTC'),
        ];
    }
}
