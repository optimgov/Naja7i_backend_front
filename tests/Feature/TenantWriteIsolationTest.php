<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Exceptions\CrossTenantWriteException;
use App\Tenancy\Exceptions\NoTenantResolvedException;
use App\Tenancy\Exceptions\UnauthorizedTenantBypassException;
use App\Tenancy\TenantBypass;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PAS-1.1 — Les tests que le PAS-1 aurait dû contenir.
 *
 * Le PAS-1 démontrait l'isolation des LECTURES Eloquent ordinaires. Il ne
 * démontrait ni l'isolation des écritures, ni l'impossibilité de contourner
 * le scope. Ces onze cas ferment cet écart.
 */
class TenantWriteIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $platform;

    private Tenant $centre;

    private Role $candidat;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = Tenant::where('kind', 'platform')->firstOrFail();
        $this->centre = Tenant::create(['slug' => 'centre-fes', 'name' => 'Centre de Fès']);
        $this->candidat = Role::where('code', 'candidat')->firstOrFail();

        $this->context()->set($this->platform);

        $this->user = User::create([
            'email' => 'candidat@naja7i.ma', 'password' => 'phrase-de-passe-longue', 'locale' => 'fr',
        ]);
    }

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    // --- BLOC-1 : écritures ---------------------------------------------

    public function test_creer_avec_un_tenant_etranger_est_refuse(): void
    {
        $this->context()->set($this->platform);

        $this->expectException(CrossTenantWriteException::class);

        try {
            Membership::create([
                'tenant_id' => $this->centre->id,
                'user_id' => $this->user->id,
                'role_id' => $this->candidat->id,
            ]);
        } finally {
            // Aucune ligne ne doit avoir été écrite, dans aucun tenant.
            $this->assertSame(0, TenantBypass::run(
                'Vérification de test : aucune ligne créée',
                fn () => Membership::withoutGlobalScope('tenant')->count()
            ));
        }
    }

    public function test_tenant_id_n_est_pas_assignable_en_masse(): void
    {
        $this->context()->set($this->platform);

        // Même en le passant, il est ignoré au profit du tenant courant.
        $membership = new Membership;
        $membership->fill(['user_id' => $this->user->id, 'role_id' => $this->candidat->id]);
        $membership->save();

        $this->assertSame($this->platform->id, $membership->tenant_id);
    }

    public function test_modifier_le_tenant_d_une_ligne_existante_est_refuse(): void
    {
        $this->context()->set($this->platform);
        $membership = Membership::create(['user_id' => $this->user->id, 'role_id' => $this->candidat->id]);

        $this->expectException(CrossTenantWriteException::class);

        $membership->tenant_id = $this->centre->id;
        $membership->save();
    }

    public function test_la_mise_a_jour_massive_du_tenant_est_refusee(): void
    {
        $this->context()->set($this->platform);
        Membership::create(['user_id' => $this->user->id, 'role_id' => $this->candidat->id]);

        // C'est le chemin qui contournait les événements de modèle au PAS-1.
        $this->expectException(CrossTenantWriteException::class);

        Membership::query()->update(['tenant_id' => $this->centre->id]);
    }

    public function test_grant_candidate_role_est_refuse_hors_du_tenant_plateforme(): void
    {
        $this->context()->set($this->centre);

        try {
            $this->user->grantCandidateRole();
            $this->fail('Une exception était attendue sous un tenant centre.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('tenant plateforme', $e->getMessage());
        }

        $this->assertSame(0, TenantBypass::run(
            'Vérification de test : aucune appartenance créée',
            fn () => Membership::withoutGlobalScope('tenant')->count()
        ));
    }

    public function test_creation_normale_sous_le_tenant_courant_fonctionne(): void
    {
        $this->context()->set($this->centre);

        $membership = Membership::create(['user_id' => $this->user->id, 'role_id' => $this->candidat->id]);

        $this->assertSame($this->centre->id, $membership->tenant_id);
    }

    // --- BLOC-2 : contexte ----------------------------------------------

    public function test_le_contexte_ne_survit_pas_a_l_execution_precedente(): void
    {
        $this->context()->set($this->platform);
        $this->assertTrue($this->context()->isResolved());

        // Simule la fin d'un cycle : le conteneur réinitialise les bindings scoped.
        app()->forgetScopedInstances();

        $this->assertFalse(app(TenantContext::class)->isResolved());
        $this->expectException(NoTenantResolvedException::class);
        app(TenantContext::class)->id();
    }

    public function test_run_for_restaure_le_contexte_meme_apres_exception(): void
    {
        $this->context()->set($this->platform);

        try {
            $this->context()->runFor($this->centre, function () {
                throw new \RuntimeException('échec simulé');
            });
        } catch (\RuntimeException) {
            // attendu
        }

        $this->assertSame($this->platform->id, $this->context()->id());
    }

    // --- BLOC-3 : bypass -------------------------------------------------

    public function test_retirer_le_scope_sans_passer_par_le_service_est_refuse(): void
    {
        $this->context()->set($this->platform);

        $this->expectException(UnauthorizedTenantBypassException::class);
        Membership::withoutGlobalScope('tenant')->get();
    }

    public function test_retirer_tous_les_scopes_est_egalement_refuse(): void
    {
        $this->context()->set($this->platform);

        $this->expectException(UnauthorizedTenantBypassException::class);
        Membership::withoutGlobalScopes()->get();
    }

    public function test_un_bypass_sans_raison_explicite_est_refuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TenantBypass::run('fix', fn () => Membership::count());
    }

    public function test_un_bypass_justifie_voit_tous_les_tenants(): void
    {
        $this->context()->set($this->platform);
        Membership::create(['user_id' => $this->user->id, 'role_id' => $this->candidat->id]);
        $this->context()->set($this->centre);
        Membership::create(['user_id' => $this->user->id, 'role_id' => $this->candidat->id]);

        $total = TenantBypass::run(
            'Tâche de support : inventaire global des appartenances',
            fn () => Membership::withoutGlobalScope('tenant')->count()
        );

        $this->assertSame(2, $total);
    }

    // --- Protection structurelle du tenant plateforme --------------------

    public function test_le_tenant_plateforme_ne_peut_pas_etre_supprime(): void
    {
        $this->expectException(QueryException::class);
        \DB::statement('DELETE FROM tenants WHERE id = 1');
    }

    public function test_un_autre_tenant_ne_peut_pas_devenir_plateforme(): void
    {
        $this->expectException(QueryException::class);
        \DB::statement("UPDATE tenants SET kind = 'platform' WHERE id = {$this->centre->id}");
    }

    public function test_le_tenant_plateforme_porte_un_uuid_v7(): void
    {
        // Le 13e caractère hexadécimal porte le numéro de version.
        $uuid = $this->platform->uuid;

        $this->assertSame('7', substr(str_replace('-', '', $uuid), 12, 1));
    }
}
