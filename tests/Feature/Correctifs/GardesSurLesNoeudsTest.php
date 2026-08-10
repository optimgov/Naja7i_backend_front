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
use Illuminate\Support\Str;
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
     * La garde RÉCLAME le verrou des appartenances — rien de plus.
     *
     * Ce test établit un fait d'ordonnancement, pas une propriété de sécurité :
     * sans le `PERFORM ... FOR UPDATE`, il vire au rouge, donc le verrou est
     * bien pris, et il est pris sur l'enfant après le parent — l'ordre
     * parent-puis-enfants tenu partout, qui évite les interblocages.
     *
     * L'invariant, lui, est porté ailleurs et vérifié par
     * `test_aucun_entrelacement_ne_produit_un_role_back_office_distribue()`.
     * Ce verrou est une défense de profondeur assumée (ADR-0024) : le
     * rendez-vous sur la ligne `roles` posé au PAS-13 suffit déjà, dans les
     * deux ordres.
     */
    public function test_la_garde_de_role_reclame_le_verrou_des_appartenances(): void
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
            $this->fail('La garde a traversé sans réclamer le verrou des appartenances.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('lock timeout', strtolower($e->getMessage()));
        } finally {
            DB::statement("SET lock_timeout = '0'");
            $seconde->rollBack();
        }
    }

    /**
     * Symétrique : la garde de permission réclame le verrou des rôles, dans le
     * même ordre. Même portée que ci-dessus — un fait d'ordonnancement, pas la
     * preuve qu'une escalade est fermée.
     */
    public function test_la_garde_de_permission_reclame_le_verrou_des_roles(): void
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
            $this->fail('La garde a traversé sans réclamer le verrou des rôles.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('lock timeout', strtolower($e->getMessage()));
        } finally {
            DB::statement("SET lock_timeout = '0'");
            $seconde->rollBack();
        }
    }

    // ===================================================================
    // L'INVARIANT, éprouvé sur un entrelacement réel
    // ===================================================================

    /**
     * Aucun entrelacement ne produit un rôle back-office porteur d'une
     * appartenance hors plateforme.
     *
     * C'est l'invariant que le lot défend, et il ne se prouve pas par un test
     * séquentiel : il faut que les deux écritures se croisent réellement, sur
     * deux sessions PostgreSQL, dans les deux ordres.
     *
     * Ce qui les sérialise est le rendez-vous sur la ligne `roles` établi au
     * PAS-13 — le trigger d'appartenance la prend en `FOR UPDATE`, et
     * `UPDATE roles` l'exige en exclusivité. Le verrou des appartenances posé
     * par ce lot n'y contribue pas : `SELECT ... FOR UPDATE` ne voit pas une
     * ligne non validée, MVCC la masquant. Vérifié en base sur deux sessions,
     * l'entrelacement ci-dessous est refusé avec ET sans ce verrou.
     *
     * Le test porte donc sur l'ISSUE, jamais sur le mécanisme : quel que soit
     * l'ordre, et que le refus vienne d'une attente ou d'une exception, l'état
     * final ne contient pas l'escalade.
     */
    public function test_aucun_entrelacement_ne_produit_un_role_back_office_distribue(): void
    {
        // --- Ordre A : l'attribution commence, la mutation tente de passer ---
        $role = Role::create([
            'code' => 'entrelacement-a', 'label_fr' => 'Entrelacement A', 'label_ar' => 'تشابك',
        ]);

        $seconde = DB::connection('pgsql_concurrent');
        $seconde->beginTransaction();
        $seconde->statement("SET lock_timeout = '0'");
        $seconde->table('memberships')->insert([
            'uuid' => (string) Str::uuid7(),
            'tenant_id' => $this->organisme->id,
            'user_id' => $this->utilisateur->id,
            'role_id' => $role->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::statement("SET lock_timeout = '500ms'");

        $passee = false;

        try {
            DB::statement('UPDATE roles SET is_staff = true WHERE id = ?', [$role->id]);
            $passee = true;
        } catch (QueryException) {
            // Attente ou refus métier : les deux conviennent, seule l'issue compte.
        } finally {
            DB::statement("SET lock_timeout = '0'");
        }

        // L'attribution aboutit : c'est le pire cas pour l'invariant.
        $seconde->commit();

        $this->assertFalse($passee, 'La mutation ne doit pas passer pendant une attribution en cours.');
        $this->assertAucuneEscalade();

        // --- Ordre B : la mutation est acquise, l'attribution arrive après ---
        $roleB = Role::create([
            'code' => 'entrelacement-b', 'label_fr' => 'Entrelacement B', 'label_ar' => 'تشابك',
        ]);

        // Aucune appartenance : devenir back-office est légitime ici.
        DB::statement('UPDATE roles SET is_staff = true WHERE id = ?', [$roleB->id]);
        $this->assertTrue((bool) $roleB->fresh()->is_staff);

        $refusee = false;

        try {
            app(TenantContext::class)->set($this->organisme);
            $this->utilisateur->memberships()->create(['role_id' => $roleB->id]);
        } catch (QueryException) {
            $refusee = true;
        } finally {
            app(TenantContext::class)->set($this->plateforme);
        }

        $this->assertTrue($refusee, 'Un rôle back-office ne s\'attribue pas dans un organisme.');
        $this->assertAucuneEscalade();
    }

    /**
     * L'invariant lui-même, formulé en SQL : aucun rôle de plateforme marqué
     * back-office ne porte d'appartenance hors du tenant plateforme.
     */
    private function assertAucuneEscalade(): void
    {
        $violations = DB::selectOne(
            'SELECT count(*) AS n
             FROM memberships m
             JOIN roles r ON r.id = m.role_id
             WHERE r.is_staff AND r.tenant_id IS NULL AND m.tenant_id <> 1'
        )->n;

        $this->assertSame(
            0, (int) $violations,
            'Un rôle back-office de plateforme porte une appartenance hors plateforme.'
        );
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
