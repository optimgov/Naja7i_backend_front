<?php

namespace Tests\Feature\BackOffice;

use App\Filament\Pages\Couverture;
use App\Filament\Pages\FileDeQualification;
use App\Filament\Resources\CompetencyNodes\Pages\ListCompetencyNodes;
use App\Filament\Resources\DifficultyLevels\Pages\ListDifficultyLevels;
use App\Filament\Resources\Questions\Pages\ListQuestions;
use App\Filament\Resources\Sources\Pages\ListSources;
use App\Filament\Resources\TaxonomyProfiles\Pages\ListTaxonomyProfiles;
use App\Filament\Support\ExpliqueSonEcran;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * CHAQUE ÉCRAN DIT À QUOI IL SERT — et le dit dans la langue du compte.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE CES TESTS ONT LAISSÉ PASSER, ET QU'ILS DÉFENDENT MAINTENANT
 *
 * La première version vérifiait que le CORPS du guide s'affichait en arabe et
 * que le corps français n'y était pas. Elle ne regardait jamais le CADRE — le
 * déclencheur et les trois titres de rubrique — resté écrit en dur en français
 * dans le composant. Un expert arabophone lisait donc un guide arabe entouré de
 * libellés français, et aucun test ne rougissait.
 *
 * Relevé par l'audit du 28 août 2026. La leçon n'est pas « il manquait un
 * test » : c'est qu'une assertion sur un fragment ne prouve rien du reste de
 * l'écran. Le test arabe refuse désormais TOUS les libellés français du cadre.
 */
class GuideDesEcransTest extends TestCase
{
    use RefreshDatabase;

    /**
     * L'INVENTAIRE EXPLICITE des écrans qui doivent porter un guide.
     *
     * Sans lui, l'inventaire acceptait en silence tout écran dépourvu de
     * guide : ajouter une page sans l'expliquer ne faisait rougir personne.
     */
    private const ECRANS_GUIDES = [
        Couverture::class,
        FileDeQualification::class,
        ListQuestions::class,
        ListSources::class,
        ListCompetencyNodes::class,
        ListTaxonomyProfiles::class,
        ListDifficultyLevels::class,
    ];

    /** Les libellés du cadre en français. Aucun ne doit paraître en locale arabe. */
    private const CADRE_FRANCAIS = [
        'Ce que vous pouvez faire ici',
        'Si la liste est vide',
        'Étape suivante',
    ];

