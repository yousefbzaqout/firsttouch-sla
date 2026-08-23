<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Allow NULL first, then normalize blanks. PostgreSQL UNIQUE treats NULLs as
        // distinct, so multiple leads without an external id remain valid.
        DB::statement('ALTER TABLE leads ALTER COLUMN external_lead_id DROP NOT NULL');

        DB::table('leads')
            ->where('external_lead_id', '')
            ->update(['external_lead_id' => null]);

        Schema::table('leads', function (Blueprint $table) {
            $table->index(['assigned_user_id', 'tenant_id'], 'leads_assigned_user_id_tenant_id_index');
            $table->index(['tenant_id', 'created_at'], 'leads_tenant_id_created_at_index');
            $table->unique(['tenant_id', 'external_lead_id'], 'leads_tenant_id_external_lead_id_unique');
        });

        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->unique('tenant_id', 'tenant_settings_tenant_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropUnique('leads_tenant_id_external_lead_id_unique');
            $table->dropIndex('leads_tenant_id_created_at_index');
            $table->dropIndex('leads_assigned_user_id_tenant_id_index');
        });

        DB::table('leads')
            ->whereNull('external_lead_id')
            ->update(['external_lead_id' => '']);

        DB::statement('ALTER TABLE leads ALTER COLUMN external_lead_id SET NOT NULL');

        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropUnique('tenant_settings_tenant_id_unique');
        });
    }
};
