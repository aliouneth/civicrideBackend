<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Onboarding is progressive: an invited driver exists before their licence and
// vehicle details are known, so those columns stay null until the driver fills
// them in (DriverController::updateApplication / update).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->date('license_expiry')->nullable()->change();
            $table->string('vehicle_make', 64)->nullable()->change();
            $table->string('vehicle_model', 64)->nullable()->change();
            $table->unsignedSmallInteger('vehicle_year')->nullable()->change();
            $table->string('vehicle_color', 32)->nullable()->change();
            $table->string('vehicle_plate', 16)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->date('license_expiry')->nullable(false)->change();
            $table->string('vehicle_make', 64)->nullable(false)->change();
            $table->string('vehicle_model', 64)->nullable(false)->change();
            $table->unsignedSmallInteger('vehicle_year')->nullable(false)->change();
            $table->string('vehicle_color', 32)->nullable(false)->change();
            $table->string('vehicle_plate', 16)->nullable(false)->change();
        });
    }
};
