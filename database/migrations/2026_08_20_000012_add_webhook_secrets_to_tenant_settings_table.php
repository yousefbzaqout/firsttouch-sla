<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table): void {
            $table->text('meta_webhook_secret')->nullable()->after('timezone');
            $table->text('tiktok_webhook_secret')->nullable()->after('meta_webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table): void {
            $table->dropColumn(['meta_webhook_secret', 'tiktok_webhook_secret']);
        });
    }
};
