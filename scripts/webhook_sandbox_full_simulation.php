<?php

declare(strict_types=1);

/**
 * Full Simulate Webhook simulation for Apex Media Agency.
 *
 * Exercises WebhookSimulatorService (same path as Filament "Simulate Webhook")
 * plus Filament page RBAC and edge cases.
 *
 * Usage:
 *   ./vendor/bin/sail artisan tinker --execute="require 'scripts/webhook_sandbox_full_simulation.php';"
 */

use App\Enums\LeadSource;
use App\Exceptions\MissingWebhookSecretException;
use App\Filament\Pages\WebhookSandbox;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Simulation\WebhookSimulatorService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$results = [];
$pass = static function (string $key, bool $ok, string $detail = '') use (&$results): void {
    $results[$key] = $ok ? ('PASS'.($detail !== '' ? " — {$detail}" : '')) : ('FAIL'.($detail !== '' ? " — {$detail}" : ''));
    echo ($ok ? '[PASS]' : '[FAIL]')." {$key}".($detail !== '' ? " | {$detail}" : '').PHP_EOL;
};

echo "=== Webhook Sandbox Full Simulation ===\n";
echo 'Time: '.now()->toIso8601String().PHP_EOL;

$apexId = '01a023da-4174-71c1-9095-4200e631a564';
$tenant = Tenant::query()->find($apexId);

if ($tenant === null) {
    fwrite(STDERR, "FAIL: Apex tenant not found ({$apexId})\n");
    exit(1);
}

$owner = User::query()->where('email', 'ahmad@apexmedia.com')->firstOrFail();
$rep = User::query()->where('email', 'sara@apexmedia.com')->first();

$setting = TenantSetting::withoutGlobalScopes()->where('tenant_id', $apexId)->firstOrFail();

// Ensure secrets exist (write-only form normally; set for sim reliability).
$metaSecret = is_string($setting->meta_webhook_secret) && $setting->meta_webhook_secret !== ''
    ? $setting->meta_webhook_secret
    : 'apex_secret_key_777';
$tiktokSecret = is_string($setting->tiktok_webhook_secret) && $setting->tiktok_webhook_secret !== ''
    ? $setting->tiktok_webhook_secret
    : 'apex_tiktok_secret_888';

$setting->update([
    'meta_webhook_secret' => $metaSecret,
    'tiktok_webhook_secret' => $tiktokSecret,
]);
$setting->refresh();

echo "Tenant: {$tenant->name} ({$apexId})\n";
echo "Owner: {$owner->email}\n";
echo 'Meta secret set: '.(filled($setting->meta_webhook_secret) ? 'yes' : 'no').PHP_EOL;
echo 'TikTok secret set: '.(filled($setting->tiktok_webhook_secret) ? 'yes' : 'no').PHP_EOL;
echo PHP_EOL;

$simulator = app(WebhookSimulatorService::class);
$tag = Str::lower(Str::ulid()->toString());

$drain = static function (int $seconds = 20): void {
    Artisan::call('queue:work', [
        '--queue' => 'high,notifications,default',
        '--stop-when-empty' => true,
        '--max-time' => max(1, min($seconds, 15)),
        '--tries' => 1,
        '--sleep' => 0,
    ]);
};

// ---------------------------------------------------------------------------
// 1) Happy path — Meta via simulator (Filament button path)
// ---------------------------------------------------------------------------
echo "[1] Meta Simulate Webhook happy path...\n";
$metaName = "Sandbox Meta {$tag}";
$metaPhone = '+96650001'.random_int(1000, 9999);
$metaOk = $simulator->simulate($apexId, 'meta', [
    'name' => $metaName,
    'phone' => $metaPhone,
    'inquiry' => 'I want Meta ads retainer pricing for Q3.',
]);
$drain(25);
$metaLead = Lead::withoutGlobalScopes()
    ->where('tenant_id', $apexId)
    ->where('name', $metaName)
    ->where('phone', $metaPhone)
    ->latest('created_at')
    ->first();
