<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\ConfiguresReliableQueueJob;
use App\Services\Sla\SlaEscalationBufferService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessSlaEscalationBuffersJob implements ShouldBeUnique, ShouldQueue
{
    use ConfiguresReliableQueueJob;
    use Queueable;

    public int $uniqueFor = 55;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function uniqueId(): string
    {
        return 'sla-escalation-buffers';
    }

    public function handle(SlaEscalationBufferService $bufferService): void
    {
        $processed = $bufferService->processDueLeads();

        Log::info('ProcessSlaEscalationBuffersJob completed', [
            'processed' => $processed,
        ]);
    }
}
