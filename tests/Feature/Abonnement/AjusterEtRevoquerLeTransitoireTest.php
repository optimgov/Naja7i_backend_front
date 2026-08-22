<?php

namespace Tests\Feature\Abonnement;

use App\Contracts\AccessGrant;
use App\Models\AccessGrantRecord;
use App\Models\Audience;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TransitionGrantChange;
use App\Models\User;
use App\Services\DroitTransitoireService;
use App\Services\OffreGratuiteService;
use App\Support\CapabilityRegistry;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Q-17, « ajustable et révocable » — et la restitution qui suit.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE LA RÉVOCATION DOIT LAISSER DERRIÈRE ELLE
 *
 * Une ligne. « La révocation n'efface pas la ligne : elle la clôt. » On doit
 * pouvoir répondre, dans six mois, à « de quoi disposait ce candidat le
 * 14 mars » — et une ligne supprimée ne répond plus. Ce qui doit disparaître,
 * c'est l'AUTORISATION, pas la trace.
 */
class AjusterEtRevoquerLeTransitoireTest extends TestCase
{
    use RefreshDatabase;

    private User $commerciale;

    private User $candidat;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->commerciale = User::create([
            'email' => 'commerciale-revoque@naja7i.ma', 'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr', 'status' => 'active',
        ]);
        $this->commerciale->markEmailAsVerified();
        $this->commerciale->memberships()->create([
            'role_id' => Role::where('code', 'finance')->whereNull('tenant_id')->value('id'),
        ]);
        $this->commerciale = $this->commerciale->fresh();

