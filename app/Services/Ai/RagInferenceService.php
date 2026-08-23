<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\DTOs\AiQuickReplyDTO;
use App\DTOs\AiResponseDTO;
use App\Models\Lead;
use App\Models\TenantSetting;
use App\Services\Ai\Contracts\EmbeddingServiceInterface;
use App\Services\Ai\Contracts\LlmProviderInterface;

class RagInferenceService
{
    public function __construct(
        private readonly CreditManagerService $creditManager,
        private readonly VectorSearchService $vectorSearch,
        private readonly EmbeddingServiceInterface $embeddingService,
        private readonly RagPromptBuilderService $promptBuilder,
        private readonly LlmProviderInterface $llmProvider,
    ) {}

    public function processLeadQuery(Lead $lead, ?string $userQuery = null): AiResponseDTO
    {
        $tenant = $lead->tenant;

        if ($tenant === null) {
            return $this->fallback(null, 0.0);
        }

        if (! $this->creditManager->hasAvailableCredits($tenant)) {
            $this->creditManager->handleExhaustedCredits($tenant);

            return $this->fallback('AI credits exhausted. Human follow-up required.', 0.0);
        }

        $setting = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->first();

        $threshold = $setting !== null
            ? (float) $setting->ai_confidence_threshold / 100.0
            : 0.90;

        $model = $setting !== null
            ? $setting->ai_selected_model
            : (string) config('services.openrouter.model', 'openrouter/free');

        $leadBrief = $this->buildLeadBrief($lead, $userQuery);
        $queryEmbedding = $this->embeddingService->generateEmbedding($leadBrief);

        if ($queryEmbedding === []) {
            return $this->fallback('Embedding unavailable. Human follow-up recommended.', 0.0);
        }

        $chunks = $this->vectorSearch->searchSimilarChunks(
            $tenant->id,
            $queryEmbedding,
            max(0.05, $threshold * 0.5),
            5,
        );

        if ($chunks->isEmpty()) {
            return $this->fallback('No matching knowledge-base context for this tenant. Human follow-up recommended.', 0.0);
        }

        $ragSimilarity = (float) $chunks->max('similarity');

        if ($ragSimilarity < $threshold) {
            return $this->fallback(
                'Knowledge-base confidence below threshold. Human follow-up recommended.',
                $ragSimilarity,
            );
        }

        $systemPrompt = $this->promptBuilder->buildQualificationPrompt($chunks);
        $raw = $this->llmProvider->generateResponse($systemPrompt, $leadBrief, $model);
        $parsed = $this->parseLlmPayload($raw, $ragSimilarity);

        if ($parsed['suggested_reply'] === null && $parsed['qualification_summary'] === null) {
            return $this->fallback('AI returned insufficient context. Human follow-up recommended.', $ragSimilarity);
        }

        $score = $parsed['qualification_score'];
        $isQualified = $parsed['is_qualified'] && $score >= $threshold && $ragSimilarity >= $threshold;
        $success = $parsed['suggested_reply'] !== null && trim((string) $parsed['suggested_reply']) !== '';

        $quickReplies = $isQualified
            ? $this->normalizeQuickReplies($parsed['quick_replies'], $parsed['suggested_reply'])
            : [];

        $routingTags = $this->normalizeRoutingTags($parsed['routing_tags']);

        $this->creditManager->deductCredit($tenant);

        return new AiResponseDTO(
            success: $success || $isQualified,
            answer: $parsed['suggested_reply'],
            fallbackToHuman: ! $isQualified,
            confidenceScore: $score,
            qualificationSummary: $parsed['qualification_summary'],
            isQualified: $isQualified,
            ragSimilarity: $ragSimilarity,
            quickReplies: $quickReplies,
            routingTags: $routingTags,
        );
    }

