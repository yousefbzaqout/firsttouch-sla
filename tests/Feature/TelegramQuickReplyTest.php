<?php

declare(strict_types=1);

use App\Adapters\Notifications\NotificationDriverFactory;
use App\Enums\LeadStatus;
use App\Enums\SlaStatus;
use App\Enums\UserRole;
use App\Models\KnowledgeBase;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Ai\Contracts\EmbeddingServiceInterface;
use App\Services\Ai\Contracts\LlmProviderInterface;
use App\Services\Ai\RagInferenceService;
use App\Services\Telegram\TelegramNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('parses AI quick_replies into the qualification DTO when the lead is qualified', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'credits_balance' => 5,
        'ai_confidence_threshold' => 50.00,
    ]);

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $kb = KnowledgeBase::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'title' => 'KB',
        'type' => 'qa_pair',
        'is_active' => true,
    ]);
    $vector = array_fill(0, 1536, 1.0 / sqrt(1536));
    DB::statement(
        'INSERT INTO knowledge_chunks (id, tenant_id, knowledge_base_id, content, priority, embedding, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?::vector, NOW(), NOW())',
        [Str::uuid()->toString(), $tenant->id, $kb->id, 'We sell Meta ads retainers', 'high', '['.implode(',', $vector).']'],
    );

    app()->bind(EmbeddingServiceInterface::class, function () use ($vector) {
        $mock = Mockery::mock(EmbeddingServiceInterface::class);
        $mock->shouldReceive('generateEmbedding')->andReturn($vector);

        return $mock;
    });

    app()->bind(LlmProviderInterface::class, function () {
        $mock = Mockery::mock(LlmProviderInterface::class);
        $mock->shouldReceive('generateResponse')->andReturn(json_encode([
            'qualification_score' => 0.9,
            'qualification_summary' => 'Fit for retainer',
            'is_qualified' => true,
            'suggested_reply' => 'Thanks for your interest in our retainers.',
            'quick_replies' => [
                ['label' => 'Reply 1: Book Call', 'text' => 'Happy to book a 15-min call this week.'],
                ['label' => 'Reply 2: Send Price', 'text' => 'Our starter retainer starts at a custom quote.'],
                ['label' => 'Reply 3: Qualify', 'text' => 'What monthly ad spend are you targeting?'],
            ],
        ], JSON_THROW_ON_ERROR));

        return $mock;
    });

    $result = app(RagInferenceService::class)->processLeadQuery($lead);

    expect($result->isQualified)->toBeTrue()
        ->and($result->quickReplies)->toHaveCount(3)
        ->and($result->quickReplies[0]->label)->toBe('Reply 1: Book Call');
});

it('attaches an inline keyboard when sending a lead-assigned telegram alert', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
    ]);

    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'notification_driver' => 'telegram',
        'telegram_bot_token' => 'BOT_TOKEN',
        'telegram_chat_id' => '-1001',
    ]);

    $rep = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'telegram_chat_id' => 555001,
    ]);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $rep->id,
        'name' => 'Keyboard Lead',
        'ai_quick_replies' => [
            ['label' => 'Reply 1: Book Call', 'text' => 'Let us book a call.'],
            ['label' => 'Reply 2: Send Price', 'text' => 'Here is pricing.'],
        ],
    ]);

    $ok = app(NotificationDriverFactory::class)
        ->resolve('telegram')
        ->sendLeadAssignedAlert($lead);

    expect($ok)->toBeTrue();

    Http::assertSent(function ($request) use ($lead): bool {
        if (! str_contains($request->url(), 'BOT_TOKEN/sendMessage')) {
            return false;
        }

        $markup = json_decode((string) ($request['reply_markup'] ?? ''), true);

        return is_array($markup)
            && isset($markup['inline_keyboard'][0][0]['callback_data'])
            && $markup['inline_keyboard'][0][0]['callback_data'] === 'qr:'.$lead->id.':0'
            && str_contains((string) $request['text'], 'Quick replies');
    });
});

