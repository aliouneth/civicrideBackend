<?php

namespace App\Services;

use App\Models\Ride;

/**
 * Total trip mileage for a ride = driver home → pickup + pickup → drop-off +
 * drop-off → driver home. Legs use Mapbox road distances, falling back to a
 * straight-line estimate when routing is unavailable; null when coordinates
 * cannot be resolved.
 *
 * A roundtrip kept as one ride runs the passenger circuit twice (out and back,
 * driver waiting at the far end): home → pickup → drop-off → pickup → home.
 */
class RideDistanceService
{
    public function __construct(
        private GeocodingService $geocoder,
        private DrivingDistanceService $distanceService,
    ) {}

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

        $leg1 = $this->legMiles($home, $pickup);
        $leg2 = $this->legMiles($pickup, $dropoff);
        // Split roundtrips are ordinary one-way legs (each priced on its own);
        // only a kept-as-one roundtrip doubles the circuit it actually drives.
        // Skip the return-to-home request when that circuit is doubled.
        $total = $ride->isSingleRoundtrip()
            ? 2 * ($leg1 + $leg2)
            : $leg1 + $leg2 + $this->legMiles($dropoff, $home);

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

    /**
     * @param  array{0: float, 1: float}  $from
     * @param  array{0: float, 1: float}  $to
     */
    private function legMiles(array $from, array $to): float
    {
        return $this->distanceService->milesBetween($from[0], $from[1], $to[0], $to[1]) ?? 0.0;
    }
}
