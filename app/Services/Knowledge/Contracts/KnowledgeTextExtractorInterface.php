<?php

declare(strict_types=1);

namespace App\Services\Knowledge\Contracts;

interface KnowledgeTextExtractorInterface
{
    /**
     * @param  list<string>  $absolutePaths
     */
    public function extract(array $absolutePaths): string;

    public function supports(string $absolutePath): bool;
}
