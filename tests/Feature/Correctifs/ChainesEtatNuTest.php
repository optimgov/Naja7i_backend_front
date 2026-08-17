<?php

namespace Tests\Feature\Correctifs;

use App\Models\Attempt;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * DET-74 — LES CHAÎNES HTTP, ÉPROUVÉES DEPUIS UN ÉTAT NU.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE TROU QUE CES TESTS REFERMENT
 *
 * 448 tests sur 606 font une requête HTTP en ayant posé le contexte tenant à la
 * main dans leur `setUp`. En test, `setUp` et la requête partagent la même
 * instance d'application : le contexte posé SUBSISTE dans le gestionnaire. Le
 * middleware qui devait le poser peut donc être mal placé — ou absent — sans
 * qu'une seule assertion bouge.
 *
 * Mesuré avant d'écrire ces tests : `ResolveTenant` retiré de la chaîne API,
 * **606 tests verts, zéro rouge**. Le middleware qui porte l'isolation
 * multi-organisme était supprimable sans qu'un seul test le remarque. En
 * production il n'y a pas de `setUp` : il est parfaitement load-bearing, et la
 * suite était aveugle à ce fait.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUI REND CES TESTS DIFFÉRENTS DES 448 AUTRES
 *
 * Ils ne posent RIEN. `forget()` est appelé explicitement avant chaque requête,
 * et le test VÉRIFIE qu'il part bien de rien — un état nu qu'on ne contrôle pas
 * n'est pas un état nu.
 *
 * L'ASSERTION PORTE SUR LE COMPORTEMENT, PAS SUR L'ÉTAT INTERNE — et la
 * première écriture s'y est trompée. Elle lisait `isResolved()` APRÈS la
 * requête, en croyant y voir la trace du middleware. Or `TenantContext` est lié
 * en `scoped` (`TenancyServiceProvider`) : l'instance est VIDÉE à la fin de
 * chaque requête, et `app()` en rend une neuve. Le test lisait un objet que le
 * middleware n'avait jamais touché, et rougissait sur du code juste.
 *
 * Ce qui s'observe de l'extérieur, c'est la RÉPONSE : depuis un état nu, sans
 * qu'aucun `setUp` n'ait rien posé, la requête aboutit — donc quelque chose a
 * résolu le tenant, et seul le middleware pouvait le faire. La mutation le
 * prouve : retirez `ResolveTenant` de la chaîne, ce test rougit, et lui seul.
 *
 * C'est aussi pourquoi ils ne remplacent pas les `setUp` des 448 autres, et ne
 * doivent pas les remplacer : une fixture a besoin du contexte pour créer ses
 * données. Un seul test par chaîne suffit à rendre l'ordre observable.
 */
class ChainesEtatNuTest extends TestCase
{
    use RefreshDatabase;

    /** Le chemin de la sonde — hors de tout espace de noms du produit. */
    private const SONDE = '/_sonde-etat-nu-web';

    /**
     * LA CHAÎNE API — celle de toute la boucle candidat.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * LE CHOIX DE LA ROUTE EST LE TEST. Première écriture : `/api/v1/plans`,
     * choisie parce que publique — « l'authentification amènerait ses propres
     * résolutions et brouillerait ce qu'on mesure ». Raisonnement plausible,
     * et faux. Mesuré : `ResolveTenant` retiré du groupe `api`, la suite entière
     * reste verte, CE TEST COMPRIS. `plans` est un catalogue d'offres, il n'est
     * pas isolé par organisme : il répond 200 sans tenant, et le test ne
     * discriminait rien. Il aurait rejoint les 448 qu'il devait dénoncer.
     *
     * Ce qui dépend VRAIMENT du tenant, c'est le trait `BelongsToTenant` : son
     * scope global appelle `TenantContext::id()`, qui lève quand rien n'est
     * résolu. `GET me/attempts` interroge `Attempt`, qui le porte. La route est
     * donc choisie sur ce que le code fait, pas sur ce qui semblait commode.
     *
     * L'authentification est ARRANGÉE sous contexte — comme les 448 autres, et
     * c'est légitime : une fixture a besoin du tenant pour créer ses lignes. La
     * différence tient dans le `forget()` qui suit : au moment de la requête,
     * plus rien n'est posé. Si elle aboutit, quelqu'un l'a résolu pendant.
     */
    public function test_la_chaine_api_resout_le_tenant_depuis_un_etat_nu(): void
    {
        $candidat = $this->candidatArrangeSousContexte();

        app(TenantContext::class)->forget();

        $this->assertFalse(
            app(TenantContext::class)->isResolved(),
            'Le test doit partir d’un état NU. S’il est déjà résolu ici, il ne mesure rien.'
        );

        $reponse = $this->actingAs($candidat)->getJson('/api/v1/me/attempts');

        $this->assertSame(
            200,
            $reponse->getStatusCode(),
            'La chaîne API n’aboutit pas depuis un état nu. Aucun contexte n’était posé '
            .'au moment de la requête : seul `ResolveTenant` pouvait le faire, et le '
            .'scope global de `BelongsToTenant` lève sans lui. Vérifiez '
            .'`bootstrap/app.php`, groupe `api`. Réponse obtenue : '
            .$reponse->getStatusCode()
        );
    }

    /**
     * Un candidat créé SOUS contexte — la seule façon d'écrire ses lignes, qui
     * sont isolées par organisme. Le contexte est oublié juste après par
     * l'appelant : c'est lui qui fait l'état nu, pas cette méthode.
     */
    private function candidatArrangeSousContexte(): User
    {
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $user = User::create([
            'email' => 'etat-nu@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
        ]);

        $user->markEmailAsVerified();
        $user->grantCandidateRole();

        return $user->fresh();
    }

