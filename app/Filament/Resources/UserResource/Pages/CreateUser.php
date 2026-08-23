<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use RuntimeException;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function getRedirectUrl(): string
    {
        return UserResource::getUrl('index');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $actor = auth()->user();

        if (! $actor instanceof User || $actor->tenant_id === null) {
            throw new RuntimeException('Authenticated tenant user is required to create team members.');
        }

        $data['tenant_id'] = $actor->tenant_id;

        $role = UserRole::tryFrom((string) ($data['role'] ?? ''));
        $data['role'] = $role !== null && in_array($role, [UserRole::SalesRep, UserRole::Admin], true)
            ? $role->value
            : UserRole::SalesRep->value;

        return $data;
    }
}
