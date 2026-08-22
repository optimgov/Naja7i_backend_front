<?php

namespace Tests\Feature\Abonnement;

use App\Contracts\AccessGrant;
use App\Models\AccessGrantRecord;
use App\Models\Attempt;
use App\Models\Audience;
use App\Models\Exam;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TransitionGrantChange;
use App\Models\User;
use App\Services\DroitTransitoireService;
use App\Services\OffreGratuiteService;
use App\Support\CapabilityRegistry;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * S-14 — le droit transitoire d'un compte existant, de bout en bout.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE CE SCÉNARIO ÉPROUVE QUE LES AUTRES NE VOIENT PAS
 *
 * Les tests des pas 1 à 3 vérifient chaque geste isolément. Celui-ci suit UN
 * COMPTE RÉEL — inscrit avant l'allumage, avec son histoire — du moment où il
 * reçoit le droit transitoire jusqu'à sa révocation, et vérifie qu'à aucune
 * étape il ne perd ce qu'il détenait par ailleurs ni ne voit une page blanche.
 *
 * C'est aussi le test d'acceptation n°11, reporté de M-004 : « l'admin
 * commerciale peut révoquer un droit transitoire ; le candidat le voit
 * disparaître de son écran ».
 */
class ScenarioS14Test extends TestCase
{
    use RefreshDatabase;

    private User $commerciale;

    private User $ancien;

    private Attempt $historique;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->commerciale = User::create([
            'email' => 'commerciale-s14@naja7i.ma', 'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr', 'status' => 'active',
        ]);
        $this->commerciale->markEmailAsVerified();
        $this->commerciale->memberships()->create([
            'role_id' => Role::where('code', 'finance')->whereNull('tenant_id')->value('id'),
        ]);
        $this->commerciale = $this->commerciale->fresh();