    /**
     * LA CHAÎNE WEB — éprouvée par une SONDE, et il faut dire pourquoi.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * CE QUE LE GROUPE `web` PORTE VRAIMENT, mesuré et non supposé
     *
     * Première écriture : `GET /admin/login`, et elle ne discriminait rien.
     * Mesuré : `ResolveTenant` retiré du groupe `web`, ce test restait VERT.
     * La raison est que les routes du panneau portent leur PROPRE liste de
     * middlewares, qui contient déjà `ResolveTenant` — le groupe `web` n'y
     * entre pas. Le test interrogeait donc une autre chaîne que celle qu'il
     * prétendait éprouver.
     *
     * L'inventaire du groupe `web` nu, en production : `/` (une vue statique,
     * qui ne touche aucun modèle) et les points d'entrée de Livewire. AUCUNE
     * route applicative n'y dépend du tenant — sauf `POST /livewire/update`,
     * qui porte la connexion au back-office, et qu'on ne sait pas conduire
     * honnêtement en HTTP depuis un test : sa charge utile est un instantané
     * signé par Livewire, et la fabriquer à la main éprouverait notre copie du
     * format, pas la chaîne.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * D'OÙ LA SONDE, ET SES LIMITES
     *
     * On enregistre une route sur le groupe `web` qui interroge un modèle
     * isolé. La requête est RÉELLE et traverse la vraie chaîne ; ce qui est
     * artificiel, c'est la destination. La sonde établit donc « le groupe `web`
     * résout le tenant pour ce qui le traverse » — pas « la connexion au
     * back-office marche ». Ce second fait est tenu par
     * `PanneauAccesHttpTest::test_la_route_de_livewire_resout_le_tenant`, qui
     * lit le groupe là où le noyau le tient.
     *
     * CONSÉQUENCE ASSUMÉE : retirer `ResolveTenant` du groupe `web` fait rougir
     * DEUX tests — celui-ci et celui de `PanneauAccesHttpTest`. Ce n'est pas un
     * défaut de discrimination : les deux portent exactement le même fait, l'un
     * par la structure, l'autre par une requête. On le note pour que personne
     * n'en déduise plus tard qu'un des deux est redondant et le supprime — le
     * structurel survit aux versions de Livewire, la sonde survit aux
     * changements de nom de route.
     */
    public function test_la_chaine_web_resout_le_tenant_depuis_un_etat_nu(): void
    {
        Route::middleware('web')->get(self::SONDE, fn () => response()->json([
            'attempts' => Attempt::count(),
        ]));

        app(TenantContext::class)->forget();

        $this->assertFalse(app(TenantContext::class)->isResolved(), 'Le test doit partir d’un état NU.');

        $reponse = $this->get(self::SONDE);

        $this->assertSame(
            200,
            $reponse->getStatusCode(),
            'La chaîne WEB n’a pas résolu le tenant : le scope global de '
            .'`BelongsToTenant` lève sans lui. C’est ce groupe que porte '
            .'`POST /livewire/update`, donc la connexion au back-office. Vérifiez '
            .'`bootstrap/app.php`, groupe `web`. Réponse obtenue : '
            .$reponse->getStatusCode()
        );
    }

    /**
     * LA GARANTIE — aucune chaîne HTTP ne s'ajoute sans son test d'état nu.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * CE QU'ELLE PEUT ET NE PEUT PAS FAIRE, dit franchement
     *
     * On ne peut pas vérifier automatiquement qu'un test EXISTE pour une
     * chaîne : rien ne relie un nom de méthode à un groupe de middlewares, et
     * un tel lien serait une convention de nommage qu'on croirait tenue.
     *
     * Ce qui se vérifie, en revanche, c'est l'INVENTAIRE : les groupes déclarés
     * sont exactement ceux que ce fichier couvre. Une chaîne ajoutée demain fait
     * rougir ce test, et son message dit quoi écrire. Ce n'est pas la garantie
     * complète — c'est celle qui est honnête.
     *
     * Le panneau Filament n'est pas un groupe du noyau : il porte sa propre
     * liste, et `PanneauAccesHttpTest` la tient depuis l'extérieur. Il est cité
     * ici pour que l'inventaire soit complet à la lecture.
     */
    public function test_aucune_chaine_http_n_echappe_a_l_inventaire(): void
    {
        $couvertes = ['web', 'api'];

        $noyau = app(Kernel::class);
        $declares = array_keys(
            (new \ReflectionClass($noyau))->getProperty('middlewareGroups')->getValue($noyau)
        );

        sort($declares);
        sort($couvertes);

        $this->assertSame(
            $couvertes,
            $declares,
            'Les groupes de middlewares déclarés ne sont plus ceux que ce fichier '
            .'couvre. Une chaîne HTTP nouvelle doit recevoir son test d’état nu — '
            .'sans quoi elle rejoint le trou de DET-74 : 448 tests qui valident une '
            .'destination sans valider le chemin. Écrivez-le sur le modèle des deux '
            .'ci-dessus, puis ajoutez son nom ici.'
        );

        /* Le panneau Filament n'est pas un groupe du noyau — il porte sa propre
         * liste. On ancre sur la route que le test d'état nu ci-dessus emprunte
         * VRAIMENT : si elle est renommée, c'est ce test-là qu'il faut reprendre,
         * et le message doit le dire. Ancrer sur une autre route du panneau
         * aurait fait de cette assertion une décoration. */
        $this->assertNotNull(
            Route::getRoutes()->getByName('filament.admin.auth.login'),
            'La page de connexion du panneau a changé de nom. C’est elle qu’emprunte '
            .'`test_la_chaine_web_resout_le_tenant_depuis_un_etat_nu` : reprenez ce '
            .'test avec elle, sans quoi la chaîne web redevient non éprouvée.'
        );
    }
}
