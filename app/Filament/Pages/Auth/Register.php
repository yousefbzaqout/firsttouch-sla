<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Services\TenantRegistrationService;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;

class Register extends BaseRegister
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getCompanyNameFormComponent(),
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
            ]);
    }

    protected function getCompanyNameFormComponent(): Component
    {
        return TextInput::make('company_name')
            ->label('Company Name')
            ->required()
            ->maxLength(255)
            ->autofocus();
    }

    protected function getNameFormComponent(): Component
    {
        return TextInput::make('name')
            ->label(__('filament-panels::auth/pages/register.form.name.label'))
            ->required()
            ->maxLength(255);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        /** @var array{company_name: string, name: string, email: string, password: string} $payload */
        $payload = [
            'company_name' => (string) $data['company_name'],
            'name' => (string) $data['name'],
            'email' => (string) $data['email'],
            'password' => (string) $data['password'],
        ];

        return app(TenantRegistrationService::class)->register($payload);
    }
}
