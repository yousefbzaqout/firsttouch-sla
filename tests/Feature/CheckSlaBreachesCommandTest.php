<?php

declare(strict_types=1);

use App\Enums\LeadStatus;
use App\Enums\SlaStatus;
use App\Jobs\EscalateLeadSlaJob;
use App\Models\Lead;
use App\Models\Tenant;
use Illuminate\Support\Facades\Queue;

it('dispatches escalation jobs for active leads past their sla deadline', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();

    $breachedLead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => LeadStatus::Claimed,
        'sla_status' => SlaStatus::Active,
        'sla_deadline' => now()->subMinute(),
        'first_action_at' => null,
    ]);

    $activeLead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => LeadStatus::Claimed,
        'sla_status' => SlaStatus::Active,
        'sla_deadline' => now()->addHour(),
        'first_action_at' => null,
    ]);

    $this->artisan('sla:check-breaches')->assertSuccessful();

    Queue::assertPushed(
        EscalateLeadSlaJob::class,
        fn (EscalateLeadSlaJob $job): bool => invade($job)->leadId === $breachedLead->id,
    );
    Queue::assertNotPushed(
        EscalateLeadSlaJob::class,
        fn (EscalateLeadSlaJob $job): bool => invade($job)->leadId === $activeLead->id,
    );

    expect($breachedLead->fresh()->sla_status)->toBe(SlaStatus::Breached)
        ->and($activeLead->fresh()->sla_status)->toBe(SlaStatus::Active);
});
