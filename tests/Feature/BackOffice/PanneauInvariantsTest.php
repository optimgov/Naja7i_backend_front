<?php

namespace Tests\Feature\BackOffice;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament as FilamentFacade;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
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
     * ══════════════════════════════════════════════════════════════════════
     * D-05 — LA GARANTIE ÉTAIT PLUS ÉTROITE QUE LA FAMILLE DU DÉFAUT
     *
     * Ce test ne visitait que `/admin`. Il avait été écrit pour une fuite vue
     * là — `filament-tables::table.result_count` — et il l'a bien fermée. La
     * fuite est revenue ailleurs : `filament-tables::table.columns.icon.boolean.true`,
     * trois fois, sur `/admin/plans`, la seule page qui porte une `IconColumn`.
     *
     * LA VRAIE CAUSE, MESURÉE ET NON DÉDUITE. Ce n'est pas que « les
     * traductions ne sont pas publiées » : la traduction FRANÇAISE DU PAQUET
     * est incomplète — 13 clés que l'anglais possède manquent au français de
     * `filament/tables`. Et `config('app.fallback_locale')` vaut `fr` dans ce
     * dépôt, délibérément : il n'y a donc PLUS RIEN derrière le français. Une
     * clé absente ne retombe pas sur l'anglais, elle s'imprime.
     *
     * Le correctif précédent avait ajouté la seule clé qui fuyait. Il en
     * restait douze, sur `filament-tables` seul, et 143 en tout sur les dix
     * paquets. Elles sont désormais traduites.
     *
     * DEUX GARANTIES, ET ELLES NE SE RECOUVRENT PAS :
     *
     *   - celle-ci parcourt TOUTES les pages du panneau, pas celle où le
     *     défaut a été vu la dernière fois ;
     *   - `test_toute_cle_de_filament_se_resout_en_francais_et_en_arabe`
     *     ci-dessous ne dépend d'aucun rendu : elle ferme la famille entière,
     *     y compris les clés d'un composant que nous n'employons pas encore.
     *
     * La première seule serait de nouveau trop étroite — elle ne voit que ce
     * que les données du test font rendre. La seconde seule ne verrait pas
     * qu'une clé bien traduite peut s'afficher au mauvais endroit.
     * ══════════════════════════════════════════════════════════════════════
     */
    public function test_aucune_cle_de_traduction_ne_fuit_sur_aucune_page_du_panneau(): void
    {
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $personnel = $this->membreDuPersonnel();
        $fuites = [];
        $visitees = 0;

        foreach ($this->adressesDuPanneau() as $adresse) {
            $reponse = $this->actingAs($personnel)->get($adresse);

            /* Une page qui ne répond pas 200 est le sujet d'un AUTRE test
             * (`test_les_pages_du_lot_abo_repondent`). Ici on ne lit que ce
             * qui a été rendu — chercher une fuite dans une page d'erreur
             * mesurerait la page d'erreur. */
            if ($reponse->getStatusCode() !== 200) {
                continue;
            }

            $visitees++;

            foreach ($this->clesQuiFuient($reponse->getContent()) as $cle) {
                $fuites[$cle][] = $adresse;
            }
        }

        /* Sans borne basse, un balayage cassé rendrait zéro page et le test
         * serait vert — le genre 3 du bestiaire, un vert qui absout du code
         * jamais exercé. Six entrées de menu au minimum. */
        $this->assertGreaterThanOrEqual(
            6, $visitees,
            "Seules {$visitees} page(s) du panneau ont été visitées : le balayage est cassé, "
            .'et son vert ne prouve rien.'
        );

        $this->assertSame(
            [], $fuites,
            'Des clés de traduction non résolues s’affichent dans le panneau : '
            .json_encode(array_map(
                fn (array $ou) => array_slice($ou, 0, 3),
                array_slice($fuites, 0, 6, true),
            ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            .'. Une clé non résolue ne lève pas, elle s’imprime.'
        );
    }

    /**
     * TOUTE CLÉ DE FILAMENT SE RÉSOUT, DANS LES DEUX LANGUES DU PRODUIT.
     *
     * La garantie que le rendu ne peut pas donner : elle ne dépend ni des
     * données du test, ni des composants employés aujourd'hui. Le jour où une
     * ressource gagne un `Repeater`, un `ColorPicker` ou un `Wizard`, ses
     * libellés sont déjà traduits — et si un paquet est mis à jour avec des
     * clés neuves que son français n'a pas, CE test rougit, pas un candidat.
     *
     * L'ANGLAIS FAIT RÉFÉRENCE parce que c'est la langue source des paquets :
     * il est toujours complet, les traductions communautaires ne le sont pas.
     */
    public function test_toute_cle_de_filament_se_resout_en_francais_et_en_arabe(): void
    {
        $langueInitiale = app()->getLocale();
        $orphelines = [];
        $verifiees = 0;

        foreach (self::PAQUETS_FILAMENT as $paquet => $espace) {
            $racine = base_path("vendor/filament/{$paquet}/resources/lang/en");

            if (! is_dir($racine)) {
                continue;
            }

            foreach (glob("{$racine}/*.php") as $fichier) {
                $groupe = basename($fichier, '.php');
                $cles = array_keys(Arr::dot(require $fichier));

                foreach (['fr', 'ar'] as $langue) {
                    app()->setLocale($langue);

                    foreach ($cles as $cle) {
                        $verifiees++;
                        $complete = "{$espace}::{$groupe}.{$cle}";

                        /* `__()` rend la CLÉ elle-même quand elle ne résout
                         * pas. C'est exactement ce qui s'imprime à l'écran. */
                        if (__($complete) === $complete) {
                            $orphelines[] = "[{$langue}] {$complete}";
                        }
                    }
                }
            }
        }

        app()->setLocale($langueInitiale);

        $this->assertGreaterThan(
            500, $verifiees,
            "Seules {$verifiees} clés examinées : le balayage des paquets Filament est cassé."
        );

        $this->assertSame(
            [], $orphelines,
            count($orphelines).' clé(s) de Filament ne se résolvent dans aucune des deux langues et '
            .'s’imprimeront telles quelles : '.implode(', ', array_slice($orphelines, 0, 8))
            .'. `fallback_locale` vaut `fr` : il n’y a rien derrière le français.'
        );
    }

    /**
     * Toutes les adresses du panneau qu'un GET peut atteindre.
     *
     * Construites depuis Filament lui-même — pages, et pour chaque ressource
     * ses pages déclarées. Une liste écrite à la main serait une liste à
     * tenir, donc une liste à oublier : c'est l'oubli qu'on répare.
     *
     * Les pages `edit` et `view` demandent un enregistrement. On en prend un
     * VRAI quand il en existe un ; sinon on n'invente pas de ligne, et
     * l'adresse est simplement absente du balayage.
     *
     * @return list<string>
     */
    private function adressesDuPanneau(): array
    {
        $panneau = FilamentFacade::getPanel('admin');
        $adresses = [];

        foreach ($panneau->getPages() as $page) {
            $adresses[] = $page::getUrl(panel: 'admin');
        }

        foreach ($panneau->getResources() as $ressource) {
            $modele = $ressource::getModel();
            $premier = $modele::query()->first();

            foreach (array_keys($ressource::getPages()) as $nom) {
                if (in_array($nom, ['edit', 'view'], true)) {
                    if ($premier === null) {
                        continue;
                    }

                    $adresses[] = $ressource::getUrl($nom, ['record' => $premier], panel: 'admin');

                    continue;
                }

                /* `index` et `create` ne prennent aucun paramètre. Toute autre
                 * page à paramètre serait sautée en silence — on ne veut pas
                 * de silence, donc on le dit. */
                $adresses[] = $ressource::getUrl($nom, panel: 'admin');
            }
        }

        return array_values(array_unique($adresses));
    }

    /**
     * Les clés d'espace de noms qui s'IMPRIMENT dans une page.
     *
     * ON NE CHERCHE QUE DANS LE TEXTE RENDU, jamais dans le balisage.
     * Première écriture du contrôle : la recherche portait sur le HTML entier
     * et ramenait des « fuites » qui n'en étaient pas — `livewire-error::backdrop`,
     * `schema-component::tableFiltersForm.exam`. Ce sont des identifiants
     * internes de Livewire, dans des attributs. Un contrôle qui accuse du code
     * juste finit désactivé, et c'est le genre 2 du bestiaire.
     *
     * `strip_tags()` ne laisse que ce qu'un œil humain voit — exactement le
     * périmètre du défaut.
     *
     * @return list<string>
     */
    private function clesQuiFuient(string $html): array
    {
        $texte = strip_tags(preg_replace('#<(script|style|template)\b.*?</\1>#si', '', $html));

        preg_match_all('#[a-z0-9-]+::[a-z0-9_.-]+#i', $texte, $trouves);

        return array_values(array_unique($trouves[0] ?? []));
    }

    /**
     * Les paquets de Filament et leur espace de noms de traduction.
     *
     * Le nom du dossier `vendor/filament/*` et l'espace `filament-*` ne
     * coïncident pas partout — `filament/filament` sert `filament-panels`.
     */
    private const PAQUETS_FILAMENT = [
        'support' => 'filament-support',
        'actions' => 'filament-actions',
        'query-builder' => 'filament-query-builder',
        'notifications' => 'filament-notifications',
        'widgets' => 'filament-widgets',
        'tables' => 'filament-tables',
        'forms' => 'filament-forms',
        'infolists' => 'filament-infolists',
        'schemas' => 'filament-schemas',
        'filament' => 'filament-panels',
    ];

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
