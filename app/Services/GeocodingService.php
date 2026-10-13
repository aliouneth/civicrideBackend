<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Server-side geocoding using OpenStreetMap Nominatim.
 * Provides fallback for US addresses with city/state parsing.
 * PRIMARY: Parses Wisconsin fire-number addresses (E#### / W#### system)
 * which Nominatim cannot handle correctly.
 */
class GeocodingService
{
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';

    /**
     * Geocode an address to lat/lng.
     * For Wisconsin fire-number addresses (E#### N Road / W#### S Road),
     * parses the grid coordinates directly since Nominatim fails.
     * For other addresses, uses Nominatim with structured query.
     */
    public function geocode(string $address): ?array
    {
        if (! $address) return null;

        // Try Wisconsin fire-number parser first (most reliable for WI addresses)
        $fireNumberResult = $this->parseWisconsinFireNumber($address);
        if ($fireNumberResult) {
            \Log::info('Geocoded via Wisconsin fire number', [
                'input' => $address,
                'result' => $fireNumberResult,
            ]);
            return $fireNumberResult;
        }

        // Fall back to Nominatim with structured query
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
            \Log::warning('Wisconsin fire number outside expected bounds', [
                'input' => $address,
                'computed' => ['lat' => $lat, 'lng' => $lng],
                'bounds' => ["min_lat" => $minLat, "max_lat" => $maxLat, "min_lng" => $minLng, "max_lng" => $maxLng],
            ]);
            return null;
        }

        return ['lat' => (float) $lat, 'lng' => (float) $lng];
    }

    /**
     * Geocode using Nominatim with structured query parameters.
     * Extracts city/state from address for better results.
     */
    private function geocodeNominatim(string $address): ?array
    {
        // Try to extract city/state for structured query
        $structured = $this->parseAddress($address);

        try {
            $params = [
                'format' => 'json',
                'limit' => 1,
                'addressdetails' => 1,
                'countrycodes' => 'us',
            ];

            if ($structured['street']) {
                $params['street'] = $structured['street'];
            }
            if ($structured['city']) {
                $params['city'] = $structured['city'];
            }
            if ($structured['state']) {
                $params['state'] = $structured['state'];
            }
            if ($structured['postalcode']) {
                $params['postalcode'] = $structured['postalcode'];
            } else {
                $params['q'] = $address;
            }

            $response = Http::timeout(5)
                ->withHeaders([
                    'User-Agent' => 'CivicRide/1.0',
                    'Accept' => 'application/json',
                ])
                ->get(self::NOMINATIM_URL, $params);

            if (! $response->ok()) return null;

            $data = $response->json();
            if (is_array($data) && count($data) > 0) {
                $result = $data[0];
                $lat = (float) $result['lat'];
                $lng = (float) $result['lon'];

                \Log::info('Geocoded via Nominatim', [
                    'input' => $address,
                    'structured' => $structured,
                    'result' => ['lat' => $lat, 'lng' => $lng, 'display_name' => $result['display_name'] ?? null],
                ]);

                return ['lat' => $lat, 'lng' => $lng];
            }
        } catch (\Throwable $e) {
            \Log::warning('Nominatim geocoding failed', ['address' => $address, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Parse address into street, city, state, postalcode components.
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

        // Extract state abbreviation
        if (preg_match('/\b([A-Z]{2})(?=\s|$)/', $address, $m)) {
            $result['state'] = $m[1];
            $address = str_replace($m[0], '', $address);
        }

        // Extract city (text before state)
        if ($result['state'] && preg_match('/(.+?)\s+' . $result['state'] . '$/i', $address, $m)) {
            $result['city'] = trim($m[1]);
        }

        $result['street'] = trim($address);

        return $result;
    }
}