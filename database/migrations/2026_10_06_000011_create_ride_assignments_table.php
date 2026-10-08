<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Append-only assignment history: re-assignment closes the old row (unassigned_at)
// and inserts a new one. Uniqueness of "one active assignment per ride/driver" is
// enforced in RideAssignmentService (transaction + row locks) — MySQL has no
// exclusion constraints for time ranges.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_assignments', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('ride_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('assigned_at')->useCurrent();
            $table->dateTime('unassigned_at')->nullable();
            $table->string('unassign_reason')->nullable();
            $table->boolean('self_assigned')->default(false);
            $table->dateTime('created_at')->useCurrent();

            $table->index(['tenant_id', 'driver_profile_id', 'assigned_at']);
            $table->index(['ride_id', 'unassigned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_assignments');
    }
};
