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
            // 1. Lock driver first (fixed global order), then ride. Never reversed.
            $lockedDriver = DriverProfile::whereKey($driver->id)->lockForUpdate()->firstOrFail();
            $lockedRide = Ride::whereKey($ride->id)->lockForUpdate()->firstOrFail();

            $this->assertSameTenant($lockedRide, $lockedDriver, $actor);
            $this->assertRideAssignable($lockedRide);
            $this->assertDriverEligible($lockedDriver);
            $this->assertNoOverlap($lockedDriver, $lockedRide);

            // 2. Close any previous open assignment (re-assignment case).
            $lockedRide->assignments()
                ->whereNull('unassigned_at')
                ->update(['unassigned_at' => now(), 'unassign_reason' => 'reassigned']);

            // 3. Create the new assignment row.
            $assignment = $lockedRide->assignments()->create([
                'driver_profile_id' => $lockedDriver->id,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
                'self_assigned' => $selfAssigned,
            ]);

            // 4. Move pending → assigned through the guarded transition (audited).
            if ($lockedRide->status->code === 'pending') {
                $this->transitions->transition($lockedRide, 'assigned', $actor, [
                    'driver_profile_id' => $lockedDriver->id,
                    'self_assigned' => $selfAssigned,
                ]);
            }

            // 5. Notify the driver (inbox; push/email are phase-2 channels).
            Notification::create([
                'user_id' => $lockedDriver->user_id,
                'type' => 'ride.assigned',
                'data' => [
                    'ride_id' => $lockedRide->id,
                    'pickup_address' => $lockedRide->pickup_address,
                    'scheduled_pickup_at' => $lockedRide->scheduled_pickup_at->toIso8601String(),
                ],
            ]);

            return $assignment->fresh(['ride.status', 'driver.user']);
        });
    }

    public function unassign(Ride $ride, User $actor, ?string $reason = null): Ride
    {
        return DB::transaction(function () use ($ride, $actor, $reason) {
            $lockedRide = Ride::whereKey($ride->id)->lockForUpdate()->firstOrFail();

            $lockedRide->assignments()
                ->whereNull('unassigned_at')
                ->update(['unassigned_at' => now(), 'unassign_reason' => $reason ?? 'unassigned']);

            // assigned → pending frees the ride for the board again.
            if ($lockedRide->status->code === 'assigned') {
                return $this->transitions->transition($lockedRide, 'pending', $actor, ['reason' => $reason]);
            }

            return $lockedRide->fresh();
        });
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
     * and SQLite in the test suite.
     */
    private function assertNoOverlap(DriverProfile $driver, Ride $newRide): void
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
