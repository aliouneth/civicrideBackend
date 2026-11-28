<?php

namespace App\Services;

use App\Events\RideStatusUpdated;
use App\Models\Notification;
use App\Models\Ride;
use App\Models\RideStatus;
use App\Models\User;
use App\Support\UserTimezone;
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
        'assigned' => ['en_route', 'pending', 'cancelled', 'unassigned'],
        'en_route' => ['at_pickup', 'assigned', 'cancelled'],
        'at_pickup' => ['transporting', 'en_route', 'cancelled'],
        'transporting' => ['at_destination', 'at_pickup'],
        'at_destination' => ['completed', 'transporting'],
        'completed' => [],
        'cancelled' => [],
    ];

    public function transition(Ride $ride, string $toCode, User $actor, array $metadata = [], ?string $timezone = null): Ride
    {
        return DB::transaction(function () use ($ride, $toCode, $actor, $metadata, $timezone) {
            // Row lock: two drivers completing/cancelling the same ride race here.
            $locked = Ride::whereKey($ride->id)->lockForUpdate()->firstOrFail();
            $fromCode = $locked->status->code;

            $this->assertTransitionAllowed($fromCode, $toCode);
            $this->assertCanStart($locked, $toCode, $actor, $timezone);

            // "unassigned" is a board action, not a lifecycle status: the ride
            // returns to `pending` (awaiting a driver) and the assignment row is
            // closed. Everything else uses its own status code.
            $statusCode = $toCode === 'unassigned' ? 'pending' : $toCode;

            $toStatus = RideStatus::where('code', $statusCode)->firstOrFail();
            $fromStatusId = $locked->status_id;

            $locked->status_id = $toStatus->id;
            if ($toCode === 'completed' && isset($metadata['mileage_claimed'])) {
                $locked->mileage_claimed = $metadata['mileage_claimed'];
            }
            if ($toCode === 'unassigned') {
                // Clear the active assignment: stamp unassigned_at and remove the driver
                $assignment = $locked->activeAssignment;
                if ($assignment) {
                    $assignment->unassigned_at = now();
                    $assignment->unassign_reason = $metadata['reason'] ?? 'Rejected by driver';
                    $assignment->save();
                }
            }
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

            if ($toCode === 'unassigned') {
                $this->notifyAgencyOfRejection($locked, $actor, $metadata);
            }

            return $locked->fresh(['status', 'activeAssignment']);
        });
    }

    /**
     * Put a driver's rejection in every dispatcher's inbox. The ride is already
     * back on the board as pending; this is the active alert that tells agency
     * staff it needs a new driver.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function notifyAgencyOfRejection(Ride $ride, User $actor, array $metadata): void
    {
        $staff = User::where('is_active', true)
            ->whereHas('role', fn ($query) => $query->whereIn('name', ['supervisor', 'admin', 'scheduler']))
            ->get();

        foreach ($staff as $user) {
            Notification::create([
                'user_id' => $user->id,
                'type' => 'ride.unassigned',
                'data' => [
                    'ride_id' => $ride->id,
                    'passenger_name' => data_get($ride->passenger_info, 'name', 'Passenger'),
                    'pickup_address' => $ride->pickup_address,
                    'scheduled_pickup_at' => $ride->scheduled_pickup_at?->toIso8601String(),
                    'rejected_by' => $actor->name,
                    'reason' => $metadata['reason'] ?? 'Rejected by driver',
                ],
                'created_at' => now(),
            ]);
        }
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
    private function assertCanStart(Ride $ride, string $toCode, User $actor, ?string $timezone): void
    {
        if ($toCode !== 'en_route' || $actor->driverProfile === null) {
            return;
        }

        // "Today" follows the requester's zone (the driver's phone), falling
        // back to the agency's. A ride from the previous day may still be
        // started - a driver who missed the window stays on their own ride.
        $zone = UserTimezone::isValid($timezone) ? (string) $timezone : UserTimezone::tenantDefault();
        $pickup = $ride->scheduled_pickup_at->copy()->setTimezone($zone);
        if (! $pickup->isToday() && ! $pickup->isYesterday()) {
            throw ValidationException::withMessages([
                'to_code' => ['This ride is scheduled for another day and cannot be started today.'],
            ]);
        }
    }

    private function stampPhaseTimestamps(Ride $ride, string $toCode): void
    {
        if ($toCode === 'transporting' && $ride->actual_pickup_at === null) {
            $ride->actual_pickup_at = now();
        }
        if ($toCode === 'completed' && $ride->actual_dropoff_at === null) {
            $ride->actual_dropoff_at = now();
        }
    }
}
