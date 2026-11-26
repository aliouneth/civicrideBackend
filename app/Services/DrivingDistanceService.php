<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Road distance between two coordinates, in miles.
 *
 * Mapbox Directions is preferred because total ride mileage includes the
 * driver's trip to the pickup and back home; a straight line badly understates
 * that circuit. A local Haversine estimate is kept as a deterministic fallback
 * when Mapbox is not configured or cannot produce a route.
 */
class DrivingDistanceService
{
    private const METERS_PER_MILE = 1609.344;

    private const EARTH_RADIUS_MILES = 3958.8;

    /** Mapbox Service Terms allow Directions results to be cached for 30 days. */
    private const CACHE_TTL = 86400 * 30;

    /** @var array<string, float> */
    private static array $memo = [];

    public function milesBetween(
        float|int|string|null $fromLat,
        float|int|string|null $fromLng,
        float|int|string|null $toLat,
        float|int|string|null $toLng,
    ): ?float {
        $coordinates = $this->validCoordinates($fromLat, $fromLng, $toLat, $toLng);
        if ($coordinates === null) {
            return null;
        }

        [$fromLatValue, $fromLngValue, $toLatValue, $toLngValue] = $coordinates;

        if ($fromLatValue === $toLatValue && $fromLngValue === $toLngValue) {
            return 0.0;
        }

        $cacheKey = $this->cacheKey($fromLatValue, $fromLngValue, $toLatValue, $toLngValue);
        if (array_key_exists($cacheKey, self::$memo)) {
            return self::$memo[$cacheKey];
        }

        if (Cache::has($cacheKey)) {
            return self::$memo[$cacheKey] = (float) Cache::get($cacheKey);
        }

        [$mapboxMiles, $providerSucceeded] = $this->mapboxMiles(
            $fromLatValue,
            $fromLngValue,
            $toLatValue,
            $toLngValue,
        );
        $miles = $mapboxMiles ?? $this->straightLineMiles(
            $fromLatValue,
            $fromLngValue,
            $toLatValue,
            $toLngValue,
        );

        // Only successful provider results are cached; outages must not freeze
        // a fallback estimate into the route history.
        if ($providerSucceeded) {
            Cache::put($cacheKey, $miles, (int) config('routing.mapbox.cache_ttl', self::CACHE_TTL));
        }

        return self::$memo[$cacheKey] = $miles;
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private function validCoordinates(
        float|int|string|null $fromLat,
        float|int|string|null $fromLng,
        float|int|string|null $toLat,
        float|int|string|null $toLng,
    ): ?array {
        foreach ([$fromLat, $fromLng, $toLat, $toLng] as $value) {
            if (! is_numeric($value) || ! is_finite((float) $value)) {
                return null;
            }
        }

        $fromLatValue = (float) $fromLat;
        $fromLngValue = (float) $fromLng;
        $toLatValue = (float) $toLat;
        $toLngValue = (float) $toLng;

        if (abs($fromLatValue) > 90 || abs($toLatValue) > 90
            || abs($fromLngValue) > 180 || abs($toLngValue) > 180) {
            return null;
        }

        return [$fromLatValue, $fromLngValue, $toLatValue, $toLngValue];
    }

    private function mapboxConfigured(): bool
    {
        return config('routing.provider') === 'mapbox'
            && is_string(config('routing.mapbox.access_token'))
            && config('routing.mapbox.access_token') !== '';
    }

    /**
     * @return array{0: float|null, 1: bool}
     */
    private function mapboxMiles(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        if (! $this->mapboxConfigured()) {
            return [null, false];
        }

        $coordinates = $this->formatCoordinate($fromLng, $fromLat).';'.$this->formatCoordinate($toLng, $toLat);
        $url = rtrim((string) config('routing.mapbox.endpoint'), '/').'/'.$coordinates;

        try {
            $response = Http::connectTimeout(3)
                ->timeout(6)
                ->withHeaders(['Accept' => 'application/json'])
                ->get($url, [
                    'access_token' => (string) config('routing.mapbox.access_token'),
                    'alternatives' => 'false',
                    'overview' => 'false',
                    'steps' => 'false',
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('Mapbox Directions request failed', [
                'from' => [$fromLat, $fromLng],
                'to' => [$toLat, $toLng],
                'error' => $exception->getMessage(),
            ]);

            return [null, false];
        }

        if (! $response->ok()) {
            Log::warning('Mapbox Directions returned an unexpected status', [
                'status' => $response->status(),
                'from' => [$fromLat, $fromLng],
                'to' => [$toLat, $toLng],
            ]);

            return [null, false];
        }

        $data = $response->json();
        $meters = data_get($data, 'routes.0.distance');
        if (data_get($data, 'code') !== 'Ok' || ! is_numeric($meters) || (float) $meters <= 0) {
            Log::warning('Mapbox Directions could not route the request', [
                'code' => data_get($data, 'code'),
                'from' => [$fromLat, $fromLng],
                'to' => [$toLat, $toLng],
            ]);

            return [null, false];
        }

        return [round((float) $meters / self::METERS_PER_MILE, 2), true];
    }

    private function formatCoordinate(float $longitude, float $latitude): string
    {
        return number_format($longitude, 6, '.', '').','.number_format($latitude, 6, '.', '');
    }

    private function cacheKey(float $fromLat, float $fromLng, float $toLat, float $toLng): string
    {
        return 'route:driving:'.md5(sprintf(
            '%.5f,%.5f;%.5f,%.5f',
            $fromLat,
            $fromLng,
            $toLat,
            $toLng,
        ));
    }

    private function straightLineMiles(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $dLat = deg2rad($toLat - $fromLat);
        $dLng = deg2rad($toLng - $fromLng);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($dLng / 2) ** 2;
        $a = max(0.0, min(1.0, $a));

        return round(self::EARTH_RADIUS_MILES * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }
}
