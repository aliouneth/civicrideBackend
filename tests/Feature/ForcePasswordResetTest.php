<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Platform administrators can force any user to choose a new password
 * (HIPAA §164.312(d), SOC 2 CC6.1): the flag blocks every API call with
 * 423 until POST /auth/change-password clears it.
 */
class ForcePasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $supervisor;

    private User $platformAdmin;

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

        $this->supervisor = User::create([
            'tenant_id' => $this->tenant->id,
            'role_id' => Role::where('tenant_id', $this->tenant->id)->where('name', 'supervisor')->firstOrFail()->id,
            'name' => 'Sam Supervisor',
            'email' => 'sam@acme.test',
            'password' => 'Sup3rvisorPass!',
            'is_active' => true,
        ]);

        // Platform admins carry no tenant (users.tenant_id nullable) but still
        // need a role: issueToken reads $user->role->name.
        $platformRole = Role::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'platform-admin'],
            ['description' => 'Platform Administrator'],
        );
        $this->platformAdmin = User::create([
            'role_id' => $platformRole->id,
            'name' => 'Pat Platform',
            'email' => 'pat@platform.test',
            'password' => 'Platf0rmPass!',
            'is_platform_admin' => true,
            'is_active' => true,
        ]);
    }

    public function test_a_platform_admin_can_force_a_password_reset(): void
    {
        Sanctum::actingAs($this->platformAdmin);

        $this->patchJson("/api/v1/platform/users/{$this->supervisor->id}/force-password-reset")
            ->assertStatus(200);

        $this->assertTrue($this->supervisor->refresh()->must_change_password);

        $event = SecurityEvent::where('event', 'platform.user_password_reset_forced')->sole();
        $this->assertSame($this->platformAdmin->id, $event->actor_user_id);
        $this->assertSame($this->supervisor->id, $event->subject_id);
    }

    public function test_agency_users_cannot_force_a_password_reset(): void
    {
        Sanctum::actingAs($this->supervisor);

        $this->patchJson("/api/v1/platform/users/{$this->platformAdmin->id}/force-password-reset")
            ->assertStatus(403);

        $this->assertFalse($this->platformAdmin->refresh()->must_change_password);
        $this->assertDatabaseMissing('security_events', ['event' => 'platform.user_password_reset_forced']);
    }

    public function test_a_forced_user_is_blocked_until_the_password_changes(): void
    {
        $this->supervisor->update(['must_change_password' => true]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $this->supervisor->email,
            'password' => 'Sup3rvisorPass!',
        ])->assertStatus(200);

        $token = $login->json('token');
        $this->assertTrue($login->json('must_change_password'));

        // The change flow itself stays reachable: profile read, sign-out, change.
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);

        // Everything else is locked with 423 + the code the client redirects on.
        $this->withToken($token)->getJson('/api/v1/tenant')
            ->assertStatus(423)
            ->assertJsonPath('code', 'password_change_required');

        // Wrong current password and a weak new password are both rejected.
        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'wrong-password',
            'password' => 'N3wPass123!',
            'password_confirmation' => 'N3wPass123!',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'Sup3rvisorPass!',
            'password' => 'tooeasy',
            'password_confirmation' => 'tooeasy',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        // A compliant change clears the flag and unlocks the account.
        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'Sup3rvisorPass!',
            'password' => 'N3wPass123!',
            'password_confirmation' => 'N3wPass123!',
        ])->assertStatus(200);

        $this->assertFalse($this->supervisor->refresh()->must_change_password);

        $event = SecurityEvent::where('event', 'auth.password_changed')->sole();
        $this->assertSame(['forced_reset' => true], $event->context);

        $this->withToken($token)->getJson('/api/v1/tenant')->assertStatus(200);

        // A fresh login no longer demands a change.
        $this->assertFalse($this->postJson('/api/v1/auth/login', [
            'email' => $this->supervisor->email,
            'password' => 'N3wPass123!',
        ])->json('must_change_password'));
    }
}
