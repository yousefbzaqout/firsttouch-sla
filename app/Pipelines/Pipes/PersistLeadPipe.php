<?php

declare(strict_types=1);

namespace App\Pipelines\Pipes;

use App\DTOs\LeadData;
use App\Enums\SlaStatus;
use App\Events\LeadIngestedEvent;
use App\Models\Lead;
use Carbon\Carbon;
use Closure;

class PersistLeadPipe
{
    /**
     * @param  array{lead_data: LeadData, lead: Lead|null, assigned_user_id?: int|null, sla_deadline?: Carbon|null, sla_status?: SlaStatus|null, sla_started_at?: Carbon|null}  $passable
     * @return array{lead_data: LeadData, lead: Lead}
     */
    public function handle(array $passable, Closure $next): array
    {
        $data = $passable['lead_data'];

        /** @var array<string, mixed> $meta */
        $meta = $data->rawPayload;

        if ($data->campaignId !== null && $data->campaignId !== '') {
            $meta['campaign_id'] = $data->campaignId;
        }

        if ($data->formId !== null && $data->formId !== '') {
            $meta['form_id'] = $data->formId;
        }

        $interestParts = [];

        if ($data->campaignId !== null && $data->campaignId !== '') {
            $interestParts[] = $data->campaignId;
        }

        if ($data->formId !== null && $data->formId !== '') {
            $interestParts[] = 'Form '.$data->formId;
        }

        if ($interestParts !== []) {
            $meta['interested_service'] = implode(' · ', $interestParts);
        }

        $hasDeadline = array_key_exists('sla_deadline', $passable) && $passable['sla_deadline'] !== null;
        $slaStatus = $passable['sla_status'] ?? ($hasDeadline ? SlaStatus::Active : SlaStatus::Pending);
        $slaStartedAt = $passable['sla_started_at'] ?? ($slaStatus === SlaStatus::Active ? now() : null);

        $externalLeadId = $data->externalLeadId !== '' ? $data->externalLeadId : null;

        $lead = Lead::withoutGlobalScopes()->create([
            'tenant_id' => $data->tenantId,
            'source' => $data->source,
            'external_lead_id' => $externalLeadId,
            'name' => $data->name,
            'phone' => $data->phone,
            'email' => $data->email,
            'meta_data' => $meta,
            'assigned_user_id' => $passable['assigned_user_id'] ?? null,
            'sla_deadline' => $passable['sla_deadline'] ?? null,
            'sla_status' => $slaStatus,
            'sla_started_at' => $slaStartedAt,
        ]);

        LeadIngestedEvent::dispatch($lead);

        $passable['lead'] = $lead;

        return $next($passable);
    }
}
