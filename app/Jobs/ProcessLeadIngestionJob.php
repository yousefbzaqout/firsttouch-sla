<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Adapters\Webhooks\WebhookAdapterFactory;
use App\Enums\LeadSource;
use App\Jobs\Concerns\ConfiguresReliableQueueJob;
use App\Pipelines\LeadProcessingPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Request;

class ProcessLeadIngestionJob implements ShouldQueue
{
    use ConfiguresReliableQueueJob;
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function __construct(
        private readonly string $tenantId,
        private readonly LeadSource $source,
        private readonly array $payload,
        private readonly array $headers = [],
    ) {
        $this->onQueue('high');
    }

    public function handle(
        WebhookAdapterFactory $adapterFactory,
        LeadProcessingPipeline $pipeline,
    ): void {
        $adapter = $adapterFactory->resolve($this->source);

        // Rebuild as JSON request. Copying Content-Type: application/json onto a
        // parameter-based Request makes input() read an empty JSON body.
        $content = json_encode($this->payload, JSON_THROW_ON_ERROR);
        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($this->headers as $name => $value) {
            $normalized = strtolower(str_replace('_', '-', $name));
            if (in_array($normalized, ['content-type', 'content-length'], true)) {
                continue;
            }

            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $request = Request::create('', 'POST', [], [], [], $server, $content);

        $leadData = $adapter->extractLeadData($request, $this->tenantId);

        $pipeline->process($leadData);
    }
}
