<?php

namespace Tests\Feature\Correctifs;

use App\Contracts\AccessGrant;
use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\CauseAcquisition;
use App\Models\CauseRevealCounter;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Remediation;
use App\Models\Response;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\CauseRevealService;
use App\Services\QuestionTransitionService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\OuvreLesDroits;
use Tests\TestCase;

/**
 * Audit tournée 2 — les courses que la tournée 1 n'avait pas couvertes.
 *
 * Deux des trois bloquants sont des COURSES, et la troisième — la fuite de
 * cause du miroir — est séquentielle : elle vit dans `QuestionMiroirTest`, où
 * la garantie est éprouvée DANS LES DEUX SENS, servie quand la cause est
 * acquise, tue quand elle ne l'est pas. C'est la leçon de méthode de cette
 * tournée : une garantie testée dans un seul sens laisse passer son contraire.
 *
 * L'entrelacement est imposé comme au PAS-21 : `DB::listen` observe la
 * connexion principale et, à l'instant précis où elle s'apprête à écrire, une
 * SECONDE connexion écrit et valide.
 */
class AuditTournee2Test extends TestCase
{
    /** Voir `AuditRevisionTest` : une seconde connexion doit VOIR le montage. */
    use DatabaseMigrations, OuvreLesDroits;

    private Exam $epreuve;

    private User $candidat;

    private CompetencyNode $noeud;

    private Source $source;

    private User $valideur;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->source = Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail();
        $this->noeud = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();

        $this->valideur = $this->utilisateur('valideur@naja7i.ma');
        $this->candidat = $this->utilisateur('candidat@naja7i.ma');
        $this->candidat->grantCandidateRole();
        $this->candidat->markEmailAsVerified();

