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
            $table->json('skills_tags')->nullable()->after('is_online');
        });

        Schema::table('leads', function (Blueprint $table): void {
            $table->json('routing_tags')->nullable()->after('ai_quick_replies');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('skills_tags');
        });

        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn('routing_tags');
        });
    }
};
