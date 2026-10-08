<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->string('home_address', 255)->nullable()->after('vehicle_plate');
            $table->decimal('home_lat', 10, 7)->nullable()->after('home_address');
            $table->decimal('home_lng', 10, 7)->nullable()->after('home_lat');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn(['home_address', 'home_lat', 'home_lng']);
        });
    }
};
