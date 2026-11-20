<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class RideGpsDistanceService
{
    /** Sum filtered GPS segments into actual driven miles for one ride. */
    public function forRide(int $rideId, int $tenantId): ?float
    {
        $points = DB::table('ride_location_points')
            ->where('ride_id', $rideId)
            ->where('tenant_id', $tenantId)
            ->where(fn ($query) => $query->whereNull('accuracy_meters')->orWhere('accuracy_meters', '<=', 100))
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get(['latitude', 'longitude']);

        if ($points->count() < 2) {
            return null;
        }

        $miles = 0.0;
        $previous = null;
        foreach ($points as $point) {
            $current = [(float) $point->latitude, (float) $point->longitude];
            if ($previous !== null) {
                $miles += $this->haversine($previous[0], $previous[1], $current[0], $current[1]);
            }
            $previous = $current;
        }

        return round($miles, 1);
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 3958.7613 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
