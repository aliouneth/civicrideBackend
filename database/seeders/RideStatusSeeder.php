<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// Canonical lifecycle: pending → assigned → en_route → picked_up → completed,
// with cancelled as terminal from any non-completed state.
class RideStatusSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('ride_statuses')->upsert([
            ['id' => 1, 'code' => 'pending',    'label' => 'Pending',    'sort_order' => 10, 'is_terminal' => false, 'allows_assignment' => true],
            ['id' => 2, 'code' => 'assigned',   'label' => 'Assigned',   'sort_order' => 20, 'is_terminal' => false, 'allows_assignment' => true],
            ['id' => 3, 'code' => 'en_route',   'label' => 'En route',   'sort_order' => 30, 'is_terminal' => false, 'allows_assignment' => false],
            ['id' => 4, 'code' => 'picked_up',  'label' => 'Picked up',  'sort_order' => 40, 'is_terminal' => false, 'allows_assignment' => false],
            ['id' => 5, 'code' => 'completed',  'label' => 'Completed',  'sort_order' => 50, 'is_terminal' => true,  'allows_assignment' => false],
            ['id' => 6, 'code' => 'cancelled',  'label' => 'Cancelled',  'sort_order' => 60, 'is_terminal' => true,  'allows_assignment' => false],
        ], ['id']);
    }
}
