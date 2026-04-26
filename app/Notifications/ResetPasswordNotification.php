<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        $expireMinutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject(__('Reset Password Notification'))
            ->greeting(__('Hello!'))
            ->line(__('You are receiving this email because we received a password reset request for your account.'))
            ->action(__('Reset Password'), $url)
            ->line(__('This password reset link will expire in :count minutes.', ['count' => $expireMinutes]))
            ->line(__('If you did not request a password reset, no further action is required.'))
            ->salutation(__('Regards').','.PHP_EOL.config('app.name'));
    }
}
