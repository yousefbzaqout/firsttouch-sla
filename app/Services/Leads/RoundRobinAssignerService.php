<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Collection;

class RoundRobinAssignerService
{
    public function assign(string $tenantId): ?int
    {
        return $this->assignNext($tenantId);
    }

    /**
     * Pick the next active online sales rep, optionally excluding a user.
     * Offline agents (is_online = false) are strictly excluded.
     */
    public function assignNext(string $tenantId, ?int $excludeUserId = null, bool $onlineOnly = true): ?int
    {
        $reps = $this->eligibleReps($tenantId, $excludeUserId, $onlineOnly);

        return $this->pickNextFrom($reps, $tenantId, $excludeUserId);
    }

    /**
     * Fair round-robin selection limited to an already-filtered candidate pool.
     *
     * @param  Collection<int, User>  $reps
     */
    public function pickNextFrom(Collection $reps, string $tenantId, ?int $excludeUserId = null): ?int
    {
        if ($reps->isEmpty()) {
            return null;
        }

        $ordered = $reps->sortBy('id')->values();

        $lastAssigned = Lead::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('assigned_user_id')
            ->when(
                $excludeUserId !== null,
                fn ($query) => $query->where('assigned_user_id', '!=', $excludeUserId),
            )
            ->latest('created_at')
            ->value('assigned_user_id');

        if ($lastAssigned === null) {
            /** @var User $first */
            $first = $ordered->first();

            return $first->id;
        }

        $currentIndex = $ordered->search(fn (User $user): bool => $user->id === (int) $lastAssigned);

        if ($currentIndex === false) {
            /** @var User $first */
            $first = $ordered->first();

            return $first->id;
        }

        $nextIndex = ((int) $currentIndex + 1) % $ordered->count();

        /** @var User $next */
        $next = $ordered->get($nextIndex);

        return $next->id;
    }

    /**
     * @return Collection<int, User>
     */
    private function eligibleReps(string $tenantId, ?int $excludeUserId, bool $onlineOnly): Collection
    {
        return User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('role', UserRole::SalesRep)
            ->where('is_active', true)
            ->when($onlineOnly, fn ($query) => $query->where('is_online', true))
            ->when(
                $excludeUserId !== null,
                fn ($query) => $query->where('id', '!=', $excludeUserId),
            )
            ->orderBy('id')
            ->get();
    }
}
