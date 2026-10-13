<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$ride = App\Models\Ride::find(92);

// Force recalculation by accessing the attribute
$dist = $ride->total_distance_miles;
echo 'After accessing total_distance_miles: ' . $dist . PHP_EOL;

// Now check price_cents after distance was calculated
$price = $ride->price_cents;
echo 'price_cents: ' . $price . PHP_EOL;

// Check tenant rate
$tenant = $ride->tenant;
echo 'mileage_rate_cents: ' . ($tenant?->mileage_rate_cents ?? 'null') . PHP_EOL;

// Recalculate price using the new distance
if ($dist !== null && $tenant?->mileage_rate_cents !== null) {
    $calculated = (int) round($dist * $tenant->mileage_rate_cents / 100);
    echo 'Calculated fare (distance * rate / 100): ' . $calculated . PHP_EOL;
}