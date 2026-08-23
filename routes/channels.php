<?php

declare(strict_types=1);

use App\Broadcasting\TenantChannel;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('tenant.{tenantId}', function (User $user, string $tenantId): bool {
    return app(TenantChannel::class)->join($user, $tenantId);
});
