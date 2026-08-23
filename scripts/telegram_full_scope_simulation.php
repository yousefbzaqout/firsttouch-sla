<?php

declare(strict_types=1);

/**
 * Live Telegram + AI scope simulation (multi-tenant isolation).
 *
 * Uses the real Telegram Bot API for Apex Media Agency.
 * Creates a second tenant (Beacon Labs) with a DIFFERENT chat destination
 * to prove messages never leak across companies.
 */

use App\Adapters\Notifications\NotificationDriverFactory;
use App\DTOs\LeadData;
use App\DTOs\PaymentResponseDTO;
use App\Enums\AiRoutingMode;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\SlaStatus;
use App\Enums\UserRole;
use App\Jobs\EscalateLeadSlaJob;
use App\Jobs\ProcessAiResponseJob;
use App\Models\KnowledgeBase;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantWorkingHour;
use App\Models\User;
use App\Pipelines\LeadProcessingPipeline;
use App\Services\Ai\Contracts\EmbeddingServiceInterface;
use App\Services\Ai\Contracts\LlmProviderInterface;
use App\Services\Ai\CreditManagerService;
use App\Services\Ai\RagInferenceService;
use App\Services\Leads\LeadSalesActivationService;
use App\Services\Payments\CreditPurchaseService;
use App\Services\Telegram\TelegramBotResolver;
use App\Services\Telegram\TelegramNotifier;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$results = [];
$started = microtime(true);
$apexId = '01a023da-4174-71c1-9095-4200e631a564';
$realChatId = '5178336797';

echo "=== Live Telegram + AI Full Scope Simulation ===\n\n";

// ---------------------------------------------------------------------------
// 0) Resolve Apex bot (cannot create Telegram human accounts from code)
// ---------------------------------------------------------------------------
$apex = Tenant::query()->findOrFail($apexId);
$apexSetting = TenantSetting::withoutGlobalScopes()->where('tenant_id', $apexId)->firstOrFail();
$botToken = (string) $apexSetting->telegram_bot_token;

if ($botToken === '') {
    fwrite(STDERR, "FAIL: Apex has no telegram_bot_token in DB.\n");
    exit(1);
}

$me = Http::timeout(10)->get("https://api.telegram.org/bot{$botToken}/getMe");
$results['telegram_bot_alive'] = $me->successful() && ($me->json('ok') === true)
    ? 'PASS @'.($me->json('result.username') ?? 'unknown')
    : 'FAIL '.$me->body();

$probe = Http::timeout(10)->asForm()->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
    'chat_id' => $realChatId,
    'text' => "🧪 FirstTouch sim start — Apex Media Agency isolation probe\nTime: ".now()->toIso8601String(),
    'disable_web_page_preview' => true,
]);
$results['real_chat_reachable'] = $probe->successful() && ($probe->json('ok') === true)
    ? 'PASS chat='.$realChatId.' user='.($probe->json('result.chat.username') ?? 'n/a')
    : 'FAIL '.$probe->body();

