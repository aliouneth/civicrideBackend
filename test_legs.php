<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$ride = App\Models\Ride::find(92);

// Use the service
$service = new App\Services\RideDistanceService(new App\Services\GeocodingService());
$total = $service->forRide($ride);
echo "Total circular distance (home→pickup→dropoff→home): " . $total . " miles" . PHP_EOL;

// Let's also check individual distances by manually geocoding
$geo = new App\Services\GeocodingService();

// Driver home (from profile)
$home = $geo->geocode("E4104 N. Rail Rd. Ogdensburg, WI"); // This would be from driver profile
// For now, let's use the ride's pickup/dropoff geocodes

// Pickup
$pickup = $geo->geocode("E4104 N. Rail Rd. Ogdensburg, WI");
// From earlier test: pickup = lat=42.52, lng=-90.64052173913

// Dropoff  
$dropoff = $geo->geocode("W1919 Vandenberg Street, Freedom, WI");
// From earlier test: dropoff = lat=42.5, lng=-90.727811594203

// Estimate home - for ride #92, driver home was also Wisconsin fire number
// Let's assume similar format
$home = $geo->geocode("E4104 N. Rail Rd. Ogdensburg, WI"); // Same as pickup area

// Calculate legs manually
function haversine($lat1, $lng1, $lat2, $lng2) {
    $earthRadius = 3958.8;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

// Leg 1: Home → Pickup (assume home same as pickup area for now)
$leg1 = haversine(42.52, -90.64052173913, 42.52, -90.64052173913); // 0 if same
echo "Leg 1 (Home→Pickup): ~$leg1 miles" . PHP_EOL;

// Leg 2: Pickup → Dropoff
$leg2 = haversine(42.52, -90.64052173913, 42.5, -90.727811594203);
echo "Leg 2 (Pickup→Dropoff): $leg2 miles" . PHP_EOL;

// Leg 3: Dropoff → Home
$leg3 = haversine(42.5, -90.727811594203, 42.52, -90.64052173913);
echo "Leg 3 (Dropoff→Home): $leg3 miles" . PHP_EOL;

$total = $leg1 + $leg2 + $leg3;
echo "Total: $total miles" . PHP_EOL;

// Now compare with Google Maps distance for just Pickup→Dropoff
echo PHP_EOL . "Google Maps likely shows just Pickup→Dropoff: ~103 miles" . PHP_EOL;
echo "My system calculates circular: home→pickup + pickup→dropoff + dropoff→home" . PHP_EOL;