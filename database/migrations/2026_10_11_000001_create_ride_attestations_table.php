<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_attestations', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('ride_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_profile_id')->constrained()->restrictOnDelete();
            $table->dateTime('attested_at')->useCurrent();

            $table->unique(['ride_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_attestations');
    }
};
