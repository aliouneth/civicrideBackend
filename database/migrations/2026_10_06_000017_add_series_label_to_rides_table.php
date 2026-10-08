<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A human name for a recurring series ("Mon/Wed school run"). Stored denormalised
// on every occurrence, exactly like `recurrence`, so listing a series never has
// to hop to an anchor row to find its name.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->string('series_label', 120)->nullable()->after('series_key');

            // The groups list sorts by label, then falls back to the next pickup.
            $table->index(['tenant_id', 'series_label'], 'rides_tenant_series_label_index');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropIndex('rides_tenant_series_label_index');
            $table->dropColumn('series_label');
        });
    }
};