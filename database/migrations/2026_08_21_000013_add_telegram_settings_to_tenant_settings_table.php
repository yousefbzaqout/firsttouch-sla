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
            $table->text('telegram_bot_token')->nullable()->after('tiktok_webhook_secret');
            $table->string('telegram_chat_id')->nullable()->after('telegram_bot_token');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table): void {
            $table->dropColumn(['telegram_bot_token', 'telegram_chat_id']);
        });
    }
};
