<?php

declare(strict_types=1);

/**
 * Full-project live simulation for FirstTouch SLA (Apex Media Agency).
 *
 * Covers: all 6 webhook sources, pipeline/assignment/SLA, Filament RBAC,
 * sandbox, outbound webhooks, developer API, credits/mock pay, multi-tenant
 * isolation, context-aware routing, agent online edge cases.
 *
 * Usage:
 *   ./vendor/bin/sail php scripts/full_project_simulation.php
 */

use App\Adapters\Notifications\NotificationDriverFactory;
use App\DTOs\PaymentResponseDTO;
use App\Enums\AiRoutingMode;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\SlaStatus;
use App\Enums\UserRole;
use App\Filament\Pages\DeveloperApiPage;
use App\Filament\Pages\TenantSettingsPage;
use App\Filament\Pages\WebhookSandbox;
use App\Jobs\DispatchOutboundWebhookJob;
use App\Jobs\EscalateLeadSlaJob;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Assignment\ContextAwareRoutingService;
use App\Services\Leads\LeadWorkflowService;
use App\Services\Payments\CreditPurchaseService;
use App\Services\Payments\Drivers\MockPaymentDriver;
use App\Services\Simulation\WebhookSimulatorService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$results = [];
$pass = static function (string $key, bool $ok, string $detail = '') use (&$results): void {
    $results[$key] = ($ok ? 'PASS' : 'FAIL').($detail !== '' ? " — {$detail}" : '');
    echo ($ok ? '[PASS]' : '[FAIL]')." {$key}".($detail !== '' ? " | {$detail}" : '').PHP_EOL;
};

$kernelPost = static function (string $path, string $json, array $server = []): Response {
    $request = Request::create(
        $path,
        'POST',
        [],
        [],
        [],
        array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $server),
        $json,
    );
    /** @var HttpKernel $kernel */
    $kernel = app(HttpKernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return $response;
};

