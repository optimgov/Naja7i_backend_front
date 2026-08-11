<?php

namespace Tests\Feature\Correctifs;

use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Permission;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Remediation;
use App\Models\Role;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\QuestionTransitionService;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PAS-13 — Sérialisation des invariants d'agrégat et de rôle.
 *
 * `DatabaseMigrations` et non `RefreshDatabase` : les tests à deux connexions
 * exigent des données réellement validées, sinon la seconde connexion ne voit
 * rien et le verrou porte sur une ligne inexistante. C'est la leçon du PAS-12.
 *
 * Méthode de vérification des blocants 2 et 3 : la seconde connexion ouvre une
 * transaction, écrit sans valider, et la première tente son écriture avec un
 * `lock_timeout` court. Un timeout prouve que les deux se disputent la même
 * ligne — donc qu'elles se sérialisent au lieu de s'ignorer.
 */
class SerialisationInvariantsTest extends TestCase
{
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

    protected function tearDown(): void
    {
        DB::statement("SET lock_timeout = '0'");
        parent::tearDown();
    }

    // ===================================================================
    // BLOC-1 — état retiré et déplacement de parent
    // ===================================================================

    public function test_les_options_d_une_question_retiree_sont_gelees(): void
    {
        $question = $this->questionRetiree();

        $this->expectException(QueryException::class);

        DB::statement(
            'UPDATE question_options SET is_correct = NOT is_correct WHERE question_id = ?',
            [$question->id]
        );
    }

    public function test_aucune_option_ne_se_supprime_sur_une_question_retiree(): void
    {
        $question = $this->questionRetiree();

        $this->expectException(QueryException::class);

        DB::statement('DELETE FROM question_options WHERE question_id = ?', [$question->id]);
    }

    public function test_les_sources_d_une_question_retiree_sont_gelees(): void
    {
        $question = $this->questionRetiree();

        $this->expectException(QueryException::class);

        DB::statement('DELETE FROM question_sources WHERE question_id = ?', [$question->id]);
    }

    public function test_une_option_ne_se_deplace_pas_d_une_question_publiee_vers_un_brouillon(): void
    {
        $publiee = $this->questionPubliee();
        $brouillon = $this->questionBrouillon();
        $option = $publiee->options->first();

        // Le scénario exact de la revue : NEW.question_id désignait le
        // brouillon, seul contrôlé — la question gelée perdait une option.
        $this->expectException(QueryException::class);

        DB::statement(
            'UPDATE question_options SET question_id = ? WHERE id = ?',
            [$brouillon->id, $option->id]
        );
    }

    public function test_une_source_ne_se_deplace_pas_d_une_question_publiee_vers_un_brouillon(): void
    {
        $publiee = $this->questionPubliee();
        $brouillon = $this->questionBrouillon();

        $this->expectException(QueryException::class);

        DB::statement(
            'UPDATE question_sources SET question_id = ? WHERE question_id = ?',
            [$brouillon->id, $publiee->id]
        );
    }

    public function test_une_option_ne_se_deplace_pas_d_un_brouillon_vers_une_question_publiee(): void
    {
        $publiee = $this->questionPubliee();
        $brouillon = $this->questionBrouillon();

        $this->expectException(QueryException::class);

        DB::statement(
            'UPDATE question_options SET question_id = ? WHERE id = ?',
            [$publiee->id, $brouillon->options->first()->id]
        );
    }

    public function test_les_options_restent_modifiables_entre_deux_brouillons(): void
    {
        $depart = $this->questionBrouillon();
        $arrivee = $this->questionBrouillon();

        DB::statement(
            'UPDATE question_options SET question_id = ?, position = 9 WHERE id = ?',
            [$arrivee->id, $depart->options->first()->id]
        );

        $this->assertSame(3, QuestionOption::where('question_id', $depart->id)->count());
        $this->assertSame(5, QuestionOption::where('question_id', $arrivee->id)->count());
    }

    // ===================================================================
    // BLOC-3 — publication et mutation des enfants sérialisées
    // ===================================================================

