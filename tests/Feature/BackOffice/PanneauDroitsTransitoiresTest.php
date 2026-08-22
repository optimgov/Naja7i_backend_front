<?php

namespace Tests\Feature\BackOffice;

use App\Filament\Pages\DroitsTransitoiresPoses;
use App\Models\AccessGrantRecord;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TransitionGrantChange;
use App\Models\User;
use App\Services\DroitTransitoireService;
use App\Services\OffreGratuiteService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ajuster et révoquer depuis l'écran — Q-17 confie ces gestes à l'admin
 * commerciale, pas à une ligne de commande.
 */
class PanneauDroitsTransitoiresTest extends TestCase
{
    use RefreshDatabase;

    private User $commerciale;

    private User $editrice;

    private User $candidat;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->commerciale = $this->membre('commerciale-panneau-transition@naja7i.ma', 'finance');
        $this->editrice = $this->membre('editrice-panneau-transition@naja7i.ma', 'editeur');

        $this->candidat = User::create([
            'email' => 'candidat-panneau-transition@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $this->candidat->markEmailAsVerified();
        $this->candidat->grantCandidateRole();
        $this->candidat = $this->candidat->fresh();
        app(OffreGratuiteService::class)->attribuer($this->candidat);

        app(DroitTransitoireService::class)->poser($this->commerciale, [
            /* NOMMÉE : le geste n'a plus de palier par défaut (3A.9 pas 0). */
            'offre' => 'session-180j',
            'motif' => 'Allumage du mur payant, sevrage annoncé.',
        ]);
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
            ->get(DroitsTransitoiresPoses::getUrl(panel: 'admin'))
            ->assertOk();

        $this->flushSession();

        $this->actingAs($this->editrice)
            ->get(DroitsTransitoiresPoses::getUrl(panel: 'admin'))
            ->assertForbidden();
    }

    public function test_l_ecran_ne_liste_que_les_porteurs_d_un_transitoire(): void
    {
        Livewire::actingAs($this->commerciale)
            ->test(DroitsTransitoiresPoses::class)
            ->assertCanSeeTableRecords([$this->candidat])
            ->assertCanNotSeeTableRecords([$this->editrice, $this->commerciale])
            ->assertCanRenderTableColumn('fin')
            ->assertCanRenderTableColumn('etat');
    }

    public function test_revoquer_depuis_l_ecran_clot_le_droit_et_trace_l_auteur(): void
    {
        Livewire::actingAs($this->commerciale)
            ->test(DroitsTransitoiresPoses::class)
            ->callTableAction('revoquer', $this->candidat, [
                'motif' => 'Fin anticipée du sevrage, décision du propriétaire.',
            ])
            ->assertHasNoTableActionErrors();

        $transitoires = AccessGrantRecord::where('user_id', $this->candidat->id)
            ->where('origin', 'transition')->get();

        $this->assertTrue($transitoires->every(fn ($o) => ! $o->ends_at->isFuture()));
        $this->assertSame(
            $this->commerciale->id,
            TransitionGrantChange::query()->first()->actor_id,
        );
    }

    public function test_les_actions_disparaissent_une_fois_le_droit_clos(): void
    {
        app(DroitTransitoireService::class)->revoquer(
            $this->candidat, $this->commerciale, 'Fin anticipée du sevrage, décision du propriétaire.',
        );

        Livewire::actingAs($this->commerciale)
            ->test(DroitsTransitoiresPoses::class)
            ->assertTableActionHidden('revoquer', $this->candidat)
            ->assertTableActionHidden('ajusterLaFin', $this->candidat);
    }

    public function test_l_ecran_refuse_une_revocation_sans_motif(): void
    {
        Livewire::actingAs($this->commerciale)
            ->test(DroitsTransitoiresPoses::class)
            ->callTableAction('revoquer', $this->candidat, ['motif' => 'court'])
            ->assertHasTableActionErrors(['motif']);

        $this->assertSame(0, TransitionGrantChange::query()->count());
    }
}
