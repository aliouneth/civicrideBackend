<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class DriverDocument extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'driver_profile_id', 'type', 'file_path',
        'mime_type', 'verified_at', 'verified_by',
    ];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    public function profile()
    {
        return $this->belongsTo(DriverProfile::class, 'driver_profile_id');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
