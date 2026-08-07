<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Exceptions\NoTenantResolvedException;
use App\Tenancy\TenantBypass;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests d'isolation NÉGATIFS (NAJAH-BACK-001 v1.3 §1.3) — exécutés en CI.
 * Chaque nouvelle table isolée devra ajouter son cas ici.
 *
 * PAS-1.1 — Les six cas du PAS-1 sont conservés à l'identique dans leur
 * intention ; seule l'API change (ADR-0006) :
 *  - `TenantContext` n'est plus statique : il se résout depuis le conteneur,
 *    en binding scoped. `clear()` devient `forget()`.
 *  - `acrossAllTenants()` a disparu au profit de `TenantBypass::run()`, seul
 *    point de sortie du scope, avec raison obligatoire et journalisation.
 *  - L'absence de tenant résolu lève une `NoTenantResolvedException` typée,
 *    et non plus une `RuntimeException` nue.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $platform;

    private Tenant $centre;

    private Role $candidat;

    protected function setUp(): void
    {
        parent::setUp();

        // Le tenant plateforme et les rôles sont créés par les migrations.
        $this->platform = Tenant::where('kind', 'platform')->firstOrFail();
        $this->centre = Tenant::create(['slug' => 'centre-fes', 'name' => 'Centre de Fès']);
        $this->candidat = Role::where('code', 'candidat')->firstOrFail();
    }

    protected function tearDown(): void
    {
        $this->context()->forget();
        parent::tearDown();
    }

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    public function test_une_requete_sans_tenant_resolu_echoue_au_lieu_de_tout_retourner(): void
    {
        $this->context()->forget();

        $this->expectException(NoTenantResolvedException::class);
        Membership::count();
    }

    public function test_le_scope_ne_retourne_que_les_donnees_du_tenant_courant(): void
    {
        $user = $this->makeUser('a@naja7i.ma');

        $this->context()->set($this->platform);
        $user->memberships()->create(['role_id' => $this->candidat->id]); // tenant auto-rempli

        $this->context()->set($this->centre);
        $user->memberships()->create(['role_id' => $this->candidat->id]);

        $this->context()->set($this->platform);
        $this->assertSame(1, Membership::count());
        $this->assertSame($this->platform->id, Membership::first()->tenant_id);

        $this->context()->set($this->centre);
        $this->assertSame(1, Membership::count());
        $this->assertSame($this->centre->id, Membership::first()->tenant_id);
    }

    public function test_une_ressource_d_un_autre_tenant_est_introuvable(): void
    {
        $user = $this->makeUser('b@naja7i.ma');

        $this->context()->set($this->centre);
        $membership = $user->memberships()->create(['role_id' => $this->candidat->id]);

        $this->context()->set($this->platform);
        // Introuvable — le futur endpoint répondra 404, jamais 403 (§1.3).
        $this->assertNull(Membership::find($membership->id));
        $this->assertNull(Membership::where('uuid', $membership->uuid ?? '')->first());
    }

    public function test_l_echappement_du_scope_est_explicite_et_voit_tout(): void
    {
        $user = $this->makeUser('c@naja7i.ma');

        $this->context()->set($this->platform);
        $user->memberships()->create(['role_id' => $this->candidat->id]);
        $this->context()->set($this->centre);
        $user->memberships()->create(['role_id' => $this->candidat->id]);

        // L'échappement passe désormais par l'unique point de sortie journalisé.
        $total = TenantBypass::run(
            'Test d\'isolation : inventaire tous tenants confondus',
            fn () => Membership::withoutGlobalScope('tenant')->count()
        );

        $this->assertSame(2, $total);
    }

    public function test_has_role_est_evalue_dans_le_tenant_courant(): void
    {
        $user = $this->makeUser('d@naja7i.ma');

        $this->context()->set($this->platform);
        $user->grantCandidateRole();

        $this->assertTrue($user->hasRole('candidat'));

        $this->context()->set($this->centre);
        $this->assertFalse($user->hasRole('candidat'));
    }

    public function test_l_id_interne_n_est_jamais_serialise(): void
    {
        $this->context()->set($this->platform);
        $user = $this->makeUser('e@naja7i.ma');

        $payload = $user->toArray();

        $this->assertArrayNotHasKey('id', $payload);
        $this->assertArrayHasKey('uuid', $payload);
    }

    private function makeUser(string $email): User
    {
        return User::create([
            'email' => $email,
            'password' => 'secret-pass-123',
            'locale' => 'fr',
        ]);
    }
}
