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
        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('knowledge_base_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->string('priority')->default('normal');
            $table->timestamps();

            $table->index(['tenant_id', 'priority']);
        });

        DB::statement('ALTER TABLE knowledge_chunks ADD COLUMN embedding vector(1536)');
        DB::statement('CREATE INDEX knowledge_chunks_embedding_idx ON knowledge_chunks USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
