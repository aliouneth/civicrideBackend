<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$ride = App\Models\Ride::find(92);
echo "price_cents (stored): " . ($ride->price_cents ?? 'null') . PHP_EOL;
echo "total_distance_miles: " . ($ride->total_distance_miles ?? 'null') . PHP_EOL;

// Now test the geocoding service
$geo = new App\Services\GeocodingService();
echo PHP_EOL . "Geocoding pickup address..." . PHP_EOL;
$pickup = $geo->geocode("E4104 N. Rail Rd. Ogdensburg, WI");
print_r($pickup);

echo PHP_EOL . "Geocoding dropoff address..." . PHP_EOL;
$dropoff = $geo->geocode("W1919 Vandenberg Street, Freedom, WI");
print_r($dropoff);