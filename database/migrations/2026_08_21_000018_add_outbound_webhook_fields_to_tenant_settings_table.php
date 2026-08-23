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
            $table->string('outbound_webhook_url')->nullable()->after('telegram_chat_id');
            $table->text('outbound_webhook_secret')->nullable()->after('outbound_webhook_url');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table): void {
            $table->dropColumn(['outbound_webhook_url', 'outbound_webhook_secret']);
        });
    }
};
