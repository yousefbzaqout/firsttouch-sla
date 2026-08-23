<?php

declare(strict_types=1);

use App\Enums\AiRoutingMode;
use App\Enums\LeadStatus;
use App\Enums\SlaStatus;
use App\Enums\UserRole;
use App\Jobs\DailyFreeCreditGrantJob;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantWorkingHour;
use App\Models\User;
use App\Notifications\ResetPassword;
use App\Services\Ai\CreditManagerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $payload
 */
function postSignedMetaWebhook(string $tenantId, array $payload, string $secret): TestResponse
{
    $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', $payloadJson, $secret);

    return test()->call(
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
}

/**
 * @return array<string, mixed>
 */
function metaLeadPayload(string $externalLeadId, string $name = 'Acme Prospect', string $phone = '+966500001111'): array
{
    return [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'leadgen_data' => [
                        'leadgen_id' => $externalLeadId,
                        'full_name' => $name,
                        'phone_number' => $phone,
                        'email' => 'prospect@acme.test',
                        'campaign_id' => 'cmp-acme-1',
                        'form_id' => 'frm-acme-1',
                    ],
                ],
            ]],
        ]],
    ];
}

it('runs the full autonomous tenant user journey including edge cases', function (): void {
    Cache::flush();
    Notification::fake();
    Http::fake([
        'https://n8n.test/*' => Http::response(['ok' => true], 200),
    ]);
    config([
        'services.n8n.webhook_url' => 'https://n8n.test/webhook/firsttouch-sla-alerts',
        'services.webhooks.meta_secret' => 'global-fallback-should-not-win',
    ]);

    // Monday mid-shift inside default 09:00–17:00 working hours.
    $this->travelTo(Carbon::parse('2026-08-17 10:00:00'));

    /*
    |--------------------------------------------------------------------------
    | PHASE 1 — Onboarding & authentication
    |--------------------------------------------------------------------------
    */
    $registerResponse = $this->postJson('/api/v1/auth/register', [
        'company_name' => 'Acme Marketing Agency',
        'name' => 'Acme Owner',
        'email' => 'owner@acme-agency.test',
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
    ]);

    $registerResponse->assertCreated()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.role', UserRole::Owner->value)
        ->assertJsonPath('user.email', 'owner@acme-agency.test');

    $tenantId = (string) $registerResponse->json('user.tenant_id');
    $tenant = Tenant::query()->findOrFail($tenantId);
    $owner = User::query()->where('email', 'owner@acme-agency.test')->firstOrFail();

    expect($tenant->name)->toBe('Acme Marketing Agency')
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

    // Forgot + reset password for the newly created owner.
    $forgotResponse = $this->postJson('/api/v1/auth/forgot-password', [
        'email' => 'owner@acme-agency.test',
    ]);
    $forgotResponse->assertOk()->assertJsonStructure(['message']);
    Notification::assertSentTo($owner, ResetPassword::class);

    $resetToken = Password::broker()->createToken($owner);
    $resetResponse = $this->postJson('/api/v1/auth/reset-password', [
        'token' => $resetToken,
        'email' => 'owner@acme-agency.test',
        'password' => 'NewPassword1!',
        'password_confirmation' => 'NewPassword1!',
    ]);
    $resetResponse->assertOk()->assertJsonStructure(['message']);
    expect(Hash::check('NewPassword1!', $owner->fresh()->password))->toBeTrue();

    /*
    |--------------------------------------------------------------------------
    | PHASE 2 — Tenant settings & webhook customization
    |--------------------------------------------------------------------------
    */
    $this->withToken((string) $registerResponse->json('token'));

    $acmeSecret = 'acme_secret_999';
    $setting->update([
        'notification_driver' => 'n8n',
        'meta_webhook_secret' => $acmeSecret,
        'sla_timeout_minutes' => 10,
        'ai_routing_mode' => AiRoutingMode::HumanFirst,
        'timezone' => 'UTC',
    ]);
    $setting->refresh();

    expect($setting->notification_driver)->toBe('n8n')
        ->and($setting->meta_webhook_secret)->toBe($acmeSecret)
        ->and($setting->sla_timeout_minutes)->toBe(10);

    User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
    ]);

    /*
    |--------------------------------------------------------------------------
    | PHASE 3 — Lead ingestion (happy path + edge cases)
    |--------------------------------------------------------------------------
    */

    // Happy path — signed with tenant secret.
    $happyLeadId = 'acme-happy-'.Str::lower(Str::random(8));
    $happyResponse = postSignedMetaWebhook(
        $tenant->id,
        metaLeadPayload($happyLeadId, 'Happy Path Lead', '+966500002222'),
        $acmeSecret,
    );
    $happyResponse->assertOk()->assertJson(['status' => 'accepted']);

    $happyLead = Lead::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('external_lead_id', $happyLeadId)
        ->first();

    expect($happyLead)->not->toBeNull()
        ->and($happyLead?->status)->toBe(LeadStatus::New)
        ->and($happyLead?->sla_status)->toBe(SlaStatus::Active)
        ->and($happyLead?->sla_deadline)->not->toBeNull()
        ->and((int) now()->diffInMinutes($happyLead?->sla_deadline))->toBe(10);

    // Edge case 1 — invalid signature.
    $badResponse = postSignedMetaWebhook(
        $tenant->id,
        metaLeadPayload('acme-bad-'.Str::lower(Str::random(6))),
        'wrong_secret',
    );
    $badResponse->assertUnauthorized()->assertJson(['error' => 'unauthorized']);

    // Edge case 2 — out of working hours (02:00) → deadline snaps into next open shift.
    $this->travelTo(Carbon::parse('2026-08-18 02:00:00')); // Tuesday 02:00
    $nightLeadId = 'acme-night-'.Str::lower(Str::random(8));
    $nightResponse = postSignedMetaWebhook(
        $tenant->id,
        metaLeadPayload($nightLeadId, 'Night Lead', '+966500003333'),
        $acmeSecret,
    );
    $nightResponse->assertOk()->assertJson(['status' => 'accepted']);

    $nightLead = Lead::withoutGlobalScopes()
        ->where('external_lead_id', $nightLeadId)
        ->firstOrFail();

    expect($nightLead->sla_status)->toBe(SlaStatus::Frozen)
        ->and($nightLead->sla_deadline)->not->toBeNull()
        ->and($nightLead->sla_started_at)->not->toBeNull()
        ->and($nightLead->sla_started_at?->equalTo(Carbon::parse('2026-08-18 09:00:00')))->toBeTrue()
        ->and($nightLead->sla_deadline?->equalTo(Carbon::parse('2026-08-18 09:10:00')))->toBeTrue();

    /*
    |--------------------------------------------------------------------------
    | PHASE 4 — SLA engine & breach escalation
    |--------------------------------------------------------------------------
    */
    // Resume from happy-path ingestion time + 15 minutes (past the 10-minute SLA).
    $this->travelTo(Carbon::parse('2026-08-17 10:00:00')->addMinutes(15));

    $this->artisan('sla:check-breaches')->assertSuccessful();

    $happyLead->refresh();
    expect($happyLead->sla_status)->toBe(SlaStatus::Breached);

    Http::assertSent(function ($request) use ($happyLead, $tenant): bool {
        if ($request->url() !== 'https://n8n.test/webhook/firsttouch-sla-alerts') {
            return false;
        }

        /** @var array<string, mixed> $body */
        $body = $request->data();

        return ($body['event_type'] ?? null) === 'sla_breach'
            && ($body['type'] ?? null) === 'escalation'
            && ($body['lead_id'] ?? null) === $happyLead->id
            && ($body['tenant_id'] ?? null) === $tenant->id;
    });

    /*
    |--------------------------------------------------------------------------
    | PHASE 5 — Credit exhaustion edge case
    |--------------------------------------------------------------------------
    */
    $setting->update(['credits_balance' => 0]);
    expect((int) $setting->fresh()->credits_balance)->toBe(0);

    $zeroCreditLeadId = 'acme-zero-'.Str::lower(Str::random(8));
    $zeroCreditResponse = postSignedMetaWebhook(
        $tenant->id,
        metaLeadPayload($zeroCreditLeadId, 'Zero Credit Lead', '+966500004444'),
        $acmeSecret,
    );

    // Webhook ingestion must remain healthy even with zero AI credits.
    $zeroCreditResponse->assertOk()->assertJson(['status' => 'accepted']);
    expect(
        Lead::withoutGlobalScopes()->where('external_lead_id', $zeroCreditLeadId)->exists(),
    )->toBeTrue();

    app(CreditManagerService::class)->handleExhaustedCredits($tenant->fresh());

    expect($setting->fresh()->ai_routing_mode)->toBe(AiRoutingMode::HumanOnly);

    Http::assertSent(function ($request) use ($tenant): bool {
        if ($request->url() !== 'https://n8n.test/webhook/firsttouch-sla-alerts') {
            return false;
        }

        /** @var array<string, mixed> $body */
        $body = $request->data();

        return ($body['event_type'] ?? null) === 'credit_exhausted'
            && ($body['tenant_id'] ?? null) === $tenant->id
            && str_contains(strtolower((string) ($body['message'] ?? '')), 'credit');
    });

    /*
    |--------------------------------------------------------------------------
    | PHASE 6 — Daily free credit grant
    |--------------------------------------------------------------------------
    */
    $this->travelTo(Carbon::parse('2026-08-19 00:00:00'));
    expect((int) $setting->fresh()->credits_balance)->toBe(0);

    (new DailyFreeCreditGrantJob)->handle();

    expect((int) $setting->fresh()->credits_balance)->toBe(1);
});