$kernelGet = static function (string $path, array $server = []): Response {
    $request = Request::create($path, 'GET', [], [], [], array_merge([
        'HTTP_ACCEPT' => 'application/json',
    ], $server));
    /** @var HttpKernel $kernel */
    $kernel = app(HttpKernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return $response;
};

$drain = static function (int $seconds = 20): void {
    Artisan::call('queue:work', [
        '--queue' => 'high,notifications,default',
        '--stop-when-empty' => true,
        '--max-time' => max(1, min($seconds, 15)),
        '--tries' => 1,
        '--sleep' => 0,
    ]);
};

$apexId = '01a023da-4174-71c1-9095-4200e631a564';
$tag = Str::lower(Str::ulid()->toString());

echo "=== FirstTouch SLA — Full Project Simulation ===\n";
echo 'Time: '.now()->toIso8601String().PHP_EOL;
echo "Run tag: {$tag}\n\n";

$tenant = Tenant::query()->find($apexId);
if ($tenant === null) {
    fwrite(STDERR, "Apex tenant missing\n");
    exit(1);
}

$setting = TenantSetting::withoutGlobalScopes()->where('tenant_id', $apexId)->firstOrFail();
$owner = User::query()->where('email', 'ahmad@apexmedia.com')->firstOrFail();
$sara = User::query()->where('email', 'sara@apexmedia.com')->firstOrFail();
$omar = User::query()->where('email', 'omar@apexmedia.com')->firstOrFail();

// Snapshot for restore
$snapshot = [
    'ai_routing_mode' => $setting->ai_routing_mode,
    'credits_balance' => $setting->credits_balance,
    'outbound_webhook_url' => $setting->outbound_webhook_url,
    'outbound_webhook_secret' => $setting->outbound_webhook_secret,
    'google_webhook_secret' => $setting->google_webhook_secret,
    'snapchat_webhook_secret' => $setting->snapchat_webhook_secret,
    'universal_webhook_secret' => $setting->universal_webhook_secret,
    'website_api_key' => $setting->website_api_key,
    'is_active' => $tenant->is_active,
    'sara_online' => $sara->is_online,
    'omar_online' => $omar->is_online,
    'sara_tags' => $sara->skills_tags,
    'omar_tags' => $omar->skills_tags,
];

$metaSecret = filled($setting->meta_webhook_secret) ? (string) $setting->meta_webhook_secret : 'apex_secret_key_777';
$tiktokSecret = filled($setting->tiktok_webhook_secret) ? (string) $setting->tiktok_webhook_secret : 'apex_tiktok_secret_888';
$googleSecret = 'apex_google_key_'.substr($tag, -8);
$snapSecret = 'apex_snap_key_'.substr($tag, -8);
$univSecret = 'apex_univ_key_'.substr($tag, -8);
$webApiKey = 'ft_web_'.substr($tag, -16);
$outboundSecret = 'apex_outbound_'.substr($tag, -8);

$setting->update([
    'meta_webhook_secret' => $metaSecret,
    'tiktok_webhook_secret' => $tiktokSecret,
    'google_webhook_secret' => $googleSecret,
    'snapchat_webhook_secret' => $snapSecret,
    'universal_webhook_secret' => $univSecret,
    'website_api_key' => $webApiKey,
    'outbound_webhook_url' => 'https://crm.sim.firsttouch.test/hooks/apex',
    'outbound_webhook_secret' => $outboundSecret,
    'ai_routing_mode' => AiRoutingMode::HumanFirst,
    'credits_balance' => max(20, (int) $setting->credits_balance),
    'is_active' => true,
]);
$tenant->update(['is_active' => true]);

$sara->update(['is_online' => true, 'is_active' => true, 'skills_tags' => ['ads', 'meta']]);
$omar->update(['is_online' => true, 'is_active' => true, 'skills_tags' => ['tiktok', 'creative']]);

Cache::flush();
Http::fake([
    'https://crm.sim.firsttouch.test/*' => Http::response(['ok' => true], 200),
]);

echo "Tenant: {$tenant->name} ({$apexId})\n";
echo "Mode forced: human_first (deterministic assignment for sim)\n";
echo "Owner: {$owner->email}\n\n";

// ===========================================================================
// A) WEBHOOK INGESTION — all 6 sources (happy)
// ===========================================================================
echo "=== A) Webhook ingestion (all sources) ===\n";

// Meta
$metaExt = "full-meta-{$tag}";
$metaBody = json_encode([
    'object' => 'page',
    'entry' => [[
        'changes' => [[
            'value' => [
                'leadgen_data' => [
                    'leadgen_id' => $metaExt,
                    'full_name' => "FullSim Meta {$tag}",
                    'phone_number' => '+9665100'.random_int(1000, 9999),
                    'email' => "meta.{$tag}@example.test",
                    'inquiry' => 'Meta ads retainer',
                ],
            ],
        ]],
    ]],
], JSON_THROW_ON_ERROR);
$metaRes = $kernelPost(
    "/api/v1/webhooks/meta/{$apexId}",
    $metaBody,
    ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $metaBody, $metaSecret)],
);
$pass('A_meta_accept', $metaRes->getStatusCode() === 200 && str_contains($metaRes->getContent(), 'accepted'), 'status='.$metaRes->getStatusCode());

// TikTok
$ttExt = "full-tt-{$tag}";
$ttBody = json_encode([
    'event' => 'lead.create',
    'data' => [
        'lead_id' => $ttExt,
        'name' => "FullSim TikTok {$tag}",
        'phone' => '+9665101'.random_int(1000, 9999),
        'email' => "tt.{$tag}@example.test",
        'inquiry' => 'TikTok lead gen',
    ],
], JSON_THROW_ON_ERROR);
$ttRes = $kernelPost(
    "/api/v1/webhooks/tiktok/{$apexId}",
    $ttBody,
    ['HTTP_X_TIKTOK_SIGNATURE' => hash_hmac('sha256', $ttBody, $tiktokSecret)],
);
$pass('A_tiktok_accept', $ttRes->getStatusCode() === 200 && str_contains($ttRes->getContent(), 'accepted'), 'status='.$ttRes->getStatusCode());

// Google
$gExt = "full-gads-{$tag}";
$gBody = json_encode([
    'lead_id' => $gExt,
    'campaign_id' => 'g-camp',
    'form_id' => 'g-form',
    'google_key' => $googleSecret,
    'user_column_data' => [
        ['column_id' => 'FULL_NAME', 'string_value' => "FullSim Google {$tag}"],
        ['column_id' => 'PHONE_NUMBER', 'string_value' => '+9665102'.random_int(1000, 9999)],
        ['column_id' => 'EMAIL', 'string_value' => "google.{$tag}@example.test"],
    ],
], JSON_THROW_ON_ERROR);
$gRes = $kernelPost("/api/v1/webhooks/google/{$apexId}", $gBody);
$pass('A_google_accept', $gRes->getStatusCode() === 200 && str_contains($gRes->getContent(), 'accepted'), 'status='.$gRes->getStatusCode());

