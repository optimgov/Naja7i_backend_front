<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Les limiteurs nommés, et la frontière entre transport et sécurité.
 *
 * Ce fichier existe parce que la première exécution de la recette de bout en
 * bout en intégration continue a échoué sur `429 RATE_LIMIT_EXCEEDED` à la
 * CINQUIÈME requête publique, alors qu'aucun plafond n'était atteint. La cause
 * n'était pas le réglage : c'était le PARTAGE du compteur entre routes.
 *
 * Ce qui est vérifié ici, dans l'ordre :
 *   1. deux routes publiques ne partagent plus un compteur ;
 *   2. le compteur d'une route authentifiée non plus ;
 *   3. le profil par défaut est celui du produit, et il refuse comme avant ;
 *   4. le profil `recette` relève le transport ;
 *   5. il ne relève PAS la route de la file d'envoi ;
 *   6. il ne relève PAS les limiteurs de sécurité, qui vivent dans le domaine.
 */
class RateLimitProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());
        Notification::fake();
    }

    /** Une inscription volontairement invalide : elle compte au débit sans créer de compte. */
    private function inscriptionRefusee()
    {
        return $this->postJson('/api/v1/auth/register', []);
    }

    // --- 1 et 2 : les compteurs ne sont plus partagés ---------------------

    public function test_deux_routes_publiques_ne_partagent_plus_un_compteur(): void
    {
        /*
         * LE DÉFAUT, EXACTEMENT. `ThrottleRequests` signe une requête sans
         * session par `sha1(domaine|ip)` : la route n'entre pas dans la clé.
         * Six ouvertures de session — bien en deçà du plafond de `login`, 20 —
         * remplissaient donc le seau que `register` compare à SON plafond, 6.
         * L'inscription suivante était refusée sans que rien ne l'explique.
         */
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => "inconnu{$i}@naja7i.ma",
                'password' => 'mauvais-mot-de-passe',
            ]);
        }

        $this->inscriptionRefusee()->assertStatus(422);
    }

    public function test_repondre_n_empeche_plus_d_ouvrir_une_serie(): void
    {
        /*
         * MÊME DÉFAUT, CÔTÉ AUTHENTIFIÉ, et il coûtait plus cher : pour une
         * requête avec session la signature est `sha1(user_id)`, partagée elle
         * aussi. `reponse` (120/min) et `ouverture-serie` (10/min) tiraient sur
         * le même seau — onze réponses à des questions suffisaient donc à
         * fermer l'ouverture d'une nouvelle série au candidat le plus assidu.
         *
         * On ne mesure pas ici le parcours complet — c'est l'affaire des tests
         * de passation. On mesure que les DEUX seaux sont distincts, ce qui se
         * lit dans la clé du limiteur.
         */
        $limiteurs = array_keys(config('naja7i.rate_limits.limits'));

        $this->assertContains('reponse', $limiteurs);
        $this->assertContains('ouverture-serie', $limiteurs);
        $this->assertNotSame(
            config('naja7i.rate_limits.limits.reponse'),
            config('naja7i.rate_limits.limits.ouverture-serie'),
            'Les deux gestes ont des seuils distincts : ils doivent avoir des seaux distincts.'
        );
    }

    public function test_le_seuil_est_par_identite_et_non_global(): void
    {
        /*
         * L'AUTRE MOITIÉ DU LIMITEUR, et elle se perd facilement : nommer
         * sépare les ROUTES, `->by()` sépare les CANDIDATS. Sans `->by()`, la
         * clé se réduit au nom du limiteur et le seuil devient mondial — six
         * inscriptions suffiraient à fermer la route à toute la plateforme.
         */
        for ($i = 0; $i < 7; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
                ->postJson('/api/v1/auth/register', []);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/api/v1/auth/register', [])->assertStatus(429);

        // Une autre adresse n'a rien consommé : elle passe.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])
            ->postJson('/api/v1/auth/register', [])->assertStatus(422);
    }

    // --- 3 : le produit n'a pas bougé -------------------------------------

    public function test_le_profil_par_defaut_est_celui_du_produit(): void
    {
        $this->assertSame('production', config('naja7i.rate_limits.profile'));
    }

    public function test_l_inscription_refuse_toujours_au_septieme_essai(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->inscriptionRefusee()->assertStatus(422);
        }

        $this->inscriptionRefusee()->assertStatus(429);
    }

    // --- 4 et 5 : ce que le profil de recette relève, et ce qu'il ne relève pas

    public function test_le_profil_recette_releve_le_transport(): void
    {
        config()->set('naja7i.rate_limits.profile', 'recette');

        // Sept essais : le septième était refusé sous le profil du produit.
        for ($i = 0; $i < 7; $i++) {
            $this->inscriptionRefusee()->assertStatus(422);
        }
    }

    public function test_le_profil_recette_ne_releve_pas_la_route_de_la_file_d_envoi(): void
    {
        /*
         * LA RÈGLE QUI NE SE DISCUTE PAS. `reponse` est la route qu'écoule la
         * file d'envoi hors connexion, et c'est la seule dont un vrai 429 a
         * déjà produit un faux vert en recette. Elle garde un limiteur RÉEL
         * dans tous les profils — sinon la recette cesse de rencontrer ce
         * qu'elle est censée éprouver.
         */
        $seuils = config('naja7i.rate_limits.limits.reponse');

        $this->assertSame(
            $seuils['production'],
            $seuils['recette'],
            'La route de la file d’envoi ne se relève jamais, dans aucun profil.'
        );
    }

    public function test_un_profil_inconnu_retombe_sur_le_produit(): void
    {
        // Une faute de frappe dans une variable d'environnement ne doit pas
        // ouvrir les vannes en silence.
        config()->set('naja7i.rate_limits.profile', 'recete');

        for ($i = 0; $i < 6; $i++) {
            $this->inscriptionRefusee()->assertStatus(422);
        }

        $this->inscriptionRefusee()->assertStatus(429);
    }

    // --- 6 : la sécurité n'est pas du transport ---------------------------

    public function test_le_limiteur_de_securite_du_renvoi_reste_reel_en_recette(): void
    {
        /*
         * Le renvoi de vérification porte DEUX limiteurs : celui de la route
         * (transport, 6/min, relevé en recette) et le sien, par adresse — 3 par
         * 900 s, dans le contrôleur, parce que c'est une arme de harcèlement
         * potentielle. Le profil ne voit que le premier.
         */
        config()->set('naja7i.rate_limits.profile', 'recette');

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/email/resend', ['email' => 'cible@naja7i.ma'])
                ->assertStatus(202);
        }

        $this->postJson('/api/v1/auth/email/resend', ['email' => 'cible@naja7i.ma'])
            ->assertStatus(429);
    }

    public function test_la_limite_de_connexion_reste_reelle_en_recette(): void
    {
        // `LoginThrottle` — trois agrégats, dans le domaine. Le profil de
        // transport ne l'atteint pas : 30 essais depuis une IP restent 30.
        config()->set('naja7i.rate_limits.profile', 'recette');

        for ($i = 1; $i <= 30; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
                ->postJson('/api/v1/auth/login', [
                    'email' => "cible{$i}@naja7i.ma", 'password' => 'Motdepasse2026',
                ]);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'cible99@naja7i.ma', 'password' => 'Motdepasse2026',
            ])->assertStatus(429);
    }
}
