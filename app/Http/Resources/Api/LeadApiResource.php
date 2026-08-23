<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Lead
 */
class LeadApiResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Lead $lead */
        $lead = $this->resource;

        return [
            'id' => (string) $lead->id,
            'name' => $lead->name,
            'phone' => $lead->phone,
            'email' => $lead->email,
            'source' => $lead->source->value,
            'status' => $lead->status->value,
            'sla' => [
                'status' => $lead->sla_status->value,
                'deadline' => $lead->sla_deadline?->toIso8601String(),
                'started_at' => $lead->sla_started_at?->toIso8601String(),
                'first_action_at' => $lead->first_action_at?->toIso8601String(),
                'breached' => $lead->sla_status->value === 'breached',
            ],
            'assigned_user' => $this->whenLoaded('assignedUser', function () use ($lead): ?array {
                $assignee = $lead->assignedUser;

                if ($assignee === null) {
                    return null;
                }

                return [
                    'id' => $assignee->id,
                    'name' => $assignee->name,
                ];
            }),
            'routing_tags' => $lead->routing_tags ?? [],
            'created_at' => $lead->created_at?->toIso8601String(),
            'updated_at' => $lead->updated_at?->toIso8601String(),
        ];
    }
}
