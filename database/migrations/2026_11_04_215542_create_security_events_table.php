<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only security audit trail (HIPAA §164.312(b) audit controls,
 * SOC 2 CC7.2). Rows are written only through SecurityAuditLogger and are
 * never updated or deleted by the application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            // Null for platform-mode events with no agency context.
            $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
            // Keep the event if the actor's account is later deleted.
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 64);
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->json('context')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
