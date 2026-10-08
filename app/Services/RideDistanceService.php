<?php

namespace App\Services;

use App\Models\Ride;

/**
 * Total trip mileage for a ride = driver home → pickup + pickup → drop-off +
 * drop-off → driver home. Haversine straight-line distance; null when the
 * ride has no assigned driver with a home base on file.
 */
class RideDistanceService
{
    private const EARTH_KM = 6371.0;

    public function forRide(Ride $ride): ?float
    {
        $profile = $ride->activeAssignment?->driver;
        $home = [$profile->home_lat ?? null, $profile->home_lng ?? null];
        $pickup = [$ride->pickup_lat, $ride->pickup_lng];
        $dropoff = [$ride->dropoff_lat, $ride->dropoff_lng];

        if ($home[0] === null || $home[1] === null
            || $pickup[0] === null || $pickup[1] === null
            || $dropoff[0] === null || $dropoff[1] === null) {
            return null;
        }

        $total = $this->haversine($home[0], $home[1], $pickup[0], $pickup[1])
            + $this->haversine($pickup[0], $pickup[1], $dropoff[0], $dropoff[1])
            + $this->haversine($dropoff[0], $dropoff[1], $home[0], $home[1]);

        return round($total, 1);
    }

    /** Great-circle distance in km between two lat/lng points. */
    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
