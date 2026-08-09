<?php

namespace Tests\Feature\Maitrise;

use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\MasteryScore;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Remediation;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\MasteryCalculator;
use App\Services\RemediationPlanner;
use App\Tenancy\TenantContext;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\Crmef2025Seeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MaitriseTest extends TestCase
{
    use RefreshDatabase;

    private Exam $epreuve;

    private User $candidat;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->seed(CatalogueSeeder::class);
        $this->seed(Crmef2025Seeder::class);

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->source = Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail();

        $this->candidat = User::create([
            'email' => 'candidat@naja7i.ma', 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $this->candidat->grantCandidateRole();

        $this->peuplerBanque(6);
    }

    private function peuplerBanque(int $parSousDomaine): void
    {
        $valideur = User::create([
            'email' => 'valideur@naja7i.ma', 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);

        foreach (CompetencyNode::where('exam_id', $this->epreuve->id)->where('depth', 1)->get() as $noeud) {
            $remediation = Remediation::create([
                'competency_node_id' => $noeud->id, 'locale' => 'fr',
                'title' => "Remédiation {$noeud->code}", 'content' => 'Contenu.',
                'estimated_minutes' => 8, 'status' => 'published',
            ]);

            for ($i = 1; $i <= $parSousDomaine; $i++) {
                $question = Question::create([
                    'exam_id' => $this->epreuve->id, 'competency_node_id' => $noeud->id,
                    'locale' => 'fr', 'sibling_group' => (string) Str::uuid7(),
                    'stem' => "Question {$i} — {$noeud->code}", 'explanation' => 'Justification.',
                    'remediation_id' => $remediation->id,
                    'status' => 'pedagogically_validated', 'validator_id' => $valideur->id,
                    'published_at' => now(),
                ]);

                foreach ([
                    ['A', false, 'A est fausse.', 'confusion_notions'],
                    ['B', true,  'B est juste.',  null],
                    ['C', false, 'C est fausse.', 'lecture_enonce'],
                    ['D', false, 'D est fausse.', 'connaissance_absente'],
                ] as $p => [$c, $juste, $justif, $cause]) {
                    QuestionOption::create([
                        'question_id' => $question->id, 'position' => $p + 1,
                        'content' => $c, 'is_correct' => $juste, 'rationale' => $justif, 'cause' => $cause,
                    ]);
                }

                $question->contentSources()->attach($this->source->id, ['verification' => 'verified']);
                $question->update(['eligible_for_diagnostic' => true, 'status' => 'published']);
            }
        }
    }

    /**
     * Fait répondre le candidat sur un sous-domaine précis.
     *
     * @param  list<array{0: bool, 1: string}>  $reponses  [juste ?, certitude]
     */
    private function repondre(string $codeNoeud, array $reponses): void
    {
        $noeud = CompetencyNode::where('code', $codeNoeud)->firstOrFail();
        $service = app(AttemptService::class);

        $questions = Question::where('competency_node_id', $noeud->id)->take(count($reponses))->get();

        $attempt = Attempt::create([
            'user_id' => $this->candidat->id, 'exam_id' => $this->epreuve->id,
            'locale' => 'fr', 'idempotency_key' => (string) Str::uuid7(),
            'kind' => 'training', 'status' => 'in_progress', 'started_at' => now(),
            'item_count' => count($reponses),
        ]);

        foreach ($questions as $i => $question) {
            $item = AttemptItem::create([
                'attempt_id' => $attempt->id, 'question_id' => $question->id,
                'competency_node_id' => $question->competency_node_id, 'position' => $i + 1,
            ]);

            [$juste, $certitude] = $reponses[$i];
            $option = $juste ? $question->correctOption() : $question->distractors()->first();

            $service->answer($item, $option, $certitude);
        }

        $service->submit($attempt);
    }

    private function maitrise(string $code): ?MasteryScore
    {
        $noeud = CompetencyNode::where('code', $code)->firstOrFail();

        return MasteryScore::where('user_id', $this->candidat->id)
            ->where('competency_node_id', $noeud->id)->first();
    }

    // --- Règle R04 : pas de score sans évidence ----------------------------

    public function test_en_dessous_du_seuil_aucun_score_n_est_produit(): void
    {
        $this->repondre('SE-PSY-DEV', [[true, 'sure'], [true, 'sure'], [true, 'sure']]);
        app(MasteryCalculator::class)->recomputeForExam($this->candidat, $this->epreuve);

        $score = $this->maitrise('SE-PSY-DEV');

        $this->assertSame(3, $score->answered_count);
        $this->assertSame('insufficient', $score->evidence);
        $this->assertNull($score->score, 'Trois questions ne fondent pas un score.');
        $this->assertFalse($score->isDisplayable());
        $this->assertSame(2, $score->answersMissing());
    }

    public function test_la_base_refuse_un_score_sans_evidence(): void
    {
        $noeud = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();

        $this->expectException(QueryException::class);

        MasteryScore::create([
            'user_id' => $this->candidat->id, 'exam_id' => $this->epreuve->id,
            'competency_node_id' => $noeud->id,
            'score' => 92.0, 'evidence' => 'insufficient',
            'answered_count' => 2, 'correct_count' => 2, 'computed_at' => now(),
        ]);
    }

    public function test_au_dessus_du_seuil_le_score_apparait(): void
    {
        $this->repondre('SE-PSY-DEV', array_fill(0, 5, [true, 'sure']));
        app(MasteryCalculator::class)->recomputeForExam($this->candidat, $this->epreuve);

        $score = $this->maitrise('SE-PSY-DEV');

        $this->assertSame('low', $score->evidence);
        $this->assertEqualsWithDelta(100.0, $score->score, 0.01);
        $this->assertTrue($score->isDisplayable());
    }

    // --- Certitude+ : la chance ne vaut pas la maîtrise --------------------

    public function test_une_reussite_au_hasard_ne_vaut_pas_une_reussite_sure(): void
    {
        $this->repondre('SE-PSY-DEV', array_fill(0, 5, [true, 'guess']));
        app(MasteryCalculator::class)->recomputeForExam($this->candidat, $this->epreuve);

        $score = $this->maitrise('SE-PSY-DEV');

        $this->assertSame(5, $score->correct_count, 'Le taux brut est de 100 %.');
        $this->assertSame(5, $score->lucky_guess_count);
        $this->assertLessThan(50.0, $score->score, 'Mais la maîtrise pondérée reste basse.');
    }

    public function test_une_erreur_avec_certitude_est_comptee_a_part(): void
    {
        $this->repondre('SE-PSY-DEV', [
            [false, 'sure'], [false, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'hesitant'],
        ]);
        app(MasteryCalculator::class)->recomputeForExam($this->candidat, $this->epreuve);

        $this->assertSame(2, $this->maitrise('SE-PSY-DEV')->confident_error_count);
    }

    public function test_une_reponse_hesitante_juste_vaut_moins_qu_une_reponse_sure(): void
    {
        $this->repondre('SE-PSY-DEV', array_fill(0, 5, [true, 'hesitant']));
        app(MasteryCalculator::class)->recomputeForExam($this->candidat, $this->epreuve);

        $this->assertEqualsWithDelta(85.0, $this->maitrise('SE-PSY-DEV')->score, 0.01);
    }

    // --- Agrégation le long de l'arbre --------------------------------------

    public function test_le_parent_agrege_ses_enfants_par_poids_officiel(): void
    {
        // Deux sous-domaines de poids égal (20 % chacun) sous Psychologie.
        $this->repondre('SE-PSY-DEV', array_fill(0, 5, [true, 'sure']));    // 100
        $this->repondre('SE-PSY-LEARN', array_fill(0, 5, [false, 'hesitant'])); // 0

        app(MasteryCalculator::class)->recomputeForExam($this->candidat, $this->epreuve);

        $parent = $this->maitrise('SE-PSY');

        $this->assertEqualsWithDelta(50.0, $parent->score, 0.01, 'Moyenne pondérée de 100 et 0 à poids égaux.');
        $this->assertSame(10, $parent->answered_count, 'L\'évidence s\'additionne.');
        $this->assertSame('sufficient', $parent->evidence);
    }

    public function test_un_parent_sans_evidence_suffisante_n_affiche_pas_de_score(): void
    {
        $this->repondre('SE-SOC-EDU', [[true, 'sure'], [true, 'sure']]);
        app(MasteryCalculator::class)->recomputeForExam($this->candidat, $this->epreuve);

        $parent = $this->maitrise('SE-SOC');

        $this->assertSame(2, $parent->answered_count);
        $this->assertNull($parent->score);
    }

    public function test_le_recalcul_est_idempotent(): void
    {
        $this->repondre('SE-PSY-DEV', array_fill(0, 5, [true, 'sure']));

        app(MasteryCalculator::class)->recomputeForExam($this->candidat, $this->epreuve);
        $premier = $this->maitrise('SE-PSY-DEV')->score;

        app(MasteryCalculator::class)->recomputeForExam($this->candidat, $this->epreuve);

        $this->assertSame($premier, $this->maitrise('SE-PSY-DEV')->score);
        $this->assertSame(1, MasteryScore::where('user_id', $this->candidat->id)
            ->where('competency_node_id', CompetencyNode::where('code', 'SE-PSY-DEV')->first()->id)->count());
    }

    // --- Ordonnance de remédiation -----------------------------------------

    public function test_le_domaine_le_plus_lourd_passe_avant_le_plus_faible_score(): void
    {
        // Sociologie (15 %) à 0, Psychologie du développement (20 %) à 40 %.
        $this->repondre('SE-SOC-EDU', array_fill(0, 5, [false, 'hesitant']));
        $this->repondre('SE-PSY-DEV', [[true, 'sure'], [true, 'sure'], [false, 'hesitant'], [false, 'hesitant'], [false, 'hesitant']]);

        app(MasteryCalculator::class)->recomputeForExam($this->candidat, $this->epreuve);

        $priorites = app(RemediationPlanner::class)->prioritize($this->candidat, $this->epreuve, 10);

        $this->assertNotEmpty($priorites);
        $this->assertSame('SE-SOC-EDU', $priorites->first()['node_code']);
    }

    public function test_une_erreur_avec_certitude_remonte_la_priorite(): void
    {
        $this->repondre('SE-PSY-DEV', array_fill(0, 5, [false, 'sure']));      // erreurs aveugles
        $this->repondre('SE-PSY-LEARN', array_fill(0, 5, [false, 'hesitant']));  // même score, hésitant

        app(MasteryCalculator::class)->recomputeForExam($this->candidat, $this->epreuve);

        $priorites = app(RemediationPlanner::class)->prioritize($this->candidat, $this->epreuve, 10);
        $premier = $priorites->first();

        $this->assertSame('SE-PSY-DEV', $premier['node_code']);
        $this->assertSame('erreurs_avec_certitude', $premier['reason']);
    }

    public function test_un_domaine_jamais_evalue_entre_avec_son_propre_motif(): void
    {
        $this->repondre('SE-PSY-DEV', array_fill(0, 5, [true, 'sure']));
        app(MasteryCalculator::class)->recomputeForExam($this->candidat, $this->epreuve);

        $priorites = app(RemediationPlanner::class)->prioritize($this->candidat, $this->epreuve, 10);
        $jamais = $priorites->firstWhere('node_code', 'SE-SOC-EDU');

        $this->assertNotNull($jamais);
        $this->assertSame('jamais_evalue', $jamais['reason']);
        $this->assertNull($jamais['score']);
    }

    public function test_chaque_priorite_porte_un_motif_lisible(): void
    {
        $this->repondre('SE-PSY-DEV', array_fill(0, 5, [false, 'hesitant']));
        app(MasteryCalculator::class)->recomputeForExam($this->candidat, $this->epreuve);

        foreach (app(RemediationPlanner::class)->prioritize($this->candidat, $this->epreuve, 10) as $ligne) {
            $this->assertNotEmpty($ligne['reason'], 'Une recommandation sans raison est une injonction.');
            $this->assertArrayHasKey('weight_percent', $ligne);
        }
    }

    public function test_la_remediation_absente_est_dite_absente(): void
    {
        Remediation::query()->update(['status' => 'draft']);

        $this->repondre('SE-PSY-DEV', array_fill(0, 5, [false, 'hesitant']));
        app(MasteryCalculator::class)->recomputeForExam($this->candidat, $this->epreuve);

        $ligne = app(RemediationPlanner::class)->prioritize($this->candidat, $this->epreuve, 10)->first();

        $this->assertNull($ligne['remediation'], 'On ne fabrique pas une ressource qui n\'existe pas.');
    }

    // --- Aucun score prédictif (METHODE §7.3) ------------------------------

    public function test_aucune_probabilite_de_reussite_n_est_produite(): void
    {
        $this->repondre('SE-PSY-DEV', array_fill(0, 5, [true, 'sure']));
        app(MasteryCalculator::class)->recomputeForExam($this->candidat, $this->epreuve);

        $resume = app(RemediationPlanner::class)->examSummary($this->candidat, $this->epreuve);
        $texte = strtolower(json_encode($resume, JSON_UNESCAPED_UNICODE));

        foreach (['probab', 'chance_reussite', 'prediction', 'admissib'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $texte);
        }
    }

    public function test_le_resume_expose_toujours_l_evidence_avec_le_score(): void
    {
        $this->repondre('SE-PSY-DEV', array_fill(0, 5, [true, 'sure']));
        app(MasteryCalculator::class)->recomputeForExam($this->candidat, $this->epreuve);

        foreach (app(RemediationPlanner::class)->examSummary($this->candidat, $this->epreuve)['domains'] as $domaine) {
            $this->assertArrayHasKey('evidence', $domaine);
            $this->assertArrayHasKey('answered_count', $domaine);
        }
    }

    // --- Isolation tenant ---------------------------------------------------

    public function test_la_maitrise_est_isolee_par_tenant(): void
    {
        $this->repondre('SE-PSY-DEV', array_fill(0, 5, [true, 'sure']));
        app(MasteryCalculator::class)->recomputeForExam($this->candidat, $this->epreuve);

        $this->assertGreaterThan(0, MasteryScore::count());

        app(TenantContext::class)->set(Tenant::create(['slug' => 'centre-fes', 'name' => 'Centre de Fès']));

        $this->assertSame(0, MasteryScore::count());
    }
}
