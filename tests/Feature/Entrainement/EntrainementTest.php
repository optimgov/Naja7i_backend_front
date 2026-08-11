<?php

namespace Tests\Feature\Entrainement;

use App\Exceptions\TrainingScopeTooNarrow;
use App\Models\Attempt;
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
use App\Services\QuestionTransitionService;
use App\Services\RemediationPlanner;
use App\Tenancy\TenantContext;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\Crmef2025Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Composition d'une session d'entraînement.
 *
 * Ce que ces tests défendent, et que le diagnostic n'avait pas à défendre :
 * une série ciblée le RESTE. Un entraînement complété hors périmètre serait
 * un mini-diagnostic déguisé, et le candidat croirait avoir travaillé son
 * point faible.
 */
class EntrainementTest extends TestCase
{
    use RefreshDatabase;

    private Exam $epreuve;

    private User $candidat;

    private User $valideur;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->seed(CatalogueSeeder::class);
        $this->seed(Crmef2025Seeder::class);

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->source = Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail();

        $this->candidat = $this->utilisateur('candidat@naja7i.ma');
        $this->candidat->grantCandidateRole();
        $this->valideur = $this->utilisateur('valideur@naja7i.ma');
    }

    private function utilisateur(string $email): User
    {
        return User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
    }

    private function noeud(string $code): CompetencyNode
    {
        return CompetencyNode::where('code', $code)->firstOrFail();
    }

    /** Peuple un sous-domaine de questions publiées, éligibles au diagnostic. */
    private function peupler(CompetencyNode $noeud, int $combien): void
    {
        $remediation = Remediation::firstOrCreate(
            ['competency_node_id' => $noeud->id, 'locale' => 'fr'],
            ['title' => "Remédiation {$noeud->code}", 'content' => 'Contenu.', 'estimated_minutes' => 8, 'status' => 'published'],
        );

        $transitions = app(QuestionTransitionService::class);

        for ($i = 1; $i <= $combien; $i++) {
            $question = Question::create([
                'exam_id' => $this->epreuve->id,
                'competency_node_id' => $noeud->id,
                'locale' => 'fr',
                'sibling_group' => (string) Str::uuid7(),
                'stem' => "Question {$i} — {$noeud->code}",
                'explanation' => 'Justification.',
                'remediation_id' => $remediation->id,
                'author_id' => $this->utilisateur("auteur-{$noeud->code}-{$i}@naja7i.ma")->id,
            ]);

            foreach ([
                ['A', false, 'A est fausse.', 'confusion_notions'],
                ['B', true, 'B est juste.', null],
                ['C', false, 'C est fausse.', 'lecture_enonce'],
                ['D', false, 'D est fausse.', 'connaissance_absente'],
            ] as $p => [$c, $juste, $justif, $cause]) {
                QuestionOption::create([
                    'question_id' => $question->id, 'position' => $p + 1,
                    'content' => $c, 'is_correct' => $juste, 'rationale' => $justif, 'cause' => $cause,
                ]);
            }

            $question->contentSources()->attach($this->source->id, ['verification' => 'verified']);

            $transitions->submitForReview($question);
            $transitions->markReviewed($question, $this->valideur);
            $transitions->validate($question, $this->valideur);
            $transitions->publish($question, forDiagnostic: true);
        }
    }

    private function service(): AttemptService
    {
        return app(AttemptService::class);
    }

    /** Répond à toute la série, juste ou faux, puis soumet. */
    private function passer(Attempt $attempt, bool $juste): Attempt
    {
        foreach ($attempt->items()->with('question.options')->get() as $item) {
            $option = $juste
                ? $item->question->correctOption()
                : $item->question->distractors()->first();

            $this->service()->answer($item, $option, $juste ? 'sure' : 'hesitant');
        }

        return $this->service()->submit($attempt->fresh());
    }

    // --- Le périmètre n'est jamais élargi -----------------------------------

    public function test_un_vivier_trop_maigre_refuse_plutot_que_de_completer(): void
    {
        $cible = $this->noeud('SE-PSY-DEV');
        $ailleurs = $this->noeud('SE-SOC-EDU');

        $this->peupler($cible, 3);      // sous le minimum utile
        $this->peupler($ailleurs, 20);  // vivier abondant, HORS périmètre

        $this->expectException(TrainingScopeTooNarrow::class);

        $this->service()->startTraining(
            $this->candidat, $this->epreuve, [$cible->id], 'fr', (string) Str::uuid7(), 15
        );
    }

    public function test_une_serie_courte_reste_dans_le_perimetre(): void
    {
        $cible = $this->noeud('SE-PSY-DEV');
        $ailleurs = $this->noeud('SE-SOC-EDU');

        $this->peupler($cible, 7);
        $this->peupler($ailleurs, 30);

        $session = $this->service()->startTraining(
            $this->candidat, $this->epreuve, [$cible->id], 'fr', (string) Str::uuid7(), 15
        );

        $attempt = $session['attempt'];

        $this->assertSame(7, $attempt->item_count, 'On sert moins, on ne complète pas.');

        $noeuds = $attempt->items()->pluck('competency_node_id')->unique();
        $this->assertSame([$cible->id], $noeuds->values()->all(),
            'Aucune question hors du périmètre demandé.');
    }

    // --- Anti-répétition ----------------------------------------------------

    public function test_la_seconde_session_ne_ressert_pas_les_questions_reussies(): void
    {
        $cible = $this->noeud('SE-PSY-DEV');
        $this->peupler($cible, 20);

        $premiere = $this->service()->startTraining(
            $this->candidat, $this->epreuve, [$cible->id], 'fr', (string) Str::uuid7(), 8
        )['attempt'];

        $vuesEtReussies = $premiere->items()->pluck('question_id')->all();
        $this->passer($premiere, juste: true);

        $seconde = $this->service()->startTraining(
            $this->candidat, $this->epreuve, [$cible->id], 'fr', (string) Str::uuid7(), 8
        )['attempt'];

        $servies = $seconde->items()->pluck('question_id')->all();

        $this->assertEmpty(
            array_intersect($vuesEtReussies, $servies),
            'Une question déjà réussie ne revient pas tant que le vivier tient.'
        );
    }

    public function test_une_question_manquee_revient_avant_une_question_reussie(): void
    {
        $cible = $this->noeud('SE-PSY-DEV');
        $this->peupler($cible, 10);

        // Session 1 : cinq questions, toutes MANQUÉES.
        $premiere = $this->service()->startTraining(
            $this->candidat, $this->epreuve, [$cible->id], 'fr', (string) Str::uuid7(), 5
        )['attempt'];
        $manquees = $premiere->items()->pluck('question_id')->all();
        $this->passer($premiere, juste: false);

        // Session 2 : les cinq jamais vues passent d'abord — rang 0.
        $seconde = $this->service()->startTraining(
            $this->candidat, $this->epreuve, [$cible->id], 'fr', (string) Str::uuid7(), 5
        )['attempt'];
        $reussies = $seconde->items()->pluck('question_id')->all();

        $this->assertEmpty(
            array_intersect($manquees, $reussies),
            'Tant qu\'il reste des questions neuves, elles passent avant les manquées.'
        );

        $this->passer($seconde, juste: true);

        // Session 3 : le vivier neuf est épuisé. Les MANQUÉES reviennent, pas
        // les réussies — c'est le cœur de l'entraînement.
        $troisieme = $this->service()->startTraining(
            $this->candidat, $this->epreuve, [$cible->id], 'fr', (string) Str::uuid7(), 5
        )['attempt'];
        $servies = $troisieme->items()->pluck('question_id')->all();

        sort($manquees);
        sort($servies);

        $this->assertSame($manquees, $servies,
            'Vivier neuf épuisé : les manquées reviennent avant toute question déjà réussie.');
    }

    // --- Idempotence et session unique -------------------------------------

    public function test_deux_appels_avec_la_meme_cle_ne_creent_qu_une_tentative(): void
    {
        $cible = $this->noeud('SE-PSY-DEV');
        $this->peupler($cible, 20);

        $cle = (string) Str::uuid7();

        $a = $this->service()->startTraining($this->candidat, $this->epreuve, [$cible->id], 'fr', $cle, 10)['attempt'];
        $b = $this->service()->startTraining($this->candidat, $this->epreuve, [$cible->id], 'fr', $cle, 10)['attempt'];

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Attempt::where('kind', 'training')->count());
    }

    public function test_une_seule_session_ouverte_a_la_fois(): void
    {
        $cible = $this->noeud('SE-PSY-DEV');
        $this->peupler($cible, 20);

        $a = $this->service()->startTraining($this->candidat, $this->epreuve, [$cible->id], 'fr', (string) Str::uuid7(), 10)['attempt'];

        // Clé DIFFÉRENTE : c'est l'unicité de session qui doit répondre.
        $b = $this->service()->startTraining($this->candidat, $this->epreuve, [$cible->id], 'fr', (string) Str::uuid7(), 10)['attempt'];

        $this->assertSame($a->id, $b->id, 'La seconde demande rend la session en cours.');
        $this->assertSame(1, Attempt::where('kind', 'training')->count());
    }

    // --- Le périmètre par défaut vient de l'ordonnance ----------------------

    public function test_sans_noeud_demande_le_perimetre_vient_de_l_ordonnance(): void
    {
        $faible = $this->noeud('SE-PSY-DEV');
        $autre = $this->noeud('SE-SOC-EDU');

        $this->peupler($faible, 15);
        $this->peupler($autre, 15);

        /* Le candidat échoue sur SE-PSY-DEV : l'ordonnance doit le placer en
         * tête, et l'entraînement sans nœud demandé doit y puiser. */
        $diagnostic = $this->service()->startTraining(
            $this->candidat, $this->epreuve, [$faible->id], 'fr', (string) Str::uuid7(), 10
        )['attempt'];
        $this->passer($diagnostic, juste: false);
        app(MasteryCalculator::class)->recomputeForExam($this->candidat, $this->epreuve);

        $priorites = app(RemediationPlanner::class)
            ->prioritize($this->candidat, $this->epreuve, 3)
            ->pluck('node_code')
            ->all();

        $this->assertContains('SE-PSY-DEV', $priorites, "Le domaine échoué figure dans l'ordonnance.");

        $this->candidat->markEmailAsVerified();

        $reponse = $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/training/{$this->epreuve->code}", ['total' => 8]);

        $reponse->assertCreated();

        $attempt = Attempt::where('kind', 'training')
            ->where('user_id', $this->candidat->id)
            ->latest('id')
            ->first();

        $codes = CompetencyNode::whereIn('id', $attempt->items()->pluck('competency_node_id'))
            ->pluck('code')
            ->unique()
            ->values()
            ->all();

        foreach ($codes as $code) {
            $this->assertContains($code, $priorites,
                "Sans nœud demandé, la série ne sort pas des têtes de l'ordonnance.");
        }
    }

    // --- Pas de chronomètre --------------------------------------------------

    public function test_une_session_d_entrainement_n_a_pas_de_chronometre(): void
    {
        $cible = $this->noeud('SE-PSY-DEV');
        $this->peupler($cible, 12);

        $attempt = $this->service()->startTraining(
            $this->candidat, $this->epreuve, [$cible->id], 'fr', (string) Str::uuid7(), 8
        )['attempt'];

        $this->assertNull($attempt->expires_at);
        $this->assertNull($attempt->secondsRemaining());
        $this->assertFalse($attempt->hasExpired());
    }

    // --- La maîtrise bouge ----------------------------------------------------

    public function test_une_session_soumise_fait_bouger_la_maitrise_du_noeud_vise(): void
    {
        $cible = $this->noeud('SE-PSY-DEV');
        $this->peupler($cible, 20);

        $attempt = $this->service()->startTraining(
            $this->candidat, $this->epreuve, [$cible->id], 'fr', (string) Str::uuid7(), 10
        )['attempt'];

        $this->passer($attempt, juste: true);
        app(MasteryCalculator::class)->recomputeForExam($this->candidat, $this->epreuve);

        $score = MasteryScore::where('user_id', $this->candidat->id)
            ->where('competency_node_id', $cible->id)
            ->first();

        $this->assertNotNull($score, "L'entraînement alimente la maîtrise.");
        $this->assertSame(10, $score->answered_count);
        $this->assertNotNull($score->score, 'Dix réponses suffisent à sortir de l\'évidence insuffisante.');
    }
}
