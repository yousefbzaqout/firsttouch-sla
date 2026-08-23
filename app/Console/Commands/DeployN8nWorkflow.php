<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DeployN8nWorkflow extends Command
{
    protected $signature = 'n8n:deploy';

    protected $description = 'Upload and activate the FirstTouch SLA n8n workflow via REST API';

    public function handle(): int
    {
        $baseUrl = rtrim((string) config('services.n8n.base_url'), '/');
        $apiKey = (string) config('services.n8n.api_key');
        $workflowPath = base_path('n8n_sla_workflow.json');

        if ($baseUrl === '' || $apiKey === '') {
            $this->error('N8N_BASE_URL and N8N_API_KEY must be set in .env');

            return self::FAILURE;
        }

        if (! is_file($workflowPath)) {
            $this->error("Workflow file not found: {$workflowPath}");

            return self::FAILURE;
        }

        $workflowJson = file_get_contents($workflowPath);

        if ($workflowJson === false) {
            $this->error('Unable to read workflow JSON file');

            return self::FAILURE;
        }

        /** @var array<string, mixed> $workflow */
        $workflow = json_decode($workflowJson, true, 512, JSON_THROW_ON_ERROR);

        // n8n 2.x treats these as read-only on create/update.
        unset(
            $workflow['id'],
            $workflow['versionId'],
            $workflow['createdAt'],
            $workflow['updatedAt'],
            $workflow['active'],
            $workflow['tags'],
            $workflow['activeVersionId'],
            $workflow['versionCounter'],
            $workflow['triggerCount'],
            $workflow['isArchived'],
            $workflow['shared'],
            $workflow['pinData'],
            $workflow['meta'],
        );

        $headers = [
            'X-N8N-API-KEY' => $apiKey,
            'Accept' => 'application/json',
        ];

        $existingId = $this->findExistingWorkflowId($baseUrl, $headers, (string) $workflow['name']);

        if ($existingId !== null) {
            $response = Http::withHeaders($headers)
                ->put("{$baseUrl}/api/v1/workflows/{$existingId}", $workflow);
        } else {
            $response = Http::withHeaders($headers)
                ->post("{$baseUrl}/api/v1/workflows", $workflow);
        }

        if (! $response->successful()) {
            $this->error('Failed to deploy workflow: '.$response->body());

            return self::FAILURE;
        }

        /** @var array<string, mixed> $result */
        $result = $response->json();
        $workflowId = (string) ($result['id'] ?? $existingId ?? '');

        if ($workflowId === '') {
            $this->error('n8n did not return a workflow ID');

            return self::FAILURE;
        }

        $activateResponse = Http::withHeaders($headers)
            ->withBody('{}', 'application/json')
            ->post("{$baseUrl}/api/v1/workflows/{$workflowId}/publish");

        if (! $activateResponse->successful()) {
            $activateResponse = Http::withHeaders($headers)
                ->withBody('{}', 'application/json')
                ->post("{$baseUrl}/api/v1/workflows/{$workflowId}/activate");
        }

        if (! $activateResponse->successful()) {
            $this->error('Workflow uploaded but activation failed: '.$activateResponse->body());

            return self::FAILURE;
        }

        $this->info("n8n workflow deployed and activated (ID: {$workflowId})");
        $this->line("Webhook URL: {$baseUrl}/webhook/firsttouch-sla-alerts");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function findExistingWorkflowId(string $baseUrl, array $headers, string $name): ?string
    {
        $response = Http::withHeaders($headers)->get("{$baseUrl}/api/v1/workflows");

        if (! $response->successful()) {
            throw new RuntimeException('Unable to list n8n workflows: '.$response->body());
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json();

        /** @var list<array<string, mixed>> $workflows */
        $workflows = isset($payload['data']) && is_array($payload['data'])
            ? array_values($payload['data'])
            : [];

        foreach ($workflows as $workflow) {
            if (($workflow['name'] ?? '') === $name) {
                return (string) ($workflow['id'] ?? '');
            }
        }

        return null;
    }
}
