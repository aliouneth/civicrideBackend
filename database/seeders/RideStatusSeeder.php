<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// Canonical lifecycle: pending → assigned → en_route → at_pickup → transporting → completed,
// with cancelled as terminal from any non-completed state.
class RideStatusSeeder extends Seeder
{
    public function run(): void
    {
        // Upsert by code, not id: databases seeded with the old lifecycle have
        // ids that rides.status_id / ride_events already reference.
        DB::table('ride_statuses')->upsert([
            ['code' => 'pending',    'label' => 'Pending',    'sort_order' => 10, 'is_terminal' => false, 'allows_assignment' => true],
            ['code' => 'assigned',   'label' => 'Assigned',   'sort_order' => 20, 'is_terminal' => false, 'allows_assignment' => true],
            ['code' => 'en_route',   'label' => 'En route',   'sort_order' => 30, 'is_terminal' => false, 'allows_assignment' => false],
            ['code' => 'at_pickup',  'label' => 'At pickup',  'sort_order' => 40, 'is_terminal' => false, 'allows_assignment' => false],
            ['code' => 'transporting', 'label' => 'Transporting', 'sort_order' => 50, 'is_terminal' => false, 'allows_assignment' => false],
            ['code' => 'completed',  'label' => 'Completed',  'sort_order' => 60, 'is_terminal' => true,  'allows_assignment' => false],
            ['code' => 'cancelled',  'label' => 'Cancelled',  'sort_order' => 70, 'is_terminal' => true,  'allows_assignment' => false],
        ], ['code'], ['label', 'sort_order', 'is_terminal', 'allows_assignment']);
    }
}
