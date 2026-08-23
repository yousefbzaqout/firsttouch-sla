<?php

declare(strict_types=1);

namespace App\Services\Assignment;

use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\RoundRobinAssignerService;
use Illuminate\Support\Collection;

class ContextAwareRoutingService
{
    public function __construct(
        private readonly RoundRobinAssignerService $roundRobin,
    ) {}

    /**
     * Route a lead to the best-matching online sales rep using routing_tags ∩ skills_tags,
     * falling back to round-robin among the top-scoring (or all) candidates.
     */
    public function assign(Lead $lead, ?int $excludeUserId = null): ?User
    {
        $candidates = $this->onlineCandidates($lead->tenant_id, $excludeUserId);

        if ($candidates->isEmpty()) {
            return null;
        }

        $routingTags = $this->normalizeTags($lead->routing_tags);

        if ($routingTags === []) {
            $userId = $this->roundRobin->pickNextFrom($candidates, $lead->tenant_id, $excludeUserId);

            return $this->findCandidate($candidates, $userId);
        }

        $scored = $candidates->map(function (User $agent) use ($routingTags): array {
            return [
                'user' => $agent,
                'score' => $this->matchScore($routingTags, $this->normalizeTags($agent->skills_tags)),
            ];
        });

        /** @var int $maxScore */
        $maxScore = (int) $scored->max('score');

        /** @var Collection<int, User> $topAgents */
        $topAgents = $scored
            ->filter(fn (array $row): bool => $row['score'] === $maxScore)
            ->map(fn (array $row): User => $row['user'])
            ->values();

        $userId = $this->roundRobin->pickNextFrom($topAgents, $lead->tenant_id, $excludeUserId);

        return $this->findCandidate($topAgents, $userId);
    }

    /**
     * @return Collection<int, User>
     */
    private function onlineCandidates(string $tenantId, ?int $excludeUserId): Collection
    {
        return User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('role', UserRole::SalesRep)
            ->where('is_active', true)
            ->where('is_online', true)
            ->when(
                $excludeUserId !== null,
                fn ($query) => $query->where('id', '!=', $excludeUserId),
            )
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<string>|array<int, mixed>|null  $tags
     * @return list<string>
     */
    private function normalizeTags(?array $tags): array
    {
        if ($tags === null || $tags === []) {
            return [];
        }

        $normalized = [];

        foreach ($tags as $tag) {
            if (! is_string($tag) && ! is_numeric($tag)) {
                continue;
            }

            $value = mb_strtolower(trim((string) $tag));

            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  list<string>  $leadTags
     * @param  list<string>  $skillTags
     */
    private function matchScore(array $leadTags, array $skillTags): int
    {
        if ($leadTags === [] || $skillTags === []) {
            return 0;
        }

        return count(array_intersect($leadTags, $skillTags));
    }

    /**
     * @param  Collection<int, User>  $candidates
     */
    private function findCandidate(Collection $candidates, ?int $userId): ?User
    {
        if ($userId === null) {
            return null;
        }

        return $candidates->first(fn (User $user): bool => $user->id === $userId);
    }
}
