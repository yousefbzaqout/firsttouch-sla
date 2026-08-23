<?php

declare(strict_types=1);

use App\Adapters\Notifications\NotificationDriverFactory;
use App\Enums\AiRoutingMode;
use App\Enums\LeadStatus;
use App\Enums\SlaStatus;
use App\Enums\UserRole;
use App\Jobs\EscalateLeadSlaJob;
use App\Jobs\RegisterTelegramWebhookJob;
use App\Jobs\SendLeadAssignedNotificationJob;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Ai\CreditManagerService;
use App\Services\Telegram\TelegramBotResolver;
use App\Services\Telegram\TelegramMessageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Creating TenantSetting with a bot token sync-dispatches RegisterTelegramWebhookJob;
    // fake it so assertSent callbacks only see notification traffic.
    Queue::fake([RegisterTelegramWebhookJob::class]);
});

it('uses the tenant custom bot token when present in the database', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
    ]);

    config(['services.telegram.bot_token' => 'GLOBAL_FALLBACK_TOKEN']);

    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'notification_driver' => 'telegram',
        'telegram_bot_token' => 'TENANT_CUSTOM_TOKEN',
        'telegram_chat_id' => '-100111222333',
        'ai_routing_mode' => AiRoutingMode::AiFirst,
    ]);

    $rep = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'telegram_chat_id' => null,
    ]);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $rep->id,
        'status' => LeadStatus::Claimed,
        'sla_status' => SlaStatus::Active,
        'sla_deadline' => now()->addMinutes(10),
        'name' => 'Lead Custom Token',
        'phone' => '+966500000001',
        'meta_data' => [
            'interested_service' => 'Social Ads Retainer',
            'ai_qualification_summary' => 'Hot lead',
            'ai_suggested_reply' => 'Thanks for your interest!',
        ],
    ]);

    (new SendLeadAssignedNotificationJob($lead->id))->handle(app(NotificationDriverFactory::class));

    Http::assertSent(function ($request): bool {
        $text = (string) $request['text'];

        return str_contains($request->url(), 'https://api.telegram.org/botTENANT_CUSTOM_TOKEN/sendMessage')
            && $request['chat_id'] === '-100111222333'
            && str_contains($text, 'Lead Custom Token')
            && str_contains($text, 'Social Ads Retainer')
            && str_contains($text, 'Hot lead')
            && str_contains($text, 'Thanks for your interest!');
    });
});

it('sends lead assigned alerts to the sales rep personal telegram chat', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]], 200),
    ]);

    config(['services.telegram.bot_token' => 'GLOBAL_FALLBACK_TOKEN']);

    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'notification_driver' => 'telegram',
        'telegram_bot_token' => 'TENANT_CUSTOM_TOKEN',
        'telegram_chat_id' => '-100111222333',
    ]);

    $rep = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'telegram_chat_id' => 555666777,
    ]);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $rep->id,
        'name' => 'Personal Chat Lead',
        'phone' => '+966500000010',
        'sla_deadline' => now()->addMinutes(12),
        'meta_data' => ['campaign_id' => 'cmp-100', 'form_id' => 'frm-9'],
    ]);

    (new SendLeadAssignedNotificationJob($lead->id))->handle(app(NotificationDriverFactory::class));

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), 'botTENANT_CUSTOM_TOKEN/sendMessage')
            && (string) $request['chat_id'] === '555666777'
            && str_contains((string) $request['text'], 'Campaign cmp-100');
    });

    Http::assertNotSent(function ($request): bool {
        return (string) $request['chat_id'] === '-100111222333';
    });
});

