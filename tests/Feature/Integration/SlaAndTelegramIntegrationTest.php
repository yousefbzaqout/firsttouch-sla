<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\RegisterTelegramWebhookJob;
use App\Livewire\AgentStatusToggle;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Leads\RoundRobinAssignerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('updates the authenticated sales rep is_online status via AgentStatusToggle', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id]);

    $rep = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => true,
    ]);

    $this->actingAs($rep);

    Livewire::test(AgentStatusToggle::class)
        ->assertSet('isOnline', true)
        ->call('toggleOnline')
        ->assertSet('isOnline', false);

    expect($rep->fresh()->is_online)->toBeFalse();

    Livewire::test(AgentStatusToggle::class)
        ->assertSet('isOnline', false)
        ->call('toggleOnline')
        ->assertSet('isOnline', true);

    expect($rep->fresh()->is_online)->toBeTrue();
});

it('dispatches RegisterTelegramWebhookJob when telegram_bot_token changes', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $setting = TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'telegram_bot_token' => null,
    ]);

    $setting->update([
        'telegram_bot_token' => '123456:ABC-NEW-TOKEN',
    ]);

    Queue::assertPushed(RegisterTelegramWebhookJob::class, function (RegisterTelegramWebhookJob $job) use ($tenant): bool {
        $reflection = new ReflectionClass($job);
        $property = $reflection->getProperty('tenantId');
        $property->setAccessible(true);

        return $property->getValue($job) === $tenant->id;
    });
});

it('does not dispatch RegisterTelegramWebhookJob when token is unchanged', function (): void {
    $tenant = Tenant::factory()->create();

    $setting = TenantSetting::withoutEvents(fn (): TenantSetting => TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'telegram_bot_token' => '123456:SAME-TOKEN',
    ]));

    Queue::fake();

    $setting->update([
        'telegram_chat_id' => '-100999',
        'sla_timeout_minutes' => 12,
    ]);

    Queue::assertNotPushed(RegisterTelegramWebhookJob::class);
});

it('registers the telegram webhook via Http setWebhook call', function (): void {
    Http::fake([
        'https://api.telegram.org/bot123456:ABC-TOKEN/setWebhook' => Http::response([
            'ok' => true,
            'result' => true,
            'description' => 'Webhook was set',
        ], 200),
    ]);

    config([
        'app.url' => 'https://firsttouch.test',
        'services.telegram.webhook_secret' => 'secret-xyz',
    ]);

    $tenant = Tenant::factory()->create();

    TenantSetting::withoutEvents(fn (): TenantSetting => TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'telegram_bot_token' => '123456:ABC-TOKEN',
    ]));

    (new RegisterTelegramWebhookJob($tenant->id))->handle();

    Http::assertSent(function ($request) use ($tenant): bool {
        if ($request->url() !== 'https://api.telegram.org/bot123456:ABC-TOKEN/setWebhook') {
            return false;
        }

        return $request['url'] === 'https://firsttouch.test/api/v1/webhooks/telegram/'.$tenant->id
            && $request['secret_token'] === 'secret-xyz'
            && isset($request['allowed_updates']);
    });
});

it('strictly excludes offline sales reps from round-robin assignment', function (): void {
    $tenant = Tenant::factory()->create();

    $offline = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => false,
    ]);

    $online = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => true,
    ]);

    $assigned = app(RoundRobinAssignerService::class)->assign($tenant->id);

    expect($assigned)->toBe($online->id)
        ->and($assigned)->not->toBe($offline->id);

    $offline->update(['is_online' => false]);
    $online->update(['is_online' => false]);

    expect(app(RoundRobinAssignerService::class)->assign($tenant->id))->toBeNull();
});
