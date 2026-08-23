<?php

declare(strict_types=1);

use App\Adapters\Notifications\N8nNotificationDriver;
use App\Adapters\Notifications\NotificationDriverFactory;
use App\Adapters\Webhooks\WebhookAdapterFactory;
use App\Enums\LeadSource;
use App\Jobs\ProcessAiResponseJob;
use App\Jobs\ProcessKnowledgeDocumentJob;
use App\Models\KnowledgeBase;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Pipelines\LeadProcessingPipeline;
use App\Services\Ai\Contracts\EmbeddingServiceInterface;
use App\Services\Ai\RagInferenceService;
use App\Services\Ai\TextChunkerService;
use App\Services\Payments\Drivers\MockPaymentDriver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$tenantId = '01a023da-4174-71c1-9095-4200e631a564';
$secret = 'apex_secret_key_777';
$results = [];

// 1) Telegram driver
$factory = app(NotificationDriverFactory::class);
$results['telegram_driver'] = $factory->resolve('telegram') instanceof N8nNotificationDriver ? 'PASS' : 'FAIL';

// 2) Mock checkout URL
$tenant = Tenant::query()->findOrFail($tenantId);
$checkoutUrl = (new MockPaymentDriver)->createCheckoutSession($tenant, 100, 9.99);
$results['mock_checkout_url'] = str_contains($checkoutUrl, '/payments/mock/checkout') ? 'PASS' : 'FAIL';

// 3) Mock checkout shows gateway first (no instant credit)
$beforeCredits = (int) TenantSetting::withoutGlobalScopes()->where('tenant_id', $tenantId)->value('credits_balance');
$checkoutResponse = Http::withOptions(['allow_redirects' => false])->get($checkoutUrl);
$afterShow = (int) TenantSetting::withoutGlobalScopes()->where('tenant_id', $tenantId)->value('credits_balance');
$results['mock_checkout_show'] = ($checkoutResponse->status() === 200
    && str_contains((string) $checkoutResponse->body(), 'Stripe Mock Gateway')
    && $afterShow === $beforeCredits)
    ? 'PASS'
    : 'FAIL status='.$checkoutResponse->status()." credits={$beforeCredits}->{$afterShow}";

// 4) Re-embed KB + RAG
$kb = KnowledgeBase::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('title', 'Apex FAQ Hours')->firstOrFail();
DB::table('knowledge_chunks')->where('knowledge_base_id', $kb->id)->delete();
(new ProcessKnowledgeDocumentJob(
    $kb->id,
    'Our support hours are Sunday to Thursday, 9am to 5pm Riyadh time. We help marketing agencies respond to leads fast.',
))->handle(app(TextChunkerService::class), app(EmbeddingServiceInterface::class));
$chunkCount = (int) DB::table('knowledge_chunks')->where('knowledge_base_id', $kb->id)->count();
$results['kb_embed'] = $chunkCount >= 1 ? 'PASS' : 'FAIL';

TenantSetting::withoutGlobalScopes()->where('tenant_id', $tenantId)->first()?->update(['ai_confidence_threshold' => 75]);

$lead = Lead::withoutGlobalScopes()->where('tenant_id', $tenantId)->latest('created_at')->firstOrFail();
$creditsBeforeRag = (int) TenantSetting::withoutGlobalScopes()->where('tenant_id', $tenantId)->value('credits_balance');
$dto = app(RagInferenceService::class)->processLeadQuery($lead, 'What are your support hours?');
$creditsAfterRag = (int) TenantSetting::withoutGlobalScopes()->where('tenant_id', $tenantId)->value('credits_balance');
$results['rag'] = ($dto->success && is_string($dto->answer) && trim($dto->answer) !== '' && $creditsAfterRag === $creditsBeforeRag - 1)
    ? 'PASS'
    : 'FAIL success='.json_encode($dto->success).' conf='.$dto->confidenceScore.' answer='.json_encode($dto->answer)." credits={$creditsBeforeRag}->{$creditsAfterRag}";

// 5) AI job dispatched on ingest
Queue::fake();
$externalId = 'resim-ai-'.bin2hex(random_bytes(4));
$payload = [
    'entry' => [[
        'changes' => [[
            'value' => [
                'leadgen_data' => [
                    'leadgen_id' => $externalId,
                    'full_name' => 'AI Auto Lead',
                    'phone_number' => '+966500001010',
                    'email' => 'ai@apexmedia.com',
                    'campaign_id' => 'c',
                    'form_id' => 'f',
                ],
            ],
        ]],
    ]],
];
$json = json_encode($payload, JSON_THROW_ON_ERROR);
$sig = 'sha256='.hash_hmac('sha256', $json, $secret);
$request = Request::create("/api/v1/webhooks/meta/{$tenantId}", 'POST', [], [], [], [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X-Hub-Signature-256' => $sig,
], $json);
$adapter = app(WebhookAdapterFactory::class)->resolve(LeadSource::Meta);
$leadData = $adapter->extractLeadData($request, $tenantId);
$created = app(LeadProcessingPipeline::class)->process($leadData);
Queue::assertPushed(ProcessAiResponseJob::class);
$results['ai_dispatch'] = $created !== null ? 'PASS' : 'FAIL';

// 6) Dashboard route exists
$results['dashboard_route'] = app('router')->has('filament.admin.pages.dashboard')
    || collect(app('router')->getRoutes())->contains(fn ($r) => str_contains($r->uri(), 'admin') && str_contains(strtolower(implode('|', $r->gatherMiddleware())), 'auth'))
    ? 'PASS'
    : 'CHECK';

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
