<?php

namespace Tests\Feature\Abonnement;

use App\Contracts\AccessGrant;
use App\Enums\QuotaPeriodicity;
use App\Models\AccessGrantRecord;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionConsumption;
use App\Models\QuestionOption;
use App\Models\Remediation;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\EnveloppeDeQuestions;
use App\Services\QuestionTransitionService;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * S-10 — deux onglets, un seul reliquat.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI CE FICHIER EST SÉPARÉ, ET POURQUOI IL MIGRE À CHAQUE TEST
 *
 * `RefreshDatabase` enveloppe chaque test dans une transaction annulée à la
 * fin : une SECONDE connexion n'y verrait rien de ce que la première a écrit,
 * et le test de concurrence mesurerait le vide. `DatabaseMigrations` commite,
 * au prix d'une migration par test. C'est le prix d'une vraie preuve, et c'est
 * le même que paie déjà `AuditRevisionTest`.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * DEUX MOITIÉS, ET IL FAUT LES DEUX
 *
 * L'énoncé de S-10 — « reliquat 12, deux séries de 10, total débité 12 » — se
 * décompose en deux propriétés qui ne se prouvent pas de la même façon :
 *
 *  1. **L'ARITHMÉTIQUE** : la seconde série compose sur ce qui RESTE, elle ne
 *     compose jamais dix sur une lecture périmée. Se prouve en séquence.
 *  2. **LA SÉRIALISATION** : la lecture du reliquat et la composition sont
 *     dans la même transaction verrouillée. Se prouve avec une seconde
 *     connexion qui tient le verrou — et c'est la seule moitié qu'une
 *     exécution séquentielle ne peut pas montrer.
 *
 * Sans la seconde, on aurait un test vert avec la composition hors du verrou.
 */
class ConcurrenceDuDebitTest extends TestCase
{
    use DatabaseMigrations;

    private Exam $epreuve;

    private User $candidat;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();

