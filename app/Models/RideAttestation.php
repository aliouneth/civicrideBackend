<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class RideAttestation extends Model
{
    use BelongsToTenant;

    public $timestamps = false;
    protected $table = 'ride_attestations';

    protected $fillable = ['tenant_id', 'ride_id', 'driver_profile_id', 'attested_at'];

    protected function casts(): array
    {
        return ['attested_at' => 'datetime'];
    }

    public function ride()
    {
        return $this->belongsTo(Ride::class);
    }

    public function driverProfile()
    {
        return $this->belongsTo(DriverProfile::class, 'driver_profile_id');
    }
}
