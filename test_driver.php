<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$ride = App\Models\Ride::find(92);

// Get the active assignment and driver
$assignment = $ride->activeAssignment;
echo "Active Assignment ID: " . ($assignment?->id ?? 'null') . PHP_EOL;

if ($assignment) {
    $driver = $assignment->driver;
    echo "Driver ID: " . ($driver?->id ?? 'null') . PHP_EOL;
    if ($driver) {
        echo "Driver home_address: " . ($driver->home_address ?? 'null') . PHP_EOL;
        echo "Driver home_lat: " . ($driver->home_lat ?? 'null') . PHP_EOL;
        echo "Driver home_lng: " . ($driver->home_lng ?? 'null') . PHP_EOL;
        echo "Driver profile city: " . ($driver->city ?? 'null') . PHP_EOL;
        echo "Driver profile state: " . ($driver->state ?? 'null') . PHP_EOL;
    }
}

// Also check tenant
$tenant = $ride->tenant;
echo PHP_EOL . "Tenant ID: " . $tenant->id . PHP_EOL;
echo "Tenant city: " . $tenant->city ?? 'null' . PHP_EOL;
echo "Tenant state: " . $tenant->state ?? 'null' . PHP_EOL;
echo "Tenant mileage_rate_cents: " . $tenant->mileage_rate_cents . PHP_EOL;