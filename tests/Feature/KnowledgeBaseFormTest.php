<?php

declare(strict_types=1);

use App\Enums\KnowledgeType;
use App\Enums\UserRole;
use App\Filament\Resources\KnowledgeBaseResource\Pages\CreateKnowledgeBase;
use App\Jobs\ProcessKnowledgeDocumentJob;
use App\Models\KnowledgeBase;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Knowledge\Extractors\PlainTextKnowledgeExtractor;
use App\Services\Knowledge\KnowledgeIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows text input for direct text and file upload for document type', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id]);
    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
    ]);

    $this->actingAs($owner);

    Livewire::test(CreateKnowledgeBase::class)
        ->fillForm([
            'title' => 'FAQ',
            'type' => KnowledgeType::DirectText->value,
            'is_active' => true,
        ])
        ->assertFormFieldIsVisible('content')
        ->assertFormFieldIsHidden('document');

    Livewire::test(CreateKnowledgeBase::class)
        ->fillForm([
            'title' => 'Policy PDF Text',
            'type' => KnowledgeType::Document->value,
            'is_active' => true,
        ])
        ->assertFormFieldIsVisible('document')
        ->assertFormFieldIsHidden('content');

    Livewire::test(CreateKnowledgeBase::class)
        ->fillForm([
            'title' => 'Support Q&A',
            'type' => KnowledgeType::QaPair->value,
            'is_active' => true,
        ])
        ->assertFormFieldIsVisible('content')
        ->assertFormFieldIsHidden('document');
});

it('creates a direct text knowledge base and queues ingestion', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id]);
    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
    ]);

    $this->actingAs($owner);

    Livewire::test(CreateKnowledgeBase::class)
        ->fillForm([
            'title' => 'Hours',
            'type' => KnowledgeType::DirectText->value,
            'content' => 'We are open Sunday to Thursday.',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $knowledgeBase = KnowledgeBase::query()->where('title', 'Hours')->first();

    expect($knowledgeBase)->not->toBeNull()
        ->and($knowledgeBase->type)->toBe(KnowledgeType::DirectText);

    Queue::assertPushed(ProcessKnowledgeDocumentJob::class);
});

it('extracts plain text from uploaded document files and queues ingestion', function (): void {
    Queue::fake();
    Storage::fake('local');

    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id]);
    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
    ]);

    $this->actingAs($owner);

    $file = UploadedFile::fake()->createWithContent('policy.txt', 'Refunds are available within 14 days.');

    Livewire::test(CreateKnowledgeBase::class)
        ->fillForm([
            'title' => 'Refund Policy',
            'type' => KnowledgeType::Document->value,
            'document' => [$file],
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $knowledgeBase = KnowledgeBase::query()->where('title', 'Refund Policy')->first();

    expect($knowledgeBase)->not->toBeNull()
        ->and($knowledgeBase->type)->toBe(KnowledgeType::Document);

    Queue::assertPushed(ProcessKnowledgeDocumentJob::class);
});

it('extracts and joins supported plain-text knowledge files', function (): void {
    $dir = sys_get_temp_dir().'/kb-extract-'.uniqid();
    mkdir($dir);

    $first = $dir.'/a.txt';
    $second = $dir.'/b.md';
    file_put_contents($first, 'First chunk');
    file_put_contents($second, 'Second chunk');

    $extractor = new PlainTextKnowledgeExtractor;
    $content = $extractor->extract([$first, $second]);

    expect($content)->toBe("First chunk\n\nSecond chunk");

    @unlink($first);
    @unlink($second);
    @rmdir($dir);
});

it('resolves content for text and document knowledge types via ingestion service', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('knowledge-documents/demo.txt', 'Uploaded knowledge body');

    $service = app(KnowledgeIngestionService::class);

    expect($service->resolveContent(KnowledgeType::DirectText, [
        'content' => ' Inline text ',
    ]))->toBe('Inline text');

    expect($service->resolveContent(KnowledgeType::Document, [
        'document' => 'knowledge-documents/demo.txt',
    ]))->toBe('Uploaded knowledge body');
});
