<?php

declare(strict_types=1);

/**
 * Full company simulation for Apex Media Agency.
 *
 * Free external APIs used:
 * - randomuser.me  → realistic Meta/TikTok lead identities
 * - api.telegram.org → assignment/breach alerts (via app Telegram driver)
 * - openrouter.ai (openrouter/free) → AI qualification when credits allow
 *
 * FirstTouch internal APIs exercised:
 * - POST /api/v1/webhooks/meta|{tiktok}/{tenant}
 * - Mock payment checkout
 * - Filament RBAC paths (via services)
 * - SLA breach command + notifications
 */

use App\Adapters\Notifications\NotificationDriverFactory;
use App\DTOs\PaymentResponseDTO;
use App\Enums\AiRoutingMode;
use App\Enums\LeadStatus;
use App\Enums\SlaStatus;
use App\Enums\UserRole;
use App\Filament\Pages\TenantSettingsPage;
use App\Filament\Resources\LeadResource;
use App\Filament\Resources\UserResource;
use App\Jobs\EscalateLeadSlaJob;
use App\Jobs\SendLeadAssignedNotificationJob;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Ai\CreditManagerService;
use App\Services\Leads\LeadWorkflowService;
use App\Services\Payments\CreditPurchaseService;
use App\Services\Payments\Drivers\MockPaymentDriver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$tenantId = '01a023da-4174-71c1-9095-4200e631a564';
$baseUrl = rtrim((string) env('APP_URL', 'http://localhost'), '/');
$results = [];
$started = microtime(true);

$tenant = Tenant::query()->findOrFail($tenantId);
$setting = TenantSetting::withoutGlobalScopes()->where('tenant_id', $tenantId)->firstOrFail();
$owner = User::query()->where('tenant_id', $tenantId)->where('role', UserRole::Owner)->firstOrFail();
$sara = User::query()->where('email', 'sara@apexmedia.com')->firstOrFail();
$omar = User::query()->where('email', 'omar@apexmedia.com')->firstOrFail();

$metaSecret = (string) $setting->meta_webhook_secret;
$tiktokSecret = (string) $setting->tiktok_webhook_secret;

echo "=== Apex Media Agency — Full Company Simulation ===\n";
echo "Tenant: {$tenant->name} ({$tenantId})\n";
echo "APIs: randomuser.me + Telegram + OpenRouter(free) + FirstTouch webhooks\n\n";

// ---------------------------------------------------------------------------
// 0) Fetch realistic people from free RandomUser API
// ---------------------------------------------------------------------------
echo "[0] Fetching realistic leads from https://randomuser.me/api/ ...\n";
$people = [];
try {
    $ru = Http::timeout(15)->get('https://randomuser.me/api/', [
        'results' => 8,
        'nat' => 'us,gb,ca',
        'inc' => 'name,email,phone,login,picture',
    ]);
    if ($ru->successful()) {
        foreach ($ru->json('results') ?? [] as $row) {
            $people[] = [
                'name' => trim(($row['name']['first'] ?? '').' '.($row['name']['last'] ?? '')),
                'email' => (string) ($row['email'] ?? ''),
                'phone' => preg_replace('/[^\d+]/', '', (string) ($row['phone'] ?? '')) ?: '+9665'.random_int(10000000, 99999999),
                'picture' => (string) ($row['picture']['thumbnail'] ?? ''),
            ];
        }
        $results['randomuser_api'] = 'PASS count='.count($people);
    } else {
        $results['randomuser_api'] = 'FAIL status='.$ru->status();
    }
} catch (Throwable $e) {
    $results['randomuser_api'] = 'FAIL '.$e->getMessage();
}

if ($people === []) {
    // Deterministic fallback if RandomUser is unreachable
    for ($i = 1; $i <= 8; $i++) {
        $people[] = [
            'name' => "Fallback Lead {$i}",
            'email' => "fallback{$i}@example.test",
            'phone' => '+9665'.str_pad((string) (20000000 + $i), 8, '0', STR_PAD_LEFT),
            'picture' => '',
        ];
    }
}

