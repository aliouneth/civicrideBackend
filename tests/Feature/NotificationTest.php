<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The in-app inbox is personal: a user reads and clears only their own rows,
 * never another agency user's, even inside the same tenant.
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $scheduler;

    private User $otherScheduler;

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

        $this->scheduler = $this->makeUser('Kim Scheduler', 'kim@acme.test');
        $this->otherScheduler = $this->makeUser('Oli Scheduler', 'oli@acme.test');
    }

    public function test_it_lists_only_the_current_users_notifications_with_an_unread_count(): void
    {
        $this->notification($this->scheduler, read: false);
        $this->notification($this->scheduler, read: true);
        $this->notification($this->otherScheduler, read: false);

        $response = $this->actingAsScheduler($this->scheduler)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonCount(2, 'data');

        $this->assertSame(
            [$this->scheduler->id],
            collect($response->json('data'))->pluck('user_id')->unique()->values()->all(),
        );
    }

    public function test_a_user_cannot_mark_another_users_notification_as_read(): void
    {
        $theirs = $this->notification($this->otherScheduler, read: false);

        $this->actingAsScheduler($this->scheduler)
            ->postJson("/api/v1/notifications/{$theirs->id}/read")
            ->assertForbidden();

        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_mark_all_read_only_clears_the_current_users_inbox(): void
    {
        $mine = $this->notification($this->scheduler, read: false);
        $theirs = $this->notification($this->otherScheduler, read: false);

        $this->actingAsScheduler($this->scheduler)
            ->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('updated', 1);

        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertNull($theirs->fresh()->read_at);
    }

    private function actingAsScheduler(User $user): static
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-Tenant', $this->tenant->slug);
    }

    private function makeUser(string $name, string $email): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id,
            'role_id' => Role::where('tenant_id', $this->tenant->id)->where('name', 'scheduler')->firstOrFail()->id,
            'name' => $name,
            'email' => $email,
            'password' => 'password123',
            'is_active' => true,
        ]);
    }

    private function notification(User $user, bool $read): Notification
    {
        return Notification::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'type' => 'ride.unassigned',
            'data' => ['ride_id' => 10],
            'read_at' => $read ? now() : null,
            'created_at' => now(),
        ]);
    }
}
