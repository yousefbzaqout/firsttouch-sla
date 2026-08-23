<?php

declare(strict_types=1);

use App\Models\KnowledgeBase;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\Ai\Contracts\EmbeddingServiceInterface;
use App\Services\Ai\Contracts\LlmProviderInterface;
use App\Services\Ai\RagInferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createVectorContext(string $tenantId, string $content, string $priority = 'normal'): void
{
    $kb = KnowledgeBase::withoutGlobalScopes()->create([
        'tenant_id' => $tenantId,
        'title' => 'Test KB',
        'type' => 'qa_pair',
        'is_active' => true,
    ]);

    $vector = array_fill(0, 1536, 1.0 / sqrt(1536));
    $vectorString = '['.implode(',', $vector).']';

    DB::statement(
        'INSERT INTO knowledge_chunks (id, tenant_id, knowledge_base_id, content, priority, embedding, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?::vector, NOW(), NOW())',
        [Str::uuid()->toString(), $tenantId, $kb->id, $content, $priority, $vectorString],
    );
}

it('returns successful AI response and deducts credit for high confidence query', function (): void {
    $tenant = Tenant::factory()->create();
    $setting = TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'credits_balance' => 5,
        'ai_confidence_threshold' => 50.00,
    ]);

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    createVectorContext($tenant->id, 'Our business hours are 9am to 5pm', 'high');

    $mockEmbedding = array_fill(0, 1536, 1.0 / sqrt(1536));

    $this->app->bind(EmbeddingServiceInterface::class, function () use ($mockEmbedding) {
        $mock = Mockery::mock(EmbeddingServiceInterface::class);
        $mock->shouldReceive('generateEmbedding')->andReturn($mockEmbedding);

        return $mock;
    });

    $this->app->bind(LlmProviderInterface::class, function () {
        $mock = Mockery::mock(LlmProviderInterface::class);
        $mock->shouldReceive('generateResponse')->andReturn('Our business hours are 9am to 5pm.');

        return $mock;
    });

    $service = app(RagInferenceService::class);
    $response = $service->processLeadQuery($lead, 'What are your business hours?');

    expect($response->success)->toBeTrue()
        ->and($response->answer)->toBe('Our business hours are 9am to 5pm.')
        ->and($response->fallbackToHuman)->toBeFalse()
        ->and($setting->fresh()->credits_balance)->toBe(4);
});

it('falls back to human when confidence is below threshold and does not deduct credits', function (): void {
    $tenant = Tenant::factory()->create();
    $setting = TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'credits_balance' => 5,
        'ai_confidence_threshold' => 99.99,
    ]);

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    createVectorContext($tenant->id, 'Unrelated content about weather');

    // Return an orthogonal vector so similarity is near 0
    $queryEmbedding = array_fill(0, 1536, 0.0);
    $queryEmbedding[0] = 1.0;

    $this->app->bind(EmbeddingServiceInterface::class, function () use ($queryEmbedding) {
        $mock = Mockery::mock(EmbeddingServiceInterface::class);
        $mock->shouldReceive('generateEmbedding')->andReturn($queryEmbedding);

        return $mock;
    });

    $service = app(RagInferenceService::class);
    $response = $service->processLeadQuery($lead, 'Something completely different');

    expect($response->success)->toBeFalse()
        ->and($response->fallbackToHuman)->toBeTrue()
        ->and($response->answer)->toBeNull()
        ->and($setting->fresh()->credits_balance)->toBe(5);
});

it('falls back to human when credits are exhausted', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'credits_balance' => 0,
    ]);

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $service = app(RagInferenceService::class);
    $response = $service->processLeadQuery($lead, 'Any query');

    expect($response->success)->toBeFalse()
        ->and($response->fallbackToHuman)->toBeTrue();
});

it('parses structured qualification JSON from the LLM', function (): void {
    $tenant = Tenant::factory()->create();
    $setting = TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'credits_balance' => 5,
        'ai_confidence_threshold' => 50.00,
    ]);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'JSON Lead',
        'meta_data' => ['campaign_id' => 'Social Ads Retainer', 'budget' => '3000'],
    ]);

    createVectorContext($tenant->id, 'We offer Social Ads Retainers for SMBs', 'high');

    $mockEmbedding = array_fill(0, 1536, 1.0 / sqrt(1536));

    $this->app->bind(EmbeddingServiceInterface::class, function () use ($mockEmbedding) {
        $mock = Mockery::mock(EmbeddingServiceInterface::class);
        $mock->shouldReceive('generateEmbedding')->andReturn($mockEmbedding);

        return $mock;
    });

    $this->app->bind(LlmProviderInterface::class, function () {
        $mock = Mockery::mock(LlmProviderInterface::class);
        $mock->shouldReceive('generateResponse')->andReturn(json_encode([
            'qualification_score' => 0.88,
            'qualification_summary' => 'Budget-ready SMB for retainers.',
            'is_qualified' => true,
            'suggested_reply' => 'Happy to walk you through our retainer packages.',
            'quick_replies' => [
                ['label' => 'Reply 1: Book Call', 'text' => 'Shall we book a short intro call?'],
                ['label' => 'Reply 2: Send Price', 'text' => 'Happy to share retainer pricing options.'],
            ],
        ], JSON_THROW_ON_ERROR));

        return $mock;
    });

    $service = app(RagInferenceService::class);
    $response = $service->processLeadQuery($lead);

    expect($response->success)->toBeTrue()
        ->and($response->isQualified)->toBeTrue()
        ->and($response->confidenceScore)->toBe(0.88)
        ->and($response->qualificationSummary)->toBe('Budget-ready SMB for retainers.')
        ->and($response->answer)->toContain('retainer packages')
        ->and($response->quickReplies)->toHaveCount(2)
        ->and($setting->fresh()->credits_balance)->toBe(4);
});
