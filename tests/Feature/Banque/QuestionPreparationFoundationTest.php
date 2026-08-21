<?php

namespace Tests\Feature\Banque;

use App\Enums\PreparedQuestionState;
use App\Enums\QuestionPreparationBatchStatus;
use App\Enums\QuestionPreparationEventType;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\PreparedQuestion;
use App\Models\Question;
use App\Models\QuestionPreparationBatch;
use App\Models\QuestionPreparationEvent;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\QuestionPreparationService;
use App\Tenancy\TenantContext;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuestionPreparationFoundationTest extends TestCase
{
    use RefreshDatabase;

    private QuestionPreparationService $service;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());
        $this->service = app(QuestionPreparationService::class);
        $this->actor = User::create([
            'email' => 'preparation@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
        ]);
        $this->actor->memberships()->create([
            'role_id' => Role::where('code', 'editeur')->whereNull('tenant_id')->value('id'),
        ]);
    }

    public function test_le_statut_source_est_mappe_sans_validation_parallele_ni_reponse_inventee(): void
    {
        $prepared = $this->prepare([
            'statut' => 'valide',
            'suggestion_reponse' => 'B',
            'stem' => 'Question relevée sur une annale.',
        ], [
            'statut' => 'valide',
            'difficulte' => 2,
            'domaine' => 'provisoire',
        ]);

        $this->assertSame(PreparedQuestionState::IMPORTED, $prepared->state);
        $this->assertSame('valide', $prepared->source_facts['statut']);
        $this->assertArrayNotHasKey('statut', $prepared->provisional);
        $this->assertArrayNotHasKey('difficulte', $prepared->provisional);
        $this->assertSame(2, $prepared->provisional_difficulty);
        $this->assertNull($prepared->declared_difficulty);
        $this->assertSame('B', $prepared->proposed_answer);
        $this->assertNull($prepared->confirmed_answer);
        $this->assertNull($prepared->answer_confirmed_by);
    }

    public function test_aucun_etat_valide_n_existe_dans_la_machine(): void
    {
        $this->assertNotContains('valid', array_column(PreparedQuestionState::cases(), 'value'));
        $this->assertNotContains('valide', array_column(PreparedQuestionState::cases(), 'value'));

        $prepared = $this->prepare();

        $this->expectException(QueryException::class);
        DB::statement("UPDATE prepared_questions SET state = 'valid' WHERE id = ?", [$prepared->id]);
    }

    public function test_rejouer_la_meme_source_est_idempotent(): void
    {
        $batch = $this->batch();
        $facts = ['statut' => 'a_saisir', 'stem' => 'Même source'];

        $first = $this->service->prepare($batch, 'REF-001', $facts);
        $second = $this->service->prepare($batch, 'REF-001', array_reverse($facts, true));

        $this->assertTrue($first->is($second));
        $this->assertSame(1, PreparedQuestion::where('import_ref', 'REF-001')->count());
    }

    public function test_une_retranscription_remplace_la_ligne_active_sans_effacer_l_historique(): void
    {
        $batch = $this->batch();
        $first = $this->service->prepare($batch, 'REF-002', [
            'statut' => 'source_illisible',
            'stem' => '[illisible]',
        ]);

        $replacement = $this->service->prepare($batch, 'REF-002', [
            'statut' => 'a_saisir',
            'stem' => 'Énoncé retranscrit depuis un exemplaire propre.',
        ]);

        $this->assertSame(PreparedQuestionState::REPLACED, $first->fresh()->state);
        $this->assertFalse($first->fresh()->active);
        $this->assertSame(PreparedQuestionState::IMPORTED, $replacement->state);
        $this->assertTrue($replacement->active);
        $this->assertSame($first->uuid, $replacement->supersedes_ref);
        $this->assertSame(2, PreparedQuestion::where('import_ref', 'REF-002')->count());
        $this->assertSame(1, PreparedQuestion::where('import_ref', 'REF-002')->where('active', true)->count());
    }

    public function test_les_faits_de_source_sont_immuables_en_base(): void
    {
        $prepared = $this->prepare(['statut' => 'a_saisir', 'stem' => 'Original']);

        $this->expectException(QueryException::class);
        $prepared->source_facts = ['statut' => 'a_saisir', 'stem' => 'Récrit sur place'];
        $prepared->save();
    }

    public function test_la_reponse_confirmee_exige_un_humain_et_reste_distincte_de_la_suggestion(): void
    {
        $prepared = $this->prepare([
            'suggestion_reponse' => 'B',
            'options' => ['Option A', 'Option B', 'Option C', 'Option D'],
        ]);
        $node = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();

        $qualified = $this->service->qualify($prepared, $this->actor, $node, 5);
        $answered = $this->service->confirmAnswer($qualified, $this->actor, 'D');

        $this->assertSame('B', $answered->proposed_answer);
        $this->assertSame('D', $answered->confirmed_answer);
        $this->assertSame(5, $answered->declared_difficulty);
        $this->assertSame($this->actor->id, $answered->answer_confirmed_by);
        $this->assertNotNull($answered->answer_confirmed_at);
        $this->assertSame(PreparedQuestionState::ANSWERED, $answered->state);
    }

    public function test_une_lettre_sans_option_source_ne_peut_pas_etre_confirmee(): void
    {
        $prepared = $this->prepare([
            'options' => ['Option A', 'Option B', 'Option C', 'Option D'],
        ]);
        $node = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();
        $prepared = $this->service->qualify($prepared, $this->actor, $node);

        $this->expectException(DomainException::class);
        $this->service->confirmAnswer($prepared, $this->actor, 'E');
    }

    public function test_la_base_refuse_une_reponse_confirmee_sans_trace_humaine(): void
    {
        $prepared = $this->prepare();
        $node = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();

        $this->expectException(QueryException::class);
        $prepared->forceFill([
            'competency_node_id' => $node->id,
            'qualified_by' => $this->actor->id,
            'qualified_at' => now(),
            'confirmed_answer' => 'A',
            'state' => PreparedQuestionState::ANSWERED,
        ])->save();
    }

    public function test_la_difficulte_declaree_est_nullable_et_bornee_de_un_a_cinq(): void
    {
        $prepared = $this->prepare();
        $this->assertNull($prepared->declared_difficulty);

        $updated = $this->service->declareDifficulty($prepared, $this->actor, 5);
        $this->assertSame(5, $updated->declared_difficulty);
        $this->assertSame($this->actor->id, $updated->difficulty_set_by);

        $this->expectException(DomainException::class);
        $this->service->declareDifficulty($updated, $this->actor, 6);
    }

    public function test_la_modification_de_difficulte_est_journalisee_avec_ancien_nouveau_acteur_et_date(): void
    {
        $prepared = $this->prepare();
        $this->service->declareDifficulty($prepared, $this->actor, 3);
        $this->service->declareDifficulty($prepared->fresh(), $this->actor, 5);

        $events = QuestionPreparationEvent::where('prepared_question_id', $prepared->id)
            ->where('event_type', QuestionPreparationEventType::DIFFICULTY_CHANGED->value)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $events);
        $this->assertSame(['difficulty' => null], $events[0]->before);
        $this->assertSame(['difficulty' => 3], $events[0]->after);
        $this->assertSame(['difficulty' => 3], $events[1]->before);
        $this->assertSame(['difficulty' => 5], $events[1]->after);
        $this->assertSame($this->actor->id, $events[1]->actor_id);
        $this->assertNotNull($events[1]->occurred_at);
    }

    public function test_les_gestes_humains_emploient_un_vocabulaire_ferme_sans_copier_le_contenu(): void
    {
        $node = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();
        $answered = $this->prepare([
            'stem' => 'Énoncé sensible qui ne doit pas entrer dans le journal.',
            'options' => ['A', 'B', 'C', 'D'],
        ]);
        $answered = $this->service->qualify($answered, $this->actor, $node);
        $this->service->confirmAnswer($answered, $this->actor, 'A');

        $original = $this->prepare(importRef: 'JOURNAL-ORIGINAL');
        $duplicate = $this->prepare(importRef: 'JOURNAL-DUPLICATE');
        $this->service->markDuplicate($duplicate, $this->actor, $original);

        $rejected = $this->prepare(importRef: 'JOURNAL-REJET');
        $this->service->reject($rejected, $this->actor, 'Motif interne à ne pas recopier.');

        $types = QuestionPreparationEvent::all()
            ->map(fn (QuestionPreparationEvent $event) => $event->event_type->value)
            ->all();
        $this->assertContains(QuestionPreparationEventType::QUALIFICATION_CHANGED->value, $types);
        $this->assertContains(QuestionPreparationEventType::ANSWER_CONFIRMED->value, $types);
        $this->assertContains(QuestionPreparationEventType::MARKED_DUPLICATE->value, $types);
        $this->assertContains(QuestionPreparationEventType::REJECTED->value, $types);

        $serialized = QuestionPreparationEvent::all()->toJson();
        $this->assertStringNotContainsString('Énoncé sensible', $serialized);
        $this->assertStringNotContainsString('Motif interne', $serialized);
    }

    public function test_le_journal_est_non_assignable_et_append_only(): void
    {
        $prepared = $this->prepare();
        $this->service->declareDifficulty($prepared, $this->actor, 4);
        $event = QuestionPreparationEvent::firstOrFail();

        $this->assertSame(['*'], $event->getGuarded());

        $this->expectException(QueryException::class);
        $event->forceFill(['after' => ['difficulty' => 1]])->save();
    }

    public function test_un_evenement_de_preparation_ne_se_supprime_pas(): void
    {
        $prepared = $this->prepare();
        $this->service->declareDifficulty($prepared, $this->actor, 4);

        $this->expectException(QueryException::class);
        QuestionPreparationEvent::firstOrFail()->delete();
    }

    public function test_un_doublon_ne_peut_jamais_porter_une_question_transferee(): void
    {
        $original = $this->prepare(importRef: 'ORIGINAL');
        $duplicate = $this->prepare(importRef: 'DUPLICATE');
        $duplicate = $this->service->markDuplicate($duplicate, $this->actor, $original);

        $this->assertSame(PreparedQuestionState::DUPLICATE, $duplicate->state);
        $this->assertSame($original->uuid, $duplicate->duplicate_of_ref);
        $this->assertNull($duplicate->question_id);

        $question = Question::create([
            'exam_id' => Exam::where('code', 'CRMEF-SE-2025')->value('id'),
            'competency_node_id' => CompetencyNode::where('code', 'SE-PSY-DEV')->value('id'),
            'locale' => 'fr',
            'stem' => 'Brouillon de contrôle.',
        ]);

        $this->expectException(QueryException::class);
        $duplicate->forceFill(['question_id' => $question->id])->save();
    }

    public function test_une_ligne_transferee_n_est_pas_remplacee_en_silence(): void
    {
        $batch = $this->batch();
        $prepared = $this->service->prepare($batch, 'REF-TRANSFER', ['stem' => 'Version une']);
        $question = Question::create([
            'exam_id' => Exam::where('code', 'CRMEF-SE-2025')->value('id'),
            'competency_node_id' => CompetencyNode::where('code', 'SE-PSY-DEV')->value('id'),
            'locale' => 'fr',
            'stem' => 'Brouillon issu de la préparation.',
        ]);
        $prepared->forceFill([
            'state' => PreparedQuestionState::TRANSFERRED,
            'question_id' => $question->id,
        ])->save();

        $this->expectException(DomainException::class);
        $this->service->prepare($batch, 'REF-TRANSFER', ['stem' => 'Version deux']);
    }

    public function test_la_file_globale_ne_duplique_pas_le_catalogue_par_tenant_et_reutilise_l_empreinte(): void
    {
        $sha = hash('sha256', 'lot-identique');
        $first = $this->service->startBatch($this->actor, '/source.json', $sha, ['rows' => 10]);
        $second = $this->service->startBatch($this->actor, '/autre-chemin.json', $sha, ['rows' => 999]);

        $this->assertTrue($first->is($second));
        $this->assertSame(QuestionPreparationBatchStatus::IN_PROGRESS, $second->status);
        $this->assertSame(10, $second->counts['rows']);
        $this->assertFalse(\Schema::hasColumn('question_preparation_batches', 'tenant_id'));
        $this->assertFalse(\Schema::hasColumn('prepared_questions', 'tenant_id'));
    }

    public function test_un_acteur_hors_du_tenant_courant_ne_peut_pas_piloter_un_lot(): void
    {
        $organization = Tenant::create([
            'slug' => 'centre-q2',
            'name' => 'Centre Q2',
            'kind' => 'organization',
            'status' => 'active',
        ]);

        app(TenantContext::class)->set($organization);
        $organizationActor = User::create([
            'email' => 'centre-q2@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
        ]);
        $organizationActor->memberships()->create([
            'role_id' => Role::where('code', 'candidat')->whereNull('tenant_id')->value('id'),
        ]);

        $this->expectException(DomainException::class);
        $this->service->startBatch(
            $organizationActor,
            '/source-centre.json',
            hash('sha256', 'source-centre'),
        );
    }

    public function test_deux_lots_rejouant_la_meme_reference_reutilisent_la_ligne_active(): void
    {
        $facts = ['stem' => 'Même source', 'options' => ['A', 'B', 'C', 'D']];
        $first = $this->service->prepare($this->batch(), 'REJEU#Q1', $facts);
        $second = $this->service->prepare($this->batch(), 'REJEU#Q1', $facts);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, PreparedQuestion::where('import_ref', 'REJEU#Q1')->where('active', true)->count());
    }

    public function test_le_socle_n_expose_aucun_chemin_de_transfert(): void
    {
        $this->assertFalse(method_exists(QuestionPreparationService::class, 'transfer'));
        $this->assertFalse(method_exists(QuestionPreparationService::class, 'importCorpus'));

        $service = file_get_contents(app_path('Services/QuestionPreparationService.php'));
        $this->assertStringNotContainsString('is_correct', $service);
        $this->assertStringNotContainsString('Question::create', $service);
        $this->assertStringNotContainsString('QuestionOption', $service);
    }

    /** @param array<string, mixed> $sourceFacts */
    private function prepare(
        array $sourceFacts = [
            'statut' => 'a_saisir',
            'stem' => 'Question',
            'options' => ['Option A', 'Option B', 'Option C', 'Option D'],
        ],
        array $provisional = [],
        ?string $importRef = null,
    ): PreparedQuestion {
        static $sequence = 0;
        $sequence++;

        return $this->service->prepare(
            $this->batch(),
            $importRef ?? "REF-HELPER-{$sequence}",
            $sourceFacts,
            $provisional,
        );
    }

    private function batch(): QuestionPreparationBatch
    {
        static $sequence = 0;
        $sequence++;

        return $this->service->startBatch(
            $this->actor,
            "/tmp/source-{$sequence}.json",
            hash('sha256', "source-{$sequence}"),
        );
    }
}