// Snapchat
$sExt = "full-snap-{$tag}";
$sBody = json_encode([
    'lead_id' => $sExt,
    'full_name' => "FullSim Snap {$tag}",
    'phone_number' => '+9665103'.random_int(1000, 9999),
    'email' => "snap.{$tag}@example.test",
    'form_id' => 'snap-form',
    'field_inputs' => [
        ['name' => 'FULL_NAME', 'value' => "FullSim Snap {$tag}"],
        ['name' => 'PHONE_NUMBER', 'value' => '+9665103'.random_int(1000, 9999)],
    ],
], JSON_THROW_ON_ERROR);
$sRes = $kernelPost(
    "/api/v1/webhooks/snapchat/{$apexId}",
    $sBody,
    ['HTTP_X_SNAPCHAT_SIGNATURE' => $snapSecret],
);
$pass('A_snapchat_accept', $sRes->getStatusCode() === 200 && str_contains($sRes->getContent(), 'accepted'), 'status='.$sRes->getStatusCode());

// Universal
$uExt = "full-univ-{$tag}";
$uBody = json_encode([
    'lead_id' => $uExt,
    'full_name' => "FullSim Zapier {$tag}",
    'phone_number' => '+9665104'.random_int(1000, 9999),
    'email_address' => "zap.{$tag}@example.test",
], JSON_THROW_ON_ERROR);
$uRes = $kernelPost(
    "/api/v1/webhooks/universal/{$apexId}",
    $uBody,
    ['HTTP_X_UNIVERSAL_SECRET' => $univSecret],
);
$pass('A_universal_accept', $uRes->getStatusCode() === 200 && str_contains($uRes->getContent(), 'accepted'), 'status='.$uRes->getStatusCode());

// Website
$wBody = json_encode([
    'name' => "FullSim Website {$tag}",
    'phone' => '+9665105'.random_int(1000, 9999),
    'email' => "web.{$tag}@example.test",
    'inquiry' => 'Contact form interest',
    'page_url' => 'https://apex.example/contact',
    'lead_id' => "full-web-{$tag}",
], JSON_THROW_ON_ERROR);
$wRes = $kernelPost(
    "/api/v1/webhooks/website/{$apexId}",
    $wBody,
    ['HTTP_X_WEBSITE_API_KEY' => $webApiKey],
);
$pass(
    'A_website_201',
    $wRes->getStatusCode() === 201 && str_contains($wRes->getContent(), 'Lead received successfully'),
    'status='.$wRes->getStatusCode().' body='.$wRes->getContent(),
);

$drain(25);

$sourcesOk = true;
foreach (
    [
        [$metaExt, LeadSource::Meta],
        [$ttExt, LeadSource::TikTok],
        [$gExt, LeadSource::Google],
        [$sExt, LeadSource::Snapchat],
        [$uExt, LeadSource::Universal],
        ["full-web-{$tag}", LeadSource::Website],
    ] as [$ext, $src]
) {
    $lead = Lead::withoutGlobalScopes()->where('tenant_id', $apexId)->where('external_lead_id', $ext)->first();
    $ok = $lead !== null && $lead->source === $src;
    $sourcesOk = $sourcesOk && $ok;
    $pass(
        'A_persisted_'.$src->value,
        $ok,
        $lead ? "id={$lead->id} status={$lead->status->value}" : 'missing',
    );
}

// ===========================================================================
// B) WEBHOOK EDGE CASES
// ===========================================================================
echo "\n=== B) Webhook edge cases ===\n";

// Bad HMAC Meta
$badMeta = json_encode(['entry' => [['changes' => [['value' => ['leadgen_data' => [
    'leadgen_id' => "bad-meta-{$tag}",
    'full_name' => 'Bad',
    'phone_number' => '+1',
]]]]]]], JSON_THROW_ON_ERROR);
$badMetaRes = $kernelPost(
    "/api/v1/webhooks/meta/{$apexId}",
    $badMeta,
    ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $badMeta, 'wrong')],
);
$pass('B_meta_bad_hmac_401', $badMetaRes->getStatusCode() === 401);

