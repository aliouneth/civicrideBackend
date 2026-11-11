<?php

namespace Database\Seeders;

use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\RideStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class RideSeeder extends Seeder
{
    private const TENANT_SLUG = 'acme';
    private const DRIVER_EMAIL = 'bmarley@wcw.gov';

    /** Wisconsin fire-number style addresses */
    private $wisconsinPickups = [
        'E4104 N. Rail Rd., Ogdensburg, WI',
        'W1919 Vandenberg Street, Freedom, WI',
        'E1234 S. Park St., Madison, WI',
        'W5678 N. Main St., Green Bay, WI',
        'E9012 W. Roosevelt Ave., Milwaukee, WI',
        'W3456 N. Oak St., Madison, WI',
        'E7890 S. State St., Milwaukee, WI',
        'W1234 E. Washington Ave., Madison, WI',
        'E5678 N. River Rd., De Pere, WI',
        'W9012 S. Court St., Portage, WI',
    ];

    private $wisconsinDropoffs = [
        'E1111 N. Commerce Rd., Madison, WI',
        'W2222 S. Oak St., Green Bay, WI',
        'E3333 N. Broadway, Milwaukee, WI',
        'W4444 S. 5th Ave., Kenosha, WI',
        'E5555 N. Lake Dr., Madison, WI',
        'W6666 S. 2nd St., La Crosse, WI',
        'E7777 N. Wells St., Milwaukee, WI',
        'W8888 S. 6th St., Racine, WI',
        'E9999 N. Sherman Ave., Madison, WI',
        'W1111 S. Packard St., Janesville, WI',
    ];

    public function run(): void
    {
        $tenant = Tenant::where('slug', self::TENANT_SLUG)->firstOrCreate(
            ['name' => 'Acme Transit', 'slug' => self::TENANT_SLUG],
            ['contact_email' => 'ops@acme.test', 'phone' => '+15550100000']
        );

        // Delete existing rides for idempotent re-runs
        Ride::where('tenant_id', $tenant->id)->delete();

        $this->createBmarleyUser($tenant);

        $rides = $this->createRides($tenant);

        $this->assignDrivers($rides, $tenant);

        $this->command->info("Seeded {$rides->count()} rides for tenant '{$tenant->slug}'.");
    }

    private function createBmarleyUser(Tenant $tenant): User
    {
        $role = $tenant->roles()->where('name', 'driver')->firstOrFail();

        $user = User::firstOrCreate(
            ['email' => self::DRIVER_EMAIL],
            [
                'tenant_id' => $tenant->id,
                'role_id' => $role->id,
                'name' => 'B Marley',
                'password' => 'password123',
            ]
        );

        // Ensure driver profile exists
        if (!$user->driverProfile) {
            $user->driverProfile()->create([
                'tenant_id' => $tenant->id,
                'license_number' => 'W2024-99999',
                'license_expiry' => now()->addYears(3)->toDateString(),
                'vehicle_make' => 'Toyota',
                'vehicle_model' => 'Camry',
                'vehicle_year' => 2021,
                'vehicle_color' => 'Blue',
                'vehicle_plate' => 'WCW1234',
                'seats' => 3,
                'onboarding_status' => 'approved',
                'approved_at' => now(),
            ]);
        }

        return $user;
    }

    private function createRides(Tenant $tenant): \Illuminate\Support\Collection
    {
        // Fixed status: "assigned" (code 2) - all seeded rides start as assigned
        $status = RideStatus::where('code', 'assigned')->firstOrFail();

        $rides = collect();

        for ($i = 0; $i < 50; $i++) {

            $pickup = $this->wisconsinPickups[array_rand($this->wisconsinPickups)];
            $dropoff = $this->wisconsinDropoffs[array_rand($this->wisconsinDropoffs)];

            $lat = 42.5 + (random_int(0, 50) / 10000);
            $lng = -88.5 + (random_int(0, 50) / 10000);

            $ride = Ride::create([
                'tenant_id' => $tenant->id,
                'status_id' => $status->id,
                'requested_by' => User::where('email', 'scheduler@acme.test')->value('id') ?? 1,
                'passenger_info' => [
                    'name' => 'Passenger ' . ($i + 1),
                    'phone' => '+155501' . str_pad($i + 1, 4, '0'),
                ],
                'pickup_address' => $pickup,
                'pickup_lat' => $lat,
                'pickup_lng' => $lng,
                'dropoff_address' => $dropoff,
                'dropoff_lat' => $lat + 0.01,
                'dropoff_lng' => $lng - 0.01,
                'scheduled_pickup_at' => now()->addDays(random_int(1, 14))->addHours(random_int(6, 20)),
                'trip_type' => random_int(0, 1) ? 'one_way' : 'roundtrip',
                'priority' => random_int(0, 2),
                'notes' => random_int(0, 4) === 0 ? 'Wheelchair access needed at pickup.' : null,
                'price_cents' => random_int(1500, 5000),
                'currency' => 'USD',
            ]);

            $rides->push($ride);
        }

        return $rides;
    }

    private function assignDrivers(\Illuminate\Support\Collection $rides, Tenant $tenant): void
    {
        $bmarleyUser = User::where('email', self::DRIVER_EMAIL)->first();
        $bmarleyDriver = $bmarleyUser?->driverProfile;

        $allDrivers = DriverProfile::where('tenant_id', $tenant->id)->get();

        $rides->each(function ($ride) use ($bmarleyDriver, $allDrivers, $tenant) {
            $isAssignedToBmarley = random_int(0, 100) < 60; // ~60% assigned to bmarley

            if ($isAssignedToBmarley && $bmarleyDriver) {
                $ride->assignments()->create([
                    'tenant_id' => $tenant->id,
                    'driver_profile_id' => $bmarleyDriver->id,
                    'assigned_by' => User::where('email', 'scheduler@acme.test')->value('id') ?? 1,
                    'assigned_at' => now(),
                ]);
            } elseif ($allDrivers->isNotEmpty() && random_int(0, 100) < 30) {
                $randomDriver = $allDrivers->random();
                $ride->assignments()->create([
                    'tenant_id' => $tenant->id,
                    'driver_profile_id' => $randomDriver->id,
                    'assigned_by' => User::where('email', 'scheduler@acme.test')->value('id') ?? 1,
                    'assigned_at' => now(),
                ]);
            }
        });
    }
}
