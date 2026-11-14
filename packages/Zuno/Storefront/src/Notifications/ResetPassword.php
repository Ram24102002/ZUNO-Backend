<?php

namespace Zuno\Storefront\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Password reset email whose link opens the React shop's reset page. */
class ResetPassword extends Notification
{
    public function __construct(protected string $token) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $url = config('zuno.frontend_url').'/account/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->email,
        ]);
        $minutes = config('auth.passwords.customers.expire', 60);

        return (new MailMessage)
            ->subject('Reset your ZUNO password')
            ->greeting('Hi '.$notifiable->first_name.',')
            ->line('Someone asked to reset the password for your ZUNO account.')
            ->action('Choose a new password', $url)
            ->line("This link works for $minutes minutes. If you did not ask for it, ignore this email.");
    }
}
