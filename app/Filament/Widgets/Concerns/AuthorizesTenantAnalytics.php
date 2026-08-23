<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Concerns;

use App\Models\User;

trait AuthorizesTenantAnalytics
{
    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canManageTenantSettings();
    }

    public function mountAuthorizesTenantAnalytics(): void
    {
        abort_unless(static::canView(), 403);
    }
}
