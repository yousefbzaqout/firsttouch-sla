<?php

declare(strict_types=1);

use App\Models\KnowledgeBase;
use App\Models\Tenant;
use App\Services\Ai\VectorSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->service = new VectorSearchService;
});

it('returns similar chunks scoped to tenant', function (): void {
    $tenant1 = Tenant::factory()->create();
    $tenant2 = Tenant::factory()->create();

    $kb1 = KnowledgeBase::withoutGlobalScopes()->create([
        'tenant_id' => $tenant1->id,
        'title' => 'KB1',
        'type' => 'document',
        'is_active' => true,
    ]);

    $kb2 = KnowledgeBase::withoutGlobalScopes()->create([
        'tenant_id' => $tenant2->id,
        'title' => 'KB2',
        'type' => 'document',
        'is_active' => true,
    ]);

    // Create a normalized vector (all same value = 1/sqrt(1536))
    $baseVector = array_fill(0, 1536, 1.0 / sqrt(1536));
    $vectorString = '['.implode(',', $baseVector).']';

    // Insert chunk for tenant1
    DB::statement(
        'INSERT INTO knowledge_chunks (id, tenant_id, knowledge_base_id, content, priority, embedding, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?::vector, NOW(), NOW())',
        [Str::uuid()->toString(), $tenant1->id, $kb1->id, 'Tenant 1 content', 'high', $vectorString],
    );

    // Insert chunk for tenant2
    DB::statement(
        'INSERT INTO knowledge_chunks (id, tenant_id, knowledge_base_id, content, priority, embedding, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?::vector, NOW(), NOW())',
        [Str::uuid()->toString(), $tenant2->id, $kb2->id, 'Tenant 2 content', 'normal', $vectorString],
    );

    // Search as tenant1 - should only see tenant1's chunk
    $results = $this->service->searchSimilarChunks($tenant1->id, $baseVector, 0.5, 10);

    expect($results)->toHaveCount(1)
        ->and($results->first()->content)->toBe('Tenant 1 content');
});

it('prioritizes high priority chunks over normal', function (): void {
    $tenant = Tenant::factory()->create();

    $kb = KnowledgeBase::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'title' => 'KB',
        'type' => 'qa_pair',
        'is_active' => true,
    ]);

    $baseVector = array_fill(0, 1536, 1.0 / sqrt(1536));
    $vectorString = '['.implode(',', $baseVector).']';

    DB::statement(
        'INSERT INTO knowledge_chunks (id, tenant_id, knowledge_base_id, content, priority, embedding, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?::vector, NOW(), NOW())',
        [Str::uuid()->toString(), $tenant->id, $kb->id, 'Normal chunk', 'normal', $vectorString],
    );

    DB::statement(
        'INSERT INTO knowledge_chunks (id, tenant_id, knowledge_base_id, content, priority, embedding, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?::vector, NOW(), NOW())',
        [Str::uuid()->toString(), $tenant->id, $kb->id, 'High priority QA', 'high', $vectorString],
    );

    $results = $this->service->searchSimilarChunks($tenant->id, $baseVector, 0.5, 10);

    expect($results)->toHaveCount(2)
        ->and($results->first()->content)->toBe('High priority QA')
        ->and($results->first()->priority)->toBe('high');
});

it('excludes chunks below confidence threshold', function (): void {
    $tenant = Tenant::factory()->create();

    $kb = KnowledgeBase::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'title' => 'KB',
        'type' => 'document',
        'is_active' => true,
    ]);

    // Create an orthogonal vector (very different from query)
    $chunkVector = array_fill(0, 1536, 0.0);
    $chunkVector[0] = 1.0;
    $chunkVectorString = '['.implode(',', $chunkVector).']';

    DB::statement(
        'INSERT INTO knowledge_chunks (id, tenant_id, knowledge_base_id, content, priority, embedding, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?::vector, NOW(), NOW())',
        [Str::uuid()->toString(), $tenant->id, $kb->id, 'Dissimilar content', 'normal', $chunkVectorString],
    );

    // Query with a different vector direction
    $queryVector = array_fill(0, 1536, 0.0);
    $queryVector[1] = 1.0;

    $results = $this->service->searchSimilarChunks($tenant->id, $queryVector, 0.99, 10);

    expect($results)->toHaveCount(0);
});
