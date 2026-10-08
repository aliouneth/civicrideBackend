<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tenants = agencies/organizations. Resolved per request by ResolveTenant
// middleware (subdomain or X-Tenant header). Never deleted while rows reference it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id(); // BIGINT UNSIGNED AUTO_INCREMENT (narrow monotonic PK)
            $table->string('name');
            $table->string('slug')->unique(); // e.g. "acme" in acme.civicride.com
            $table->string('contact_email');
            $table->string('phone', 32)->nullable();
            $table->boolean('is_active')->default(true);
            // DATETIME, not TIMESTAMP (no 2038 limit, no implicit tz conversion).
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
