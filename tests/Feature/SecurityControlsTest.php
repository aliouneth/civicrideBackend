<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SecurityAuditLogger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HIPAA §164.312 / SOC 2 technical safeguards: credential-endpoint rate
 * limiting, the security audit trail, its read permission, and token expiry.
 */
class SecurityControlsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $supervisor;

    private User $scheduler;

    protected function setUp(): void
    {
        parent::setUp();

        // Permissions must exist before tenant roles are synced (the seeder
        // attaches whatever Permission rows exist by name).
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

        $this->supervisor = $this->makeUser('supervisor', 'Sam Supervisor', 'sam@acme.test');
        $this->scheduler = $this->makeUser('scheduler', 'Kim Scheduler', 'kim@acme.test');
    }

    public function test_login_attempts_are_rate_limited_per_email(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'victim@acme.test',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'victim@acme.test',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_registration_attempts_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/v1/auth/register', [])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/register', [])->assertStatus(429);
    }

    public function test_a_failed_login_is_recorded_in_the_audit_trail(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => $this->supervisor->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);

        $event = SecurityEvent::where('event', 'auth.login_failed')->sole();

        $this->assertNull($event->actor_user_id);
        $this->assertSame($this->supervisor->id, $event->subject_id);
        $this->assertSame($this->tenant->id, $event->tenant_id);
        $this->assertSame(['email' => $this->supervisor->email], $event->context);
        $this->assertNotNull($event->ip_address);
        $this->assertNotNull($event->created_at);
    }

    public function test_login_and_logout_are_recorded_in_the_audit_trail(): void
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $this->supervisor->email,
            'password' => 'Sup3rvisorPass!',
        ])->assertStatus(200)->json('token');

        $this->assertNotNull($token);

        $loginEvent = SecurityEvent::where('event', 'auth.login')->sole();
        $this->assertSame($this->supervisor->id, $loginEvent->actor_user_id);
        $this->assertSame($this->tenant->id, $loginEvent->tenant_id);

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertStatus(200);

        $this->assertNotNull(SecurityEvent::where('event', 'auth.logout')->first());
    }

    public function test_reading_the_audit_trail_requires_the_security_view_permission(): void
    {
        Sanctum::actingAs($this->scheduler);
        $this->getJson('/api/v1/security/events')->assertStatus(403);

        Sanctum::actingAs($this->supervisor);
        $this->getJson('/api/v1/security/events')->assertStatus(200);
    }

    public function test_audit_events_are_scoped_to_the_request_tenant(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Beta Cab',
            'slug' => 'beta',
            'contact_email' => 'ops@beta.test',
            'is_active' => true,
        ]);

        $mine = SecurityEvent::create([
            'tenant_id' => $this->tenant->id,
            'actor_user_id' => $this->supervisor->id,
            'event' => 'auth.login',
        ]);
        $theirs = SecurityEvent::create([
            'tenant_id' => $otherTenant->id,
            'event' => 'auth.login',
        ]);

        Sanctum::actingAs($this->supervisor);
        $ids = collect($this->getJson('/api/v1/security/events')->assertStatus(200)->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
    }

    public function test_registration_rejects_passwords_that_fail_the_policy(): void
    {
        $payload = [
            'name' => 'Pat Rider',
            'email' => 'pat@acme.test',
            'license_number' => 'L-1',
            'license_expiry' => now()->addYear()->toDateString(),
            'vehicle_make' => 'Ford',
            'vehicle_model' => 'Transit',
            'vehicle_year' => 2020,
            'vehicle_color' => 'White',
            'vehicle_plate' => 'ABC-123',
            'turnstile_token' => 'test-token',
        ];

        // Too short.
        $this->withHeaders(['X-Tenant' => 'acme'])->postJson('/api/v1/auth/register', $payload + [
            'password' => 'short1',
            'password_confirmation' => 'short1',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        // Long enough but no digits.
        $this->withHeaders(['X-Tenant' => 'acme'])->postJson('/api/v1/auth/register', $payload + [
            'password' => 'abcdefghijkl',
            'password_confirmation' => 'abcdefghijkl',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        // Meets the policy: 10+ characters with letters and numbers.
        $this->withHeaders(['X-Tenant' => 'acme'])->postJson('/api/v1/auth/register', $payload + [
            'password' => 'StrongPass123',
            'password_confirmation' => 'StrongPass123',
        ])->assertStatus(201);

        $this->assertNotNull(SecurityEvent::where('event', 'auth.register')->first());
    }

    public function test_api_tokens_expire(): void
    {
        $this->assertSame(43200, config('sanctum.expiration'));

        $expired = $this->supervisor->createToken('api', ['supervisor'], now()->subDay());
        $this->withToken($expired->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);

        $fresh = $this->supervisor->createToken('api', ['supervisor']);
        $this->withToken($fresh->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200);
    }

    public function test_the_audit_logger_records_actor_subject_and_context(): void
    {
        $event = SecurityAuditLogger::record(
            'user.invited',
            request(),
            actor: $this->supervisor,
            subject: $this->scheduler,
            context: ['role' => 'scheduler'],
        );

        $this->assertSame('user.invited', $event->event);
        $this->assertSame($this->supervisor->id, $event->actor_user_id);
        $this->assertSame($this->scheduler->id, $event->subject_id);
        $this->assertSame('users', $event->subject_type);
        $this->assertSame($this->tenant->id, $event->tenant_id);
        $this->assertSame(['role' => 'scheduler'], $event->context);
    }

    private function makeUser(string $roleName, string $name, string $email): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id,
            'role_id' => Role::where('tenant_id', $this->tenant->id)->where('name', $roleName)->firstOrFail()->id,
            'name' => $name,
            'email' => $email,
            'password' => 'Sup3rvisorPass!',
            'is_active' => true,
        ]);
    }
}
