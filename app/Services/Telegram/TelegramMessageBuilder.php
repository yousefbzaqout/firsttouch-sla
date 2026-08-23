<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\Lead;
use App\Models\TenantSetting;
use App\Models\User;

class TelegramMessageBuilder
{
    public function leadAssigned(Lead $lead): string
    {
        $assignee = $lead->assignedUser;
        $meta = $lead->meta_data ?? [];
        $setting = $this->settingFor($lead);
        $assigneeName = $assignee instanceof User ? $assignee->name : 'Unassigned';

        $lines = [
            '<b>New Lead Assigned</b>',
            '',
            '<b>Lead:</b> '.$this->e($lead->name),
            '<b>Phone:</b> '.$this->e($lead->phone),
            '<b>Interested in:</b> '.$this->e($this->interestedService($lead)),
            '<b>Assigned to:</b> '.$this->e($assigneeName),
            '<b>SLA remaining:</b> '.$this->e($this->slaRemaining($lead)),
        ];

        $lines = array_merge($lines, $this->aiLines($meta, $setting));

        if (is_array($lead->ai_quick_replies) && $lead->ai_quick_replies !== []) {
            $lines[] = '';
            $lines[] = '<b>Quick replies:</b> use the buttons below for one-click copyable templates.';
        }

        $lines[] = '';
        $lines[] = '<a href="'.$this->e($this->leadUrl($lead)).'">Open lead in FirstTouch</a>';

        return implode("\n", $lines);
    }

    public function quickReplyCopyable(Lead $lead, string $label, string $replyText): string
    {
        return implode("\n", [
            '<b>Quick Reply ready</b>',
            '<b>Template:</b> '.$this->e($label),
            '<b>Lead:</b> '.$this->e($lead->name).' · '.$this->e($lead->phone),
            '',
            'Copy the message below and send it to the customer:',
            '<pre>'.$this->e($replyText).'</pre>',
            '',
            '<a href="'.$this->e($this->leadUrl($lead)).'">Open lead in FirstTouch</a>',
        ]);
    }

    public function slaBreach(Lead $lead, string $message): string
    {
        $meta = $lead->meta_data ?? [];
        $setting = $this->settingFor($lead);
        $assignee = $lead->assignedUser;
        $assigneeName = $assignee instanceof User ? $assignee->name : 'Unassigned';

        $lines = [
            '<b>SLA BREACH — Escalation</b>',
            '',
            '<b>Alert:</b> '.$this->e($assigneeName).' is late contacting '
                .$this->e($lead->name).' and has breached the SLA!',
            '<b>Lead:</b> '.$this->e($lead->name),
            '<b>Phone:</b> '.$this->e($lead->phone),
            '<b>Interested in:</b> '.$this->e($this->interestedService($lead)),
            '<b>Assigned to:</b> '.$this->e($assigneeName),
            '<b>Details:</b> '.$this->e($message),
        ];

        $lines = array_merge($lines, $this->aiLines($meta, $setting));
        $lines[] = '';
        $lines[] = '<a href="'.$this->e($this->reassignUrl($lead)).'">Reassign this lead to another agent</a>';

        return implode("\n", $lines);
    }

    public function systemAlert(Lead $lead, string $message): string
    {
        return implode("\n", [
            '<b>Tenant System Alert</b>',
            '',
            '<b>Lead:</b> '.$this->e($lead->name),
            '<b>Phone:</b> '.$this->e($lead->phone),
            '<b>Message:</b> '.$this->e($message),
            '',
            '<a href="'.$this->e($this->leadUrl($lead)).'">Open in FirstTouch</a>',
        ]);
    }

    public function accountStatus(string $message): string
    {
        return implode("\n", [
            '<b>Account Status Update</b>',
            '',
            $this->e($message),
            '',
            '<a href="'.$this->e(rtrim((string) config('app.url'), '/').'/admin/credit-top-up').'">Manage credits</a>',
        ]);
    }

    public function slaHalfwayWarning(Lead $lead): string
    {
        $lines = [
            '<b>SLA Warning — 50% elapsed</b>',
            '',
            'You still have not actioned this lead. Please contact the prospect before the SLA is breached.',
            '',
            '<b>Lead:</b> '.$this->e($lead->name),
            '<b>Phone:</b> '.$this->e($lead->phone),
            '<b>Interested in:</b> '.$this->e($this->interestedService($lead)),
            '<b>SLA remaining:</b> '.$this->e($this->slaRemaining($lead)),
            '',
            '<a href="'.$this->e($this->leadUrl($lead)).'">Open lead now</a>',
        ];

        return implode("\n", $lines);
    }

