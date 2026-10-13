<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$ride = App\Models\Ride::find(92);
echo "ID: " . $ride->id . PHP_EOL;
echo "Pickup: " . $ride->pickup_address . " (" . $ride->pickup_lat . ", " . $ride->pickup_lng . ")" . PHP_EOL;
echo "Dropoff: " . $ride->dropoff_address . " (" . $ride->dropoff_lat . ", " . $ride->dropoff_lng . ")" . PHP_EOL;
echo "Status: " . $ride->status->name . PHP_EOL;
echo "Tenant ID: " . $ride->tenant_id . PHP_EOL;
echo "Tenant city: " . ($ride->tenant?->city ?? 'null') . PHP_EOL;
echo "Tenant state: " . ($ride->tenant?->state ?? 'null') . PHP_EOL;
echo "Mileage rate: " . ($ride->tenant?->mileage_rate_cents ?? 'null') . " cents/mile" . PHP_EOL;