<?php

namespace Tests\Feature;

use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\RideAssignment;
use App\Models\RideAttestation;
use App\Models\RideStatus;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Agency users review a driver's monthly attestations through the monthly
 * rides report (GET /drivers/report): each ride carries the `attested_at`
 * timestamp the driver signed off, and the summary counts them.
 */
class DriverAttestationReportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $driver;

    private User $scheduler;

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

        $driverRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'driver']);
        $this->driver = User::create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $driverRole->id,
            'name' => 'Dana Driver',
            'email' => 'dana@acme.test',
            'password' => 'password123',
        ]);

        $schedulerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'scheduler']);
        $this->scheduler = User::create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $schedulerRole->id,
            'name' => 'Sam Scheduler',
            'email' => 'sam@acme.test',
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
    }

    public function test_agency_user_sees_which_rides_the_driver_attested(): void
    {
        $month = now()->format('Y-m');
        $attested = $this->assignRide('completed', Carbon::now()->setTimeFromTimeString('09:00'));
        $pendingAttestation = $this->assignRide('cancelled', Carbon::now()->setTimeFromTimeString('11:00'));

        RideAttestation::create([
            'tenant_id' => $this->tenant->id,
            'ride_id' => $attested->id,
            'driver_profile_id' => $this->profile->id,
            'attested_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($this->scheduler);

        $response = $this->getJson("/api/v1/drivers/report?month={$month}&driver_profile_id={$this->profile->id}")
            ->assertOk()
            ->assertJsonPath('summary.rides', 2)
            ->assertJsonPath('summary.attested', 1);

        $rides = collect($response->json('rides'))->keyBy('id');

        $this->assertNotNull($rides[$attested->id]['attested_at'], 'The attested ride must expose its attestation.');
        $this->assertNull($rides[$pendingAttestation->id]['attested_at'], 'An unattested ride must stay null.');
    }

    public function test_driver_sees_their_own_attestations_on_the_self_report(): void
    {
        $month = now()->format('Y-m');
        $attested = $this->assignRide('completed', Carbon::now()->setTimeFromTimeString('09:00'));

        RideAttestation::create([
            'tenant_id' => $this->tenant->id,
            'ride_id' => $attested->id,
            'driver_profile_id' => $this->profile->id,
            'attested_at' => now(),
        ]);

        Sanctum::actingAs($this->driver);

        $response = $this->getJson("/api/v1/drivers/report?month={$month}")
            ->assertOk()
            ->assertJsonPath('summary.attested', 1);

        // The stored datetime has second precision, so compare the day rather
        // than the exact microsecond of `now()`.
        $attestedAt = collect($response->json('rides'))->firstWhere('id', $attested->id)['attested_at'] ?? null;

        $this->assertNotNull($attestedAt);
        $this->assertTrue(now()->isSameDay(Carbon::parse($attestedAt)));
    }

    public function test_attestations_from_another_agency_are_not_exposed(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Other Transit',
            'slug' => 'other',
            'contact_email' => 'ops@other.test',
            'is_active' => true,
        ]);
        $otherRole = Role::create(['tenant_id' => $otherTenant->id, 'name' => 'driver']);
        $otherDriver = User::create([
            'tenant_id' => $otherTenant->id,
            'role_id' => $otherRole->id,
            'name' => 'Pat Driver',
            'email' => 'pat@other.test',
            'password' => 'password123',
        ]);
        $otherProfile = DriverProfile::create([
            'tenant_id' => $otherTenant->id,
            'user_id' => $otherDriver->id,
            'license_number' => 'D-2',
            'license_expiry' => now()->addYear()->toDateString(),
            'vehicle_make' => 'Ford',
            'vehicle_model' => 'Transit',
            'vehicle_year' => 2021,
            'vehicle_color' => 'Black',
            'vehicle_plate' => 'XYZ-9876',
            'onboarding_status' => 'approved',
        ]);

        $month = now()->format('Y-m');
        $ownRide = $this->assignRide('completed', Carbon::now()->setTimeFromTimeString('09:00'));

        $otherRide = Ride::create([
            'tenant_id' => $otherTenant->id,
            'status_id' => $this->statuses['completed']->id,
            'requested_by' => $otherDriver->id,
            'passenger_info' => ['name' => 'Pat Rider', 'phone' => '5550101'],
            'pickup_address' => '9 Other St',
            'pickup_lat' => 44.5,
            'pickup_lng' => -88.5,
            'dropoff_address' => '10 Other St',
            'dropoff_lat' => 44.6,
            'dropoff_lng' => -88.6,
            'scheduled_pickup_at' => Carbon::now()->setTimeFromTimeString('09:00'),
            'price_cents' => 1000,
            'currency' => 'USD',
        ]);
        RideAssignment::create([
            'tenant_id' => $otherTenant->id,
            'ride_id' => $otherRide->id,
            'driver_profile_id' => $otherProfile->id,
            'assigned_by' => $otherDriver->id,
            'assigned_at' => now(),
        ]);
        RideAttestation::create([
            'tenant_id' => $otherTenant->id,
            'ride_id' => $otherRide->id,
            'driver_profile_id' => $otherProfile->id,
            'attested_at' => now(),
        ]);

        Sanctum::actingAs($this->scheduler);

        $response = $this->getJson("/api/v1/drivers/report?month={$month}&driver_profile_id={$this->profile->id}")
            ->assertOk()
            ->assertJsonPath('summary.attested', 0);

        $rides = collect($response->json('rides'))->keyBy('id');

        $this->assertArrayHasKey($ownRide->id, $rides);
        $this->assertArrayNotHasKey($otherRide->id, $rides);
        $this->assertNull($rides[$ownRide->id]['attested_at']);
    }

    public function test_report_requires_authentication(): void
    {
        $this->getJson('/api/v1/drivers/report?month='.now()->format('Y-m'))
            ->assertUnauthorized();
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
}
