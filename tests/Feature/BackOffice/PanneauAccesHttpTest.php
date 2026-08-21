<?php

namespace Tests\Feature\BackOffice;

use App\Http\Middleware\ResolveTenant;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * L'ACCÈS HTTP RÉEL AU PANNEAU — ce que les autres tests du back-office ne
 * peuvent pas voir.
 *
 * `PanneauCouvertureTest`, `PanneauRedactionTest` et `PanneauSourcesTest`
 * ouvrent tous leur `setUp()` par :
 *
 *     app(TenantContext::class)->set(Tenant::where('kind','platform')->…);
 *
 * C'est légitime — ils testent le CONTENU du panneau, et il leur faut un
 * contexte pour construire leurs jeux d'essai. Mais l'effet de bord est que
 * l'ordre des middlewares devient sans conséquence : le contexte est déjà là
 * quand la requête part. Ces tests valident le panneau sans valider le chemin
 * qui y mène.
 *
 * Le défaut que cette classe existe pour empêcher : `ResolveTenant` était placé
 * en DERNIER dans `->middleware([...])`, donc après `AuthenticateSession`. Or
 * c'est ce middleware-là qui résout l'utilisateur depuis le cookie « remember »,
 * et Filament appelle à cet instant `User::canAccessPanel()`, qui interroge
 * `PermissionResolver`, qui réclame le tenant. Résultat : toute ouverture de
 * `/admin` par un utilisateur déjà connecté rendait une 500
 * `NoTenantResolvedException` — en production comme en local, jamais en test.
 *
 * Trouvé en faisant tourner la pile complète sur un poste, pas en relisant.
 */
class PanneauAccesHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $administrateur;

    protected function setUp(): void
    {
        parent::setUp();

        $contexte = app(TenantContext::class);
        $plateforme = Tenant::where('kind', 'platform')->firstOrFail();

        // Le contexte est posé UNIQUEMENT le temps de construire le compte :
        // écrire une appartenance sans tenant résolu est refusé, et c'est une
        // garantie du PAS-1 qu'on ne cherche pas à contourner.
        $contexte->set($plateforme);

        $this->administrateur = User::create([
            'email' => 'panneau@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
            'status' => 'active',
        ]);
        $this->administrateur->markEmailAsVerified();
        $this->administrateur->memberships()->create([
            'role_id' => Role::where('code', 'super_admin')->whereNull('tenant_id')->value('id'),
        ]);

        /*
         * PUIS ON LE RETIRE — c'est tout l'objet de cette classe.
         *
         * À partir d'ici, seule la chaîne de middlewares peut résoudre le
         * tenant. Si elle ne le fait pas au bon moment, la requête échoue,
         * exactement comme dans un navigateur.
         */
        $contexte->forget();
        $this->administrateur = $this->administrateur->fresh();
    }

    /**
     * LE TEST QUI DISCRIMINE.
     *
     * Il porte sur l'ORDRE, parce que c'est l'ordre qui était faux et que
     * l'ordre est vérifiable sans dépendre du chemin exact par lequel Filament
     * résout l'utilisateur — chemin qui peut changer d'une version à l'autre
     * du paquet. Remettre `ResolveTenant` en fin de liste fait rougir ce test
     * immédiatement.
     *
     * `SetLocale`, lui, doit rester APRÈS l'authentification : il lit une
     * préférence sur `$request->user()`. Les deux middlewares n'ont pas la
     * même contrainte, et c'est en les traitant comme une paire qu'on avait
     * placé le premier trop loin.
     */
    public function test_resolve_tenant_precede_toute_resolution_d_utilisateur(): void
    {
        $chaine = Route::getRoutes()
            ->getByName('filament.admin.pages.couverture')
            ->gatherMiddleware();

        $position = static function (string $classe) use ($chaine): int {
            $index = array_search($classe, $chaine, true);

            static::assertNotFalse(
                $index,
                $classe.' est absent de la chaîne du panneau : '.implode(', ', $chaine)
            );

            return (int) $index;
        };

        $tenant = $position(ResolveTenant::class);

        $this->assertLessThan(
            $position(AuthenticateSession::class),
            $tenant,
            'ResolveTenant doit précéder AuthenticateSession : ce middleware résout '
            .'l’utilisateur depuis le cookie « remember », et Filament appelle alors '
            .'canAccessPanel(), qui exige un tenant.'
        );

        $this->assertLessThan(
            $position(Authenticate::class),
            $tenant,
            'ResolveTenant doit précéder l’authentification du panneau.'
        );
    }

    /**
     * LA ROUTE DE LIVEWIRE — celle que le panneau ne couvre pas.
     *
     * Le formulaire de connexion de Filament est un composant Livewire : sa
     * méthode `authenticate()` tourne sur `POST /livewire/update`, avec le
     * groupe `web` et rien d'autre. Les middlewares déclarés sur le panneau
     * n'y sont PAS appliqués — c'est ce que la trace de la 500 a montré, et
     * c'est ce que ce test empêche de revenir.
     *
     * Il vaut pour tout ce que Livewire sert : la connexion, mais aussi chaque
     * interaction avec un tableau, un formulaire ou une action du back-office.
     */
    public function test_la_route_de_livewire_resout_le_tenant(): void
    {
        /*
         * DEUX PIÈGES, MESURÉS PLUTÔT QUE SUPPOSÉS — la première écriture de ce
         * test tombait dans les deux, et cassait avant même d'assurer quoi que
         * ce soit.
         *
         * 1. LA ROUTE NE S'APPELLE PAS `livewire.update`. Livewire la nomme
         *    `default.livewire.update` — le préfixe est celui de son « pack »
         *    par défaut. `getByName('livewire.update')` rendait donc `null`, et
         *    le test mourait sur un appel de méthode, pas sur son assertion.
         *
         * 2. `gatherMiddleware()` NE DÉPLIE PAS LES GROUPES. Sur cette route il
         *    rend exactement `['web']` — l'alias, jamais son contenu. Chercher
         *    `ResolveTenant::class` là-dedans n'aurait rien trouvé même avec le
         *    bon nom de route, et aurait fait croire à un défaut inexistant.
         *
         * On lit donc le groupe `web` tel que le noyau le tient : c'est là que
         * `$middleware->web(prepend: …)` écrit, et c'est ce que la route
         * exécutera réellement.
         */
        $route = Route::getRoutes()->getByName('default.livewire.update');

        $this->assertNotNull(
            $route,
            'La route de mise à jour de Livewire est introuvable. Si Livewire a changé '
            .'le nom de son pack, ce test doit suivre — pas être supprimé.'
        );

        $this->assertContains('web', $route->gatherMiddleware());

        $noyau = app(Kernel::class);
        $groupes = (new \ReflectionClass($noyau))->getProperty('middlewareGroups')->getValue($noyau);
        $chaine = $groupes['web'] ?? [];

        $this->assertContains(
            ResolveTenant::class,
            $chaine,
            'Le groupe `web` doit résoudre le tenant : c’est lui que porte la route de '
            .'Livewire, qui exécute la connexion au back-office — pas les routes du '
            .'panneau. Chaîne observée : '.implode(', ', $chaine)
        );

        /* ET EN TÊTE : la connexion résout l'utilisateur dès `StartSession`. */
        $this->assertSame(
            ResolveTenant::class,
            $chaine[0] ?? null,
            'ResolveTenant doit venir EN TÊTE du groupe `web`, avant `StartSession` : '
            .'c’est la session qui fait résoudre l’utilisateur, et `canAccessPanel()` '
            .'réclame alors le tenant.'
        );
    }

    /**
     * LA GARANTIE LARGE.
     *
     * Honnêteté sur sa portée : ce test-ci n'aurait PAS attrapé le défaut
     * d'origine. `actingAs()` place l'utilisateur directement sur le garde,
     * donc la résolution précoce qui déclenchait la panne n'a pas lieu. Il
     * couvre une famille plus vaste — « /admin répond sans contexte
     * préétabli » — et c'est le test de l'ordre, au-dessus, qui discrimine.
     *
     * Il est écrit quand même : le jour où un middleware nouvellement ajouté
     * interrogera les permissions avant `ResolveTenant`, c'est lui qui parlera.
     */
    public function test_le_panneau_repond_sans_contexte_pretabli(): void
    {
        $this->assertFalse(
            app(TenantContext::class)->isResolved(),
            'Le contexte doit être vide avant la requête, sinon ce test ne mesure rien.'
        );

        $this->actingAs($this->administrateur)
            ->get('/admin')
            ->assertOk();
    }

    /**
     * Un compte sans permission de back-office ne doit pas obtenir une 500 non
     * plus : il doit être ÉCONDUIT. La distinction compte — une erreur serveur
     * sur un refus d'accès est un défaut, pas une protection.
     */
    public function test_un_compte_sans_permission_est_econduit_et_non_casse(): void
    {
        $contexte = app(TenantContext::class);
        $contexte->set(Tenant::where('kind', 'platform')->firstOrFail());

        $candidat = User::create([
            'email' => 'candidat-panneau@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
            'status' => 'active',
        ]);
        $candidat->markEmailAsVerified();
        $candidat->memberships()->create([
            'role_id' => Role::where('code', 'candidat')->whereNull('tenant_id')->value('id'),
        ]);

        $contexte->forget();

        $reponse = $this->actingAs($candidat->fresh())->get('/admin');

        $this->assertContains(
            $reponse->getStatusCode(),
            [302, 403],
            'Attendu une redirection ou un refus, obtenu '.$reponse->getStatusCode().'.'
        );
    }
}