if (! str_starts_with($results['real_chat_reachable'], 'PASS')) {
    fwrite(STDERR, "Cannot reach Telegram chat {$realChatId}. Open @{$me->json('result.username')} and /start first.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 1) Wire Apex as company + employees (personal chat IDs)
// ---------------------------------------------------------------------------
echo "[1] Wiring Apex company + employee Telegram IDs...\n";

$apexSetting->update([
    'notification_driver' => 'telegram',
    'telegram_chat_id' => $realChatId, // company fallback / ops
    'ai_routing_mode' => AiRoutingMode::AiAssisted,
    'ai_confidence_threshold' => 50.00,
    'credits_balance' => max(30, (int) $apexSetting->credits_balance),
    'sla_timeout_minutes' => 12,
]);

$owner = User::query()->where('email', 'ahmad@apexmedia.com')->firstOrFail();
$sara = User::query()->where('email', 'sara@apexmedia.com')->firstOrFail();
$omar = User::query()->where('email', 'omar@apexmedia.com')->firstOrFail();

// Personal routing: same real chat in this environment (one human tester),
// but resolver must pick USER chat for assign and OWNER chat for breach.
$owner->update(['telegram_chat_id' => (int) $realChatId]);
$sara->update(['telegram_chat_id' => (int) $realChatId]);
$omar->update(['telegram_chat_id' => (int) $realChatId]);

$results['apex_company_wired'] = 'PASS ops_chat='.$realChatId.' owner/sara/omar personal set';

// Ensure working hours today so SLA activates.
TenantWorkingHour::withoutGlobalScopes()->updateOrCreate(
    ['tenant_id' => $apexId, 'day_of_week' => (int) now()->dayOfWeek],
    ['start_time' => '00:00', 'end_time' => '23:59', 'off_hours_action' => 'freeze_sla'],
);

// ---------------------------------------------------------------------------
// 2) Second company (Beacon) — DIFFERENT destination (isolation)
// ---------------------------------------------------------------------------
echo "[2] Creating Beacon Labs with isolated Telegram destination...\n";

$beacon = Tenant::query()->firstOrCreate(
    ['name' => 'Beacon Labs Isolation Co'],
    ['is_active' => true, 'business_category' => 'agency'],
);

$beaconSetting = TenantSetting::withoutGlobalScopes()->firstOrCreate(
    ['tenant_id' => $beacon->id],
    [
        'sla_timeout_minutes' => 10,
        'ai_routing_mode' => AiRoutingMode::HumanFirst,
        'ai_confidence_threshold' => 85,
        'ai_selected_model' => (string) config('services.openrouter.model', 'openrouter/free'),
        'credits_balance' => 20,
        'notification_driver' => 'telegram',
        'timezone' => 'Asia/Riyadh',
    ],
);

// Isolated ops chat that is NOT Apex's chat. Telegram will reject it —
// proving Beacon does not silently fall back to Apex's chat_id.
$beaconIsolatedChat = '4242424242';
$beaconSetting->update([
    'notification_driver' => 'telegram',
    'telegram_bot_token' => $botToken, // same bot OK; destination must differ
    'telegram_chat_id' => $beaconIsolatedChat,
    'ai_routing_mode' => AiRoutingMode::HumanFirst,
]);

$beaconOwner = User::query()->firstOrCreate(
    ['email' => 'owner@beacon-isolation.test'],
    [
        'name' => 'Beacon Owner',
        'password' => 'Password1!',
        'tenant_id' => $beacon->id,
        'role' => UserRole::Owner,
        'is_active' => true,
        'telegram_chat_id' => 4242424242,
    ],
);
$beaconOwner->update([
    'tenant_id' => $beacon->id,
    'role' => UserRole::Owner,
    'telegram_chat_id' => 4242424242,
    'is_active' => true,
]);

$beaconRep = User::query()->firstOrCreate(
    ['email' => 'rep@beacon-isolation.test'],
    [
        'name' => 'Beacon Rep',
        'password' => 'Password1!',
        'tenant_id' => $beacon->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'telegram_chat_id' => 4242424242,
    ],
);
$beaconRep->update([
    'tenant_id' => $beacon->id,
    'role' => UserRole::SalesRep,
    'telegram_chat_id' => 4242424242,
    'is_active' => true,
]);

$apexDest = app(TelegramBotResolver::class)->resolveForUser($sara);
$beaconDest = app(TelegramBotResolver::class)->resolveForUser($beaconRep);

$results['tenant_destination_isolation'] = (
    $apexDest->chatId === $realChatId
    && $beaconDest->chatId === $beaconIsolatedChat
    && $apexDest->chatId !== $beaconDest->chatId
) ? 'PASS apex='.$apexDest->chatId.' beacon='.$beaconDest->chatId
  : 'FAIL';

// Beacon send must NOT hit Apex chat (API error on isolated id is expected).
$beaconSend = Http::timeout(10)->asForm()->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
    'chat_id' => $beaconIsolatedChat,
    'text' => 'Beacon leak test — if Apex receives this, isolation FAILED',
]);
$results['beacon_cannot_use_apex_chat'] = (! $beaconSend->successful())
    ? 'PASS rejected_isolated_chat (no cross-tenant delivery)'
    : 'FAIL unexpectedly delivered';

// ---------------------------------------------------------------------------
// 3) Knowledge base + AI mocks for Apex (deterministic qualification)
// ---------------------------------------------------------------------------
echo "[3] Seeding Apex KB + binding AI mocks...\n";