        /* Un compte inscrit AVANT l'allumage, avec son histoire : c'est le
         * départ du scénario, et ce que le lot ne doit pas déranger. */
        $this->ancien = User::create([
            'email' => 'ancien-s14@naja7i.ma', 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $this->ancien->markEmailAsVerified();
        $this->ancien->grantCandidateRole();
        $this->ancien = $this->ancien->fresh();
        app(OffreGratuiteService::class)->attribuer($this->ancien);

        $this->historique = Attempt::create([
            'user_id' => $this->ancien->id,
            'exam_id' => Exam::query()->firstOrFail()->id,
            'locale' => 'fr',
            'idempotency_key' => (string) Str::uuid7(),
            'kind' => 'diagnostic',
            'status' => 'in_progress',
            'started_at' => now()->subMonth(),
        ]);
    }

    private function palier600(): Plan
    {
        return Plan::create([
            'code' => 'palier-600-s14',
            'audience_id' => Audience::where('code', 'crmef')->value('id'),
            'name_fr' => 'Palier 600', 'name_ar' => 'الباقة 600',
            'price_cents' => 60000, 'currency' => 'MAD', 'duration_days' => 30,
            'capabilities' => CapabilityRegistry::COMMERCIALIZABLE,
            'active' => true, 'position' => 9,
        ]);
    }

    private function etat(): array
    {
        return $this->actingAs($this->ancien)
            ->getJson('/api/v1/me/subscription')
            ->assertOk()
            ->json('data');
    }

    /**
     * L'ORDRE Q-18, VÉRIFIÉ AU JOURNAL DES PAS.
     *
     * « La correction de `departDe` précède les deux attributions. » Ce n'est
     * pas une préférence de planning : posés dans le mauvais ordre, un droit
     * daté et un droit sans terme produisent le chevauchement du scénario S-08.
     * Le journal des pas est le seul endroit où cet ordre est vérifiable après
     * coup — on l'y vérifie donc, plutôt que de l'affirmer au retour.
     */
    public function test_l_ordre_q18_est_verifiable_au_journal_des_pas(): void
    {
        $journal = file_get_contents(base_path('docs/PAS.md'));

        $correctionV2 = strpos($journal, '| 3A.1 |');
        $posePremiere = strpos($journal, '| 3A.8.1 |');
        $gratuitPose = strpos($journal, '| 3A.7.2 |');

        $this->assertNotFalse($correctionV2, 'Le correctif V-2 doit être inscrit au journal.');
        $this->assertNotFalse($posePremiere);
        $this->assertNotFalse($gratuitPose);
        $this->assertLessThan(
            $gratuitPose, $correctionV2,
            'Q-18 : `departDe` est corrigé avant l’attribution du gratuit.',
        );
        $this->assertLessThan(
            $posePremiere, $correctionV2,
            'Q-18 : `departDe` est corrigé avant la pose du droit transitoire.',
        );
    }

    public function test_s14_de_la_pose_a_la_revocation(): void
    {
        // ═══ Départ : le compte ne porte que son palier gratuit ════════════
        $depart = $this->etat();
        $this->assertSame([AccessGrant::QUESTIONS_ANSWER], $depart['capabilities']);
        $this->assertCount(1, $depart['droits']);

        // ═══ Geste 1 : l'admin commerciale pose le droit transitoire ═══════
        $trace = app(DroitTransitoireService::class)->poser($this->commerciale, [
            'offre' => $this->palier600()->code,
            'motif' => 'Allumage du mur payant : soixante jours de transition annoncés.',
        ]);

        $this->assertSame(1, $trace->accounts_granted);

        $transitoires = AccessGrantRecord::where('user_id', $this->ancien->id)
            ->where('origin', 'transition')->get();

        // Il porte les capacités du palier 600…
        $attendues = CapabilityRegistry::COMMERCIALIZABLE;
        sort($attendues);
        $this->assertSame($attendues, $transitoires->pluck('capability')->sort()->values()->all());
        // …et jamais la certification.
        $this->assertNotContains(AccessGrant::CERTIFICATION, $transitoires->pluck('capability')->all());

        // Son origine est distincte de `purchase` et n'entre dans aucun agrégat.
        $this->assertSame(['transition'], $transitoires->pluck('origin')->unique()->all());
        $this->assertSame(0, Order::query()->count());

        // Il apparaît sur l'écran, nommé et daté.
        $pose = $this->etat();
        $transitoire = collect($pose['droits'])->firstWhere('source', 'transitoire');
        $this->assertNotNull($transitoire);
        $this->assertSame('Accès transitoire', $transitoire['source_label']);
        $this->assertNotNull($transitoire['expires_at']);
        $this->assertContains(AccessGrant::MASTERY_DETAIL, $pose['capabilities']);

        // Le gratuit sans terme et son enveloppe coexistent dessous, intacts.
        $gratuite = collect($pose['droits'])->firstWhere('source', 'essai');
        $this->assertNull($gratuite['expires_at']);
        $this->assertSame(40, $pose['quotas'][0]['granted']);

        // Son histoire n'a pas bougé.
        $this->assertSame('in_progress', $this->historique->fresh()->status);

        // ═══ Geste 2 : l'admin commerciale révoque ═════════════════════════
        $clos = app(DroitTransitoireService::class)->revoquer(
            $this->ancien, $this->commerciale, 'Sevrage écourté après arbitrage du propriétaire.',
        );

        $this->assertSame(8, $clos);

        // Clos, jamais effacé : les huit lignes subsistent.
        $this->assertCount(
            8,
            AccessGrantRecord::where('user_id', $this->ancien->id)->where('origin', 'transition')->get(),
        );
        $this->assertSame(8, TransitionGrantChange::query()->count());

        // Le candidat le voit disparaître de son écran, sans erreur ni page blanche.
        $apres = $this->etat();
        $this->assertSame([AccessGrant::QUESTIONS_ANSWER], $apres['capabilities']);
        $this->assertCount(1, $apres['droits']);
        $this->assertSame('essai', $apres['droits'][0]['source']);

        // La restitution retombe au palier courant : l'enveloppe est intacte.
        $this->assertSame(40, $apres['quotas'][0]['granted']);
        $this->assertSame(40, $apres['quotas'][0]['remaining']);

        // Et son histoire n'a toujours pas bougé.
        $this->assertSame('in_progress', $this->historique->fresh()->status);
    }
}