// Bad Google key
$badG = json_encode([
    'lead_id' => "bad-g-{$tag}",
    'google_key' => 'wrong',
    'user_column_data' => [['column_id' => 'FULL_NAME', 'string_value' => 'X']],
], JSON_THROW_ON_ERROR);
$pass('B_google_bad_key_401', $kernelPost("/api/v1/webhooks/google/{$apexId}", $badG)->getStatusCode() === 401);

// Bad Snap
$badS = json_encode(['lead_id' => "bad-s-{$tag}", 'full_name' => 'X', 'phone_number' => '+1'], JSON_THROW_ON_ERROR);
$pass(
    'B_snap_bad_key_401',
    $kernelPost("/api/v1/webhooks/snapchat/{$apexId}", $badS, ['HTTP_X_SNAPCHAT_SIGNATURE' => 'wrong'])->getStatusCode() === 401,
);

// Universal handshake (no job / no lead)
$pingRes = $kernelPost(
    "/api/v1/webhooks/universal/{$apexId}",
    json_encode(['event' => 'ping'], JSON_THROW_ON_ERROR),
    ['HTTP_X_ZAPIER_SECRET' => $univSecret],
);
$pass(
    'B_universal_ping_connected',
    $pingRes->getStatusCode() === 200 && str_contains($pingRes->getContent(), 'connected'),
    $pingRes->getContent(),
);
$pingLead = Lead::withoutGlobalScopes()->where('tenant_id', $apexId)->where('external_lead_id', 'like', "%ping%{$tag}%")->exists();
$pass('B_universal_ping_no_lead', $pingLead === false);

// Universal bad secret
$pass(
    'B_universal_bad_401',
    $kernelPost(
        "/api/v1/webhooks/universal/{$apexId}",
        json_encode(['name' => 'X', 'phone' => '+1'], JSON_THROW_ON_ERROR),
        ['HTTP_X_UNIVERSAL_SECRET' => 'wrong'],
    )->getStatusCode() === 401,
);

// Website 422 missing phone
$w422 = $kernelPost(
    "/api/v1/webhooks/website/{$apexId}",
    json_encode(['name' => 'No Phone'], JSON_THROW_ON_ERROR),
    ['HTTP_X_API_KEY' => $webApiKey],
);
$pass('B_website_422_missing_phone', $w422->getStatusCode() === 422, 'status='.$w422->getStatusCode());

// Website 401 message shape
$w401 = $kernelPost(
    "/api/v1/webhooks/website/{$apexId}",
    json_encode(['name' => 'X', 'phone' => '+1'], JSON_THROW_ON_ERROR),
    ['HTTP_X_WEBSITE_API_KEY' => 'wrong'],
);
$pass(
    'B_website_401_shape',
    $w401->getStatusCode() === 401 && str_contains($w401->getContent(), 'Invalid API Key'),
    $w401->getContent(),
);

// Idempotency Meta
$dupBody = json_encode([
    'entry' => [[
        'changes' => [[
            'value' => [
                'leadgen_data' => [
                    'leadgen_id' => "dup-{$tag}",
                    'full_name' => "Dup {$tag}",
                    'phone_number' => '+96651069999',
                ],
            ],
        ]],
    ]],
], JSON_THROW_ON_ERROR);
$dupSig = ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $dupBody, $metaSecret)];
$dup1 = $kernelPost("/api/v1/webhooks/meta/{$apexId}", $dupBody, $dupSig);
$dup2 = $kernelPost("/api/v1/webhooks/meta/{$apexId}", $dupBody, $dupSig);
$pass(
    'B_idempotent_duplicate',
    $dup1->getStatusCode() === 200
        && $dup2->getStatusCode() === 200
        && str_contains($dup2->getContent(), 'already_processed'),
    'second='.$dup2->getContent(),
);

