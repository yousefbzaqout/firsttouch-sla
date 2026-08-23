<?php

declare(strict_types=1);

namespace App\Services\Ai;

class TextChunkerService
{
    private const int CHUNK_SIZE = 300;

    private const int OVERLAP_SIZE = 50;

    /**
     * @return list<string>
     */
    public function chunk(string $text): array
    {
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false || count($words) === 0) {
            return [];
        }

        $chunks = [];
        $position = 0;
        $totalWords = count($words);

        while ($position < $totalWords) {
            $chunkWords = array_slice($words, $position, self::CHUNK_SIZE);
            $chunks[] = implode(' ', $chunkWords);

            $position += self::CHUNK_SIZE - self::OVERLAP_SIZE;
        }

        return $chunks;
    }
}
