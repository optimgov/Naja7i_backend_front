<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final /**
 * ENVOI DIFFÉRÉ — DET-14, et le défaut est survenu pour de vrai.
 *
 * Le 27 août 2026 à 21:32:55, une inscription sur la préproduction a rendu un
 * 500. Le journal donne la cause : « Connection could not be established with
 * host ssl://mail.optimgov.com:465 — Connection refused ». Le compte, lui,
 * AVAIT ÉTÉ CRÉÉ à la même seconde.
 *
 * DET-14 l'annonçait mot pour mot : « l'aller-retour SMTP se fait pendant la
 * requête d'inscription ; un fournisseur en panne la fait échouer en 500 alors
 * que le compte est déjà créé ». La personne voit une erreur interne, ne reçoit
 * aucun courriel, et ne peut pas se réinscrire — son adresse est prise.
 *
 * `ShouldQueue` sépare les deux : l'inscription réussit, l'envoi part sur la
 * file Redis, et une messagerie en panne se solde par un job qui réessaie au
 * lieu d'une porte fermée.
 *
 * `$afterCommit` EST INDISPENSABLE ICI, pas décoratif : sans lui, le job peut
 * être pris par un worker AVANT que la transaction qui crée le compte ne soit
 * validée — le worker chercherait alors un destinataire qui n'existe pas
 * encore. C'est aussi la moitié de DET-83, où l'invitation du personnel part
 * DANS la transaction de création.
 */
class StaffInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $plainToken)
    {
        /* Le trait `Queueable` porte déjà `$afterCommit` ; on l'ACTIVE ici plutôt
         * que de le redéclarer, ce que PHP refuse. Le job attend donc la
         * validation de la transaction qui l'a déclenché. */
        $this->afterCommit();
    }

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
