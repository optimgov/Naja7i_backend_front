<?php

namespace Tests\Feature\BackOffice;

use App\Contracts\AccessGrant;
use App\Filament\Resources\Plans\Pages\EditPlan;
use App\Filament\Resources\Plans\RelationManagers\VersionsRelationManager;
use App\Models\AccessGrantRecord;
use App\Models\Audience;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\Paiement\CouponGateway;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * §2.6 — lire l'historique d'une offre, et ce qui dépend de chaque version.
 *
 * « C'est cette lecture qui rend le §3 compréhensible plutôt que frustrant. »
 * Une admin commerciale à qui l'on refuse une modification sans lui dire
 * combien de commandes et de droits en dépendent conclut à une panne.
 */
class HistoriqueDesVersionsTest extends TestCase
{
    use RefreshDatabase;

    private Plan $offre;

    private User $commerciale;

    private User $editrice;

    private User $candidat;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->commerciale = $this->membre('commerciale-versions@naja7i.ma', 'finance');
        $this->editrice = $this->membre('editrice-versions@naja7i.ma', 'super_admin');

        $this->candidat = User::create([
            'email' => 'candidat-versions@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $this->candidat->markEmailAsVerified();
        $this->candidat->grantCandidateRole();

        $this->offre = Plan::create([
            'code' => 'historique',
            'audience_id' => Audience::where('code', 'crmef')->value('id'),
            'name_fr' => 'Pack historique', 'name_ar' => 'باقة السجل',
            'price_cents' => 20000, 'currency' => 'MAD', 'duration_days' => 30,
            'capabilities' => [AccessGrant::QUESTIONS_ANSWER],
            'active' => true, 'position' => 1,
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

    private function panneau(User $acteur): Testable
    {
        return Livewire::actingAs($acteur)->test(VersionsRelationManager::class, [
            'ownerRecord' => $this->offre->fresh(),
            'pageClass' => EditPlan::class,
        ]);
    }

    // ═══ Ce que l'historique dit ═══════════════════════════════════════════

    public function test_chaque_version_porte_son_auteur_et_son_champ_declencheur(): void
    {
        $this->actingAs($this->commerciale);
        $this->offre->update(['price_cents' => 60000]);

        $seconde = $this->offre->fresh()->currentVersion()->firstOrFail();

        $this->assertSame($this->commerciale->id, $seconde->composed_by);
        $this->assertSame(['price_cents'], $seconde->triggered_by);
    }

    public function test_deux_champs_changes_ensemble_sont_tous_les_deux_nommes(): void
    {
        $this->actingAs($this->commerciale);
        $this->offre->update(['price_cents' => 30000, 'duration_days' => 60]);

        $this->assertSame(
            ['price_cents', 'duration_days'],
            $this->offre->fresh()->currentVersion()->firstOrFail()->triggered_by,
        );
    }

    public function test_une_version_sans_auteur_humain_ne_s_en_invente_pas_un(): void
    {
        $premiere = $this->offre->currentVersion()->firstOrFail();

        $this->assertNull($premiere->composed_by, 'Aucune session n’a signé cette composition.');
        $this->assertSame([], $premiere->triggered_by, 'Une création n’a pas de champ déclencheur.');
    }

    public function test_l_ecran_compte_les_commandes_et_les_droits_de_chaque_version(): void
    {
        $version = $this->offre->currentVersion()->firstOrFail();
        $coupon = Coupon::create([
            'code' => Coupon::engendrer(), 'plan_id' => $this->offre->id,
            'valid_from' => now()->subDay(), 'valid_until' => now()->addMonth(),
            'max_uses' => 1, 'used_count' => 0, 'status' => 'actif',
        ]);
        $commande = app(CouponGateway::class)->ouvrir(
            $this->candidat, ['code' => $coupon->code], (string) Str::uuid7(),
        );
        app(AbonnementService::class)->honorer($commande);

        $this->assertSame(1, $version->orders()->count());
        $this->assertSame(1, $version->droitsIssus()->count());
        $this->assertSame(1, $version->droitsIssus()->active()->count());

        /* Un droit expiré reste une dépendance, mais n'est plus actif : les
         * deux nombres ne disent pas la même chose, et l'écran rend les deux. */
        AccessGrantRecord::query()->update([
            'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay(),
        ]);

        $this->assertSame(1, $version->droitsIssus()->count());
        $this->assertSame(0, $version->droitsIssus()->active()->count());
    }

    public function test_le_panneau_rend_l_historique_a_l_admin_commerciale(): void
    {
        $this->actingAs($this->commerciale);
        $this->offre->update(['price_cents' => 60000]);

        $this->panneau($this->commerciale)
            ->assertCanSeeTableRecords($this->offre->fresh()->versions()->get())
            ->assertCanRenderTableColumn('composedBy.email')
            ->assertCanRenderTableColumn('triggered_by')
            ->assertCanRenderTableColumn('commandes')
            ->assertCanRenderTableColumn('droits_actifs');
    }

    // ═══ La correction de coquille, depuis l'écran ═════════════════════════

    public function test_l_editrice_corrige_une_coquille_sans_creer_de_version(): void
    {
        $version = $this->offre->currentVersion()->firstOrFail();

        $this->panneau($this->editrice)
            ->callTableAction('corrigerLaCoquille', $version, [
                'champ' => 'name_fr',
                'texte' => 'Pack Historique',
                'motif' => 'Majuscule manquante au nom commercial.',
            ])
            ->assertHasNoTableActionErrors();

        $relue = $version->fresh();
        $this->assertSame('Pack Historique', $relue->name_fr);
        $this->assertSame(1, $this->offre->fresh()->versions()->count());
        $this->assertSame('Pack historique', $relue->editorialFixes()->sole()->before_text);
        $this->assertSame($this->editrice->id, $relue->editorialFixes()->sole()->actor_id);
    }

    public function test_le_bouton_de_correction_n_existe_pas_sans_la_permission(): void
    {
        $version = $this->offre->currentVersion()->firstOrFail();

        $this->panneau($this->commerciale)
            ->assertTableActionHidden('corrigerLaCoquille', $version);

        $this->panneau($this->editrice)
            ->assertTableActionVisible('corrigerLaCoquille', $version);
    }

    public function test_aucune_version_ne_se_cree_ni_ne_se_supprime_depuis_l_ecran(): void
    {
        $version = $this->offre->currentVersion()->firstOrFail();

        $this->assertFalse($this->commerciale->can('delete', $version));
        $this->assertFalse(
            Livewire::actingAs($this->commerciale)
                ->test(VersionsRelationManager::class, [
                    'ownerRecord' => $this->offre, 'pageClass' => EditPlan::class,
                ])
                ->instance()
                ->canCreate(),
        );
    }
}
