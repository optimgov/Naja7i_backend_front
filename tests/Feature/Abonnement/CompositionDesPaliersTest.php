<?php

namespace Tests\Feature\Abonnement;

use App\Contracts\AccessGrant;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\DroitTransitoireService;
use App\Support\CapabilityRegistry;
use App\Tenancy\TenantContext;
use Database\Seeders\PlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La composition arbitrée des paliers — décisions D-CAT-1 à D-CAT-4.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE FICHIER NE DÉCIDE RIEN, IL PROUVE UNE DÉCISION PRISE
 *
 * L'arbitrage du 22 août 2026 donne une table, colonne par colonne. Ce test la
 * rejoue telle quelle sur le catalogue semé, parce que la composition d'une
 * offre est une DONNÉE : rien dans le code ne la contraint, et c'est
 * exactement ce que le lot 3A.6 a voulu — l'admin commerciale la recomposera
 * demain sans développeur. Ce que ce test garde, c'est le point de départ
 * décidé, et le fait que semer le redonne.
 *
 * DEUX ABSENCES SONT AUSSI TESTÉES QUE LES PRÉSENCES.
 *
 *   · `annales.practice` est fermée PAR CHOIX (D-CAT-2, Q-21 : l'audit du
 *     marqueur d'annales précède l'ouverture). Un choix qui n'est pas testé se
 *     transforme en oubli le jour où quelqu'un le retrouve.
 *   · `certification.take` n'est pas commercialisable : la fonction n'existe
 *     pas (lot 11), et vendable ≠ existant (P6).
 */
class CompositionDesPaliersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * La table de l'arbitrage, transcrite. L'ordre des capacités n'y est pas
     * significatif : c'est un ensemble, et le test compare des ensembles triés.
     *
     * @var array<string, list<string>>
     */
    private const TABLE = [
        'decouverte-7j' => [
            AccessGrant::QUESTIONS_ANSWER,
            AccessGrant::CAUSE_REVEAL,
            AccessGrant::SERIES_TARGETED,
            AccessGrant::SIMULATOR_FULL,
        ],
        'preparation-30j' => [
            AccessGrant::QUESTIONS_ANSWER,
            AccessGrant::CAUSE_REVEAL,
            AccessGrant::SERIES_TARGETED,
            AccessGrant::SIMULATOR_FULL,
        ],
        'session-180j' => [
            AccessGrant::QUESTIONS_ANSWER,
            AccessGrant::CAUSE_REVEAL,
            AccessGrant::SERIES_TARGETED,
            AccessGrant::SIMULATOR_FULL,
            AccessGrant::MASTERY_DETAIL,
            AccessGrant::REMEDIATION_PLAN,
            AccessGrant::MEMORY_SESSIONS,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());
    }

    private function offre(string $code): Plan
    {
        return Plan::where('code', $code)->sole();
    }

    public function test_les_trois_offres_payantes_composent_la_table_arbitree(): void
    {
        foreach (self::TABLE as $code => $attendues) {
            $composee = $this->offre($code)->capabilities;

            sort($attendues);
            sort($composee);

            $this->assertSame($attendues, $composee, "La composition de « {$code} » a dérivé de la table D-CAT.");
        }
    }

    public function test_les_trois_offres_payantes_ouvrent_le_droit_de_repondre_sans_enveloppe(): void
    {
        foreach (array_keys(self::TABLE) as $code) {
            $offre = $this->offre($code);

            $this->assertContains(
                AccessGrant::QUESTIONS_ANSWER,
                $offre->capabilities,
                "D-CAT-1 : « {$code} » doit rendre le droit de répondre, sans quoi payer fait perdre l’essai.",
            );

            /* « L'illimité est une ABSENCE de profil, jamais un nombre »
             * (ADR-0027). Un profil ici poserait une enveloppe sur une offre
             * qui vend la consommation libre. */
            $this->assertNull(
                $offre->quota_profile_id,
                "« {$code} » ne porte aucun profil de quota : l’absence de profil EST l’illimité.",
            );
        }

        /* Et l'essai, lui, la porte : sans cette moitié, l'assertion ci-dessus
         * serait vraie d'un produit qui ne sait pas borner du tout. */
        $essai = $this->offre('decouverte-gratuite');
        $this->assertNotNull($essai->quota_profile_id);
        $this->assertSame([AccessGrant::QUESTIONS_ANSWER], $essai->capabilities);
    }

    public function test_la_profondeur_appartient_a_la_session_complete_et_a_elle_seule(): void
    {
        foreach ([AccessGrant::MASTERY_DETAIL, AccessGrant::REMEDIATION_PLAN, AccessGrant::MEMORY_SESSIONS] as $c) {
            $this->assertContains($c, $this->offre('session-180j')->capabilities);
            $this->assertNotContains($c, $this->offre('preparation-30j')->capabilities);
            $this->assertNotContains($c, $this->offre('decouverte-7j')->capabilities);
            $this->assertNotContains($c, $this->offre('decouverte-gratuite')->capabilities);
        }
    }

    public function test_les_annales_restent_fermees_partout_par_choix(): void
    {
        foreach (Plan::all() as $offre) {
            $this->assertNotContains(
                AccessGrant::ANNALES_PRACTICE,
                $offre->capabilities ?? [],
                "D-CAT-2 : « {$offre->code} » ouvre les annales, que Q-21 garde fermées jusqu’à "
                .'l’audit du marqueur d’annales. C’est un choix, pas un oubli.',
            );
        }
    }

    public function test_aucune_offre_ne_compose_la_certification(): void
    {
        foreach (Plan::all() as $offre) {
            $this->assertNotContains(AccessGrant::CERTIFICATION, $offre->capabilities ?? []);

            foreach ($offre->capabilities ?? [] as $capacite) {
                $this->assertContains(
                    $capacite,
                    CapabilityRegistry::COMMERCIALIZABLE,
                    "« {$offre->code} » compose {$capacite}, qui n’est pas commercialisable.",
                );
            }
        }
    }

    public function test_les_paliers_ne_se_nomment_plus_par_un_prix(): void
    {
        $attendus = [
            'decouverte-7j' => 'Entrée',
            'preparation-30j' => 'Préparation',
            'session-180j' => 'Session complète',
        ];

        foreach ($attendus as $code => $nom) {
            $offre = $this->offre($code);

            $this->assertSame($nom, $offre->name_fr, "D-CAT-4 : « {$code} » porte le nom du palier.");
            $this->assertNotEmpty($offre->name_ar, 'La clé arabe est posée, même si sa relecture attend O-6.');

            /* Aucun palier ne se nomme par un montant — ni « 200 », ni « 600 »,
             * ni « 49 MAD ». Le prix a sa colonne ; le confondre avec le nom
             * rend le catalogue faux au premier changement de tarif. */
            $this->assertDoesNotMatchRegularExpression('/\d/', $offre->name_fr);
        }
    }

    // ═══ Ce que la composition ferme du côté du pas 0 ══════════════════════

    public function test_plus_aucune_offre_payante_ne_declenche_l_avertissement_du_pas_0(): void
    {
        foreach (array_keys(self::TABLE) as $code) {
            $offre = $this->offre($code);

            $this->assertSame(
                [],
                app(CapabilityRegistry::class)->avertissementsDeComposition(
                    $offre->capabilities,
                    payante: $offre->price_cents > 0,
                ),
                "« {$code} » déclenche encore l’avertissement de composition : elle ferait payer pour perdre.",
            );
        }
    }

    public function test_la_session_complete_est_l_offre_la_plus_complete_du_catalogue(): void
    {
        /* Le droit transitoire ne devine plus son palier — c'est le pas 0 —
         * mais celui qu'on nommera doit tout de même être le plus complet, sans
         * quoi « équivalent au palier le plus complet » resterait un vœu. */
        $reference = app(DroitTransitoireService::class)->offreDeReference('session-180j');
        $capacites = app(DroitTransitoireService::class)->capacitesDe($reference);

        foreach (Plan::where('auto_granted', false)->get() as $offre) {
            $this->assertLessThanOrEqual(count($capacites), count($offre->capabilities ?? []));
        }

        $this->assertNotContains(AccessGrant::CERTIFICATION, $capacites);
    }

    // ═══ Semer redonne le catalogue décidé, et versionne ═══════════════════

    public function test_semer_a_nouveau_redonne_la_composition_sans_creer_de_version(): void
    {
        $avant = Plan::where('code', 'session-180j')->sole()->versions()->count();

        $this->seed(PlansSeeder::class);

        $offre = Plan::where('code', 'session-180j')->sole();

        $composee = $offre->capabilities;
        $attendues = self::TABLE['session-180j'];
        sort($composee);
        sort($attendues);

        $this->assertSame($attendues, $composee);
        $this->assertSame(
            $avant,
            $offre->versions()->count(),
            'Semer une composition identique ne compose pas une version de plus : '
            .'un journal des versions qui compte les semis ne se relit plus.',
        );
    }
}