// Ghost tenant
$ghost = (string) Str::uuid();
$ghostBody = json_encode([
    'entry' => [[
        'changes' => [[
            'value' => [
                'leadgen_data' => [
                    'leadgen_id' => "ghost-{$tag}",
                    'full_name' => 'Ghost',
                    'phone_number' => '+1',
                ],
            ],
        ]],
    ]],
], JSON_THROW_ON_ERROR);
$ghostRes = $kernelPost(
    "/api/v1/webhooks/meta/{$ghost}",
    $ghostBody,
    ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $ghostBody, $metaSecret)],
);
$pass('B_ghost_tenant_404', $ghostRes->getStatusCode() === 404 && str_contains($ghostRes->getContent(), 'tenant_not_found'));

// Inactive tenant
$tenant->update(['is_active' => false]);
$inactBody = json_encode([
    'entry' => [[
        'changes' => [[
            'value' => [
                'leadgen_data' => [
                    'leadgen_id' => "inact-{$tag}",
                    'full_name' => 'Inactive',
                    'phone_number' => '+1',
                ],
            ],
        ]],
    ]],
], JSON_THROW_ON_ERROR);
$inactRes = $kernelPost(
    "/api/v1/webhooks/meta/{$apexId}",
    $inactBody,
    ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $inactBody, $metaSecret)],
);
$tenant->update(['is_active' => true]);
$pass('B_inactive_tenant_404', $inactRes->getStatusCode() === 404);

// Invalid source
$invRes = $kernelPost("/api/v1/webhooks/notasource/{$apexId}", '{}');
$pass('B_invalid_source_400', $invRes->getStatusCode() === 400);

// Global fallback google (tenant secret null temporarily)
$setting->update(['google_webhook_secret' => null]);
config(['services.webhooks.google_secret' => 'global-g-'.$tag]);
$fbBody = json_encode([
    'lead_id' => "g-fallback-{$tag}",
    'google_key' => 'global-g-'.$tag,
    'user_column_data' => [
        ['column_id' => 'FULL_NAME', 'string_value' => 'Fallback G'],
        ['column_id' => 'PHONE_NUMBER', 'string_value' => '+96651071111'],
    ],
], JSON_THROW_ON_ERROR);
$fbRes = $kernelPost("/api/v1/webhooks/google/{$apexId}", $fbBody);
$setting->update(['google_webhook_secret' => $googleSecret]);
$pass('B_google_global_fallback', $fbRes->getStatusCode() === 200, 'status='.$fbRes->getStatusCode());

$drain(15);

// ===========================================================================
// C) SANDBOX + FILAMENT RBAC
// ===========================================================================
echo "\n=== C) Sandbox + Filament RBAC ===\n";

auth()->login($owner);
$pass('C_owner_sandbox_access', WebhookSandbox::canAccess() === true);
$pass('C_owner_settings_access', TenantSettingsPage::canAccess() === true);
$pass('C_owner_developer_access', DeveloperApiPage::canAccess() === true);

$sim = app(WebhookSimulatorService::class);
$simName = "SandboxSim {$tag}";
$simOk = $sim->simulate($apexId, 'meta', [
    'name' => $simName,
    'phone' => '+9665108'.random_int(1000, 9999),
    'inquiry' => 'sandbox path',
]);
$drain(20);
$simLead = Lead::withoutGlobalScopes()->where('tenant_id', $apexId)->where('name', $simName)->first();
$pass('C_simulator_meta', $simOk && $simLead !== null, $simLead ? "id={$simLead->id}" : 'missing');

auth()->login($sara);
$pass('C_rep_sandbox_denied', WebhookSandbox::canAccess() === false);
$pass('C_rep_settings_denied', TenantSettingsPage::canAccess() === false);
$pass('C_rep_developer_denied', DeveloperApiPage::canAccess() === false);

// ===========================================================================
// D) ASSIGNMENT / CONTEXT / ONLINE
// ===========================================================================
echo "\n=== D) Assignment, context routing, online ===\n";

$router = app(ContextAwareRoutingService::class);
$ctxLead = Lead::withoutGlobalScopes()->create([
    'tenant_id' => $apexId,
    'source' => LeadSource::Manual,
    'external_lead_id' => "ctx-{$tag}",
    'name' => "Context Lead {$tag}",
    'phone' => '+96651090001',
    'status' => LeadStatus::New,
    'sla_status' => SlaStatus::Active,
    'routing_tags' => ['tiktok', 'creative'],
    'meta_data' => [],
]);
$picked = $router->assign($ctxLead);
$pass(
    'D_context_prefers_omar',
    $picked !== null && (int) $picked->id === (int) $omar->id,
    $picked ? "picked={$picked->email}" : 'null',
);

