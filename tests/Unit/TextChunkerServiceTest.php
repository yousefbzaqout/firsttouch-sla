<?php

declare(strict_types=1);

use App\Services\Ai\TextChunkerService;

it('splits text into chunks of approximately 300 words', function (): void {
    $service = new TextChunkerService;
    $text = implode(' ', array_fill(0, 1000, 'word'));

    $chunks = $service->chunk($text);

    expect($chunks)->not->toBeEmpty();

    $firstChunkWords = str_word_count($chunks[0]);
    expect($firstChunkWords)->toBe(300);
});

it('creates overlapping chunks with 50-word overlap', function (): void {
    $service = new TextChunkerService;

    $words = [];
    for ($i = 1; $i <= 1000; $i++) {
        $words[] = "word{$i}";
    }
    $text = implode(' ', $words);

    $chunks = $service->chunk($text);

    // First chunk: words 1-300, second chunk: words 251-550 (overlap of 50)
    $firstChunkWords = explode(' ', $chunks[0]);
    $secondChunkWords = explode(' ', $chunks[1]);

    // Last 50 words of first chunk should equal first 50 words of second chunk
    $endOfFirst = array_slice($firstChunkWords, -50);
    $startOfSecond = array_slice($secondChunkWords, 0, 50);

    expect($endOfFirst)->toBe($startOfSecond);
});

it('returns empty array for empty text', function (): void {
    $service = new TextChunkerService;

    expect($service->chunk(''))->toBe([]);
});

it('returns single chunk for short text', function (): void {
    $service = new TextChunkerService;
    $text = implode(' ', array_fill(0, 100, 'word'));

    $chunks = $service->chunk($text);

    expect($chunks)->toHaveCount(1);
});
