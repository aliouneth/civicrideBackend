<?php

namespace Tests\Feature;

use App\Models\DriverProfile;
use App\Models\Permission;
use App\Models\Ride;
use App\Models\RideAssignment;
use App\Models\RideStatus;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Double-booking guard on POST /rides/{ride}/assign.
 *
 * Regressions covered: re-assigning a ride to its current driver always failed
 * (the ride conflicted with itself), and any ride still marked in-progress —
 * however old — blocked every future assignment for that driver.
 */
class RideAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $scheduler;

    private User $driver;

    private DriverProfile $profile;

    /** @var array<string, RideStatus> */
    private array $statuses = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (array_keys(RolePermissionSeeder::MATRIX) as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $this->tenant = Tenant::create([
            'name' => 'Acme Transit',
            'slug' => 'acme',
            'contact_email' => 'ops@acme.test',
            'is_active' => true,
        ]);
        $this->tenant->settings()->create(['timezone' => 'UTC', 'currency' => 'USD']);
        RolePermissionSeeder::seedTenantRoles($this->tenant);

        $this->scheduler = $this->makeUser('scheduler', 'Kim Scheduler', 'kim@acme.test');
        $this->driver = $this->makeUser('driver', 'Dana Driver', 'dana@acme.test');

        $this->profile = DriverProfile::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->driver->id,
            'license_number' => 'D-1',
            'onboarding_status' => 'approved',
        ]);

        foreach (['pending', 'assigned', 'en_route', 'at_pickup', 'transporting', 'completed', 'cancelled'] as $index => $code) {
            $this->statuses[$code] = RideStatus::create([
                'code' => $code,
                'label' => ucfirst(str_replace('_', ' ', $code)),
                'sort_order' => $index + 1,
                'is_terminal' => in_array($code, ['completed', 'cancelled'], true),
                'allows_assignment' => in_array($code, ['pending', 'assigned'], true),
            ]);
        }
    }

    public function test_a_free_driver_can_be_assigned_a_pending_ride(): void
    {
        $ride = $this->makeRide('pending', '2026-11-10 09:00:00');

        $this->asScheduler()
            ->postJson("/api/v1/rides/{$ride->id}/assign", ['driver_profile_id' => $this->profile->id])
            ->assertCreated();

        $this->assertSame($this->statuses['assigned']->id, $ride->fresh()->status_id);
        $this->assertSame(1, $ride->assignments()->whereNull('unassigned_at')->count());
    }

    public function test_reassigning_the_same_ride_to_the_same_driver_is_not_an_overlap(): void
    {
        $ride = $this->makeRide('assigned', '2026-11-10 09:00:00');
        $this->assignOpen($ride);

        $this->asScheduler()
            ->postJson("/api/v1/rides/{$ride->id}/assign", ['driver_profile_id' => $this->profile->id])
            ->assertCreated();

        $this->assertSame(1, $ride->assignments()->whereNull('unassigned_at')->count());
        $this->assertSame($this->profile->id, $ride->fresh('activeAssignment')->activeAssignment->driver_profile_id);
    }

    public function test_a_stale_in_progress_ride_does_not_block_a_later_ride(): void
    {
        $this->travelTo(Carbon::parse('2026-11-08 10:00:00'));

        $stale = $this->makeRide('transporting', '2026-10-07 08:41:11');
        $this->assignOpen($stale);

        $ride = $this->makeRide('pending', '2026-11-09 08:00:00');

        $this->asScheduler()
            ->postJson("/api/v1/rides/{$ride->id}/assign", ['driver_profile_id' => $this->profile->id])
            ->assertCreated();
    }

    public function test_an_ancient_in_progress_ride_does_not_block_a_pickup_happening_now(): void
    {
        $this->travelTo(Carbon::parse('2026-11-08 10:00:00'));

        $stale = $this->makeRide('transporting', '2026-10-07 08:41:11');
        $this->assignOpen($stale);

        $ride = $this->makeRide('pending', '2026-11-08 10:30:00');

        $this->asScheduler()
            ->postJson("/api/v1/rides/{$ride->id}/assign", ['driver_profile_id' => $this->profile->id])
            ->assertCreated();
    }

    public function test_a_pickup_inside_the_overlap_buffer_is_rejected(): void
    {
        $existing = $this->makeRide('assigned', '2026-11-10 09:00:00');
        $this->assignOpen($existing);

        $ride = $this->makeRide('pending', '2026-11-10 10:00:00');

        $this->asScheduler()
            ->postJson("/api/v1/rides/{$ride->id}/assign", ['driver_profile_id' => $this->profile->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('driver');
    }

    public function test_an_active_ride_blocks_a_pickup_that_starts_soon(): void
    {
        $this->travelTo(Carbon::parse('2026-11-08 10:00:00'));

        $active = $this->makeRide('transporting', '2026-11-08 08:30:00');
        $this->assignOpen($active);

        // 140 minutes after the active pickup (outside the pickup buffer) but
        // only 50 minutes from now, while the driver is still transporting.
        $ride = $this->makeRide('pending', '2026-11-08 10:50:00');

        $this->asScheduler()
            ->postJson("/api/v1/rides/{$ride->id}/assign", ['driver_profile_id' => $this->profile->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('driver');
    }

    public function test_an_active_ride_does_not_block_a_ride_scheduled_for_another_day(): void
    {
        $this->travelTo(Carbon::parse('2026-11-08 10:00:00'));

        $active = $this->makeRide('transporting', '2026-11-08 09:30:00');
        $this->assignOpen($active);

        $ride = $this->makeRide('pending', '2026-11-09 09:00:00');

        $this->asScheduler()
            ->postJson("/api/v1/rides/{$ride->id}/assign", ['driver_profile_id' => $this->profile->id])
            ->assertCreated();
    }

    private function asScheduler(): static
    {
        Sanctum::actingAs($this->scheduler);

        return $this->withHeader('X-Tenant', $this->tenant->slug);
    }

    private function makeUser(string $roleName, string $name, string $email): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id,
            'role_id' => Role::where('tenant_id', $this->tenant->id)->where('name', $roleName)->firstOrFail()->id,
            'name' => $name,
            'email' => $email,
            'password' => 'password123',
            'is_active' => true,
        ]);
    }

    private function makeRide(string $status, string $pickupAt): Ride
    {
        return Ride::create([
            'tenant_id' => $this->tenant->id,
            'status_id' => $this->statuses[$status]->id,
            'requested_by' => $this->scheduler->id,
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

    private function assignOpen(Ride $ride): RideAssignment
    {
        return RideAssignment::create([
            'tenant_id' => $this->tenant->id,
            'ride_id' => $ride->id,
            'driver_profile_id' => $this->profile->id,
            'assigned_by' => $this->scheduler->id,
            'assigned_at' => now(),
        ]);
    }
}