        $this->candidat = User::create([
            'email' => 'candidat-revoque@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $this->candidat->markEmailAsVerified();
        $this->candidat->grantCandidateRole();
        $this->candidat = $this->candidat->fresh();

        app(OffreGratuiteService::class)->attribuer($this->candidat);
    }

    private function service(): DroitTransitoireService
    {
        return app(DroitTransitoireService::class);
    }

    private function palier600(): Plan
    {
        return Plan::create([
            'code' => 'palier-600-revoque',
            'audience_id' => Audience::where('code', 'crmef')->value('id'),
            'name_fr' => 'Palier 600', 'name_ar' => 'الباقة 600',
            'price_cents' => 60000, 'currency' => 'MAD', 'duration_days' => 30,
            'capabilities' => CapabilityRegistry::COMMERCIALIZABLE,
            'active' => true, 'position' => 9,
        ]);
    }

    private function poser(array $parametres = []): void
    {
        $this->service()->poser($this->commerciale, $parametres + [
            'offre' => $this->palier600()->code,
            'motif' => 'Allumage du mur payant, sevrage annoncé.',
        ]);
    }

    private function transitoires()
    {
        return AccessGrantRecord::where('user_id', $this->candidat->id)
            ->where('origin', 'transition')->get();
    }

    // ═══ Ajuster ═══════════════════════════════════════════════════════════

    public function test_ajuster_la_fin_aligne_toutes_les_capacites_et_se_journalise(): void
    {
        $this->poser();
        $nouvelle = now()->addDays(20)->startOfDay();

        $touches = $this->service()->ajusterLaFin(
            $this->candidat, $this->commerciale, $nouvelle->toDateString(),
            'Le sevrage est raccourci après arbitrage du propriétaire.',
        );

        $this->assertSame(8, $touches);

        foreach ($this->transitoires() as $octroi) {
            $this->assertSame($nouvelle->toDateString(), $octroi->ends_at->toDateString());
        }

        $traces = TransitionGrantChange::query()->get();
        $this->assertCount(8, $traces, 'L’avant/après de chaque octroi se relit.');
        $this->assertSame(TransitionGrantChange::KIND_ADJUSTED, $traces->first()->kind);
        $this->assertSame($this->commerciale->id, $traces->first()->actor_id);
        $this->assertNotNull($traces->first()->ends_at_before);
        $this->assertStringContainsString('arbitrage', $traces->first()->reason);
    }

    public function test_une_fin_placee_avant_le_debut_est_refusee(): void
    {
        $this->poser();

        $this->expectException(ValidationException::class);

        $this->service()->ajusterLaFin(
            $this->candidat, $this->commerciale, now()->subDay()->toDateString(),
            'Tentative de raccourcir dans le passé.',
        );
    }

    // ═══ Révoquer ══════════════════════════════════════════════════════════

    public function test_revoquer_clot_sans_effacer(): void
    {
        $this->poser();
        $avant = $this->transitoires()->count();

        $clos = $this->service()->revoquer(
            $this->candidat, $this->commerciale, 'Fin anticipée du sevrage, décision du propriétaire.',
        );

        $this->assertSame(8, $clos);
        $this->assertCount($avant, $this->transitoires(), 'La ligne subsiste : on clôt, on n’efface pas.');

        foreach ($this->transitoires() as $octroi) {
            $this->assertNotNull($octroi->ends_at);
            $this->assertFalse($octroi->ends_at->isFuture(), 'L’autorisation a cessé.');
        }

        $this->assertSame(
            TransitionGrantChange::KIND_REVOKED,
            TransitionGrantChange::query()->first()->kind,
        );
    }

    public function test_la_restitution_retombe_immediatement_au_palier_courant(): void
    {
        $this->poser();

        $avant = $this->actingAs($this->candidat)
            ->getJson('/api/v1/me/subscription')->assertOk()->json('data');
        $this->assertContains(AccessGrant::MASTERY_DETAIL, $avant['capabilities']);
        $this->assertCount(2, $avant['droits']);

        $this->service()->revoquer(
            $this->candidat, $this->commerciale, 'Fin anticipée du sevrage, décision du propriétaire.',
        );

        $apres = $this->actingAs($this->candidat)
            ->getJson('/api/v1/me/subscription')->assertOk()->json('data');

        $this->assertNotContains(AccessGrant::MASTERY_DETAIL, $apres['capabilities']);
        $this->assertSame(
            [AccessGrant::QUESTIONS_ANSWER], $apres['capabilities'],
            'Il retombe au palier gratuit qu’il détenait par ailleurs — sans erreur, sans page blanche.',
        );
        $this->assertCount(1, $apres['droits']);
        $this->assertSame('gratuite', $apres['droits'][0]['source']);
        $this->assertSame(40, $apres['quotas'][0]['granted'], 'Son enveloppe n’a jamais bougé.');
    }

    public function test_revoquer_un_droit_non_encore_commence_le_clot_sur_sa_propre_date(): void
    {
        $this->poser(['pose_le' => now()->addWeek()->toDateString()]);

        $this->service()->revoquer(
            $this->candidat, $this->commerciale, 'Annulation avant prise d’effet, changement de calendrier.',
        );

        foreach ($this->transitoires() as $octroi) {
            $this->assertTrue(
                $octroi->ends_at->equalTo($octroi->starts_at),
                'Une période vide : jamais active, et son histoire n’est pas réécrite.',
            );
        }

        $etat = $this->actingAs($this->candidat)
            ->getJson('/api/v1/me/subscription')->assertOk()->json('data');
        $this->assertSame([AccessGrant::QUESTIONS_ANSWER], $etat['capabilities']);
    }

    // ═══ Ce que les gestes ne touchent jamais ══════════════════════════════

    public function test_les_gestes_ne_touchent_que_les_droits_transitoires(): void
    {
        $this->poser();
        $gratuit = AccessGrantRecord::where('user_id', $this->candidat->id)
            ->where('origin', OffreGratuiteService::ORIGINE_INSCRIPTION)->sole();
        $achete = AccessGrantRecord::create([
            'user_id' => $this->candidat->id,
            'capability' => AccessGrant::SIMULATOR_FULL,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonths(3),
            'origin' => 'purchase', 'origin_reference' => 'commande-reelle-1',
        ]);

        $this->service()->revoquer(
            $this->candidat, $this->commerciale, 'Fin anticipée du sevrage, décision du propriétaire.',
        );

        $this->assertNull($gratuit->fresh()->ends_at, 'Le gratuit sans terme est intact.');
        $this->assertTrue($achete->fresh()->ends_at->isFuture(), 'Le droit acheté est intact.');
        $this->assertSame(
            8, TransitionGrantChange::query()->count(),
            'Huit octrois transitoires touchés, et eux seuls.',
        );
    }

    public function test_un_compte_sans_droit_transitoire_ne_se_revoque_pas(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->revoquer(
            $this->candidat, $this->commerciale, 'Tentative sur un compte qui n’a rien reçu.',
        );
    }

    public function test_le_journal_des_ajustements_est_en_ajout_seul(): void
    {
        $this->poser();
        $this->service()->revoquer(
            $this->candidat, $this->commerciale, 'Fin anticipée du sevrage, décision du propriétaire.',
        );
        $trace = TransitionGrantChange::query()->first();

        $this->expectException(QueryException::class);

        DB::table('transition_grant_changes')->where('id', $trace->id)->delete();
    }
}