    private User $expert;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->expert = $this->membre('guide@naja7i.ma', 'fr');
    }

    private function membre(string $email, string $locale): User
    {
        $user = User::create([
            'email' => $email,
            'password' => 'une-phrase-de-passe-solide',
            'locale' => $locale,
            'status' => 'active',
        ]);
        $user->markEmailAsVerified();
        $user->memberships()->create([
            'role_id' => Role::where('code', 'expert_pedagogue')->whereNull('tenant_id')->value('id'),
        ]);

        return $user->fresh();
    }

    // ── Le câblage ──────────────────────────────────────────────────────

    /**
     * LE TEST QUI DISCRIMINE — le guide est-il RENDU, pas seulement déclaré.
     *
     * Un guide déclaré mais jamais rendu ne casse rien d'observable : la page
     * s'affiche, personne ne voit qu'il manque quelque chose.
     */
    public function test_chaque_ecran_declare_rend_bien_son_guide(): void
    {
        foreach (self::ECRANS_GUIDES as $ecran) {
            $guide = $ecran::guideDeLEcran();

            $this->actingAs($this->expert)
                ->get($ecran::getUrl())
                ->assertOk()
                ->assertSee($guide->titre, escape: false)
                ->assertSee($guide->role, escape: false);
        }
    }

    /**
     * LE DÉCLENCHEUR PORTE LE NOM DE L'ÉCRAN, pas « Aide ».
     *
     * Le commentaire de la première version l'affirmait déjà, et le code
     * rendait toujours « À quoi sert cet écran ? » : le commentaire mentait.
     */
    public function test_le_declencheur_est_propre_a_chaque_ecran(): void
    {
        $titres = array_map(fn (string $e) => $e::guideDeLEcran()->titre, self::ECRANS_GUIDES);

        $this->assertCount(count($titres), array_unique($titres), 'Titres partagés : '.implode(' · ', $titres));

        foreach ($titres as $titre) {
            $this->assertStringNotContainsStringIgnoringCase('cet écran', $titre, "« {$titre} » reste générique.");
        }
    }

    // ── Le bilinguisme ──────────────────────────────────────────────────

    /**
     * LE TEST QUI A MANQUÉ — le CADRE aussi suit la langue.
     *
     * Sans cette moitié, le défaut relevé le 28 août serait resté invisible.
     */
    public function test_un_expert_arabophone_ne_lit_aucun_francais_dans_le_cadre(): void
    {
        $arabophone = $this->membre('guide-ar@naja7i.ma', 'ar');

        foreach (self::ECRANS_GUIDES as $ecran) {
            $reponse = $this->actingAs($arabophone)->get($ecran::getUrl())->assertOk();

            foreach (self::CADRE_FRANCAIS as $libelle) {
                $reponse->assertDontSee($libelle, escape: false);
            }
        }
    }

    /** Et l'arabe est bien SERVI, pas seulement le français absent. */
    public function test_un_expert_arabophone_lit_son_guide_en_arabe(): void
    {
        $arabophone = $this->membre('guide-ar2@naja7i.ma', 'ar');

        $reponse = $this->actingAs($arabophone)->get(Couverture::getUrl())->assertOk();

        $fr = require lang_path('fr/guides.php');
        $ar = require lang_path('ar/guides.php');

        $reponse->assertSee($ar['couverture']['titre'], escape: false);
        $reponse->assertSee($ar['commun']['gestes'], escape: false);
        $reponse->assertDontSee($fr['couverture']['role'], escape: false);
    }

    /**
     * PARITÉ fr/ar, ET REFUS D'UNE CLÉ PRÉSENTE MAIS VIDE.
     *
     * Une clé vide passe la parité et rend une chaîne blanche à l'écran : elle
     * est pire qu'une clé absente. C'est une part de DET-98.
     */
    public function test_chaque_guide_existe_et_est_rempli_dans_les_deux_langues(): void
    {
        $aplatir = function (array $t, string $prefixe = '') use (&$aplatir): array {
            $cles = [];
            foreach ($t as $cle => $valeur) {
                $chemin = $prefixe === '' ? (string) $cle : "{$prefixe}.{$cle}";
                $cles = is_array($valeur) ? [...$cles, ...$aplatir($valeur, $chemin)] : [...$cles, $chemin];
            }

            return $cles;
        };

        $fr = require lang_path('fr/guides.php');
        $ar = require lang_path('ar/guides.php');

        $clesFr = $aplatir($fr);
        $clesAr = $aplatir($ar);
        sort($clesFr);
        sort($clesAr);

        $this->assertSame($clesFr, $clesAr, 'Clés absentes d’un côté : '.implode(', ', array_merge(
            array_diff($clesFr, $clesAr),
            array_diff($clesAr, $clesFr),
        )));

        foreach (['fr' => $fr, 'ar' => $ar] as $langue => $tableau) {
            foreach ($aplatir($tableau) as $cle) {
                $this->assertNotSame('', trim((string) data_get($tableau, $cle)), "guides.{$cle} est vide en {$langue}.");
            }
        }
    }

    // ── Le contenu ──────────────────────────────────────────────────────

    /**
     * LES CAS D'UNE LISTE VIDE SONT SÉPARÉS, pas fondus en un paragraphe.
     *
     * Une liste vide a souvent deux causes opposées. Les fondre oblige le
     * lecteur à les démêler, ce que le guide devait lui épargner.
     */
    public function test_les_cas_d_une_liste_vide_sont_separes(): void
    {
        foreach (self::ECRANS_GUIDES as $ecran) {
            $guide = $ecran::guideDeLEcran();

            $this->assertNotEmpty($guide->quandCEstVide, class_basename($ecran).' n’explique pas sa liste vide.');
        }

        $this->assertGreaterThanOrEqual(2, count(Couverture::guideDeLEcran()->quandCEstVide));
    }

    /**
     * CHAQUE PORTE DE SORTIE MÈNE QUELQUE PART, et à une page que celui qui
     * voit le guide peut ouvrir. Un lien vers un écran interdit au rôle serait
     * un cul-de-sac poli.
     */
    public function test_chaque_porte_de_sortie_est_ouvrable_par_qui_voit_le_guide(): void
    {
        foreach (self::ECRANS_GUIDES as $ecran) {
            foreach ($ecran::guideDeLEcran()->ensuite as $porte) {
                $this->assertNotEmpty($porte['url'], 'Porte sans adresse sur '.class_basename($ecran));

                $this->actingAs($this->expert)->get($porte['url'])->assertSuccessful();
            }
        }
    }

    /**
     * L'INVENTAIRE ROUGIT DANS LES DEUX SENS : un écran inventorié qui perdrait
     * son guide, et un écran qui en déclare un mais le laisse incomplet.
     */
    public function test_l_inventaire_des_ecrans_guides_est_tenu(): void
    {
        foreach (self::ECRANS_GUIDES as $ecran) {
            $this->assertTrue(
                is_subclass_of($ecran, ExpliqueSonEcran::class),
                class_basename($ecran).' est inventorié comme guidé mais n’implémente plus le contrat.'
            );
        }

        $incomplets = [];

        foreach (Finder::create()->files()->in(app_path('Filament'))->name('*.php') as $fichier) {
            $classe = 'App\\Filament\\'.str_replace(['/', '.php'], ['\\', ''], $fichier->getRelativePathname());

            if (! class_exists($classe) || ! is_subclass_of($classe, ExpliqueSonEcran::class)) {
                continue;
            }

            $guide = $classe::guideDeLEcran();

            if (trim($guide->titre) === '' || trim($guide->role) === '' || $guide->gestes === []) {
                $incomplets[] = class_basename($classe);
            }
        }

        $this->assertEmpty($incomplets, 'Écran(s) déclarant un guide incomplet : '.implode(', ', $incomplets));
    }
}
