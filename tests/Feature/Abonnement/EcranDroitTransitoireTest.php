<?php

namespace Tests\Feature\Abonnement;

use App\Contracts\AccessGrant;
use App\Models\Audience;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DroitTransitoireService;
use App\Services\OffreGratuiteService;
use App\Support\CapabilityRegistry;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Q-17, « visible » — un sevrage annoncé, jamais subi.
 *
 * Le droit transitoire n'a pas d'enveloppe : il n'apparaîtrait donc nulle part
 * si l'écran ne rendait que des quotas. Ce qu'il faut au candidat, c'est la
 * ligne : ce qu'elle ouvre, comment elle s'appelle, et **quand elle s'arrête**.
 */
class EcranDroitTransitoireTest extends TestCase
{
    use RefreshDatabase;

    private User $candidat;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->candidat = User::create([
            'email' => 'ecran-transition@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $this->candidat->markEmailAsVerified();
        $this->candidat->grantCandidateRole();
        $this->candidat = $this->candidat->fresh();

        app(OffreGratuiteService::class)->attribuer($this->candidat);
    }

    /**
     * Le palier 600 de la matrice — huit capacités commercialisables.
     *
     * Le catalogue semé n'en contient aucun : ses trois offres en composent
     * quatre. On compose donc ici le palier que la matrice décrit, par le chemin
     * normal, plutôt que d'affaiblir l'assertion au niveau du catalogue de
     * démonstration.
     */
    private function palier600(): Plan
    {
        return Plan::create([
            'code' => 'palier-600',
            'audience_id' => Audience::where('code', 'crmef')->value('id'),
            'name_fr' => 'Palier 600', 'name_ar' => 'الباقة 600',
            'price_cents' => 60000, 'currency' => 'MAD', 'duration_days' => 30,
            'capabilities' => CapabilityRegistry::COMMERCIALIZABLE,
            'active' => true, 'position' => 9,
        ]);
    }

    private function poser(int $duree = 60): void
    {
        $commerciale = User::create([
            'email' => 'commerciale-ligne@naja7i.ma', 'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr', 'status' => 'active',
        ]);
        $commerciale->markEmailAsVerified();
        $commerciale->memberships()->create([
            'role_id' => Role::where('code', 'finance')->whereNull('tenant_id')->value('id'),
        ]);

        app(DroitTransitoireService::class)->poser($commerciale->fresh(), [
            'offre' => $this->palier600()->code,
            'duree' => $duree,
            'motif' => 'Allumage du mur payant, sevrage annoncé.',
        ]);
    }

    private function etat(): array
    {
        return $this->actingAs($this->candidat)
            ->getJson('/api/v1/me/subscription')
            ->assertOk()
            ->json('data');
    }

    public function test_avant_la_pose_le_candidat_ne_voit_que_sa_ligne_gratuite(): void
    {
        $lignes = $this->etat()['droits'];

        $this->assertCount(1, $lignes);
        $this->assertSame('gratuite', $lignes[0]['source']);
        $this->assertNull($lignes[0]['expires_at']);
    }

    public function test_la_ligne_transitoire_est_nommee_et_datee(): void
    {
        $this->poser();

        $lignes = collect($this->etat()['droits']);
        $transitoire = $lignes->firstWhere('source', 'transitoire');

        $this->assertNotNull($transitoire, 'Sans ligne, le sevrage se découvre le jour où il tombe.');
        $this->assertSame('Accès transitoire', $transitoire['source_label']);
        $this->assertNotNull($transitoire['expires_at'], 'Q-17 : « avec sa date de fin ».');
        $this->assertSame(
            now()->addDays(60)->toDateString(),
            Carbon::parse($transitoire['expires_at'])->toDateString(),
        );
    }

    public function test_les_deux_lignes_coexistent_sans_se_confondre(): void
    {
        $this->poser();

        $lignes = collect($this->etat()['droits']);

        $this->assertCount(2, $lignes, 'Jamais un état « abonné » unique.');
        $this->assertSame(['transitoire', 'gratuite'], $lignes->pluck('source')->all());

        $gratuite = $lignes->firstWhere('source', 'gratuite');
        $this->assertNull($gratuite['expires_at'], 'Le sans-terme reste sans terme, dessous.');
        $this->assertSame([AccessGrant::QUESTIONS_ANSWER], $gratuite['capabilities']);

        $transitoire = $lignes->firstWhere('source', 'transitoire');
        $this->assertContains(AccessGrant::MASTERY_DETAIL, $transitoire['capabilities']);
        $this->assertNotContains(AccessGrant::CERTIFICATION, $transitoire['capabilities']);
    }

    public function test_l_enveloppe_gratuite_reste_lisible_sous_le_transitoire(): void
    {
        $this->poser();

        $quotas = $this->etat()['quotas'];

        $this->assertCount(1, $quotas, 'Le transitoire n’a pas d’enveloppe : comme tout palier payant.');
        $this->assertSame(40, $quotas[0]['granted']);
        $this->assertSame('gratuite', $quotas[0]['source']);
    }

    public function test_le_libelle_suit_la_langue_du_candidat(): void
    {
        $this->poser();
        $this->candidat->update(['locale' => 'ar']);

        $transitoire = collect($this->etat()['droits'])->firstWhere('source', 'transitoire');

        $this->assertSame('وصول انتقالي', $transitoire['source_label']);
    }

    public function test_la_ligne_ne_laisse_fuir_aucun_identifiant(): void
    {
        $this->poser();

        foreach ($this->etat()['droits'] as $ligne) {
            $this->assertSame(
                ['source', 'source_label', 'expires_at', 'capabilities'],
                array_keys($ligne),
            );
        }
    }
}
