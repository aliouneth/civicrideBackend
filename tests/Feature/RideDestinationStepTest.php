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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RideDestinationStepTest extends TestCase
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

        $this->driver = User::create([
            'tenant_id' => $this->tenant->id,
            'role_id' => Role::where('tenant_id', $this->tenant->id)->where('name', 'driver')->firstOrFail()->id,
            'name' => 'Dana Driver',
            'email' => 'dana@acme.test',
            'password' => 'password123',
            'is_active' => true,
        ]);

        $this->profile = DriverProfile::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->driver->id,
            'license_number' => 'D-1',
            'onboarding_status' => 'approved',
        ]);

        foreach (['pending', 'assigned', 'en_route', 'at_pickup', 'transporting', 'at_destination', 'completed', 'cancelled'] as $index => $code) {
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

    public function test_arrival_step_records_metadata_and_completion_requires_mileage(): void
    {
        $this->travelTo('2026-11-27 10:00:00');
        $ride = $this->makeAssignedRide();

        foreach (['en_route', 'at_pickup', 'transporting'] as $code) {
            $this->asDriver()
                ->postJson("/api/v1/rides/{$ride->id}/transition", ['to_code' => $code])
                ->assertOk()
                ->assertJsonPath('status.code', $code);
        }

        $this->asDriver()
            ->postJson("/api/v1/rides/{$ride->id}/transition", [
                'to_code' => 'at_destination',
                'note' => 'Arrived at the main gate.',
                'incident' => 'Traffic delay cleared.',
                'lat' => 44.01,
                'lng' => -88.01,
            ])
            ->assertOk()
            ->assertJsonPath('status.code', 'at_destination');

        $event = $ride->events()->where('to_status_id', $this->statuses['at_destination']->id)->firstOrFail();
        $this->assertSame('Arrived at the main gate.', $event->metadata['note']);
        $this->assertSame('Traffic delay cleared.', $event->metadata['incident']);
        $this->assertSame(44.01, $event->metadata['lat']);
        $this->assertSame(-88.01, $event->metadata['lng']);
        $this->assertSame($this->driver->id, $event->actor_id);
        $this->assertNotNull($event->created_at);
        $this->assertNull($ride->fresh()->actual_dropoff_at);

        $this->asDriver()
            ->postJson("/api/v1/rides/{$ride->id}/transition", ['to_code' => 'completed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mileage_claimed');

        $this->asDriver()
            ->postJson("/api/v1/rides/{$ride->id}/transition", [
                'to_code' => 'completed',
                'mileage_claimed' => 12.5,
            ])
            ->assertOk()
            ->assertJsonPath('status.code', 'completed');

        $this->assertSame(12.5, $ride->fresh()->mileage_claimed);
        $this->assertNotNull($ride->fresh()->actual_dropoff_at);

        $completionEvent = $ride->events()->where('to_status_id', $this->statuses['completed']->id)->firstOrFail();
        $this->assertSame(12.5, $completionEvent->metadata['mileage_claimed']);
    }

    public function test_transporting_cannot_skip_the_arrival_step(): void
    {
        $this->travelTo('2026-11-27 10:00:00');
        $ride = $this->makeAssignedRide();

        foreach (['en_route', 'at_pickup', 'transporting'] as $code) {
            $this->asDriver()
                ->postJson("/api/v1/rides/{$ride->id}/transition", ['to_code' => $code])
                ->assertOk();
        }

        $this->asDriver()
            ->postJson("/api/v1/rides/{$ride->id}/transition", [
                'to_code' => 'completed',
                'mileage_claimed' => 12.5,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to_code');

        $this->assertSame($this->statuses['transporting']->id, $ride->fresh()->status_id);
        $this->assertSame(0, $ride->events()->where('to_status_id', $this->statuses['completed']->id)->count());
    }

    public function test_driver_can_go_back_from_arrival_to_transporting(): void
    {
        $this->travelTo('2026-11-27 10:00:00');
        $ride = $this->makeAssignedRide();

        foreach (['en_route', 'at_pickup', 'transporting', 'at_destination'] as $code) {
            $this->asDriver()
                ->postJson("/api/v1/rides/{$ride->id}/transition", ['to_code' => $code])
                ->assertOk();
        }

        $this->asDriver()
            ->postJson("/api/v1/rides/{$ride->id}/transition", [
                'to_code' => 'transporting',
                'note' => 'Passenger still onboard.',
            ])
            ->assertOk()
            ->assertJsonPath('status.code', 'transporting');

        $event = $ride->events()
            ->where('from_status_id', $this->statuses['at_destination']->id)
            ->where('to_status_id', $this->statuses['transporting']->id)
            ->firstOrFail();
        $this->assertSame('Passenger still onboard.', $event->metadata['note']);
    }

    private function asDriver(): static
    {
        Sanctum::actingAs($this->driver);

        return $this->withHeader('X-Tenant', $this->tenant->slug);
    }

    private function makeAssignedRide(): Ride
    {
        $ride = Ride::create([
            'tenant_id' => $this->tenant->id,
            'status_id' => $this->statuses['assigned']->id,
            'requested_by' => $this->driver->id,
            'passenger_info' => ['name' => 'Pat Rider', 'phone' => '5550100'],
            'pickup_address' => '1 Main St',
            'pickup_lat' => 44.5,
            'pickup_lng' => -88.5,
            'dropoff_address' => '2 Main St',
            'dropoff_lat' => 44.6,
            'dropoff_lng' => -88.6,
            'scheduled_pickup_at' => '2026-11-27 09:00:00',
            'price_cents' => 1000,
            'currency' => 'USD',
        ]);

        RideAssignment::create([
            'tenant_id' => $this->tenant->id,
            'ride_id' => $ride->id,
            'driver_profile_id' => $this->profile->id,
            'assigned_by' => $this->driver->id,
            'assigned_at' => now(),
        ]);

        return $ride;
    }
}