it('handles a telegram quick-reply callback, marks in progress, and returns copyable text', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9]], 200),
    ]);

    config(['services.telegram.webhook_secret' => 'tg-webhook-secret']);

    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'notification_driver' => 'telegram',
        'telegram_bot_token' => 'BOT_TOKEN',
        'telegram_chat_id' => '-1001',
    ]);

    $rep = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'telegram_chat_id' => 777888,
        'is_active' => true,
    ]);

    $started = now()->subMinutes(2);
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $rep->id,
        'status' => LeadStatus::Claimed,
        'sla_status' => SlaStatus::Active,
        'sla_started_at' => $started,
        'sla_deadline' => now()->addMinutes(8),
        'first_action_at' => null,
        'ai_quick_replies' => [
            ['label' => 'Reply 1: Book Call', 'text' => 'Can we book a call tomorrow at 10am?'],
            ['label' => 'Reply 2: Send Price', 'text' => 'Pricing starts from a custom package.'],
        ],
    ]);

    $response = $this->postJson('/api/v1/webhooks/telegram/'.$tenant->id, [
        'callback_query' => [
            'id' => 'cbq-1',
            'from' => ['id' => 777888, 'username' => 'agent'],
            'data' => 'qr:'.$lead->id.':0',
            'message' => [
                'message_id' => 42,
                'chat' => ['id' => 777888],
            ],
        ],
    ], [
        'X-Telegram-Bot-Api-Secret-Token' => 'tg-webhook-secret',
    ]);

    $response->assertOk()->assertJsonPath('status', 'ok');

    $lead->refresh();

    expect($lead->status)->toBe(LeadStatus::InProgress)
        ->and($lead->first_action_at)->not->toBeNull()
        ->and($lead->meta_data['telegram_quick_reply_used']['index'] ?? null)->toBe(0);

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), 'answerCallbackQuery');
    });

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), 'sendMessage')
            && str_contains((string) $request['text'], 'Can we book a call tomorrow at 10am?')
            && str_contains((string) $request['text'], '<pre>');
    });
});

it('rejects quick-reply callbacks from agents who do not own the lead', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
    ]);

    config(['services.telegram.webhook_secret' => 'tg-webhook-secret']);

    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'telegram_bot_token' => 'BOT_TOKEN',
        'telegram_chat_id' => '-1001',
        'notification_driver' => 'telegram',
    ]);

    $ownerRep = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'telegram_chat_id' => 111,
    ]);
    $otherRep = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'telegram_chat_id' => 222,
    ]);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $ownerRep->id,
        'status' => LeadStatus::Claimed,
        'ai_quick_replies' => [
            ['label' => 'Reply 1: Book Call', 'text' => 'Hello'],
        ],
    ]);

    $response = $this->postJson('/api/v1/webhooks/telegram/'.$tenant->id, [
        'callback_query' => [
            'id' => 'cbq-forbidden',
            'from' => ['id' => 222],
            'data' => 'qr:'.$lead->id.':0',
            'message' => ['message_id' => 1, 'chat' => ['id' => 222]],
        ],
    ], [
        'X-Telegram-Bot-Api-Secret-Token' => 'tg-webhook-secret',
    ]);

    $response->assertOk()->assertJsonPath('error', 'forbidden');
    expect($lead->fresh()->status)->toBe(LeadStatus::Claimed)
        ->and($lead->fresh()->first_action_at)->toBeNull();
});

it('builds callback_data within telegram 64-byte limit', function (): void {
    $lead = Lead::factory()->make([
        'id' => (string) Str::uuid(),
        'ai_quick_replies' => [
            ['label' => 'Reply 1: Book Call', 'text' => 'Text'],
        ],
    ]);

    // Persist-less keyboard builder needs a model with id + replies.
    $lead->id = (string) Str::uuid();
    $lead->ai_quick_replies = [
        ['label' => 'Reply 1: Book Call', 'text' => 'Text'],
    ];

    $markup = app(TelegramNotifier::class)->quickReplyKeyboard($lead);

    expect($markup)->not->toBeNull()
        ->and(strlen($markup['inline_keyboard'][0][0]['callback_data']))->toBeLessThanOrEqual(64);
});
