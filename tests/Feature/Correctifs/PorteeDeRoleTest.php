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
 * PAS-14.2 — Le sens du changement de portée d'un rôle.
 *
 * La contre-revue PAS-14.1 a montré que la garde contrôlait la mauvaise
 * direction. Ces tests éprouvent les deux sens, et surtout l'INVARIANT
 * D'ÉTAT — aucun rôle d'organisme ne porte de permission réservée — plutôt
 * que le seul chemin qui vient d'être signalé.
 *
 * C'est la leçon de cinq revues : vérifier l'état interdit, pas le chemin
 * connu pour y mener.
 */
class PorteeDeRoleTest extends TestCase
{
    use DatabaseMigrations;

    private Tenant $plateforme;

    private Tenant $organisme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plateforme = Tenant::where('kind', 'platform')->firstOrFail();
        $this->organisme = Tenant::create(['slug' => 'centre-fes', 'name' => 'Centre de Fès']);

        app(TenantContext::class)->set($this->plateforme);
    }

    /**
     * L'INVARIANT, tel que la revue demande de le vérifier : aucune ligne de
     * `roles` avec un `tenant_id` ne joint une permission `platform_only`.
     */
    private function assertAucunRoleDOrganismePorteUnePermissionReservee(): void
    {
        $violations = DB::table('roles')
            ->join('permission_role', 'permission_role.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->whereNotNull('roles.tenant_id')
            ->where('permissions.platform_only', true)
            ->count();

        $this->assertSame(0, $violations,
            'Un rôle d\'organisme porte une permission réservée à la plateforme.');
    }

    private function roleGlobalReserve(): Role
    {
        $role = Role::create([
            'code' => 'outil-plateforme', 'label_fr' => 'Outil plateforme', 'label_ar' => 'أداة المنصة',
        ]);

        // Autorisé : rôle global non distribué, la garde du pivot l'accepte.
        $role->permissions()->attach(Permission::where('code', 'tenants.manage')->value('id'));

        return $role->fresh();
    }

    // --- Le sens dangereux ---------------------------------------------------

    public function test_un_role_global_portant_une_permission_reservee_ne_devient_pas_local(): void
    {
        $role = $this->roleGlobalReserve();

        // Le scénario exact de la contre-revue.
        $this->expectException(QueryException::class);

        DB::statement('UPDATE roles SET tenant_id = ? WHERE id = ?', [$this->organisme->id, $role->id]);
    }

    /**
     * L'ÉTAT interdit d'abord, le chemin ensuite — l'ordre compte.
     *
     * Ce test est celui qui porte l'invariant, et il doit échouer EN LE
     * NOMMANT. Avec l'assertion de chemin en tête, une mutation de la garde le
     * faisait bien virer au rouge, mais sur « tenant_id n'est pas nul » :
     * PHPUnit s'arrêtant à la première assertion en défaut, la requête d'état
     * n'était jamais évaluée. Le test tombait pour la bonne cause et le
     * rapportait par le mauvais symptôme — cinq revues ont montré ce que coûte
     * cette confusion.
     *
     * L'assertion de chemin reste, en second : elle documente par où l'état
     * aurait été atteint, elle ne définit pas l'invariant.
     */
    public function test_l_invariant_tient_apres_la_tentative(): void
    {
        $role = $this->roleGlobalReserve();

        try {
            DB::statement('UPDATE roles SET tenant_id = ? WHERE id = ?', [$this->organisme->id, $role->id]);
        } catch (QueryException) {
            // attendu
        }

        $this->assertAucunRoleDOrganismePorteUnePermissionReservee();
        $this->assertNull($role->fresh()->tenant_id, 'Le chemin lui-même doit rester fermé.');
    }

    public function test_le_chemin_complet_de_la_revue_est_ferme(): void
    {
        $role = $this->roleGlobalReserve();
        $utilisateur = User::create([
            'email' => 'membre@naja7i.ma', 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);

        // Étape 1 : rendre le rôle local — désormais refusée.
        try {
            DB::statement('UPDATE roles SET tenant_id = ? WHERE id = ?', [$this->organisme->id, $role->id]);
        } catch (QueryException) {
            // attendu
        }

        // Étape 2 : l'attribution qui aurait suivi ne trouve plus de rôle local.
        app(TenantContext::class)->set($this->organisme);

        $this->expectException(QueryException::class);
        $utilisateur->memberships()->create(['role_id' => $role->id]);
    }

    // --- Le sens inverse, également gardé ------------------------------------

    public function test_un_role_d_organisme_portant_une_permission_reservee_ne_devient_pas_global(): void
    {
        $role = Role::create([
            'tenant_id' => $this->organisme->id, 'code' => 'local',
            'label_fr' => 'Local', 'label_ar' => 'محلي',
        ]);

        // On force l'état interdit hors garde du pivot, pour éprouver celle-ci.
        DB::statement('ALTER TABLE permission_role DISABLE TRIGGER permission_role_scope_guard');
        DB::table('permission_role')->insert([
            'permission_id' => Permission::where('code', 'tenants.manage')->value('id'),
            'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('ALTER TABLE permission_role ENABLE TRIGGER permission_role_scope_guard');

        $this->expectException(QueryException::class);

        DB::statement('UPDATE roles SET tenant_id = NULL WHERE id = ?', [$role->id]);
    }

    // --- Ce qui doit rester possible -----------------------------------------

    public function test_un_role_global_sans_permission_reservee_peut_devenir_local(): void
    {
        $role = Role::create(['code' => 'ordinaire', 'label_fr' => 'Ordinaire', 'label_ar' => 'عادي']);
        $role->permissions()->attach(Permission::where('code', 'members.view')->value('id'));

        DB::statement('UPDATE roles SET tenant_id = ? WHERE id = ?', [$this->organisme->id, $role->id]);

        $this->assertSame($this->organisme->id, $role->fresh()->tenant_id);
        $this->assertAucunRoleDOrganismePorteUnePermissionReservee();
    }

    public function test_un_role_global_reserve_reste_utilisable_sur_la_plateforme(): void
    {
        $role = $this->roleGlobalReserve();
        $utilisateur = User::create([
            'email' => 'staff@naja7i.ma', 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);

        app(TenantContext::class)->set($this->plateforme);
        $membership = $utilisateur->memberships()->create(['role_id' => $role->id]);

        $this->assertNotNull($membership->id);
    }

    public function test_l_invariant_tient_sur_les_donnees_initiales(): void
    {
        // super_admin porte les 19 permissions, dont les réservées : il doit
        // rester global. Le seeder ne doit produire aucune violation.
        $this->assertAucunRoleDOrganismePorteUnePermissionReservee();
    }
}
