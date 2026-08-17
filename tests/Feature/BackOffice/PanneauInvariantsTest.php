<?php

namespace Tests\Feature\BackOffice;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Les invariants du panneau — ceux qu'aucune relecture n'attrape.
 *
 * Deux défauts trouvés par le pilote en une visite, et tous deux SILENCIEUX :
 * une page de liste sans bouton « créer » s'affiche parfaitement, et une clé de
 * traduction non résolue s'affiche comme du texte. Rien ne rougit, rien ne
 * lève ; c'est l'utilisateur qui découvre.
 */
class PanneauInvariantsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * TOUTE LISTE DONT LA RESSOURCE DÉCLARE UNE PAGE `create` DOIT L'OFFRIR.
     *
     * `/admin/plans` et `/admin/coupons` déclaraient la page et n'y menaient
     * par aucun chemin : la route existait, le bouton non. Le lot ABO livrait
     * donc un chemin de revenu dont le premier maillon était invisible.
     *
     * Ce test parcourt les classes RÉELLEMENT présentes plutôt qu'une liste
     * écrite à la main — une liste à tenir est une liste à oublier, et c'est
     * exactement l'oubli qu'on répare.
     */
    public function test_toute_liste_offre_la_creation_quand_la_ressource_la_declare(): void
    {
        $manquants = [];
        $verifies = 0;

        foreach ($this->pagesDeListe() as $classe) {
            $ressource = $classe::getResource();

            if (! $ressource::hasPage('create')) {
                continue;
            }

            $verifies++;

            $page = new $classe;
            $actions = (fn () => $this->getHeaderActions())->call($page);

            $aCreation = collect($actions)->contains(
                fn ($a) => $a instanceof CreateAction
            );

            if (! $aCreation) {
                $manquants[] = class_basename($classe);
            }
        }

        $this->assertGreaterThan(0, $verifies, 'Aucune page de liste examinée : le balayage est cassé.');

        $this->assertSame(
            [],
            $manquants,
            'Ces listes déclarent une page « create » sans y mener : '
            .implode(', ', $manquants).'. Une route sans chemin d’interface est une '
            .'fonction que personne ne peut atteindre.'
        );
    }

    /**
     * UNE LISTE SANS PAGE `create` N'INVENTE PAS DE BOUTON.
     *
     * Le pendant du test précédent, et il n'est pas décoratif : une classe de
     * base qui ajouterait le bouton partout satisferait le premier test et
     * casserait le produit. `OrderResource` ne déclare pas de page `create`
     * parce qu'une commande naît d'un candidat qui saisit un coupon — jamais
     * d'un membre du personnel.
     */
    public function test_une_liste_sans_page_de_creation_n_offre_pas_de_bouton(): void
    {
        $fautives = [];
        $verifies = 0;

        foreach ($this->pagesDeListe() as $classe) {
            if ($classe::getResource()::hasPage('create')) {
                continue;
            }

            $verifies++;

            $actions = (fn () => $this->getHeaderActions())->call(new $classe);

            if (collect($actions)->contains(fn ($a) => $a instanceof CreateAction)) {
                $fautives[] = class_basename($classe);
            }
        }

        $this->assertGreaterThan(
            0,
            $verifies,
            'Aucune liste sans page « create » : ce test ne discrimine plus rien.'
        );

        $this->assertSame([], $fautives, 'Ces listes offrent une création que leur ressource ne déclare pas : '
            .implode(', ', $fautives));
    }

    /**
     * AUCUNE CLÉ DE TRADUCTION NE FUIT DANS LE RENDU.
     *
     * `filament-tables::table.result_count` s'affichait tel quel sur le tableau
     * de bord : les traductions de Filament n'étaient pas publiées. Une clé non
     * résolue est SILENCIEUSE par nature — elle ne lève pas, elle s'imprime.
     * C'est ce qu'un contrôle attrape et qu'une relecture manque.
     *
     * Le marqueur `::` est celui de toutes les clés d'espace de noms. On
     * l'interdit dans le corps rendu, en écartant les endroits où deux
     * deux-points sont légitimes — une URL `https://`, un attribut Alpine.
     */
    public function test_aucune_cle_de_traduction_ne_fuit_dans_le_panneau(): void
    {
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $reponse = $this->actingAs($this->membreDuPersonnel())->get('/admin');
        $reponse->assertOk();

        $corps = $reponse->getContent();

        /*
         * ON NE CHERCHE QUE DANS LE TEXTE RENDU, jamais dans le balisage.
         *
         * Première écriture : la recherche portait sur le HTML entier et
         * ramenait six « fuites » qui n'en étaient pas — `livewire-error::backdrop`,
         * `filament-panels::layout.topbar.label`, `schema-component::tableFiltersForm.exam`.
         * Ce sont des identifiants internes de Livewire, dans des attributs que
         * personne ne lit. Un contrôle qui accuse du code juste finit désactivé,
         * et c'est le genre 2 du bestiaire.
         *
         * `strip_tags()` ne laisse que ce qu'un œil humain voit. C'est
         * exactement le périmètre du défaut : une clé non résolue s'IMPRIME.
         */
        $texte = strip_tags(preg_replace('#<(script|style|template)\b.*?</\1>#si', '', $corps));

        preg_match_all('#[a-z0-9-]+::[a-z0-9_.-]+#i', $texte, $trouves);

        $cles = array_values(array_unique($trouves[0] ?? []));

        $this->assertSame(
            [],
            $cles,
            'Des clés de traduction non résolues s’affichent dans le panneau : '
            .implode(', ', array_slice($cles, 0, 6)).'. Publiez les traductions '
            .'du paquet concerné — une clé non résolue ne lève pas, elle s’imprime.'
        );
    }

    /**
     * LES PAGES DU LOT ABO RÉPONDENT — établissement d'un fait, pas correctif.
     *
     * Le pilote a vu `/admin/coupons` et `/admin/orders` rendre 500. Le journal
     * du dépôt n'en portait AUCUNE trace : sa dernière erreur HTTP datait de
     * plusieurs heures avant. C'était la pile Docker de DET-73 qui répondait —
     * image ancienne, autre base — et il a testé celle-là toute une journée.
     *
     * Ce test établit ce que fait le CODE COURANT. S'il passe, les 500 étaient
     * un artefact de la pile, et c'est une preuve de plus que DET-73 fabrique
     * de faux défauts produit — ce qui vaut mieux qu'un correctif, puisqu'il
     * n'y a rien à corriger.
     *
     * Il reste ensuite comme garde : ces trois pages sont celles du chemin de
     * revenu, et personne ne les ouvrait avant le pilote.
     */
    public function test_les_pages_du_lot_abo_repondent(): void
    {
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $personnel = $this->membreDuPersonnel();
        $codes = [];

        /* PAS de `withoutExceptionHandling()` : on veut le CODE DE RÉPONSE que
         * verrait un navigateur, pas l'exception. Un 500 est un fait
         * d'interface avant d'être une trace. */
        foreach (['/admin/plans', '/admin/coupons', '/admin/orders'] as $chemin) {
            $codes[$chemin] = $this->actingAs($personnel)->get($chemin)->getStatusCode();
        }

        $fautives = array_filter($codes, fn ($c) => $c !== 200);

        $this->assertSame(
            [],
            $fautives,
            'Ces pages du back-office ne répondent pas : '
            .json_encode($fautives, JSON_UNESCAPED_SLASHES)
            .'. Si elles répondent chez vous et pas au navigateur, vérifiez QUELLE pile '
            .'tient le port 8000 — voir DET-73.'
        );
    }

    /** @return list<class-string<ListRecords>> */
    private function pagesDeListe(): array
    {
        $classes = [];

        foreach (Finder::create()->files()->in(app_path('Filament'))->name('List*.php') as $fichier) {
            $relatif = str_replace([app_path().'/', '/', '.php'], ['', '\\', ''], $fichier->getRealPath());
            $classe = 'App\\'.$relatif;

            if (! class_exists($classe) || ! is_subclass_of($classe, ListRecords::class)) {
                continue;
            }

            /* La classe de BASE s'appelle `ListeAvecCreation` et tombe donc dans
             * le motif `List*.php`. Elle n'a pas de `$resource` — c'est tout
             * l'objet d'une classe abstraite. On ne balaie que le concret. */
            if ((new \ReflectionClass($classe))->isAbstract()) {
                continue;
            }

            $classes[] = $classe;
        }

        return $classes;
    }

    private function membreDuPersonnel(): User
    {
        $user = User::firstOrCreate(
            ['email' => 'invariants@naja7i.ma'],
            ['password' => 'Invariants-2026!', 'locale' => 'fr', 'status' => 'active']
        );

        /* UN RÔLE DU PRODUIT, PAS UN RÔLE FABRIQUÉ. Inventer un rôle pour le
         * test reviendrait à éprouver le panneau sous une identité qui n'existe
         * nulle part ailleurs — et les permissions réelles cesseraient d'être
         * en jeu. `super_admin` est semé par le catalogue. */
        $roleId = Role::where('code', 'super_admin')->whereNull('tenant_id')->value('id');

        if ($roleId !== null && ! $user->memberships()->where('role_id', $roleId)->exists()) {
            $user->memberships()->create(['role_id' => $roleId]);
        }

        return $user->fresh();
    }
}
