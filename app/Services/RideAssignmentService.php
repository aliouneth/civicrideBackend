<?php

namespace App\Services;

use App\Models\DriverProfile;
use App\Models\Notification;
use App\Models\Ride;
use App\Models\RideAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Double-booking-safe assignment. Fixed lock order (driver row → ride row)
 * prevents deadlocks when two schedulers assign concurrently. Re-assignment
 * closes the previous open row instead of deleting it (history preserved).
 */
class RideAssignmentService
{
    // Two scheduled pickups closer than this are treated as overlapping work.
    private const OVERLAP_BUFFER_MINUTES = 90;

    // An in-progress ride older than this is stale data, not a trip under way.
    private const MAX_ACTIVE_RIDE_HOURS = 24;

    public function __construct(private RideTransitionService $transitions) {}

    public function assign(Ride $ride, DriverProfile $driver, User $actor, bool $selfAssigned = false): RideAssignment
    {
        return DB::transaction(function () use ($ride, $driver, $actor, $selfAssigned) {
            // 1. Lock driver first (fixed global order), then outbound, then
            //    return leg. Never reversed, so concurrent assignments cannot
            //    deadlock on a roundtrip pair.
            $lockedDriver = DriverProfile::whereKey($driver->id)->lockForUpdate()->firstOrFail();
            $lockedRide = Ride::whereKey($ride->id)->lockForUpdate()->firstOrFail();
            $returnRide = $lockedRide->return_ride_id !== null
                ? Ride::whereKey($lockedRide->return_ride_id)->lockForUpdate()->first()
                : null;

            $assignment = $this->assignOne($lockedRide, $lockedDriver, $actor, $selfAssigned);

            // 2. A split roundtrip is one booking: the driver who takes the
            //    outbound is booked for the return leg too, so the pair never
            //    sits on the board (and in the driver's list) half-assigned.
            if ($returnRide !== null
                && $returnRide->status->allows_assignment
                && ! $this->hasActiveAssignmentTo($returnRide, $lockedDriver)) {
                $this->assignOne($returnRide, $lockedDriver, $actor, $selfAssigned, [$lockedRide->id]);
            }

            return $assignment;
        });
    }

    private function assignOne(
        Ride $ride,
        DriverProfile $driver,
        User $actor,
        bool $selfAssigned,
        array $overlapExclusions = [],
    ): RideAssignment {
        $this->assertSameTenant($ride, $driver, $actor);
        $this->assertRideAssignable($ride);
        $this->assertDriverEligible($driver);
        $this->assertNoOverlap($driver, $ride, $overlapExclusions);

        // Close any previous open assignment (re-assignment case).
        $ride->assignments()
            ->whereNull('unassigned_at')
            ->update(['unassigned_at' => now(), 'unassign_reason' => 'reassigned']);

        $assignment = $ride->assignments()->create([
            'driver_profile_id' => $driver->id,
            'assigned_by' => $actor->id,
            'assigned_at' => now(),
            'self_assigned' => $selfAssigned,
        ]);

        // Move pending → assigned through the guarded transition (audited).
        if ($ride->status->code === 'pending') {
            $this->transitions->transition($ride, 'assigned', $actor, [
                'driver_profile_id' => $driver->id,
                'self_assigned' => $selfAssigned,
            ]);
        }

        // Notify the driver (inbox; push/email are phase-2 channels).
        Notification::create([
            'user_id' => $driver->user_id,
            'type' => 'ride.assigned',
            'data' => [
                'ride_id' => $ride->id,
                'pickup_address' => $ride->pickup_address,
                'scheduled_pickup_at' => $ride->scheduled_pickup_at->toIso8601String(),
            ],
        ]);

        return $assignment->fresh(['ride.status', 'driver.user']);
    }

    public function unassign(Ride $ride, User $actor, ?string $reason = null): Ride
    {
        return DB::transaction(function () use ($ride, $actor, $reason) {
            $lockedRide = Ride::whereKey($ride->id)->lockForUpdate()->firstOrFail();
            $returnRide = $lockedRide->return_ride_id !== null
                ? Ride::whereKey($lockedRide->return_ride_id)->lockForUpdate()->first()
                : null;

            $unassigned = $this->unassignOne($lockedRide, $actor, $reason);

            // Freeing the outbound frees its return leg as well: a roundtrip
            // whose halves belong to different drivers is not dispatchable.
            if ($returnRide !== null && in_array($returnRide->status->code, ['pending', 'assigned'], true)) {
                $this->unassignOne($returnRide, $actor, $reason);
            }

            return $unassigned;
        });
    }