it('escalates SLA breaches to owner/admin personal chats with a reassign link', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 22]], 200),
    ]);

    config(['services.telegram.bot_token' => 'GLOBAL_FALLBACK_TOKEN']);

    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'notification_driver' => 'telegram',
        'telegram_bot_token' => null,
        'telegram_chat_id' => '-100444555666',
    ]);

    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
        'name' => 'Owner Ahmad',
        'telegram_chat_id' => 111222333,
    ]);

    $rep = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'name' => 'Sara',
        'telegram_chat_id' => 999888777,
    ]);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $rep->id,
        'status' => LeadStatus::Claimed,
        'sla_status' => SlaStatus::Breached,
        'sla_deadline' => now()->subMinute(),
        'name' => 'Mahmoud',
        'phone' => '+966500000002',
    ]);

    $destination = app(TelegramBotResolver::class)->resolveForTenant($tenant->id);

    expect($destination->usedFallbackToken)->toBeTrue()
        ->and($destination->botToken)->toBe('GLOBAL_FALLBACK_TOKEN')
        ->and($destination->canSend())->toBeTrue();

    (new EscalateLeadSlaJob($lead->id))->handle(app(NotificationDriverFactory::class));

    Http::assertSent(function ($request) use ($lead, $owner): bool {
        $text = (string) $request['text'];

        return str_contains($request->url(), 'botGLOBAL_FALLBACK_TOKEN/sendMessage')
            && (string) $request['chat_id'] === (string) $owner->telegram_chat_id
            && str_contains($text, 'SLA BREACH')
            && str_contains($text, 'Sara')
            && str_contains($text, 'Mahmoud')
            && str_contains($text, 'reassign=1')
            && str_contains($text, (string) $lead->id);
    });

    Http::assertNotSent(function ($request): bool {
        return (string) $request['chat_id'] === '999888777'
            || (string) $request['chat_id'] === '-100444555666';
    });
});

it('handles missing telegram chat id gracefully without crashing', function (): void {
    Http::fake();
    Log::spy();

    config(['services.telegram.bot_token' => 'GLOBAL_FALLBACK_TOKEN']);

    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'notification_driver' => 'telegram',
        'telegram_bot_token' => 'TENANT_TOKEN',
        'telegram_chat_id' => null,
    ]);

    $rep = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'telegram_chat_id' => null,
    ]);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $rep->id,
        'name' => 'No Chat Lead',
    ]);

    $destination = app(TelegramBotResolver::class)->resolveForTenant($tenant->id);

    expect($destination->canSend())->toBeFalse()
        ->and($destination->chatId)->toBeNull();

    $result = app(NotificationDriverFactory::class)
        ->resolve('telegram')
        ->sendLeadAssignedAlert($lead);

    expect($result)->toBeFalse();

    Http::assertNothingSent();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'Telegram'))
        ->atLeast()
        ->once();
});

it('notifies managers once when credits drop to the low threshold', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 33]], 200),
    ]);

    config([
        'services.telegram.bot_token' => 'GLOBAL_TOKEN',
        'services.telegram.low_credits_threshold' => 3,
    ]);

    $tenant = Tenant::factory()->create(['name' => 'Apex Media']);
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'notification_driver' => 'telegram',
        'telegram_bot_token' => 'TENANT_TOKEN',
        'telegram_chat_id' => '-100000',
        'credits_balance' => 3,
        'ai_routing_mode' => AiRoutingMode::AiFirst,
    ]);

    User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
        'telegram_chat_id' => 444555666,
    ]);

    Cache::flush();

    app(CreditManagerService::class)->deductCredit($tenant);

    expect(TenantSetting::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('credits_balance'))->toBe(2);

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        return (string) ($data['chat_id'] ?? '') === '444555666'
            && str_contains((string) ($data['text'] ?? ''), 'Low Credit Balance')
            && str_contains((string) ($data['text'] ?? ''), '2 credit');
    });

    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 34]], 200),
    ]);

    app(CreditManagerService::class)->deductCredit($tenant);

    Http::assertNothingSent();
});

it('includes interested service and reassign CTA in telegram message templates', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'ai_routing_mode' => AiRoutingMode::AiAssisted,
    ]);

    $rep = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'name' => 'Omar',
    ]);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $rep->id,
        'name' => 'Client X',
        'phone' => '+966511122233',
        'sla_deadline' => now()->addMinutes(8),
        'meta_data' => [
            'interested_service' => 'TikTok Lead Gen Package',
            'ai_qualification_summary' => 'Qualified',
            'ai_suggested_reply' => 'Hello, happy to help.',
        ],
    ]);

    $builder = app(TelegramMessageBuilder::class);

    $assigned = $builder->leadAssigned($lead);
    $breach = $builder->slaBreach($lead, 'No action taken');

    expect($assigned)
        ->toContain('TikTok Lead Gen Package')
        ->toContain('Qualified')
        ->toContain('Hello, happy to help.')
        ->toContain('/admin/leads/'.$lead->id)
        ->and($breach)
        ->toContain('Omar')
        ->toContain('Client X')
        ->toContain('reassign=1')
        ->toContain('Reassign this lead');
});
