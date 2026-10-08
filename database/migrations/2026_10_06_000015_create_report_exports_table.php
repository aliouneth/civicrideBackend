<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Async CSV/JSON exports: request row → ExportReportJob (queue) → S3 file.
// Keeps heavy reporting off the web dynos; status: queued|processing|ready|failed.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('type', 64); // rides.daily | rides.range | driver.performance | tenant.metrics
            $table->json('filters');    // {from, to, driver_id, format: csv|json}
            $table->string('status', 32)->default('queued');
            $table->string('file_path')->nullable(); // S3 key when ready
            $table->text('error')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->nullable();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