    /**
     * Une suppression d'option non validée doit bloquer la publication.
     * Sans verrou sur les enfants, la publication comptait quatre options et
     * passait ; la suppression validait ensuite, laissant une question publiée
     * à trois options.
     */
    public function test_la_publication_attend_une_mutation_d_option_en_cours(): void
    {
        $question = $this->questionValidee();

        $seconde = DB::connection('pgsql_concurrent');
        $seconde->beginTransaction();
        $seconde->table('question_options')
            ->where('question_id', $question->id)
            ->orderBy('id')
            ->limit(1)
            ->update(['content' => 'Modifiée hors transaction']);

        DB::statement("SET lock_timeout = '500ms'");

        try {
            DB::statement(
                "UPDATE questions SET status = 'published', published_at = now(), eligible_for_diagnostic = true WHERE id = ?",
                [$question->id]
            );
            $this->fail('La publication n\'a pas réclamé le verrou des options.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('lock timeout', strtolower($e->getMessage()));
        } finally {
            DB::statement("SET lock_timeout = '0'");
            $seconde->rollBack();
        }

        $this->assertSame('pedagogically_validated', Question::find($question->id)->status);
    }

    /**
     * Symétrique : une publication en cours doit bloquer la mutation d'une
     * option, puisque le trigger enfant verrouille désormais le parent.
     */
    public function test_une_mutation_d_option_attend_une_publication_en_cours(): void
    {
        $question = $this->questionValidee();

        $seconde = DB::connection('pgsql_concurrent');
        $seconde->beginTransaction();
        $seconde->statement("SET lock_timeout = '0'");
        $seconde->table('questions')->where('id', $question->id)->lockForUpdate()->get();

        DB::statement("SET lock_timeout = '500ms'");

        try {
            DB::statement(
                'UPDATE question_options SET content = ? WHERE question_id = ?',
                ['Modifiée', $question->id]
            );
            $this->fail('Le trigger enfant n\'a pas réclamé le verrou du parent.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('lock timeout', strtolower($e->getMessage()));
        } finally {
            DB::statement("SET lock_timeout = '0'");
            $seconde->rollBack();
        }
    }

    /**
     * Le verrou du contrôle de publication, ISOLÉ — ce test discrimine, celui
     * ci-dessus non.
     *
     * Vérifié par mutation : en retirant les deux `PERFORM ... FOR UPDATE` du
     * contrôle de publication, `test_la_publication_attend_une_mutation_d_option_en_cours`
     * reste vert. Son attente ne vient pas du contrôle de publication mais de
     * la garde enfant : modifier une option verrouille déjà la ligne `questions`
     * (`statut_question_verrouille`), et c'est ce verrou-là que la publication
     * rencontre. Il mesure donc la garde 1 une seconde fois.
     *
     * Ici la seconde connexion verrouille les OPTIONS sans passer par le
     * trigger — un `SELECT ... FOR UPDATE` n'en déclenche aucun — donc sans
     * verrouiller la question. Seul le rendez-vous du contrôle de publication
     * peut encore faire attendre.
     */
    public function test_le_controle_de_publication_reclame_le_verrou_des_enfants(): void
    {
        $question = $this->questionValidee();

        $seconde = DB::connection('pgsql_concurrent');
        $seconde->beginTransaction();
        $seconde->select(
            'SELECT id FROM question_options WHERE question_id = ? FOR UPDATE',
            [$question->id]
        );

        DB::statement("SET lock_timeout = '500ms'");

        try {
            DB::statement(
                "UPDATE questions SET status = 'published', published_at = now(), eligible_for_diagnostic = true WHERE id = ?",
                [$question->id]
            );
            $this->fail('La publication a traversé sans réclamer le verrou de ses options.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('lock timeout', strtolower($e->getMessage()));
        } finally {
            DB::statement("SET lock_timeout = '0'");
            $seconde->rollBack();
        }

        $this->assertSame('pedagogically_validated', Question::find($question->id)->status);
    }

    public function test_la_publication_reste_possible_sans_concurrence(): void
    {
        $question = $this->questionValidee();

        $publiee = app(QuestionTransitionService::class)->publish($question, forDiagnostic: true);

        $this->assertSame('published', $publiee->status);
    }

    // ===================================================================
    // BLOC-2 — invariant rôle / permission sérialisé
    // ===================================================================

    /**
     * Le scénario exact de la revue : T1 attribue le rôle global dans un
     * organisme sans valider, T2 tente d'y attacher une permission réservée.
     *
     * Les deux triggers verrouillent désormais la même ligne `roles` : la
     * seconde attend, au lieu de lire un état périmé et d'accepter.
     */
    public function test_attacher_une_permission_reservee_attend_une_attribution_en_cours(): void
    {
        $candidat = Role::where('code', 'candidat')->whereNull('tenant_id')->firstOrFail();

        $seconde = DB::connection('pgsql_concurrent');
        $seconde->beginTransaction();
        $seconde->table('memberships')->insert([
            // L'insertion brute court-circuite HasPublicUuid : l'UUID public
            // est fourni à la main, la colonne étant NOT NULL (ADR-0002).
            'uuid' => (string) Str::uuid7(),
            'tenant_id' => $this->organisme->id,
            'user_id' => $this->auteur->id,
            'role_id' => $candidat->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::statement("SET lock_timeout = '500ms'");

        try {
            DB::table('permission_role')->insert([
                'permission_id' => Permission::where('code', 'tenants.manage')->value('id'),
                'role_id' => $candidat->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('L\'attachement n\'a pas réclamé le verrou du rôle.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('lock timeout', strtolower($e->getMessage()));
        } finally {
            DB::statement("SET lock_timeout = '0'");
            $seconde->rollBack();
        }
    }

    /** Symétrique : l'attribution attend un attachement en cours. */
    public function test_une_attribution_attend_un_attachement_de_permission_en_cours(): void
    {
        $candidat = Role::where('code', 'candidat')->whereNull('tenant_id')->firstOrFail();

        $seconde = DB::connection('pgsql_concurrent');
        $seconde->beginTransaction();
        $seconde->statement("SET lock_timeout = '0'");
        $seconde->table('roles')->where('id', $candidat->id)->lockForUpdate()->get();

        DB::statement("SET lock_timeout = '500ms'");

        try {
            DB::table('memberships')->insert([
                'tenant_id' => $this->organisme->id,
                'user_id' => $this->auteur->id,
                'role_id' => $candidat->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('L\'attribution n\'a pas réclamé le verrou du rôle.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('lock timeout', strtolower($e->getMessage()));
        } finally {
            DB::statement("SET lock_timeout = '0'");
            $seconde->rollBack();
        }
    }

    /**
     * Le verrou de l'attachement de permission, ISOLÉ — celui-ci discrimine.
     *
     * Vérifié par mutation : en retirant le `FOR UPDATE` de
     * `assert_permission_scope`,
     * `test_attacher_une_permission_reservee_attend_une_attribution_en_cours`
     * reste vert. Son attente ne vient pas du trigger mais d'une CLÉ ÉTRANGÈRE :
     * insérer dans `permission_role` prend un verrou `FOR KEY SHARE` sur la
     * ligne `roles`, incompatible avec le `FOR UPDATE` que détient le trigger
     * d'appartenance. L'insertion attendrait donc même sans rendez-vous.
     *
     * `FOR NO KEY UPDATE` sépare les deux : il entre en conflit avec le
     * `FOR UPDATE` du trigger, mais reste compatible avec le `FOR KEY SHARE`
     * de la clé étrangère. Ce qui attend ici ne peut être que le rendez-vous.
     */
    public function test_l_attachement_de_permission_reclame_le_verrou_du_role(): void
    {
        $candidat = Role::where('code', 'candidat')->whereNull('tenant_id')->firstOrFail();

        $seconde = DB::connection('pgsql_concurrent');
        $seconde->beginTransaction();
        $seconde->select('SELECT id FROM roles WHERE id = ? FOR NO KEY UPDATE', [$candidat->id]);

        DB::statement("SET lock_timeout = '500ms'");

        try {
            DB::table('permission_role')->insert([
                'permission_id' => Permission::where('code', 'tenants.manage')->value('id'),
                'role_id' => $candidat->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('L\'attachement a traversé sans réclamer le verrou du rôle.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('lock timeout', strtolower($e->getMessage()));
        } finally {
            DB::statement("SET lock_timeout = '0'");
            $seconde->rollBack();
        }
    }

    public function test_l_attribution_ordinaire_reste_possible(): void
    {
        $candidat = Role::where('code', 'candidat')->whereNull('tenant_id')->firstOrFail();

        app(TenantContext::class)->set($this->organisme);
        $membership = $this->auteur->memberships()->create(['role_id' => $candidat->id]);

        $this->assertNotNull($membership->id);
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
        ] as $p => [$c, $juste, $justif, $cause]) {
            QuestionOption::create([
                'question_id' => $question->id, 'position' => $p + 1,
                'content' => $c, 'is_correct' => $juste, 'rationale' => $justif, 'cause' => $cause,
            ]);
        }

        return $question->fresh('options');
    }

    private function questionValidee(): Question
    {
        $question = $this->questionBrouillon();
        $question->contentSources()->attach($this->source->id, ['verification' => 'verified']);

        $service = app(QuestionTransitionService::class);
        $service->submitForReview($question);
        $service->markReviewed($question->fresh(), $this->valideur);
        $service->validate($question->fresh(), $this->valideur);

        return $question->fresh('options');
    }

    private function questionPubliee(): Question
    {
        return app(QuestionTransitionService::class)
            ->publish($this->questionValidee(), forDiagnostic: true)
            ->load('options');
    }

    private function questionRetiree(): Question
    {
        return app(QuestionTransitionService::class)
            ->retire($this->questionPubliee())
            ->load('options');
    }
}
