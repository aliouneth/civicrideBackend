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
 * Address lookup must survive an intermittent Nominatim outage and must not
 * record a dropped connection as a permanently invalid address.
 */
class GeocodingServiceTest extends TestCase
{
    private GeocodingService $geocoder;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('geocoding.retry_delay_ms', 0);
        Cache::flush();

        // The service memoizes per process; tests share one PHP process.
        $memo = new ReflectionProperty(GeocodingService::class, 'memo');
        $memo->setValue(null, []);

        $this->geocoder = $this->app->make(GeocodingService::class);
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
        $cached = Cache::get('geocode:'.md5(mb_strtolower($address)));
        $this->assertNotFalse($cached);
        $this->assertIsString($cached);

        // A later request can try again instead of being stuck with the old failure.
        Cache::forget('geocode:'.md5(mb_strtolower($address)));
        $memo = new ReflectionProperty(GeocodingService::class, 'memo');
        $memo->setValue(null, []);

        $memo = new ReflectionProperty(GeocodingService::class, 'memo');
        $memo->setValue(null, []);

        $this->assertSame('ok', $this->geocoder->geocodeWithStatus($address)['status']);
    }

    public function test_a_genuine_no_match_is_cached_as_such(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);

        $address = 'Not a real street, Nowhere, WI';
        $this->assertSame('missing', $this->geocoder->geocodeWithStatus($address)['status']);
        $this->assertFalse(Cache::get('geocode:'.md5(mb_strtolower($address))));
    }
}