$pass(
    'meta_simulate_ok',
    $metaOk && $metaLead !== null,
    $metaLead ? "lead={$metaLead->id} status={$metaLead->status->value} source={$metaLead->source->value}" : 'lead_missing',
);
$pass('meta_source_persisted', $metaLead !== null && $metaLead->source === LeadSource::Meta);
$pass(
    'meta_inquiry_in_meta_data',
    $metaLead !== null && str_contains(
        json_encode($metaLead->meta_data ?? [], JSON_THROW_ON_ERROR),
        'Meta ads retainer',
    ),
);

// ---------------------------------------------------------------------------
// 2) Happy path — TikTok via simulator
// ---------------------------------------------------------------------------
echo "[2] TikTok Simulate Webhook happy path...\n";
$ttName = "Sandbox TikTok {$tag}";
$ttPhone = '+96650002'.random_int(1000, 9999);
$ttOk = $simulator->simulate($apexId, 'tiktok', [
    'name' => $ttName,
    'phone' => $ttPhone,
    'inquiry' => 'Book a demo for TikTok lead gen.',
]);
$drain(25);
$ttLead = Lead::withoutGlobalScopes()
    ->where('tenant_id', $apexId)
    ->where('name', $ttName)
    ->where('phone', $ttPhone)
    ->latest('created_at')
    ->first();
$pass(
    'tiktok_simulate_ok',
    $ttOk && $ttLead !== null,
    $ttLead ? "lead={$ttLead->id} status={$ttLead->status->value}" : 'lead_missing',
);
$pass('tiktok_source_persisted', $ttLead !== null && $ttLead->source === LeadSource::TikTok);

// ---------------------------------------------------------------------------
// 3) Filament Livewire — owner success notification path
// ---------------------------------------------------------------------------
echo "[3] Filament WebhookSandbox as owner...\n";
auth()->login($owner);
$pass('filament_owner_can_access', WebhookSandbox::canAccess() === true);

$filamentName = "Filament Sim {$tag}";
$filamentPhone = '+96650003'.random_int(1000, 9999);
$filamentInquiry = "Filament UI simulate path — {$tag}";
$beforeCount = Lead::withoutGlobalScopes()->where('tenant_id', $apexId)->count();
Livewire::test(WebhookSandbox::class)
    ->set('data', [
        'source' => 'meta',
        'name' => $filamentName,
        'phone' => $filamentPhone,
        'inquiry' => $filamentInquiry,
    ])
    ->call('simulateWebhook');
$drain(25);
$filamentLead = Lead::withoutGlobalScopes()
    ->where('tenant_id', $apexId)
    ->where('name', $filamentName)
    ->where('phone', $filamentPhone)
    ->latest('created_at')
    ->first();
$afterCount = Lead::withoutGlobalScopes()->where('tenant_id', $apexId)->count();
$pass(
    'filament_owner_simulate_creates_lead',
    $filamentLead !== null && $afterCount > $beforeCount,
    "leads {$beforeCount}→{$afterCount}".($filamentLead ? " id={$filamentLead->id}" : ''),
);

// ---------------------------------------------------------------------------
// 4) Edge: sales_rep forbidden
// ---------------------------------------------------------------------------
echo "[4] Edge: sales_rep cannot access sandbox...\n";
if ($rep instanceof User) {
    auth()->login($rep);
    $pass('filament_rep_cannot_access', WebhookSandbox::canAccess() === false);
} else {
    $pass('filament_rep_cannot_access', false, 'sara@apexmedia.com missing');
}

// ---------------------------------------------------------------------------
// 5) Edge: missing webhook secret
// ---------------------------------------------------------------------------
echo "[5] Edge: missing secret throws MissingWebhookSecretException...\n";
$savedMeta = $setting->meta_webhook_secret;
$setting->update(['meta_webhook_secret' => null]);
$threwMissing = false;
try {
    $simulator->simulate($apexId, 'meta', [
        'name' => 'No Secret',
        'phone' => '+10000000000',
        'inquiry' => 'should fail',
    ]);
} catch (MissingWebhookSecretException) {
    $threwMissing = true;
}
$setting->update(['meta_webhook_secret' => $savedMeta]);
$pass('missing_secret_throws', $threwMissing);