    private function unassignOne(Ride $ride, User $actor, ?string $reason): Ride
    {
        $ride->assignments()
            ->whereNull('unassigned_at')
            ->update(['unassigned_at' => now(), 'unassign_reason' => $reason ?? 'unassigned']);

        // assigned → pending frees the ride for the board again.
        if ($ride->status->code === 'assigned') {
            return $this->transitions->transition($ride, 'pending', $actor, ['reason' => $reason]);
        }

        return $ride->fresh();
    }

    private function hasActiveAssignmentTo(Ride $ride, DriverProfile $driver): bool
    {
        return $ride->activeAssignment()->where('driver_profile_id', $driver->id)->exists();
    }

    private function assertSameTenant(Ride $ride, DriverProfile $driver, User $actor): void
    {
        if ($ride->tenant_id !== $driver->tenant_id || $ride->tenant_id !== $actor->tenant_id) {
            throw ValidationException::withMessages(['driver' => ['Driver belongs to a different tenant.']]);
        }
    }

    private function assertRideAssignable(Ride $ride): void
    {
        if (! $ride->status->allows_assignment) {
            throw ValidationException::withMessages([
                'ride' => ["Ride in status {$ride->status->code} cannot be assigned."],
            ]);
        }
    }

    private function assertDriverEligible(DriverProfile $driver): void
    {
        if (! $driver->isApproved()) {
            throw ValidationException::withMessages([
                'driver' => ['Driver is not approved or is deactivated.'],
            ]);
        }
    }

    /**
     * A driver is busy when another pickup sits inside the overlap buffer, or
     * when a trip is still under way and the new pickup starts soon enough that
     * the driver could still be on it. Long-running in-progress data is ignored,
     * and the ride itself never conflicts with a re-assignment to the same driver.
     *
     * Implemented in PHP (not SQL) so the same logic runs on MySQL in production
     * and SQLite in the test suite. Ride ids in $overlapExclusions are skipped:
     * the two legs of one split roundtrip never conflict with each other.
     *
     * @param  list<int>  $overlapExclusions
     */
    private function assertNoOverlap(DriverProfile $driver, Ride $newRide, array $overlapExclusions = []): void
    {
        // Every pickup is stored as a UTC instant, so the clock comparisons are
        // zone-free - the same instant reads correctly in any user's zone.
        $now = Carbon::now();
        $startsSoon = $newRide->scheduled_pickup_at->lessThanOrEqualTo(
            $now->copy()->addMinutes(self::OVERLAP_BUFFER_MINUTES)
        );
        $activeSince = $now->copy()->subHours(self::MAX_ACTIVE_RIDE_HOURS);

        $inProgressStatuses = ['en_route', 'at_pickup', 'transporting'];

        foreach ($driver->activeAssignments()->with('ride.status')->get() as $assignment) {
            if ((int) $assignment->ride_id === (int) $newRide->id) {
                continue;
            }

            if (in_array((int) $assignment->ride_id, $overlapExclusions, true)) {
                continue;
            }

            $ride = $assignment->ride;
            if ($ride === null) {
                continue;
            }

            $status = $ride->status?->code;
            $isInProgress = in_array($status, $inProgressStatuses, true);

            // Any two pickups closer than the buffer overlap.
            if (abs($ride->scheduled_pickup_at->diffInMinutes($newRide->scheduled_pickup_at)) < self::OVERLAP_BUFFER_MINUTES) {
                throw $this->overlapException();
            }

            // A trip still under way blocks a pickup that starts soon, but only
            // when the in-progress ride is recent enough to be genuinely ongoing.
            if ($isInProgress
                && $ride->scheduled_pickup_at->greaterThanOrEqualTo($activeSince)
                && $startsSoon) {
                throw $this->overlapException();
            }
        }
    }

    private function overlapException(): ValidationException
    {
        return ValidationException::withMessages([
            'driver' => ['Driver already has an overlapping ride (double-booking prevented).'],
        ]);
    }
}
