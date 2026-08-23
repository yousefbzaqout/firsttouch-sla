<?php

declare(strict_types=1);

use App\Adapters\Notifications\NotificationDriverFactory;
use App\DTOs\LeadData;
use App\Enums\AiRoutingMode;
use App\Enums\LeadSource;
use App\Enums\SlaStatus;
use App\Enums\UserRole;
use App\Jobs\ProcessAiResponseJob;
use App\Models\KnowledgeBase;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantWorkingHour;
use App\Models\User;
use App\Pipelines\LeadProcessingPipeline;
use App\Services\Ai\Contracts\EmbeddingServiceInterface;
use App\Services\Ai\Contracts\LlmProviderInterface;
use App\Services\Ai\RagInferenceService;
use App\Services\Leads\LeadSalesActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @param  list<float>  $vector
 */
function seedKnowledge(string $tenantId, array $vector, string $content = 'We sell Meta and TikTok lead-gen retainers for SMBs.'): void
{
    $kb = KnowledgeBase::withoutGlobalScopes()->create([
        'tenant_id' => $tenantId,
        'title' => 'Services',
        'type' => 'qa_pair',
        'is_active' => true,
    ]);

    $vectorString = '['.implode(',', $vector).']';

    DB::statement(
        'INSERT INTO knowledge_chunks (id, tenant_id, knowledge_base_id, content, priority, embedding, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?::vector, NOW(), NOW())',
        [Str::uuid()->toString(), $tenantId, $kb->id, $content, 'high', $vectorString],
    );
}

function bindSuccessfulAiMocks(array $vector, bool $qualified = true): void
{
    app()->bind(EmbeddingServiceInterface::class, function () use ($vector) {
        $mock = Mockery::mock(EmbeddingServiceInterface::class);
        $mock->shouldReceive('generateEmbedding')->andReturn($vector);

        return $mock;
    });

    app()->bind(LlmProviderInterface::class, function () use ($qualified) {
        $mock = Mockery::mock(LlmProviderInterface::class);
        $mock->shouldReceive('generateResponse')->andReturn(json_encode([
            'qualification_score' => $qualified ? 0.91 : 0.2,
            'qualification_summary' => $qualified
                ? 'SMB ready for paid social retainer.'
                : 'Outside ICP — enterprise only inquiry.',
            'is_qualified' => $qualified,
            'suggested_reply' => $qualified
                ? 'Thanks for your interest in our Meta/TikTok retainers!'
                : '',
        ], JSON_THROW_ON_ERROR));

        return $mock;
    });
}

it('human_first assigns immediately and skips AI processing', function (): void {
    Queue::fake([ProcessAiResponseJob::class]);

    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'ai_routing_mode' => AiRoutingMode::HumanFirst,
        'sla_timeout_minutes' => 10,
        'credits_balance' => 20,
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

    $lead = app(LeadProcessingPipeline::class)->process(new LeadData(
        tenantId: $tenant->id,
        source: LeadSource::Meta,
        externalLeadId: 'hf-'.Str::uuid(),
        name: 'Human First Lead',
        phone: '+966500000100',
        email: 'hf@example.test',
        campaignId: 'cmp-hf',
        formId: 'frm-hf',
        rawPayload: [],
    ));

    expect($lead)->not->toBeNull()
        ->and($lead?->assigned_user_id)->toBe($rep->id)
        ->and($lead?->sla_status)->toBe(SlaStatus::Active)
        ->and($lead?->sla_deadline)->not->toBeNull();

    Queue::assertNotPushed(ProcessAiResponseJob::class);
});

it('ai_assisted assigns immediately then stores qualification + suggested reply', function (): void {
    Cache::flush();
    Queue::fake([ProcessAiResponseJob::class]);

    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'ai_routing_mode' => AiRoutingMode::AiAssisted,
        'ai_confidence_threshold' => 50.00,
        'credits_balance' => 10,
        'notification_driver' => 'log',
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

    $vector = array_fill(0, 1536, 1.0 / sqrt(1536));
    seedKnowledge($tenant->id, $vector);
    bindSuccessfulAiMocks($vector, true);

    $lead = app(LeadProcessingPipeline::class)->process(new LeadData(
        tenantId: $tenant->id,
        source: LeadSource::TikTok,
        externalLeadId: 'aa-'.Str::uuid(),
        name: 'Assisted Lead',
        phone: '+966500000200',
        email: 'aa@example.test',
        campaignId: 'TikTok Lead Gen Package',
        formId: 'frm-aa',
        rawPayload: [],
    ));

    expect($lead)->not->toBeNull()
        ->and($lead?->assigned_user_id)->toBe($rep->id)
        ->and($lead?->sla_status)->toBe(SlaStatus::Active);

    Queue::assertPushed(ProcessAiResponseJob::class);

    (new ProcessAiResponseJob((string) $lead?->id))
        ->handle(
            app(RagInferenceService::class),
            app(LeadSalesActivationService::class),
            app(NotificationDriverFactory::class),
        );

    $lead?->refresh();

    expect($lead?->meta_data['ai_qualification_summary'] ?? null)->toContain('SMB')
        ->and($lead?->meta_data['ai_suggested_reply'] ?? null)->toContain('retainers')
        ->and($lead?->meta_data['ai_is_qualified'] ?? false)->toBeTrue()
        ->and($lead?->assigned_user_id)->toBe($rep->id);
});

