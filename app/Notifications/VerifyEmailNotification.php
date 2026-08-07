<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Lien de vérification. Le lien mène au FRONTEND, jamais à l'API (ADR-0008) :
 * le candidat atterrit sur une page Naja7i.ma, qui poste le jeton à l'API.
 */
class VerifyEmailNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $plainToken) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable->locale ?? 'fr';
        $url = rtrim((string) config('app.frontend_url'), '/')
            .'/'.($locale === 'ar' ? 'ar' : 'fr')
            .'/verifier-email?token='.$this->plainToken;

        return (new MailMessage)
            ->subject(__('mail.verify.subject', [], $locale))
            ->greeting(__('mail.verify.greeting', [], $locale))
            ->line(__('mail.verify.line_1', [], $locale))
            ->action(__('mail.verify.action', [], $locale), $url)
            ->line(__('mail.verify.expiry', [], $locale))
            ->line(__('mail.verify.ignore', [], $locale))
            ->salutation(__('mail.signature', [], $locale));
    }
}
