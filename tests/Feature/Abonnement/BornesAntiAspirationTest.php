<?php

namespace Tests\Feature\Abonnement;

use App\Contracts\AccessGrant;
use App\Models\Attempt;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Remediation;
use App\Models\ReviewSchedule;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\QuestionTransitionService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\OuvreLesDroits;
use Tests\TestCase;

/**
 * Les deux bornes qui rendent Q-16 tenable.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * « LE MIROIR ET LA SÉANCE NE CONSOMMENT PAS » EST UNE DÉCISION CONDITIONNELLE
 *
 * Le propriétaire l'a assortie d'une condition : les deux chemins doivent
 * rester AUTO-BORNÉS. Le raisonnement est solide et mérite d'être relu — un
 * miroir naît d'une erreur, une erreur naît d'une question, et toute question
 * est décomptée à son service. Le chemin est donc **payé en amont**, et le
 * plafonner une seconde fois ferait payer deux fois la même unité.
 *
 * Restent deux fuites, et ce fichier les ferme :
 *
 *  1. **La boucle sur un même couple.** Rien n'empêchait d'obtenir des miroirs
 *     indéfiniment sur la même (compétence, cause) tant que la banque en
 *     fournit. La borne N la referme. Elle N'EXISTAIT PAS avant ce lot — les
 *     trois autres gardes du miroir, oui.
 *  2. **La séance qui servirait n'importe quoi.** L'invariant était vrai par
 *     construction et garanti par personne : `ReviewComposer` part des
 *     rendez-vous échus, mais rien ne l'écrivait. Un test l'écrit.
 */
class BornesAntiAspirationTest extends TestCase
{
    use OuvreLesDroits, RefreshDatabase;

    private Exam $epreuve;

    private CompetencyNode $noeud;

    private User $candidat;

    private const CAUSE = 'confusion_notions';

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->noeud = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();

