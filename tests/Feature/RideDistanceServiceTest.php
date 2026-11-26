<?php

namespace Tests\Feature;

use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\RideAssignment;
use App\Services\DrivingDistanceService;
use App\Services\RideDistanceService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ReflectionProperty;
use Tests\TestCase;

class RideDistanceServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('routing.provider', 'mapbox');
        config()->set('routing.mapbox.access_token', 'pk.test');
        Cache::flush();

        $memo = new ReflectionProperty(DrivingDistanceService::class, 'memo');
        $memo->setValue(null, []);
    }

    public function test_it_sums_mapbox_road_distances_for_the_full_driver_circuit(): void
    {
        $responses = [10000, 20000, 30000];
        $index = 0;
        Http::preventStrayRequests();
        Http::fake([
            'api.mapbox.com/directions/v5/mapbox/driving/*' => function () use (&$index, $responses) {
                return Http::response([
                    'code' => 'Ok',
                    'routes' => [['distance' => $responses[$index++]]],
                ]);
            },
        ]);

        $ride = $this->rideWithPoints([44.0, -88.0], [44.1, -88.1], [44.2, -88.2]);

        $miles = app(RideDistanceService::class)->forRide($ride);

        $this->assertSame(37.3, $miles);

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => str_contains(
            $request->url(),
            '/mapbox/driving/-88.000000,44.000000;-88.100000,44.100000',
        ));
        Http::assertSent(fn (Request $request): bool => str_contains(
            $request->url(),
            '/mapbox/driving/-88.100000,44.100000;-88.200000,44.200000',
        ));
        Http::assertSent(fn (Request $request): bool => str_contains(
            $request->url(),
            '/mapbox/driving/-88.200000,44.200000;-88.000000,44.000000',
        ));
    }

    public function test_it_doubles_the_outbound_road_distance_for_a_kept_roundtrip(): void
    {
        $responses = [5000, 8000];
        $index = 0;
        Http::preventStrayRequests();
        Http::fake([
            'api.mapbox.com/directions/v5/mapbox/driving/*' => function () use (&$index, $responses) {
                return Http::response([
                    'code' => 'Ok',
                    'routes' => [['distance' => $responses[$index++]]],
                ]);
            },
        ]);

        $ride = $this->rideWithPoints([44.0, -88.0], [44.1, -88.1], [44.2, -88.2], 'roundtrip');

        $miles = app(RideDistanceService::class)->forRide($ride);

        $this->assertSame(16.2, $miles);

        Http::assertSentCount(2);
    }

    public function test_it_returns_null_when_a_required_point_is_missing(): void
    {
        Http::preventStrayRequests();
        Http::fake([]);

        $ride = $this->rideWithPoints([44.0, -88.0], [null, null], [44.2, -88.2]);

        $miles = app(RideDistanceService::class)->forRide($ride);

        $this->assertNull($miles);

        Http::assertNothingSent();
    }

    /**
     * @param  array{0: float|null, 1: float|null}  $home
     * @param  array{0: float|null, 1: float|null}  $pickup
     * @param  array{0: float|null, 1: float|null}  $dropoff
     */
    private function rideWithPoints(
        array $home,
        array $pickup,
        array $dropoff,
        ?string $tripType = null,
    ): Ride {
        $ride = new Ride([
            'pickup_lat' => $pickup[0],
            'pickup_lng' => $pickup[1],
            'dropoff_lat' => $dropoff[0],
            'dropoff_lng' => $dropoff[1],
            'trip_type' => $tripType,
        ]);

        $driver = new DriverProfile([
            'home_lat' => $home[0],
            'home_lng' => $home[1],
        ]);
        $assignment = new RideAssignment();
        $assignment->setRelation('driver', $driver);
        $ride->setRelation('activeAssignment', $assignment);

        return $ride;
    }
}
