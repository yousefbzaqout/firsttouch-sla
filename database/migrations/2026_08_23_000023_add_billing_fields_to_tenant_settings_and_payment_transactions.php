<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->string('plan_type')->default('free')->after('credits_balance');
            $table->string('subscription_status')->default('active')->after('plan_type');
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->string('type')->default('topup')->after('driver');
            $table->string('description')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn(['plan_type', 'subscription_status']);
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropColumn(['type', 'description']);
        });
    }
};