function postSigned(string $url, array $payload, string $headerName, string $signature): array
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $response = Http::withHeaders([
        'Content-Type' => 'application/json',
        $headerName => $signature,
        'Accept' => 'application/json',
    ])->withBody($body, 'application/json')->post($url);

    return ['status' => $response->status(), 'json' => $response->json(), 'body' => $response->body()];
}

function metaPayload(array $person, string $externalId): array
{
    return [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'leadgen_data' => [
                        'leadgen_id' => $externalId,
                        'full_name' => $person['name'],
                        'phone_number' => $person['phone'],
                        'email' => $person['email'],
                        'campaign_id' => 'cmp-apex-meta',
                        'form_id' => 'frm-apex-meta',
                        'avatar' => $person['picture'],
                    ],
                ],
            ]],
        ]],
    ];
}

function tiktokPayload(array $person, string $externalId): array
{
    return [
        'data' => [
            'lead_id' => $externalId,
            'name' => $person['name'],
            'phone' => $person['phone'],
            'email' => $person['email'],
            'campaign_id' => 'cmp-apex-tt',
            'form_id' => 'frm-apex-tt',
        ],
    ];
}

function drainQueues(int $seconds = 45): void
{
    // Horizon is already consuming; also drain leftovers locally.
    Artisan::call('queue:work', [
        '--queue' => 'high,notifications,default',
        '--stop-when-empty' => true,
        '--max-time' => max(1, min($seconds, 20)),
        '--tries' => 1,
        '--sleep' => 0,
    ]);
}

/**
 * @param  list<string>  $externalIds
 */
function waitForLeads(string $tenantId, array $externalIds, int $timeoutSeconds = 90): int
{
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        $count = Lead::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('external_lead_id', $externalIds)
            ->count();
        if ($count >= count($externalIds)) {
            return $count;
        }
        drainQueues(5);
        usleep(400_000);
    } while (microtime(true) < $deadline);

    return Lead::withoutGlobalScopes()
        ->where('tenant_id', $tenantId)
        ->whereIn('external_lead_id', $externalIds)
        ->count();
}

// Prepare company for a clean run (keep users; reset operational state)
$setting->update([
    'ai_routing_mode' => AiRoutingMode::AiFirst,
    'credits_balance' => max(20, (int) $setting->credits_balance),
    'notification_driver' => 'telegram',
    'telegram_chat_id' => $setting->telegram_chat_id ?: (string) config('services.telegram.chat_id'),
    'sla_timeout_minutes' => 12,
]);
$setting->refresh();

// ---------------------------------------------------------------------------
// 1) Happy path: Meta leads from RandomUser
// ---------------------------------------------------------------------------
echo "[1] Meta webhook happy-path (3 leads)...\n";
$metaIds = [];
for ($i = 0; $i < 3; $i++) {
    $person = $people[$i];
    $ext = 'ru-meta-'.Str::uuid()->toString();
    $payload = metaPayload($person, $ext);
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $sig = 'sha256='.hash_hmac('sha256', $body, $metaSecret);
    $res = postSigned("{$baseUrl}/api/v1/webhooks/meta/{$tenantId}", $payload, 'X-Hub-Signature-256', $sig);
    $metaIds[] = $ext;
    $results["meta_accept_{$i}"] = ($res['status'] === 200 && ($res['json']['status'] ?? '') === 'accepted')
        ? 'PASS'
        : 'FAIL '.$res['status'].' '.$res['body'];
}

