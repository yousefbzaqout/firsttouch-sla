<?php

declare(strict_types=1);

use App\Jobs\ProcessLeadIngestionJob;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    Bus::fake([ProcessLeadIngestionJob::class]);
});

/**
 * @return array<string, mixed>
 */
function websiteFormPayload(): array
{
    return [
        'name' => 'Website Lead',
        'phone' => '+966500000400',
        'email' => 'website.lead@example.com',
        'inquiry' => 'Interested in your pricing page.',
        'page_url' => 'https://example.com/contact',
    ];
}

it('accepts a custom website form payload with a valid tenant website_api_key', function (): void {
    $tenant = Tenant::factory()->create(['is_active' => true]);
    $apiKey = 'ft_web_'.Str::lower(Str::random(16));

    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'website_api_key' => $apiKey,
    ]);

    config(['services.webhooks.website_secret' => 'global-website-should-not-win']);

    $payload = json_encode(websiteFormPayload(), JSON_THROW_ON_ERROR);

    $response = $this->call(
        'POST',
        "/api/v1/webhooks/website/{$tenant->id}",
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WEBSITE_API_KEY' => $apiKey,
        ],
        $payload,
    );

    $response->assertCreated();
    $response->assertJson([
        'success' => true,
        'message' => 'Lead received successfully',
        'source' => 'website',
    ]);
    Bus::assertDispatched(ProcessLeadIngestionJob::class);
});

it('returns 422 when required name or phone fields are missing', function (): void {
    $tenant = Tenant::factory()->create(['is_active' => true]);
    $apiKey = 'ft_web_validation_key';

    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'website_api_key' => $apiKey,
    ]);

    $missingPhone = $this->call(
        'POST',
        "/api/v1/webhooks/website/{$tenant->id}",
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_API_KEY' => $apiKey,
        ],
        json_encode(['name' => 'No Phone'], JSON_THROW_ON_ERROR),
    );

    $missingPhone->assertStatus(422);
    $missingPhone->assertJsonPath('success', false);
    expect($missingPhone->json('errors'))->toHaveKey('phone');

    $missingName = $this->call(
        'POST',
        "/api/v1/webhooks/website/{$tenant->id}",
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_API_KEY' => $apiKey,
        ],
        json_encode(['phone' => '+966500000401'], JSON_THROW_ON_ERROR),
    );

    $missingName->assertStatus(422);
    expect($missingName->json('errors'))->toHaveKey('name');
    Bus::assertNotDispatched(ProcessLeadIngestionJob::class);
});

it('returns 401 Unauthorized when the website_api_key is invalid or missing', function (): void {
    $tenant = Tenant::factory()->create(['is_active' => true]);
    $apiKey = 'ft_web_tenant_only';

    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'website_api_key' => $apiKey,
    ]);

    config(['services.webhooks.website_secret' => 'global-website']);

    $payload = json_encode(websiteFormPayload(), JSON_THROW_ON_ERROR);

    $invalid = $this->call(
        'POST',
        "/api/v1/webhooks/website/{$tenant->id}",
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WEBSITE_API_KEY' => 'wrong-key',
        ],
        $payload,
    );

    $invalid->assertUnauthorized();
    $invalid->assertJson([
        'success' => false,
        'message' => 'Invalid API Key',
    ]);

    $missing = $this->call(
        'POST',
        "/api/v1/webhooks/website/{$tenant->id}",
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        $payload,
    );

    $missing->assertUnauthorized();
    $missing->assertJson([
        'success' => false,
        'message' => 'Invalid API Key',
    ]);
    Bus::assertNotDispatched(ProcessLeadIngestionJob::class);
});

it('falls back to the global .env website secret when the tenant key is null', function (): void {
    $tenant = Tenant::factory()->create(['is_active' => true]);
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'website_api_key' => null,
    ]);

    $globalSecret = 'global-website-'.Str::lower(Str::random(8));
    config(['services.webhooks.website_secret' => $globalSecret]);

    $form = websiteFormPayload();
    $payload = json_encode($form, JSON_THROW_ON_ERROR);

    $response = $this->call(
        'POST',
        "/api/v1/webhooks/website/{$tenant->id}",
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$globalSecret,
        ],
        $payload,
    );

    $response->assertCreated();
    $response->assertJson([
        'success' => true,
        'message' => 'Lead received successfully',
        'source' => 'website',
    ]);

    Bus::assertDispatched(ProcessLeadIngestionJob::class, function (ProcessLeadIngestionJob $job) use ($tenant, $form): bool {
        $reflection = new ReflectionClass($job);
        $tenantProp = $reflection->getProperty('tenantId');
        $tenantProp->setAccessible(true);
        $sourceProp = $reflection->getProperty('source');
        $sourceProp->setAccessible(true);
        $payloadProp = $reflection->getProperty('payload');
        $payloadProp->setAccessible(true);

        /** @var array<string, mixed> $jobPayload */
        $jobPayload = $payloadProp->getValue($job);

        return $tenantProp->getValue($job) === $tenant->id
            && $sourceProp->getValue($job)->value === 'website'
            && ($jobPayload['name'] ?? null) === $form['name']
            && ($jobPayload['phone'] ?? null) === $form['phone'];
    });
});
