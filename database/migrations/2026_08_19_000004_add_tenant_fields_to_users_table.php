<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('role')->default('sales_rep')->after('name');
            $table->bigInteger('telegram_chat_id')->nullable()->after('email');
            $table->boolean('is_active')->default(true)->after('telegram_chat_id');

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'is_active']);
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn(['role', 'telegram_chat_id', 'is_active']);
        });
    }
};
