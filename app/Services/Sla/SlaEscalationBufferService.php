<?php

declare(strict_types=1);

namespace App\Services\Sla;

use App\Adapters\Notifications\NotificationDriverFactory;
use App\Enums\LeadStatus;
use App\Enums\SlaStatus;
use App\Events\SlaWarningBroadcastEvent;
use App\Jobs\SendLeadAssignedNotificationJob;
use App\Models\Lead;
use App\Models\TenantSetting;
use App\Models\TenantWorkingHour;
use App\Models\User;
use App\Services\Assignment\ContextAwareRoutingService;
use App\Support\Timezones;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SLA Escalation Buffer:
 * - 50% elapsed without agent action → warn the assigned agent
 * - 80% elapsed without agent action → unassign, warn, reassign to next online agent
 * - Contacted / In Progress (or first_action_at) cancels further buffer actions
 */
class SlaEscalationBufferService
{
    public const HALF_THRESHOLD = 0.50;

    public const ESCALATION_THRESHOLD = 0.80;

    public function __construct(
        private readonly ContextAwareRoutingService $router,
        private readonly SlaCalculatorService $slaCalculator,
        private readonly NotificationDriverFactory $notificationFactory,
    ) {}

    public function isAwaitingAgentAction(Lead $lead): bool
    {
        // Frozen leads must never receive 50%/80% buffers until unfrozen.
        if ($lead->sla_status === SlaStatus::Frozen || $lead->sla_status !== SlaStatus::Active) {
            return false;
        }

        if ($lead->assigned_user_id === null || $lead->sla_deadline === null) {
            return false;
        }

        if ($lead->first_action_at !== null) {
            return false;
        }

        return $lead->status->isAwaitingAgentAction();
    }

    /**
     * Progress through the SLA window: 0.0 → 1.0 (clamped).
     */
    public function progress(Lead $lead, ?Carbon $now = null): float
    {
        $now ??= Carbon::now();
        $startedAt = $this->resolveStartedAt($lead);
        $deadline = $lead->sla_deadline;

        if ($startedAt === null || $deadline === null) {
            return 0.0;
        }

        $totalSeconds = max(1, $deadline->getTimestamp() - $startedAt->getTimestamp());
        $elapsedSeconds = max(0, $now->getTimestamp() - $startedAt->getTimestamp());

        return min(1.0, max(0.0, $elapsedSeconds / $totalSeconds));
    }

    /**
     * Mark the lead as actioned so buffer checks stop immediately.
     */
    public function cancelBuffers(Lead $lead): Lead
    {
        if ($lead->first_action_at !== null) {
            return $lead;
        }

        $lead->update([
            'first_action_at' => now(),
        ]);

        return $lead->fresh() ?? $lead;
    }

    public function processDueLeads(?Carbon $now = null): int
    {
        $now ??= Carbon::now();

        $this->unfreezeDueLeads($now);

        $processed = 0;

        Lead::withoutGlobalScopes()
            ->with('assignedUser')
            ->where('sla_status', SlaStatus::Active)
            ->whereNotNull('assigned_user_id')
            ->whereNotNull('sla_deadline')
            ->whereNull('first_action_at')
            ->whereIn('status', [LeadStatus::New->value, LeadStatus::Claimed->value])
            ->where(function ($query): void {
                $query
                    ->whereNull('sla_warning_sent_at')
                    ->orWhereNull('sla_escalated_at');
            })
            ->orderBy('id')
            ->each(function (Lead $lead) use ($now, &$processed): void {
                if ($this->processLead($lead, $now)) {
                    $processed++;
                }
            });

        return $processed;
    }

    /**
     * Thaw Frozen leads whose scheduled sla_started_at has been reached.
     * Buffers stay paused while Frozen and now < sla_started_at.
     */
    public function unfreezeDueLeads(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $thawed = 0;

        $leads = Lead::withoutGlobalScopes()
            ->where('sla_status', SlaStatus::Frozen)
            ->whereNotNull('sla_started_at')
            ->where('sla_started_at', '<=', $now)
            ->orderBy('id')
            ->get();

        if ($leads->isEmpty()) {
            return 0;
        }

        /** @var list<string> $tenantIds */
        $tenantIds = $leads->pluck('tenant_id')->unique()->values()->all();

        $settingsByTenant = TenantSetting::withoutGlobalScopes()
            ->whereIn('tenant_id', $tenantIds)
            ->get()
            ->keyBy('tenant_id');

        $hoursByTenant = TenantWorkingHour::withoutGlobalScopes()
            ->whereIn('tenant_id', $tenantIds)
            ->get()
            ->groupBy('tenant_id');

        foreach ($leads as $lead) {
            $startedAt = $lead->sla_started_at?->copy() ?? $now->copy();

            $setting = $settingsByTenant->get($lead->tenant_id);
            $slaMinutes = $setting instanceof TenantSetting ? $setting->sla_timeout_minutes : 5;
            $timezone = Timezones::resolve(
                $setting instanceof TenantSetting ? $setting->timezone : null,
            );

            /** @var Collection<int, TenantWorkingHour> $workingHours */
            $workingHours = $hoursByTenant->get($lead->tenant_id, collect());

            $deadline = $lead->sla_deadline;
            if ($deadline === null || $deadline->lte($startedAt)) {
                $deadline = $this->slaCalculator->calculate(
                    $startedAt->copy(),
                    $slaMinutes,
                    $workingHours,
                    $timezone,
                );
            }

            // Resume as Active so 50%/80% buffers begin from sla_started_at.
            $lead->update([
                'sla_status' => SlaStatus::Active,
                'sla_started_at' => $startedAt,
                'sla_deadline' => $deadline,
                'sla_warning_sent_at' => null,
                'sla_escalated_at' => null,
            ]);

            Log::info('SLA unfrozen after business hours resumed', [
                'lead_id' => $lead->id,
                'tenant_id' => $lead->tenant_id,
                'sla_started_at' => $startedAt->toIso8601String(),
                'sla_deadline' => $deadline->toIso8601String(),
            ]);

            $thawed++;
        }

        return $thawed;
    }

