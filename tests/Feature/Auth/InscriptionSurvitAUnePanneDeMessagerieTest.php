<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * DET-14 — UNE MESSAGERIE EN PANNE NE DOIT PLUS FERMER LA PORTE.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE DÉFAUT EST SURVENU POUR DE VRAI, PAS EN THÉORIE
 *
 * Le 27 août 2026 à 21:32:55, une inscription sur la préproduction a rendu un
 * 500. Journal : « Connection could not be established with host
 * ssl://mail.optimgov.com:465 — Connection refused ». Le compte
 * `lyceentest@gmail.com` avait été créé à LA MÊME SECONDE.
 *
 * La personne voyait « une erreur interne est survenue », ne recevait aucun
 * courriel, et ne pouvait pas se réinscrire — son adresse était déjà prise.
 * Deux comptes se sont retrouvés dans cet état.
 *
 * DET-14 l'annonçait mot pour mot depuis le PAS-3.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE CE FICHIER DÉFEND
 *
 * Que l'inscription et l'envoi soient DEUX temps. Le premier ne dépend plus du
 * second. Un test qui vérifierait seulement « la notification part » resterait
 * vert avec un envoi synchrone : c'est la panne qu'il faut simuler.
 */
class InscriptionSurvitAUnePanneDeMessagerieTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function corps(string $email = 'nouveau.candidat@naja7i.test'): array
    {
        return [
            'email' => $email,
            'password' => 'une-phrase-de-passe-solide',
            'password_confirmation' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
            'terms_accepted' => true,
            'privacy_notice_acknowledged' => true,
            'marketing_granted' => false,
        ];
    }

    /**
     * LE TEST QUI REPRODUIT LA PANNE DU 27 AOÛT.
     *
     * Le transport lève exactement ce que le serveur a levé. Avec un envoi
     * synchrone, l'exception remonte et la requête rend 500 ; différé, elle ne
     * peut plus atteindre la requête.
     */
    public function test_une_messagerie_injoignable_ne_fait_plus_echouer_l_inscription(): void
    {
        /*
         * ON REPRODUIT LA PANNE, ON NE LA SIMULE PAS. Un port fermé donne
         * exactement l'exception qu'a levée la 62 — « Connection refused » —
         * sans simulacre qui masquerait la moitié de la façade.
         */
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 9,
            'mail.mailers.smtp.timeout' => 1,
        ]);

        /* La file est feinte : un job différé y est DÉPOSÉ et jamais exécuté.
         * Avec un envoi synchrone, le transport serait atteint pendant la
         * requête et l'exception remonterait — c'est ce qui discrimine. */
        Queue::fake();

        $reponse = $this->postJson('/api/v1/auth/register', $this->corps());

        $reponse->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'nouveau.candidat@naja7i.test']);
    }

    /**
     * L'ENVOI EST DIFFÉRÉ, ET IL PART QUAND MÊME.
     *
     * Le compte n'est pas créé « au prix » du courriel : la notification est
     * bien mise en file, elle n'est simplement plus dans le chemin de la
     * requête.
     */
    public function test_la_verification_part_par_la_file_et_non_pendant_la_requete(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', $this->corps('file@naja7i.test'))
            ->assertCreated();

        $utilisateur = User::where('email', 'file@naja7i.test')->sole();

        Notification::assertSentTo($utilisateur, VerifyEmailNotification::class);
    }

    /**
     * LES TROIS NOTIFICATIONS SONT DIFFÉRÉES, PAS SEULEMENT CELLE-CI.
     *
     * L'inventaire rougit si une notification est ajoutée en synchrone : le
     * défaut se rejouerait ailleurs — au mot de passe oublié, ou à l'invitation
     * du personnel, où DET-83 signale qu'il part DANS la transaction.
     */
    public function test_aucune_notification_ne_part_en_synchrone(): void
    {
        $synchrones = [];

        foreach (glob(app_path('Notifications/*.php')) as $fichier) {
            $classe = 'App\\Notifications\\'.basename($fichier, '.php');

            if (! is_subclass_of($classe, ShouldQueue::class)) {
                $synchrones[] = class_basename($classe);
            }
        }

        $this->assertEmpty(
            $synchrones,
            'Notification(s) envoyée(s) pendant la requête : '.implode(', ', $synchrones)
            .'. Un fournisseur en panne ferait alors échouer l’action qui les déclenche (DET-14).'
        );
    }

    /**
     * LE JOB ATTEND LA VALIDATION DE LA TRANSACTION — moitié de DET-83.
     *
     * Sans `$afterCommit`, un worker peut prendre le job avant que la
     * transaction qui crée le compte ne soit validée, et chercher un
     * destinataire qui n'existe pas encore.
     */
    public function test_le_job_ne_part_qu_apres_validation_de_la_transaction(): void
    {
        Queue::fake();

        foreach (glob(app_path('Notifications/*.php')) as $fichier) {
            $classe = 'App\\Notifications\\'.basename($fichier, '.php');
            /* LA PRÉSENCE DE LA PROPRIÉTÉ NE PROUVE RIEN : le trait `Queueable`
             * la donne à toutes. C'est sa VALEUR qui discrimine, et elle n'est
             * vraie que si le constructeur appelle `afterCommit()`. */
            $reflet = new \ReflectionClass($classe);
            $instance = $reflet->newInstanceWithoutConstructor();
            $reflet->getConstructor()?->invokeArgs($instance, array_map(
                fn ($p) => $p->isDefaultValueAvailable() ? $p->getDefaultValue() : 'x',
                $reflet->getConstructor()->getParameters(),
            ));

            $this->assertTrue(
                $instance->afterCommit,
                class_basename($classe).' doit appeler $this->afterCommit() : sinon le job peut '
                .'partir avant que la transaction qui l’a déclenché ne soit validée.'
            );
        }
    }
}
