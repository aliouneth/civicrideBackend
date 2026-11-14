<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The 2026-11-06 lifecycle rename (picked_up → at_pickup/transporting) only
// changed RideStatusSeeder, but deployments run `migrate --force` without
// seeding. Databases seeded with the old lifecycle therefore lacked
// 'at_pickup', so any driver transition to it failed with
// "No query results for model [App\Models\RideStatus]".
//
// Sync by code (never by id): ids on legacy databases are already referenced
// by rides.status_id and ride_events, so inserts take fresh auto-increment ids.
return new class extends Migration
{
    /** @var array<int, array{code: string, label: string, sort_order: int, is_terminal: bool, allows_assignment: bool}> */
    private const STATUSES = [
        ['code' => 'pending', 'label' => 'Pending', 'sort_order' => 10, 'is_terminal' => false, 'allows_assignment' => true],
        ['code' => 'assigned', 'label' => 'Assigned', 'sort_order' => 20, 'is_terminal' => false, 'allows_assignment' => true],
        ['code' => 'en_route', 'label' => 'En route', 'sort_order' => 30, 'is_terminal' => false, 'allows_assignment' => false],
        ['code' => 'at_pickup', 'label' => 'At pickup', 'sort_order' => 40, 'is_terminal' => false, 'allows_assignment' => false],
        ['code' => 'transporting', 'label' => 'Transporting', 'sort_order' => 50, 'is_terminal' => false, 'allows_assignment' => false],
        ['code' => 'completed', 'label' => 'Completed', 'sort_order' => 60, 'is_terminal' => true, 'allows_assignment' => false],
        ['code' => 'cancelled', 'label' => 'Cancelled', 'sort_order' => 70, 'is_terminal' => true, 'allows_assignment' => false],
    ];

    public function up(): void
    {
        foreach (self::STATUSES as $status) {
            $existing = DB::table('ride_statuses')->where('code', $status['code'])->first();

            if ($existing !== null) {
                DB::table('ride_statuses')->where('id', $existing->id)->update([
                    'label' => $status['label'],
                    'sort_order' => $status['sort_order'],
                    'is_terminal' => $status['is_terminal'],
                    'allows_assignment' => $status['allows_assignment'],
                ]);

                continue;
            }

            DB::table('ride_statuses')->insert($status);
        }

        $this->retirePickedUpStatus();
    }

    /**
     * Old 'picked_up' meant the passenger was aboard, which is 'transporting'
     * today. Re-point current states and audit history before dropping it
     * (the FKs are restrictOnDelete, so order matters).
     */
    private function retirePickedUpStatus(): void
    {
        $legacy = DB::table('ride_statuses')->where('code', 'picked_up')->first();
        $transporting = DB::table('ride_statuses')->where('code', 'transporting')->first();

        if ($legacy === null || $transporting === null) {
            return;
        }

        DB::table('rides')->where('status_id', $legacy->id)->update(['status_id' => $transporting->id]);
        DB::table('ride_events')->where('from_status_id', $legacy->id)->update(['from_status_id' => $transporting->id]);
        DB::table('ride_events')->where('to_status_id', $legacy->id)->update(['to_status_id' => $transporting->id]);
        DB::table('ride_statuses')->where('id', $legacy->id)->delete();
    }

    public function down(): void
    {
        // Lookup rows are canonical reference data; rides and events may now
        // reference them, so there is nothing safe to roll back.
    }
};
