<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tenant resolution rules (ResolveTenant middleware).
 *
 * The regression this guards: a driver opening the app from a phone (fresh
 * session, no tenant cookie yet) produced
 * "Unknown or inactive tenant. Send X-Tenant header or use the tenant
 * subdomain." because the API fell back to the hostname of the request, which
 * in production is an infrastructure host (api/app/staging…) and not a tenant.
 */
class TenantResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const UNKNOWN_TENANT_MESSAGE = 'Unknown or inactive tenant. Send X-Tenant header or use the tenant subdomain.';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('tenant')->get('/api/_tenant-probe', fn () => response()->json([
            'tenant' => app()->bound('tenant') ? app('tenant')->slug : null,
        ]));

        Route::middleware(['tenant', 'auth:sanctum'])->get('/api/_tenant-probe-auth', fn () => response()->json([
            'tenant' => app()->bound('tenant') ? app('tenant')->slug : null,
            'user' => request()->user()?->email,
        ]));
    }

    public function test_explicit_tenant_header_resolves_the_agency(): void
    {
        $this->makeTenant('acme');

        $this->get('/api/_tenant-probe', ['X-Tenant' => 'acme'])
            ->assertOk()
            ->assertJson(['tenant' => 'acme']);
    }

    public function test_unknown_tenant_header_is_rejected(): void
    {
        $this->get('/api/_tenant-probe', ['X-Tenant' => 'ghost'])
            ->assertStatus(404)
            ->assertJson(['message' => self::UNKNOWN_TENANT_MESSAGE]);
    }

    public function test_inactive_tenant_header_is_rejected(): void
    {
        $this->makeTenant('dormant', active: false);

        $this->get('/api/_tenant-probe', ['X-Tenant' => 'dormant'])
            ->assertStatus(404)
            ->assertJson(['message' => self::UNKNOWN_TENANT_MESSAGE]);
    }

    public function test_empty_tenant_header_is_no_claim_and_uses_the_public_host(): void
    {
        $this->makeTenant('acme');

        $this->get('http://acme.civicride.com/api/_tenant-probe', ['X-Tenant' => ''])
            ->assertOk()
            ->assertJson(['tenant' => 'acme']);
    }

    public function test_empty_tenant_header_without_a_host_binds_no_tenant(): void
    {
        $this->makeTenant('acme');

        $this->get('/api/_tenant-probe', ['X-Tenant' => ''])
            ->assertOk()
            ->assertJson(['tenant' => null]);
    }

    public function test_platform_claim_binds_no_tenant(): void
    {
        $this->makeTenant('acme');

        $this->get('http://acme.civicride.com/api/_tenant-probe', ['X-Tenant' => 'platform'])
            ->assertOk()
            ->assertJson(['tenant' => null]);
    }

    public function test_tenant_subdomain_resolves_the_agency(): void
    {
        $this->makeTenant('acme');

        $this->get('http://acme.civicride.com/api/_tenant-probe')
            ->assertOk()
            ->assertJson(['tenant' => 'acme']);
    }

    public function test_public_host_forwarded_by_the_edge_resolves_the_agency(): void
    {
        $this->makeTenant('acme');

        $this->get('/api/_tenant-probe', ['X-Tenant-Host' => 'acme.civicride.com'])
            ->assertOk()
            ->assertJson(['tenant' => 'acme']);
    }

    public function test_inactive_tenant_subdomain_is_rejected(): void
    {
        $this->makeTenant('dormant', active: false);

        $this->get('http://dormant.civicride.com/api/_tenant-probe')
            ->assertStatus(404)
            ->assertJson(['message' => self::UNKNOWN_TENANT_MESSAGE]);
    }

    /**
     * The production regression: an infrastructure hostname must never be read
     * as a tenant slug, or every request without an X-Tenant header 404s.
     */
    public function test_infrastructure_host_is_not_a_tenant_error(): void
    {
        $this->makeTenant('acme');

        foreach (['api.civicride.com', 'app.civicride.com', 'www.civicride.com', 'staging.civicride.com', 'civicride.com'] as $host) {
            $this->get('http://'.$host.'/api/_tenant-probe')
                ->assertOk()
                ->assertJson(['tenant' => null]);
        }
    }

    public function test_ip_literal_host_is_not_a_tenant_error(): void
    {
        $this->makeTenant('acme');

        foreach (['127.0.0.1', '10.0.0.5', '[::1]'] as $host) {
            $this->get('http://'.$host.'/api/_tenant-probe')
                ->assertOk()
                ->assertJson(['tenant' => null]);
        }
    }

    public function test_token_tenant_is_used_when_the_request_has_no_tenant_context(): void
    {
        $tenant = $this->makeTenant('acme');
        $driver = $this->makeDriver($tenant);

        Sanctum::actingAs($driver);

        $this->get('http://api.civicride.com/api/_tenant-probe-auth')
            ->assertOk()
            ->assertJson(['tenant' => 'acme', 'user' => $driver->email]);
    }

    public function test_token_tenant_is_used_when_the_tenant_header_is_empty(): void
    {
        $tenant = $this->makeTenant('acme');
        $driver = $this->makeDriver($tenant);

        Sanctum::actingAs($driver);

        $this->get('/api/_tenant-probe-auth', ['X-Tenant' => ''])
            ->assertOk()
            ->assertJson(['tenant' => 'acme', 'user' => $driver->email]);
    }

    public function test_token_from_another_tenant_is_rejected(): void
    {
        $acme = $this->makeTenant('acme');
        $this->makeTenant('other');
        $driver = $this->makeDriver($acme);

        Sanctum::actingAs($driver);

        $this->get('/api/_tenant-probe-auth', ['X-Tenant' => 'other'])
            ->assertStatus(403)
            ->assertJson(['message' => 'Token does not belong to this tenant.']);
    }

    private function makeTenant(string $slug, bool $active = true): Tenant
    {
        return Tenant::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'contact_email' => $slug.'@example.test',
            'is_active' => $active,
        ]);
    }

    private function makeDriver(Tenant $tenant): User
    {
        $role = Role::create([
            'tenant_id' => $tenant->id,
            'name' => 'driver',
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'name' => 'Dana Driver',
            'email' => 'dana@'.$tenant->slug.'.test',
            'password' => 'password123',
        ]);
    }
}
