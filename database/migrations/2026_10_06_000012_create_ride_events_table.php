<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Append-only lifecycle audit: every status transition writes one row.
// Current state lives on rides.status_id (fast indexed reads); full history
// rebuilds from here. No updated_at — rows are immutable.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_events', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('ride_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('from_status_id')->nullable();
            $table->foreign('from_status_id')->references('id')->on('ride_statuses')->restrictOnDelete();
            $table->unsignedTinyInteger('to_status_id');
            $table->foreign('to_status_id')->references('id')->on('ride_statuses')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable(); // {note, incident, lat, lng, odometer_km}
            $table->dateTime('created_at')->useCurrent();

            $table->index(['tenant_id', 'ride_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_events');
    }
};
