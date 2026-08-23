<?php

declare(strict_types=1);

namespace App\Pipelines\Pipes;

use App\DTOs\LeadData;
use App\Enums\AiRoutingMode;
use App\Models\Lead;
use App\Models\TenantSetting;
use App\Services\Assignment\ContextAwareRoutingService;
use Closure;

class AssignRoundRobinSalesPipe
{
    public function __construct(
        private readonly ContextAwareRoutingService $router,
    ) {}

    /**
     * @param  array{lead_data: LeadData, lead: Lead|null, assigned_user_id?: int|null}  $passable
     * @return array{lead_data: LeadData, lead: Lead|null, assigned_user_id?: int|null}
     */
    public function handle(array $passable, Closure $next): array
    {
        $mode = $this->routingMode($passable['lead_data']->tenantId);

        // AI First: qualify first — do not assign until AI passes.
        if ($mode === AiRoutingMode::AiFirst) {
            $passable['assigned_user_id'] = null;

            return $next($passable);
        }

        // Pre-persist assignment: routing_tags are empty until AI runs, so this falls back to round-robin.
        $probe = new Lead([
            'tenant_id' => $passable['lead_data']->tenantId,
            'routing_tags' => null,
        ]);

        $assignee = $this->router->assign($probe);
        $passable['assigned_user_id'] = $assignee?->id;

        return $next($passable);
    }

    private function routingMode(string $tenantId): AiRoutingMode
    {
        $setting = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->first();

        return $setting !== null ? $setting->ai_routing_mode : AiRoutingMode::HumanFirst;
    }
}
