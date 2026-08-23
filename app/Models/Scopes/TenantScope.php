<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** @implements Scope<Model> */
class TenantScope implements Scope
{
    private static bool $resolving = false;

    public function apply(Builder $builder, Model $model): void
    {
        // Users must not be scoped via auth()->user() or login becomes an infinite loop.
        if ($model instanceof User || self::$resolving) {
            return;
        }

        self::$resolving = true;

        try {
            $user = auth()->user();

            if ($user instanceof User && $user->tenant_id !== null) {
                $builder->where($model->getTable().'.tenant_id', $user->tenant_id);

                return;
            }

            // No authenticated tenant context: return zero rows.
            // Jobs/webhooks/commands must use withoutGlobalScopes() + explicit tenant_id.
            $builder->whereRaw('0 = 1');
        } finally {
            self::$resolving = false;
        }
    }
}
