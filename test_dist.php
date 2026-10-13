<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$ride = App\Models\Ride::find(92);

// Use the RideDistanceService directly
$service = new App\Services\RideDistanceService(new App\Services\GeocodingService());
$dist = $service->forRide($ride);
echo 'RideDistanceService::forRide() result: ' . $dist . PHP_EOL;

// Also check what coords the service found
// Let's manually geocode
$geo = new App\Services\GeocodingService();
$pickup = $geo->geocode("E4104 N. Rail Rd. Ogdensburg, WI");
$dropoff = $geo->geocode("W1919 Vandenberg Street, Freedom, WI");
echo "Pickup geocoded: " . ($pickup ? "lat={$pickup['lat']}, lng={$pickup['lng']}" : 'null') . PHP_EOL;
echo "Dropoff geocoded: " . ($dropoff ? "lat={$dropoff['lat']}, lng={$dropoff['lng']}" : 'null') . PHP_EOL;