it('ai_first waits for qualification before assignment and SLA', function (): void {
    Cache::flush();
    Queue::fake([ProcessAiResponseJob::class]);
    Http::fake();

    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'ai_routing_mode' => AiRoutingMode::AiFirst,
        'ai_confidence_threshold' => 50.00,
        'credits_balance' => 10,
        'notification_driver' => 'log',
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

    $vector = array_fill(0, 1536, 1.0 / sqrt(1536));
    seedKnowledge($tenant->id, $vector);
    bindSuccessfulAiMocks($vector, true);

    $lead = app(LeadProcessingPipeline::class)->process(new LeadData(
        tenantId: $tenant->id,
        source: LeadSource::Meta,
        externalLeadId: 'af-'.Str::uuid(),
        name: 'AI First Lead',
        phone: '+966500000300',
        email: 'af@example.test',
        campaignId: 'cmp-af',
        formId: 'frm-af',
        rawPayload: ['budget' => '5000'],
    ));

    expect($lead)->not->toBeNull()
        ->and($lead?->assigned_user_id)->toBeNull()
        ->and($lead?->sla_deadline)->toBeNull()
        ->and($lead?->sla_status)->toBe(SlaStatus::Pending);

    Queue::assertPushed(ProcessAiResponseJob::class);

    (new ProcessAiResponseJob((string) $lead?->id))
        ->handle(
            app(RagInferenceService::class),
            app(LeadSalesActivationService::class),
            app(NotificationDriverFactory::class),
        );

    $lead?->refresh();

    expect($lead?->assigned_user_id)->toBe($rep->id)
        ->and($lead?->sla_status)->toBe(SlaStatus::Active)
        ->and($lead?->sla_deadline)->not->toBeNull()
        ->and($lead?->meta_data['ai_is_qualified'] ?? false)->toBeTrue()
        ->and($lead?->meta_data['interested_service'] ?? null)->toContain('cmp-af');
});

it('ai_first leaves unqualified leads unassigned without starting SLA', function (): void {
    Cache::flush();
    Queue::fake([ProcessAiResponseJob::class]);

    $tenant = Tenant::factory()->create(['name' => 'Apex']);
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'ai_routing_mode' => AiRoutingMode::AiFirst,
        'ai_confidence_threshold' => 50.00,
        'credits_balance' => 10,
        'notification_driver' => 'log',
        'timezone' => 'UTC',
    ]);
    TenantWorkingHour::create([
        'tenant_id' => $tenant->id,
        'day_of_week' => (int) now()->timezone('UTC')->dayOfWeek,
        'start_time' => '00:00',
        'end_time' => '23:59',
        'off_hours_action' => 'freeze_sla',
    ]);
    User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => true,
    ]);

    $vector = array_fill(0, 1536, 1.0 / sqrt(1536));
    seedKnowledge($tenant->id, $vector);
    bindSuccessfulAiMocks($vector, qualified: false);

    $lead = app(LeadProcessingPipeline::class)->process(new LeadData(
        tenantId: $tenant->id,
        source: LeadSource::Meta,
        externalLeadId: 'uf-'.Str::uuid(),
        name: 'Unqualified Lead',
        phone: '+966500000400',
        email: 'uf@example.test',
        campaignId: null,
        formId: null,
        rawPayload: [],
    ));

    (new ProcessAiResponseJob((string) $lead?->id))
        ->handle(
            app(RagInferenceService::class),
            app(LeadSalesActivationService::class),
            app(NotificationDriverFactory::class),
        );

    $lead?->refresh();

    expect($lead?->assigned_user_id)->toBeNull()
        ->and($lead?->sla_deadline)->toBeNull()
        ->and($lead?->sla_status)->toBe(SlaStatus::Pending)
        ->and($lead?->meta_data['ai_is_qualified'] ?? true)->toBeFalse()
        ->and($lead?->meta_data['ai_qualification_summary'] ?? null)->toContain('Outside ICP');
});
