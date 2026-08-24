<?php

namespace Tests\Feature\Correctifs;

use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Permission;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Remediation;
use App\Models\Response;
use App\Models\Role;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\PermissionResolver;
use App\Services\QuestionTransitionService;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * PAS-12 — Correctifs de la contre-revue PAS-11.
 *
 * Le BLOC-1 exigeait un entrelacement réel, pas une séquence. Ces tests
 * ouvrent donc une SECONDE CONNEXION PostgreSQL et imposent l'ordre :
 * A lit → B verrouille et clôt → A tente d'écrire.
 *
 * C'est la leçon du lot précédent : un test séquentiel ne prouve rien sur une
 * course. Il vérifie l'état final, jamais l'entrelacement qui le produit.
 */
class CorrectifsContreRevueTest extends TestCase
{
    /**
     * `DatabaseMigrations`, et non `RefreshDatabase` : ces tests prouvent des
     * COURSES, ce qui exige deux sessions PostgreSQL réelles.
     *
     * `RefreshDatabase` enveloppe chaque test dans une transaction qui n'est
     * jamais validée. La seconde connexion ne voit donc pas la tentative créée
     * par le fixture : son `lockForUpdate` porte sur une ligne inexistante,
     * aucun verrou n'est détenu, et le test conclut à tort que `answer()`
     * n'attend pas. Un test de concurrence qui ne peut pas voir la donnée
     * disputée ne teste rien.
     *
     * Le prix est une migration complète par test. Il est assumé : c'est la
     * seule stratégie où l'entrelacement vérifié est le vrai.
     */
    use DatabaseMigrations;

    private Tenant $plateforme;

    private Tenant $organisme;

    private Exam $epreuve;

    private Source $source;

    private User $auteur;

    private User $valideur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plateforme = Tenant::where('kind', 'platform')->firstOrFail();
        $this->organisme = Tenant::create(['slug' => 'centre-fes', 'name' => 'Centre de Fès']);

