<?php

namespace Tests\Feature\BackOffice;

use App\Filament\Pages\MonDossier;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

final class MonDossierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_tout_personnel_pouvant_entrer_ouvre_son_dossier_et_voit_ses_roles(): void
    {
        $staff = $this->staff('personnel@naja7i.ma', ['orders.view']);

        $this->actingAs($staff)
            ->get(MonDossier::getUrl())
            ->assertOk()
            ->assertSee('Mon dossier')
            ->assertSee('Gestionnaire');
    }

    public function test_un_candidat_ne_peut_pas_ouvrir_la_page_du_panneau(): void
    {
        $candidat = $this->membre('candidat-dossier@naja7i.ma', 'candidat');

        $this->actingAs($candidat)
            ->get(MonDossier::getUrl())
            ->assertForbidden();
    }

    public function test_le_personnel_modifie_uniquement_ses_coordonnees_et_conserve_ses_roles(): void
    {
        $staff = $this->staff('ancienne-adresse@naja7i.ma', ['members.view']);
        $staff->forceFill(['email_verified_at' => now()])->save();
        $rolesAvant = $staff->memberships()->pluck('role_id')->all();

        $this->actingAs($staff);

        Livewire::test(MonDossier::class)
            ->fillForm([
                'email' => 'nouvelle-adresse@naja7i.ma',
                'phone' => '+212600000777',
                'locale' => 'ar',
            ], 'accountForm')
            ->call('saveAccount')
            ->assertHasNoFormErrors();

        $staff->refresh();
        $this->assertSame('nouvelle-adresse@naja7i.ma', $staff->email);
        $this->assertSame('+212600000777', $staff->phone);
        $this->assertSame('ar', $staff->locale);
        $this->assertNull($staff->email_verified_at);
        $this->assertSame($rolesAvant, $staff->memberships()->pluck('role_id')->all());
        $this->assertSame('active', $staff->status);
    }

    public function test_les_roles_ne_sont_jamais_acceptes_comme_donnees_du_formulaire(): void
    {
        $staff = $this->staff('roles-intacts@naja7i.ma', ['members.view']);
        $rolesAvant = $staff->memberships()->pluck('role_id')->all();

        $this->actingAs($staff);

        Livewire::test(MonDossier::class)
            ->fillForm([
                'email' => $staff->email,
                'phone' => $staff->phone,
                'locale' => 'fr',
                'roles_label' => 'Super administrateur',
                'status_label' => 'Suspendu',
            ], 'accountForm')
            ->call('saveAccount')
            ->assertHasNoFormErrors();

        $staff->refresh();
        $this->assertSame($rolesAvant, $staff->memberships()->pluck('role_id')->all());
        $this->assertSame('active', $staff->status);
    }

    public function test_le_mot_de_passe_courant_est_exige_et_les_roles_restent_intacts(): void
    {
        $staff = $this->staff('mot-de-passe@naja7i.ma', ['members.view']);
        $rolesAvant = $staff->memberships()->pluck('role_id')->all();

        $this->actingAs($staff);

        Livewire::test(MonDossier::class)
            ->fillForm([
                'current_password' => 'incorrect',
                'password' => 'Nouvelle-phrase-solide-2026!',
                'password_confirmation' => 'Nouvelle-phrase-solide-2026!',
            ], 'passwordForm')
            ->call('savePassword')
            ->assertHasErrors(['passwordData.current_password']);

        $staff->refresh();
        $this->assertTrue(Hash::check('une-phrase-de-passe-solide', $staff->password));
        $this->assertSame($rolesAvant, $staff->memberships()->pluck('role_id')->all());
    }

    public function test_le_personnel_change_son_mot_de_passe_par_le_service_central(): void
    {
        $staff = $this->staff('mot-de-passe-valide@naja7i.ma', ['members.view']);

        $this->actingAs($staff);

        Livewire::test(MonDossier::class)
            ->fillForm([
                'current_password' => 'une-phrase-de-passe-solide',
                'password' => 'Nouvelle-phrase-solide-2026!',
                'password_confirmation' => 'Nouvelle-phrase-solide-2026!',
            ], 'passwordForm')
            ->call('savePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('Nouvelle-phrase-solide-2026!', $staff->fresh()->password));
    }

    public function test_le_dossier_suit_la_locale_arabe_du_personnel(): void
    {
        $staff = $this->staff('arabe-dossier@naja7i.ma', ['members.view']);
        $staff->forceFill(['locale' => 'ar'])->save();

        $this->actingAs($staff)
            ->get(MonDossier::getUrl())
            ->assertOk()
            ->assertSee('ملفي')
            ->assertSee('بيانات الاتصال الخاصة بي');
    }

    public function test_un_compte_suspendu_ne_contourne_pas_la_garde_de_la_page(): void
    {
        $staff = $this->staff('suspendu-dossier@naja7i.ma', ['members.view']);
        $staff->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($staff)
            ->get(MonDossier::getUrl())
            ->assertForbidden();
    }

    /** @param list<string> $permissionCodes */
    private function staff(string $email, array $permissionCodes): User
    {
        $role = Role::create([
            'code' => 'dossier-'.strstr($email, '@', true),
            'label_fr' => 'Gestionnaire',
            'label_ar' => 'مسير',
            'is_staff' => true,
        ]);
        $role->permissions()->attach(Permission::whereIn('code', $permissionCodes)->pluck('id'));

        $user = User::create([
            'email' => $email,
            'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
            'status' => 'active',
        ]);
        $user->identities()->create([
            'provider' => 'password',
            'provider_user_id' => $email,
        ]);
        $user->memberships()->create(['role_id' => $role->id]);

        return $user;
    }

    private function membre(string $email, string $roleCode): User
    {
        $user = User::create([
            'email' => $email,
            'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
            'status' => 'active',
        ]);
        $user->memberships()->create([
            'role_id' => Role::where('code', $roleCode)->whereNull('tenant_id')->value('id'),
        ]);

        return $user;
    }
}