$sara->update(['is_online' => false]);
$omar->update(['is_online' => false]);
User::query()
    ->where('tenant_id', $apexId)
    ->where('role', UserRole::SalesRep)
    ->update(['is_online' => false]);
$offlineLead = Lead::withoutGlobalScopes()->create([
    'tenant_id' => $apexId,
    'source' => LeadSource::Manual,
    'external_lead_id' => "offline-{$tag}",
    'name' => "Offline Lead {$tag}",
    'phone' => '+96651090002',
    'status' => LeadStatus::New,
    'sla_status' => SlaStatus::Active,
    'routing_tags' => ['ads'],
    'meta_data' => [],
]);
$pickedOffline = $router->assign($offlineLead);
$pass('D_all_offline_returns_null', $pickedOffline === null, $pickedOffline ? 'picked='.$pickedOffline->email : 'null');
User::query()
    ->where('tenant_id', $apexId)
    ->where('role', UserRole::SalesRep)
    ->update(['is_online' => true]);
$sara->update(['is_online' => true]);
$omar->update(['is_online' => true]);

// Human-first ingest should assign someone online
$assignedLead = Lead::withoutGlobalScopes()
    ->where('tenant_id', $apexId)
    ->where('external_lead_id', $metaExt)
    ->first();
$pass(
    'D_human_first_assigned',
    $assignedLead !== null && $assignedLead->assigned_user_id !== null,
    $assignedLead ? 'assignee='.(string) $assignedLead->assigned_user_id : 'missing',
);

// ===========================================================================
// E) WORKFLOW / SLA
// ===========================================================================
echo "\n=== E) Workflow + SLA ===\n";

$workflow = app(LeadWorkflowService::class);
$wfLead = Lead::withoutGlobalScopes()
    ->where('tenant_id', $apexId)
    ->where('external_lead_id', $ttExt)
    ->first();

if ($wfLead !== null) {
    $assignee = User::query()->find($wfLead->assigned_user_id) ?? $sara;
    if ($wfLead->assigned_user_id === null) {
        $wfLead = $workflow->claim($wfLead, $sara);
        $assignee = $sara;
    }
    $contacted = $workflow->markContacted($wfLead->fresh() ?? $wfLead, $assignee);
    $pass(
        'E_mark_contacted_sla_met',
        $contacted->status === LeadStatus::Contacted && $contacted->sla_status === SlaStatus::Met,
        "status={$contacted->status->value} sla={$contacted->sla_status->value}",
    );
} else {
    $pass('E_mark_contacted_sla_met', false, 'tiktok lead missing');
}

// Force breach candidate
$breachLead = Lead::withoutGlobalScopes()->create([
    'tenant_id' => $apexId,
    'source' => LeadSource::Manual,
    'external_lead_id' => "breach-{$tag}",
    'name' => "Breach Lead {$tag}",
    'phone' => '+96651090003',
    'assigned_user_id' => $sara->id,
    'status' => LeadStatus::Claimed,
    'sla_status' => SlaStatus::Active,
    'sla_deadline' => now()->subMinutes(10),
    'sla_started_at' => now()->subMinutes(30),
    'meta_data' => [],
]);
Artisan::call('sla:check-breaches');
$drain(10);
$breachLead->refresh();
$pass(
    'E_sla_breach_command',
    $breachLead->sla_status === SlaStatus::Breached,
    "sla={$breachLead->sla_status->value}",
);

try {
    (new EscalateLeadSlaJob($breachLead->id))->handle(app(NotificationDriverFactory::class));
    $pass('E_sla_escalate_job_runs', true);
} catch (Throwable $e) {
    $pass('E_sla_escalate_job_runs', false, $e->getMessage());
}

// ===========================================================================
// F) OUTBOUND WEBHOOK
// ===========================================================================
echo "\n=== F) Outbound CRM webhook ===\n";

$outLead = Lead::withoutGlobalScopes()->create([
    'tenant_id' => $apexId,
    'source' => LeadSource::Website,
    'external_lead_id' => "out-{$tag}",
    'name' => "Outbound Lead {$tag}",
    'phone' => '+96651090004',
    'assigned_user_id' => $sara->id,
    'status' => LeadStatus::Claimed,
    'sla_status' => SlaStatus::Active,
    'meta_data' => [],
]);
$outLead->update(['status' => LeadStatus::Contacted]);
$drain(10);
(new DispatchOutboundWebhookJob($outLead->id, 'lead.contacted'))->handle();

