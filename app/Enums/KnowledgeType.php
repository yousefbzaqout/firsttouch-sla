<?php

declare(strict_types=1);

namespace App\Enums;

enum KnowledgeType: string
{
    case Document = 'document';
    case DirectText = 'direct_text';
    case QaPair = 'qa_pair';

    public function requiresFileUpload(): bool
    {
        return $this === self::Document;
    }

    public function requiresTextInput(): bool
    {
        return match ($this) {
            self::DirectText, self::QaPair => true,
            self::Document => false,
        };
    }

    public function contentFieldLabel(): string
    {
        return match ($this) {
            self::QaPair => 'Q&A Text',
            self::DirectText => 'Content',
            self::Document => 'Document file',
        };
    }
}
