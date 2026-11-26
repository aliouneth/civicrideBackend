<?php

namespace Tests\Feature;

use App\Services\GeocodingService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Address lookup must survive provider outages, prefer Mapbox when configured,
 * and never record a dropped connection as a permanently invalid address.
 */
class GeocodingServiceTest extends TestCase
{
    private GeocodingService $geocoder;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('geocoding.retry_delay_ms', 0);
        config()->set('geocoding.provider', 'mapbox');
        config()->set('geocoding.mapbox.access_token', null);
        config()->set('geocoding.mapbox.permanent', false);
        Cache::flush();

        // The service memoizes per process; tests share one PHP process.
        $memo = new ReflectionProperty(GeocodingService::class, 'memo');
        $memo->setValue(null, []);

        $this->geocoder = $this->app->make(GeocodingService::class);
    }

    private function cacheKeyFor(string $address): string
    {
        $provider = config('geocoding.provider') === 'mapbox' && config('geocoding.mapbox.access_token')
            ? 'mapbox'
            : 'nominatim';

        return 'geocode:'.$provider.':'.md5(mb_strtolower($address));
    }

    public function test_it_uses_a_free_form_query_for_a_normal_address(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                ['lat' => '44.3018', 'lon' => '-88.2757', 'display_name' => '1500 Arbor Way, Kaukauna, WI'],
            ]),
        ]);

        $address = '1500 Arbor Way, Kaukauna, WI 54130';
        $result = $this->geocoder->geocodeWithStatus($address);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(['lat' => 44.3018, 'lng' => -88.2757], $result['result']);

        Http::assertSent(fn (Request $request): bool => $request['q'] === $address
            && $request['countrycodes'] === 'us');
    }

    public function test_it_falls_back_to_parsed_street_city_and_state_when_the_query_finds_nothing(): void
    {
        Http::fakeSequence()
            ->push([])
            ->push([['lat' => '44.3018', 'lon' => '-88.2757']]);

        $result = $this->geocoder->geocodeWithStatus('1500 Arbor Way, Kaukauna, WI 54130');

        $this->assertSame('ok', $result['status']);

        Http::assertSent(fn (Request $request): bool => ($request['street'] ?? null) === '1500 Arbor Way'
            && ($request['city'] ?? null) === 'Kaukauna'
            && ($request['state'] ?? null) === 'WI'
            && ($request['postalcode'] ?? null) === '54130');
    }

    public function test_a_dropped_connection_is_retried_and_not_cached_as_a_bad_address(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            if ($attempts <= 2) {
                throw new ConnectionException('Recv failure: Connection was reset');
            }

            return Http::response([['lat' => '44.26', 'lon' => '-88.42']]);
        });

        $address = '844 E. Sunset Ave, Appleton, WI';
        $result = $this->geocoder->geocodeWithStatus($address);

        $this->assertSame('unavailable', $result['status']);
        $this->assertNull($result['result']);
        $this->assertSame(2, $attempts);

        // Cached as a short outage, never as `false` (genuine no-match).
        $cached = Cache::get($this->cacheKeyFor($address));
        $this->assertNotFalse($cached);
        $this->assertIsString($cached);

        // A later request can try again instead of being stuck with the old failure.
        Cache::forget($this->cacheKeyFor($address));
        $memo = new ReflectionProperty(GeocodingService::class, 'memo');
        $memo->setValue(null, []);

        $this->assertSame('ok', $this->geocoder->geocodeWithStatus($address)['status']);
    }

    public function test_a_genuine_no_match_is_cached_as_such(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);

        $address = 'Not a real street, Nowhere, WI';
        $this->assertSame('missing', $this->geocoder->geocodeWithStatus($address)['status']);
        $this->assertFalse(Cache::get($this->cacheKeyFor($address)));
    }

    public function test_it_prefers_mapbox_and_caches_permanent_results(): void
    {
        config()->set('geocoding.mapbox.access_token', 'pk.test');
        config()->set('geocoding.mapbox.permanent', true);

        Http::fake([
            'api.mapbox.com/*' => Http::response([
                'features' => [[
                    'properties' => [
                        'full_address' => '1500 Arbor Way, Kaukauna, WI 54130, United States',
                        'coordinates' => ['latitude' => 44.3018, 'longitude' => -88.2757],
                    ],
                    'geometry' => ['coordinates' => [-88.2757, 44.3018]],
                ]],
            ]),
        ]);

        $address = '1500 Arbor Way, Kaukauna, WI 54130';
        $result = $this->geocoder->geocodeWithStatus($address);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(['lat' => 44.3018, 'lng' => -88.2757], $result['result']);
        $this->assertSame($result['result'], Cache::get($this->cacheKeyFor($address)));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'search/geocode/v6/forward')
            && $request['q'] === $address
            && $request['autocomplete'] === 'false'
            && $request['permanent'] === 'true'
            && $request['access_token'] === 'pk.test');
    }

    public function test_temporary_mapbox_results_are_not_cached(): void
    {
        config()->set('geocoding.mapbox.access_token', 'pk.test');
        config()->set('geocoding.mapbox.permanent', false);

        Http::fake([
            'api.mapbox.com/*' => Http::response([
                'features' => [[
                    'properties' => [
                        'full_address' => '844 E. Sunset Ave, Appleton, WI',
                        'coordinates' => ['latitude' => 44.26, 'longitude' => -88.42],
                    ],
                ]],
            ]),
        ]);

        $address = '844 E. Sunset Ave, Appleton, WI';
        $this->assertSame('ok', $this->geocoder->geocodeWithStatus($address)['status']);
        $this->assertFalse(Cache::has($this->cacheKeyFor($address)));
    }

    public function test_it_falls_back_to_nominatim_when_mapbox_is_unavailable(): void
    {
        config()->set('geocoding.mapbox.access_token', 'pk.test');

        Http::fakeSequence()
            ->push(['message' => 'temporary failure'], 500)
            ->push([['lat' => '44.3018', 'lon' => '-88.2757']]);

        $result = $this->geocoder->geocodeWithStatus('1500 Arbor Way, Kaukauna, WI 54130');

        $this->assertSame('ok', $result['status']);
        $this->assertSame(['lat' => 44.3018, 'lng' => -88.2757], $result['result']);
    }

    public function test_autocomplete_returns_formatted_mapbox_suggestions(): void
    {
        config()->set('geocoding.mapbox.access_token', 'pk.test');

        Http::fake([
            'api.mapbox.com/*' => Http::response([
                'features' => [[
                    'properties' => [
                        'name' => '1500 Arbor Way',
                        'place_formatted' => 'Kaukauna, Wisconsin 54130, United States',
                        'coordinates' => ['latitude' => 44.3018, 'longitude' => -88.2757],
                    ],
                ]],
            ]),
        ]);

        $suggestions = $this->geocoder->suggest('1500 Arbor');

        $this->assertSame([[
            'label' => '1500 Arbor Way, Kaukauna, Wisconsin 54130, United States',
            'lat' => 44.3018,
            'lng' => -88.2757,
        ]], $suggestions);

        Http::assertSent(fn (Request $request): bool => $request['q'] === '1500 Arbor'
            && $request['autocomplete'] === 'true'
            && (int) $request['limit'] === 5);
    }

    public function test_wisconsin_fire_numbers_get_a_local_suggestion(): void
    {
        $suggestions = $this->geocoder->suggest('W1919 Vandenberg Street');

        $this->assertCount(1, $suggestions);
        $this->assertSame('W1919 Vandenberg Street', $suggestions[0]['label']);
        $this->assertSame(-90.7278115942029, $suggestions[0]['lng']);
    }
}