        $this->candidat = User::create([
            'email' => 'onglets@naja7i.ma', 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $this->candidat->markEmailAsVerified();
        $this->candidat->grantCandidateRole();
        $this->candidat = $this->candidat->fresh();

        /* RELIQUAT 12, exactement l'énoncé de S-10. */
        AccessGrantRecord::create([
            'user_id' => $this->candidat->id,
            'capability' => AccessGrant::QUESTIONS_ANSWER,
            'starts_at' => now()->subDay(),
            'origin' => 'account_level',
            'quota_unit' => 'questions',
            'quota_periodicity' => QuotaPeriodicity::CUMULATIVE_GRANT->value,
            'quota_value' => 12,
        ]);

        $this->peuplerLaBanque(8);
    }

    private function peuplerLaBanque(int $parSousDomaine): void
    {
        $auteur = User::create([
            'email' => 'auteur-onglets@naja7i.ma', 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $valideur = User::create([
            'email' => 'valideur-onglets@naja7i.ma', 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $source = Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail();
        $transitions = app(QuestionTransitionService::class);

        foreach (CompetencyNode::where('exam_id', $this->epreuve->id)->where('depth', 1)->get() as $noeud) {
            $remediation = Remediation::firstOrCreate(
                ['competency_node_id' => $noeud->id, 'locale' => 'fr'],
                ['title' => "Remédiation {$noeud->code}", 'content' => 'Contenu.',
                    'estimated_minutes' => 8, 'status' => 'published'],
            );

            for ($i = 1; $i <= $parSousDomaine; $i++) {
                $question = Question::create([
                    'exam_id' => $this->epreuve->id,
                    'competency_node_id' => $noeud->id,
                    'locale' => 'fr',
                    'sibling_group' => (string) Str::uuid7(),
                    'stem' => "Question {$i} — {$noeud->code}",
                    'explanation' => 'Justification.',
                    'remediation_id' => $remediation->id,
                    'author_id' => $auteur->id,
                ]);

                foreach ([
                    ['A', false, 'A est fausse.', 'confusion_notions'],
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
    }

    // ═══ Moitié 1 — l'arithmétique ═════════════════════════════════════════

    public function test_s10_deux_series_de_dix_sur_un_reliquat_de_douze_debitent_douze(): void
    {
        $premiere = app(AttemptService::class)->startDiagnostic(
            $this->candidat, $this->epreuve, 'fr', (string) Str::uuid7(), 10,
        );

        $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/attempts/{$premiere->uuid}/submit")->assertOk();

        $seconde = app(AttemptService::class)->startDiagnostic(
            $this->candidat, $this->epreuve, 'fr', (string) Str::uuid7(), 10,
        );

        $this->assertSame(10, $premiere->items()->count());
        $this->assertSame(
            2, $seconde->items()->count(),
            'La seconde compose sur ce qui RESTE. Dix ici viendrait d’une lecture périmée.',
        );

        $this->assertSame(
            12,
            QuestionConsumption::where('user_id', $this->candidat->id)->count(),
            'Douze débités, jamais vingt.',
        );
    }

    // ═══ Moitié 2 — la sérialisation, prouvée par une seconde connexion ════

    public function test_la_composition_reclame_le_verrou_du_droit(): void
    {
        /* La seconde connexion prend le verrou du couple (compte, capacité) et
         * le garde : c'est exactement l'onglet qui compose au même instant. */
        $seconde = DB::connection('pgsql_concurrent');
        $seconde->beginTransaction();
        $seconde->statement(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ['droit|'.$this->candidat->id.'|'.EnveloppeDeQuestions::CAPACITE],
        );

        DB::statement("SET lock_timeout = '600ms'");

        try {
            app(AttemptService::class)->startDiagnostic(
                $this->candidat, $this->epreuve, 'fr', (string) Str::uuid7(), 10,
            );

            $this->fail(
                'La composition a traversé sans réclamer le verrou du droit. '
                .'Deux onglets composeraient alors chacun dix items sur un reliquat de douze.'
            );
        } catch (QueryException $e) {
            $this->assertStringContainsString('lock timeout', strtolower($e->getMessage()));
        } finally {
            DB::statement("SET lock_timeout = '0'");
            $seconde->rollBack();
        }

        $this->assertSame(
            0,
            QuestionConsumption::where('user_id', $this->candidat->id)->count(),
            'Rien n’a été débité : la transaction bloquée est annulée en entier.',
        );
    }

    // ═══ La variante de l'ADR-0029 : deux validations de commande ══════════

    public function test_deux_commandes_de_trente_jours_reservent_soixante_jours(): void
    {
        /* Deux achats du même forfait, honorés par le chemin réel. Le second
         * octroi doit démarrer à la FIN du premier, pas maintenant : sans le
         * verrou du couple (compte, capacité), deux validations simultanées
         * liraient la même fin et réserveraient deux fois les mêmes trente
         * jours — le candidat paierait deux mois et en recevrait un. */
        foreach ([1, 2] as $achat) {
            $this->actingAs($this->candidat)
                ->postJson('/api/v1/me/orders/simulated', ['plan_code' => 'preparation-30j'])
                ->assertStatus(201);
        }

        $octrois = AccessGrantRecord::where('user_id', $this->candidat->id)
            ->where('capability', AccessGrant::CAUSE_REVEAL)
            ->where('origin', 'purchase')
            ->orderBy('starts_at')
            ->get();

        $this->assertCount(2, $octrois);
        $this->assertTrue(
            $octrois->last()->starts_at->equalTo($octrois->first()->ends_at),
            'Le second part exactement où le premier finit : aucun chevauchement, aucun trou.',
        );
        $this->assertSame(
            60,
            (int) $octrois->first()->starts_at->diffInDays($octrois->last()->ends_at),
            'Soixante jours réservés pour deux forfaits de trente.',
        );
    }
}
