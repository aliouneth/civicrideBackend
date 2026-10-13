<?php

namespace App\Services;

use App\Models\Ride;

/**
 * Total trip mileage for a ride = driver home → pickup + pickup → drop-off +
 * drop-off → driver home. Haversine straight-line distance in miles; null when
 * coordinates cannot be resolved.
 *
 * A roundtrip kept as one ride runs the passenger circuit twice (out and back,
 * driver waiting at the far end): home → pickup → drop-off → pickup → home.
 */
class RideDistanceService
{
    private const EARTH_RADIUS_MILES = 3958.8;

    public function __construct(private GeocodingService $geocoder) {}

    public function forRide(Ride $ride): ?float
    {
        $profile = $ride->activeAssignment?->driver;

        $home = $this->resolveCoords(
            $profile->home_lat ?? null,
            $profile->home_lng ?? null,
            $profile->home_address ?? null,
        );
        $pickup = $this->resolveCoords(
            $ride->pickup_lat,
            $ride->pickup_lng,
            $ride->pickup_address,
        );
        $dropoff = $this->resolveCoords(
            $ride->dropoff_lat,
            $ride->dropoff_lng,
            $ride->dropoff_address,
        );

        if (! $home || ! $pickup || ! $dropoff) {
            return null;
        }

        $leg1 = $this->haversine($home[0], $home[1], $pickup[0], $pickup[1]);
        $leg2 = $this->haversine($pickup[0], $pickup[1], $dropoff[0], $dropoff[1]);
        $leg3 = $this->haversine($dropoff[0], $dropoff[1], $home[0], $home[1]);
        // Split roundtrips are ordinary one-way legs (each priced on its own);
        // only a kept-as-one roundtrip doubles the circuit it actually drives.
        $total = $ride->isSingleRoundtrip()
            ? 2 * ($leg1 + $leg2)
            : $leg1 + $leg2 + $leg3;

        \Log::info('Ride distance calculated', [
            'ride_id' => $ride->id,
            'home' => $home,
            'pickup' => $pickup,
            'dropoff' => $dropoff,
            'total_miles' => round($total, 1),
        ]);

        return round($total, 1);
    }

    /**
     * Resolve lat/lng from stored values, falling back to geocoding the address.
     * Rejects 0.0 values which indicate missing/invalid coordinates.
     */
    private function resolveCoords(?float $lat, ?float $lng, ?string $address): ?array
    {
        // Reject 0.0 values - they indicate missing/invalid coordinates
        if ($lat !== null && $lng !== null && $lat !== 0.0 && $lng !== 0.0) {
            return [$lat, $lng];
        }
        if (! $address) return null;

        $geocoded = $this->geocoder->geocode($address);
        return $geocoded ? [$geocoded['lat'], $geocoded['lng']] : null;
    }

    /** Great-circle distance in miles between two lat/lng points. */
    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_MILES * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
