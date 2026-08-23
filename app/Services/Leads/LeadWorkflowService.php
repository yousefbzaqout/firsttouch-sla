<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Enums\LeadStatus;
use App\Enums\SlaStatus;
use App\Enums\UserRole;
use App\Jobs\SendLeadAssignedNotificationJob;
use App\Models\Lead;
use App\Models\TenantSetting;
use App\Models\TenantWorkingHour;
use App\Models\User;
use App\Services\Sla\SlaCalculatorService;
use App\Services\Sla\SlaEscalationBufferService;
use App\Support\Timezones;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class LeadWorkflowService
{
    public function __construct(
        private readonly SlaEscalationBufferService $slaEscalationBuffer,
        private readonly SlaCalculatorService $slaCalculator,
    ) {}

    public function claim(Lead $lead, User $actor): Lead
    {
        $this->assertCanAccessLead($lead, $actor);

        if ($lead->assigned_user_id !== null) {
            throw ValidationException::withMessages([
                'lead' => 'This lead is already assigned.',
            ]);
        }

        $now = now();
        $needsSlaRestart = $lead->sla_deadline === null
            || $lead->sla_status === SlaStatus::Pending;

        $attributes = [
            'assigned_user_id' => $actor->id,
            'status' => LeadStatus::Claimed->value,
            'claimed_at' => $now,
            'sla_warning_sent_at' => null,
            'sla_escalated_at' => null,
        ];

        if ($needsSlaRestart) {
            $setting = TenantSetting::withoutGlobalScopes()
                ->where('tenant_id', $lead->tenant_id)
                ->first();
            $slaMinutes = $setting !== null ? $setting->sla_timeout_minutes : 5;
            $timezone = Timezones::resolve(
                is_string($setting?->timezone) ? $setting->timezone : null,
            );
            $workingHours = TenantWorkingHour::withoutGlobalScopes()
                ->where('tenant_id', $lead->tenant_id)
                ->get();

            $attributes['sla_started_at'] = $now;
            $attributes['sla_deadline'] = $this->slaCalculator->calculate(
                $now->copy(),
                $slaMinutes,
                $workingHours,
                $timezone,
            );
            $attributes['sla_status'] = SlaStatus::Active->value;
        } else {
            $attributes['sla_started_at'] = $lead->sla_started_at ?? $now;
        }

        $updated = Lead::withoutGlobalScopes()
            ->whereKey($lead->id)
            ->whereNull('assigned_user_id')
            ->update($attributes);

        if ($updated !== 1) {
            throw ValidationException::withMessages([
                'lead' => 'This lead is already assigned.',
            ]);
        }

        $fresh = Lead::withoutGlobalScopes()->find($lead->id) ?? $lead;
        SendLeadAssignedNotificationJob::dispatch($fresh->id);

        return $fresh;
    }

    public function markInProgress(Lead $lead, User $actor): Lead
    {
        $this->assertCanMutateAssignedLead($lead, $actor);

        $lead->update([
            'status' => LeadStatus::InProgress,
            'first_action_at' => $lead->first_action_at ?? now(),
        ]);

        return $this->slaEscalationBuffer->cancelBuffers($lead->fresh() ?? $lead);
    }

    public function markContacted(Lead $lead, User $actor): Lead
    {
        $this->assertCanMutateAssignedLead($lead, $actor);

        $lead->update([
            'status' => LeadStatus::Contacted,
            'sla_status' => SlaStatus::Met,
            'first_action_at' => $lead->first_action_at ?? now(),
        ]);

        return $this->slaEscalationBuffer->cancelBuffers($lead->fresh() ?? $lead);
    }

    public function updateStatus(Lead $lead, LeadStatus $status, User $actor): Lead
    {
        $this->assertCanMutateAssignedLead($lead, $actor);

        if ($status === LeadStatus::Contacted) {
            return $this->markContacted($lead, $actor);
        }

        if ($status === LeadStatus::InProgress) {
            return $this->markInProgress($lead, $actor);
        }

        $payload = ['status' => $status];

        if (in_array($status, [LeadStatus::Closed, LeadStatus::Lost], true)
            && $lead->sla_status === SlaStatus::Active
        ) {
            $payload['sla_status'] = SlaStatus::Met;
            $payload['first_action_at'] = $lead->first_action_at ?? now();
        }

        $lead->update($payload);
        $fresh = $lead->fresh() ?? $lead;

        if ($status->stopsSlaBuffers()) {
            return $this->slaEscalationBuffer->cancelBuffers($fresh);
        }

        return $fresh;
    }

    public function addInternalNote(Lead $lead, string $note, User $actor): Lead
    {
        $this->assertCanMutateAssignedLead($lead, $actor);

        $trimmed = trim($note);

        if ($trimmed === '') {
            throw ValidationException::withMessages([
                'note' => 'Note cannot be empty.',
            ]);
        }

        /** @var array<string, mixed> $meta */
        $meta = $lead->meta_data ?? [];
        /** @var list<array{body: string, user_id: int, user_name: string, at: string}> $notes */
        $notes = is_array($meta['internal_notes'] ?? null) ? $meta['internal_notes'] : [];

        $notes[] = [
            'body' => $trimmed,
            'user_id' => $actor->id,
            'user_name' => $actor->name,
            'at' => Carbon::now()->toIso8601String(),
        ];

        $meta['internal_notes'] = $notes;

        $lead->update(['meta_data' => $meta]);

        return $lead->fresh() ?? $lead;
    }

    public function reassign(Lead $lead, User $assignee, User $actor): Lead
    {
        if (! $actor->canViewAllTenantLeads()) {
            throw new AuthorizationException('Only owners and admins can reassign leads.');
        }

        $this->assertSameTenant($lead, $actor);

        if ($assignee->tenant_id !== $actor->tenant_id
            || $assignee->role !== UserRole::SalesRep
            || ! $assignee->is_active
        ) {
            throw ValidationException::withMessages([
                'assigned_user_id' => 'Assignee must be an active sales rep in the same tenant.',
            ]);
        }

        $lead->update([
            'previous_assigned_user_id' => $lead->assigned_user_id,
            'assigned_user_id' => $assignee->id,
            'status' => $lead->status === LeadStatus::New ? LeadStatus::Claimed : $lead->status,
            'claimed_at' => $lead->claimed_at ?? now(),
            'sla_started_at' => $lead->sla_deadline !== null ? now() : $lead->sla_started_at,
            'sla_warning_sent_at' => null,
            'sla_escalated_at' => null,
        ]);

        $fresh = $lead->fresh() ?? $lead;
        SendLeadAssignedNotificationJob::dispatch($fresh->id);

        return $fresh;
    }

    public function assertCanAccessLead(Lead $lead, User $actor): void
    {
        $this->assertSameTenant($lead, $actor);

        if ($actor->canViewAllTenantLeads()) {
            return;
        }

        if ($actor->isSalesRep()
            && ($lead->assigned_user_id === null || $lead->assigned_user_id === $actor->id)
        ) {
            return;
        }

        throw new AuthorizationException('You are not allowed to access this lead.');
    }

    public function assertCanMutateAssignedLead(Lead $lead, User $actor): void
    {
        $this->assertSameTenant($lead, $actor);

        if ($actor->canViewAllTenantLeads()) {
            return;
        }

        if ($actor->isSalesRep() && $lead->assigned_user_id === $actor->id) {
            return;
        }

        throw new AuthorizationException('You can only update leads assigned to you.');
    }

    private function assertSameTenant(Lead $lead, User $actor): void
    {
        if ($actor->tenant_id === null || $lead->tenant_id !== $actor->tenant_id) {
            throw new AuthorizationException('Lead does not belong to your tenant.');
        }
    }
}
