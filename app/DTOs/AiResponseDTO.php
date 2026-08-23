<?php

declare(strict_types=1);

namespace App\DTOs;

readonly class AiResponseDTO
{
    /**
     * @param  list<AiQuickReplyDTO>  $quickReplies
     * @param  list<string>  $routingTags
     */
    public function __construct(
        public bool $success,
        public ?string $answer,
        public bool $fallbackToHuman,
        public float $confidenceScore,
        public ?string $qualificationSummary = null,
        public bool $isQualified = false,
        public float $ragSimilarity = 0.0,
        public array $quickReplies = [],
        public array $routingTags = [],
    ) {}
}
