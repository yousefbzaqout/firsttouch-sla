<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->timestamp('sla_started_at')->nullable()->after('sla_deadline');
            $table->timestamp('sla_warning_sent_at')->nullable()->after('sla_started_at');
            $table->timestamp('sla_escalated_at')->nullable()->after('sla_warning_sent_at');
            $table->foreignId('previous_assigned_user_id')
                ->nullable()
                ->after('assigned_user_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['sla_status', 'sla_started_at', 'sla_deadline'], 'leads_sla_buffer_lookup_index');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_online')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex('leads_sla_buffer_lookup_index');
            $table->dropConstrainedForeignId('previous_assigned_user_id');
            $table->dropColumn([
                'sla_started_at',
                'sla_warning_sent_at',
                'sla_escalated_at',
            ]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_online');
        });
    }
};
