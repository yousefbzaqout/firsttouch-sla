<?php

declare(strict_types=1);

namespace App\DTOs;

readonly class AiQuickReplyDTO
{
    public function __construct(
        public string $label,
        public string $text,
    ) {}

    /**
     * @return array{label: string, text: string}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'text' => $this->text,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $label = isset($data['label']) && is_scalar($data['label']) ? trim((string) $data['label']) : '';
        $text = isset($data['text']) && is_scalar($data['text']) ? trim((string) $data['text']) : '';

        if ($label === '' || $text === '') {
            return null;
        }

        return new self(
            label: mb_substr($label, 0, 64),
            text: $text,
        );
    }
}
