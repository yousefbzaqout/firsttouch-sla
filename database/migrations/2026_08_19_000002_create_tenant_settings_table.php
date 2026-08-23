<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->integer('sla_timeout_minutes')->default(5);
            $table->string('ai_routing_mode')->default('human_only');
            $table->decimal('ai_confidence_threshold', 5, 2)->default(90.00);
            $table->string('ai_selected_model')->default('openrouter/free');
            $table->integer('credits_balance')->default(10);
            $table->string('notification_driver')->default('telegram');
            $table->string('timezone')->default('Asia/Riyadh');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};