// ---------------------------------------------------------------------------
// 2) TikTok happy path
// ---------------------------------------------------------------------------
echo "[2] TikTok webhook happy-path (2 leads)...\n";
$ttIds = [];
for ($i = 3; $i < 5; $i++) {
    $person = $people[$i];
    $ext = 'ru-tt-'.Str::uuid()->toString();
    $payload = tiktokPayload($person, $ext);
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $sig = hash_hmac('sha256', $body, $tiktokSecret);
    $res = postSigned("{$baseUrl}/api/v1/webhooks/tiktok/{$tenantId}", $payload, 'X-TikTok-Signature', $sig);
    $ttIds[] = $ext;
    $results['tiktok_accept_'.($i - 3)] = ($res['status'] === 200 && ($res['json']['status'] ?? '') === 'accepted')
        ? 'PASS'
        : 'FAIL '.$res['status'].' '.$res['body'];
}

$persistedCount = waitForLeads($tenantId, array_merge($metaIds, $ttIds), 120);
$created = Lead::withoutGlobalScopes()
    ->where('tenant_id', $tenantId)
    ->whereIn('external_lead_id', array_merge($metaIds, $ttIds))
    ->get();
$results['leads_persisted'] = $persistedCount === 5
    ? 'PASS'
    : 'FAIL count='.$persistedCount;
$results['round_robin_assigned'] = $created->whereNotNull('assigned_user_id')->count() >= 1
    ? 'PASS assigned='.$created->whereNotNull('assigned_user_id')->count()
    : 'FAIL';

// ---------------------------------------------------------------------------
// 3) Edge: bad HMAC → 401
// ---------------------------------------------------------------------------
echo "[3] Edge: bad Meta signature...\n";
$badPerson = $people[5];
$badPayload = metaPayload($badPerson, 'ru-bad-'.Str::uuid());
$body = json_encode($badPayload, JSON_THROW_ON_ERROR);
$badSig = 'sha256='.hash_hmac('sha256', $body, 'wrong-secret');
$res = postSigned("{$baseUrl}/api/v1/webhooks/meta/{$tenantId}", $badPayload, 'X-Hub-Signature-256', $badSig);
$results['meta_bad_hmac'] = $res['status'] === 401 ? 'PASS' : 'FAIL '.$res['status'];

// ---------------------------------------------------------------------------
// 4) Edge: idempotent duplicate webhook
// ---------------------------------------------------------------------------
echo "[4] Edge: duplicate Meta lead (idempotency)...\n";
$dupExt = 'ru-dup-'.Str::uuid();
$dupPayload = metaPayload($people[6], $dupExt);
$body = json_encode($dupPayload, JSON_THROW_ON_ERROR);
$sig = 'sha256='.hash_hmac('sha256', $body, $metaSecret);
$first = postSigned("{$baseUrl}/api/v1/webhooks/meta/{$tenantId}", $dupPayload, 'X-Hub-Signature-256', $sig);
drainQueues(30);
$second = postSigned("{$baseUrl}/api/v1/webhooks/meta/{$tenantId}", $dupPayload, 'X-Hub-Signature-256', $sig);
$dupCount = Lead::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('external_lead_id', $dupExt)->count();
$results['idempotent_duplicate'] = (
    $first['status'] === 200
    && in_array($second['status'], [200], true)
    && $dupCount === 1
) ? 'PASS' : 'FAIL first='.$first['status'].' second='.$second['status'].' count='.$dupCount;

// ---------------------------------------------------------------------------
// 5) Edge: unknown tenant → 404
// ---------------------------------------------------------------------------
echo "[5] Edge: unknown tenant...\n";
$ghostPayload = metaPayload($people[7], 'ghost-'.Str::uuid());
$body = json_encode($ghostPayload, JSON_THROW_ON_ERROR);
$sig = 'sha256='.hash_hmac('sha256', $body, $metaSecret);
$res = postSigned("{$baseUrl}/api/v1/webhooks/meta/".Str::uuid(), $ghostPayload, 'X-Hub-Signature-256', $sig);
$results['unknown_tenant_404'] = $res['status'] === 404 ? 'PASS' : 'FAIL '.$res['status'];

