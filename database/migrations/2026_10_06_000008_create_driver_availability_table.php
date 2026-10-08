<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Weekly availability windows set by the driver (weekday 0=Sunday … 6=Saturday).
// CHECK constraints are added via ALTER because Blueprint has no check() helper;
// MySQL 8.0.16+ enforces them.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_availability', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('start_time');
            $table->time('end_time');
            $table->dateTime('created_at')->useCurrent();

            $table->unique(['driver_profile_id', 'weekday', 'start_time']);
        });

        DB::statement('ALTER TABLE driver_availability ADD CONSTRAINT chk_availability_weekday CHECK (weekday BETWEEN 0 AND 6)');
        DB::statement('ALTER TABLE driver_availability ADD CONSTRAINT chk_availability_window CHECK (end_time > start_time)');
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_availability');
    }
};