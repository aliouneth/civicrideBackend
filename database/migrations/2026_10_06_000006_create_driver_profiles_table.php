<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Onboarding workflow states: applied → documents_pending → in_review →
// approved | rejected | suspended. One profile per user.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_profiles', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('license_number', 64);
            $table->date('license_expiry');
            $table->string('vehicle_make', 64);
            $table->string('vehicle_model', 64);
            $table->unsignedSmallInteger('vehicle_year');
            $table->string('vehicle_color', 32);
            $table->string('vehicle_plate', 16);
            $table->unsignedTinyInteger('seats')->default(4);
            $table->string('onboarding_status', 32)->default('applied');
            $table->dateTime('applied_at')->useCurrent();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->nullable();

            $table->index(['tenant_id', 'onboarding_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_profiles');
    }
};
