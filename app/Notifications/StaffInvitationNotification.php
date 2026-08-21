<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class StaffInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $plainToken) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable->locale === 'ar' ? 'ar' : 'fr';
        $url = rtrim((string) config('app.frontend_url'), '/')
            ."/{$locale}/invitation-personnel?token={$this->plainToken}";

        return (new MailMessage)
            ->subject(__('mail.staff_invitation.subject', [], $locale))
            ->greeting(__('mail.staff_invitation.greeting', [], $locale))
            ->line(__('mail.staff_invitation.line_1', [], $locale))
            ->action(__('mail.staff_invitation.action', [], $locale), $url)
            ->line(__('mail.staff_invitation.expiry', ['hours' => config('naja7i.staff_invitation.expire_hours')], $locale))
            ->line(__('mail.staff_invitation.ignore', [], $locale))
            ->salutation(__('mail.signature', [], $locale));
    }
}
