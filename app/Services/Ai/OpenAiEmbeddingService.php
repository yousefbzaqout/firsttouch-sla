<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Contracts\EmbeddingServiceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiEmbeddingService implements EmbeddingServiceInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'text-embedding-3-small',
        private readonly ?string $openRouterApiKey = null,
    ) {}

    /**
     * @return list<float>
     */
    public function generateEmbedding(string $text): array
    {
        if ($this->apiKey !== '') {
            $embedding = $this->requestEmbedding(
                baseUrl: 'https://api.openai.com/v1/embeddings',
                token: $this->apiKey,
                model: $this->model,
                text: $text,
            );

            if ($embedding !== []) {
                return $embedding;
            }
        }

        if (is_string($this->openRouterApiKey) && $this->openRouterApiKey !== '') {
            $embedding = $this->requestEmbedding(
                baseUrl: 'https://openrouter.ai/api/v1/embeddings',
                token: $this->openRouterApiKey,
                model: 'openai/text-embedding-3-small',
                text: $text,
            );

            if ($embedding !== []) {
                return $embedding;
            }
        }

        Log::warning('Using local lexical embedding fallback (no usable OpenAI/OpenRouter embedding response)');

        return $this->localLexicalEmbedding($text);
    }

    /**
     * Deterministic character-ngram embedding for local/dev when providers are unavailable.
     *
     * @return list<float>
     */
    private function localLexicalEmbedding(string $text): array
    {
        $dimensions = 1536;
        /** @var list<float> $vector */
        $vector = array_fill(0, $dimensions, 0.0);
        $normalized = strtolower((string) preg_replace('/\s+/', ' ', $text));
        $length = strlen($normalized);

        for ($i = 0; $i < $length; $i++) {
            $end = min(3, $length - $i);
            for ($size = 1; $size <= $end; $size++) {
                $gram = substr($normalized, $i, $size);
                $index = abs((int) crc32($gram)) % $dimensions;
                $vector[$index] += 1.0 / $size;
            }
        }

        $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $vector)));

        if ($norm <= 0.0) {
            $vector[0] = 1.0;

            return array_values($vector);
        }

        foreach ($vector as $i => $value) {
            $vector[$i] = $value / $norm;
        }

        return array_values($vector);
    }

    /**
     * @return list<float>
     */
    private function requestEmbedding(string $baseUrl, string $token, string $model, string $text): array
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->post($baseUrl, [
                'model' => $model,
                'input' => $text,
            ]);

        if (! $response->successful()) {
            Log::warning('Embedding provider request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        /** @var list<float> */
        return $response->json('data.0.embedding', []);
    }
}
