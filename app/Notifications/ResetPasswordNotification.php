<?php

namespace App\Notifications;

use App\Support\FrontendUrl;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends ResetPassword
{
    /**
     * Build the frontend password-reset URL containing the token and email.
     */
    protected function resetUrl($notifiable): string
    {
        return FrontendUrl::build(
            config('contactly.frontend.password_reset_url'),
            [
                'token' => $this->token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]
        );
    }

    /**
     * Build the password-reset email.
     */
    protected function buildMailMessage($url): MailMessage
    {
        $minutes = (int) config(
            'auth.passwords.'.config('auth.defaults.passwords').'.expire',
            60
        );

        return (new MailMessage)
            ->subject('Reset your CONTACTLY password')
            ->greeting('Hello,')
            ->line('You are receiving this email because a password reset was requested for your CONTACTLY account.')
            ->action('Reset password', $url)
            ->line("This link expires in {$minutes} minutes and can only be used once.")
            ->line('If you did not request a password reset, no action is needed.');
    }
}
