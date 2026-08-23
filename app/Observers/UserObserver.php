<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\AgentStatusChangedEvent;
use App\Models\User;

class UserObserver
{
    public function updated(User $user): void
    {
        if (! $user->wasChanged('is_online')) {
            return;
        }

        if ($user->tenant_id === null || ! $user->isSalesRep()) {
            return;
        }

        AgentStatusChangedEvent::dispatch($user);
    }
}
