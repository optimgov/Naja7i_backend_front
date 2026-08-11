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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
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

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->source = Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail();

        $this->candidat = User::create([
            'email' => 'candidat@naja7i.ma', 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $this->candidat->grantCandidateRole();

        /* Dix par sous-domaine : la sonde d'évitement rejoue la série de dix
         * de l'exemple fondateur, cinq répondues et cinq sautées. */
        $this->peuplerBanque(10);
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

    /**
     * Sert une série sur un sous-domaine, en laissant sauter certaines
     * questions. Une entrée `null` est un item SERVI et laissé sans réponse —
     * ce que fait un candidat qui passe son tour.
     *
     * @param  list<array{0: bool, 1: string}|null>  $reponses
     */
    private function servir(string $codeNoeud, array $reponses): void
    {
        $noeud = CompetencyNode::where('code', $codeNoeud)->firstOrFail();
        $service = app(AttemptService::class);

        $questions = Question::where('competency_node_id', $noeud->id)->take(count($reponses))->get();

        $this->assertCount(
            count($reponses), $questions,
            "La banque de {$codeNoeud} ne contient pas assez de questions pour cette sonde."
        );

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

            if ($reponses[$i] === null) {
                continue;   // servie, jamais répondue
            }

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

    /**
     * Le motif se lit, il ne se décode pas. La convention du planificateur
     * vaut pour `questions_sautees` comme pour les cinq autres : des mots,
     * jamais un code d'erreur ni un numéro.
     */
    public function test_le_motif_du_saut_est_un_texte_lisible_comme_les_autres(): void
    {
        $motifs = $this->sondeEvitement()->pluck('reason')->unique();

        $this->assertContains('questions_sautees', $motifs);

        foreach ($motifs as $motif) {
            $this->assertMatchesRegularExpression(
                '/^[a-z]+(_[a-z]+)*$/', $motif,
                "Le motif « {$motif} » doit rester un texte lisible, pas un code."
            );
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

    // --- L'évitement ne paie plus -------------------------------------------

    /**
     * Le défaut fondateur, rejoué tel qu'il a été constaté.
     *
     * Deux candidats — ici deux domaines de MÊME POIDS pour un seul candidat,
     * ce qui isole le comportement — reçoivent la même série de dix et
     * réussissent cinq questions chacun. L'un répond faux aux cinq autres,
     * l'autre les saute. Avant correctif : le premier tombait à 50 et prenait
     * la tête, le second affichait 100 et sortait de l'ordonnance.
     *
     * Deux témoins encadrent la mesure : un domaine tout juste (rien ne doit
     * bouger) et un domaine tout sauté (le cas extrême).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function sondeEvitement(): Collection
    {
        $this->servir('SE-PSY-DEV', array_merge(          // 20 % — répond faux
            array_fill(0, 5, [true, 'sure']),
            array_fill(0, 5, [false, 'hesitant']),
        ));

        $this->servir('SE-PSY-LEARN', array_merge(        // 20 % — saute
            array_fill(0, 5, [true, 'sure']),
            array_fill(0, 5, null),
        ));

        $this->servir('SE-SOC-EDU', array_fill(0, 10, [true, 'sure']));   // témoin : tout juste
        $this->servir('SE-SOC-GROUP', array_fill(0, 10, null));           // témoin : tout sauté

        app(MasteryCalculator::class)->recomputeForExam($this->candidat, $this->epreuve);

        return app(RemediationPlanner::class)->prioritize($this->candidat, $this->epreuve, 30);
    }

    public function test_une_question_sautee_laisse_une_trace_sans_toucher_au_score(): void
    {
        $this->sondeEvitement();

        $score = $this->maitrise('SE-PSY-LEARN');

        $this->assertSame(5, $score->answered_count);
        $this->assertSame(5, $score->skipped_count, 'La question servie et laissée est comptée.');
        $this->assertEqualsWithDelta(
            100.0, $score->score, 0.01,
            'Le score reste honnête : de ce qu\'il a tenté, tout était juste.'
        );
        $this->assertSame('low', $score->evidence, 'Ce qu\'il vaut se lit dans son volume d\'évidence.');
    }

    public function test_le_sauteur_remonte_dans_l_ordonnance_avec_son_propre_motif(): void
    {
        $priorites = $this->sondeEvitement();

        $sauteur = $priorites->firstWhere('node_code', 'SE-PSY-LEARN');
        $toutJuste = $priorites->firstWhere('node_code', 'SE-SOC-EDU');

        $this->assertGreaterThan(0.0, $sauteur['urgency'], 'Sauter ne sort plus de l\'ordonnance.');
        $this->assertGreaterThan(
            $toutJuste['urgency'], $sauteur['urgency'],
            'Le sauteur passe devant un domaine réellement maîtrisé — c\'est tout le correctif.'
        );

        $this->assertSame('questions_sautees', $sauteur['reason']);
        $this->assertNotSame('erreurs_avec_certitude', $sauteur['reason']);
        $this->assertNotSame('jamais_evalue', $sauteur['reason']);

        $this->assertSame(5, $sauteur['skipped_count'], 'Le candidat peut vérifier ce que le motif affirme.');
    }

    public function test_le_sauteur_ne_passe_pas_devant_celui_qui_a_repondu_faux(): void
    {
        $priorites = $this->sondeEvitement();

        $faux = $priorites->firstWhere('node_code', 'SE-PSY-DEV');
        $sauteur = $priorites->firstWhere('node_code', 'SE-PSY-LEARN');

        $this->assertLessThan(
            $faux['urgency'], $sauteur['urgency'],
            'L\'erreur démontrée reste le signal le plus sûr : rien ne la dépasse.'
        );
    }

    /**
     * La sonde de calibration : elle ne juge pas une valeur, elle tient la
     * BORNE au-delà de laquelle le correctif se retournerait.
     *
     * Les deux domaines pèsent 20 %, et le sauteur a esquivé la moitié de ce
     * qui lui a été servi. Son urgence vaut donc poids × facteur × 0,5, celle
     * du répondeur poids × 0,5 — le facteur réellement appliqué se relit dans
     * le résultat, sans que la constante ait à être exposée.
     */
    public function test_le_facteur_du_saut_reste_sous_la_borne_mesuree(): void
    {
        $priorites = $this->sondeEvitement();

        $sauteur = $priorites->firstWhere('node_code', 'SE-PSY-LEARN');
        $facteur = $sauteur['urgency'] / ($sauteur['weight_percent'] * 0.5);

        $this->assertGreaterThan(0.0, $facteur, 'À facteur nul, sauter redevient gratuit.');
        $this->assertLessThan(
            1.0, $facteur,
            'Borne mesurée : à 1,0 le sauteur égale exactement celui qui a répondu faux, '
            .'au-delà il le dépasse. Le réétalonnage peut déplacer la valeur, jamais franchir ce plafond.'
        );
    }

    public function test_un_domaine_entierement_repondu_et_reussi_ne_bouge_pas(): void
    {
        $priorites = $this->sondeEvitement();

        $toutJuste = $priorites->firstWhere('node_code', 'SE-SOC-EDU');

        $this->assertSame(0, $this->maitrise('SE-SOC-EDU')->skipped_count);
        $this->assertEqualsWithDelta(
            0.0, $toutJuste['urgency'], 0.001,
            'Le correctif ne déplace aucun domaine que le candidat a entièrement affronté.'
        );
    }

    public function test_un_domaine_entierement_saute_n_est_pas_un_angle_mort(): void
    {
        $priorites = $this->sondeEvitement();

        $toutSaute = $priorites->firstWhere('node_code', 'SE-SOC-GROUP');
        $jamaisServi = $priorites->firstWhere('reason', 'jamais_evalue');

        $this->assertNull($toutSaute['score'], 'Aucune réponse ne fonde aucun score.');
        $this->assertSame(10, $toutSaute['skipped_count']);
        $this->assertSame(
            'questions_sautees', $toutSaute['reason'],
            'Servi puis refusé n\'est pas jamais servi : le candidat ne doit pas lire « à découvrir ».'
        );

        /* Même urgence à poids égal que le domaine jamais servi, et c'est
         * assumé : sur ce qui n'a pas été répondu, on ne sait rien dans les
         * deux cas. Ce qui les sépare est le motif, pas la connaissance. */
        $this->assertSame(15.0, $toutSaute['weight_percent']);
        $this->assertSame(15.0, $jamaisServi['weight_percent']);
        $this->assertEqualsWithDelta($jamaisServi['urgency'], $toutSaute['urgency'], 0.001);
    }

    public function test_le_parent_herite_des_questions_sautees(): void
    {
        $this->sondeEvitement();

        $this->assertSame(5, $this->maitrise('SE-PSY')->skipped_count, 'Cinq sautées sous Psychologie.');
        $this->assertSame(10, $this->maitrise('SE-SOC')->skipped_count, 'Dix sous Sociologie.');
    }

    public function test_une_tentative_en_cours_ne_produit_aucune_sautee(): void
    {
        $noeud = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();

        $attempt = Attempt::create([
            'user_id' => $this->candidat->id, 'exam_id' => $this->epreuve->id,
            'locale' => 'fr', 'idempotency_key' => (string) Str::uuid7(),
            'kind' => 'training', 'status' => 'in_progress', 'started_at' => now(),
            'item_count' => 5,
        ]);

        foreach (Question::where('competency_node_id', $noeud->id)->take(5)->get() as $i => $question) {
            AttemptItem::create([
                'attempt_id' => $attempt->id, 'question_id' => $question->id,
                'competency_node_id' => $question->competency_node_id, 'position' => $i + 1,
            ]);
        }

        app(MasteryCalculator::class)->recomputeForExam($this->candidat, $this->epreuve);

        $this->assertNull(
            $this->maitrise('SE-PSY-DEV'),
            'Ne pas avoir encore répondu n\'est pas avoir sauté : c\'est ne pas avoir fini.'
        );
    }

    public function test_le_recalcul_ne_gonfle_pas_les_sautees(): void
    {
        $this->servir('SE-PSY-LEARN', array_merge(
            array_fill(0, 5, [true, 'sure']),
            array_fill(0, 5, null),
        ));

        app(MasteryCalculator::class)->recomputeForExam($this->candidat, $this->epreuve);
        app(MasteryCalculator::class)->recomputeForExam($this->candidat, $this->epreuve);

        $this->assertSame(5, $this->maitrise('SE-PSY-LEARN')->skipped_count);
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
