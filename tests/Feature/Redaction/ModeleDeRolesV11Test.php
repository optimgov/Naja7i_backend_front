<?php

namespace Tests\Feature\Redaction;

use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Permission;
use App\Models\Question;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AccountAdministrationService;
use App\Services\PermissionResolver;
use App\Services\QuestionTransitionService;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ModeleDeRolesV11Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());
    }

    public function test_la_migration_reprend_les_anciennes_appartenances_sans_doublon_et_sans_effacer_l_historique(): void
    {
        $user = $this->user('migration-profils@naja7i.ma');
        $expertExistant = Role::whereNull('tenant_id')->where('code', 'expert_pedagogue')->firstOrFail();
        DB::table('permission_role')->where('role_id', $expertExistant->id)->delete();
        $expertExistant->delete();

        $anciens = Role::whereNull('tenant_id')->whereIn('code', ['auteur', 'reviseur', 'editeur'])->get();

        Role::whereNull('tenant_id')->whereIn('code', ['auteur', 'reviseur', 'editeur'])
            ->update(['is_active' => true]);

        foreach ($anciens as $role) {
            $user->memberships()->create(['role_id' => $role->id]);
        }

        $migration = require database_path('migrations/0001_01_01_000810_unifier_les_profils_editoriaux.php');
        $migration->up();
        $migration->up();

        $codes = $user->memberships()->with('role')->get()->pluck('role.code');

        $this->assertSame(1, Role::whereNull('tenant_id')->where('code', 'expert_pedagogue')->count());
        $this->assertCount(4, $codes);
        $this->assertEqualsCanonicalizing(
            ['auteur', 'reviseur', 'editeur', 'expert_pedagogue'],
            $codes->all(),
        );
        $this->assertSame(
            3,
            Role::whereNull('tenant_id')
                ->whereIn('code', ['auteur', 'reviseur', 'editeur'])
                ->where('is_active', false)
                ->count(),
        );
    }

    public function test_les_anciens_roles_ne_sont_plus_attribuables_et_ne_donnent_plus_de_permissions(): void
    {
        $admin = $this->membre('admin-roles@naja7i.ma', 'super_admin');
        $ancien = $this->user('ancien-auteur@naja7i.ma');
        $ancien->memberships()->create([
            'role_id' => Role::whereNull('tenant_id')->where('code', 'auteur')->value('id'),
        ]);

        $attribuables = app(AccountAdministrationService::class)
            ->assignableRoles($admin, staffOnly: true)
            ->pluck('code');

        $this->assertTrue($attribuables->contains('expert_pedagogue'));
        $this->assertFalse($attribuables->intersect(['auteur', 'reviseur', 'editeur'])->isNotEmpty());
        $this->assertSame([], app(PermissionResolver::class)->forUser($ancien));
    }

    public function test_un_expert_peut_accomplir_seul_toutes_les_transitions_et_chaque_acteur_reste_trace(): void
    {
        $expert = $this->membre('expert-seul@naja7i.ma', 'expert_pedagogue');
        $question = Question::create([
            'exam_id' => Exam::where('code', 'CRMEF-SE-2025')->value('id'),
            'competency_node_id' => CompetencyNode::where('code', 'SE-PSY-DEV')->value('id'),
            'locale' => 'fr',
            'stem' => 'Une question éditoriale complète ?',
            'explanation' => 'Oui, ses étapes restent toutes tracées.',
            'author_id' => $expert->id,
        ]);

        foreach ([
            ['A', true],
            ['B', false],
            ['C', false],
            ['D', false],
            ['E', false],
        ] as $position => [$contenu, $correcte]) {
            $question->options()->create([
                'position' => $position + 1,
                'content' => $contenu,
                'is_correct' => $correcte,
                'rationale' => 'Justification présente.',
            ]);
        }

        $service = app(QuestionTransitionService::class);
        $service->submitForReview($question);
        $service->markReviewed($question->fresh(), $expert);
        $service->validate($question->fresh(), $expert);
        $publiee = $service->publish($question->fresh());

        $this->assertSame('published', $publiee->status);
        $this->assertSame($expert->id, $publiee->author_id);
        $this->assertSame($expert->id, $publiee->reviewer_id);
        $this->assertSame($expert->id, $publiee->validator_id);
    }

    public function test_l_expert_recoit_exactement_l_allowlist_editoriale(): void
    {
        $expert = $this->membre('expert-permissions@naja7i.ma', 'expert_pedagogue');
        $permissions = app(PermissionResolver::class)->forUser($expert);

        $attendues = [
            'questions.view', 'questions.create', 'questions.review',
            'questions.validate', 'questions.publish', 'questions.retire',
            'questions.difficulty', 'catalogue.view', 'catalogue.manage',
            'taxonomy.manage',
        ];

        $this->assertEqualsCanonicalizing($attendues, $permissions);

        foreach ([
            'quotas.manage', 'plans.editorial_fix', 'orders.view',
            'users.support', 'members.view', 'tenants.manage',
        ] as $horsPerimetre) {
            $this->assertNotContains($horsPerimetre, $permissions);
        }
    }

    public function test_la_migration_editoriale_ne_modifie_pas_le_role_support_deja_resserre(): void
    {
        $support = Role::whereNull('tenant_id')->where('code', 'support')->firstOrFail();
        $avant = $support->permissions()->orderBy('code')->pluck('code')->all();

        $migration = require database_path('migrations/0001_01_01_000810_unifier_les_profils_editoriaux.php');
        $migration->up();

        $this->assertSame($avant, $support->fresh()->permissions()->orderBy('code')->pluck('code')->all());
        $this->assertEqualsCanonicalizing(['complaints.view', 'complaints.reply'], $avant);
    }

    public function test_le_super_admin_conserve_toutes_les_permissions_actuelles(): void
    {
        $admin = $this->membre('admin-toutes-permissions@naja7i.ma', 'super_admin');

        $this->assertSame(
            Permission::count(),
            count(app(PermissionResolver::class)->forUser($admin)),
        );
    }

    public function test_une_question_ne_peut_jamais_etre_supprimee_definitivement(): void
    {
        $question = Question::create([
            'exam_id' => Exam::where('code', 'CRMEF-SE-2025')->value('id'),
            'competency_node_id' => CompetencyNode::where('code', 'SE-PSY-DEV')->value('id'),
            'locale' => 'fr',
            'stem' => 'Cette trace doit-elle rester ?',
            'explanation' => 'Oui, le retrait porte son cycle de vie.',
        ]);

        try {
            DB::transaction(fn () => $question->delete());
            $this->fail('Une Question ne doit avoir aucun chemin de suppression définitive.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('se retire', $exception->getMessage());
        }

        $this->assertDatabaseHas('questions', ['id' => $question->id]);
    }

    public function test_la_garde_sql_refuse_une_justification_nulle(): void
    {
        $expert = $this->membre('expert-rationale@naja7i.ma', 'expert_pedagogue');
        $question = Question::create([
            'exam_id' => Exam::where('code', 'CRMEF-SE-2025')->value('id'),
            'competency_node_id' => CompetencyNode::where('code', 'SE-PSY-DEV')->value('id'),
            'locale' => 'fr',
            'stem' => 'La garde SQL voit-elle une justification nulle ?',
            'explanation' => 'Oui.',
            'author_id' => $expert->id,
        ]);

        foreach (range(1, 5) as $position) {
            $question->options()->create([
                'position' => $position,
                'content' => 'Option '.$position,
                'is_correct' => $position === 1,
                'rationale' => $position === 5 ? null : 'Justification présente.',
            ]);
        }

        $service = app(QuestionTransitionService::class);
        $service->submitForReview($question);
        $service->markReviewed($question->fresh(), $expert);
        $service->validate($question->fresh(), $expert);

        try {
            DB::transaction(fn () => DB::table('questions')->where('id', $question->id)->update([
                'status' => 'published',
                'published_at' => now(),
            ]));
            $this->fail('La garde PostgreSQL doit compter rationale IS NULL comme justification absente.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('sans justification', $exception->getMessage());
        }

        $this->assertSame('pedagogically_validated', $question->fresh()->status);
    }

    public function test_le_down_retablit_le_modele_precedent(): void
    {
        $migration = require database_path('migrations/0001_01_01_000810_unifier_les_profils_editoriaux.php');
        $migration->down();

        $this->assertFalse(Schema::hasColumn('roles', 'is_active'));
        $this->assertDatabaseMissing('roles', ['code' => 'expert_pedagogue']);
        $this->assertSame(
            3,
            Role::whereNull('tenant_id')->whereIn('code', ['auteur', 'reviseur', 'editeur'])->count(),
        );
    }

    private function user(string $email): User
    {
        $user = User::create([
            'email' => $email,
            'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
            'status' => 'active',
        ]);
        $user->markEmailAsVerified();

        return $user;
    }

    private function membre(string $email, string $roleCode): User
    {
        $user = $this->user($email);
        $user->memberships()->create([
            'role_id' => Role::whereNull('tenant_id')->where('code', $roleCode)->value('id'),
        ]);

        return $user;
    }
}