        app(TenantContext::class)->set($this->plateforme);

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->source = Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail();
        $this->auteur = $this->utilisateur('auteur@naja7i.ma');
        $this->valideur = $this->utilisateur('valideur@naja7i.ma');
    }

    // ===================================================================
    // BLOC-1 — la course réponse / soumission
    // ===================================================================

    /**
     * L'entrelacement exact décrit par la revue, imposé par un verrou détenu
     * sur une seconde connexion.
     *
     * `answer()` doit désormais réclamer le verrou de la TENTATIVE avant
     * d'écrire. Tant que la seconde connexion le détient, il attend — et le
     * délai d'attente expire. C'est la preuve que le verrou est réclamé :
     * l'ancienne implémentation ne le demandait pas et écrivait aussitôt.
     */
    public function test_answer_reclame_le_verrou_de_la_tentative(): void
    {
        $item = $this->itemDeTentative();

        $seconde = DB::connection('pgsql_concurrent');
        $seconde->beginTransaction();
        $seconde->table('attempts')->where('id', $item->attempt_id)->lockForUpdate()->get();

        DB::statement("SET lock_timeout = '400ms'");

        try {
            app(AttemptService::class)->answer($item, $item->question->options->first(), 'sure');
            $this->fail('answer() a écrit sans attendre le verrou de la tentative.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('lock timeout', strtolower($e->getMessage()));
        } finally {
            DB::statement("SET lock_timeout = '0'");
            $seconde->rollBack();
        }

        $this->assertSame(0, Response::where('attempt_item_id', $item->id)->count());
    }

    /**
     * Le même verrou, mais isolé — ce test DISCRIMINE, le précédent non.
     *
     * Le test ci-dessus voit bien un « lock timeout », mais pas forcément
     * celui qu'il croit : `answer()` finit par incrémenter `answered_count` sur
     * la tentative, et cette écriture tardive bute elle aussi sur le verrou de
     * la seconde connexion. Vérifié par mutation : en retirant le
     * `lockForUpdate()` de la ligne 1, il reste vert.
     *
     * Ici, la réponse existe déjà et `presented_at` est posé : plus aucune
     * écriture sur `attempts` ni sur `attempt_items` ne suit. Si `answer()`
     * n'exige pas le verrou de la tentative AVANT de relire son état, il
     * traverse sans attendre et le test échoue. C'est la seule formulation où
     * l'attente prouve la prise de verrou.
     */
    public function test_le_verrou_est_reclame_avant_toute_lecture_d_etat(): void
    {
        $item = $this->itemDeTentative();

        // Une première réponse, écrite normalement : plus rien à incrémenter.
        app(AttemptService::class)->answer($item, $item->question->options->first(), 'sure');

        $this->assertNotNull($item->fresh()->presented_at);
        $this->assertSame(1, Response::where('attempt_item_id', $item->id)->count());

        $seconde = DB::connection('pgsql_concurrent');
        $seconde->beginTransaction();
        $seconde->table('attempts')->where('id', $item->attempt_id)->lockForUpdate()->get();

        DB::statement("SET lock_timeout = '400ms'");

        try {
            app(AttemptService::class)->answer($item->fresh(), $item->question->options->last(), 'guess');
            $this->fail('answer() a relu l\'état de la tentative sans réclamer son verrou.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('lock timeout', strtolower($e->getMessage()));
        } finally {
            DB::statement("SET lock_timeout = '0'");
            $seconde->rollBack();
        }
    }

    /**
     * L'entrelacement complet : A détient une instance lue avant clôture,
     * B soumet, A tente d'écrire ensuite. La relecture sous verrou doit
     * refuser.
     */
    public function test_une_reponse_ecrite_apres_une_soumission_concurrente_est_refusee(): void
    {
        $item = $this->itemDeTentative();
        $service = app(AttemptService::class);

        // A a chargé l'item et sa tentative, encore ouverte.
        $instanceA = AttemptItem::with('attempt')->find($item->id);
        $this->assertSame('in_progress', $instanceA->attempt->status);

        // B soumet, sur une autre connexion : A ne le sait pas.
        $seconde = DB::connection('pgsql_concurrent');
        $seconde->table('attempts')->where('id', $item->attempt_id)->update([
            'status' => 'submitted',
            'submitted_at' => now(),
            'correct_count' => 0,
        ]);

        // A écrit : la relecture sous verrou doit constater la clôture.
        $this->expectException(RuntimeException::class);
        $service->answer($instanceA, $instanceA->question->options->first(), 'sure');
    }

    public function test_aucune_reponse_ne_subsiste_apres_un_refus(): void
    {
        $item = $this->itemDeTentative();

        DB::connection('pgsql_concurrent')->table('attempts')
            ->where('id', $item->attempt_id)
            ->update(['status' => 'submitted', 'submitted_at' => now(), 'correct_count' => 0]);

        try {
            app(AttemptService::class)->answer($item, $item->question->options->first(), 'sure');
        } catch (RuntimeException) {
            // attendu
        }

        $this->assertSame(0, Response::where('attempt_item_id', $item->id)->count());
        $this->assertSame(0, Attempt::find($item->attempt_id)->answered_count);
    }

    public function test_la_soumission_reste_idempotente(): void
    {
        $item = $this->itemDeTentative();
        $service = app(AttemptService::class);

        $service->answer($item, $item->question->correctOption(), 'sure');

        $premier = $service->submit($item->attempt->fresh());
        $second = $service->submit($premier);

        $this->assertSame($premier->submitted_at->timestamp, $second->submitted_at->timestamp);
        $this->assertSame(1, $second->correct_count);
    }

    // ===================================================================
    // BLOC-2 — permission réservée attachée après l'attribution du rôle
    // ===================================================================

    public function test_une_permission_reservee_ne_s_attache_pas_a_un_role_deja_attribue_hors_plateforme(): void
    {
        $candidat = Role::where('code', 'candidat')->whereNull('tenant_id')->firstOrFail();

        app(TenantContext::class)->set($this->organisme);
        $this->auteur->memberships()->create(['role_id' => $candidat->id]);

        // Le scénario exact de la revue : attacher APRÈS l'attribution.
        $this->expectException(QueryException::class);

        $candidat->permissions()->attach(Permission::where('code', 'tenants.manage')->value('id'));
    }

    public function test_le_resolveur_n_accorde_jamais_une_permission_reservee_hors_plateforme(): void
    {
        $candidat = Role::where('code', 'candidat')->whereNull('tenant_id')->firstOrFail();

        /* L'attribution vient EN PREMIER, tant que le rôle est encore sain :
         * la garde d'appartenance refuserait un rôle portant déjà une
         * permission réservée, et le montage ne pourrait pas exister. */
        app(TenantContext::class)->set($this->organisme);
        $this->auteur->memberships()->create(['role_id' => $candidat->id]);

        /* On corrompt ENSUITE, en désactivant réellement les triggers.
         *
         * Un `DB::table()->insert()` n'en contourne aucun — c'est du SQL
         * ordinaire, et la garde d'attachement le refuserait. Or l'objet de ce
         * test est justement la SECONDE barrière : que vaut le résolveur si la
         * première a été franchie, par une migration maladroite ou une
         * restauration de sauvegarde ? Pour le savoir, il faut produire un état
         * que la base seule n'accepterait jamais. */
        DB::statement('ALTER TABLE permission_role DISABLE TRIGGER USER');

        try {
            DB::table('permission_role')->insert([
                'permission_id' => Permission::where('code', 'tenants.manage')->value('id'),
                'role_id' => $candidat->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } finally {
            DB::statement('ALTER TABLE permission_role ENABLE TRIGGER USER');
        }

        $resolveur = app(PermissionResolver::class);
        $resolveur->forget();

        $this->assertFalse(
            $resolveur->has($this->auteur, 'tenants.manage'),
            'Même si la table est corrompue, aucune permission réservée hors plateforme.'
        );
    }

    public function test_une_permission_reservee_reste_attachable_a_un_role_de_plateforme_pur(): void
    {
        $editeur = Role::where('code', 'expert_pedagogue')->whereNull('tenant_id')->firstOrFail();

        app(TenantContext::class)->set($this->plateforme);
        $this->auteur->memberships()->create(['role_id' => $editeur->id]);

        $editeur->permissions()->syncWithoutDetaching(
            [Permission::where('code', 'tenants.manage')->value('id')]
        );

        $this->assertTrue($editeur->permissions()->where('code', 'tenants.manage')->exists());
    }

    // ===================================================================
    // BLOC-3 — sortie de l'état publié
    // ===================================================================

    #[DataProvider('destinationsInterdites')]
    public function test_une_question_publiee_ne_repasse_pas_dans_un_etat_modifiable(string $destination): void
    {
        $question = $this->questionPubliee();

        $this->expectException(QueryException::class);

        DB::statement(
            'UPDATE questions SET status = ?::question_status WHERE id = ?',
            [$destination, $question->id]
        );
    }

    /** @return array<string, array{0: string}> */
    public static function destinationsInterdites(): array
    {
        return [
            'draft' => ['draft'],
            'a_verifier' => ['a_verifier'],
            'reviewed' => ['reviewed'],
            'pedagogically_validated' => ['pedagogically_validated'],
        ];
    }

    public function test_le_retrait_reste_la_seule_sortie(): void
    {
        $question = $this->questionPubliee();

        $retiree = app(QuestionTransitionService::class)->retire($question);

        $this->assertSame('retired', $retiree->status);
        $this->assertNotNull($retiree->retired_at);
    }

    public function test_un_retrait_sans_horodatage_est_refuse(): void
    {
        $question = $this->questionPubliee();

        $this->expectException(QueryException::class);

        DB::statement("UPDATE questions SET status = 'retired' WHERE id = ?", [$question->id]);
    }

    public function test_une_question_retiree_ne_se_reactive_pas(): void
    {
        $question = app(QuestionTransitionService::class)->retire($this->questionPubliee());

        $this->expectException(QueryException::class);

        DB::statement("UPDATE questions SET status = 'draft' WHERE id = ?", [$question->id]);
    }

    public function test_le_contenu_d_une_question_retiree_reste_gele(): void
    {
        $question = app(QuestionTransitionService::class)->retire($this->questionPubliee());

        $this->expectException(QueryException::class);

        DB::statement("UPDATE questions SET stem = 'Réécrit après retrait' WHERE id = ?", [$question->id]);
    }

    // ===================================================================
    // BLOC-4 — options et sources d'une question publiée
    // ===================================================================

    public function test_les_options_d_une_question_publiee_restent_gelees(): void
    {
        // Vérification que la garde du PAS-10 est toujours active : la
        // contre-revue la croyait absente.
        $question = $this->questionPubliee();

        $this->expectException(QueryException::class);

        DB::statement(
            'UPDATE question_options SET is_correct = NOT is_correct WHERE question_id = ?',
            [$question->id]
        );
    }

    public function test_la_source_verifiee_ne_peut_pas_etre_retiree_apres_publication(): void
    {
        $question = $this->questionPubliee();

        // C'est le volet fondé du BLOC-4 : cette source conditionnait la
        // publication. La retirer invaliderait rétroactivement le contrôle.
        $this->expectException(QueryException::class);

        DB::statement('DELETE FROM question_sources WHERE question_id = ?', [$question->id]);
    }

    public function test_le_statut_de_verification_d_une_source_est_gele(): void
    {
        $question = $this->questionPubliee();

        $this->expectException(QueryException::class);

        DB::statement(
            "UPDATE question_sources SET verification = 'disputed' WHERE question_id = ?",
            [$question->id]
        );
    }

    public function test_aucune_source_ne_s_ajoute_apres_publication(): void
    {
        $question = $this->questionPubliee();
        $autre = Source::where('code', 'SRC-CRMEF-2025-FR-DID')->firstOrFail();

        $this->expectException(QueryException::class);

        DB::table('question_sources')->insert([
            'question_id' => $question->id, 'source_id' => $autre->id,
            'verification' => 'verified', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_les_sources_restent_modifiables_avant_publication(): void
    {
        $question = $this->questionBrouillon();

        $question->contentSources()->attach($this->source->id, ['verification' => 'unverified']);
        $question->contentSources()->updateExistingPivot($this->source->id, ['verification' => 'verified']);

        $this->assertTrue($question->fresh()->hasVerifiedContentSource());
    }

    // ===================================================================
    // Utilitaires
    // ===================================================================

    private function utilisateur(string $email): User
    {
        $user = User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $user->markEmailAsVerified();

        return $user->fresh();
    }

    private function questionBrouillon(): Question
    {
        $noeud = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();

        $remediation = Remediation::firstOrCreate(
            ['competency_node_id' => $noeud->id, 'locale' => 'fr'],
            ['title' => 'Remédiation', 'content' => 'Contenu.', 'estimated_minutes' => 8, 'status' => 'published']
        );

        $question = Question::create([
            'exam_id' => $this->epreuve->id, 'competency_node_id' => $noeud->id,
            'locale' => 'fr', 'sibling_group' => (string) Str::uuid7(),
            'stem' => 'Énoncé '.Str::random(5).' ?', 'explanation' => 'Justification.',
            'remediation_id' => $remediation->id, 'author_id' => $this->auteur->id,
        ]);

        foreach ([
            ['A', false, 'A est fausse.', 'confusion_notions'],
            ['B', true,  'B est juste.',  null],
            ['C', false, 'C est fausse.', 'lecture_enonce'],
            ['D', false, 'D est fausse.', 'connaissance_absente'],
            ['Aucune des propositions précédentes', false, 'Elle est fausse puisqu’une autre proposition est correcte.', 'indetermine'],
        ] as $p => [$c, $juste, $justif, $cause]) {
            QuestionOption::create([
                'question_id' => $question->id, 'position' => $p + 1,
                'content' => $c, 'is_correct' => $juste, 'rationale' => $justif, 'cause' => $cause,
            ]);
        }

        return $question->fresh('options');
    }

    private function questionPubliee(): Question
    {
        $question = $this->questionBrouillon();
        $question->contentSources()->attach($this->source->id, ['verification' => 'verified']);

        $service = app(QuestionTransitionService::class);
        $service->submitForReview($question);
        $service->markReviewed($question->fresh(), $this->relecteurDeControle());
        $service->validate($question->fresh(), $this->valideur);

        return $service->publish($question->fresh(), forDiagnostic: true)->load('options');
    }

    private function itemDeTentative(): AttemptItem
    {
        $candidat = $this->utilisateur('candidat-'.Str::random(5).'@naja7i.ma');

        app(TenantContext::class)->set($this->plateforme);
        $candidat->grantCandidateRole();

        $question = $this->questionPubliee();

        $attempt = Attempt::create([
            'user_id' => $candidat->id, 'exam_id' => $this->epreuve->id,
            'locale' => 'fr', 'idempotency_key' => (string) Str::uuid7(),
            'kind' => 'training', 'status' => 'in_progress',
            'started_at' => now(), 'item_count' => 1,
        ]);

        return AttemptItem::create([
            'attempt_id' => $attempt->id, 'question_id' => $question->id,
            'competency_node_id' => $question->competency_node_id, 'position' => 1,
        ])->fresh(['attempt', 'question.options']);
    }
}
