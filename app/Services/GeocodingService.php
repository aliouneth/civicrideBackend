<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side geocoding using OpenStreetMap Nominatim.
 * Provides fallback for US addresses with city/state parsing.
 * PRIMARY: Parses Wisconsin fire-number addresses (E#### / W#### system)
 * which Nominatim cannot handle correctly.
 *
 * Nominatim is a free public service: this machine's connection to it drops
 * intermittently. A dropped connection is NOT evidence that an address is
 * invalid, so it gets a short retry/TTL instead of being cached as "not found"
 * for an hour (which used to make a good address fail for the rest of the day).
 */
class GeocodingService
{
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';

    /** Successful geocodes are stable. */
    private const SUCCESS_TTL = 86400 * 30;

    /** A genuine "no match" is stable enough to cache briefly. */
    private const NO_MATCH_TTL = 3600;

    /** A provider outage must be retried soon, not remembered as a bad address. */
    private const UNAVAILABLE_TTL = 60;

    private const ATTEMPTS = 2;

    /** Nominatim's usage policy allows one request per second. */
    private const RETRY_DELAY_MS = 1200;

    /** Cache sentinel distinguishing outage from a genuine no-match (false). */
    private const UNAVAILABLE = '__geocode_unavailable__';

    /** @var list<string> */
    private const US_STATES = [
        'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FL',
        'GA', 'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME',
        'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH',
        'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI',
        'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY',
    ];

    /** @var array<string, array{status: string, result: array{lat: float, lng: float}|null}> */
    private static array $memo = [];

    /**
     * Geocode an address to lat/lng.
     * For Wisconsin fire-number addresses (E#### or W#### followed by road),
     * parses the grid coordinates directly since Nominatim fails.
     * For other addresses, uses Nominatim with structured query.
     */
    public function geocode(string $address): ?array
    {
        return $this->geocodeWithStatus($address)['result'];
    }

    /**
     * Same lookup, with the reason for a miss so the UI can tell a bad address
     * from a temporarily unreachable lookup service.
     *
     * @return array{status: string, result: array{lat: float, lng: float}|null}
     */
    public function geocodeWithStatus(string $address): array
    {
        if (! $address) {
            return $this->outcome('empty', null);
        }

        $key = mb_strtolower(trim($address));

        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        $cacheKey = 'geocode:'.md5($key);
        if (Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            $outcome = match (true) {
                $cached === self::UNAVAILABLE => $this->outcome('unavailable', null),
                $cached === false => $this->outcome('missing', null),
                default => $this->outcome('ok', $cached),
            };

            return self::$memo[$key] = $outcome;
        }

        $outcome = $this->geocodeUncached($address);

        [$value, $ttl] = match ($outcome['status']) {
            'ok' => [$outcome['result'], self::SUCCESS_TTL],
            'missing' => [false, self::NO_MATCH_TTL],
            'unavailable' => [self::UNAVAILABLE, self::UNAVAILABLE_TTL],
            default => [null, 0],
        };
        if ($ttl > 0) {
            Cache::put($cacheKey, $value, $ttl);
        }

        return self::$memo[$key] = $outcome;
    }

    /**
     * @return array{status: string, result: array{lat: float, lng: float}|null}
     */
    private function outcome(string $status, ?array $result): array
    {
        return ['status' => $status, 'result' => $result];
    }

    /**
     * @return array{status: string, result: array{lat: float, lng: float}|null}
     */
    private function geocodeUncached(string $address): array
    {
        // Try Wisconsin fire-number parser first (most reliable for WI addresses)
        $fireNumberResult = $this->parseWisconsinFireNumber($address);
        if ($fireNumberResult) {
            Log::info('Geocoded via Wisconsin fire number', [
                'input' => $address,
                'result' => $fireNumberResult,
            ]);

            return $this->outcome('ok', $fireNumberResult);
        }

        // Fall back to Nominatim with a free-form query, then structured fields.
        return $this->geocodeNominatim($address);
    }

    /**
     * Parse Wisconsin fire-number addresses (E#### or W#### followed by road).
     * Wisconsin uses a coordinate-based addressing system for rural areas.
     * E = easting, W = westing (from a base origin).
     *
     * Rough conversion: ~1000 units ≈ 1 mile at Wisconsin's latitude.
     * Origin is approximately near 42.5°N, 90.7°W (around Dubuque, IA/WI border).
     */
    private function parseWisconsinFireNumber(string $address): ?array
    {
        // Pattern: E#### or W#### followed by road name, possibly with N/S prefixes
        // Examples: "E4104 N. Rail Rd", "W1919 Vandenberg Street"
        if (! preg_match('/^([EW])(\d{3,5})\s+([NS]?)\.?\s*(.+)/i', $address, $m)) {
            return null;
        }

        $ew = strtoupper($m[1]);    // E or W
        $coord = (int) $m[2];       // coordinate value (e.g., 4104, 1919)
        $ns = strtoupper($m[3]);    // N, S, or empty
        $road = trim($m[4]);        // road name

        // Wisconsin grid conversion (approximate)
        // Base point: ~42.5°N, -90.7°W
        // 1000 units ≈ 1 mile ≈ 0.01445° latitude/longitude at this latitude
        $baseLat = 42.5;
        $baseLng = -90.7;
        $unitsPerDegree = 69.0; // miles per degree of longitude at this latitude

        // Calculate approximate coordinates
        // E/W affects longitude, N/S affects latitude
        $lngDelta = ($ew === 'E' ? 1 : -1) * ($coord / 1000) / $unitsPerDegree;
        $latDelta = ($ns === 'N' ? 1 : ($ns === 'S' ? -1 : 0)) * 0.02; // approximate north/south

        $lat = $baseLat + $latDelta;
        $lng = $baseLng + $lngDelta;

        // Validate Wisconsin bounds
        $minLat = 42.0; $maxLat = 47.5;
        $minLng = -93.5; $maxLng = -86.5;

        if ($lat < $minLat || $lat > $maxLat || $lng < $minLng || $lng > $maxLng) {
            Log::warning('Wisconsin fire number outside expected bounds', [
                'input' => $address,
                'computed' => ['lat' => $lat, 'lng' => $lng],
                'bounds' => ['min_lat' => $minLat, 'max_lat' => $maxLat, 'min_lng' => $minLng, 'max_lng' => $maxLng],
            ]);

            return null;
        }

        return ['lat' => (float) $lat, 'lng' => (float) $lng];
    }

    /**
     * Free-form query first (Nominatim handles complete US addresses well),
     * then the parsed street/city/state/postal fields as a fallback.
     *
     * @param  array{street: string|null, city: string|null, state: string|null, postalcode: string|null}  $structured
     * @return array{status: string, result: array{lat: float, lng: float}|null}
     */
    private function geocodeNominatim(string $address): array
    {
        $structured = $this->parseAddress($address);

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            try {
                $result = $this->queryNominatim($address, $structured);
                if ($result !== null) {
                    Log::info('Geocoded via Nominatim', [
                        'input' => $address,
                        'structured' => $structured,
                        'result' => $result,
                    ]);

                    return $this->outcome('ok', $result);
                }

                return $this->outcome('missing', null);
            } catch (ConnectionException|RequestException $e) {
                Log::warning('Nominatim geocoding failed', [
                    'address' => $address,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($attempt < self::ATTEMPTS) {
                usleep(max(0, (int) config('geocoding.retry_delay_ms', self::RETRY_DELAY_MS)) * 1000);
            }
        }

        return $this->outcome('unavailable', null);
    }

    /**
     * @param  array{street: string|null, city: string|null, state: string|null, postalcode: string|null}  $structured
     */
    private function queryNominatim(string $address, array $structured): ?array
    {
        $base = [
            'format' => 'json',
            'limit' => 1,
            'addressdetails' => 1,
            'countrycodes' => 'us',
        ];

        $result = $this->requestNominatim($base + ['q' => $address]);
        if ($result !== null) {
            return $result;
        }

        $fallback = $base;
        foreach (['street', 'city', 'state', 'postalcode'] as $field) {
            if ($structured[$field]) {
                $fallback[$field] = $structured[$field];
            }
        }
        if (count($fallback) === count($base)) {
            return null;
        }

        return $this->requestNominatim($fallback);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{lat: float, lng: float}|null
     */
    private function requestNominatim(array $params): ?array
    {
        $response = Http::timeout(6)
            ->withHeaders([
                'User-Agent' => 'CivicRide/1.0 ('.config('app.url').')',
                'Accept' => 'application/json',
            ])
            ->get(self::NOMINATIM_URL, $params);

        // Rate limiting / server errors are transient — throw so the caller
        // retries instead of recording the address as unfindable.
        if ($response->status() === 429 || $response->serverError()) {
            $response->throw();
        }
        if (! $response->ok()) {
            Log::warning('Nominatim returned an unexpected status', [
                'status' => $response->status(),
                'params' => $params,
            ]);

            return null;
        }

        $data = $response->json();
        if (! is_array($data) || count($data) === 0) {
            return null;
        }

        $first = $data[0];

        return [
            'lat' => (float) ($first['lat'] ?? 0),
            'lng' => (float) ($first['lon'] ?? 0),
        ];
    }

    /**
     * Parse address into street, city, state, postalcode components.
     * The state must be matched BEFORE it is stripped from the string — the
     * previous implementation removed it first, so `city` was always null and
     * the street ended up containing the city with a trailing comma.
     *
     * @return array{street: string|null, city: string|null, state: string|null, postalcode: string|null}
     */
    private function parseAddress(string $address): array
    {
        $result = [
            'street' => null,
            'city' => null,
            'state' => null,
            'postalcode' => null,
        ];

        // Extract ZIP code
        if (preg_match('/\b(\d{5}(?:-\d{4})?)\b/', $address, $m)) {
            $result['postalcode'] = $m[1];
            $address = str_replace($m[0], '', $address);
        }
        $address = preg_replace('/\s+/', ' ', trim($address)) ?? $address;

        // Extract a real US state abbreviation (case-insensitive, with or
        // without a trailing comma), remembering where it appeared.
        if (preg_match('/\b([A-Za-z]{2})\b/i', $address, $m, PREG_OFFSET_CAPTURE)
            && in_array(strtoupper($m[1][0]), self::US_STATES, true)) {
            $result['state'] = strtoupper($m[1][0]);
            $beforeState = substr($address, 0, $m[0][1]);
            $address = trim(substr($address, $m[0][1] + strlen($m[0][0])));

            $parts = array_values(array_filter(
                array_map('trim', explode(',', $beforeState)),
                fn (string $part): bool => $part !== '',
            ));
            if (count($parts) >= 2) {
                $result['city'] = array_pop($parts);
                $result['street'] = implode(', ', $parts);
            } elseif (count($parts) === 1) {
                $result['city'] = $parts[0];
            }
        }

        if ($result['street'] === null) {
            $street = trim($address, " \t\n\r\0\x0B,");
            $result['street'] = $street !== '' ? $street : null;
        }

        return $result;
    }
}
