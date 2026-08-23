<?php

declare(strict_types=1);

namespace App\Services\Knowledge\Extractors;

use App\Services\Knowledge\Contracts\KnowledgeTextExtractorInterface;
use RuntimeException;

class PlainTextKnowledgeExtractor implements KnowledgeTextExtractorInterface
{
    /** @var list<string> */
    private const SUPPORTED_EXTENSIONS = ['txt', 'md', 'markdown', 'csv', 'json', 'html', 'htm'];

    public function supports(string $absolutePath): bool
    {
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        return in_array($extension, self::SUPPORTED_EXTENSIONS, true);
    }

    /**
     * @param  list<string>  $absolutePaths
     */
    public function extract(array $absolutePaths): string
    {
        $parts = [];

        foreach ($absolutePaths as $absolutePath) {
            if (! is_file($absolutePath)) {
                throw new RuntimeException("Knowledge file not found: {$absolutePath}");
            }

            if (! $this->supports($absolutePath)) {
                throw new RuntimeException('Unsupported knowledge file type: '.pathinfo($absolutePath, PATHINFO_EXTENSION));
            }

            $contents = file_get_contents($absolutePath);

            if ($contents === false) {
                throw new RuntimeException("Unable to read knowledge file: {$absolutePath}");
            }

            $trimmed = trim($contents);

            if ($trimmed !== '') {
                $parts[] = $trimmed;
            }
        }

        return implode("\n\n", $parts);
    }
}
