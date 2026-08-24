<?php

namespace Tests\Feature\Abonnement;

use App\Models\AccessGrantRecord;
use App\Models\Plan;
use App\Models\QuotaProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EnveloppeDeQuestions;
use App\Services\OffreGratuiteService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Le passage v1.1 : les nouveaux à dix, les anciens à quarante intacts. */
class DiagnosticGratuitDixQuestionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());
    }

    private function candidat(string $email): User
    {
        $candidat = User::create([
            'email' => $email,
            'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
            'status' => 'active',
        ]);
        $candidat->markEmailAsVerified();
        $candidat->grantCandidateRole();

        return $candidat->fresh();
    }

    private function replacerLeGratuitSurQuarante(): Plan
    {
        $offre = Plan::autoGranted()->sole();
        $profilHistorique = QuotaProfile::where('code', 'decouverte')->sole();
        $offre->update(['quota_profile_id' => $profilHistorique->id]);

        return $offre->fresh();
    }

    public function test_une_installation_fraiche_seme_le_diagnostic_a_dix(): void
    {
        $profil = QuotaProfile::where('code', 'decouverte-v11-10')->sole();
        $version = Plan::autoGranted()->sole()->currentVersion()->firstOrFail();

        $this->assertSame(10, $profil->value);
        $this->assertSame(10, $profil->min_value);
        $this->assertSame(120, $profil->max_value);
        $this->assertTrue($profil->active);
        $this->assertSame('decouverte-v11-10', $version->quota_profile_code);
        $this->assertSame(10, $version->quota_value);
    }

    public function test_la_bascule_cree_une_version_et_preserve_le_grant_historique_a_quarante(): void
    {
        $offre = $this->replacerLeGratuitSurQuarante();
        $ancien = $this->candidat('historique-40@naja7i.ma');
        app(OffreGratuiteService::class)->attribuer($ancien);

        $grant = AccessGrantRecord::where('user_id', $ancien->id)->sole();
        $versionHistorique = $offre->currentVersion()->firstOrFail();
        $reliquatAvant = app(EnveloppeDeQuestions::class)->reliquat($grant);
        $versionsAvant = $offre->versions()->count();

        $this->assertSame(40, $grant->quota_value);
        $this->assertSame(40, $reliquatAvant);

        $this->artisan('naja7i:activer-diagnostic-gratuit-v1-1')
            ->expectsOutput('enveloppe_apres=10')
            ->expectsOutput('resultat=activee')
            ->assertSuccessful();

        $courante = $offre->fresh()->currentVersion()->firstOrFail();
        $grantRelu = $grant->fresh();

        $this->assertSame($versionsAvant + 1, $offre->versions()->count());
        $this->assertSame(40, $versionHistorique->fresh()->quota_value);
        $this->assertSame('decouverte-v11-10', $courante->quota_profile_code);
        $this->assertSame(10, $courante->quota_value);
        $this->assertSame($grant->id, $grantRelu->id);
        $this->assertSame(40, $grantRelu->quota_value);
        $this->assertSame($reliquatAvant, app(EnveloppeDeQuestions::class)->reliquat($grantRelu));
    }

    public function test_la_commande_est_previsualisable_et_idempotente(): void
    {
        $offre = $this->replacerLeGratuitSurQuarante();
        $versionsAvant = $offre->versions()->count();

        $this->artisan('naja7i:activer-diagnostic-gratuit-v1-1', ['--dry-run' => true])
            ->expectsOutput('resultat=a_activer')
            ->expectsOutput('mode=sec')
            ->assertSuccessful();

        $this->assertSame($versionsAvant, $offre->versions()->count());
        $this->assertSame(40, $offre->fresh()->currentVersion()->firstOrFail()->quota_value);

        $this->artisan('naja7i:activer-diagnostic-gratuit-v1-1')->assertSuccessful();
        $versionsApres = $offre->versions()->count();

        $this->artisan('naja7i:activer-diagnostic-gratuit-v1-1')
            ->expectsOutput('resultat=deja_active')
            ->assertSuccessful();

        $this->assertSame($versionsApres, $offre->versions()->count());
    }

    public function test_la_commande_globale_ne_depend_pas_d_un_contexte_tenant(): void
    {
        $this->replacerLeGratuitSurQuarante();
        app(TenantContext::class)->forget();

        $this->artisan('naja7i:activer-diagnostic-gratuit-v1-1', ['--dry-run' => true])
            ->expectsOutput('resultat=a_activer')
            ->assertSuccessful();
    }

    public function test_nouvelle_inscription_et_rattrapage_tardif_recoivent_dix_sans_migrer_l_ancien(): void
    {
        $offre = $this->replacerLeGratuitSurQuarante();
        $ancien = $this->candidat('ancien-preserve@naja7i.ma');
        app(OffreGratuiteService::class)->attribuer($ancien);

        $this->artisan('naja7i:activer-diagnostic-gratuit-v1-1')->assertSuccessful();

        $nouveau = $this->candidat('nouveau-10@naja7i.ma');
        app(OffreGratuiteService::class)->attribuer($nouveau);
        $tardif = $this->candidat('tardif-10@naja7i.ma');

        $this->artisan('naja7i:rattraper-le-gratuit')
            ->expectsOutput('poses=1')
            ->expectsOutput('deja_porteurs=2')
            ->assertSuccessful();

        $this->assertSame(40, AccessGrantRecord::where('user_id', $ancien->id)->sole()->quota_value);
        $this->assertSame(10, AccessGrantRecord::where('user_id', $nouveau->id)->sole()->quota_value);
        $this->assertSame(10, AccessGrantRecord::where('user_id', $tardif->id)->sole()->quota_value);
        $this->assertSame(3, AccessGrantRecord::query()->count());
    }
}
