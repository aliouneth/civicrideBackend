<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Recurring rides are expanded on write: occurrence #1..N are ordinary `rides`
// rows sharing a series_key, so the dispatch board, assignment and the driver
// execution flow need no special-casing. `series_key` is a ULID rather than a
// self-referencing FK so a series has no anchor row to protect and no extra
// table to join — grouping is a plain equality lookup.
//
// Automatic future generation is deliberately out of scope; see
// docs/05-scalability-roadmap.md (a scheduled job would roll the window forward).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->char('series_key', 26)->nullable()->after('id');
            $table->unsignedSmallInteger('occurrence_index')->nullable()->after('series_key');
            $table->json('recurrence')->nullable()->after('occurrence_index');

            // "Every occurrence of series X in this tenant, in order."
            $table->index(['tenant_id', 'series_key', 'occurrence_index'], 'rides_tenant_series_index');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropIndex('rides_tenant_series_index');
            $table->dropColumn(['series_key', 'occurrence_index', 'recurrence']);
        });
    }
};