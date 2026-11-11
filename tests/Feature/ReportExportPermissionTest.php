<?php

namespace Tests\Feature;

use App\Jobs\ExportReportJob;
use App\Models\Permission;
use App\Models\ReportExport;
use App\Models\Ride;
use App\Models\RideStatus;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Exports move PHI/PII out of the app: only roles holding reports.export
 * (supervisor/admin) may queue or read one. The queued job also has to know
 * which clock produced the file, so the requester's zone is pinned at request
 * time.
 */
class ReportExportPermissionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $scheduler;

    private User $driver;

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

        $roleId = fn (string $name): int => Role::where('tenant_id', $this->tenant->id)->where('name', $name)->firstOrFail()->id;

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $roleId('admin'),
            'name' => 'Ada Admin',
            'email' => 'ada@acme.test',
            'password' => 'password123',
        ]);
        $this->scheduler = User::create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $roleId('scheduler'),
            'name' => 'Sam Scheduler',
            'email' => 'sam@acme.test',
            'password' => 'password123',
        ]);
        $this->driver = User::create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $roleId('driver'),
            'name' => 'Dee Driver',
            'email' => 'dee@acme.test',
            'password' => 'password123',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['type' => 'rides.range', 'format' => 'csv', 'from' => '2026-11-01', 'to' => '2026-11-30'];
    }

    public function test_drivers_cannot_queue_exports(): void
    {
        Sanctum::actingAs($this->driver);

        $this->postJson('/api/v1/reports/exports', $this->payload())->assertStatus(403);
    }

    public function test_schedulers_cannot_queue_exports(): void
    {
        Sanctum::actingAs($this->scheduler);

        $this->postJson('/api/v1/reports/exports', $this->payload())->assertStatus(403);
    }

    public function test_admins_queue_an_export_pinned_to_their_zone(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin);

        $this->withHeader('X-Timezone', 'America/New_York')
            ->postJson('/api/v1/reports/exports', $this->payload())
            ->assertStatus(202);

        Queue::assertPushed(ExportReportJob::class);

        $export = ReportExport::sole();
        $this->assertSame('queued', $export->status);
        $this->assertSame('America/New_York', $export->filters['timezone']);

        // Reading the export back stays on the same permission.
        $this->getJson("/api/v1/reports/exports/{$export->id}")->assertOk();

        Sanctum::actingAs($this->driver);
        $this->getJson("/api/v1/reports/exports/{$export->id}")->assertStatus(403);
    }

    public function test_the_export_file_renders_pickups_in_the_requesters_zone(): void
    {
        Storage::fake(config('reports.export_disk'));
        Sanctum::actingAs($this->admin);

        $status = RideStatus::create([
            'code' => 'pending',
            'label' => 'Pending',
            'sort_order' => 1,
            'is_terminal' => false,
            'allows_assignment' => true,
        ]);
        Ride::create([
            'tenant_id' => $this->tenant->id,
            'status_id' => $status->id,
            'requested_by' => $this->admin->id,
            'passenger_info' => ['name' => 'Pat Rider', 'phone' => '5550100'],
            'pickup_address' => '1 Main St',
            'pickup_lat' => 44.5,
            'pickup_lng' => -88.5,
            'dropoff_address' => '2 Main St',
            'dropoff_lat' => 44.6,
            'dropoff_lng' => -88.6,
            'scheduled_pickup_at' => Carbon::parse('2026-11-20 16:30:00', 'UTC'),
            'price_cents' => 1000,
            'currency' => 'USD',
        ]);

        $export = ReportExport::create([
            'tenant_id' => $this->tenant->id,
            'requested_by' => $this->admin->id,
            'type' => 'rides.range',
            'filters' => ['format' => 'csv', 'from' => '2026-11-01', 'to' => '2026-11-30', 'timezone' => 'America/New_York'],
        ]);

        (new ExportReportJob($export))->handle();

        $this->assertSame('ready', $export->refresh()->status);

        $csv = Storage::disk(config('reports.export_disk'))->get($export->file_path);
        $this->assertStringContainsString('2026-11-20T11:30:00-05:00', $csv, 'The file must show the requester-local time, not UTC.');
    }
}