// ---------------------------------------------------------------------------
// 6) Sales claim + note + mark contacted (SLA halt)
// ---------------------------------------------------------------------------
echo "[6] Sales workflow: claim / note / contacted...\n";
$unassigned = Lead::withoutGlobalScopes()
    ->where('tenant_id', $tenantId)
    ->whereNull('assigned_user_id')
    ->latest()
    ->first();

if ($unassigned === null) {
    // Force one unassigned for claim path
    $unassigned = Lead::withoutGlobalScopes()->where('tenant_id', $tenantId)->latest()->first();
    $unassigned?->update(['assigned_user_id' => null, 'status' => LeadStatus::New, 'claimed_at' => null]);
    $unassigned?->refresh();
}

$workflow = app(LeadWorkflowService::class);
if ($unassigned) {
    $claimed = $workflow->claim($unassigned, $sara);
    $workflow->addInternalNote($claimed, 'Company sim: left voicemail, will retry.', $sara);
    $contacted = $workflow->markContacted($claimed->fresh() ?? $claimed, $sara);
    $results['sales_claim_contact'] = (
        $contacted->assigned_user_id === $sara->id
        && $contacted->status === LeadStatus::Contacted
        && $contacted->sla_status === SlaStatus::Met
    ) ? 'PASS' : 'FAIL';
} else {
    $results['sales_claim_contact'] = 'FAIL no_lead';
}

drainQueues(20);

// ---------------------------------------------------------------------------
// 7) SLA breach escalation path
// ---------------------------------------------------------------------------
echo "[7] SLA breach escalation...\n";
Http::fake([
    'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 991]], 200),
]);

$breachLead = Lead::withoutGlobalScopes()->create([
    'tenant_id' => $tenantId,
    'assigned_user_id' => $omar->id,
    'source' => 'meta',
    'external_lead_id' => 'sla-breach-'.Str::uuid(),
    'name' => 'SLA Breach Prospect',
    'phone' => '+966599900011',
    'email' => 'breach@sim.apex.test',
    'status' => LeadStatus::Claimed,
    'sla_status' => SlaStatus::Active,
    'sla_deadline' => now()->subMinutes(2),
    'claimed_at' => now()->subMinutes(20),
    'meta_data' => ['ai_qualification_summary' => 'Urgent — missed callback window'],
]);

Artisan::call('sla:check-breaches');
drainQueues(20);

$breachLead->refresh();
$results['sla_breach_status'] = $breachLead->sla_status === SlaStatus::Breached ? 'PASS' : 'FAIL '.$breachLead->sla_status->value;

(new EscalateLeadSlaJob($breachLead->id))->handle(app(NotificationDriverFactory::class));
$tgRecorded = collect(Http::recorded())->contains(function (array $pair): bool {
    return str_contains($pair[0]->url(), 'api.telegram.org/bot')
        && str_contains($pair[0]->url(), '/sendMessage');
});
$results['telegram_breach_dispatch'] = $tgRecorded ? 'PASS' : 'FAIL';

$assignedLead = Lead::withoutGlobalScopes()
    ->where('tenant_id', $tenantId)
    ->whereNotNull('assigned_user_id')
    ->latest()
    ->first();
if ($assignedLead) {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 992]], 200),
    ]);
    (new SendLeadAssignedNotificationJob($assignedLead->id))
        ->handle(app(NotificationDriverFactory::class));
    $assignSent = collect(Http::recorded())->contains(function (array $pair): bool {
        return str_contains($pair[0]->url(), '/sendMessage');
    });
    $results['telegram_assign_dispatch'] = $assignSent ? 'PASS' : 'FAIL';
}

// ---------------------------------------------------------------------------
// 8) Zero credits → human_only + alert path
// ---------------------------------------------------------------------------
echo "[8] Edge: zero credits exhaust...\n";
Http::fake([
    'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 993]], 200),
]);
$setting->update(['credits_balance' => 1, 'ai_routing_mode' => AiRoutingMode::AiFirst]);
$cm = app(CreditManagerService::class);
$cm->deductCredit($tenant->fresh());
$setting->refresh();
$results['credits_exhausted'] = (
    $setting->credits_balance === 0
    && $setting->ai_routing_mode === AiRoutingMode::HumanOnly
) ? 'PASS' : 'FAIL';

