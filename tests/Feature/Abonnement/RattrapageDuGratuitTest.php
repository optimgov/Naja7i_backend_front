<?php

namespace Tests\Feature\Abonnement;

use App\Contracts\AccessGrant;
use App\Models\AccessGrantRecord;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OffreGratuiteService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le rattrapage des comptes antérieurs au palier gratuit.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE LA COMMANDE DOIT PROUVER AVANT D'ÊTRE LANCÉE UN JOUR
 *
 * Elle n'est exécutée sur aucune base durable par ce lot. Elle est donc jugée
 * ici, sur une base peuplée : elle pose ce qui manque, ne double rien, se
 * relance sans dégât, et rend un compte rendu CHIFFRÉ — une distribution de
 * droits qu'on ne sait pas compter n'est pas une distribution qu'on ose lancer.
 */
class RattrapageDuGratuitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());
    }

    /** Un compte candidat antérieur au palier gratuit : aucun droit. */
    private function ancienCompte(string $email): User
    {
        $user = User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $user->markEmailAsVerified();
        $user->grantCandidateRole();

        return $user->fresh();
    }

    private function droits(User $user): int
    {
        return AccessGrantRecord::where('user_id', $user->id)->count();
    }

    public function test_la_commande_pose_les_droits_manquants_et_rend_son_compte(): void
    {
        $a = $this->ancienCompte('ancien-a@naja7i.ma');
        $b = $this->ancienCompte('ancien-b@naja7i.ma');
        $deja = $this->ancienCompte('deja@naja7i.ma');
        app(OffreGratuiteService::class)->attribuer($deja);

        $this->artisan('naja7i:rattraper-le-gratuit')
            ->expectsOutput('poses=2')
            ->expectsOutput('deja_porteurs=1')
            ->expectsOutput('examines=3')
            ->expectsOutput('mode=ecriture')
            ->assertSuccessful();

        $this->assertSame(1, $this->droits($a));
        $this->assertSame(1, $this->droits($b));
        $this->assertSame(1, $this->droits($deja), 'Aucun droit doublé.');
    }

    public function test_les_droits_rattrapes_portent_leur_propre_origine(): void
    {
        $compte = $this->ancienCompte('origine@naja7i.ma');

        $this->artisan('naja7i:rattraper-le-gratuit')->assertSuccessful();

        $droit = AccessGrantRecord::where('user_id', $compte->id)->sole();
        $this->assertSame(OffreGratuiteService::ORIGINE_RATTRAPAGE, $droit->origin);
        $this->assertNotSame('purchase', $droit->origin);
        $this->assertSame(AccessGrant::QUESTIONS_ANSWER, $droit->capability);
        $this->assertSame(40, $droit->quota_value);
        $this->assertNull($droit->ends_at);
    }

    public function test_relancer_la_commande_ne_pose_rien_de_plus(): void
    {
        $compte = $this->ancienCompte('relance@naja7i.ma');

        $this->artisan('naja7i:rattraper-le-gratuit')->assertSuccessful();
        $this->artisan('naja7i:rattraper-le-gratuit')
            ->expectsOutput('poses=0')
            ->expectsOutput('deja_porteurs=1')
            ->assertSuccessful();

        $this->assertSame(1, $this->droits($compte));
    }

    public function test_le_mode_sec_compte_sans_rien_ecrire(): void
    {
        $compte = $this->ancienCompte('sec@naja7i.ma');

        $this->artisan('naja7i:rattraper-le-gratuit', ['--dry-run' => true])
            ->expectsOutput('poses=1')
            ->expectsOutput('mode=sec')
            ->assertSuccessful();

        $this->assertSame(0, $this->droits($compte), 'Une prévisualisation n’écrit rien.');
    }

    public function test_un_compte_de_personnel_n_est_pas_concerne(): void
    {
        $personnel = User::create([
            'email' => 'personnel-rattrapage@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr', 'status' => 'active',
        ]);
        $personnel->markEmailAsVerified();
        $personnel->memberships()->create([
            'role_id' => Role::where('code', 'editeur')->whereNull('tenant_id')->value('id'),
        ]);

        $this->artisan('naja7i:rattraper-le-gratuit')
            ->expectsOutput('examines=0')
            ->assertSuccessful();

        $this->assertSame(0, $this->droits($personnel->fresh()));
    }

    public function test_sans_offre_gratuite_la_commande_echoue_au_lieu_de_deviner(): void
    {
        Plan::autoGranted()->sole()->update(['auto_granted' => false]);

        $this->artisan('naja7i:rattraper-le-gratuit')
            ->expectsOutput('aucune_offre_gratuite=1')
            ->assertFailed();
    }

    public function test_le_rattrapage_respecte_le_grandfathering(): void
    {
        $ancien = $this->ancienCompte('grandfather@naja7i.ma');
        app(OffreGratuiteService::class)->attribuer($ancien);

        /* L'offre passe en version 2 après coup. */
        $offre = Plan::autoGranted()->sole();
        $offre->update(['name_fr' => 'Découverte 2026', 'name_ar' => 'الاكتشاف 2026']);
        $this->assertSame(2, $offre->fresh()->versions()->count());

        $this->artisan('naja7i:rattraper-le-gratuit')
            ->expectsOutput('poses=0')
            ->expectsOutput('deja_porteurs=1')
            ->assertSuccessful();

        $this->assertSame(
            1, $this->droits($ancien),
            'Le rattrapage ne migre personne : un compte qui porte déjà le gratuit le garde tel quel.',
        );
    }
}
