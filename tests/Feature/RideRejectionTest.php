<?php

namespace Tests\Feature;

use App\Models\DriverProfile;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Ride;
use App\Models\RideAssignment;
use App\Models\RideStatus;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rejecting an assignment is a driver-only action. The ride returns to the
 * dispatch board as unassigned (status `pending`, assignment row closed) and
 * dispatch/schedulers cannot invoke it — they cancel instead.
 */
class RideRejectionTest extends TestCase
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
            $this->statuses[$code] = RideStatus::firstOrCreate(
                ['code' => $code],
                [
                    'label' => ucfirst(str_replace('_', ' ', $code)),
                    'sort_order' => $index + 1,
                    'is_terminal' => in_array($code, ['completed', 'cancelled'], true),
                    'allows_assignment' => in_array($code, ['pending', 'assigned'], true),
                ],
            );
        }
    }

    public function test_the_assigned_driver_can_reject_and_the_ride_returns_to_the_board(): void
    {
        $ride = $this->makeAssignedRide();

        $this->asDriver($this->driver)
            ->postJson("/api/v1/rides/{$ride->id}/transition", [
                'to_code' => 'unassigned',
                'reason' => 'Rejected by driver',
            ])
            ->assertOk();

        // Ride is unassigned on the board (pending) and carries no active driver.
        $this->assertSame($this->statuses['pending']->id, $ride->fresh()->status_id);
        $this->assertSame(0, $ride->assignments()->whereNull('unassigned_at')->count());

        $assignment = $ride->assignments()->latest('id')->first();
        $this->assertNotNull($assignment->unassigned_at);
        $this->assertSame('Rejected by driver', $assignment->unassign_reason);

        // Dispatch staff get an active inbox alert; the driver does not.
        $this->assertDatabaseHas('notifications', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->scheduler->id,
            'type' => 'ride.unassigned',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->driver->id,
            'type' => 'ride.unassigned',
        ]);
    }

    public function test_a_scheduler_cannot_reject_an_assignment(): void
    {
        $ride = $this->makeAssignedRide();

        $this->asScheduler()
            ->postJson("/api/v1/rides/{$ride->id}/transition", ['to_code' => 'unassigned'])
            ->assertForbidden();

        // Untouched: still assigned with an open assignment.
        $this->assertSame($this->statuses['assigned']->id, $ride->fresh()->status_id);
        $this->assertSame(1, $ride->assignments()->whereNull('unassigned_at')->count());
    }

    public function test_a_different_driver_cannot_reject_someone_elses_assignment(): void
    {
        $ride = $this->makeAssignedRide();

        $other = $this->makeUser('driver', 'Oli Other', 'oli@acme.test');
        DriverProfile::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $other->id,
            'license_number' => 'D-2',
            'onboarding_status' => 'approved',
        ]);

        $this->asDriver($other)
            ->postJson("/api/v1/rides/{$ride->id}/transition", ['to_code' => 'unassigned'])
            ->assertForbidden();

        $this->assertSame(1, $ride->assignments()->whereNull('unassigned_at')->count());
    }

    public function test_a_scheduler_can_still_cancel_an_upcoming_ride(): void
    {
        $ride = $this->makeAssignedRide();

        $this->asScheduler()
            ->postJson("/api/v1/rides/{$ride->id}/transition", [
                'to_code' => 'cancelled',
                'reason' => 'other',
            ])
            ->assertOk();

        $this->assertSame($this->statuses['cancelled']->id, $ride->fresh()->status_id);
    }

    private function asScheduler(): static
    {
        Sanctum::actingAs($this->scheduler);

        return $this->withHeader('X-Tenant', $this->tenant->slug);
    }

    private function asDriver(User $driver): static
    {
        Sanctum::actingAs($driver);

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

    private function makeAssignedRide(): Ride
    {
        $ride = Ride::create([
            'tenant_id' => $this->tenant->id,
            'status_id' => $this->statuses['assigned']->id,
            'requested_by' => $this->scheduler->id,
            'passenger_info' => ['name' => 'Pat Rider', 'phone' => '5550100'],
            'pickup_address' => '1 Main St',
            'pickup_lat' => 44.5,
            'pickup_lng' => -88.5,
            'dropoff_address' => '2 Main St',
            'dropoff_lat' => 44.6,
            'dropoff_lng' => -88.6,
            'scheduled_pickup_at' => '2026-11-10 09:00:00',
            'price_cents' => 1000,
            'currency' => 'USD',
        ]);

        RideAssignment::create([
            'tenant_id' => $this->tenant->id,
            'ride_id' => $ride->id,
            'driver_profile_id' => $this->profile->id,
            'assigned_by' => $this->scheduler->id,
            'assigned_at' => now(),
        ]);

        return $ride;
    }
}
