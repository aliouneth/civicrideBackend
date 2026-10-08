<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class RideAssignment extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'ride_id', 'driver_profile_id', 'assigned_by',
        'assigned_at', 'unassigned_at', 'unassign_reason', 'self_assigned',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'unassigned_at' => 'datetime',
            'self_assigned' => 'boolean',
        ];
    }

    public function ride()
    {
        return $this->belongsTo(Ride::class);
    }

    public function driver()
    {
        return $this->belongsTo(DriverProfile::class, 'driver_profile_id');
    }

    public function assigner()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function isOpen(): bool
    {
        return $this->unassigned_at === null;
    }
}
