<?php

namespace Tests\Feature;

use App\Models\DriverProfile;
use App\Models\Permission;
use App\Models\Ride;
use App\Models\RideAttestation;
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
 * Rides are stored as UTC instants while every reader works from their own
 * zone. These tests pin the contract between the two: the X-Timezone header
 * decides which calendar day/month an instant belongs to, and inbound naive
 * datetimes are read as the requester's wall clock.
 */
class TimezoneHandlingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $scheduler;

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
        // The agency is on UTC; the people using it are not.
        $this->tenant->settings()->create(['timezone' => 'UTC', 'currency' => 'USD']);
        RolePermissionSeeder::seedTenantRoles($this->tenant);

        $this->scheduler = User::create([
            'tenant_id' => $this->tenant->id,
            'role_id' => Role::where('tenant_id', $this->tenant->id)->where('name', 'scheduler')->firstOrFail()->id,
            'name' => 'Kim Scheduler',
            'email' => 'kim@acme.test',
            'password' => 'password123',
        ]);

        foreach (['pending', 'assigned', 'en_route', 'at_pickup', 'transporting', 'completed', 'cancelled'] as $index => $code) {
            $this->statuses[$code] = RideStatus::create([
                'code' => $code,
                'label' => ucfirst(str_replace('_', ' ', $code)),
                'sort_order' => $index + 1,
                'is_terminal' => in_array($code, ['completed', 'cancelled'], true),
                'allows_assignment' => true,
            ]);
        }

        Sanctum::actingAs($this->scheduler);
    }

    public function test_the_board_groups_rides_by_the_requesters_calendar_day(): void
    {
        // 03:30 UTC is 22:30 on Nov 9 in New York but 12:30 on Nov 10 in Tokyo.
        $this->travelTo(Carbon::parse('2026-11-10 03:30:00', 'UTC'));
        $ride = $this->makeRide(Carbon::parse('2026-11-10 02:30:00', 'UTC'));

        $newYork = $this->withHeader('X-Timezone', 'America/New_York')
            ->getJson('/api/v1/rides/board?by=day&past_days=1&days=2');
        $newYork->assertOk();
        $this->assertSame('2026-11-09', $this->groupKeyFor($newYork, $ride->id));

        $tokyo = $this->withHeader('X-Timezone', 'Asia/Tokyo')
            ->getJson('/api/v1/rides/board?by=day&past_days=1&days=2');
        $tokyo->assertOk();
        $this->assertSame('2026-11-10', $this->groupKeyFor($tokyo, $ride->id));
    }

    public function test_a_naive_pickup_time_is_read_in_the_requesters_zone(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 12:00:00', 'UTC'));

        $this->withHeader('X-Timezone', 'America/New_York')
            ->postJson('/api/v1/rides', $this->payload('2026-11-10T09:00'))
            ->assertCreated();

        // 09:00 EST is 14:00 UTC, not the 09:00 a UTC reader would assume.
        $this->assertSame(
            '2026-11-10 14:00:00',
            Ride::withoutGlobalScopes()->sole()->scheduled_pickup_at->utc()->toDateTimeString()
        );
    }

    public function test_an_explicit_offset_wins_over_the_header(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 12:00:00', 'UTC'));

        $this->withHeader('X-Timezone', 'America/New_York')
            ->postJson('/api/v1/rides', $this->payload('2026-11-10T09:00:00-08:00'))
            ->assertCreated();

        $this->assertSame(
            '2026-11-10 17:00:00',
            Ride::withoutGlobalScopes()->sole()->scheduled_pickup_at->utc()->toDateTimeString()
        );
    }

    public function test_a_series_end_date_is_compared_on_the_users_calendar(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 12:00:00', 'UTC'));

        // 20:00 New York is 01:00 UTC on the 11th, but the user still calls it
        // the 10th - so an "until" of the 10th is a valid end date.
        $this->withHeader('X-Timezone', 'America/New_York')
            ->postJson('/api/v1/rides', [
                ...$this->payload('2026-11-10T20:00'),
                'recurrence' => ['frequency' => 'daily', 'interval' => 1, 'until' => '2026-11-10'],
            ])
            ->assertCreated();

        // The 9th is before the series starts on the user's clock.
        $this->withHeader('X-Timezone', 'America/New_York')
            ->postJson('/api/v1/rides', [
                ...$this->payload('2026-11-10T20:00'),
                'recurrence' => ['frequency' => 'daily', 'interval' => 1, 'until' => '2026-11-09'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recurrence.until');
    }

    public function test_a_weekly_series_keeps_its_local_time_across_dst(): void
    {
        // DST starts in New York on 2026-03-08: a 09:00 series must stay at
        // 09:00 local, i.e. move from 14:00 UTC to 13:00 UTC, not drift to 08:00.
        $this->travelTo(Carbon::parse('2026-03-01 00:00:00', 'UTC'));

        $this->withHeader('X-Timezone', 'America/New_York')
            ->postJson('/api/v1/rides', [
                ...$this->payload('2026-03-01T09:00'),
                'recurrence' => ['frequency' => 'weekly', 'interval' => 1, 'count' => 2],
            ])
            ->assertCreated();

        $pickups = Ride::withoutGlobalScopes()
            ->orderBy('scheduled_pickup_at')
            ->get()
            ->map(fn (Ride $ride) => $ride->scheduled_pickup_at->utc()->toDateTimeString())
            ->all();

        $this->assertSame(['2026-03-01 14:00:00', '2026-03-08 13:00:00'], $pickups);
    }

    public function test_attestations_are_filtered_by_the_requesters_month(): void
    {
        $this->travelTo(Carbon::parse('2026-12-01 12:00:00', 'UTC'));

        $driver = User::create([
            'tenant_id' => $this->tenant->id,
            'role_id' => Role::where('tenant_id', $this->tenant->id)->where('name', 'driver')->firstOrFail()->id,
            'name' => 'Sam Driver',
            'email' => 'sam@acme.test',
            'password' => 'password123',
        ]);
        $profile = DriverProfile::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $driver->id,
            'license_number' => 'D1234',
            'license_expiry' => '2027-01-01',
            'vehicle_make' => 'Ford',
            'vehicle_model' => 'Transit',
            'vehicle_year' => 2024,
            'vehicle_color' => 'White',
            'vehicle_plate' => 'ABC123',
        ]);

        // 02:00 UTC on Dec 1 is still Nov 30 on the user's New York clock.
        RideAttestation::create([
            'tenant_id' => $this->tenant->id,
            'ride_id' => $this->makeRide(Carbon::parse('2026-11-30 15:00:00', 'UTC'))->id,
            'driver_profile_id' => $profile->id,
            'attested_at' => Carbon::parse('2026-12-01 02:00:00', 'UTC'),
        ]);

        $this->withHeader('X-Timezone', 'America/New_York')
            ->getJson('/api/v1/drivers/attestations?month=2026-11')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withHeader('X-Timezone', 'America/New_York')
            ->getJson('/api/v1/drivers/attestations?month=2026-12')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // A UTC reader still sees it in December.
        $this->withHeader('X-Timezone', 'UTC')
            ->getJson('/api/v1/drivers/attestations?month=2026-12')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    private function makeRide(Carbon $pickupAt): Ride
    {
        return Ride::create([
            'tenant_id' => $this->tenant->id,
            'status_id' => $this->statuses['pending']->id,
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

    /** @return array<string, mixed> */
    private function payload(string $pickupAt): array
    {
        return [
            'passenger_info' => ['name' => 'Pat Rider', 'phone' => '5550100'],
            'pickup_address' => '1 Main St',
            'pickup_lat' => 44.5,
            'pickup_lng' => -88.5,
            'dropoff_address' => '2 Main St',
            'dropoff_lat' => 44.6,
            'dropoff_lng' => -88.6,
            'scheduled_pickup_at' => $pickupAt,
            'price_cents' => 1000,
        ];
    }

    private function groupKeyFor($response, int $rideId): ?string
    {
        foreach ($response->json('groups') as $group) {
            foreach ($group['rides'] as $ride) {
                if ($ride['id'] === $rideId) {
                    return $group['key'];
                }
            }
        }

        return null;
    }
}
