<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_working_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('off_hours_action')->default('freeze_sla');
            $table->timestamps();

            $table->index(['tenant_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_working_hours');
    }
};
