<?php

declare(strict_types=1);

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\SlaStatus;
use App\Enums\UserRole;
use App\Jobs\EscalateLeadSlaJob;
use App\Jobs\ProcessAiResponseJob;
use App\Jobs\ProcessLeadIngestionJob;
use App\Jobs\ProcessSlaEscalationBuffersJob;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantWorkingHour;
use App\Models\User;
use App\Services\Billing\StripeBillingService;
use App\Services\Leads\LeadWorkflowService;
use App\Services\Sla\SlaCalculatorService;
use App\Services\Webhooks\Drivers\UniversalWebhookDriver;
use App\Services\Webhooks\Drivers\WebsiteFormWebhookDriver;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('configures reliable retries on critical queue jobs', function (): void {
    $ingestion = new ProcessLeadIngestionJob('t', LeadSource::Meta, []);
    $buffers = new ProcessSlaEscalationBuffersJob;
    $ai = new ProcessAiResponseJob('lead-1');

    expect($ingestion->tries)->toBe(3)
        ->and($ingestion->backoff)->toBe([10, 30, 60])
        ->and($ingestion->timeout)->toBe(120)
        ->and($buffers)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($ai->tries)->toBe(3);
});

it('does not breach in-progress leads that already have first_action_at', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();

    $inProgress = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => LeadStatus::InProgress,
        'sla_status' => SlaStatus::Active,
        'sla_deadline' => now()->subMinute(),
        'first_action_at' => now()->subSeconds(30),
    ]);

    $awaiting = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => LeadStatus::Claimed,
        'sla_status' => SlaStatus::Active,
        'sla_deadline' => now()->subMinute(),
        'first_action_at' => null,
    ]);

    $this->artisan('sla:check-breaches')->assertSuccessful();

    expect($inProgress->fresh()->sla_status)->toBe(SlaStatus::Active)
        ->and($awaiting->fresh()->sla_status)->toBe(SlaStatus::Breached);

    Queue::assertPushed(
        EscalateLeadSlaJob::class,
        fn (EscalateLeadSlaJob $job): bool => invade($job)->leadId === $awaiting->id,
    );
    Queue::assertNotPushed(
        EscalateLeadSlaJob::class,
        fn (EscalateLeadSlaJob $job): bool => invade($job)->leadId === $inProgress->id,
    );
});

it('restarts the SLA clock when claiming a pending pool lead with no deadline', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'sla_timeout_minutes' => 10,
        'timezone' => 'UTC',
    ]);
    TenantWorkingHour::create([
        'tenant_id' => $tenant->id,
        'day_of_week' => (int) now()->timezone('UTC')->dayOfWeek,
        'start_time' => '00:00',
        'end_time' => '23:59',
        'off_hours_action' => 'freeze_sla',
    ]);

    $rep = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => true,
    ]);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => null,
        'status' => LeadStatus::New,
        'sla_status' => SlaStatus::Pending,
        'sla_deadline' => null,
        'sla_started_at' => null,
    ]);

    $claimed = app(LeadWorkflowService::class)->claim($lead, $rep);

    expect($claimed->assigned_user_id)->toBe($rep->id)
        ->and($claimed->sla_status)->toBe(SlaStatus::Active)
        ->and($claimed->sla_started_at)->not->toBeNull()
        ->and($claimed->sla_deadline)->not->toBeNull()
        ->and($claimed->sla_deadline?->greaterThan($claimed->sla_started_at))->toBeTrue();
});

it('applies tenant timezone when calculating SLA deadlines', function (): void {
    $workingHours = collect([
        new TenantWorkingHour([
            'day_of_week' => 1, // Monday
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'off_hours_action' => 'freeze_sla',
        ]),
    ]);

    // Monday 06:00 UTC = Monday 09:00 Asia/Riyadh (UTC+3) — start of shift in Riyadh.
    $startUtc = Carbon::parse('2026-08-17 06:00:00', 'UTC');

    $deadline = app(SlaCalculatorService::class)->calculate(
        $startUtc,
        30,
        $workingHours,
        'Asia/Riyadh',
    );

    expect($deadline->equalTo(Carbon::parse('2026-08-17 06:30:00', 'UTC')))->toBeTrue();
});

it('treats empty working hours as always-open in the SLA calculator', function (): void {
    $start = Carbon::parse('2026-08-17 22:00:00', 'UTC');

    $deadline = app(SlaCalculatorService::class)->calculate($start, 15, collect(), 'UTC');

    expect($deadline->equalTo(Carbon::parse('2026-08-17 22:15:00', 'UTC')))->toBeTrue();
});

it('rejects universal and website secrets passed via query string', function (): void {
    $universal = new UniversalWebhookDriver;
    $website = new WebsiteFormWebhookDriver;

    $universalRequest = Request::create('/?secret=tenant-secret', 'POST');
    $websiteRequest = Request::create('/?api_key=tenant-key', 'POST');

    expect($universal->validateSignature($universalRequest, 'tenant-secret'))->toBeFalse()
        ->and($website->validateSignature($websiteRequest, 'tenant-key'))->toBeFalse();
});

it('hides tenant-scoped rows when there is no authenticated user', function (): void {
    $tenant = Tenant::factory()->create();
    Lead::factory()->create(['tenant_id' => $tenant->id]);

    auth()->logout();

    expect(Lead::query()->count())->toBe(0)
        ->and(Lead::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('returns a generic error body for unexpected Stripe payment webhook failures', function (): void {
    $this->mock(StripeBillingService::class, function ($mock): void {
        $mock->shouldReceive('handlePaymentSuccessWebhook')
            ->once()
            ->andThrow(new RuntimeException('internal secret details'));
    });

    $this->postJson('/api/v1/payments/webhook/stripe', [])
        ->assertStatus(500)
        ->assertJson(['error' => 'payment_webhook_failed'])
        ->assertJsonMissing(['error' => 'internal secret details']);
});

it('rejects unknown payment drivers with a client error', function (): void {
    $this->postJson('/api/v1/payments/webhook/not-a-real-driver', [])
        ->assertStatus(400);
});
