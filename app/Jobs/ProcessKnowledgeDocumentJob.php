<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\ConfiguresReliableQueueJob;
use App\Models\KnowledgeBase;
use App\Services\Ai\Contracts\EmbeddingServiceInterface;
use App\Services\Ai\TextChunkerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProcessKnowledgeDocumentJob implements ShouldQueue
{
    use ConfiguresReliableQueueJob;
    use Queueable;

    public function __construct(
        private readonly string $knowledgeBaseId,
        private readonly string $content,
    ) {}

    public function handle(
        TextChunkerService $chunker,
        EmbeddingServiceInterface $embeddingService,
    ): void {
        $knowledgeBase = KnowledgeBase::withoutGlobalScopes()->find($this->knowledgeBaseId);

        if (! $knowledgeBase) {
            return;
        }

        $chunks = $chunker->chunk($this->content);

        foreach ($chunks as $chunkText) {
            $embedding = $embeddingService->generateEmbedding($chunkText);

            if ($embedding === []) {
                continue;
            }

            $vectorString = '['.implode(',', $embedding).']';

            DB::statement(
                'INSERT INTO knowledge_chunks (id, tenant_id, knowledge_base_id, content, priority, embedding, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?::vector, NOW(), NOW())',
                [
                    (string) Str::uuid(),
                    $knowledgeBase->tenant_id,
                    $knowledgeBase->id,
                    $chunkText,
                    'normal',
                    $vectorString,
                ],
            );
        }
    }
}
