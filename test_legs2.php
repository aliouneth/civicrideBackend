<?php
function haversine($lat1, $lng1, $lat2, $lng2) {
    $earthRadius = 3958.8;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

// Coordinates
$home_lat = 44.2129; $home_lng = -88.4270;  // Menasha, WI (driver home)
$pickup_lat = 42.52; $pickup_lng = -90.6405; // Ogdensburg, WI (pickup)
$dropoff_lat = 42.5; $dropoff_lng = -90.7278; // Freedom, WI (dropoff)

echo "Coordinates:" . PHP_EOL;
echo "  Home (Menasha):       $home_lat, $home_lng" . PHP_EOL;
echo "  Pickup (Ogdensburg):  $pickup_lat, $pickup_lng" . PHP_EOL;
echo "  Dropoff (Freedom):     $dropoff_lat, $dropoff_lng" . PHP_EOL;
PHP_EOL;

// Calculate legs
$leg1 = haversine($home_lat, $home_lng, $pickup_lat, $pickup_lng); // Home → Pickup
$leg2 = haversine($pickup_lat, $pickup_lng, $dropoff_lat, $dropoff_lng); // Pickup → Dropoff
$leg3 = haversine($dropoff_lat, $dropoff_lng, $home_lat, $home_lng); // Dropoff → Home

echo "Leg 1: Home → Pickup = $leg1 miles" . PHP_EOL;
echo "Leg 2: Pickup → Dropoff = $leg2 miles" . PHP_EOL;
echo "Leg 3: Dropoff → Home = $leg3 miles" . PHP_EOL;
PHP_EOL;

$total = $leg1 + $leg2 + $leg3;
echo "Total circular: $total miles" . PHP_EOL;
PHP_EOL;

// Google Maps 103 miles - what does this likely represent?
// Either: (a) Pickup→Dropoff via roads, or (b) Home→Pickup→Dropoff (half the route)
$leg1_2 = $leg1 + $leg2; // Home→Pickup→Dropoff
echo "Home→Pickup→Dropoff: $leg1_2 miles" . PHP_EOL;
echo "Pickup→Dropoff only: $leg2 miles" . PHP_EOL;
echo PHP_EOL;
echo "If Google Maps shows 103 miles, it could be:" . PHP_EOL;
echo "  - Pickup→Dropoff road route (possibly via highways)" . PHP_EOL;
echo "  - Home→Pickup→Dropoff via roads" . PHP_EOL;