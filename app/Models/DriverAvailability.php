<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class DriverAvailability extends Model
{
    use BelongsToTenant;

    public $timestamps = false;
    protected $table = 'driver_availability';

    protected $fillable = ['tenant_id', 'driver_profile_id', 'weekday', 'start_time', 'end_time'];

    public function profile()
    {
        return $this->belongsTo(DriverProfile::class, 'driver_profile_id');
    }
}
