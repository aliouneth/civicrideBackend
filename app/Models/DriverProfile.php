<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class DriverProfile extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'user_id', 'license_number', 'license_expiry',
        'vehicle_make', 'vehicle_model', 'vehicle_year', 'vehicle_color',
        'vehicle_plate', 'seats', 'onboarding_status', 'approved_at', 'approved_by',
        'home_address', 'home_lat', 'home_lng',
    ];

    protected function casts(): array
    {
        return ['license_expiry' => 'date', 'approved_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function documents()
    {
        return $this->hasMany(DriverDocument::class);
    }

    public function availability()
    {
        return $this->hasMany(DriverAvailability::class)->orderBy('weekday')->orderBy('start_time');
    }

    public function assignments()
    {
        return $this->hasMany(RideAssignment::class);
    }

    public function isApproved(): bool
    {
        return $this->onboarding_status === 'approved'
            && $this->user->is_active;
    }

    /** Active (non-terminal) assignments currently held by this driver. */
    public function activeAssignments()
    {
        return $this->assignments()
            ->whereNull('unassigned_at')
            ->whereHas('ride.status', fn ($q) => $q->where('is_terminal', false));
    }
}
