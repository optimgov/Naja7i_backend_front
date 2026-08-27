<?php

namespace Tests\Feature\Catalogue;

use App\Models\BlueprintModel;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Source;
use App\Models\Specialty;
use App\Models\Track;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PAS-4.1 — Le référentiel officiel est-il fidèlement représenté ?
 *
 * Ces tests protègent contre le mode de défaillance le plus coûteux de ce
 * projet : présenter comme officielle une donnée qui ne l'est pas. Un
 * candidat qui organise ses révisions sur un coefficient inventé est trompé
 * sur l'essentiel, et la plateforme perd le seul avantage qu'elle revendique.
 */
class ReferentielCrmefTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * LES ÉPREUVES DU CRMEF, ET ELLES SEULES.
     *
     * Ce fichier parcourait `Exam::all()`, ce qui revenait à dire « toute
     * épreuve du catalogue est une épreuve du CRMEF ». C'était vrai tant qu'il
     * n'y avait qu'un univers ; ADR-0038 en a ouvert un second, et les onze
     * matières du lycée n'ont ni poids officiels ni coefficient — par
     * construction, pas par oubli.
     *
     * Le cadrage devient donc EXPLICITE. Il n'affaiblit rien : les mêmes
     * assertions portent sur les mêmes épreuves qu'avant.
     */
    private function parcoursDuCrmef(): \Illuminate\Support\Collection
    {
        $filiere = \DB::table('filieres')->where('slug', 'sciences-education')->value('id');
        $familles = \DB::table('exam_families')->where('filiere_id', $filiere)->pluck('id');

        return \DB::table('tracks')->whereIn('exam_family_id', $familles)->pluck('id');
    }

    private function epreuvesDuCrmef(): \Illuminate\Support\Collection
    {
        return Exam::whereIn('track_id', $this->parcoursDuCrmef())->get();
    }

    // --- Les poids officiels ----------------------------------------------

    public function test_les_racines_de_chaque_matrice_totalisent_cent_pour_cent(): void
    {
        foreach ($this->epreuvesDuCrmef() as $exam) {
            $total = CompetencyNode::where('exam_id', $exam->id)
                ->whereNull('parent_id')
                ->sum('weight_percent');

            $this->assertEqualsWithDelta(
                100.0, (float) $total, 0.01,
                "Les domaines de l'épreuve {$exam->code} doivent totaliser 100 %, obtenu {$total}."
            );
        }
    }

    public function test_les_enfants_totalisent_le_poids_de_leur_parent(): void
    {
        foreach (CompetencyNode::whereNull('parent_id')->get() as $parent) {
            $enfants = CompetencyNode::where('parent_id', $parent->id)->get();

            if ($enfants->isEmpty()) {
                continue;
            }

            $this->assertEqualsWithDelta(
                (float) $parent->weight_percent,
                (float) $enfants->sum('weight_percent'),
                0.01,
                "Les sous-domaines de {$parent->code} doivent totaliser {$parent->weight_percent} %."
            );
        }
    }

    public function test_les_matrices_ont_le_nombre_de_sous_domaines_attendu(): void
    {
        $attendu = [
            'CRMEF-SE-2025' => 6,
            'CRMEF-FR-DID-2025' => 9,
            'CRMEF-FR-SPEC-2025' => 10,
        ];

        foreach ($attendu as $code => $nombre) {
            $exam = Exam::where('code', $code)->firstOrFail();

            $this->assertSame(
                $nombre,
                CompetencyNode::where('exam_id', $exam->id)->where('depth', 1)->count(),
                "L'épreuve {$code} doit compter {$nombre} sous-domaines officiels."
            );
        }
    }

    // --- Les trois épreuves restent séparées -------------------------------

    public function test_les_trois_epreuves_ont_leurs_coefficients_officiels(): void
    {
        $attendu = [
            'CRMEF-SE-2025' => [8, 120, ['ar', 'fr']],
            'CRMEF-FR-DID-2025' => [12, 120, ['fr']],
            'CRMEF-FR-SPEC-2025' => [20, 240, ['fr']],
        ];

        foreach ($attendu as $code => [$coef, $duree, $langues]) {
            $exam = Exam::where('code', $code)->firstOrFail();

            $this->assertSame($coef, $exam->coefficient);
            $this->assertSame($duree, $exam->duration_minutes);
            $this->assertSame($langues, $exam->languages_allowed);
            $this->assertSame('official', $exam->provenance);
        }
    }

    public function test_sciences_de_l_education_est_commune_aux_specialites(): void
    {
        $se = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();

        $this->assertNull($se->specialty_id, 'Un seul descriptif couvre les treize spécialités.');
        $this->assertTrue($se->isShared());
    }

    public function test_une_matrice_ne_deborde_jamais_sur_une_autre_epreuve(): void
    {
        $se = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $did = Exam::where('code', 'CRMEF-FR-DID-2025')->firstOrFail();

        $codesSe = CompetencyNode::where('exam_id', $se->id)->pluck('code');
        $codesDid = CompetencyNode::where('exam_id', $did->id)->pluck('code');

        $this->assertEmpty(
            $codesSe->intersect($codesDid),
            'Les matrices des trois épreuves doivent rester étanches.'
        );
    }

    // --- Rien d'inventé ----------------------------------------------------

    public function test_aucun_nombre_officiel_de_questions_n_est_renseigne(): void
    {
        foreach (BlueprintModel::all() as $blueprint) {
            $this->assertNull(
                $blueprint->official_question_count,
                "Le descriptif {$blueprint->version} n'établit pas de nombre de questions : il doit rester nul."
            );
            $this->assertFalse($blueprint->hasOfficialQuestionCount());
        }
    }

    public function test_les_specialites_non_documentees_n_ont_ni_epreuve_ni_coefficient(): void
    {
        /* Cadré au CRMEF pour la même raison que le poids des matrices : au
         * lycée, TOUTES les matières sont en liste d'attente ET portent une
         * épreuve, puisque l'épreuve y est le contenant d'un arbre et non un
         * examen. La règle défendue ici — « pas de descriptif, pas d'épreuve »
         * — est une règle du référentiel CRMEF. */
        $specialitesCrmef = \DB::table('specialties')
            ->whereIn('track_id', $this->parcoursDuCrmef())
            ->pluck('id');

        $fermees = Specialty::whereIn('id', $specialitesCrmef)
            ->where('availability', '!=', 'open')->get();

        $this->assertGreaterThan(10, $fermees->count());

        foreach ($fermees as $specialite) {
            $this->assertSame(
                0,
                Exam::where('specialty_id', $specialite->id)->count(),
                "La spécialité {$specialite->slug} n'a pas de descriptif : aucune épreuve ne doit être créée."
            );
        }
    }

    public function test_seul_le_francais_est_ouvert_dans_le_secondaire(): void
    {
        $secondaire = Track::where('slug', 'secondaire')->firstOrFail();

        $ouvertes = Specialty::where('track_id', $secondaire->id)
            ->where('availability', 'open')->pluck('slug');

        $this->assertSame(['langue-francaise-secondaire'], $ouvertes->all());
    }

    public function test_les_parcours_primaires_existent_mais_restent_fermes(): void
    {
        foreach (['primaire-bilingue', 'primaire-amazigh'] as $slug) {
            $parcours = Track::where('slug', $slug)->firstOrFail();

            $this->assertSame('waitlist', $parcours->availability);
            $this->assertSame(0, Exam::where('track_id', $parcours->id)->count());
        }
    }

    // --- Provenance et sources ---------------------------------------------

    public function test_chaque_noeud_officiel_cite_sa_source(): void
    {
        $sansSource = CompetencyNode::where('provenance', 'official')
            ->whereNull('source_id')->count();

        $this->assertSame(0, $sansSource, 'Un poids officiel sans source n\'est pas auditable.');
    }

    public function test_le_registre_contient_les_trois_descriptifs(): void
    {
        foreach (['SRC-CRMEF-2025-SE', 'SRC-CRMEF-2025-FR-DID', 'SRC-CRMEF-2025-FR-SPEC'] as $code) {
            $source = Source::where('code', $code)->firstOrFail();

            $this->assertTrue($source->isOfficial());
            $this->assertSame('Novembre 2025', $source->session_label);
            $this->assertNotNull($source->authority_fr);
        }
    }

    public function test_les_matrices_sont_bilingues(): void
    {
        $sansArabe = CompetencyNode::whereNull('name_ar')->orWhere('name_ar', '')->count();

        $this->assertSame(0, $sansArabe);
    }

    // --- Cohérence de l'arbre ----------------------------------------------

    public function test_un_noeud_ne_peut_pas_avoir_un_parent_d_une_autre_epreuve(): void
    {
        $se = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $did = Exam::where('code', 'CRMEF-FR-DID-2025')->firstOrFail();
        $racineDid = CompetencyNode::where('exam_id', $did->id)->whereNull('parent_id')->firstOrFail();

        $this->expectException(QueryException::class);

        CompetencyNode::create([
            'exam_id' => $se->id, 'parent_id' => $racineDid->id,
            'code' => 'INTRUS', 'name_fr' => 'Intrus', 'name_ar' => 'دخيل',
        ]);
    }

    public function test_le_profil_nomme_les_niveaux_de_chaque_epreuve(): void
    {
        $did = Exam::where('code', 'CRMEF-FR-DID-2025')->with('taxonomyProfile')->firstOrFail();

        $this->assertSame('Bloc', $did->taxonomyProfile->levelName(0));
        $this->assertSame('Sous-domaine', $did->taxonomyProfile->levelName(1));

        $se = Exam::where('code', 'CRMEF-SE-2025')->with('taxonomyProfile')->firstOrFail();
        $this->assertSame('Domaine', $se->taxonomyProfile->levelName(0));
    }

    // --- Carte de couverture du corpus --------------------------------------

    public function test_le_secondaire_compte_onze_specialites(): void
    {
        $secondaire = Track::where('slug', 'secondaire')->firstOrFail();

        $this->assertSame(
            11,
            Specialty::where('track_id', $secondaire->id)->count(),
            'L\'inventaire des sources fait foi : onze disciplines au secondaire.'
        );
    }

    public function test_les_specialites_ecartees_ne_sont_pas_creees(): void
    {
        foreach (['sciences-economiques', 'technologie'] as $slug) {
            $this->assertNull(
                Specialty::where('slug', $slug)->first(),
                "La spécialité {$slug} ne figure pas dans l'inventaire des sources."
            );
        }
    }

    public function test_le_primaire_bilingue_a_ses_quatre_disciplines(): void
    {
        $bilingue = Track::where('slug', 'primaire-bilingue')->firstOrFail();

        $this->assertSame(4, Specialty::where('track_id', $bilingue->id)->count());
    }

    public function test_la_carte_du_corpus_recense_trente_deux_descriptifs(): void
    {
        $this->assertSame(32, Source::count());

        $this->assertSame(3, Source::where('transposition_status', 'transpose')->count());
        $this->assertSame(29, Source::where('transposition_status', 'identifie_non_transpose')->count());
    }

    public function test_chaque_source_du_corpus_porte_sa_composante(): void
    {
        $this->assertSame(0, Source::whereNull('component')->count());

        // Trois composantes : sciences de l'éducation, didactique, discipline.
        $this->assertSame(3, Source::distinct()->pluck('component')->count());
    }

    public function test_les_sources_transposees_sont_celles_du_francais_et_du_tronc_commun(): void
    {
        $transposees = Source::where('transposition_status', 'transpose')
            ->pluck('discipline_label_fr')->unique()->sort()->values();

        $this->assertSame(['Commune', 'Langue française'], $transposees->all());
    }

    public function test_aucune_specialite_orpheline_ne_subsiste(): void
    {
        $this->assertSame(
            0,
            Specialty::whereNull('track_id')->count(),
            'Les spécialités provisoires du PAS-4 doivent avoir été remplacées.'
        );
    }
}
