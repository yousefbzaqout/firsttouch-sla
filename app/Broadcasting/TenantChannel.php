<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\User;

class TenantChannel
{
    public function join(User $user, string $tenantId): bool
    {
        if (! $user->is_active || $user->tenant_id === null) {
            return false;
        }

        return (string) $user->tenant_id === (string) $tenantId;
    }
}
