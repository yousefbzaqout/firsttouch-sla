<?php

declare(strict_types=1);

namespace App\Pipelines;

use App\DTOs\LeadData;
use App\Models\Lead;
use App\Pipelines\Pipes\AssignRoundRobinSalesPipe;
use App\Pipelines\Pipes\CalculateSlaDeadlinePipe;
use App\Pipelines\Pipes\DispatchAiResponsePipe;
use App\Pipelines\Pipes\PersistLeadPipe;
use App\Pipelines\Pipes\VerifyIdempotencyPipe;
use Illuminate\Support\Facades\Pipeline;

class LeadProcessingPipeline
{
    public function process(LeadData $leadData): ?Lead
    {
        /** @var array{lead_data: LeadData, lead: Lead|null} $result */
        $result = Pipeline::send(['lead_data' => $leadData, 'lead' => null])
            ->through([
                VerifyIdempotencyPipe::class,
                AssignRoundRobinSalesPipe::class,
                CalculateSlaDeadlinePipe::class,
                PersistLeadPipe::class,
                DispatchAiResponsePipe::class,
            ])
            ->thenReturn();

        return $result['lead'] ?? null;
    }
}
