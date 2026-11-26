<?php

namespace Tests\Feature;

use App\Services\DrivingDistanceService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ReflectionProperty;
use Tests\TestCase;

class DrivingDistanceServiceTest extends TestCase
{
    private DrivingDistanceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('routing.provider', 'mapbox');
        config()->set('routing.mapbox.access_token', 'pk.test');
        config()->set('routing.mapbox.cache_ttl', 2592000);
        Cache::flush();
        $this->resetMemo();

        $this->service = $this->app->make(DrivingDistanceService::class);
    }

    private function resetMemo(): void
    {
        $memo = new ReflectionProperty(DrivingDistanceService::class, 'memo');
        $memo->setValue(null, []);
    }

    public function test_it_returns_mapbox_road_distance_in_miles(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.mapbox.com/directions/v5/mapbox/driving/*' => Http::response([
                'code' => 'Ok',
                'routes' => [['distance' => 16655.096]],
            ]),
        ]);

        $miles = $this->service->milesBetween(44.300005, -88.27564, 44.26, -88.42);

        $this->assertSame(10.35, $miles);

        Http::assertSent(fn (Request $request): bool => str_contains(
            $request->url(),
            '/mapbox/driving/-88.275640,44.300005;-88.420000,44.260000',
        )
            && $request['access_token'] === 'pk.test'
            && $request['overview'] === 'false'
            && $request['steps'] === 'false');
    }

    public function test_it_reuses_a_cached_distance_for_the_same_route(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.mapbox.com/directions/v5/mapbox/driving/*' => Http::response([
                'code' => 'Ok',
                'routes' => [['distance' => 16655.096]],
            ]),
        ]);

        $first = $this->service->milesBetween(44.300005, -88.27564, 44.26, -88.42);
        $this->resetMemo();
        $second = app(DrivingDistanceService::class)->milesBetween(44.300005, -88.27564, 44.26, -88.42);

        $this->assertSame($first, $second);

        Http::assertSentCount(1);
    }

    public function test_it_falls_back_to_straight_line_miles_when_mapbox_is_unavailable(): void
    {
        $attempts = 0;
        Http::preventStrayRequests();
        Http::fake([
            'api.mapbox.com/directions/v5/mapbox/driving/*' => function () use (&$attempts) {
                $attempts++;

                return $attempts === 1
                    ? Http::response(['message' => 'temporary failure'], 500)
                    : Http::response([
                        'code' => 'Ok',
                        'routes' => [['distance' => 16655.096]],
                    ]);
            },
        ]);

        $fallback = $this->service->milesBetween(44, -88, 45, -88);

        $this->assertSame(69.09, $fallback);

        // A failed lookup must never be cached as the route's answer.
        $this->resetMemo();
        $recovered = $this->service->milesBetween(44, -88, 45, -88);

        $this->assertSame(10.35, $recovered);

        Http::assertSentCount(2);
    }

    public function test_it_falls_back_without_a_mapbox_token(): void
    {
        config()->set('routing.mapbox.access_token', null);

        Http::preventStrayRequests();
        Http::fake([]);

        $miles = $this->service->milesBetween(44, -88, 45, -88);

        $this->assertSame(69.09, $miles);

        Http::assertNothingSent();
    }

    public function test_it_returns_null_for_invalid_coordinates(): void
    {
        Http::preventStrayRequests();
        Http::fake([]);

        $miles = $this->service->milesBetween(null, -88.27564, 44.26, -88.42);

        $this->assertNull($miles);

        Http::assertNothingSent();
    }
}