    public function slaReassignmentWarning(
        Lead $lead,
        ?User $previousAgent,
        ?User $newAgent,
    ): string {
        $previousName = $previousAgent !== null ? $previousAgent->name : 'Previous agent';
        $newName = $newAgent !== null ? $newAgent->name : 'Unassigned pool';

        $lines = [
            '<b>SLA Breach Warning — 80% auto-reassignment</b>',
            '',
            'Lead was still pending after 80% of the SLA window and has been pulled from '
                .$this->e($previousName).'.',
            '',
            '<b>Lead:</b> '.$this->e($lead->name),
            '<b>Phone:</b> '.$this->e($lead->phone),
            '<b>Interested in:</b> '.$this->e($this->interestedService($lead)),
            '<b>Reassigned to:</b> '.$this->e($newName),
            '<b>New SLA remaining:</b> '.$this->e($this->slaRemaining($lead)),
            '',
            '<a href="'.$this->e($this->leadUrl($lead)).'">Open lead in FirstTouch</a>',
        ];

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return list<string>
     */
    private function aiLines(array $meta, ?TenantSetting $setting): array
    {
        $assisted = $setting !== null && $setting->ai_routing_mode->runsAi();

        if (! $assisted) {
            return [];
        }

        $summary = is_string($meta['ai_qualification_summary'] ?? null)
            ? $meta['ai_qualification_summary']
            : 'Pending / unavailable';
        $reply = is_string($meta['ai_suggested_reply'] ?? null)
            ? $meta['ai_suggested_reply']
            : null;
        $confidence = $meta['ai_confidence_score'] ?? null;

        $lines = [
            '',
            '<b>AI Qualification:</b> '.$this->e($summary),
        ];

        if (is_numeric($confidence)) {
            $lines[] = '<b>AI Confidence:</b> '.$this->e((string) $confidence);
        }

        if (is_string($reply) && trim($reply) !== '') {
            $lines[] = '<b>AI Suggested Reply:</b>';
            $lines[] = $this->e($reply);
        }

        return $lines;
    }

    private function interestedService(Lead $lead): string
    {
        $meta = $lead->meta_data ?? [];

        if (is_string($meta['interested_service'] ?? null) && trim($meta['interested_service']) !== '') {
            return trim($meta['interested_service']);
        }

        $campaign = $this->metaString($meta, 'campaign_id')
            ?? $this->nestedMetaString($meta, ['entry', 0, 'changes', 0, 'value', 'leadgen_data', 'campaign_id'])
            ?? $this->nestedMetaString($meta, ['data', 'campaign_id']);

        $form = $this->metaString($meta, 'form_id')
            ?? $this->nestedMetaString($meta, ['entry', 0, 'changes', 0, 'value', 'leadgen_data', 'form_id'])
            ?? $this->nestedMetaString($meta, ['data', 'form_id']);

        $parts = [];

        if ($campaign !== null) {
            $parts[] = 'Campaign '.$campaign;
        }

        if ($form !== null) {
            $parts[] = 'Form '.$form;
        }

        return $parts !== [] ? implode(' · ', $parts) : 'Not specified';
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function metaString(array $meta, string $key): ?string
    {
        $value = $meta[$key] ?? null;

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<int|string>  $path
     */
    private function nestedMetaString(array $meta, array $path): ?string
    {
        $cursor = $meta;

        foreach ($path as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        if (! is_string($cursor) && ! is_numeric($cursor)) {
            return null;
        }

        $trimmed = trim((string) $cursor);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function slaRemaining(Lead $lead): string
    {
        if ($lead->sla_deadline === null) {
            return 'N/A';
        }

        if ($lead->sla_deadline->isPast()) {
            return 'Overdue';
        }

        return $lead->sla_deadline->diffForHumans(now(), [
            'parts' => 2,
            'short' => true,
        ]).' remaining';
    }

    private function leadUrl(Lead $lead): string
    {
        return rtrim((string) config('app.url'), '/').'/admin/leads/'.$lead->id;
    }

    private function reassignUrl(Lead $lead): string
    {
        return $this->leadUrl($lead).'?reassign=1';
    }

    private function settingFor(Lead $lead): ?TenantSetting
    {
        return TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $lead->tenant_id)
            ->first();
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