        /* Le droit de répondre, sans enveloppe (lot 3B) : cet audit porte sur
         * la cause et le miroir, pas sur le comptage. */
        $this->ouvrirLesDroits($this->candidat, AccessGrant::QUESTIONS_ANSWER);
    }

    private function utilisateur(string $email): User
    {
        $u = User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $u->markEmailAsVerified();

        return $u;
    }

    private function peupler(int $combien, ?CompetencyNode $noeud = null): void
    {
        $noeud ??= $this->noeud;

        $remediation = Remediation::firstOrCreate(
            ['competency_node_id' => $noeud->id, 'locale' => 'fr'],
            ['title' => 'R', 'content' => 'x', 'estimated_minutes' => 5, 'status' => 'published'],
        );

        $transitions = app(QuestionTransitionService::class);

        for ($i = 1; $i <= $combien; $i++) {
            $question = Question::create([
                'exam_id' => $this->epreuve->id,
                'competency_node_id' => $noeud->id,
                'locale' => 'fr',
                'sibling_group' => (string) Str::uuid7(),
                'stem' => "Énoncé {$i} — {$noeud->code}",
                'explanation' => 'Justification.',
                'remediation_id' => $remediation->id,
                'author_id' => $this->utilisateur("a-{$noeud->code}-{$i}@naja7i.ma")->id,
            ]);

            foreach ([
                ['A', false, 'confusion_notions'],
                ['B', true, null],
                ['C', false, 'lecture_enonce'],
                ['D', false, 'connaissance_absente'],
                ['Aucune des propositions précédentes', false, 'indetermine'],
            ] as $p => [$c, $juste, $cause]) {
                QuestionOption::create([
                    'question_id' => $question->id, 'position' => $p + 1,
                    'content' => $c, 'is_correct' => $juste, 'rationale' => 'r', 'cause' => $cause,
                ]);
            }

            $question->contentSources()->attach($this->source->id, ['verification' => 'verified']);
            $transitions->submitForReview($question);
            $transitions->markReviewed($question, $this->relecteurDeControle());
            $transitions->validate($question, $this->valideur);
            $transitions->publish($question, forDiagnostic: true);
        }
    }

    /** Répond faux sur un distracteur donné, puis soumet. Rend la réponse. */
    private function repondreFaux(Question $question, int $positionDistracteur): Response
    {
        $service = app(AttemptService::class);

        $attempt = Attempt::create([
            'user_id' => $this->candidat->id, 'exam_id' => $this->epreuve->id,
            'locale' => 'fr', 'idempotency_key' => (string) Str::uuid7(),
            'kind' => 'training', 'status' => 'in_progress', 'started_at' => now(),
            'item_count' => 1,
        ]);

        $item = AttemptItem::create([
            'attempt_id' => $attempt->id, 'question_id' => $question->id,
            'competency_node_id' => $question->competency_node_id, 'position' => 1,
        ]);

        $service->answer($item, $question->options->firstWhere('position', $positionDistracteur), 'sure');
        $service->submit($attempt->fresh());

        return $item->fresh()->response;
    }

    private function pendantLaRequete(string $motif, callable $intervention): void
    {
        $dejaFait = false;

        DB::listen(function ($requete) use (&$dejaFait, $motif, $intervention) {
            if ($dejaFait || ! str_contains($requete->sql, $motif)) {
                return;
            }

            $dejaFait = true;
            $intervention(DB::connection('pgsql_concurrent'));
        });
    }

    /** Insère une acquisition depuis une SECONDE connexion, et valide. */
    private function acquisitionConcurrente(int $nodeId, string $cause): void
    {
        DB::connection('pgsql_concurrent')->table('cause_acquisitions')->insert([
            'uuid' => (string) Str::uuid7(),
            'tenant_id' => app(TenantContext::class)->id(),
            'user_id' => $this->candidat->id,
            'competency_node_id' => $nodeId,
            'cause' => $cause,
            'granted_by_access' => false,
            'acquired_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ===================================================================
    // BLOC-2 — deux révélations concurrentes du MÊME couple
    // ===================================================================

    /**
     * Le dommage exact : deux unités pour une seule cause.
     *
     * `coupleDejaPaye()` était testé AVANT la transaction et le remboursement
     * ne jouait que pour la MÊME réponse. Deux réponses distinctes du même
     * couple passaient toutes deux : le plafond restait atomique, la nouvelle
     * unité du PAS-26 ne l'était pas.
     *
     * L'acquisition étant désormais une LIGNE, la seconde transaction bute sur
     * l'index unique et constate au lieu de payer.
     */
    public function test_deux_revelations_concurrentes_du_meme_couple_coutent_une_unite(): void
    {
        $this->peupler(2);
        $questions = Question::where('competency_node_id', $this->noeud->id)->with('options')->orderBy('id')->get();

        // Deux réponses DISTINCTES, même couple (compétence, confusion_notions).
        $premiere = $this->repondreFaux($questions[0], 1);
        $seconde = $this->repondreFaux($questions[1], 1);

        $noeudId = $this->noeud->id;

        /* Au moment où la principale lit l'item de sa réponse — juste avant
         * d'écrire l'acquisition — une seconde connexion acquiert le couple. */
        $this->pendantLaRequete(
            'from "attempt_items"',
            fn () => $this->acquisitionConcurrente($noeudId, 'confusion_notions')
        );

        $ouverte = app(CauseRevealService::class)->reveal($this->candidat, $premiere, false);

        $this->assertTrue($ouverte, 'La cause est ouverte : elle vient d\'être acquise par l\'autre requête.');

        $this->assertSame(
            1,
            CauseAcquisition::where('user_id', $this->candidat->id)->count(),
            'Une seule acquisition : l\'index unique a tranché.'
        );
        $this->assertSame(
            0,
            (int) CauseRevealCounter::where('user_id', $this->candidat->id)->value('revealed_total'),
            'La principale n\'a RIEN consommé : le couple était déjà acquis quand elle a voulu l\'acheter.'
        );

        // Et la seconde réponse du même couple reste gratuite, elle aussi.
        app(CauseRevealService::class)->reveal($this->candidat, $seconde->fresh(), false);

        $this->assertSame(
            0,
            (int) CauseRevealCounter::where('user_id', $this->candidat->id)->value('revealed_total')
        );
    }

    /**
     * LE TÉMOIN. Deux couples DISTINCTS coûtent bien deux unités.
     *
     * Sans lui, un correctif qui rendrait toute acquisition gratuite ferait
     * passer le test précédent sans que personne ne le voie. La mutation de
     * BLOC-2 doit faire tomber l'un et laisser l'autre vert.
     */
    public function test_deux_couples_distincts_coutent_bien_deux_unites(): void
    {
        $this->peupler(2);
        $questions = Question::where('competency_node_id', $this->noeud->id)->with('options')->orderBy('id')->get();

        // Distracteur A puis C : deux causes, donc deux couples.
        $premiere = $this->repondreFaux($questions[0], 1);
        $seconde = $this->repondreFaux($questions[1], 3);

        $service = app(CauseRevealService::class);

        $this->assertTrue($service->reveal($this->candidat, $premiere, false));
        $this->assertTrue($service->reveal($this->candidat, $seconde, false));

        $this->assertSame(2, CauseAcquisition::where('user_id', $this->candidat->id)->count());
        $this->assertSame(
            2,
            (int) CauseRevealCounter::where('user_id', $this->candidat->id)->value('revealed_total'),
            'Deux causes différentes se paient deux fois : le couple est l\'unité, pas la cause seule.'
        );
    }

    public function test_le_plafond_tient_encore_sur_des_couples_distincts(): void
    {
        $this->peupler(3);
        $questions = Question::where('competency_node_id', $this->noeud->id)->with('options')->orderBy('id')->get();

        $service = app(CauseRevealService::class);

        $this->assertTrue($service->reveal($this->candidat, $this->repondreFaux($questions[0], 1), false));
        $this->assertTrue($service->reveal($this->candidat, $this->repondreFaux($questions[1], 3), false));

        $troisieme = $this->repondreFaux($questions[2], 4);

        $this->assertFalse(
            $service->reveal($this->candidat, $troisieme, false),
            'La troisième cause reste derrière l\'abonnement.'
        );
        $this->assertSame(
            2,
            CauseAcquisition::where('user_id', $this->candidat->id)->count(),
            'L\'acquisition refusée ne survit pas : sans ce retrait, elle serait acquise sans être payée.'
        );
        $this->assertFalse((bool) $troisieme->fresh()->cause_revealed);
    }

    // ===================================================================
    // BLOC-3 — la reprise après collision revalide l'empreinte
    // ===================================================================

    /**
     * Deux opérations différentes sous la même clé, lancées ensemble.
     *
     * Le contrôle préalable ne voit rien — aucune des deux n'a encore écrit.
     * L'une insère ; l'autre butait sur l'index de clé, relisait la ligne
     * gagnante et la RENDAIT, sans comparer son empreinte. Le client recevait
     * la tentative d'une autre opération. Deux correctifs justes séparément
     * — l'interception nommée du BLOC-4, l'empreinte du BLOC-5 — se
     * composaient en défaut.
     */
    public function test_une_collision_de_cle_sur_une_autre_operation_est_refusee(): void
    {
        $this->peupler(6);

        $cle = (string) Str::uuid7();
        $tenantId = app(TenantContext::class)->id();
        $candidatId = $this->candidat->id;
        $epreuveId = $this->epreuve->id;
        $uuidConcurrent = (string) Str::uuid7();

        /* Au moment où la principale cherche sa clé — et ne trouve rien — une
         * seconde connexion ouvre une AUTRE opération sous la même clé. */
        $this->pendantLaRequete(
            'idempotency_key',
            function ($seconde) use ($tenantId, $candidatId, $epreuveId, $cle, $uuidConcurrent) {
                $seconde->table('attempts')->insert([
                    'uuid' => $uuidConcurrent,
                    'tenant_id' => $tenantId,
                    'user_id' => $candidatId,
                    'exam_id' => $epreuveId,
                    'locale' => 'fr',
                    'idempotency_key' => $cle,
                    /* Empreinte d'une opération DIFFÉRENTE : peu importe
                     * laquelle, elle ne peut pas être celle de la demande en
                     * cours. */
                    'idempotency_fingerprint' => str_repeat('f', 64),
                    'kind' => 'training',
                    'status' => 'submitted',
                    'started_at' => now(),
                    'submitted_at' => now(),
                    'last_activity_at' => now(),
                    'item_count' => 1,
                    'answered_count' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        );

        $reponse = $this->actingAs($this->candidat)
            ->withHeaders(['Idempotency-Key' => $cle])
            ->postJson("/api/v1/me/diagnostics/{$this->epreuve->code}", ['total' => 5]);

        $this->assertSame(1, Attempt::where('uuid', $uuidConcurrent)->count(), 'intervention concurrente jouée');

        $reponse->assertStatus(409);
        $this->assertSame(
            'IDEMPOTENCY_KEY_REUSED', $reponse->json('error.code'),
            'La reprise après collision rendait la tentative de l\'AUTRE opération.'
        );
        $this->assertNull(
            $reponse->json('data.uuid'),
            'Rien de la tentative concurrente ne sort d\'ici.'
        );
    }

    /**
     * Un miroir déjà ouvert sur un AUTRE item ne se reprend pas.
     *
     * Sa charge utile décrit un item précis : rendre celui de l'item A en
     * réponse à une demande sur B servirait une question sans rapport, sous la
     * cause et la question source de B. Le défaut existait aussi hors course,
     * le contrôle préalable reprenant n'importe quel miroir ouvert.
     */
    public function test_un_miroir_ouvert_sur_un_autre_item_ne_se_reprend_pas(): void
    {
        $this->peupler(6);
        $questions = Question::where('competency_node_id', $this->noeud->id)->with('options')->orderBy('id')->get();

        $premier = $this->repondreFaux($questions[0], 1)->item;
        $second = $this->repondreFaux($questions[1], 1)->item;

        $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/mirrors/{$premier->uuid}")
            ->assertCreated();

        $reponse = $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/mirrors/{$second->uuid}");

        $reponse->assertStatus(409);
        $this->assertSame('MIRROR_ALREADY_OPEN', $reponse->json('error.code'));
        $this->assertSame(
            1,
            Attempt::where('kind', 'mirror')->where('status', 'in_progress')->count(),
            'Un seul miroir ouvert, et c\'est toujours le premier.'
        );
    }
}
