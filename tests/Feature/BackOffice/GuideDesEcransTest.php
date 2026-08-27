<?php

namespace Tests\Feature\BackOffice;

use App\Filament\Pages\Couverture;
use App\Filament\Support\ExpliqueSonEcran;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * CHAQUE ÉCRAN DIT À QUOI IL SERT — et le crochet le rend vraiment.
 *
 * Le panneau expliquait ses ÉTATS et jamais ses MISSIONS : `Couverture` sait
 * distinguer « Aucun trou » de « Rien à mesurer », mais son sous-titre annonce
 * « couples (compétence, cause) attendus par des candidats » — une définition
 * écrite pour qui connaît déjà le modèle.
 *
 * CE QUI SE DÉFEND ICI N'EST PAS LE TEXTE, c'est le CÂBLAGE. Un guide déclaré
 * sur une page mais jamais rendu ne casse rien d'observable : la page s'affiche,
 * personne ne voit qu'il manque quelque chose. C'est exactement le genre de
 * défaut qui survit des mois.
 */
class GuideDesEcransTest extends TestCase
{
    use RefreshDatabase;

    private User $expert;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->expert = User::create([
            'email' => 'guide@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
            'status' => 'active',
        ]);
        $this->expert->markEmailAsVerified();
        $this->expert->memberships()->create([
            'role_id' => Role::where('code', 'expert_pedagogue')->whereNull('tenant_id')->value('id'),
        ]);
        $this->expert = $this->expert->fresh();
    }

    /**
     * LE TEST QUI DISCRIMINE — le guide est-il RENDU, pas seulement déclaré.
     *
     * Il porte sur une phrase du guide et non sur le mot « guide » : le second
     * resterait vert si le crochet rendait un panneau vide.
     */
    public function test_l_ecran_de_couverture_explique_sa_mission_a_l_ecran(): void
    {
        $reponse = $this->actingAs($this->expert)->get(Couverture::getUrl());

        $reponse->assertOk();
        $reponse->assertSee('À quoi sert cet écran', escape: false);
        $reponse->assertSee('Ce qu’il faut écrire en priorité', escape: false);
    }

    /**
     * LE CAS DU VIDE EST EXPLIQUÉ, et c'est le plus utile.
     *
     * Une liste vide a ici DEUX causes opposées — la banque couvre tout, ou
     * personne n'a rien demandé. Un expert qui les confond conclut « tout va
     * bien » d'un instrument qui ne mesure rien.
     */
    public function test_le_guide_leve_l_ambiguite_d_une_liste_vide(): void
    {
        $guide = Couverture::guideDeLEcran();

        $this->assertNotNull($guide->quandCEstVide);
        $this->assertStringContainsString('opposées', $guide->quandCEstVide);

        $this->actingAs($this->expert)
            ->get(Couverture::getUrl())
            ->assertSee('Quand la liste est vide', escape: false);
    }

    /** Un guide sans geste ni porte de sortie n'est qu'un paragraphe. */
    public function test_le_guide_dit_quoi_faire_et_ou_aller(): void
    {
        $guide = Couverture::guideDeLEcran();

        $this->assertNotEmpty($guide->gestes);
        $this->assertNotEmpty($guide->ensuite);

        foreach ($guide->ensuite as $porte) {
            $this->assertArrayHasKey('libelle', $porte);
            $this->assertNotEmpty($porte['url'], "La porte « {$porte['libelle']} » n'a pas d'adresse.");
        }
    }

    /**
     * LE TEST QUI DISCRIMINE LE BILINGUISME — le guide s'affiche EN ARABE.
     *
     * La parité des clés ne prouve rien sur le rendu : des clés peuvent être
     * traduites des deux côtés et l'écran servir le français à tout le monde.
     * Ce test-là monte un expert dont la préférence est `ar` et exige de lire
     * l'arabe à l'écran — et de NE PAS y lire le français.
     */
    public function test_un_expert_arabophone_lit_son_guide_en_arabe(): void
    {
        $arabophone = User::create([
            'email' => 'guide-ar@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide',
            'locale' => 'ar',
            'status' => 'active',
        ]);
        $arabophone->markEmailAsVerified();
        $arabophone->memberships()->create([
            'role_id' => Role::where('code', 'expert_pedagogue')->whereNull('tenant_id')->value('id'),
        ]);

        $reponse = $this->actingAs($arabophone->fresh())->get(Couverture::getUrl());

        $reponse->assertOk();
        $reponse->assertSee('ما يجب تحريره أولا', escape: false);
        $reponse->assertDontSee('Ce qu’il faut écrire en priorité', escape: false);
    }

    /**
     * LES DEUX LANGUES DISENT LA MÊME CHOSE — parité fr/ar des guides.
     *
     * Le back-office se lit en arabe comme en français : `SetLocale` suit la
     * préférence du compte. Un guide écrit dans une seule langue laisserait un
     * expert arabophone lire son poste de travail en français.
     *
     * C'est une part de DET-98, qui constate que personne ne contrôle la parité
     * des locales SERVEUR alors que le serveur en sert. Ce test la tient au
     * moins sur les guides — le fichier qui va le plus grossir.
     */
    public function test_chaque_guide_existe_dans_les_deux_langues(): void
    {
        $aplatir = function (array $tableau, string $prefixe = '') use (&$aplatir): array {
            $cles = [];
            foreach ($tableau as $cle => $valeur) {
                $chemin = $prefixe === '' ? (string) $cle : "{$prefixe}.{$cle}";
                $cles = is_array($valeur)
                    ? [...$cles, ...$aplatir($valeur, $chemin)]
                    : [...$cles, $chemin];
            }

            return $cles;
        };

        $fr = $aplatir(require lang_path('fr/guides.php'));
        $ar = $aplatir(require lang_path('ar/guides.php'));

        sort($fr);
        sort($ar);

        $this->assertSame($fr, $ar, 'Clés absentes d’un côté : '.implode(', ', array_merge(
            array_diff($fr, $ar),
            array_diff($ar, $fr),
        )));

        /* UNE CLÉ PRÉSENTE MAIS VIDE EST PIRE QU'UNE CLÉ ABSENTE : la première
         * passe la parité et rend une chaîne vide à l'écran. */
        foreach (['fr', 'ar'] as $langue) {
            foreach ($aplatir(require lang_path("{$langue}/guides.php")) as $cle) {
                $valeur = data_get(require lang_path("{$langue}/guides.php"), $cle);
                $this->assertNotSame('', trim((string) $valeur), "guides.{$cle} est vide en {$langue}.");
            }
        }
    }

    /**
     * L'INVENTAIRE — il rougit quand un écran est ajouté sans guide utile.
     *
     * Il ne réclame PAS que tout écran en ait un : certains n'ont rien à
     * expliquer. Il réclame que celui qui déclare en avoir un le remplisse.
     */
    public function test_tout_ecran_qui_declare_un_guide_le_remplit(): void
    {
        $vides = [];

        foreach (Finder::create()->files()->in(app_path('Filament'))->name('*.php') as $fichier) {
            $classe = 'App\\Filament\\'.str_replace(
                ['/', '.php'],
                ['\\', ''],
                $fichier->getRelativePathname(),
            );

            if (! class_exists($classe) || ! is_subclass_of($classe, ExpliqueSonEcran::class)) {
                continue;
            }

            $guide = $classe::guideDeLEcran();

            if (trim($guide->role) === '' || $guide->gestes === []) {
                $vides[] = $classe;
            }
        }

        $this->assertEmpty($vides, 'Écran(s) déclarant un guide vide : '.implode(', ', $vides));
    }
}
