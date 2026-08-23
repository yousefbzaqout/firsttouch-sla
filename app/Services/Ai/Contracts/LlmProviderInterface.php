<?php

declare(strict_types=1);

namespace App\Services\Ai\Contracts;

interface LlmProviderInterface
{
    public function generateResponse(string $systemPrompt, string $userQuery, string $model): string;
}
