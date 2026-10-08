<?php

namespace App\Services;

use App\Models\DriverProfile;
use App\Models\Notification;
use App\Models\Ride;
use App\Models\RideAssignment;
use App\Models\User;
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

    /** A driver with an in-progress ride, or another pickup inside the buffer, is busy. */
    private function assertNoOverlap(DriverProfile $driver, Ride $newRide): void
    {
        $conflict = $driver->activeAssignments()
            ->where(function ($query) use ($newRide): void {
                $query->whereHas('ride', fn ($q) => $q->whereIn('status_id', function ($sub): void {
                    $sub->select('id')->from('ride_statuses')->whereIn('code', ['en_route', 'picked_up']);
                }))->orWhereHas('ride', function ($q) use ($newRide): void {
                    $q->whereRaw(
                        'ABS(TIMESTAMPDIFF(MINUTE, scheduled_pickup_at, ?)) < ?',
                        [$newRide->scheduled_pickup_at, self::OVERLAP_BUFFER_MINUTES]
                    );
                });
            })
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'driver' => ['Driver already has an overlapping ride (double-booking prevented).'],
            ]);
        }
    }
}
