<?php

namespace Tests\Feature\Abonnement;

use App\Contracts\AccessGrant;
use App\Models\AccessGrantRecord;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PorteesTypeesDesDroitsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'email' => 'portees@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
        ]);
    }

    #[DataProvider('couplesIncomplets')]
    public function test_la_base_refuse_les_deux_formes_de_couple_mi_nul(
        ?string $type,
        ?string $uuid,
    ): void {
        $this->expectException(QueryException::class);

        $this->grant('mastery.detail', $type, $uuid);
    }

    /** @return array<string, array{string|null, string|null}> */
    public static function couplesIncomplets(): array
    {
        return [
            'type sans uuid' => [AccessGrantRecord::SCOPE_EXAM, null],
            'uuid sans type' => [null, '0198d7c1-b454-718d-8481-a0a98ccd6231'],
        ];
    }

    #[DataProvider('typesInterdits')]
    public function test_la_base_refuse_un_type_hors_du_registre_et_specialty(string $type): void
    {
        $this->expectException(QueryException::class);

        $this->grant('mastery.detail', $type, (string) Str::uuid7());
    }

    /** @return array<string, array{string}> */
    public static function typesInterdits(): array
    {
        return [
            'specialty' => ['specialty'],
            'track' => ['track'],
            'matiere' => ['matiere'],
            'chapitre' => ['chapitre'],
        ];
    }

    public function test_un_droit_global_couvre_un_noeud_profond(): void
    {
        $node = CompetencyNode::whereNotNull('parent_id')->orderByDesc('depth')->firstOrFail();
        $this->grant('questions.answer');

        $this->assertTrue($this->access()->allows(
            $this->user,
            'questions.answer',
            AccessGrantRecord::SCOPE_COMPETENCY_NODE,
            $node->uuid,
        ));
    }

    public function test_une_epreuve_couvre_ses_noeuds_et_pas_une_autre_epreuve(): void
    {
        $exam = Exam::whereHas('competencyNodes')->firstOrFail();
        $ownNode = CompetencyNode::where('exam_id', $exam->id)->firstOrFail();
        $otherNode = CompetencyNode::whereNotNull('exam_id')
            ->where('exam_id', '<>', $exam->id)
            ->firstOrFail();

        $this->grant('mastery.detail', AccessGrantRecord::SCOPE_EXAM, $exam->uuid);

        $this->assertTrue($this->allowsNode('mastery.detail', $ownNode));
        $this->assertFalse($this->allowsNode('mastery.detail', $otherNode));
    }

    public function test_une_matiere_couvre_son_chapitre_jamais_sa_soeur_ni_son_parent(): void
    {
        $matter = CompetencyNode::whereNull('parent_id')->whereHas('children')->firstOrFail();
        $chapter = $matter->children()->firstOrFail();
        $sibling = CompetencyNode::whereNull('parent_id')
            ->where('id', '<>', $matter->id)
            ->firstOrFail();

        $this->grant(
            'mastery.detail',
            AccessGrantRecord::SCOPE_COMPETENCY_NODE,
            $matter->uuid,
        );

        $this->assertTrue($this->allowsNode('mastery.detail', $matter));
        $this->assertTrue($this->allowsNode('mastery.detail', $chapter));
        $this->assertFalse($this->allowsNode('mastery.detail', $sibling));

        AccessGrantRecord::query()->delete();
        $this->grant(
            'remediation.plan',
            AccessGrantRecord::SCOPE_COMPETENCY_NODE,
            $chapter->uuid,
        );

        $this->assertTrue($this->allowsNode('remediation.plan', $chapter));
        $this->assertFalse($this->allowsNode('remediation.plan', $matter));
    }

    public function test_la_resolution_profonde_interroge_les_droits_une_seule_fois(): void
    {
        $node = CompetencyNode::whereNotNull('parent_id')->orderByDesc('depth')->firstOrFail();
        $this->grant('mastery.detail');

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'access_grants')) {
                $queries[] = $query->sql;
            }
        });

        $this->assertTrue($this->allowsNode('mastery.detail', $node));
        $this->assertCount(1, $queries);
    }

    public function test_l_origine_tenant_ne_change_pas_la_validite_du_droit_global(): void
    {
        $node = CompetencyNode::whereNotNull('parent_id')->firstOrFail();
        $tenant = Tenant::create([
            'slug' => 'origine-portee',
            'name' => 'Origine portée',
            'kind' => 'organization',
            'status' => 'active',
        ]);

        $this->grant('mastery.detail', originTenantId: $tenant->id);

        $this->assertTrue($this->allowsNode('mastery.detail', $node));
    }

    private function access(): AccessGrant
    {
        return app(AccessGrant::class);
    }

    private function allowsNode(string $capability, CompetencyNode $node): bool
    {
        return $this->access()->allows(
            $this->user,
            $capability,
            AccessGrantRecord::SCOPE_COMPETENCY_NODE,
            $node->uuid,
        );
    }

    private function grant(
        string $capability,
        ?string $scopeType = null,
        ?string $scopeUuid = null,
        ?int $originTenantId = null,
    ): AccessGrantRecord {
        return AccessGrantRecord::create([
            'user_id' => $this->user->id,
            'capability' => $capability,
            'scope_type' => $scopeType,
            'scope_uuid' => $scopeUuid,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
            'origin' => 'support',
            'origin_tenant_id' => $originTenantId,
            'origin_reference' => (string) Str::uuid7(),
        ]);
    }
}
