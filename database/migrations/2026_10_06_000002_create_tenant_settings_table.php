<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per tenant. Service area stored as GeoJSON polygon (JSON column);
// dispatch rules are explicit typed columns so they stay queryable.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->restrictOnDelete();
            $table->string('timezone', 64)->default('UTC'); // IANA name, e.g. America/New_York
            $table->char('currency', 3)->default('USD');
            $table->boolean('allow_driver_self_assignment')->default(false);
            $table->boolean('auto_suggest_nearest_driver')->default(true);
            $table->unsignedTinyInteger('max_rides_per_driver_per_day')->default(20);
            $table->json('service_area_geojson')->nullable(); // GeoJSON Polygon/MultiPolygon
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};
