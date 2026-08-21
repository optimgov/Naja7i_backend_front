<?php

namespace Tests\Feature\BackOffice;

use App\Filament\Resources\Users\UserResource;
use App\Models\Identity;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AccountAdministrationService;
use App\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PanneauPersonnesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $plateforme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plateforme = Tenant::where('kind', 'platform')->firstOrFail();
        app(TenantContext::class)->set($this->plateforme);
    }

    public function test_members_view_ouvre_la_liste_sans_ouvrir_les_gestes(): void
    {
        $lecteur = $this->staff('lecteur@naja7i.ma', ['members.view']);

        $this->assertTrue($lecteur->can('viewAny', User::class));
        $this->assertFalse($lecteur->can('create', User::class));
        $this->assertFalse($lecteur->can('update', $this->membre('cible@naja7i.ma', 'candidat')));

        $this->actingAs($lecteur)->get('/admin/users')->assertOk();
    }

    public function test_un_compte_sans_members_view_ne_voit_pas_la_surface(): void
    {
        $auteur = $this->membre('auteur@naja7i.ma', 'auteur');

        $this->actingAs($auteur);
        $this->assertFalse(UserResource::canViewAny());
        $this->actingAs($auteur)->get('/admin/users')->assertForbidden();
    }

    public function test_la_creation_exige_invitation_et_attribution_et_cree_une_identite_utilisable(): void
    {
        $gestionnaire = $this->superAdmin('gestionnaire@naja7i.ma');
        $role = Role::where('code', 'auteur')->whereNull('tenant_id')->firstOrFail();

        $cree = app(AccountAdministrationService::class)->create($gestionnaire, [
            'email' => 'nouveau@naja7i.ma',
            'phone' => null,
            'password' => 'une-phrase-temporaire-solide',
            'locale' => 'fr',
            'status' => 'active',
            'role_uuids' => [$role->uuid],
        ]);

        $this->assertTrue($cree->memberships()->where('role_id', $role->id)->exists());
        $this->assertTrue(Identity::where('user_id', $cree->id)->where('provider', 'password')->exists());
        $this->assertNull($cree->email_verified_at, 'Le panneau ne fabrique pas une vérification d’e-mail.');
    }

    public function test_les_ecrans_de_creation_et_edition_se_rendent_entierement(): void
    {
        $gestionnaire = $this->superAdmin('ecrans@naja7i.ma');
        $cible = $this->membre('ecran-cible@naja7i.ma', 'auteur');

        $this->actingAs($gestionnaire)
            ->get('/admin/users/create')
            ->assertOk()
            ->assertSee('Mot de passe temporaire');

        $this->actingAs($gestionnaire)
            ->get(UserResource::getUrl('edit', ['record' => $cible]))
            ->assertOk()
            ->assertSee('Rôles dans ce tenant');
    }

    public function test_members_invite_sans_roles_assign_ne_cree_pas_un_compte_orphelin(): void
    {
        $inviteur = $this->staff('inviteur@naja7i.ma', ['members.view', 'members.invite']);
        $role = Role::where('code', 'auteur')->whereNull('tenant_id')->firstOrFail();

        try {
            app(AccountAdministrationService::class)->create($inviteur, [
                'email' => 'orphelin@naja7i.ma', 'phone' => null,
                'password' => 'une-phrase-temporaire-solide', 'locale' => 'fr', 'status' => 'active',
                'role_uuids' => [$role->uuid],
            ]);
            $this->fail('La création aurait dû être refusée.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('users', ['email' => 'orphelin@naja7i.ma']);
        }
    }

    public function test_modifier_les_coordonnees_invalide_leur_verification_sans_toucher_aux_roles(): void
    {
        $gestionnaire = $this->staff('coordonnees@naja7i.ma', ['members.view', 'members.invite']);
        $cible = $this->membre('ancienne@naja7i.ma', 'auteur');
        $cible->forceFill([
            'email_verified_at' => now(),
            'phone' => '+212600000001',
            'phone_verified_at' => now(),
        ])->save();

        app(AccountAdministrationService::class)->update($gestionnaire, $cible, [
            'email' => 'nouvelle@naja7i.ma',
            'phone' => '+212600000002',
            'locale' => 'ar',
            'status' => 'suspended',
        ]);

        $cible->refresh();
        $this->assertNull($cible->email_verified_at);
        $this->assertNull($cible->phone_verified_at);
        $this->assertSame('suspended', $cible->status);
        $this->assertSame(['auteur'], $cible->memberships()->with('role')->get()->pluck('role.code')->all());
    }

    public function test_un_gestionnaire_ne_peut_pas_modifier_ses_propres_roles(): void
    {
        $gestionnaire = $this->staff('gestionnaire2@naja7i.ma', [
            'members.view', 'members.invite', 'roles.assign',
        ]);

        $this->expectException(ValidationException::class);
        app(AccountAdministrationService::class)->syncRoles($gestionnaire, $gestionnaire, []);
    }

    public function test_roles_assign_attribue_et_retire_uniquement_dans_le_tenant_courant(): void
    {
        $gestionnaire = $this->superAdmin('gestionnaire3@naja7i.ma');
        $cible = $this->membre('personnel@naja7i.ma', 'auteur');
        $reviseur = Role::where('code', 'reviseur')->whereNull('tenant_id')->firstOrFail();

        app(AccountAdministrationService::class)->syncRoles($gestionnaire, $cible, [$reviseur->uuid]);

        $this->assertSame(['reviseur'], $cible->memberships()->with('role')->get()->pluck('role.code')->all());
    }

    public function test_un_role_hors_portee_ne_permet_aucune_escalade(): void
    {
        $gestionnaire = $this->staff('gestionnaire4@naja7i.ma', ['members.view', 'roles.assign']);
        $cible = $this->membre('cible2@naja7i.ma', 'auteur');
        $autre = Tenant::create(['slug' => 'centre-test', 'name' => 'Centre test']);
        $roleExterne = Role::create([
            'tenant_id' => $autre->id, 'code' => 'coordonnateur',
            'label_fr' => 'Coordonnateur', 'label_ar' => 'منسق', 'is_staff' => true,
        ]);

        $this->expectException(ValidationException::class);
        app(AccountAdministrationService::class)->syncRoles($gestionnaire, $cible, [$roleExterne->uuid]);
    }

    public function test_le_panneau_ne_peut_pas_creer_un_candidat_sans_actes_juridiques(): void
    {
        $gestionnaire = $this->superAdmin('gestionnaire5@naja7i.ma');
        $candidat = Role::where('code', 'candidat')->whereNull('tenant_id')->firstOrFail();

        $this->expectException(ValidationException::class);
        app(AccountAdministrationService::class)->create($gestionnaire, [
            'email' => 'candidat-admin@naja7i.ma', 'phone' => null,
            'password' => 'une-phrase-temporaire-solide', 'locale' => 'fr', 'status' => 'active',
            'role_uuids' => [$candidat->uuid],
        ]);
    }

    public function test_create_refuse_un_role_dont_une_permission_depasse_celles_de_l_acteur(): void
    {
        $gestionnaire = $this->staff('create-borne@naja7i.ma', ['members.view', 'members.invite', 'roles.assign']);
        $auteur = Role::where('code', 'auteur')->whereNull('tenant_id')->firstOrFail();

        try {
            app(AccountAdministrationService::class)->create($gestionnaire, [
                'email' => 'escalade-create@naja7i.ma', 'phone' => null,
                'password' => 'une-phrase-temporaire-solide', 'locale' => 'fr', 'status' => 'active',
                'role_uuids' => [$auteur->uuid],
            ]);
            $this->fail('Le rôle plus puissant aurait dû être refusé.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('users', ['email' => 'escalade-create@naja7i.ma']);
        }
    }

    public function test_sync_roles_refuse_un_role_dont_une_permission_depasse_celles_de_l_acteur(): void
    {
        $gestionnaire = $this->staff('sync-borne@naja7i.ma', ['members.view', 'roles.assign']);
        $cible = $this->membre('escalade-sync@naja7i.ma', 'auteur');
        $reviseur = Role::where('code', 'reviseur')->whereNull('tenant_id')->firstOrFail();

        try {
            app(AccountAdministrationService::class)->syncRoles($gestionnaire, $cible, [$reviseur->uuid]);
            $this->fail('Le rôle plus puissant aurait dû être refusé.');
        } catch (ValidationException) {
            $this->assertSame(['auteur'], $cible->memberships()->with('role')->get()->pluck('role.code')->all());
        }
    }

    public function test_super_admin_n_est_attribuable_que_par_un_super_admin_et_dans_la_borne_de_permissions(): void
    {
        $role = Role::where('code', 'super_admin')->whereNull('tenant_id')->firstOrFail();
        $cible = $this->membre('future-admin@naja7i.ma', 'auteur');
        $imposteur = $this->staff(
            'imposteur@naja7i.ma',
            Permission::query()->pluck('code')->all(),
        );

        try {
            app(AccountAdministrationService::class)->syncRoles($imposteur, $cible, [$role->uuid]);
            $this->fail('Un rôle complet sans appartenance super_admin ne doit pas pouvoir transmettre super_admin.');
        } catch (ValidationException) {
            $this->assertSame(['auteur'], $cible->memberships()->with('role')->get()->pluck('role.code')->all());
        }

        app(AccountAdministrationService::class)->syncRoles(
            $this->superAdmin('autorise@naja7i.ma'),
            $cible,
            [$role->uuid],
        );

        $this->assertSame(['super_admin'], $cible->memberships()->with('role')->get()->pluck('role.code')->all());
    }

    public function test_suspendre_un_compte_deja_authentifie_ferme_immediatement_le_panneau(): void
    {
        $personnel = $this->staff('session-suspendue@naja7i.ma', ['members.view']);

        $this->actingAs($personnel)->get('/admin/users')->assertOk();
        $personnel->forceFill(['status' => 'suspended'])->save();

        $this->get('/admin/users')->assertForbidden();
        $this->assertFalse($personnel->fresh()->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_sync_roles_refuse_la_derniere_appartenance_sans_aucune_mutation(): void
    {
        $gestionnaire = $this->superAdmin('garde-dernier-role@naja7i.ma');
        $cible = $this->membre('reste-visible@naja7i.ma', 'auteur');

        try {
            app(AccountAdministrationService::class)->syncRoles($gestionnaire, $cible, []);
            $this->fail('La dernière appartenance aurait dû être conservée.');
        } catch (ValidationException) {
            $this->assertSame(['auteur'], $cible->memberships()->with('role')->get()->pluck('role.code')->all());
            $this->actingAs($gestionnaire)->get(UserResource::getUrl('edit', ['record' => $cible]))->assertOk();
        }
    }

    public function test_creation_du_personnel_utilise_les_bornes_centrales_de_mot_de_passe(): void
    {
        config()->set('naja7i.password.min_length', 20);
        config()->set('naja7i.password.max_length', 24);
        config()->set('naja7i.password.check_compromised', false);
        $gestionnaire = $this->superAdmin('politique-mdp@naja7i.ma');
        $auteur = Role::where('code', 'auteur')->whereNull('tenant_id')->firstOrFail();

        foreach (['trop-court-mais-12', str_repeat('x', 25)] as $password) {
            try {
                app(AccountAdministrationService::class)->create($gestionnaire, [
                    'email' => hash('sha256', $password).'@naja7i.ma', 'phone' => null,
                    'password' => $password, 'locale' => 'fr', 'status' => 'active',
                    'role_uuids' => [$auteur->uuid],
                ]);
                $this->fail('La borne centrale du mot de passe aurait dû refuser la valeur.');
            } catch (ValidationException) {
                $this->assertDatabaseMissing('users', ['email' => hash('sha256', $password).'@naja7i.ma']);
            }
        }
    }

    public function test_creation_du_personnel_applique_conditionnellement_le_controle_anti_fuite(): void
    {
        config()->set('naja7i.password.check_compromised', true);
        app()->instance(UncompromisedVerifier::class, new class implements UncompromisedVerifier
        {
            public function verify($data): bool
            {
                return false;
            }
        });
        $gestionnaire = $this->superAdmin('anti-fuite@naja7i.ma');
        $auteur = Role::where('code', 'auteur')->whereNull('tenant_id')->firstOrFail();

        try {
            app(AccountAdministrationService::class)->create($gestionnaire, [
                'email' => 'mot-de-passe-fuite@naja7i.ma', 'phone' => null,
                'password' => 'une-phrase-temporaire-solide', 'locale' => 'fr', 'status' => 'active',
                'role_uuids' => [$auteur->uuid],
            ]);
            $this->fail('Le contrôle anti-fuite central aurait dû refuser le mot de passe.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('users', ['email' => 'mot-de-passe-fuite@naja7i.ma']);
        }
    }

    private function membre(string $email, string $roleCode): User
    {
        $user = User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr', 'status' => 'active',
        ]);
        $user->memberships()->create([
            'role_id' => Role::where('code', $roleCode)->whereNull('tenant_id')->value('id'),
        ]);

        return $user;
    }

    /** @param list<string> $permissionCodes */
    private function staff(string $email, array $permissionCodes): User
    {
        $role = Role::create([
            'code' => 'role-'.strstr($email, '@', true),
            'label_fr' => 'Gestionnaire', 'label_ar' => 'مسير', 'is_staff' => true,
        ]);
        $role->permissions()->attach(Permission::whereIn('code', $permissionCodes)->pluck('id'));

        $user = User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr', 'status' => 'active',
        ]);
        $user->memberships()->create(['role_id' => $role->id]);

        return $user;
    }

    private function superAdmin(string $email): User
    {
        return $this->membre($email, 'super_admin');
    }
}
