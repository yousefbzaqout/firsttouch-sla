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
            "UPDATE tenant_settings SET ai_selected_model = 'openrouter/free' WHERE ai_selected_model IN ('meta-llama/llama-3.3-70b-instruct', 'meta-llama/llama-3.1-8b-instruct:free')",
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE tenant_settings ALTER COLUMN ai_selected_model SET DEFAULT 'meta-llama/llama-3.3-70b-instruct'",
        );

        DB::statement(
            "UPDATE tenant_settings SET ai_selected_model = 'meta-llama/llama-3.3-70b-instruct' WHERE ai_selected_model = 'openrouter/free'",
        );
    }
};
