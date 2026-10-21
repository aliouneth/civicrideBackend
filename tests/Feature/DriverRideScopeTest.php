<?php

namespace Tests\Feature;

use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\RideAssignment;
use App\Models\RideStatus;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The driver's job list windows (GET /drivers/me/rides?scope=…).
 *
 * The regression this guards: the driver home screen picked the first assigned
 * ride in ascending pickup order, so a stale ride from a previous day took the
 * "Now" slot and the rides scheduled for today never showed up.
 */
class DriverRideScopeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $driver;

    private DriverProfile $profile;

    /** @var array<string, RideStatus> */
    private array $statuses = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Acme Transit',
            'slug' => 'acme',
            'contact_email' => 'ops@acme.test',
            'is_active' => true,
        ]);
        $this->tenant->settings()->create(['timezone' => 'UTC', 'currency' => 'USD']);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'driver']);
        $this->driver = User::create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
            'name' => 'Dana Driver',
            'email' => 'dana@acme.test',
            'password' => 'password123',
        ]);
        $this->profile = DriverProfile::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->driver->id,
            'license_number' => 'D-1',
            'license_expiry' => now()->addYear()->toDateString(),
            'vehicle_make' => 'Ford',
            'vehicle_model' => 'Transit',
            'vehicle_year' => 2020,
            'vehicle_color' => 'White',
            'vehicle_plate' => 'ABC-1234',
            'onboarding_status' => 'approved',
        ]);

        foreach (['pending', 'assigned', 'en_route', 'picked_up', 'completed', 'cancelled'] as $index => $code) {
            $this->statuses[$code] = RideStatus::create([
                'code' => $code,
                'label' => ucfirst(str_replace('_', ' ', $code)),
                'sort_order' => $index + 1,
                'is_terminal' => in_array($code, ['completed', 'cancelled'], true),
                'allows_assignment' => true,
            ]);
        }

        Sanctum::actingAs($this->driver);
    }

    public function test_today_scope_lists_todays_rides_and_hides_earlier_days(): void
    {
        $yesterday = $this->assignRide('assigned', Carbon::now()->subDay()->setTimeFromTimeString('10:00'));
        $today = $this->assignRide('assigned', Carbon::now()->setTimeFromTimeString('15:00'));
        $tomorrow = $this->assignRide('assigned', Carbon::now()->addDay()->setTimeFromTimeString('09:00'));

        $ids = $this->rideIds($this->getJson('/api/v1/drivers/me/rides?scope=today'));

        $this->assertContains($today->id, $ids);
        $this->assertNotContains($yesterday->id, $ids);
        $this->assertNotContains($tomorrow->id, $ids);
    }

    public function test_today_scope_keeps_a_ride_that_is_still_in_progress(): void
    {
        $startedYesterday = $this->assignRide('picked_up', Carbon::now()->subDay()->setTimeFromTimeString('23:00'));

        $ids = $this->rideIds($this->getJson('/api/v1/drivers/me/rides?scope=today'));

        $this->assertContains($startedYesterday->id, $ids);
    }

    public function test_today_scope_follows_the_agency_timezone(): void
    {
        $this->tenant->settings()->update(['timezone' => 'America/Chicago']);

        // 02:00 UTC is still the previous day in Chicago.
        $lateYesterday = $this->assignRide('assigned', Carbon::now('UTC')->startOfDay()->addHours(2));
        $today = $this->assignRide('assigned', Carbon::now('UTC')->startOfDay()->addHours(14));

        $ids = $this->rideIds($this->getJson('/api/v1/drivers/me/rides?scope=today'));

        $this->assertContains($today->id, $ids);
        $this->assertNotContains($lateYesterday->id, $ids);
    }

    public function test_today_scope_leaves_out_rides_the_driver_already_finished(): void
    {
        $finished = $this->assignRide('completed', Carbon::now()->setTimeFromTimeString('09:00'));
        $cancelled = $this->assignRide('cancelled', Carbon::now()->setTimeFromTimeString('11:00'));
        $open = $this->assignRide('assigned', Carbon::now()->setTimeFromTimeString('15:00'));

        $ids = $this->rideIds($this->getJson('/api/v1/drivers/me/rides?scope=today'));

        $this->assertContains($open->id, $ids);
        $this->assertNotContains($finished->id, $ids);
        $this->assertNotContains($cancelled->id, $ids);
    }

    public function test_later_scope_lists_only_the_following_days(): void
    {
        $yesterday = $this->assignRide('assigned', Carbon::now()->subDay()->setTimeFromTimeString('10:00'));
        $today = $this->assignRide('assigned', Carbon::now()->setTimeFromTimeString('15:00'));
        $nextWeek = $this->assignRide('assigned', Carbon::now()->addWeek()->setTimeFromTimeString('09:00'));

        $ids = $this->rideIds($this->getJson('/api/v1/drivers/me/rides?scope=later'));

        $this->assertContains($nextWeek->id, $ids);
        $this->assertNotContains($today->id, $ids);
        $this->assertNotContains($yesterday->id, $ids);
    }

    public function test_rides_without_a_scope_still_list_every_assignment(): void
    {
        $yesterday = $this->assignRide('assigned', Carbon::now()->subDay()->setTimeFromTimeString('10:00'));
        $today = $this->assignRide('assigned', Carbon::now()->setTimeFromTimeString('15:00'));

        $ids = $this->rideIds($this->getJson('/api/v1/drivers/me/rides'));

        $this->assertContains($yesterday->id, $ids);
        $this->assertContains($today->id, $ids);
    }

    public function test_pending_rides_can_be_scoped_to_the_current_day_and_later(): void
    {
        $yesterday = $this->makeRide('pending', Carbon::now()->subDay()->setTimeFromTimeString('10:00'));
        $today = $this->makeRide('pending', Carbon::now()->setTimeFromTimeString('15:00'));

        $ids = $this->rideIds($this->getJson('/api/v1/rides?status=pending&scope=upcoming'));

        $this->assertContains($today->id, $ids);
        $this->assertNotContains($yesterday->id, $ids);
    }

    public function test_an_unknown_scope_is_rejected(): void
    {
        $this->getJson('/api/v1/drivers/me/rides?scope=yesterday')
            ->assertStatus(422)
            ->assertJsonValidationErrors('scope');
    }

    private function assignRide(string $status, Carbon $pickupAt): Ride
    {
        $ride = $this->makeRide($status, $pickupAt);

        RideAssignment::create([
            'tenant_id' => $this->tenant->id,
            'ride_id' => $ride->id,
            'driver_profile_id' => $this->profile->id,
            'assigned_by' => $this->driver->id,
            'assigned_at' => now(),
        ]);

        return $ride;
    }

    private function makeRide(string $status, Carbon $pickupAt): Ride
    {
        return Ride::create([
            'tenant_id' => $this->tenant->id,
            'status_id' => $this->statuses[$status]->id,
            'requested_by' => $this->driver->id,
            'passenger_info' => ['name' => 'Pat Rider', 'phone' => '5550100'],
            'pickup_address' => '1 Main St',
            'pickup_lat' => 44.5,
            'pickup_lng' => -88.5,
            'dropoff_address' => '2 Main St',
            'dropoff_lat' => 44.6,
            'dropoff_lng' => -88.6,
            'scheduled_pickup_at' => $pickupAt,
            'price_cents' => 1000,
            'currency' => 'USD',
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function rideIds($response): array
    {
        return collect($response->json('data'))->pluck('id')->all();
    }
}
