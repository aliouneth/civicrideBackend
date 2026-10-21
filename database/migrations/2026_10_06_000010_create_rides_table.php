<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Core entity. Money as integer minor units (price_cents) — never FLOAT.
// Coordinates DECIMAL(10,7) (~1cm precision). Hot-path index puts equality
// columns first (tenant_id, status_id), range column last (scheduled_pickup_at).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rides', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('status_id');
            $table->foreign('status_id')->references('id')->on('ride_statuses')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->json('passenger_info'); // {name, phone, passengers, wheelchair, child_seat}
            $table->string('pickup_address');
            $table->decimal('pickup_lat', 10, 7);
            $table->decimal('pickup_lng', 10, 7);
            $table->string('dropoff_address');
            $table->decimal('dropoff_lat', 10, 7);
            $table->decimal('dropoff_lng', 10, 7);
            $table->dateTime('scheduled_pickup_at');
            $table->dateTime('actual_pickup_at')->nullable();
            $table->dateTime('actual_dropoff_at')->nullable();
            $table->unsignedTinyInteger('priority')->default(0); // 0 normal, higher first
            $table->text('notes')->nullable();
            $table->string('cancelled_reason')->nullable();
            $table->unsignedInteger('price_cents')->nullable();
            $table->char('currency', 3)->default('USD');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->nullable();

            // Dispatch board: "tenant + status, ordered by pickup time".
            $table->index(['tenant_id', 'status_id', 'scheduled_pickup_at']);
            // Driver day view / scheduler timeline range scans.
            $table->index(['tenant_id', 'scheduled_pickup_at']);
            $table->index(['tenant_id', 'requested_by']);
        });

        // MySQL 8.0.16+ enforces named CHECKs; other drivers (sqlite in the
        // test suite) have no ALTER TABLE ... ADD CONSTRAINT syntax.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE rides ADD CONSTRAINT chk_rides_pickup_lat CHECK (pickup_lat BETWEEN -90 AND 90)');
            DB::statement('ALTER TABLE rides ADD CONSTRAINT chk_rides_pickup_lng CHECK (pickup_lng BETWEEN -180 AND 180)');
            DB::statement('ALTER TABLE rides ADD CONSTRAINT chk_rides_dropoff_lat CHECK (dropoff_lat BETWEEN -90 AND 90)');
            DB::statement('ALTER TABLE rides ADD CONSTRAINT chk_rides_dropoff_lng CHECK (dropoff_lng BETWEEN -180 AND 180)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rides');
    }
};