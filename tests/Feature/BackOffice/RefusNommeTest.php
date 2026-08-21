<?php

namespace Tests\Feature\BackOffice;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PermissionResolver;
use App\Tenancy\TenantContext;
use Filament\Facades\Filament as FilamentFacade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le refus nomme ce qu'il refuse — D-13.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * DEUX CHOSES DIFFÉRENTES SONT VÉRIFIÉES ICI, ET LA SECONDE PORTE LA PREMIÈRE
 *
 * 1. LA PAGE 403 NOMME. La surface, la permission qui l'ouvre, et ce que le
 *    compte porte. « 403 FORBIDDEN » était correct et inutile.
 *
 * 2. LA PERMISSION DÉCLARÉE EST BIEN CELLE QUI OUVRE. `PERMISSION_REQUISE` est
 *    une déclaration posée à côté d'une politique, donc une seconde source de
 *    vérité — le dépôt en a déjà payé le prix. Elle n'est acceptable qu'avec ce
 *    qui l'empêche de dériver : sur CHAQUE surface, un compte qui porte la
 *    permission déclarée doit entrer, et un compte qui ne la porte pas doit
 *    être refusé. Si une politique change d'avis, ce test rougit.
 *
 * DES RÔLES DU PRODUIT, PAS DES RÔLES FABRIQUÉS. Le référentiel du PAS-9 en
 * fournit déjà deux qui se complètent exactement — `auteur` porte
 * `questions.view` sans `orders.view`, `finance` l'inverse. Inventer un rôle
 * pour le test éprouverait le panneau sous une identité qui n'existe nulle
 * part, et les permissions réelles cesseraient d'être en jeu.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class RefusNommeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());
    }

    /**
     * @return list<array{surface: class-string, adresse: string, permission: string}>
     */
    private function surfaces(): array
    {
        $panneau = FilamentFacade::getPanel('admin');
        $surfaces = [];

        foreach ($panneau->getResources() as $ressource) {
            if (! defined("{$ressource}::PERMISSION_REQUISE")) {
                continue;
            }

            $surfaces[] = [
                'surface' => $ressource,
                'adresse' => $ressource::getUrl('index', panel: 'admin'),
                'permission' => constant("{$ressource}::PERMISSION_REQUISE"),
            ];
        }

        foreach ($panneau->getPages() as $page) {
            if (! defined("{$page}::PERMISSION_REQUISE")) {
                continue;
            }

            $surfaces[] = [
                'surface' => $page,
                'adresse' => $page::getUrl(panel: 'admin'),
                'permission' => constant("{$page}::PERMISSION_REQUISE"),
            ];
        }

        return $surfaces;
    }

    /**
     * Un compte du produit portant exactement le rôle nommé.
     *
     * Le rôle vient du semis ; on ne pose que l'appartenance, ce qui est le
     * geste réel de `roles.assign`.
     */
    private function compte(string $codeDeRole): User
    {
        $user = User::create([
            'email' => "refus.{$codeDeRole}@naja7i.ma",
            'password' => 'Refus-Nomme-2026!',
            'locale' => 'fr',
            'status' => 'active',
        ]);

        $user->memberships()->create([
            'role_id' => Role::where('code', $codeDeRole)->whereNull('tenant_id')->value('id'),
        ]);

        return $user->fresh();
    }

    /** Le rôle du produit qui porte la permission, et celui qui ne la porte pas. */
    private function rolesAutourDe(string $permission): array
    {
        $porteurs = [
            'questions.view' => 'auteur',
            'orders.view' => 'finance',
            'members.view' => 'support',
        ];
        $etrangers = [
            'questions.view' => 'finance',
            'orders.view' => 'auteur',
            'members.view' => 'auteur',
        ];

        $this->assertArrayHasKey(
            $permission, $porteurs,
            "Aucun rôle du produit n'est associé à « {$permission} » dans ce test : une surface a "
            .'déclaré une permission que la table ci-dessus ignore. Complétez-la plutôt que de '
            .'fabriquer un rôle.'
        );

        return [$porteurs[$permission], $etrangers[$permission]];
    }

    public function test_la_permission_declaree_est_bien_celle_qui_ouvre_la_surface(): void
    {
        $surfaces = $this->surfaces();

        $this->assertGreaterThanOrEqual(
            5, count($surfaces),
            'Moins de cinq surfaces déclarent leur permission : le balayage est cassé, ou une '
            .'surface a perdu sa déclaration.'
        );

        $ecarts = [];

        foreach ($surfaces as $cas) {
            [$porteur, $etranger] = $this->rolesAutourDe($cas['permission']);

            /*
             * UNE SESSION NEUVE PAR IDENTITÉ, et ce n'est pas une précaution
             * de confort. `AuthenticateSession` invalide la session dès que
             * l'utilisateur authentifié change, et redirige : sans ce vidage,
             * la seconde requête d'une même méthode de test rend 302 vers la
             * connexion. On mesurerait alors le middleware de session, pas
             * l'autorisation — un test qui passe pour la mauvaise raison.
             */
            $this->flushSession();
            $codeAvec = $this->actingAs($this->compte($porteur))->get($cas['adresse'])->getStatusCode();

            $this->flushSession();
            $codeSans = $this->actingAs($this->compte($etranger))->get($cas['adresse'])->getStatusCode();

            if ($codeAvec !== 200) {
                $ecarts[] = class_basename($cas['surface'])
                    ." refuse « {$porteur} », qui porte pourtant {$cas['permission']} (code {$codeAvec})";
            }

            if ($codeSans !== 403) {
                $ecarts[] = class_basename($cas['surface'])
                    ." rend {$codeSans} à « {$etranger} », qui ne porte pas {$cas['permission']} — "
                    .'un refus de permission de personnel répond 403 explicite';
            }

            User::whereIn('email', ["refus.{$porteur}@naja7i.ma", "refus.{$etranger}@naja7i.ma"])->delete();
        }

        $this->assertSame(
            [], $ecarts,
            "La permission déclarée n'est plus celle que la politique exige :\n  · "
            .implode("\n  · ", $ecarts)
        );
    }

    /**
     * LA PAGE NOMME LES TROIS CHOSES QUE LE REFUSÉ A BESOIN DE SAVOIR.
     *
     * Où il est, ce qui lui manque, et ce qu'il a — la troisième parce que le
     * cas le plus fréquent est de s'être connecté avec le mauvais compte.
     */
    public function test_la_page_403_nomme_la_surface_la_permission_et_le_compte(): void
    {
        $auteur = $this->compte('auteur');
        $adresse = OrderResource::getUrl('index', panel: 'admin');

        $reponse = $this->actingAs($auteur)->get($adresse);
        $reponse->assertForbidden();

        $corps = $reponse->getContent();
        $chemin = parse_url($adresse, PHP_URL_PATH);

        $reponse->assertSee($chemin, escape: false);
        $reponse->assertSee('orders.view', escape: false);

        /* Ce que le compte porte : `auteur` a bien `questions.view`, et le
         * lire lui dit en une seconde qu'il s'est trompé de compte. */
        $reponse->assertSee('questions.view', escape: false);

        /* Le nom lisible de la surface, pas seulement son adresse. */
        $reponse->assertSee(OrderResource::getNavigationLabel(), escape: false);

        /* Règle des portes : un refus se termine par un chemin cliquable. */
        $this->assertMatchesRegularExpression(
            '#<a\s[^>]*href=#i', $corps,
            'La page 403 ne propose aucune sortie : un refus sans porte est un cul-de-sac.'
        );
    }

    /**
     * ELLE NE DEVIENT PAS UN ANNUAIRE — le D-04, par la porte de secours.
     *
     * Nommer la permission manquante est utile ; nommer QUI la détient
     * servirait à un relecteur la liste des comptes du personnel, sans qu'il
     * ait la permission qui les gouverne. La page dit d'aller voir
     * l'administrateur, elle ne dit pas qui il est.
     *
     * ══════════════════════════════════════════════════════════════════════
     * PREMIÈRE ÉCRITURE DE CE TEST : IL NE DISCRIMINAIT RIEN.
     *
     * Il lisait `User::pluck('email')` AVANT de créer son propre compte. Sous
     * `RefreshDatabase`, le semis ne crée aucun compte de personnel — la liste
     * était donc VIDE, la boucle d'assertions ne s'exécutait pas, et le test
     * était vert quoi que la page affiche. Posée la mutation qui fait fuiter
     * une adresse sur la page 403, il est resté vert.
     *
     * C'est le genre 6 du bestiaire : la différence qu'il devait détecter
     * n'existait pas dans les données qu'on lui donnait. Un compte tiers est
     * désormais CRÉÉ ici, avec un rôle qui porte exactement la permission
     * manquante — le voisin qu'on serait le plus tenté de nommer.
     * ══════════════════════════════════════════════════════════════════════
     */
    public function test_la_page_403_ne_nomme_aucun_autre_compte(): void
    {
        /* Le compte qu'il serait tentant de désigner : il porte `orders.view`,
         * exactement ce qui manque au refusé. */
        $voisin = $this->compte('finance');

        $this->flushSession();
        $auteur = $this->compte('auteur');

        $reponse = $this->actingAs($auteur)
            ->get(OrderResource::getUrl('index', panel: 'admin'));

        $reponse->assertForbidden();

        $tiers = User::where('id', '!=', $auteur->id)->pluck('email');

        $this->assertGreaterThan(
            0, $tiers->count(),
            'Aucun compte tiers en base : ce test ne peut rien détecter — genre 6 du bestiaire.'
        );

        foreach ($tiers as $email) {
            $reponse->assertDontSee($email, escape: false);
        }

        /* Ni les permissions d'autrui : seules celles du compte refusé sont
         * affichées. `orders.validate` est portée par le voisin, pas par lui. */
        $this->assertContains('orders.validate', app(PermissionResolver::class)->forUser($voisin));
        $this->assertNotContains('orders.validate', app(PermissionResolver::class)->forUser($auteur));

        $reponse->assertDontSee('orders.validate', escape: false);
    }
}
