<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Roundtrips: a booking is one-way or roundtrip. A roundtrip carries a return
// pickup time and is either kept as one ride (driver runs both legs under one
// lifecycle) or split into an outbound + linked return leg (swapped route,
// return time as its pickup). The link is a plain nullable self-FK on the
// outbound, so a return leg is an ordinary ride everywhere else (board,
// assignment, execution, reports need no special-casing).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->string('trip_type', 16)->default('one_way')->after('currency');
            $table->dateTime('return_pickup_at')->nullable()->after('trip_type');
            $table->foreignId('return_ride_id')->nullable()->after('return_pickup_at')
                ->constrained('rides')->nullOnDelete();
        });

        DB::statement("ALTER TABLE rides ADD CONSTRAINT chk_rides_trip_type CHECK (trip_type IN ('one_way', 'roundtrip'))");
        DB::statement('ALTER TABLE rides ADD CONSTRAINT chk_rides_return_time CHECK (return_pickup_at IS NULL OR return_pickup_at > scheduled_pickup_at)');
    }

    public function down(): void
    {
        // Named CHECKs outlive their columns unless dropped explicitly.
        DB::statement('ALTER TABLE rides DROP CHECK chk_rides_return_time');
        DB::statement('ALTER TABLE rides DROP CHECK chk_rides_trip_type');

        Schema::table('rides', function (Blueprint $table) {
            $table->dropForeign(['return_ride_id']);
            $table->dropColumn(['trip_type', 'return_pickup_at', 'return_ride_id']);
        });
    }
};
