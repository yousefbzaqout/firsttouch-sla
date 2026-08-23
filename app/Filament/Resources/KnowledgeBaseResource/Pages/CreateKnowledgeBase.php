<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeBaseResource\Pages;

use App\Filament\Resources\KnowledgeBaseResource;
use App\Models\KnowledgeBase;
use App\Services\Knowledge\KnowledgeIngestionService;
use Filament\Resources\Pages\CreateRecord;

class CreateKnowledgeBase extends CreateRecord
{
    protected static string $resource = KnowledgeBaseResource::class;

    protected function afterCreate(): void
    {
        if (! $this->record instanceof KnowledgeBase) {
            return;
        }

        app(KnowledgeIngestionService::class)->ingestFromFormData($this->record, $this->data ?? []);
    }
}
