<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Agency user management: supervisors/admins invite, list, and activate or
 * deactivate users inside their own agency; everyone else is forbidden.
 */
class UserInviteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $supervisor;

    private User $admin;

    private User $scheduler;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();

        // Permissions must exist before tenant roles are synced by name.
        foreach (array_keys(RolePermissionSeeder::MATRIX) as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $this->tenant = $this->makeTenant('acme');
        RolePermissionSeeder::seedTenantRoles($this->tenant);

        $this->supervisor = $this->makeUser('supervisor', 'Sam Supervisor', 'sam@acme.test');
        $this->admin = $this->makeUser('admin', 'Ada Admin', 'ada@acme.test');
        $this->scheduler = $this->makeUser('scheduler', 'Kim Scheduler', 'kim@acme.test');
        $this->driver = $this->makeUser('driver', 'Dana Driver', 'dana@acme.test');
    }

    public function test_a_supervisor_can_create_a_scheduler_and_receives_a_temporary_password(): void
    {
        $response = $this->as($this->supervisor)->postJson('/api/v1/users/invite', [
            'name' => 'New Dispatcher',
            'email' => 'dispatch2@acme.test',
            'phone' => '+1 555 0100',
            'role' => 'scheduler',
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'user' => ['id', 'name', 'email', 'role' => ['name']],
                'temporary_password',
            ])
            ->assertJsonPath('user.role.name', 'scheduler');

        $this->assertSame(16, strlen($response->json('temporary_password')));

        $created = User::where('email', 'dispatch2@acme.test')->sole();
        $this->assertSame($this->tenant->id, $created->tenant_id);
        $this->assertTrue(Hash::check($response->json('temporary_password'), $created->password));
    }

    public function test_an_admin_can_create_users(): void
    {
        $this->as($this->admin)->postJson('/api/v1/users/invite', [
            'name' => 'Second Dispatcher',
            'email' => 'dispatch3@acme.test',
            'role' => 'scheduler',
        ])->assertCreated();

        $this->assertDatabaseHas('users', [
            'tenant_id' => $this->tenant->id,
            'email' => 'dispatch3@acme.test',
        ]);
    }

    public function test_a_driver_cannot_create_list_or_toggle_users(): void
    {
        $this->as($this->driver)->postJson('/api/v1/users/invite', [
            'name' => 'Sneaky User',
            'email' => 'sneaky@acme.test',
            'role' => 'scheduler',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@acme.test']);

        $this->as($this->driver)->getJson('/api/v1/users')->assertForbidden();

        $this->as($this->driver)
            ->patchJson("/api/v1/users/{$this->scheduler->id}/active")
            ->assertForbidden();

        $this->assertTrue($this->scheduler->fresh()->is_active);
    }

    public function test_a_scheduler_cannot_create_list_or_toggle_users(): void
    {
        $this->as($this->scheduler)->postJson('/api/v1/users/invite', [
            'name' => 'Blocked User',
            'email' => 'blocked@acme.test',
            'role' => 'scheduler',
        ])->assertForbidden();

        $this->as($this->scheduler)->getJson('/api/v1/users')->assertForbidden();

        $this->as($this->scheduler)
            ->patchJson("/api/v1/users/{$this->driver->id}/active")
            ->assertForbidden();
    }

    public function test_a_duplicate_email_within_the_agency_is_rejected(): void
    {
        $this->as($this->supervisor)->postJson('/api/v1/users/invite', [
            'name' => 'Duplicate',
            'email' => $this->scheduler->email,
            'role' => 'scheduler',
        ])->assertStatus(422)
            ->assertJson(['message' => 'Email already invited in this agency.']);

        $this->assertSame(1, User::where('email', $this->scheduler->email)->count());
    }

    public function test_creating_a_driver_also_creates_a_driver_profile(): void
    {
        $response = $this->as($this->supervisor)->postJson('/api/v1/users/invite', [
            'name' => 'Rico Driver',
            'email' => 'rico@acme.test',
            'role' => 'driver',
        ])->assertCreated();

        $created = User::findOrFail($response->json('user.id'));

        $this->assertDatabaseHas('driver_profiles', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $created->id,
            'license_number' => 'PENDING',
        ]);
    }

    public function test_roles_outside_the_allowed_set_are_rejected(): void
    {
        $this->as($this->supervisor)->postJson('/api/v1/users/invite', [
            'name' => 'Role Escalation',
            'email' => 'escalate@acme.test',
            'role' => 'supervisor',
        ])->assertStatus(422)->assertJsonValidationErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'escalate@acme.test']);
    }

    public function test_a_supervisor_cannot_deactivate_their_own_account(): void
    {
        $this->as($this->supervisor)
            ->patchJson("/api/v1/users/{$this->supervisor->id}/active")
            ->assertStatus(422);

        $this->assertTrue($this->supervisor->fresh()->is_active);
    }

    public function test_a_supervisor_toggles_another_agency_users_status(): void
    {
        $this->as($this->supervisor)
            ->patchJson("/api/v1/users/{$this->scheduler->id}/active")
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $this->assertFalse($this->scheduler->fresh()->is_active);

        $this->as($this->supervisor)
            ->patchJson("/api/v1/users/{$this->scheduler->id}/active")
            ->assertOk()
            ->assertJsonPath('is_active', true);
    }

    public function test_users_in_another_agency_cannot_be_toggled(): void
    {
        $other = $this->makeTenant('beta');
        RolePermissionSeeder::seedTenantRoles($other);
        $outsider = $this->makeUser('scheduler', 'Bea Outside', 'bea@beta.test', $other);

        $this->as($this->supervisor)
            ->patchJson("/api/v1/users/{$outsider->id}/active")
            ->assertNotFound();

        $this->assertTrue($outsider->fresh()->is_active);
    }

    public function test_the_user_list_is_tenant_scoped_and_excludes_passwords(): void
    {
        $other = $this->makeTenant('beta');
        RolePermissionSeeder::seedTenantRoles($other);
        $outsider = $this->makeUser('scheduler', 'Bea Outside', 'bea@beta.test', $other);

        $users = collect(
            $this->as($this->supervisor)->getJson('/api/v1/users')->assertOk()->json('data')
        );

        $this->assertFalse($users->contains('id', $outsider->id));
        $this->assertTrue($users->contains('id', $this->scheduler->id));

        foreach ($users as $user) {
            $this->assertArrayNotHasKey('password', $user);
            $this->assertArrayNotHasKey('remember_token', $user);
        }
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-Tenant', $this->tenant->slug);
    }

    private function makeTenant(string $slug): Tenant
    {
        $tenant = Tenant::create([
            'name' => ucfirst($slug).' Transit',
            'slug' => $slug,
            'contact_email' => 'ops@'.$slug.'.test',
            'is_active' => true,
        ]);
        $tenant->settings()->create(['timezone' => 'UTC', 'currency' => 'USD']);

        return $tenant;
    }

    private function makeUser(string $roleName, string $name, string $email, ?Tenant $tenant = null): User
    {
        $tenant ??= $this->tenant;

        return User::create([
            'tenant_id' => $tenant->id,
            'role_id' => Role::where('tenant_id', $tenant->id)->where('name', $roleName)->firstOrFail()->id,
            'name' => $name,
            'email' => $email,
            'password' => 'Sup3rvisorPass!',
            'is_active' => true,
        ]);
    }
}
