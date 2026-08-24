<?php

namespace Tests\Feature\BackOffice;

use App\Filament\Resources\Audiences\AudienceResource;
use App\Filament\Resources\Audiences\Pages\CreateAudience;
use App\Models\Audience;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Les catégories de public, vues de l'écran — premier geste du test n°1.
 *
 * « L'admin commerciale crée une catégorie, un pack, sa version 1, et le met en
 * vente — sans intervention d'un développeur, sans migration. » Ce fichier
 * tient le premier tiers de cette promesse ; le pack et sa mise en vente sont
 * éprouvés par le scénario lycée de bout en bout.
 */
class PanneauCategoriesDePublicTest extends TestCase
{
    use RefreshDatabase;

    private User $commerciale;

    private User $pedagogue;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->commerciale = $this->membre('commerciale-publics@naja7i.ma', 'finance');
        $this->pedagogue = $this->membre('pedagogue-publics@naja7i.ma', 'expert_pedagogue');
    }

    private function membre(string $email, string $role): User
    {
        $user = User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr', 'status' => 'active',
        ]);
        $user->markEmailAsVerified();
        $user->memberships()->create([
            'role_id' => Role::where('code', $role)->whereNull('tenant_id')->value('id'),
        ]);

        return $user->fresh();
    }

    public function test_l_admin_commerciale_cree_une_categorie_sans_developpeur(): void
    {
        Livewire::actingAs($this->commerciale)
            ->test(CreateAudience::class)
            ->fillForm([
                'code' => 'lycee',
                'name_fr' => 'Lycée',
                'name_ar' => 'الثانوي',
                'active' => true,
                'position' => 20,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $lycee = Audience::where('code', 'lycee')->sole();

        $this->assertSame('Lycée', $lycee->name_fr);
        $this->assertSame('الثانوي', $lycee->name_ar);
        $this->assertTrue($lycee->active);
    }

    public function test_le_libelle_arabe_est_exige(): void
    {
        Livewire::actingAs($this->commerciale)
            ->test(CreateAudience::class)
            ->fillForm([
                'code' => 'sans-arabe',
                'name_fr' => 'Sans arabe',
                'name_ar' => '',
            ])
            ->call('create')
            ->assertHasFormErrors(['name_ar']);

        $this->assertNull(Audience::where('code', 'sans-arabe')->first());
    }

    public function test_le_registre_pedagogique_n_ouvre_pas_les_categories_de_public(): void
    {
        $this->actingAs($this->commerciale)
            ->get(AudienceResource::getUrl('index', panel: 'admin'))
            ->assertOk();

        $this->flushSession();

        $this->actingAs($this->pedagogue)
            ->get(AudienceResource::getUrl('index', panel: 'admin'))
            ->assertForbidden();
    }

    public function test_aucune_categorie_ne_se_supprime_depuis_l_ecran(): void
    {
        $crmef = Audience::where('code', 'crmef')->sole();

        $this->assertFalse($this->commerciale->can('delete', $crmef));
        $this->assertFalse($this->commerciale->can('forceDelete', $crmef));
    }
}
