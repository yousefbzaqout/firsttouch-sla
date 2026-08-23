<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeBaseResource\Pages;

use App\Filament\Resources\KnowledgeBaseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKnowledgeBases extends ListRecords
{
    protected static string $resource = KnowledgeBaseResource::class;

    protected function authorizeAccess(): void
    {
        abort_unless(KnowledgeBaseResource::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
