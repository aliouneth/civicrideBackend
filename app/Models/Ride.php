<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Ride extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'status_id', 'requested_by', 'passenger_info',
        'pickup_address', 'pickup_lat', 'pickup_lng',
        'dropoff_address', 'dropoff_lat', 'dropoff_lng',
        'scheduled_pickup_at', 'actual_pickup_at', 'actual_dropoff_at',
        'priority', 'notes', 'cancelled_reason', 'price_cents', 'currency',
        'series_key', 'series_label', 'occurrence_index', 'recurrence',
    ];

    protected function casts(): array
    {
        return [
            'passenger_info' => 'array',
            'recurrence' => 'array',
            'occurrence_index' => 'integer',
            'scheduled_pickup_at' => 'datetime',
            'actual_pickup_at' => 'datetime',
            'actual_dropoff_at' => 'datetime',
        ];
    }

    /** Appended so every ride JSON carries it without controller changes. */
    protected $appends = ['total_distance_km'];

    public function getTotalDistanceKmAttribute(): ?float
    {
        return app(\App\Services\RideDistanceService::class)->forRide($this);
    }

    /** True when this ride belongs to a recurring series (occurrence 0 is the first). */
    public function isRecurring(): bool
    {
        return $this->series_key !== null;
    }

    public function scopeInSeries($query, string $seriesKey)
    {
        return $query->where('series_key', $seriesKey);
    }

    public function status()
    {
        return $this->belongsTo(RideStatus::class, 'status_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function assignments()
    {
        return $this->hasMany(RideAssignment::class)->latest('assigned_at');
    }

    public function activeAssignment()
    {
        return $this->hasOne(RideAssignment::class)->whereNull('unassigned_at')->latestOfMany('assigned_at');
    }

    public function events()
    {
        return $this->hasMany(RideEvent::class)->orderBy('id');
    }

    public function messages()
    {
        return $this->hasMany(Message::class)->orderBy('id');
    }

    public function isTerminal(): bool
    {
        return (bool) $this->status->is_terminal;
    }
}
