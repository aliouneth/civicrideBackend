<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lookup table instead of ENUM (3NF): labels are localizable, terminal flags
// drive UI guards, new states need no schema change. Seeded — never edited at runtime.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_statuses', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            // Tiny lookup: smallest monotonic PK is correct here (not write-heavy).
            $table->tinyIncrements('id');
            $table->string('code', 32)->unique(); // pending|assigned|en_route|picked_up|completed|cancelled
            $table->string('label', 64);
            $table->unsignedTinyInteger('sort_order');
            $table->boolean('is_terminal')->default(false);
            $table->boolean('allows_assignment')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_statuses');
    }
};
