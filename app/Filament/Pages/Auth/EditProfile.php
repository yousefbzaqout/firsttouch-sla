<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;
use SensitiveParameter;

class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getAvatarFormComponent(),
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getTelegramChatIdFormComponent(),
                $this->getOnlineStatusFormComponent(),
                $this->getSkillsTagsFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
            ]);
    }

    protected function getOnlineStatusFormComponent(): Component
    {
        return Toggle::make('is_online')
            ->label('Online for lead routing')
            ->helperText('Turn off when unavailable so SLA auto-reassignment skips you.')
            ->default(true);
    }

    protected function getSkillsTagsFormComponent(): Component
    {
        return TagsInput::make('skills_tags')
            ->label('Skills tags')
            ->helperText('Tags used to match you with inbound leads (e.g. English, VIP, Technical).')
            ->placeholder('Add a skill tag')
            ->suggestions([
                'English',
                'Arabic',
                'VIP',
                'Budget',
                'Technical',
                'Ads',
            ]);
    }

    protected function getTelegramChatIdFormComponent(): Component
    {
        return TextInput::make('telegram_chat_id')
            ->label('Telegram chat ID')
            ->numeric()
            ->helperText('Used for personal lead alerts and SLA escalations. Message @userinfobot on Telegram to find your ID.')
            ->nullable();
    }

    protected function getAvatarFormComponent(): Component
    {
        $user = $this->getUser();
        $tenantId = is_string($user->getAttribute('tenant_id'))
            ? $user->getAttribute('tenant_id')
            : 'shared';

        return FileUpload::make('avatar_url')
            ->label('Avatar')
            ->image()
            ->avatar()
            ->disk('public')
            ->directory('avatars/'.$tenantId)
            ->visibility('public')
            ->maxSize(2048)
            ->imageEditor()
            ->circleCropper()
            ->nullable();
    }

    protected function getPasswordFormComponent(): Component
    {
        // Rely on the User model's `hashed` cast — do not Hash::make here.
        return TextInput::make('password')
            ->label(__('filament-panels::auth/pages/edit-profile.form.password.label'))
            ->validationAttribute(__('filament-panels::auth/pages/edit-profile.form.password.validation_attribute'))
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->rule(Password::default())
            ->showAllValidationMessages()
            ->autocomplete('new-password')
            ->dehydrated(fn (#[SensitiveParameter] $state): bool => filled($state))
            ->live(debounce: 500)
            ->same('passwordConfirmation');
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return TextInput::make('passwordConfirmation')
            ->label(__('filament-panels::auth/pages/edit-profile.form.password_confirmation.label'))
            ->validationAttribute(__('filament-panels::auth/pages/edit-profile.form.password_confirmation.validation_attribute'))
            ->password()
            ->autocomplete('new-password')
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->visible(fn (Get $get): bool => filled($get('password')))
            ->dehydrated(false);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(#[SensitiveParameter] array $data): array
    {
        unset($data['passwordConfirmation'], $data['currentPassword']);

        if (! array_key_exists('password', $data) || blank($data['password'])) {
            unset($data['password']);
        }

        return $data;
    }
}
