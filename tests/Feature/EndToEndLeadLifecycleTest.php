<?php

declare(strict_types=1);

use App\Adapters\Notifications\Contracts\NotificationDriverInterface;
use App\Adapters\Notifications\NotificationDriverFactory;
use App\DTOs\LeadData;
use App\Enums\KnowledgeType;
use App\Enums\LeadSource;
use App\Enums\UserRole;
use App\Jobs\EscalateLeadSlaJob;
use App\Jobs\ProcessLeadIngestionJob;
use App\Models\KnowledgeBase;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantWorkingHour;
use App\Models\User;
use App\Pipelines\LeadProcessingPipeline;
use App\Services\Ai\Contracts\EmbeddingServiceInterface;
use App\Services\Ai\Contracts\LlmProviderInterface;
use App\Services\Ai\RagInferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('validates the full lead lifecycle end to end', function (): void {
    Cache::flush();
    Queue::fake();
    $externalLeadId = 'meta-lead-'.Str::lower(Str::random(8));
    $pipelineLeadId = $externalLeadId.'-pipeline';

    $tenantA = Tenant::factory()->create(['name' => 'Tenant A']);
    $tenantB = Tenant::factory()->create(['name' => 'Tenant B']);

    TenantSetting::factory()->create([
        'tenant_id' => $tenantA->id,
        'credits_balance' => 5,
        'ai_confidence_threshold' => 80.00,
        'notification_driver' => 'log',
        'timezone' => 'UTC',
    ]);

    TenantSetting::factory()->create([
        'tenant_id' => $tenantB->id,
        'credits_balance' => 3,
        'timezone' => 'UTC',
    ]);

    TenantWorkingHour::create([
        'tenant_id' => $tenantA->id,
        'day_of_week' => (int) now()->timezone('UTC')->dayOfWeek,
        'start_time' => '00:00',
        'end_time' => '23:59',
        'off_hours_action' => 'freeze_sla',
    ]);

    TenantWorkingHour::create([
        'tenant_id' => $tenantB->id,
        'day_of_week' => (int) now()->timezone('UTC')->dayOfWeek,
        'start_time' => '00:00',
        'end_time' => '23:59',
        'off_hours_action' => 'freeze_sla',
    ]);

    $salesRepA = User::factory()->create([
        'tenant_id' => $tenantA->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => true,
    ]);

    $userB = User::factory()->create([
        'tenant_id' => $tenantB->id,
        'role' => UserRole::Owner,
        'is_active' => true,
    ]);

    $webhookPayload = [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'leadgen_data' => [
                        'leadgen_id' => $externalLeadId,
                        'full_name' => 'Alice Prospect',
                        'phone_number' => '+15550000001',
                        'email' => 'alice@example.com',
                        'campaign_id' => 'cmp-001',
                        'form_id' => 'frm-001',
                    ],
                ],
            ]],
        ]],
    ];

    $payloadJson = json_encode($webhookPayload, JSON_THROW_ON_ERROR);
    config(['services.webhooks.meta_secret' => 'meta-secret']);
    $signature = 'sha256='.hash_hmac('sha256', $payloadJson, 'meta-secret');

    $response = $this->withHeaders([
        'X-Hub-Signature-256' => $signature,
        'Content-Type' => 'application/json',
    ])->postJson("/api/v1/webhooks/meta/{$tenantA->id}", $webhookPayload);

    $response->assertOk()->assertJson(['status' => 'accepted']);
    Queue::assertPushed(ProcessLeadIngestionJob::class);
    Cache::flush();

    $lead = app(LeadProcessingPipeline::class)->process(
        new LeadData(
            tenantId: $tenantA->id,
            source: LeadSource::Meta,
            externalLeadId: $pipelineLeadId,
            name: 'Alice Prospect',
            phone: '+15550000001',
            email: 'alice@example.com',
            campaignId: 'cmp-001',
            formId: 'frm-001',
            rawPayload: $webhookPayload,
        ),
    );

    expect($lead)->not->toBeNull()
        ->and($lead?->assigned_user_id)->toBe($salesRepA->id)
        ->and($lead?->sla_deadline)->not->toBeNull();

    $knowledgeBaseA = KnowledgeBase::withoutGlobalScopes()->create([
        'tenant_id' => $tenantA->id,
        'title' => 'Business Hours',
        'type' => KnowledgeType::QaPair,
        'is_active' => true,
    ]);

    $knowledgeBaseB = KnowledgeBase::withoutGlobalScopes()->create([
        'tenant_id' => $tenantB->id,
        'title' => 'Other Tenant KB',
        'type' => KnowledgeType::Document,
        'is_active' => true,
    ]);

    $vector = array_fill(0, 1536, 1.0 / sqrt(1536));
    $vectorString = '['.implode(',', $vector).']';

    DB::statement(
        'INSERT INTO knowledge_chunks (id, tenant_id, knowledge_base_id, content, priority, embedding, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?::vector, NOW(), NOW())',
        [(string) Str::uuid(), $tenantA->id, $knowledgeBaseA->id, 'Our business hours are 9am to 5pm.', 'high', $vectorString],
    );

    DB::statement(
        'INSERT INTO knowledge_chunks (id, tenant_id, knowledge_base_id, content, priority, embedding, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?::vector, NOW(), NOW())',
        [(string) Str::uuid(), $tenantB->id, $knowledgeBaseB->id, 'Tenant B private answer.', 'high', $vectorString],
    );

    $this->app->bind(EmbeddingServiceInterface::class, function () use ($vector) {
        return new class($vector) implements EmbeddingServiceInterface
        {
            /** @param list<float> $embedding */
            public function __construct(private readonly array $embedding) {}

            public function generateEmbedding(string $text): array
            {
                return $this->embedding;
            }
        };
    });

    $this->app->bind(LlmProviderInterface::class, function () {
        return new class implements LlmProviderInterface
        {
            public function generateResponse(string $systemPrompt, string $userQuery, string $model): string
            {
                return 'Our business hours are 9am to 5pm.';
            }
        };
    });

    $ragResponse = app(RagInferenceService::class)->processLeadQuery($lead, 'What are your business hours?');

    expect($ragResponse->success)->toBeTrue()
        ->and($ragResponse->answer)->toBe('Our business hours are 9am to 5pm.')
        ->and($ragResponse->fallbackToHuman)->toBeFalse();

    expect(
        TenantSetting::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->first()?->credits_balance,
    )->toBe(4);

    $this->actingAs($userB);

    expect(Lead::all())->toHaveCount(0);
    expect(KnowledgeBase::all())->toHaveCount(1)
        ->and(KnowledgeBase::all()->first()?->tenant_id)->toBe($tenantB->id);

    $captured = [];
    $fakeDriver = new class($captured) implements NotificationDriverInterface
    {
        /** @var array<string, mixed> */
        public array $captured;

        /** @param array<string, mixed> $captured */
        public function __construct(array &$captured)
        {
            $this->captured = &$captured;
        }

        public function sendLeadAssignedAlert(Lead $lead): bool
        {
            $this->captured = ['type' => 'assigned', 'lead_id' => $lead->id];

            return true;
        }

        public function sendSlaAlert(Lead $lead, string $message): bool
        {
            $this->captured = ['type' => 'sla', 'lead_id' => $lead->id, 'message' => $message];

            return true;
        }

        public function sendEscalationAlert(Lead $lead, string $message): bool
        {
            $this->captured = ['type' => 'escalation', 'lead_id' => $lead->id, 'message' => $message];

            return true;
        }

        public function sendAccountStatusAlert(string $tenantId, string $message): bool
        {
            $this->captured = ['type' => 'account_status', 'tenant_id' => $tenantId, 'message' => $message];

            return true;
        }

        public function sendSlaHalfwayWarning(Lead $lead): bool
        {
            $this->captured = ['type' => 'sla_halfway', 'lead_id' => $lead->id];

            return true;
        }

        public function sendSlaReassignmentWarning(Lead $lead, ?User $previousAgent, ?User $newAgent): bool
        {
            $this->captured = [
                'type' => 'sla_reassignment',
                'lead_id' => $lead->id,
                'previous_user_id' => $previousAgent?->id,
                'new_user_id' => $newAgent?->id,
            ];

            return true;
        }
    };

    $factory = new class($fakeDriver) extends NotificationDriverFactory
    {
        public function __construct(private readonly NotificationDriverInterface $driver)
        {
            // Skip parent DI — this anonymous factory only returns the fake driver.
        }

        public function resolve(string $driver): NotificationDriverInterface
        {
            return $this->driver;
        }
    };

    $this->travelTo($lead->sla_deadline?->copy()->addMinute());
    (new EscalateLeadSlaJob($lead->id))->handle($factory);

    expect($captured['type'] ?? null)->toBe('escalation')
        ->and($captured['lead_id'] ?? null)->toBe($lead->id)
        ->and($captured['message'] ?? null)->toContain('breached the SLA');
});
