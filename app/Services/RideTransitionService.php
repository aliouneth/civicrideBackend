<?php

namespace App\Services;

use App\Events\RideStatusUpdated;
use App\Models\Ride;
use App\Models\RideStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Single place where ride status may change. Guards the lifecycle graph,
 * stamps phase timestamps, and appends an immutable audit row per transition.
 */
class RideTransitionService
{
    /** Allowed transitions by source status code. Terminal states map to []. */
    private const ALLOWED = [
        'pending' => ['assigned', 'cancelled'],
        'assigned' => ['en_route', 'pending', 'cancelled'],
        'en_route' => ['picked_up', 'cancelled'],
        'picked_up' => ['completed'],
        'completed' => [],
        'cancelled' => [],
    ];

    public function transition(Ride $ride, string $toCode, User $actor, array $metadata = []): Ride
    {
        return DB::transaction(function () use ($ride, $toCode, $actor, $metadata) {
            // Row lock: two drivers completing/cancelling the same ride race here.
            $locked = Ride::whereKey($ride->id)->lockForUpdate()->firstOrFail();
            $fromCode = $locked->status->code;

            $this->assertTransitionAllowed($fromCode, $toCode);
            $this->assertCanStart($locked, $toCode, $actor);

            $toStatus = RideStatus::where('code', $toCode)->firstOrFail();
            $fromStatusId = $locked->status_id;

            $locked->status_id = $toStatus->id;
            $this->stampPhaseTimestamps($locked, $toCode);
            if ($toCode === 'cancelled') {
                $locked->cancelled_reason = $metadata['reason'] ?? $locked->cancelled_reason;
            }
            $locked->save();

            $locked->events()->create([
                'from_status_id' => $fromStatusId,
                'to_status_id' => $toStatus->id,
                'actor_id' => $actor->id,
                'metadata' => $metadata ?: null,
            ]);

            broadcast(new RideStatusUpdated($locked->fresh()))->toOthers();

            return $locked->fresh(['status', 'activeAssignment']);
        });
    }

    private function assertTransitionAllowed(string $from, string $to): void
    {
        if (! in_array($to, self::ALLOWED[$from] ?? [], true)) {
            throw ValidationException::withMessages([
                'to_code' => ["Transition {$from} → {$to} is not allowed."],
            ]);
        }
    }

    /** Drivers may only start rides that are due today; schedulers are unconstrained. */
    private function assertCanStart(Ride $ride, string $toCode, User $actor): void
    {
        if ($toCode !== 'en_route' || $actor->driverProfile === null) {
            return;
        }

        if (! $ride->scheduled_pickup_at->isToday()) {
            throw ValidationException::withMessages([
                'to_code' => ['This ride is scheduled for another day and cannot be started today.'],
            ]);
        }
    }

    private function stampPhaseTimestamps(Ride $ride, string $toCode): void
    {
        if ($toCode === 'picked_up' && $ride->actual_pickup_at === null) {
            $ride->actual_pickup_at = now();
        }
        if ($toCode === 'completed' && $ride->actual_dropoff_at === null) {
            $ride->actual_dropoff_at = now();
        }
    }
}
