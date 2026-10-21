<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancellation_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('label', 128);
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        // Seed default reasons
        DB::table('cancellation_reasons')->insert([
            ['code' => 'no_show', 'label' => 'Passenger no-show', 'description' => 'Passenger did not appear for the scheduled pickup', 'sort_order' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'passenger_cancelled', 'label' => 'Passenger cancelled', 'description' => 'Passenger called to cancel the ride', 'sort_order' => 2, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'vehicle_issue', 'label' => 'Vehicle breakdown', 'description' => 'Mechanical issue prevented the ride', 'sort_order' => 3, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'driver_emergency', 'label' => 'Driver emergency', 'description' => 'Driver had a personal emergency', 'sort_order' => 4, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'weather', 'label' => 'Severe weather', 'description' => 'Unsafe conditions due to weather', 'sort_order' => 5, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'scheduling_error', 'label' => 'Scheduling error', 'description' => 'Ride was created in error or duplicated', 'sort_order' => 6, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'other', 'label' => 'Other', 'description' => 'Other reason not listed above', 'sort_order' => 99, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('cancellation_reasons');
    }
};