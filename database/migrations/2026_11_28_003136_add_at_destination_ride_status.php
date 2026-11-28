<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Deployed databases already have lifecycle rows referenced by rides and
// ride_events, so add the new step by code instead of rewriting old migrations
// or changing existing ids.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ride_statuses')->upsert([
            ['code' => 'at_destination', 'label' => 'At destination', 'sort_order' => 60, 'is_terminal' => false, 'allows_assignment' => false],
            ['code' => 'completed',     'label' => 'Completed',     'sort_order' => 70, 'is_terminal' => true,  'allows_assignment' => false],
            ['code' => 'cancelled',     'label' => 'Cancelled',     'sort_order' => 80, 'is_terminal' => true,  'allows_assignment' => false],
        ], ['code'], ['label', 'sort_order', 'is_terminal', 'allows_assignment']);
    }

    public function down(): void
    {
        // Lookup rows are canonical reference data and may now be referenced by
        // rides and events; leave them in place on rollback.
    }
};
