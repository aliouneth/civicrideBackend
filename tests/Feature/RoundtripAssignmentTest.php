<?php

namespace Tests\Feature;

use App\Models\DriverProfile;
use App\Models\Permission;
use App\Models\Ride;
use App\Models\RideStatus;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A split roundtrip is one booking: assigning the outbound books the same
 * driver for the return leg, so the pair never lands on the board (and in the
 * driver's job list) half-assigned.
 */
class RoundtripAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $scheduler;

    private User $driverUser;

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
        $this->tenant->settings()->create([
            'timezone' => 'UTC',
            'currency' => 'USD',
            'allow_driver_self_assignment' => true,
        ]);
        RolePermissionSeeder::seedTenantRoles($this->tenant);

        $this->scheduler = $this->makeUser('scheduler', 'Kim Scheduler', 'kim@acme.test');
        $this->driverUser = $this->makeUser('driver', 'Dana Driver', 'dana@acme.test');
        $this->profile = $this->makeProfile($this->driverUser, 'D-1');

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

    public function test_assigning_the_outbound_also_assigns_the_return_leg(): void
    {
        [$outbound, $return] = $this->createSplitRoundtrip();

        $this->asScheduler()
            ->postJson("/api/v1/rides/{$outbound->id}/assign", ['driver_profile_id' => $this->profile->id])
            ->assertCreated();

        $this->assertSame($this->statuses['assigned']->id, $outbound->fresh()->status_id);
        $this->assertSame($this->statuses['assigned']->id, $return->fresh()->status_id);
        $this->assertSame($this->profile->id, $outbound->fresh('activeAssignment')->activeAssignment->driver_profile_id);
        $this->assertSame($this->profile->id, $return->fresh('activeAssignment')->activeAssignment->driver_profile_id);

        $board = $this->asScheduler()->getJson('/api/v1/rides/board?include_completed=1&days=30&past_days=0');
        $board->assertOk();
        $boardIds = collect($board->json('groups'))->flatMap(fn ($g) => collect($g['rides'])->pluck('id'))->all();
        $this->assertContains($outbound->id, $boardIds);
        $this->assertContains($return->id, $boardIds);

        $later = $this->asDriver()->getJson('/api/v1/drivers/me/rides?scope=later');
        $later->assertOk();
        $driverIds = collect($later->json('data'))->pluck('id')->all();
        $this->assertContains($outbound->id, $driverIds);
        $this->assertContains($return->id, $driverIds);
    }

    public function test_assigning_the_outbound_reassigns_a_return_leg_held_by_another_driver(): void
    {
        [$outbound, $return] = $this->createSplitRoundtrip();

        $otherUser = $this->makeUser('driver', 'Rico Driver', 'rico@acme.test');
        $otherProfile = $this->makeProfile($otherUser, 'D-2');

        $this->asScheduler()
            ->postJson("/api/v1/rides/{$return->id}/assign", ['driver_profile_id' => $otherProfile->id])
            ->assertCreated();

        $this->asScheduler()
            ->postJson("/api/v1/rides/{$outbound->id}/assign", ['driver_profile_id' => $this->profile->id])
            ->assertCreated();

        $this->assertSame($this->profile->id, $return->fresh('activeAssignment')->activeAssignment->driver_profile_id);
        $this->assertSame(1, $return->assignments()->whereNull('unassigned_at')->count());
    }

    public function test_unassigning_the_outbound_frees_the_return_leg(): void
    {
        [$outbound, $return] = $this->createSplitRoundtrip();

        $this->asScheduler()
            ->postJson("/api/v1/rides/{$outbound->id}/assign", ['driver_profile_id' => $this->profile->id])
            ->assertCreated();

        $this->asScheduler()
            ->deleteJson("/api/v1/rides/{$outbound->id}/assign")
            ->assertOk();

        $this->assertSame($this->statuses['pending']->id, $outbound->fresh()->status_id);
        $this->assertSame($this->statuses['pending']->id, $return->fresh()->status_id);
        $this->assertSame(0, $outbound->assignments()->whereNull('unassigned_at')->count());
        $this->assertSame(0, $return->assignments()->whereNull('unassigned_at')->count());
    }

    public function test_a_self_assigning_driver_gets_the_return_leg_too(): void
    {
        [$outbound, $return] = $this->createSplitRoundtrip();

        $this->asDriver()
            ->postJson("/api/v1/rides/{$outbound->id}/self-assign")
            ->assertCreated();

        $outboundAssignment = $outbound->fresh('activeAssignment')->activeAssignment;
        $returnAssignment = $return->fresh('activeAssignment')->activeAssignment;

        $this->assertSame($this->profile->id, $outboundAssignment->driver_profile_id);
        $this->assertSame($this->profile->id, $returnAssignment->driver_profile_id);
        $this->assertTrue($outboundAssignment->self_assigned);
        $this->assertTrue($returnAssignment->self_assigned);
    }

    public function test_close_legs_of_one_roundtrip_do_not_trip_the_overlap_guard(): void
    {
        [$outbound, $return] = $this->createSplitRoundtrip(returnOffsetMinutes: 30);

        $this->asScheduler()
            ->postJson("/api/v1/rides/{$outbound->id}/assign", ['driver_profile_id' => $this->profile->id])
            ->assertCreated();

        $this->assertSame($this->statuses['assigned']->id, $return->fresh()->status_id);
    }

    /** @return array{0: Ride, 1: Ride} */
    private function createSplitRoundtrip(int $returnOffsetMinutes = 360): array
    {
        $pickup = now()->addDays(2)->setTime(9, 0);
        $returnAt = $pickup->copy()->addMinutes($returnOffsetMinutes);

        $response = $this->asScheduler()->postJson('/api/v1/rides', [
            'passenger_info' => ['name' => 'Pat Rider', 'phone' => '5550100'],
            'pickup_address' => '1 Main St',
            'pickup_lat' => 44.5,
            'pickup_lng' => -88.5,
            'dropoff_address' => '2 Main St',
            'dropoff_lat' => 44.6,
            'dropoff_lng' => -88.6,
            'scheduled_pickup_at' => $pickup->format('Y-m-d\TH:i'),
            'trip_type' => 'roundtrip',
            'return_pickup_at' => $returnAt->format('Y-m-d\TH:i'),
            'split_return' => true,
        ])->assertCreated();

        $outbound = Ride::findOrFail($response->json('id'));
        $return = Ride::findOrFail($response->json('return_ride.id'));

        return [$outbound, $return];
    }

    private function asScheduler(): static
    {
        Sanctum::actingAs($this->scheduler);

        return $this->withHeader('X-Tenant', $this->tenant->slug);
    }

    private function asDriver(): static
    {
        Sanctum::actingAs($this->driverUser);

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

    private function makeProfile(User $user, string $license): DriverProfile
    {
        return DriverProfile::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'license_number' => $license,
            'onboarding_status' => 'approved',
        ]);
    }
}
