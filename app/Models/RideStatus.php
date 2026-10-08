<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Global lookup, seeded by RideStatusSeeder. No tenant_id by design:
// the lifecycle is identical for every tenant (labels localizable in UI).
class RideStatus extends Model
{
    public $timestamps = false;
    protected $fillable = ['code', 'label', 'sort_order', 'is_terminal', 'allows_assignment'];

    protected function casts(): array
    {
        return ['is_terminal' => 'boolean', 'allows_assignment' => 'boolean'];
    }
}