// Restore usable credits via mock payment service (not just UI)
$beforePay = $setting->credits_balance;
app(CreditPurchaseService::class)->processSuccessfulPayment(
    $tenantId,
    'mock',
    new PaymentResponseDTO(
        success: true,
        transactionId: 'sim_txn_'.Str::uuid(),
        creditsPurchased: 100,
        amountPaid: 9.99,
        currency: 'USD',
    ),
);
$setting->refresh();
$results['mock_payment_credit'] = (
    $setting->credits_balance === $beforePay + 100
    && $setting->ai_routing_mode === AiRoutingMode::AiFirst
) ? 'PASS' : 'FAIL bal='.$setting->credits_balance;

$checkoutUrl = (new MockPaymentDriver)->createCheckoutSession($tenant, 50, 4.99);
$results['mock_checkout_url'] = str_contains($checkoutUrl, '/payments/mock/checkout') ? 'PASS' : 'FAIL';

// ---------------------------------------------------------------------------
// 9) Off-hours / SLA deadline still calculated
// ---------------------------------------------------------------------------
echo "[9] Off-hours / SLA deadline presence...\n";
$withDeadline = Lead::withoutGlobalScopes()
    ->where('tenant_id', $tenantId)
    ->whereIn('external_lead_id', $metaIds)
    ->whereNotNull('sla_deadline')
    ->count();
$results['sla_deadlines_set'] = $withDeadline >= 1 ? 'PASS count='.$withDeadline : 'FAIL';

// ---------------------------------------------------------------------------
// 10) Owner sees all; Sara cannot see Omar-only lead after reassign isolation check
// ---------------------------------------------------------------------------
echo "[10] RBAC visibility...\n";
auth()->login($owner);
$ownerVisible = LeadResource::getEloquentQuery()->count();
auth()->login($sara);
$saraVisible = LeadResource::getEloquentQuery()->count();
$omarOnly = Lead::withoutGlobalScopes()->create([
    'tenant_id' => $tenantId,
    'assigned_user_id' => $omar->id,
    'source' => 'meta',
    'external_lead_id' => 'omar-only-'.Str::uuid(),
    'name' => 'Omar Private Lead',
    'phone' => '+966588800099',
    'email' => 'omar-private@sim.apex.test',
    'status' => LeadStatus::Claimed,
    'sla_status' => SlaStatus::Active,
    'sla_deadline' => now()->addHour(),
]);
auth()->login($sara);
$saraSeesOmar = LeadResource::getEloquentQuery()
    ->where('id', $omarOnly->id)
    ->exists();
$results['owner_sees_more'] = $ownerVisible >= $saraVisible ? 'PASS owner='.$ownerVisible.' sara='.$saraVisible : 'FAIL';
$results['sales_isolation'] = $saraSeesOmar ? 'FAIL' : 'PASS';
$results['sales_cannot_team'] = UserResource::canViewAny() ? 'FAIL' : 'PASS';
$results['sales_cannot_settings'] = TenantSettingsPage::canAccess() ? 'FAIL' : 'PASS';

auth()->logout();

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------
$elapsed = round(microtime(true) - $started, 2);
$pass = count(array_filter($results, fn ($v) => str_starts_with((string) $v, 'PASS')));
$total = count($results);

echo "\n=== RESULTS ({$pass}/{$total} PASS) in {$elapsed}s ===\n";
foreach ($results as $k => $v) {
    echo str_pad($k, 28).' '.$v."\n";
}

$failed = array_filter($results, fn ($v) => ! str_starts_with((string) $v, 'PASS'));
if ($failed !== []) {
    echo "\nFailed keys: ".implode(', ', array_keys($failed))."\n";
    exit(1);
}

echo "\nCompany simulation complete — all exercised paths passed.\n";
exit(0);
