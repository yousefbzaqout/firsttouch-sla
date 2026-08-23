<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VectorSearchService
{
    /**
     * @param  list<float>  $queryEmbedding
     * @return Collection<int, \stdClass&object{id: string, content: string, priority: string, knowledge_base_id: string, similarity: float}>
     */
    public function searchSimilarChunks(
        string $tenantId,
        array $queryEmbedding,
        float $minConfidence = 0.85,
        int $limit = 5,
    ): Collection {
        $vectorString = '['.implode(',', $queryEmbedding).']';
        $maxDistance = 1.0 - $minConfidence;

        $results = DB::select(
            <<<'SQL'
                SELECT
                    kc.id,
                    kc.content,
                    kc.priority,
                    kc.knowledge_base_id,
                    1 - (kc.embedding <=> :embedding::vector) AS similarity
                FROM knowledge_chunks kc
                INNER JOIN knowledge_bases kb ON kb.id = kc.knowledge_base_id
                WHERE kc.tenant_id = :tenant_id
                  AND kb.is_active = true
                  AND (kc.embedding <=> :embedding2::vector) <= :max_distance
                ORDER BY
                    CASE WHEN kc.priority = 'high' THEN 0 ELSE 1 END,
                    kc.embedding <=> :embedding3::vector
                LIMIT :limit
                SQL,
            [
                'embedding' => $vectorString,
                'embedding2' => $vectorString,
                'embedding3' => $vectorString,
                'tenant_id' => $tenantId,
                'max_distance' => $maxDistance,
                'limit' => $limit,
            ],
        );

        return collect($results);
    }
}