    public function processLead(Lead $lead, ?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        if (! $this->isAwaitingAgentAction($lead)) {
            return false;
        }

        $progress = $this->progress($lead, $now);
        $acted = false;

        if ($progress >= self::HALF_THRESHOLD && $lead->sla_warning_sent_at === null) {
            $this->sendHalfwayWarning($lead);
            $acted = true;
        }

        // Re-check after warning — lead may have been actioned concurrently.
        $lead->refresh();

        if (! $this->isAwaitingAgentAction($lead)) {
            return $acted;
        }

        if ($progress >= self::ESCALATION_THRESHOLD && $lead->sla_escalated_at === null) {
            $this->escalateAndReassign($lead, $now);
            $acted = true;
        }

        return $acted;
    }

    private function sendHalfwayWarning(Lead $lead): void
    {
        $setting = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $lead->tenant_id)
            ->first();

        $driver = $this->notificationFactory->resolve(
            $setting !== null ? $setting->notification_driver : 'telegram',
        );

        $sent = $driver->sendSlaHalfwayWarning($lead);

        $lead->update([
            'sla_warning_sent_at' => now(),
        ]);

        $lead->loadMissing('assignedUser');
        SlaWarningBroadcastEvent::dispatch($lead, 50);

        Log::info('SLA 50% warning dispatched', [
            'lead_id' => $lead->id,
            'tenant_id' => $lead->tenant_id,
            'assigned_user_id' => $lead->assigned_user_id,
            'notification_sent' => $sent,
        ]);
    }

    private function escalateAndReassign(Lead $lead, Carbon $now): void
    {
        $previousUserId = $lead->assigned_user_id;
        $previousUser = $lead->assignedUser;

        $setting = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $lead->tenant_id)
            ->first();

        $driverName = $setting !== null ? $setting->notification_driver : 'telegram';
        $driver = $this->notificationFactory->resolve($driverName);

        $lead->loadMissing('assignedUser');

        $nextUser = $this->router->assign($lead, excludeUserId: $previousUserId);
        $nextUserId = $nextUser?->id;

        $slaMinutes = $setting !== null ? $setting->sla_timeout_minutes : 5;
        $timezone = Timezones::resolve(
            $setting !== null ? $setting->timezone : null,
        );
        $workingHours = TenantWorkingHour::withoutGlobalScopes()
            ->where('tenant_id', $lead->tenant_id)
            ->get();

        $newDeadline = $this->slaCalculator->calculate($now->copy(), $slaMinutes, $workingHours, $timezone);

        $didEscalate = false;

        DB::transaction(function () use ($lead, $previousUserId, $nextUserId, $now, $newDeadline, &$didEscalate): void {
            $locked = Lead::withoutGlobalScopes()->whereKey($lead->id)->lockForUpdate()->first();

            if ($locked === null || ! $this->isAwaitingAgentAction($locked) || $locked->sla_escalated_at !== null) {
                return;
            }

            // Spec: unassign first (Unassigned), then push back to routing.
            $locked->update([
                'previous_assigned_user_id' => $previousUserId,
                'assigned_user_id' => null,
                'status' => LeadStatus::New,
                'claimed_at' => null,
                'sla_escalated_at' => $now,
            ]);

            if ($nextUserId !== null) {
                $locked->update([
                    'assigned_user_id' => $nextUserId,
                    'status' => LeadStatus::Claimed,
                    'claimed_at' => $now,
                    'sla_started_at' => $now,
                    'sla_deadline' => $newDeadline,
                    'sla_status' => SlaStatus::Active,
                    'sla_warning_sent_at' => null,
                ]);
            } else {
                // No online/active agent available — leave unassigned for claim pool.
                $locked->update([
                    'sla_status' => SlaStatus::Pending,
                    'sla_deadline' => null,
                    'sla_started_at' => null,
                    'sla_warning_sent_at' => null,
                ]);
            }

            $didEscalate = true;
        });

        if (! $didEscalate) {
            return;
        }

        $fresh = Lead::withoutGlobalScopes()->with(['assignedUser'])->find($lead->id);

        if ($fresh === null) {
            return;
        }

        SlaWarningBroadcastEvent::dispatch($fresh, 80);

        $newAssignee = $fresh->assignedUser;

        $driver->sendSlaReassignmentWarning(
            $fresh,
            $previousUser instanceof User ? $previousUser : null,
            $newAssignee instanceof User ? $newAssignee : null,
        );

        if ($fresh->assigned_user_id !== null) {
            SendLeadAssignedNotificationJob::dispatch($fresh->id);
        }

        Log::warning('SLA 80% auto-reassignment executed', [
            'lead_id' => $fresh->id,
            'tenant_id' => $fresh->tenant_id,
            'previous_user_id' => $previousUserId,
            'new_user_id' => $fresh->assigned_user_id,
        ]);
    }

    private function resolveStartedAt(Lead $lead): ?Carbon
    {
        if ($lead->sla_started_at !== null) {
            return $lead->sla_started_at->copy();
        }

        if ($lead->claimed_at !== null) {
            return $lead->claimed_at->copy();
        }

        return $lead->created_at?->copy();
    }
}
