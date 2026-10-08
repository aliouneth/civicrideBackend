<?php

namespace Database\Seeders;

use App\Models\DriverProfile;
use App\Models\DriverAvailability;
use App\Models\Ride;
use App\Models\RideStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates one walkthrough tenant with staff, drivers and rides covering every
 * lifecycle state, so the scheduler board and driver app have real data.
 * Idempotent: re-running updates nothing if the slug already exists.
 */
class DemoTenantSeeder extends Seeder
{
    private const TENANT_SLUG = 'acme';

    public function run(): void
    {
        if (Tenant::where('slug', self::TENANT_SLUG)->exists()) {
            $this->command?->warn("Tenant 'acme' already exists — skipping demo data.");

            return;
        }

        $tenant = Tenant::create([
            'name' => 'Acme Transit',
            'slug' => self::TENANT_SLUG,
            'contact_email' => 'ops@acme.test',
            'phone' => '+15550100000',
        ]);

        // Creates tenant_settings, the 4 default roles + their permissions, and the supervisor.
        $supervisor = $tenant->provisionDefaults([
            'name' => 'Alex Supervisor',
            'email' => 'supervisor@acme.test',
            'password' => 'password123',
            'timezone' => 'America/New_York',
            'currency' => 'USD',
        ]);

        $scheduler = $this->createUser($tenant, 'scheduler', 'Sam Scheduler', 'dispatch@acme.test');
        $admin = $this->createUser($tenant, 'admin', 'Avery Admin', 'admin@acme.test');

        $driverA = $this->createDriver($tenant, 'Dana Driver', 'dana@acme.test', 'D2024-88431', 'Toyota', 'Prius', '8CJX214', 4);
        $driverB = $this->createDriver($tenant, 'Rico Reyes', 'rico@acme.test', 'R2019-10922', 'Honda', 'Civic', 'K7PL903', 4);

        $this->createRides($tenant, $scheduler, [$driverA, $driverB]);

        $this->command?->info('Demo tenant "acme" seeded.');
        $this->command?->table(['Role', 'Email', 'Password'], [
            ['supervisor', $supervisor->email, 'password123'],
            ['admin', $admin->email, 'password123'],
            ['scheduler', $scheduler->email, 'password123'],
            ['driver', $driverA->user->email, 'password123'],
            ['driver', $driverB->user->email, 'password123'],
        ]);
    }

    private function createUser(Tenant $tenant, string $roleName, string $name, string $email): User
    {
        return $tenant->users()->create([
            'role_id' => $tenant->roles()->where('name', $roleName)->value('id'),
            'name' => $name,
            'email' => $email,
            'password' => 'password123', // hashed by the User model cast
        ]);
    }

    private function createDriver(
        Tenant $tenant,
        string $name,
        string $email,
        string $license,
        string $make,
        string $model,
        string $plate,
        int $seats,
    ): DriverProfile {
        $user = $this->createUser($tenant, 'driver', $name, $email);

        $profile = $user->driverProfile()->create([
            'tenant_id' => $tenant->id,
            'license_number' => $license,
            'license_expiry' => now()->addYears(3)->toDateString(),
            'vehicle_make' => $make,
            'vehicle_model' => $model,
            'vehicle_year' => 2022,
            'vehicle_color' => 'White',
            'vehicle_plate' => $plate,
            'seats' => $seats,
            'onboarding_status' => 'approved',
            'approved_at' => now(),
        ]);

        // Mon–Fri 06:00–20:00 so the scheduler sees realistic availability.
        foreach (range(1, 5) as $weekday) {
            DriverAvailability::create([
                'tenant_id' => $tenant->id,
                'driver_profile_id' => $profile->id,
                'weekday' => $weekday,
                'start_time' => '06:00:00',
                'end_time' => '20:00:00',
            ]);
        }

        return $profile;
    }

    /** One ride per status so every badge/state is exercised in the UI. */
    private function createRides(Tenant $tenant, User $requester, array $drivers): void
    {
        $rows = [
            ['pending', 'Jordan Ellis', '+15550101111', '200 Park Ave', '89 Lexington Ave', null],
            ['pending', 'Morgan Diaz', '+15550102222', '55 Water St', '1 Brooklyn Bridge', null],
            ['assigned', 'Casey Nguyen', '+15550103333', '12 E 34th St', 'JFK Terminal 4', 0],
            ['en_route', 'Riley Patel', '+15550104444', '7 W 57th St', '350 5th Ave', 0],
            ['picked_up', 'Taylor Kim', '+15550105555', '300 E 42nd St', '475 Park Ave', 1],
            ['completed', 'Avery Stone', '+15550106666', '10 Wall St', 'Pier 11', 2],
            ['cancelled', 'Quinn Baker', '+15550107777', '1 Bryant Park', 'Chelsea Market', null],
        ];

        foreach ($rows as $index => [$code, $passenger, $phone, $pickup, $dropoff, $driverIndex]) {
            $status = RideStatus::where('code', $code)->firstOrFail();

            Ride::create([
                'tenant_id' => $tenant->id,
                'status_id' => $status->id,
                'requested_by' => $requester->id,
                'passenger_info' => ['name' => $passenger, 'phone' => $phone, 'passengers' => 1],
                'pickup_address' => $pickup,
                'pickup_lat' => 40.7484 + ($index * 0.001),
                'pickup_lng' => -73.9857 + ($index * 0.001),
                'dropoff_address' => $dropoff,
                'dropoff_lat' => 40.7527 + ($index * 0.001),
                'dropoff_lng' => -73.9772 + ($index * 0.001),
                'scheduled_pickup_at' => now()->addHours($index + 1),
                'actual_pickup_at' => in_array($code, ['picked_up', 'completed'], true) ? now()->subMinutes(10) : null,
                'actual_dropoff_at' => $code === 'completed' ? now() : null,
                'priority' => $index % 3,
                'notes' => $index === 0 ? 'Wheelchair access needed at pickup.' : null,
                'cancelled_reason' => $code === 'cancelled' ? 'Passenger no-show after 15 min.' : null,
                'price_cents' => 2200 + ($index * 350),
                'currency' => 'USD',
            ]);

            // Link a driver + append-only assignment row for the non-pending rides.
            if ($driverIndex !== null) {
                $ride = Ride::where('tenant_id', $tenant->id)
                    ->where('passenger_info->name', $passenger)
                    ->first();

                $ride->assignments()->create([
                    'tenant_id' => $tenant->id,
                    'driver_profile_id' => $drivers[$driverIndex % count($drivers)]->id,
                    'assigned_by' => $requester->id,
                    'assigned_at' => now()->subMinutes(30),
                ]);
            }
        }
    }
}