        $this->candidat = User::create([
            'email' => 'bornes@naja7i.ma', 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $this->candidat->markEmailAsVerified();
        $this->candidat->grantCandidateRole();
        $this->candidat = $this->candidat->fresh();

        $this->ouvrirLesDroits(
            $this->candidat,
            AccessGrant::QUESTIONS_ANSWER,
            AccessGrant::SERIES_TARGETED,
            AccessGrant::MEMORY_SESSIONS,
        );

        $this->peupler(12);
    }

    /** Douze questions sur un seul nœud, toutes portant la même cause en A. */
    private function peupler(int $combien): void
    {
        $auteur = User::create([
            'email' => 'auteur-bornes@naja7i.ma', 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $valideur = User::create([
            'email' => 'valideur-bornes@naja7i.ma', 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $source = Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail();
        $transitions = app(QuestionTransitionService::class);

        $remediation = Remediation::firstOrCreate(
            ['competency_node_id' => $this->noeud->id, 'locale' => 'fr'],
            ['title' => 'R', 'content' => 'x', 'estimated_minutes' => 5, 'status' => 'published'],
        );

        for ($i = 1; $i <= $combien; $i++) {
            $question = Question::create([
                'exam_id' => $this->epreuve->id,
                'competency_node_id' => $this->noeud->id,
                'locale' => 'fr',
                'sibling_group' => (string) Str::uuid7(),
                'stem' => "Question {$i} — bornes",
                'explanation' => 'Justification.',
                'remediation_id' => $remediation->id,
                'author_id' => $auteur->id,
            ]);

            foreach ([
                ['A', false, 'A est fausse.', self::CAUSE],
                ['B', true, 'B est juste.', null],
                ['C', false, 'C est fausse.', 'lecture_enonce'],
                ['D', false, 'D est fausse.', 'connaissance_absente'],
                ['Aucune des propositions précédentes', false, 'Fausse.', 'indetermine'],
            ] as $p => [$c, $juste, $justif, $cause]) {
                QuestionOption::create([
                    'question_id' => $question->id, 'position' => $p + 1,
                    'content' => $c, 'is_correct' => $juste, 'rationale' => $justif, 'cause' => $cause,
                ]);
            }

            $question->contentSources()->attach($source->id, ['verification' => 'verified']);

            $transitions->submitForReview($question);
            $transitions->markReviewed($question, $this->relecteurDeControle());
            $transitions->validate($question, $valideur);
            $transitions->publish($question, forDiagnostic: true);
        }
    }

    /** Une série ciblée sur le nœud, entièrement ratée sur la cause A. */
    private function serieRatee(int $total = 8): Attempt
    {
        $session = app(AttemptService::class)->startTraining(
            $this->candidat, $this->epreuve, [$this->noeud->id], 'fr', (string) Str::uuid7(), $total,
        );

        $attempt = $session['attempt'];

        foreach ($attempt->items()->with('question.options')->get() as $item) {
            $piege = $item->question->options->firstWhere('cause', self::CAUSE);

            $this->actingAs($this->candidat)->putJson(
                "/api/v1/me/attempts/{$attempt->uuid}/items/{$item->uuid}",
                ['option_uuid' => $piege->uuid, 'confidence' => 'hesitant'],
            )->assertOk();
        }

        $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/attempts/{$attempt->uuid}/submit")->assertOk();

        return $attempt->fresh();
    }

    // ═══ La borne N de miroirs par couple ══════════════════════════════════

    public function test_la_borne_de_miroirs_par_couple_refuse_le_quatrieme(): void
    {
        $attempt = $this->serieRatee();
        $items = $attempt->items()->orderBy('position')->get();

        for ($i = 0; $i < AttemptService::MIROIRS_PAR_COUPLE; $i++) {
            $miroir = $this->actingAs($this->candidat)
                ->postJson("/api/v1/me/mirrors/{$items[$i]->uuid}")
                ->assertCreated()
                ->json('data.uuid');

            /* Un seul miroir ouvert à la fois : on referme avant le suivant. */
            $this->actingAs($this->candidat)
                ->postJson("/api/v1/me/attempts/{$miroir}/submit")->assertOk();
        }

        $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/mirrors/{$items[AttemptService::MIROIRS_PAR_COUPLE]->uuid}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'MIRROR_QUOTA_REACHED')
            ->assertJsonPath('error.details.limit', AttemptService::MIROIRS_PAR_COUPLE);

        $this->assertSame(
            AttemptService::MIROIRS_PAR_COUPLE,
            Attempt::where('user_id', $this->candidat->id)->where('kind', 'mirror')->count(),
            'Le refus ne crée pas la tentative qu’il refuse.',
        );
    }

    public function test_le_refus_de_borne_se_distingue_d_une_banque_vide(): void
    {
        /* Les deux situations rendent 409, et c'est le CODE qui les sépare :
         * « la banque n'a pas d'autre énoncé » demande d'attendre, « vous avez
         * déjà vérifié ce point » demande de passer à autre chose. */
        $attempt = $this->serieRatee();
        $items = $attempt->items()->orderBy('position')->get();

        for ($i = 0; $i < AttemptService::MIROIRS_PAR_COUPLE; $i++) {
            $miroir = $this->actingAs($this->candidat)
                ->postJson("/api/v1/me/mirrors/{$items[$i]->uuid}")->assertCreated()->json('data.uuid');
            $this->actingAs($this->candidat)->postJson("/api/v1/me/attempts/{$miroir}/submit");
        }

        $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/mirrors/{$items[3]->uuid}")
            ->assertJsonPath('error.code', 'MIRROR_QUOTA_REACHED');

        $this->assertGreaterThan(
            AttemptService::MIROIRS_PAR_COUPLE,
            Question::where('competency_node_id', $this->noeud->id)->count(),
            'La banque avait de quoi servir : c’est bien la borne qui a refusé, pas le vide.',
        );
    }

    // ═══ L'invariant de la séance mémoire ══════════════════════════════════

    public function test_une_seance_ne_sert_que_des_items_rattaches_a_une_erreur_causee_du_compte(): void
    {
        $this->serieRatee();

        ReviewSchedule::where('user_id', $this->candidat->id)
            ->update(['due_on' => now()->subDay()->toDateString()]);

        $echus = ReviewSchedule::where('user_id', $this->candidat->id)
            ->get()
            ->map(fn (ReviewSchedule $r): string => $r->competency_node_id.'|'.$r->cause)
            ->all();

        $this->assertNotEmpty($echus, 'Sans rendez-vous échu, ce test mesurerait le vide.');

        $seance = $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/memory/{$this->epreuve->code}/session")
            ->assertCreated()
            ->json('data.uuid');

        $items = Attempt::where('uuid', $seance)->sole()
            ->items()->with('question.options')->get();

        $this->assertNotEmpty($items);

        foreach ($items as $item) {
            $couples = $item->question->options
                ->where('is_correct', false)
                ->whereNotNull('cause')
                ->map(fn (QuestionOption $o): string => $item->competency_node_id.'|'.$o->cause)
                ->all();

            $this->assertNotEmpty(
                array_intersect($couples, $echus),
                'Un item servi par la séance ne se rattache à AUCUNE erreur causée du compte. '
                .'C’est l’invariant que la matrice §3 bis demande d’écrire, et il vient de céder.',
            );
        }
    }

    public function test_une_seance_ne_s_ouvre_pas_sans_rendez_vous_echu(): void
    {
        /* La moitié qui empêche l'invariant d'être vrai par vacuité : sans
         * erreur causée, il n'y a pas de séance du tout. */
        $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/memory/{$this->epreuve->code}/session")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'MEMORY_NOTHING_DUE');
    }
}
