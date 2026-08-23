<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use LogicException;

class ResetPassword extends BaseResetPassword
{
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Reset Password Notification'))
            ->line(__('You are receiving this email because we received a password reset request for your account.'))
            ->action(__('Reset Password'), $this->resetUrl($notifiable))
            ->line(__('This password reset link will expire in :count minutes.', [
                'count' => (string) config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
            ]))
            ->line(__('If you did not request a password reset, no further action is required.'));
    }

    protected function resetUrl(mixed $notifiable): string
    {
        if (! $notifiable instanceof User) {
            throw new LogicException('Password reset notifications require an App\\Models\\User notifiable.');
        }

        return $notifiable->getFilamentResetPasswordUrl($this->token);
    }
}
