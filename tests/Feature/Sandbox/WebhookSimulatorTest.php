<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Exceptions\MissingWebhookSecretException;
use App\Filament\Pages\WebhookSandbox;
use App\Jobs\ProcessLeadIngestionJob;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Simulation\WebhookSimulatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('generates a Meta HMAC signature and posts to the meta webhook route', function (): void {
    Bus::fake([ProcessLeadIngestionJob::class]);

    $tenant = Tenant::factory()->create(['is_active' => true]);
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'meta_webhook_secret' => 'meta-sandbox-secret',
    ]);

    $ok = app(WebhookSimulatorService::class)->simulate($tenant->id, 'meta', [
        'name' => 'Sandbox Lead',
        'phone' => '+966500000111',
        'inquiry' => 'Need pricing for ads management',
    ]);

    expect($ok)->toBeTrue();

    Bus::assertDispatched(ProcessLeadIngestionJob::class, function (ProcessLeadIngestionJob $job) use ($tenant): bool {
        $reflection = new ReflectionClass($job);
        $tenantProp = $reflection->getProperty('tenantId');
        $tenantProp->setAccessible(true);
        $payloadProp = $reflection->getProperty('payload');
        $payloadProp->setAccessible(true);

        if ($tenantProp->getValue($job) !== $tenant->id) {
            return false;
        }

        /** @var array<string, mixed> $payload */
        $payload = $payloadProp->getValue($job);
        $leadgen = $payload['entry'][0]['changes'][0]['value']['leadgen_data'] ?? null;

        return is_array($leadgen)
            && ($leadgen['full_name'] ?? null) === 'Sandbox Lead'
            && ($leadgen['phone_number'] ?? null) === '+966500000111'
            && ($leadgen['inquiry'] ?? null) === 'Need pricing for ads management';
    });
});

it('generates a TikTok HMAC signature and posts to the tiktok webhook route', function (): void {
    Bus::fake([ProcessLeadIngestionJob::class]);

    $tenant = Tenant::factory()->create(['is_active' => true]);
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'tiktok_webhook_secret' => 'tiktok-sandbox-secret',
    ]);

    $ok = app(WebhookSimulatorService::class)->simulate($tenant->id, 'tiktok', [
        'name' => 'TT Lead',
        'phone' => '+966500000222',
        'inquiry' => 'Book a demo please',
    ]);

    expect($ok)->toBeTrue();

    Bus::assertDispatched(ProcessLeadIngestionJob::class, function (ProcessLeadIngestionJob $job) use ($tenant): bool {
        $reflection = new ReflectionClass($job);
        $tenantProp = $reflection->getProperty('tenantId');
        $tenantProp->setAccessible(true);
        $payloadProp = $reflection->getProperty('payload');
        $payloadProp->setAccessible(true);

        if ($tenantProp->getValue($job) !== $tenant->id) {
            return false;
        }

        /** @var array<string, mixed> $payload */
        $payload = $payloadProp->getValue($job);
        $data = $payload['data'] ?? null;

        return is_array($data)
            && ($data['name'] ?? null) === 'TT Lead'
            && ($data['phone'] ?? null) === '+966500000222'
            && ($data['inquiry'] ?? null) === 'Book a demo please';
    });
});

it('throws MissingWebhookSecretException when the tenant secret is null', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'meta_webhook_secret' => null,
        'tiktok_webhook_secret' => null,
    ]);

    expect(fn () => app(WebhookSimulatorService::class)->simulate($tenant->id, 'meta', [
        'name' => 'No Secret',
        'phone' => '+10000000000',
        'inquiry' => 'Hello',
    ]))->toThrow(MissingWebhookSecretException::class);
});

it('renders the webhook sandbox page for owners', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'meta_webhook_secret' => 'meta-sandbox-secret',
    ]);

    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
        'is_active' => true,
    ]);

    $this->actingAs($owner);

    expect(WebhookSandbox::canAccess())->toBeTrue();

    Livewire::test(WebhookSandbox::class)
        ->assertSuccessful()
        ->assertSee('Simulate Webhook')
        ->assertSee('Webhook Sandbox');
});

it('forbids sales reps from accessing the webhook sandbox page', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id]);

    $rep = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
    ]);

    $this->actingAs($rep);

    expect(WebhookSandbox::canAccess())->toBeFalse();

    $this->get(WebhookSandbox::getUrl())->assertForbidden();
    Livewire::test(WebhookSandbox::class)->assertForbidden();
});

it('shows a danger notification when simulating without a webhook secret', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'meta_webhook_secret' => null,
    ]);

    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Admin,
        'is_active' => true,
    ]);

    $this->actingAs($owner);

    Livewire::test(WebhookSandbox::class)
        ->fillForm([
            'source' => 'meta',
            'name' => 'Test Lead',
            'phone' => '+1234567890',
            'inquiry' => 'I am interested in your premium services. What is the price?',
        ])
        ->call('simulateWebhook')
        ->assertNotified('Webhook secret is missing for this source. Please configure it in Tenant Settings.');
});

it('shows a success notification in the same Livewire response after Simulate Webhook', function (): void {
    Bus::fake([ProcessLeadIngestionJob::class]);
    Cache::flush();

    $tenant = Tenant::factory()->create(['is_active' => true]);
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'meta_webhook_secret' => 'meta-sandbox-secret',
    ]);

    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
        'is_active' => true,
    ]);

    $this->actingAs($owner);

    Livewire::test(WebhookSandbox::class)
        ->fillForm([
            'source' => 'meta',
            'name' => 'Immediate Notify Lead',
            'phone' => '+966500001111',
            'inquiry' => 'Notification should appear without refresh',
        ])
        ->call('simulateWebhook')
        ->assertNotified('Test lead dispatched successfully!');

    Bus::assertDispatched(ProcessLeadIngestionJob::class);
});
