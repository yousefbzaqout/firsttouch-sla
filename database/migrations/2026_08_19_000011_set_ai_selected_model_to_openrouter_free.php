<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE tenant_settings ALTER COLUMN ai_selected_model SET DEFAULT 'openrouter/free'",
        );

        DB::statement(
            "UPDATE tenant_settings
             SET ai_selected_model = 'openrouter/free'
             WHERE ai_selected_model <> 'openrouter/free'",
        );
    }

    public function down(): void
    {
        // Revert to a safe free fallback; tenants can always override later.
        DB::statement(
            "ALTER TABLE tenant_settings ALTER COLUMN ai_selected_model SET DEFAULT 'openrouter/free'",
        );
    }
};
