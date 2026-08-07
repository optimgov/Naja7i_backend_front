<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable->locale ?? 'fr';
        $url = rtrim((string) config('app.frontend_url'), '/')
            .'/'.($locale === 'ar' ? 'ar' : 'fr')
            .'/nouveau-mot-de-passe?token='.$this->token
            .'&email='.urlencode($notifiable->email);

        return (new MailMessage)
            ->subject(__('mail.reset.subject', [], $locale))
            ->greeting(__('mail.reset.greeting', [], $locale))
            ->line(__('mail.reset.line_1', [], $locale))
            ->action(__('mail.reset.action', [], $locale), $url)
            ->line(__('mail.reset.expiry', [], $locale))
            ->line(__('mail.reset.ignore', [], $locale))
            ->salutation(__('mail.signature', [], $locale));
    }
}
