<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeBaseResource\Pages;

use App\Filament\Resources\KnowledgeBaseResource;
use App\Models\KnowledgeBase;
use App\Services\Knowledge\KnowledgeIngestionService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditKnowledgeBase extends EditRecord
{
    protected static string $resource = KnowledgeBaseResource::class;

    protected function afterSave(): void
    {
        if (! $this->record instanceof KnowledgeBase) {
            return;
        }

        app(KnowledgeIngestionService::class)->ingestFromFormData(
            $this->record->fresh() ?? $this->record,
            $this->data ?? [],
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
