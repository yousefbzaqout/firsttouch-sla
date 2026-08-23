<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Adapters\Notifications\NotificationDriverFactory;
use App\Enums\AiRoutingMode;
use App\Jobs\Concerns\ConfiguresReliableQueueJob;
use App\Models\Lead;
use App\Models\TenantSetting;
use App\Services\Ai\RagInferenceService;
use App\Services\Leads\LeadSalesActivationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessAiResponseJob implements ShouldQueue
{
    use ConfiguresReliableQueueJob;
    use Queueable;

    public function __construct(
        private readonly string $leadId,
        private readonly ?string $userQuery = null,
    ) {
        $this->onQueue('high');
    }

    public function handle(
        RagInferenceService $inferenceService,
        LeadSalesActivationService $activationService,
        NotificationDriverFactory $notificationFactory,
    ): void {
        $lead = Lead::withoutGlobalScopes()->find($this->leadId);

        if (! $lead) {
            return;
        }

        $setting = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $lead->tenant_id)
            ->first();

        $mode = $setting !== null ? $setting->ai_routing_mode : AiRoutingMode::AiAssisted;

        try {
            $result = $inferenceService->processLeadQuery($lead, $this->userQuery);

            /** @var array<string, mixed> $meta */
            $meta = $lead->meta_data ?? [];
            $meta['ai_qualification_summary'] = $result->qualificationSummary
                ?? ($result->fallbackToHuman
                    ? 'AI could not confidently qualify this lead. Human follow-up recommended.'
                    : 'AI qualification completed successfully.');
            $meta['ai_suggested_reply'] = $result->answer;
            $meta['ai_confidence_score'] = $result->confidenceScore;
            $meta['ai_rag_similarity'] = $result->ragSimilarity;
            $meta['ai_is_qualified'] = $result->isQualified;
            $meta['ai_fallback_to_human'] = $result->fallbackToHuman;
            $meta['ai_processed_at'] = now()->toIso8601String();
            $meta['ai_routing_mode'] = $mode->value;

            $quickReplies = array_map(
                static fn ($reply): array => $reply->toArray(),
                $result->quickReplies,
            );

            $lead->update([
                'meta_data' => $meta,
                'ai_quick_replies' => $quickReplies !== [] ? $quickReplies : null,
                'routing_tags' => $result->routingTags !== [] ? $result->routingTags : null,
            ]);
            $lead = $lead->fresh() ?? $lead;
        } catch (Throwable $exception) {
            Log::warning('ProcessAiResponseJob failed', [
                'lead_id' => $this->leadId,
                'error' => $exception->getMessage(),
            ]);

            if ($mode === AiRoutingMode::AiAssisted && $lead->assigned_user_id !== null) {
                SendLeadAssignedNotificationJob::dispatch($lead->id);
            }

            throw $exception;
        }

        if ($mode === AiRoutingMode::AiFirst) {
            if ($result->isQualified && ! $result->fallbackToHuman) {
                $activationService->activate($lead, dispatchAssignmentNotification: true);

                return;
            }

            $this->notifyManagersUnqualified(
                $lead,
                $notificationFactory,
                $setting !== null ? $setting->notification_driver : 'telegram',
            );

            return;
        }

        // AI Assistance: already assigned + SLA active; notify with AI payload ready.
        if ($lead->assigned_user_id !== null) {
            SendLeadAssignedNotificationJob::dispatch($lead->id);
        }
    }

    private function notifyManagersUnqualified(
        Lead $lead,
        NotificationDriverFactory $factory,
        string $driverName,
    ): void {
        $summary = is_string($lead->meta_data['ai_qualification_summary'] ?? null)
            ? $lead->meta_data['ai_qualification_summary']
            : 'Not auto-qualified';

        $factory->resolve($driverName)->sendAccountStatusAlert(
            $lead->tenant_id,
            "AI First: lead {$lead->name} ({$lead->phone}) was not auto-qualified. {$summary} Review and assign manually in FirstTouch.",
        );
    }
}
