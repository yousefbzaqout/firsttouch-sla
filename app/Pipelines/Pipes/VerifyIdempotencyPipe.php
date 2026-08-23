<?php

declare(strict_types=1);

namespace App\Pipelines\Pipes;

use App\DTOs\LeadData;
use App\Models\Lead;
use Closure;

class VerifyIdempotencyPipe
{
    /**
     * @param  array{lead_data: LeadData, lead: Lead|null}  $passable
     * @return array{lead_data: LeadData, lead: Lead|null}
     */
    public function handle(array $passable, Closure $next): array
    {
        $leadData = $passable['lead_data'];

        // Blank external IDs are not idempotent keys (stored as NULL).
        if ($leadData->externalLeadId === '') {
            return $next($passable);
        }

        $existing = Lead::withoutGlobalScopes()
            ->where('tenant_id', $leadData->tenantId)
            ->where('external_lead_id', $leadData->externalLeadId)
            ->first();

        if ($existing !== null) {
            $passable['lead'] = $existing;

            return $passable;
        }

        return $next($passable);
    }
}
