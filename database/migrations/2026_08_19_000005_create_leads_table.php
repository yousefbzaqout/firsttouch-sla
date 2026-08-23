<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source');
            $table->string('external_lead_id')->index();
            $table->string('name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('status')->default('new');
            $table->string('sla_status')->default('pending');
            $table->timestamp('sla_deadline')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('first_action_at')->nullable();
            $table->jsonb('meta_data')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'sla_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
