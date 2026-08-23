<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProcessSlaEscalationBuffersJob;
use App\Services\Sla\SlaEscalationBufferService;
use Illuminate\Console\Command;

class CheckSlaEscalationBuffersCommand extends Command
{
    protected $signature = 'sla:check-escalation-buffers {--sync : Run inline instead of queueing}';

    protected $description = 'Check assigned leads for 50% SLA warnings and 80% auto-reassignment';

    public function handle(SlaEscalationBufferService $bufferService): int
    {
        if ($this->option('sync')) {
            $processed = $bufferService->processDueLeads();
            $this->info("Processed {$processed} SLA escalation buffer action(s).");

            return self::SUCCESS;
        }

        ProcessSlaEscalationBuffersJob::dispatch();
        $this->info('Dispatched SLA escalation buffer job.');

        return self::SUCCESS;
    }
}