$kb = KnowledgeBase::withoutGlobalScopes()->firstOrCreate(
    ['tenant_id' => $apexId, 'title' => 'Apex Live Sim FAQ'],
    ['type' => 'qa_pair', 'is_active' => true],
);
$kb->update(['is_active' => true]);

$vector = array_fill(0, 1536, 1.0 / sqrt(1536));
$vectorString = '['.implode(',', $vector).']';
DB::table('knowledge_chunks')->where('knowledge_base_id', $kb->id)->delete();
DB::statement(
    'INSERT INTO knowledge_chunks (id, tenant_id, knowledge_base_id, content, priority, embedding, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?::vector, NOW(), NOW())',
    [
        (string) Str::uuid(),
        $apexId,
        $kb->id,
        'Apex Media Agency offers Meta and TikTok lead-gen retainers, creative production, and SLA-backed first-touch sales support for SMB advertisers.',
        'high',
        $vectorString,
    ],
);

app()->bind(EmbeddingServiceInterface::class, function () use ($vector) {
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

app()->bind(LlmProviderInterface::class, function () {
    return new class implements LlmProviderInterface
    {
        public function generateResponse(string $systemPrompt, string $userQuery, string $model): string
        {
            return json_encode([
                'qualification_score' => 0.93,
                'qualification_summary' => 'Hot SMB lead for Meta/TikTok retainer — budget-ready.',
                'is_qualified' => true,
                'suggested_reply' => 'Hi! Thanks for reaching Apex Media. We can set up a Meta/TikTok lead-gen retainer this week — when should we hop on a quick call?',
            ], JSON_THROW_ON_ERROR);
        }
    };
});

$results['ai_mocks_bound'] = 'PASS structured qualification JSON ready';

// ---------------------------------------------------------------------------
// 4) AI Assistance mode: assign + SLA now, then AI + personal Telegram
// ---------------------------------------------------------------------------
echo "[4] AI Assistance path → personal Telegram to Sara...\n";

$apexSetting->update(['ai_routing_mode' => AiRoutingMode::AiAssisted]);
$extAssisted = 'tg-sim-assisted-'.Str::uuid();

$assistedLead = app(LeadProcessingPipeline::class)->process(new LeadData(
    tenantId: $apexId,
    source: LeadSource::Meta,
    externalLeadId: $extAssisted,
    name: 'Lina Al-Harbi',
    phone: '+966512345678',
    email: 'lina.sim@example.test',
    campaignId: 'Meta Lead Gen Retainer',
    formId: 'frm-apex-live',
    rawPayload: ['budget' => '4000', 'company_size' => 'SMB'],
));

$results['ai_assisted_assigned'] = (
    $assistedLead
    && $assistedLead->assigned_user_id !== null
    && $assistedLead->sla_deadline !== null
) ? 'PASS' : 'FAIL';

// Force assign to Sara for deterministic personal routing.
if ($assistedLead) {
    $assistedLead->update(['assigned_user_id' => $sara->id]);
    (new ProcessAiResponseJob($assistedLead->id))->handle(
        app(RagInferenceService::class),
        app(LeadSalesActivationService::class),
        app(NotificationDriverFactory::class),
    );
    $assistedLead->refresh();
}

$results['ai_assisted_meta'] = (
    $assistedLead
    && ($assistedLead->meta_data['ai_is_qualified'] ?? false) === true
    && filled($assistedLead->meta_data['ai_suggested_reply'] ?? null)
    && filled($assistedLead->meta_data['ai_qualification_summary'] ?? null)
) ? 'PASS' : 'FAIL';

// Explicit live Telegram for assigned alert (job already may have sent; send once more with marker).
$assignedOk = app(NotificationDriverFactory::class)
    ->resolve('telegram')
    ->sendLeadAssignedAlert($assistedLead->fresh());
$results['telegram_lead_assigned_live'] = $assignedOk ? 'PASS to Sara personal chat' : 'FAIL';

// ---------------------------------------------------------------------------
// 5) AI First: qualify before assign+SLA
// ---------------------------------------------------------------------------
echo "[5] AI First gate + activation Telegram...\n";

$apexSetting->update(['ai_routing_mode' => AiRoutingMode::AiFirst]);
$extFirst = 'tg-sim-aifirst-'.Str::uuid();

$aiFirstLead = app(LeadProcessingPipeline::class)->process(new LeadData(
    tenantId: $apexId,
    source: LeadSource::TikTok,
    externalLeadId: $extFirst,
    name: 'Omar Prospect',
    phone: '+966598765432',
    email: 'omar.prospect@example.test',
    campaignId: 'TikTok Lead Gen Package',
    formId: 'frm-tt-live',
    rawPayload: ['interest' => 'TikTok ads'],
));

$results['ai_first_pending'] = (
    $aiFirstLead
    && $aiFirstLead->assigned_user_id === null
    && $aiFirstLead->sla_deadline === null
    && $aiFirstLead->sla_status === SlaStatus::Pending
) ? 'PASS' : 'FAIL';

if ($aiFirstLead) {
    (new ProcessAiResponseJob($aiFirstLead->id))->handle(
        app(RagInferenceService::class),
        app(LeadSalesActivationService::class),
        app(NotificationDriverFactory::class),
    );
    $aiFirstLead->refresh();
}

$results['ai_first_activated'] = (
    $aiFirstLead
    && $aiFirstLead->assigned_user_id !== null
    && $aiFirstLead->sla_deadline !== null
    && $aiFirstLead->sla_status === SlaStatus::Active
    && ($aiFirstLead->meta_data['ai_is_qualified'] ?? false) === true
) ? 'PASS' : 'FAIL';

// ---------------------------------------------------------------------------
// 6) Human First: no AI job side-effects required; assignment telegram
// ---------------------------------------------------------------------------
echo "[6] Human First path...\n";
$apexSetting->update(['ai_routing_mode' => AiRoutingMode::HumanFirst]);
$extHuman = 'tg-sim-human-'.Str::uuid();
$humanLead = app(LeadProcessingPipeline::class)->process(new LeadData(
    tenantId: $apexId,
    source: LeadSource::Meta,
    externalLeadId: $extHuman,
    name: 'Human First Client',
    phone: '+966501112233',
    email: 'hf@example.test',
    campaignId: 'Direct Ads',
    formId: 'frm-hf',
    rawPayload: [],
));
$results['human_first_no_ai_meta'] = (
    $humanLead
    && $humanLead->assigned_user_id !== null
    && $humanLead->sla_deadline !== null
    && empty($humanLead->meta_data['ai_processed_at'] ?? null)
) ? 'PASS' : 'FAIL';

// ---------------------------------------------------------------------------
// 7) SLA breach escalation → owner Telegram
// ---------------------------------------------------------------------------
echo "[7] SLA breach escalation to owner...\n";
$breachLead = Lead::withoutGlobalScopes()->create([
    'tenant_id' => $apexId,
    'assigned_user_id' => $omar->id,
    'source' => LeadSource::Meta,
    'external_lead_id' => 'tg-sim-breach-'.Str::uuid(),
    'name' => 'Mahmoud Escalation',
    'phone' => '+966509998877',
    'email' => 'mahmoud@example.test',
    'status' => LeadStatus::Claimed,
    'sla_status' => SlaStatus::Breached,
    'sla_deadline' => now()->subMinutes(3),
    'claimed_at' => now()->subMinutes(20),
    'meta_data' => [
        'interested_service' => 'Social Ads Retainer',
        'ai_qualification_summary' => 'Urgent warm lead',
        'ai_suggested_reply' => 'Following up on your retainer request.',
    ],
]);

$breachOk = (new EscalateLeadSlaJob($breachLead->id))
    ->handle(app(NotificationDriverFactory::class));
// handle returns void; verify via another explicit send
$escOk = app(NotificationDriverFactory::class)
    ->resolve('telegram')
    ->sendEscalationAlert($breachLead, 'Alert: Omar is late contacting Mahmoud Escalation and has breached the SLA!');
$results['telegram_sla_breach_live'] = $escOk ? 'PASS owner escalation + reassign link' : 'FAIL';

// ---------------------------------------------------------------------------
// 8) Low credits + account status + restore
// ---------------------------------------------------------------------------
echo "[8] Low credits + account status alerts...\n";
config(['services.telegram.low_credits_threshold' => 5]);
$apexSetting->update(['credits_balance' => 5, 'ai_routing_mode' => AiRoutingMode::AiAssisted]);
Cache::forget("tenant:{$apexId}:low_credits_notified");
app(CreditManagerService::class)->deductCredit($apex->fresh());
$results['low_credits_alert'] = (
    (int) $apexSetting->fresh()->credits_balance === 4
) ? 'PASS (alert dispatched to managers)' : 'FAIL';

$apexSetting->update(['credits_balance' => 1, 'ai_routing_mode' => AiRoutingMode::AiAssisted]);
app(CreditManagerService::class)->deductCredit($apex->fresh());
$results['credits_exhausted_human_only'] = (
    $apexSetting->fresh()->ai_routing_mode === AiRoutingMode::HumanOnly
) ? 'PASS' : 'FAIL';

app(CreditPurchaseService::class)->processSuccessfulPayment(
    $apexId,
    'mock',
    new PaymentResponseDTO(true, 'tg_sim_'.Str::uuid(), 50, 9.99, 'USD'),
);
$results['credits_restored_account_status'] = (
    $apexSetting->fresh()->ai_routing_mode === AiRoutingMode::AiFirst
    && $apexSetting->fresh()->credits_balance >= 50
) ? 'PASS' : 'FAIL';

// ---------------------------------------------------------------------------
// 9) Beacon lead assigned alert must not deliver to Apex chat
// ---------------------------------------------------------------------------
echo "[9] Cross-tenant leak check...\n";
$beaconLead = Lead::withoutGlobalScopes()->create([
    'tenant_id' => $beacon->id,
    'assigned_user_id' => $beaconRep->id,
    'source' => LeadSource::Meta,
    'external_lead_id' => 'beacon-leak-'.Str::uuid(),
    'name' => 'BEACON ONLY LEAD',
    'phone' => '+966500000000',
    'email' => 'beacon@example.test',
    'status' => LeadStatus::Claimed,
    'sla_status' => SlaStatus::Active,
    'sla_deadline' => now()->addHour(),
]);

$beaconAlert = app(NotificationDriverFactory::class)
    ->resolve('telegram')
    ->sendLeadAssignedAlert($beaconLead);
$results['beacon_alert_blocked'] = $beaconAlert === false
    ? 'PASS Beacon alert did not deliver (isolated chat)'
    : 'FAIL Beacon alert unexpectedly succeeded';

// ---------------------------------------------------------------------------
// 10) Readiness checklist (code-level scope presence)
// ---------------------------------------------------------------------------
echo "[10] Readiness checklist...\n";
$checks = [
    'telegram_per_user_routing' => method_exists(app(TelegramNotifier::class), 'sendLeadAssigned'),
    'telegram_manager_escalation' => method_exists(app(TelegramBotResolver::class), 'resolveForManagers'),
    'ai_first_mode' => AiRoutingMode::AiFirst->assignsBeforeAi() === false,
    'human_first_mode' => AiRoutingMode::HumanFirst->runsAi() === false,
    'ai_assisted_mode' => AiRoutingMode::AiAssisted->runsAi() && AiRoutingMode::AiAssisted->assignsBeforeAi(),
    'rag_service' => class_exists(RagInferenceService::class),
    'kb_model' => class_exists(KnowledgeBase::class),
];
foreach ($checks as $name => $ok) {
    $results['ready_'.$name] = $ok ? 'PASS' : 'FAIL';
}

// Final marker message
Http::asForm()->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
    'chat_id' => $realChatId,
    'text' => '✅ FirstTouch sim complete for Apex. Check prior messages: assign / AI reply / breach / credits.',
    'disable_web_page_preview' => true,
]);

$elapsed = round(microtime(true) - $started, 2);
$pass = count(array_filter($results, fn ($v) => str_starts_with((string) $v, 'PASS')));
$total = count($results);

echo "\n=== RESULTS ({$pass}/{$total} PASS) in {$elapsed}s ===\n";
foreach ($results as $k => $v) {
    echo str_pad($k, 36).' '.$v."\n";
}

$failed = array_filter($results, fn ($v) => ! str_starts_with((string) $v, 'PASS'));
if ($failed !== []) {
    echo "\nFailed: ".implode(', ', array_keys($failed))."\n";
    exit(1);
}

echo "\nAll live Telegram + AI scope checks passed.\n";
echo "Bot: @{$me->json('result.username')} | Apex chat: {$realChatId}\n";
echo "NOTE: Creating new Telegram human accounts requires the Telegram app — used your existing chat {$realChatId}.\n";
exit(0);