$outboundSent = false;
foreach (Http::recorded() as [$request, $response]) {
    if (! str_contains($request->url(), 'crm.sim.firsttouch.test')) {
        continue;
    }
    $body = $request->body();
    $sig = $request->header('X-FirstTouch-Signature')[0] ?? '';
    if (hash_equals(hash_hmac('sha256', $body, $outboundSecret), $sig) && str_contains($body, (string) $outLead->id)) {
        $outboundSent = true;
        break;
    }
}
$pass('F_outbound_hmac_sent', $outboundSent === true);

// ===========================================================================
// G) DEVELOPER API (Sanctum)
// ===========================================================================
echo "\n=== G) Developer API ===\n";

$token = $owner->createToken('full-sim-'.$tag, ['developer:*'])->plainTextToken;
$listRes = $kernelGet('/api/v1/developer/leads', [
    'HTTP_AUTHORIZATION' => 'Bearer '.$token,
]);
$pass('G_developer_leads_list', $listRes->getStatusCode() === 200, 'status='.$listRes->getStatusCode());

$analytics = $kernelGet('/api/v1/developer/analytics/sla-summary', [
    'HTTP_AUTHORIZATION' => 'Bearer '.$token,
]);
$pass('G_developer_sla_summary', $analytics->getStatusCode() === 200, 'status='.$analytics->getStatusCode());

try {
    if (auth('web')->check()) {
        auth('web')->logout();
    }
} catch (Throwable) {
}
auth()->forgetGuards();
$unauth = $kernelGet('/api/v1/developer/leads');
$pass('G_developer_unauth_401', in_array($unauth->getStatusCode(), [401, 403], true), 'status='.$unauth->getStatusCode());

// Cross-tenant isolation via API: create Beacon lead if Beacon exists
$beacon = Tenant::query()->where('name', 'like', 'Beacon%')->first();
if ($beacon !== null) {
    $beaconLead = Lead::withoutGlobalScopes()->create([
        'tenant_id' => $beacon->id,
        'source' => LeadSource::Manual,
        'external_lead_id' => "beacon-{$tag}",
        'name' => "Beacon Secret {$tag}",
        'phone' => '+96659999999',
        'status' => LeadStatus::New,
        'sla_status' => SlaStatus::Active,
        'meta_data' => [],
    ]);
    $showOther = $kernelGet('/api/v1/developer/leads/'.$beaconLead->id, [
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    ]);
    $pass('G_developer_cross_tenant_blocked', in_array($showOther->getStatusCode(), [403, 404], true), 'status='.$showOther->getStatusCode());
} else {
    $pass('G_developer_cross_tenant_blocked', true, 'beacon absent — skipped');
}

// ===========================================================================
// H) CREDITS / MOCK PAYMENT
// ===========================================================================
echo "\n=== H) Credits + mock payment ===\n";

$setting->refresh();
$beforeCredits = (int) $setting->credits_balance;
$setting->update([
    'credits_balance' => 0,
    'ai_routing_mode' => AiRoutingMode::HumanOnly,
]);

$checkoutUrl = app(MockPaymentDriver::class)->createCheckoutSession($tenant, 50, 49.99);
$pass('H_mock_checkout_url', is_string($checkoutUrl) && str_contains($checkoutUrl, 'payments/mock'), substr($checkoutUrl, 0, 80));

$dto = new PaymentResponseDTO(
    success: true,
    transactionId: 'sim-pay-'.$tag,
    creditsPurchased: 50,
    amountPaid: 49.99,
    currency: 'USD',
);
app(CreditPurchaseService::class)->processSuccessfulPayment($apexId, 'mock', $dto);
$setting->refresh();
$pass(
    'H_payment_restores_credits_and_ai',
    (int) $setting->credits_balance >= 50 && $setting->ai_routing_mode === AiRoutingMode::AiFirst,
    'credits='.$setting->credits_balance.' mode='.$setting->ai_routing_mode->value,
);

// Keep human_first for remaining asserts consistency in restore
$setting->update([
    'credits_balance' => max(20, $beforeCredits),
    'ai_routing_mode' => AiRoutingMode::HumanFirst,
]);
$pass('H_credits_restored_for_cleanup', (int) $setting->credits_balance >= 20, 'credits='.$setting->credits_balance);

