<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Enums\KnowledgeType;
use App\Jobs\ProcessKnowledgeDocumentJob;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Services\Knowledge\Contracts\KnowledgeTextExtractorInterface;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

class KnowledgeIngestionService
{
    public function __construct(
        private readonly KnowledgeTextExtractorInterface $textExtractor,
    ) {}

    /**
     * @param  array<string, mixed>  $formData
     */
    public function ingestFromFormData(KnowledgeBase $knowledgeBase, array $formData): void
    {
        $content = $this->resolveContent($knowledgeBase->type, $formData);

        if ($content === '') {
            return;
        }

        KnowledgeChunk::withoutGlobalScopes()
            ->where('knowledge_base_id', $knowledgeBase->id)
            ->delete();

        ProcessKnowledgeDocumentJob::dispatch($knowledgeBase->id, $content);
    }

    /**
     * @param  array<string, mixed>  $formData
     */
    public function resolveContent(KnowledgeType $type, array $formData): string
    {
        if ($type->requiresTextInput()) {
            return trim((string) ($formData['content'] ?? ''));
        }

        if ($type->requiresFileUpload()) {
            return $this->extractFromUploads($formData['document'] ?? null);
        }

        throw new InvalidArgumentException("Unsupported knowledge type: {$type->value}");
    }

    private function extractFromUploads(mixed $document): string
    {
        $paths = $this->normalizeUploadPaths($document);

        if ($paths === []) {
            return '';
        }

        $absolutePaths = [];

        foreach ($paths as $path) {
            $absolutePath = Storage::disk('local')->path($path);

            if (! is_file($absolutePath)) {
                throw new RuntimeException("Uploaded knowledge file missing on disk: {$path}");
            }

            $absolutePaths[] = $absolutePath;
        }

        return $this->textExtractor->extract($absolutePaths);
    }

    /**
     * @return list<string>
     */
    private function normalizeUploadPaths(mixed $document): array
    {
        if ($document === null || $document === '' || $document === []) {
            return [];
        }

        if (is_string($document)) {
            return [$document];
        }

        if (! is_array($document)) {
            throw new InvalidArgumentException('Invalid document upload payload.');
        }

        $paths = [];

        foreach ($document as $path) {
            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }

        return $paths;
    }
}
