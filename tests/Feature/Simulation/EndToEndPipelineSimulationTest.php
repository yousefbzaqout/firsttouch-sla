<?php

declare(strict_types=1);

use App\Enums\AiRoutingMode;
use App\Enums\KnowledgeType;
use App\Enums\LeadStatus;
use App\Enums\SlaStatus;
use App\Enums\UserRole;
use App\Models\KnowledgeBase;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantWorkingHour;
use App\Models\User;
use App\Services\Ai\Contracts\EmbeddingServiceInterface;
use App\Services\Ai\Contracts\LlmProviderInterface;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('simulates the full first-touch pipeline from registration to sla escalation and credit deduction', function (): void {
    Cache::flush();
    Http::fake([
        'https://n8n.test/*' => Http::response(['ok' => true], 200),
    ]);
    config([
        'services.n8n.webhook_url' => 'https://n8n.test/webhook/firsttouch-sla-alerts',
        'services.webhooks.meta_secret' => 'global-fallback-secret-should-not-be-used',
    ]);

    // Deterministic Monday mid-shift in the app timezone (default working hours 09:00-17:00).
    $this->travelTo(Carbon::parse('2026-08-17 10:00:00'));

    /*
    |--------------------------------------------------------------------------
    | Step 1 — Tenant onboarding (self-registration)
    |--------------------------------------------------------------------------
    */
    $registerResponse = $this->postJson('/api/v1/auth/register', [
        'company_name' => 'Simulation Co',
        'name' => 'Sim Owner',
        'email' => 'owner@simulation.test',
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
    ]);

    $registerResponse->assertCreated()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.role', UserRole::Owner->value)
        ->assertJsonPath('user.email', 'owner@simulation.test');

    $tenantId = (string) $registerResponse->json('user.tenant_id');
    $tenant = Tenant::query()->findOrFail($tenantId);
    $owner = User::query()->where('email', 'owner@simulation.test')->firstOrFail();

    expect($tenant->name)->toBe('Simulation Co')
        ->and($tenant->is_active)->toBeTrue()
        ->and($owner->tenant_id)->toBe($tenant->id)
        ->and($owner->role)->toBe(UserRole::Owner);

    $setting = TenantSetting::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->firstOrFail();

    expect($setting->credits_balance)->toBe(50)
        ->and($setting->sla_timeout_minutes)->toBe(15)
        ->and($setting->notification_driver)->toBe('n8n');

    expect(
        TenantWorkingHour::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
    )->toBe(7);

    /*
    |--------------------------------------------------------------------------
    | Step 2 — Tenant configuration
    |--------------------------------------------------------------------------
    */
    $tenantMetaSecret = 'tenant-meta-'.Str::lower(Str::random(16));

    $setting->update([
        'meta_webhook_secret' => $tenantMetaSecret,
        'notification_driver' => 'n8n',
        'sla_timeout_minutes' => 15,
        'ai_routing_mode' => AiRoutingMode::AiFirst,
        'ai_confidence_threshold' => 50.00,
    ]);

    // Ensure a sales rep exists for round-robin assignment (Owner alone is not assignable).
    $salesRep = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
    ]);

    $setting->refresh();

    expect($setting->meta_webhook_secret)->toBe($tenantMetaSecret)
        ->and($setting->notification_driver)->toBe('n8n');

    /*
    |--------------------------------------------------------------------------
    | Step 2b — Knowledge base + AI mocks (required before AI First sync job)
    |--------------------------------------------------------------------------
    */
    $knowledgeBase = KnowledgeBase::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'title' => 'Simulation FAQ',
        'type' => KnowledgeType::QaPair,
        'is_active' => true,
    ]);

    $vector = array_fill(0, 1536, 1.0 / sqrt(1536));
    $vectorString = '['.implode(',', $vector).']';

    DB::statement(
        'INSERT INTO knowledge_chunks (id, tenant_id, knowledge_base_id, content, priority, embedding, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?::vector, NOW(), NOW())',
        [(string) Str::uuid(), $tenant->id, $knowledgeBase->id, 'We open from 9am to 5pm Sunday through Thursday. Social ads retainers available.', 'high', $vectorString],
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
                return json_encode([
                    'qualification_score' => 0.92,
                    'qualification_summary' => 'Hot prospect interested in retainers during business hours.',
                    'is_qualified' => true,
                    'suggested_reply' => 'We open from 9am to 5pm Sunday through Thursday.',
                ], JSON_THROW_ON_ERROR);
            }
        };
    });

    /*
    |--------------------------------------------------------------------------
    | Step 3 — Incoming Meta lead (HMAC signed with tenant secret)
    |--------------------------------------------------------------------------
    */
    $externalLeadId = 'sim-lead-'.Str::lower(Str::random(8));

    $webhookPayload = [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'leadgen_data' => [
                        'leadgen_id' => $externalLeadId,
                        'full_name' => 'Sim Prospect',
                        'phone_number' => '+966500001234',
                        'email' => 'prospect@simulation.test',
                        'campaign_id' => 'cmp-sim-1',
                        'form_id' => 'frm-sim-1',
                    ],
                ],
            ]],
        ]],
    ];

    $payloadJson = json_encode($webhookPayload, JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', $payloadJson, $tenantMetaSecret);

    $webhookResponse = $this->call(
        'POST',
        "/api/v1/webhooks/meta/{$tenant->id}",
        [],
        [],
        [],
        [
            'HTTP_X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ],
        $payloadJson,
    );

    $webhookResponse->assertOk()->assertJson(['status' => 'accepted']);

    /*
    |--------------------------------------------------------------------------
    | Step 4 — Queue & pipeline assertions (sync executes ProcessLeadIngestionJob)
    |--------------------------------------------------------------------------
    */
    $lead = Lead::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('external_lead_id', $externalLeadId)
        ->first();

    expect($lead)->not->toBeNull()
        ->and($lead?->name)->toBe('Sim Prospect')
        ->and($lead?->phone)->toBe('+966500001234')
        ->and($lead?->status)->toBe(LeadStatus::New)
        ->and($lead?->sla_status)->toBe(SlaStatus::Active)
        ->and($lead?->assigned_user_id)->toBe($salesRep->id)
        ->and($lead?->sla_deadline)->not->toBeNull()
        ->and($lead?->meta_data['ai_is_qualified'] ?? false)->toBeTrue()
        ->and($lead?->meta_data['ai_suggested_reply'] ?? null)->not->toBeNull();

    // 15-minute SLA inside Mon 10:00 working hours → deadline ~15 minutes ahead.
    expect((int) now()->diffInMinutes($lead?->sla_deadline))->toBe(15);

    /*
    |--------------------------------------------------------------------------
    | Step 5 — Credit deduction already applied by AI First qualification job
    |--------------------------------------------------------------------------
    */
    expect(
        (int) TenantSetting::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('credits_balance'),
    )->toBe(49);

    /*
    |--------------------------------------------------------------------------
    | Step 5b — SLA timeout + breach escalation to n8n
    |--------------------------------------------------------------------------
    */
    $this->travelTo($lead->sla_deadline?->copy()->addMinute());

    $this->artisan('sla:check-breaches')->assertSuccessful();

    $lead->refresh();

    expect($lead->sla_status)->toBe(SlaStatus::Breached);

    Http::assertSent(function ($request) use ($lead, $tenant): bool {
        if ($request->url() !== 'https://n8n.test/webhook/firsttouch-sla-alerts') {
            return false;
        }

        /** @var array<string, mixed> $body */
        $body = $request->data();

        return ($body['event_type'] ?? null) === 'sla_breach'
            && ($body['type'] ?? null) === 'escalation'
            && ($body['lead_id'] ?? null) === $lead->id
            && ($body['tenant_id'] ?? null) === $tenant->id;
    });
});

it('rejects the simulation webhook when signed with the global secret after a tenant secret is set', function (): void {
    Cache::flush();

    $registerResponse = $this->postJson('/api/v1/auth/register', [
        'company_name' => 'Secure Sim Co',
        'name' => 'Secure Owner',
        'email' => 'secure@simulation.test',
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
    ]);

    $registerResponse->assertCreated();
    $tenantId = (string) $registerResponse->json('user.tenant_id');

    $setting = TenantSetting::withoutGlobalScopes()
        ->where('tenant_id', $tenantId)
        ->firstOrFail();

    $setting->update(['meta_webhook_secret' => 'tenant-only-secret']);

    config(['services.webhooks.meta_secret' => 'global-secret']);

    $payload = [
        'id' => 'should-fail',
        'name' => 'Nope',
        'phone' => '+966500009999',
    ];
    $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', $payloadJson, 'global-secret');

    $response = $this->call(
        'POST',
        "/api/v1/webhooks/meta/{$tenantId}",
        [],
        [],
        [],
        [
            'HTTP_X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ],
        $payloadJson,
    );

    $response->assertUnauthorized();
    expect(
        Lead::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
    )->toBe(0);
});