auth()->login($owner);
$setting->update(['tiktok_webhook_secret' => null]);
$uiMissingCaught = false;
$uiBefore = Lead::withoutGlobalScopes()->where('tenant_id', $apexId)->count();
try {
    Livewire::test(WebhookSandbox::class)
        ->set('data', [
            'source' => 'tiktok',
            'name' => 'No Secret UI',
            'phone' => '+10000000001',
            'inquiry' => 'should notify danger',
        ])
        ->call('simulateWebhook');
    $uiMissingCaught = true; // page swallows MissingWebhookSecretException
} catch (MissingWebhookSecretException) {
    $uiMissingCaught = false;
}
$uiAfter = Lead::withoutGlobalScopes()->where('tenant_id', $apexId)->count();
$setting->update(['tiktok_webhook_secret' => $tiktokSecret]);
$pass(
    'filament_missing_secret_no_lead',
    $uiMissingCaught && $uiAfter === $uiBefore,
    "leads {$uiBefore}→{$uiAfter}",
);

// ---------------------------------------------------------------------------
// 6) Edge: unsupported source
// ---------------------------------------------------------------------------
echo "[6] Edge: unsupported source...\n";
$threwInvalid = false;
try {
    $simulator->simulate($apexId, 'snapchat', [
        'name' => 'Bad Source',
        'phone' => '+1',
        'inquiry' => 'x',
    ]);
} catch (InvalidArgumentException $e) {
    $threwInvalid = str_contains($e->getMessage(), 'Unsupported');
}
$pass('unsupported_source_throws', $threwInvalid);

// ---------------------------------------------------------------------------
// 7) Edge: unknown tenant — simulator missing secret; raw HTTP → 404
// ---------------------------------------------------------------------------
echo "[7] Edge: ghost / unknown tenant...\n";
$ghostId = (string) Str::uuid();
$ghostMissingSecret = false;
try {
    $simulator->simulate($ghostId, 'meta', [
        'name' => 'Ghost Lead',
        'phone' => '+966500099999',
        'inquiry' => 'ghost tenant',
    ]);
} catch (MissingWebhookSecretException) {
    $ghostMissingSecret = true;
}
$pass('ghost_tenant_missing_secret', $ghostMissingSecret);

$ghostBody = json_encode([
    'object' => 'page',
    'entry' => [[
        'changes' => [[
            'value' => [
                'leadgen_data' => [
                    'leadgen_id' => 'ghost-'.$tag,
                    'full_name' => 'Ghost HTTP',
                    'phone_number' => '+966500099998',
                ],
            ],
        ]],
    ]],
], JSON_THROW_ON_ERROR);
$ghostReq = Request::create(
    '/api/v1/webhooks/meta/'.$ghostId,
    'POST',
    [],
    [],
    [],
    [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $ghostBody, $metaSecret),
    ],
    $ghostBody,
);
$ghostHttp = app(HttpKernel::class)->handle($ghostReq);
$pass(
    'ghost_tenant_http_404',
    $ghostHttp->getStatusCode() === 404
        && str_contains((string) $ghostHttp->getContent(), 'tenant_not_found'),
    'status='.$ghostHttp->getStatusCode().' body='.$ghostHttp->getContent(),
);

// ---------------------------------------------------------------------------
// 8) Edge: inactive tenant → rejected
// ---------------------------------------------------------------------------
echo "[8] Edge: inactive tenant...\n";
$tenant->update(['is_active' => false]);
$inactiveOk = $simulator->simulate($apexId, 'meta', [
    'name' => 'Inactive Tenant Lead',
    'phone' => '+966500088888',
    'inquiry' => 'should reject',
]);
$tenant->update(['is_active' => true]);
$pass('inactive_tenant_rejected', $inactiveOk === false);

