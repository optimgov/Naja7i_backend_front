<?php

namespace Tests\Feature\BackOffice;

use App\Filament\Pages\DroitTransitoire;
use App\Models\AccessGrantRecord;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TransitionBatch;
use App\Models\User;
use App\Services\OffreGratuiteService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Le droit transitoire, posé depuis l'écran — l'équivalent de la commande.
 *
 * Q-17 dit « geste d'administration », pas « commande d'exploitation » : l'admin
 * commerciale doit pouvoir le poser elle-même, avec la prévisualisation devant
 * les yeux. Ce fichier tient que les deux chemins font la même chose et que
 * l'auteur du geste est celui de la session, jamais un nom passé en argument.
 */
class PanneauDroitTransitoireTest extends TestCase
{
    use RefreshDatabase;

    private User $commerciale;

    private User $editrice;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->commerciale = $this->membre('commerciale-ecran-transition@naja7i.ma', 'finance');
        $this->editrice = $this->membre('editrice-ecran-transition@naja7i.ma', 'editeur');

        $candidat = User::create([
            'email' => 'candidat-ecran-transition@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $candidat->markEmailAsVerified();
        $candidat->grantCandidateRole();
        app(OffreGratuiteService::class)->attribuer($candidat->fresh());
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

    public function test_la_surface_est_reservee_a_l_admin_commerciale(): void
    {
        $this->actingAs($this->commerciale)
            ->get(DroitTransitoire::getUrl(panel: 'admin'))
            ->assertOk();

        $this->flushSession();

        $this->actingAs($this->editrice)
            ->get(DroitTransitoire::getUrl(panel: 'admin'))
            ->assertForbidden();
    }

    public function test_previsualiser_depuis_l_ecran_n_ecrit_rien(): void
    {
        Livewire::actingAs($this->commerciale)
            ->test(DroitTransitoire::class)
            ->callAction('previsualiser', ['offre' => 'session-180j', 'duree' => 60])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSame(0, AccessGrantRecord::where('origin', 'transition')->count());
        $this->assertSame(0, TransitionBatch::query()->count());
    }

    public function test_poser_depuis_l_ecran_trace_l_auteur_de_la_session(): void
    {
        Livewire::actingAs($this->commerciale)
            ->test(DroitTransitoire::class)
            ->callAction('poser', [
                'offre' => 'session-180j',
                'duree' => 60,
                'motif' => 'Allumage du mur payant, sevrage de soixante jours.',
            ])
            ->assertHasNoActionErrors();

        $trace = TransitionBatch::query()->sole();

        $this->assertSame($this->commerciale->id, $trace->actor_id);
        $this->assertSame(1, $trace->accounts_granted);
        $this->assertGreaterThan(0, AccessGrantRecord::where('origin', 'transition')->count());
    }

    public function test_l_ecran_refuse_une_pose_sans_motif(): void
    {
        Livewire::actingAs($this->commerciale)
            ->test(DroitTransitoire::class)
            ->callAction('poser', ['offre' => 'session-180j', 'duree' => 60, 'motif' => ''])
            ->assertHasActionErrors(['motif']);

        $this->assertSame(0, TransitionBatch::query()->count());
    }

    public function test_le_journal_des_poses_s_affiche(): void
    {
        Livewire::actingAs($this->commerciale)
            ->test(DroitTransitoire::class)
            ->callAction('poser', [
                'offre' => 'session-180j',
                'duree' => 60,
                'motif' => 'Allumage du mur payant, sevrage de soixante jours.',
            ]);

        Livewire::actingAs($this->commerciale)
            ->test(DroitTransitoire::class)
            ->assertCanSeeTableRecords(TransitionBatch::query()->get())
            ->assertCanRenderTableColumn('accounts_granted')
            ->assertCanRenderTableColumn('reason');
    }
}
