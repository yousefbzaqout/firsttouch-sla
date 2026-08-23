<?php

declare(strict_types=1);

namespace App\Services\Ai\Contracts;

interface EmbeddingServiceInterface
{
    /**
     * @return list<float>
     */
    public function generateEmbedding(string $text): array;
}
