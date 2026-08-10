<?php

namespace Tests\Feature\Correctifs;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PAS-14 — Gardes sur les nœuds du graphe d'autorisation.
 *
 * Les lots précédents gardaient les arêtes : `memberships` et
 * `permission_role`. Ces tests éprouvent les nœuds — `roles` et
 * `permissions` — dont la mutation contournait tout.
 *
 * `DatabaseMigrations` : les tests de concurrence exigent des données
 * réellement validées, sinon la seconde connexion ne voit rien.
 */
class GardesSurLesNoeudsTest extends TestCase
{
    use DatabaseMigrations;

    private Tenant $plateforme;

    private Tenant $organisme;

    private User $utilisateur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plateforme = Tenant::where('kind', 'platform')->firstOrFail();
        $this->organisme = Tenant::create(['slug' => 'centre-fes', 'name' => 'Centre de Fès']);

        app(TenantContext::class)->set($this->plateforme);

        $this->utilisateur = User::create([
            'email' => 'membre@naja7i.ma', 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $this->utilisateur->markEmailAsVerified();
    }

    protected function tearDown(): void
    {
        DB::statement("SET lock_timeout = '0'");
        parent::tearDown();
    }

    // ===================================================================
    // BLOC-1 — les attributs d'un rôle distribué
    // ===================================================================

    public function test_la_portee_d_un_role_distribue_ne_change_plus(): void
    {
        $role = $this->roleGlobalDistribue();

        // Le scénario exact de la revue : déplacer le rôle après coup.
        $this->expectException(QueryException::class);

        DB::statement('UPDATE roles SET tenant_id = ? WHERE id = ?', [$this->organisme->id, $role->id]);
    }

    public function test_un_role_d_organisme_distribue_ne_devient_pas_global(): void
    {
        $role = Role::create([
            'tenant_id' => $this->organisme->id, 'code' => 'coordonnateur',
            'label_fr' => 'Coordonnateur', 'label_ar' => 'منسق',
        ]);

        app(TenantContext::class)->set($this->organisme);
        $this->utilisateur->memberships()->create(['role_id' => $role->id]);

        $this->expectException(QueryException::class);

        DB::statement('UPDATE roles SET tenant_id = NULL WHERE id = ?', [$role->id]);
    }

    public function test_un_role_distribue_hors_plateforme_ne_devient_pas_back_office(): void
    {
        $role = $this->roleGlobalDistribue();

        // Second scénario de la revue : is_staff après distribution.
        $this->expectException(QueryException::class);

        DB::statement('UPDATE roles SET is_staff = true WHERE id = ?', [$role->id]);
    }

    public function test_un_role_d_organisme_portant_une_permission_reservee_ne_devient_pas_global(): void
    {
        $role = Role::create([
            'tenant_id' => $this->organisme->id, 'code' => 'local',
            'label_fr' => 'Local', 'label_ar' => 'محلي',
        ]);

        // On force l'état interdit sans passer par la garde du pivot.
        DB::statement('ALTER TABLE permission_role DISABLE TRIGGER permission_role_scope_guard');
        DB::table('permission_role')->insert([
            'permission_id' => Permission::where('code', 'tenants.manage')->value('id'),
            'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('ALTER TABLE permission_role ENABLE TRIGGER permission_role_scope_guard');

        $this->expectException(QueryException::class);

        DB::statement('UPDATE roles SET tenant_id = NULL WHERE id = ?', [$role->id]);
    }

    // --- Ce qui doit rester possible ---------------------------------------

    public function test_renommer_un_role_distribue_reste_possible(): void
    {
        $role = $this->roleGlobalDistribue();

        DB::statement('UPDATE roles SET label_fr = ? WHERE id = ?', ['Candidat inscrit', $role->id]);

        $this->assertSame('Candidat inscrit', $role->fresh()->label_fr);
    }

    public function test_un_role_non_distribue_reste_modifiable(): void
    {
        $role = Role::create([
            'code' => 'nouveau', 'label_fr' => 'Nouveau', 'label_ar' => 'جديد',
        ]);

        DB::statement('UPDATE roles SET is_staff = true, tenant_id = ? WHERE id = ?', [
            $this->organisme->id, $role->id,
        ]);

        $this->assertTrue($role->fresh()->is_staff);
    }

    public function test_un_role_distribue_sur_la_plateforme_peut_devenir_back_office(): void
    {
        $role = Role::create(['code' => 'coordination', 'label_fr' => 'Coordination', 'label_ar' => 'تنسيق']);

        app(TenantContext::class)->set($this->plateforme);
        $this->utilisateur->memberships()->create(['role_id' => $role->id]);

        DB::statement('UPDATE roles SET is_staff = true WHERE id = ?', [$role->id]);

        $this->assertTrue($role->fresh()->is_staff);
    }

    // ===================================================================
    // BLOC-2 — une permission devient réservée après coup
    // ===================================================================

    public function test_une_permission_accordee_hors_plateforme_ne_devient_pas_reservee(): void
    {
        $role = $this->roleGlobalDistribue();
        $role->permissions()->attach(Permission::where('code', 'catalogue.view')->value('id'));

        // Le scénario exact de la revue.
        $this->expectException(QueryException::class);

        DB::statement("UPDATE permissions SET platform_only = true WHERE code = 'catalogue.view'");
    }

    public function test_une_permission_portee_par_un_role_d_organisme_ne_devient_pas_reservee(): void
    {
        $role = Role::create([
            'tenant_id' => $this->organisme->id, 'code' => 'lecteur',
            'label_fr' => 'Lecteur', 'label_ar' => 'قارئ',
        ]);
        $role->permissions()->attach(Permission::where('code', 'members.view')->value('id'));

        $this->expectException(QueryException::class);

        DB::statement("UPDATE permissions SET platform_only = true WHERE code = 'members.view'");
    }

    public function test_une_permission_non_distribuee_peut_devenir_reservee(): void
    {
        DB::statement("UPDATE permissions SET platform_only = true WHERE code = 'questions.retire'");

        $this->assertTrue(Permission::where('code', 'questions.retire')->value('platform_only'));
    }

    public function test_lever_la_reservation_reste_toujours_possible(): void
    {
        // Restreindre l'accès ne peut jamais produire d'escalade.
        DB::statement("UPDATE permissions SET platform_only = false WHERE code = 'tenants.manage'");

        $this->assertFalse(Permission::where('code', 'tenants.manage')->value('platform_only'));
    }

    public function test_renommer_une_permission_distribuee_reste_possible(): void
    {
        $role = $this->roleGlobalDistribue();
        $role->permissions()->attach(Permission::where('code', 'catalogue.view')->value('id'));

        DB::statement("UPDATE permissions SET label_fr = ? WHERE code = 'catalogue.view'", ['Voir le catalogue']);

        $this->assertSame('Voir le catalogue', Permission::where('code', 'catalogue.view')->value('label_fr'));
    }

    // ===================================================================
    // Sérialisation — les gardes voient une écriture concurrente
    // ===================================================================

    /**
     * Une attribution non validée doit bloquer le passage en `is_staff`.
     *
     * La seconde connexion prend un `FOR NO KEY UPDATE` sur le rôle : ce mode
     * entre en conflit avec le `FOR UPDATE` réclamé par la garde, tout en
     * restant compatible avec le `FOR KEY SHARE` que prendrait une clé
     * étrangère. Ce qui attend ne peut donc être que le rendez-vous.
     */
    /**
     * CE QUE CE TEST PROUVE, ET CE QU'IL NE PROUVE PAS.
     *
     * Il prouve que la garde RÉCLAME le verrou des appartenances : sans le
     * `PERFORM ... FOR UPDATE`, il vire au rouge. Éprouvé par mutation.
     *
     * Il ne prouve pas que ce verrou ferme une escalade. Sous mutation, la
     * garde refuse encore — mais par son exception métier au lieu d'attendre,
     * car l'appartenance posée ici est VALIDÉE, donc visible d'un simple
     * `count()`. Seule la temporalité change, pas l'issue.
     *
     * Et dans le cas que le nom évoque — une attribution réellement EN COURS,
     * non validée —, `SELECT ... FOR UPDATE` ne peut rien voir : MVCC masque
     * les lignes non validées. Vérifié en base : l'entrelacement est refusé par
     * attente avec ET sans ce verrou. Ce qui sérialise là est le rendez-vous
     * sur la ligne `roles` posé au PAS-13 — le trigger d'appartenance la prend
     * en `FOR UPDATE`, et `UPDATE roles` l'exige en exclusivité.
     *
     * Le verrou de ce lot reste une défense de profondeur cohérente avec
     * l'ordre parent-puis-enfants ; il n'est pas ce qui porte l'invariant.
     */
    public function test_la_garde_de_role_attend_une_attribution_en_cours(): void
    {
        $role = Role::create(['code' => 'a-distribuer', 'label_fr' => 'À distribuer', 'label_ar' => 'للتوزيع']);

        app(TenantContext::class)->set($this->organisme);
        $this->utilisateur->memberships()->create(['role_id' => $role->id]);

        $seconde = DB::connection('pgsql_concurrent');
        $seconde->beginTransaction();
        $seconde->statement("SET lock_timeout = '0'");
        $seconde->select('SELECT id FROM memberships WHERE role_id = ? FOR UPDATE', [$role->id]);

        DB::statement("SET lock_timeout = '500ms'");

        try {
            DB::statement('UPDATE roles SET is_staff = true WHERE id = ?', [$role->id]);
            $this->fail('La garde n\'a pas réclamé le verrou des appartenances.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('lock timeout', strtolower($e->getMessage()));
        } finally {
            DB::statement("SET lock_timeout = '0'");
            $seconde->rollBack();
        }
    }

    /**
     * Symétrique, pour la garde de permission — et même réserve que ci-dessus :
     * sous mutation, la garde refuse encore par son exception métier. Le test
     * établit que le verrou est réclamé, non qu'il ferme une escalade.
     */
    public function test_la_garde_de_permission_attend_une_mutation_de_role_en_cours(): void
    {
        $role = $this->roleGlobalDistribue();
        $role->permissions()->attach(Permission::where('code', 'catalogue.view')->value('id'));

        $seconde = DB::connection('pgsql_concurrent');
        $seconde->beginTransaction();
        $seconde->statement("SET lock_timeout = '0'");
        $seconde->select('SELECT id FROM roles WHERE id = ? FOR NO KEY UPDATE', [$role->id]);

        DB::statement("SET lock_timeout = '500ms'");

        try {
            DB::statement("UPDATE permissions SET platform_only = true WHERE code = 'catalogue.view'");
            $this->fail('La garde n\'a pas réclamé le verrou des rôles.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('lock timeout', strtolower($e->getMessage()));
        } finally {
            DB::statement("SET lock_timeout = '0'");
            $seconde->rollBack();
        }
    }

    // ===================================================================

    /** Rôle global attribué dans un organisme — le cas à protéger. */
    private function roleGlobalDistribue(): Role
    {
        $role = Role::where('code', 'candidat')->whereNull('tenant_id')->firstOrFail();

        app(TenantContext::class)->set($this->organisme);
        $this->utilisateur->memberships()->create(['role_id' => $role->id]);
        app(TenantContext::class)->set($this->plateforme);

        return $role->fresh();
    }
}
