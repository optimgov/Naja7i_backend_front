<?php

namespace Tests\Feature\Rbac;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PermissionResolver;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PAS-9 — Les permissions décidées à l'ADR-0009 sont-elles réellement
 * exécutées par le code ? C'était l'écart G10 le plus visible du dépôt.
 */
class PermissionsTest extends TestCase
{
    use RefreshDatabase;

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
            'email' => 'staff@naja7i.ma', 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
    }

    private function resolveur(): PermissionResolver
    {
        $r = app(PermissionResolver::class);
        $r->forget();

        return $r;
    }

    private function attribuer(string $roleCode, ?Tenant $tenant = null): void
    {
        $tenant ??= $this->plateforme;

        $role = Role::where('code', $roleCode)
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenant->id))
            ->firstOrFail();

        app(TenantContext::class)->set($tenant);
        $this->utilisateur->memberships()->create(['role_id' => $role->id]);
    }

    // --- Le référentiel est peuplé ------------------------------------------

    public function test_les_permissions_initiales_sont_en_base(): void
    {
        $this->assertGreaterThanOrEqual(19, Permission::count());

        foreach (['questions.publish', 'catalogue.manage', 'roles.assign', 'refunds.issue'] as $code) {
            $this->assertNotNull(Permission::where('code', $code)->first(), "{$code} doit exister.");
        }
    }

    public function test_chaque_permission_porte_un_libelle_bilingue(): void
    {
        $muettes = Permission::where(fn ($q) => $q->whereNull('label_ar')->orWhere('label_ar', ''))->count();

        $this->assertSame(0, $muettes, 'Une permission qu\'on ne sait pas décrire ne doit pas exister.');
    }

    // --- La vérification passe par les permissions, plus par les rôles ------

    public function test_un_auteur_peut_rediger_mais_pas_publier(): void
    {
        $this->attribuer('auteur');

        $resolveur = $this->resolveur();

        $this->assertTrue($resolveur->has($this->utilisateur, 'questions.create'));
        $this->assertFalse($resolveur->has($this->utilisateur, 'questions.publish'));
    }

    public function test_un_editeur_peut_publier(): void
    {
        $this->attribuer('editeur');

        $this->assertTrue($this->resolveur()->has($this->utilisateur, 'questions.publish'));
    }

    public function test_le_super_admin_a_toutes_les_permissions(): void
    {
        $this->attribuer('super_admin');

        $this->assertSame(
            Permission::count(),
            count($this->resolveur()->forUser($this->utilisateur))
        );
    }

    public function test_un_candidat_n_a_aucune_permission_de_back_office(): void
    {
        $this->attribuer('candidat');

        $this->assertSame([], $this->resolveur()->forUser($this->utilisateur));
    }

    public function test_le_cumul_de_roles_donne_l_union_des_permissions(): void
    {
        $this->attribuer('auteur');
        $this->attribuer('finance');

        $resolveur = $this->resolveur();

        $this->assertTrue($resolveur->has($this->utilisateur, 'questions.create'));
        $this->assertTrue($resolveur->has($this->utilisateur, 'refunds.issue'));
    }

    // --- Les permissions restent évaluées dans le tenant courant ------------

    public function test_une_permission_ne_traverse_pas_les_tenants(): void
    {
        $this->attribuer('editeur');   // sur la plateforme

        app(TenantContext::class)->set($this->plateforme);
        $this->assertTrue($this->resolveur()->has($this->utilisateur, 'questions.publish'));

        app(TenantContext::class)->set($this->organisme);
        $this->assertFalse(
            $this->resolveur()->has($this->utilisateur, 'questions.publish'),
            'Éditeur sur la plateforme ne signifie pas éditeur chez un organisme.'
        );
    }

    // --- Rôles propres à un organisme ---------------------------------------

    public function test_un_organisme_peut_definir_son_propre_role(): void
    {
        $coordonnateur = Role::create([
            'tenant_id' => $this->organisme->id, 'code' => 'coordonnateur',
            'label_fr' => 'Coordonnateur', 'label_ar' => 'منسق', 'is_staff' => true,
        ]);

        $coordonnateur->permissions()->attach(
            Permission::whereIn('code', ['members.view', 'members.invite'])->pluck('id')
        );

        $this->attribuer('coordonnateur', $this->organisme);

        app(TenantContext::class)->set($this->organisme);
        $this->assertTrue($this->resolveur()->has($this->utilisateur, 'members.invite'));
    }

    public function test_deux_organismes_peuvent_avoir_un_role_de_meme_code(): void
    {
        $autre = Tenant::create(['slug' => 'centre-agadir', 'name' => 'Centre d\'Agadir']);

        Role::create([
            'tenant_id' => $this->organisme->id, 'code' => 'coordonnateur',
            'label_fr' => 'Coordonnateur', 'label_ar' => 'منسق',
        ]);

        $second = Role::create([
            'tenant_id' => $autre->id, 'code' => 'coordonnateur',
            'label_fr' => 'Coordonnateur', 'label_ar' => 'منسق',
        ]);

        $this->assertNotNull($second->id);
    }

    public function test_un_meme_organisme_ne_peut_pas_dupliquer_un_code_de_role(): void
    {
        Role::create([
            'tenant_id' => $this->organisme->id, 'code' => 'coordonnateur',
            'label_fr' => 'Coordonnateur', 'label_ar' => 'منسق',
        ]);

        $this->expectException(QueryException::class);

        Role::create([
            'tenant_id' => $this->organisme->id, 'code' => 'coordonnateur',
            'label_fr' => 'Doublon', 'label_ar' => 'مكرر',
        ]);
    }

    public function test_les_codes_de_role_de_plateforme_restent_uniques(): void
    {
        $this->expectException(QueryException::class);

        Role::create(['code' => 'editeur', 'label_fr' => 'Doublon', 'label_ar' => 'مكرر']);
    }

    // --- Le garde-fou des permissions réservées -----------------------------

    public function test_un_role_d_organisme_ne_peut_pas_recevoir_une_permission_de_plateforme(): void
    {
        $role = Role::create([
            'tenant_id' => $this->organisme->id, 'code' => 'admin-local',
            'label_fr' => 'Administrateur local', 'label_ar' => 'مدير محلي', 'is_staff' => true,
        ]);

        $reservee = Permission::where('code', 'tenants.manage')->firstOrFail();
        $this->assertTrue($reservee->platform_only);

        $this->expectException(QueryException::class);

        $role->permissions()->attach($reservee->id);
    }

    public function test_un_role_d_organisme_peut_recevoir_une_permission_ordinaire(): void
    {
        $role = Role::create([
            'tenant_id' => $this->organisme->id, 'code' => 'lecteur',
            'label_fr' => 'Lecteur', 'label_ar' => 'قارئ',
        ]);

        $role->permissions()->attach(Permission::where('code', 'members.view')->value('id'));

        $this->assertSame(1, $role->permissions()->count());
    }

    public function test_un_role_de_plateforme_peut_recevoir_une_permission_reservee(): void
    {
        $role = Role::where('code', 'super_admin')->whereNull('tenant_id')->firstOrFail();

        $this->assertTrue(
            $role->permissions()->where('code', 'tenants.manage')->exists()
        );
    }

    // --- Effet immédiat, sans redéploiement ---------------------------------

    public function test_retirer_une_permission_prend_effet_immediatement(): void
    {
        $this->attribuer('editeur');
        $this->assertTrue($this->resolveur()->has($this->utilisateur, 'questions.publish'));

        $editeur = Role::where('code', 'editeur')->whereNull('tenant_id')->firstOrFail();
        $editeur->permissions()->detach(Permission::where('code', 'questions.publish')->value('id'));

        $this->assertFalse(
            $this->resolveur()->has($this->utilisateur, 'questions.publish'),
            'Aucune mise en cache persistante ne doit survivre au retrait.'
        );
    }

    public function test_ajouter_une_permission_prend_effet_immediatement(): void
    {
        $this->attribuer('auteur');
        $this->assertFalse($this->resolveur()->has($this->utilisateur, 'questions.publish'));

        Role::where('code', 'auteur')->whereNull('tenant_id')->firstOrFail()
            ->permissions()->attach(Permission::where('code', 'questions.publish')->value('id'));

        $this->assertTrue($this->resolveur()->has($this->utilisateur, 'questions.publish'));
    }

    // --- Aucune régression sur l'existant -----------------------------------

    public function test_has_role_continue_de_fonctionner_pour_les_controles_grossiers(): void
    {
        $this->attribuer('candidat');

        app(TenantContext::class)->set($this->plateforme);
        $this->assertTrue($this->utilisateur->hasRole('candidat'));
    }

    public function test_aucune_cle_interne_dans_la_serialisation_des_permissions(): void
    {
        $payload = Permission::first()->toArray();

        $this->assertArrayNotHasKey('id', $payload);
        $this->assertArrayHasKey('uuid', $payload);

        $role = Role::first()->toArray();
        $this->assertArrayNotHasKey('id', $role);
        $this->assertArrayNotHasKey('tenant_id', $role);
    }
}