// ---------------------------------------------------------------------------
// 9) Edge: bad HMAC via raw internal dispatch (bypass simulator signing)
// ---------------------------------------------------------------------------
echo "[9] Edge: bad HMAC signature → 401...\n";
$badBody = json_encode([
    'entry' => [['changes' => [['value' => ['leadgen_data' => [
        'leadgen_id' => 'bad-hmac-'.$tag,
        'full_name' => 'Bad Sig',
        'phone_number' => '+966500077777',
    ]]]]]],
], JSON_THROW_ON_ERROR);
$badRequest = Request::create(
    '/api/v1/webhooks/meta/'.$apexId,
    'POST',
    [],
    [],
    [],
    [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $badBody, 'wrong-secret'),
    ],
    $badBody,
);
$badResponse = app(HttpKernel::class)->handle($badRequest);
$pass('bad_hmac_401', $badResponse->getStatusCode() === 401, 'status='.$badResponse->getStatusCode());

// ---------------------------------------------------------------------------
// 10) Edge: duplicate external_lead_id → already_processed (idempotent)
// ---------------------------------------------------------------------------
echo "[10] Edge: duplicate webhook idempotency...\n";
Cache::flush();
$dupExt = 'sandbox-dup-'.$tag;
$dupPayload = [
    'object' => 'page',
    'entry' => [[
        'changes' => [[
            'value' => [
                'leadgen_data' => [
                    'leadgen_id' => $dupExt,
                    'full_name' => "Dup Lead {$tag}",
                    'phone_number' => '+966500066666',
                    'inquiry' => 'dup',
                ],
            ],
        ]],
    ]],
];
$dupJson = json_encode($dupPayload, JSON_THROW_ON_ERROR);
$dupSig = 'sha256='.hash_hmac('sha256', $dupJson, $metaSecret);
$makeDup = static function () use ($apexId, $dupJson, $dupSig) {
    $req = Request::create(
        '/api/v1/webhooks/meta/'.$apexId,
        'POST',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $dupSig,
        ],
        $dupJson,
    );

    return app(HttpKernel::class)->handle($req);
};
$firstDup = $makeDup();
$secondDup = $makeDup();
$dupCount = Lead::withoutGlobalScopes()
    ->where('tenant_id', $apexId)
    ->where('external_lead_id', $dupExt)
    ->count();
$pass(
    'idempotent_duplicate',
    $firstDup->getStatusCode() === 200
        && $secondDup->getStatusCode() === 200
        && str_contains((string) $secondDup->getContent(), 'already_processed'),
    'first='.$firstDup->getStatusCode().' second='.$secondDup->getContent(),
);
$drain(20);
$dupCountAfter = Lead::withoutGlobalScopes()
    ->where('tenant_id', $apexId)
    ->where('external_lead_id', $dupExt)
    ->count();
$pass('idempotent_single_lead_row', $dupCountAfter === 1, "count={$dupCountAfter}");

// ---------------------------------------------------------------------------
// 11) Isolation — Beacon tenant must not receive Apex sandbox leads
// ---------------------------------------------------------------------------
echo "[11] Isolation: Apex leads stay on Apex...\n";
$beacon = Tenant::query()->where('name', 'like', 'Beacon%')->first();
if ($beacon !== null) {
    $leak = Lead::withoutGlobalScopes()
        ->where('tenant_id', $beacon->id)
        ->where('name', 'like', "%{$tag}%")
        ->exists();
    $pass('no_cross_tenant_leak', $leak === false);
} else {
    $pass('no_cross_tenant_leak', true, 'beacon tenant absent — skipped leak check');
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo PHP_EOL."=== SUMMARY ===\n";
$failed = 0;
foreach ($results as $key => $value) {
    echo str_pad($key, 36).' '.$value.PHP_EOL;
    if (str_starts_with($value, 'FAIL')) {
        $failed++;
    }
}

echo PHP_EOL.'Total: '.count($results).' | Failed: '.$failed.PHP_EOL;
exit($failed === 0 ? 0 : 1);
