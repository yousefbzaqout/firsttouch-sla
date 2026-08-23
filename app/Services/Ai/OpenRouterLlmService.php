<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Contracts\LlmProviderInterface;
use Illuminate\Support\Facades\Http;

class OpenRouterLlmService implements LlmProviderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://openrouter.ai/api/v1',
    ) {}

    public function generateResponse(string $systemPrompt, string $userQuery, string $model): string
    {
        $response = Http::withToken($this->apiKey)
            ->post("{$this->baseUrl}/chat/completions", [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userQuery],
                ],
            ]);

        return (string) $response->json('choices.0.message.content', '');
    }
}
