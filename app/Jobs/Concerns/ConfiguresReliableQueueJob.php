<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use Illuminate\Support\Facades\Log;
use Throwable;

trait ConfiguresReliableQueueJob
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public int $timeout = 120;

    public function failed(?Throwable $exception): void
    {
        Log::error(static::class.' failed permanently', [
            'error' => $exception?->getMessage(),
            'exception' => $exception !== null ? $exception::class : null,
        ]);
    }
}