// ===========================================================================
// I) MULTI-TENANT ISOLATION
// ===========================================================================
echo "\n=== I) Multi-tenant isolation ===\n";

$leak = false;
if ($beacon !== null) {
    $leak = Lead::withoutGlobalScopes()
        ->where('tenant_id', $beacon->id)
        ->where(function ($q) use ($tag): void {
            $q->where('name', 'like', "%{$tag}%")
                ->where('name', 'not like', 'Beacon%');
        })
        ->exists();
}
$pass('I_no_apex_tag_leak_to_beacon', $leak === false);

$apexOnly = Lead::withoutGlobalScopes()
    ->where('external_lead_id', $metaExt)
    ->where('tenant_id', '!=', $apexId)
    ->doesntExist();
$pass('I_meta_lead_only_on_apex', $apexOnly);

// ===========================================================================
// J) CATCH-ALL ROUTE + WEBSITE FORM-DATA
// ===========================================================================
echo "\n=== J) Catch-all route + form-data ===\n";

$formReq = Request::create(
    "/api/v1/webhooks/website/{$apexId}",
    'POST',
    [
        'name' => "FormData {$tag}",
        'phone' => '+9665110'.random_int(1000, 9999),
        'email' => "form.{$tag}@example.test",
    ],
    [],
    [],
    [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_API_KEY' => $webApiKey,
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ],
);
/** @var HttpKernel $kernel */
$kernel = app(HttpKernel::class);
$formRes = $kernel->handle($formReq);
$kernel->terminate($formReq, $formRes);
$pass('J_website_form_data_201', $formRes->getStatusCode() === 201, 'status='.$formRes->getStatusCode());

$catchBody = json_encode([
    'lead_id' => "catch-univ-{$tag}",
    'name' => "CatchAll {$tag}",
    'phone' => '+96651110002',
], JSON_THROW_ON_ERROR);
$catchRes = $kernelPost(
    "/api/v1/webhooks/universal/{$apexId}",
    $catchBody,
    ['HTTP_AUTHORIZATION' => 'Bearer '.$univSecret],
);
$pass('J_universal_bearer_auth', $catchRes->getStatusCode() === 200, 'status='.$catchRes->getStatusCode());

$drain(15);

// ===========================================================================
// RESTORE SNAPSHOT
// ===========================================================================
echo "\n=== Restoring Apex settings snapshot ===\n";
$setting->update([
    'ai_routing_mode' => $snapshot['ai_routing_mode'],
    'credits_balance' => $snapshot['credits_balance'],
    'outbound_webhook_url' => $snapshot['outbound_webhook_url'],
    'outbound_webhook_secret' => $snapshot['outbound_webhook_secret'],
    'google_webhook_secret' => $snapshot['google_webhook_secret'] ?: $googleSecret,
    'snapchat_webhook_secret' => $snapshot['snapchat_webhook_secret'] ?: $snapSecret,
    'universal_webhook_secret' => $snapshot['universal_webhook_secret'] ?: $univSecret,
    'website_api_key' => $snapshot['website_api_key'] ?: $webApiKey,
]);
$tenant->update(['is_active' => (bool) $snapshot['is_active']]);
$sara->update([
    'is_online' => (bool) $snapshot['sara_online'],
    'skills_tags' => $snapshot['sara_tags'],
]);
$omar->update([
    'is_online' => (bool) $snapshot['omar_online'],
    'skills_tags' => $snapshot['omar_tags'],
]);
$owner->tokens()->where('name', 'like', 'full-sim-'.$tag.'%')->delete();

// ===========================================================================
// SUMMARY
// ===========================================================================
echo "\n=== SUMMARY ===\n";
$failed = 0;
$passed = 0;
foreach ($results as $key => $value) {
    echo str_pad($key, 40).' '.$value.PHP_EOL;
    if (str_starts_with($value, 'FAIL')) {
        $failed++;
    } else {
        $passed++;
    }
}

echo "\nTotal: ".count($results)." | PASS: {$passed} | FAIL: {$failed}\n";
echo 'Elapsed: '.number_format(microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)), 2)."s\n";

exit($failed === 0 ? 0 : 1);