    private function buildLeadBrief(Lead $lead, ?string $userQuery): string
    {
        $meta = $lead->meta_data ?? [];
        $interest = is_string($meta['interested_service'] ?? null) ? $meta['interested_service'] : null;
        $campaign = is_string($meta['campaign_id'] ?? null) ? $meta['campaign_id'] : null;
        $form = is_string($meta['form_id'] ?? null) ? $meta['form_id'] : null;

        $metaSnippet = $this->summarizeMeta($meta);

        $lines = [
            'Qualify this inbound sales lead and draft a first-touch reply for the assigned human sales rep.',
            'Also prepare 2-3 Telegram quick-reply templates tailored to industry, language, and inquiry.',
            'Also extract 1-3 routing_tags for matching the lead to the best sales agent skills.',
            '',
            'Lead profile:',
            '- Name: '.$lead->name,
            '- Phone: '.$lead->phone,
            '- Email: '.($lead->email ?? 'n/a'),
            '- Source: '.$lead->source->value,
            '- Interested service / campaign: '.($interest ?? $campaign ?? 'n/a'),
            '- Form ID: '.($form ?? 'n/a'),
        ];

        if ($metaSnippet !== '') {
            $lines[] = '- Form / payload signals: '.$metaSnippet;
        }

        if (filled($userQuery)) {
            $lines[] = '';
            $lines[] = 'Additional sales instruction: '.$userQuery;
        }

        $lines[] = '';
        $lines[] = 'Return JSON only as specified in the system prompt.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function summarizeMeta(array $meta): string
    {
        $skip = [
            'ai_qualification_summary',
            'ai_suggested_reply',
            'ai_confidence_score',
            'ai_fallback_to_human',
            'ai_processed_at',
            'ai_rag_similarity',
            'ai_is_qualified',
            'internal_notes',
            'telegram_quick_reply_used',
        ];
        $flat = [];

        $walker = function (mixed $value, string $prefix = '') use (&$walker, &$flat, $skip): void {
            if (is_array($value)) {
                foreach ($value as $key => $child) {
                    if (in_array((string) $key, $skip, true)) {
                        continue;
                    }

                    $next = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                    $walker($child, $next);
                }

                return;
            }

            if (is_bool($value)) {
                $flat[] = $prefix.'='.($value ? 'true' : 'false');

                return;
            }

            if (is_scalar($value)) {
                $text = trim((string) $value);

                if ($text !== '' && strlen($text) <= 120) {
                    $flat[] = $prefix.'='.$text;
                }
            }
        };

        $walker($meta);

        return implode('; ', array_slice($flat, 0, 20));
    }

    /**
     * @return array{
     *     qualification_score: float,
     *     qualification_summary: ?string,
     *     is_qualified: bool,
     *     suggested_reply: ?string,
     *     quick_replies: list<array{label: string, text: string}>,
     *     routing_tags: list<string>
     * }
     */
    private function parseLlmPayload(string $raw, float $ragSimilarity): array
    {
        $trimmed = trim($raw);

        if ($trimmed === '' || $trimmed === 'INSUFFICIENT_CONTEXT') {
            return [
                'qualification_score' => 0.0,
                'qualification_summary' => null,
                'is_qualified' => false,
                'suggested_reply' => null,
                'quick_replies' => [],
                'routing_tags' => [],
            ];
        }

        $decoded = json_decode($trimmed, true);

        if (! is_array($decoded) && preg_match('/\{.*\}/s', $trimmed, $matches) === 1) {
            $decoded = json_decode($matches[0], true);
        }

        if (is_array($decoded)) {
            $summary = isset($decoded['qualification_summary']) && is_scalar($decoded['qualification_summary'])
                ? trim((string) $decoded['qualification_summary'])
                : null;
            $reply = isset($decoded['suggested_reply']) && is_scalar($decoded['suggested_reply'])
                ? trim((string) $decoded['suggested_reply'])
                : null;
            $score = isset($decoded['qualification_score']) && is_numeric($decoded['qualification_score'])
                ? max(0.0, min(1.0, (float) $decoded['qualification_score']))
                : $ragSimilarity;
            $isQualified = array_key_exists('is_qualified', $decoded)
                ? filter_var($decoded['is_qualified'], FILTER_VALIDATE_BOOLEAN)
                : $score >= 0.5;

            return [
                'qualification_score' => $score,
                'qualification_summary' => $summary !== '' ? $summary : null,
                'is_qualified' => $isQualified,
                'suggested_reply' => $reply !== '' ? $reply : null,
                'quick_replies' => $this->extractQuickReplyArrays($decoded['quick_replies'] ?? null),
                'routing_tags' => $this->extractRoutingTags($decoded['routing_tags'] ?? null),
            ];
        }

        return [
            'qualification_score' => $ragSimilarity,
            'qualification_summary' => 'AI qualification completed successfully.',
            'is_qualified' => true,
            'suggested_reply' => $trimmed,
            'quick_replies' => [],
            'routing_tags' => [],
        ];
    }

    /**
     * @return list<string>
     */
    private function extractRoutingTags(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $tags = [];

        foreach ($raw as $tag) {
            if (! is_string($tag) && ! is_numeric($tag)) {
                continue;
            }

            $value = trim((string) $tag);

            if ($value !== '') {
                $tags[] = $value;
            }
        }

        return array_values(array_unique(array_slice($tags, 0, 3)));
    }

    /**
     * @param  list<string>  $tags
     * @return list<string>
     */
    private function normalizeRoutingTags(array $tags): array
    {
        return array_values(array_unique(array_slice(array_filter(
            array_map(static fn (string $tag): string => trim($tag), $tags),
            static fn (string $tag): bool => $tag !== '',
        ), 0, 3)));
    }

    /**
     * @return list<array{label: string, text: string}>
     */
    private function extractQuickReplyArrays(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $items = [];

        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }

            $dto = AiQuickReplyDTO::fromArray($row);

            if ($dto !== null) {
                $items[] = $dto->toArray();
            }
        }

        return $items;
    }

    /**
     * @param  list<array{label: string, text: string}>  $rawReplies
     * @return list<AiQuickReplyDTO>
     */
    private function normalizeQuickReplies(array $rawReplies, ?string $suggestedReply): array
    {
        $replies = [];

        foreach ($rawReplies as $row) {
            $dto = AiQuickReplyDTO::fromArray($row);

            if ($dto !== null) {
                $replies[] = $dto;
            }
        }

        if ($replies === [] && is_string($suggestedReply) && trim($suggestedReply) !== '') {
            $primary = trim($suggestedReply);
            $replies[] = new AiQuickReplyDTO('Reply 1: Primary', $primary);
            $replies[] = new AiQuickReplyDTO(
                'Reply 2: Short follow-up',
                'Hi! Just following up on your inquiry — happy to share details or book a quick call whenever you are free.',
            );
        }

        if (count($replies) === 1 && is_string($suggestedReply) && trim($suggestedReply) !== '') {
            $replies[] = new AiQuickReplyDTO(
                'Reply 2: Soft CTA',
                'Hi, thanks for reaching out. Would you prefer a short call or a pricing overview first?',
            );
        }

        return array_slice($replies, 0, 3);
    }

    private function fallback(?string $summary, float $ragSimilarity): AiResponseDTO
    {
        return new AiResponseDTO(
            success: false,
            answer: null,
            fallbackToHuman: true,
            confidenceScore: $ragSimilarity,
            qualificationSummary: $summary,
            isQualified: false,
            ragSimilarity: $ragSimilarity,
            quickReplies: [],
            routingTags: [],
        );
    }
}
