<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

// Immutable audit rows (no updated_at). Written only by RideTransitionService.
class RideEvent extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'ride_id', 'from_status_id', 'to_status_id',
        'actor_id', 'metadata', 'created_at',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    public function fromStatus()
    {
        return $this->belongsTo(RideStatus::class, 'from_status_id');
    }

    public function toStatus()
    {
        return $this->belongsTo(RideStatus::class, 'to_status_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
