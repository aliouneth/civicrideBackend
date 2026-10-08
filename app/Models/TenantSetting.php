<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TenantSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'timezone', 'currency', 'allow_driver_self_assignment',
        'auto_suggest_nearest_driver', 'max_rides_per_driver_per_day',
        'service_area_geojson',
    ];

    protected function casts(): array
    {
        return [
            'allow_driver_self_assignment' => 'boolean',
            'auto_suggest_nearest_driver' => 'boolean',
            'service_area_geojson' => 'array',
        ];
    }
}
