<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('avatar_url')->nullable()->after('email');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('logo_url')->nullable()->after('domain');
            $table->string('business_category')->nullable()->after('logo_url');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('avatar_url');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['logo_url', 'business_category']);
        });
    }
};
