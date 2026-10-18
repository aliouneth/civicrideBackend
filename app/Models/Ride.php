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
        'trip_type', 'return_pickup_at', 'return_ride_id',
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
            'return_pickup_at' => 'datetime',
        ];
    }

    /** Appended so every ride JSON carries it without controller changes. */
    protected $appends = ['total_distance_miles', 'price_cents'];

    /**
     * Skips the geocode-backed distance computation when false: the board can
     * request rides without it (serve stored price_cents untouched instead).
     * Defaults to true so every other caller keeps computing distance.
     */
    public bool $computeDistance = true;

    private ?float $totalDistanceMilesMemo = null;

    private bool $totalDistanceMilesMemoized = false;

    public function getTotalDistanceMilesAttribute(): ?float
    {
        if (! $this->computeDistance) {
            return null;
        }

        // price_cents reads total_distance_miles; memoize so serialization
        // computes the (expensive, geocode-backed) distance only once per ride.
        if (! $this->totalDistanceMilesMemoized) {
            $this->totalDistanceMilesMemo = app(\App\Services\RideDistanceService::class)->forRide($this);
            $this->totalDistanceMilesMemoized = true;
        }

        return $this->totalDistanceMilesMemo;
    }

    public function getPriceCentsAttribute(): ?int
    {
        if ($this->computeDistance) {
            $distance = $this->total_distance_miles;
            $rate = $this->tenant?->mileage_rate_cents;

            if ($distance !== null && $rate !== null) {
                return (int) round($distance * $rate);
            }
        }

        $stored = $this->attributes['price_cents'] ?? null;

        return $stored !== null ? (int) $stored : null;
    }

    protected static function booted(): void
    {
        static::creating(function (Ride $ride): void {
            if ($ride->price_cents === null && $ride->total_distance_miles !== null && $ride->tenant?->mileage_rate_cents !== null) {
                $ride->price_cents = (int) round($ride->total_distance_miles * $ride->tenant->mileage_rate_cents);
            }
        });
    }

    /** True when this ride belongs to a recurring series (occurrence 0 is the first). */
    public function isRecurring(): bool
    {
        return $this->series_key !== null;
    }

    /** A roundtrip kept as one ride (no split return leg) runs both legs itself. */
    public function isSingleRoundtrip(): bool
    {
        return $this->trip_type === 'roundtrip' && $this->return_ride_id === null;
    }

    /** The split return leg of this outbound ride, if it was split. */
    public function returnRide()
    {
        return $this->belongsTo(Ride::class, 'return_ride_id');
    }

    /** When this ride is itself a return leg, the outbound ride that owns it. */
    public function outboundRide()
    {
        return $this->hasOne(Ride::class, 'return_ride_id');
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
