<?php

namespace Tests\Feature\Abonnement;

use App\Contracts\AccessGrant;
use App\Exceptions\PaiementRefuse;
use App\Models\AccessGrantRecord;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\Paiement\CouponGateway;
use App\Services\Paiement\SimulatedGateway;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Le chemin de revenu : commande, honoration, octrois.
 *
 * Ce qui est éprouvé ici est ce qui coûte de l'argent quand ça casse — dans un
 * sens comme dans l'autre : un droit ouvert sans contrepartie, ou un droit
 * acheté et non rendu.
 */
class AbonnementTest extends TestCase
{
    use RefreshDatabase;

    private User $candidat;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->candidat = $this->compte('candidat@naja7i.ma');

        $this->plan = Plan::create([
            'code' => 'test-30j',
            'name_fr' => 'Test 30 jours', 'name_ar' => 'اختبار 30 يوما',
            'price_cents' => 19900, 'currency' => 'MAD', 'duration_days' => 30,
            'capabilities' => [AccessGrant::CAUSE_REVEAL, AccessGrant::SERIES_TARGETED],
            'active' => true, 'position' => 1,
        ]);
    }

    private function compte(string $email): User
    {
        $u = User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $u->markEmailAsVerified();
        $u->grantCandidateRole();

        return $u;
    }

    private function coupon(array $remplace = []): Coupon
    {
        return Coupon::create(array_replace([
            'code' => Coupon::engendrer(),
            'plan_id' => $this->plan->id,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addMonth(),
            'max_uses' => 1,
            'used_count' => 0,
            'status' => 'actif',
        ], $remplace));
    }

    private function service(): AbonnementService
    {
        return app(AbonnementService::class);
    }

    // ══════════════════════════════ 1. Une commande honorée pose les octrois

    public function test_une_commande_honoree_produit_un_octroi_par_capacite(): void
    {
        $commande = app(CouponGateway::class)
            ->ouvrir($this->candidat, ['code' => $this->coupon()->code], (string) Str::uuid7());

        $this->service()->honorer($commande, $this->compte('valideur@naja7i.ma'));

        $octrois = AccessGrantRecord::where('user_id', $this->candidat->id)->get();

        $this->assertCount(2, $octrois, 'Un octroi par capacité du plan, pas un de plus.');

        /* `origin_reference` PORTE L'UUID DE LA COMMANDE : c'est ce qui rend la
         * chaîne relisible. Un droit sans commande est un droit qu'on ne sait
         * pas expliquer. */
        $this->assertSame(
            [$commande->uuid, $commande->uuid],
            $octrois->pluck('origin_reference')->all(),
        );
        $this->assertSame(['purchase', 'purchase'], $octrois->pluck('origin')->all());
    }

    public function test_honorer_deux_fois_ne_cree_pas_un_second_octroi(): void
    {
        $commande = app(CouponGateway::class)
            ->ouvrir($this->candidat, ['code' => $this->coupon()->code], (string) Str::uuid7());

        $this->service()->honorer($commande);
        $this->service()->honorer($commande->fresh());
        $this->service()->honorer($commande->fresh());

        $this->assertSame(
            2,
            AccessGrantRecord::where('user_id', $this->candidat->id)->count(),
            'Rejouer une honoration est sans effet : c\'est la règle 2.'
        );
    }

    public function test_le_mur_paye_s_ouvre_a_l_honoration_et_pas_avant(): void
    {
        $droits = app(AccessGrant::class);

        $commande = app(CouponGateway::class)
            ->ouvrir($this->candidat, ['code' => $this->coupon()->code], (string) Str::uuid7());

        $this->assertFalse(
            $droits->allows($this->candidat, AccessGrant::CAUSE_REVEAL),
            'Saisir un coupon n\'ouvre RIEN : un humain doit valider.'
        );

        $this->service()->honorer($commande, $this->compte('valideur@naja7i.ma'));

        $this->assertTrue($droits->allows($this->candidat, AccessGrant::CAUSE_REVEAL));
    }

    public function test_un_refus_n_ouvre_rien(): void
    {
        $commande = app(CouponGateway::class)
            ->ouvrir($this->candidat, ['code' => $this->coupon()->code], (string) Str::uuid7());

        $refusee = $this->service()->refuser(
            $commande, $this->compte('valideur@naja7i.ma'), 'virement non reçu'
        );

        $this->assertSame('annulee', $refusee->status);
        $this->assertSame(0, AccessGrantRecord::where('user_id', $this->candidat->id)->count());
        $this->assertFalse(app(AccessGrant::class)->allows($this->candidat, AccessGrant::CAUSE_REVEAL));
    }

    // ══════════════════════════════════════ 2. Échéance et prolongation

    public function test_l_echeance_se_calcule_a_l_honoration_pas_a_la_commande(): void
    {
        /* Le candidat saisit lundi ; l'équipe valide jeudi. Il doit avoir
         * trente jours PLEINS à partir de jeudi — il ne paie pas la lenteur de
         * l'équipe. */
        $commande = app(CouponGateway::class)
            ->ouvrir($this->candidat, ['code' => $this->coupon()->code], (string) Str::uuid7());

        $this->travel(3)->days();

        $this->service()->honorer($commande->fresh(), $this->compte('valideur@naja7i.ma'));

        $octroi = AccessGrantRecord::where('user_id', $this->candidat->id)->first();

        $this->assertEqualsWithDelta(
            30 * 24 * 3600,
            now()->diffInSeconds($octroi->ends_at, false),
            60,
            'Trente jours à partir de la VALIDATION, pas de la saisie.'
        );

        /* L’horloge est rendue : un temps figé se paie dans un test ULTÉRIEUR. */
        $this->travelBack();
    }

    public function test_la_prolongation_empile_au_lieu_d_ecraser(): void
    {
        $premiere = app(CouponGateway::class)
            ->ouvrir($this->candidat, ['code' => $this->coupon()->code], (string) Str::uuid7());
        $this->service()->honorer($premiere);

        $this->travel(10)->days();

        $seconde = app(CouponGateway::class)
            ->ouvrir($this->candidat, ['code' => $this->coupon()->code], (string) Str::uuid7());
        $this->service()->honorer($seconde);

        $fin = AccessGrantRecord::where('user_id', $this->candidat->id)
            ->where('capability', AccessGrant::CAUSE_REVEAL)
            ->get()->max('ends_at');

        /* Il restait 20 jours, il en achète 30 : il en a 50 devant lui.
         * Écraser en aurait rendu 30 — vingt jours volés. */
        $this->assertEqualsWithDelta(
            50 * 24 * 3600,
            now()->diffInSeconds($fin, false),
            120,
            'Un candidat qui achète deux fois a deux fois.'
        );

        /* L’horloge est rendue : un temps figé se paie dans un test ULTÉRIEUR. */
        $this->travelBack();
    }

    // ═════════════════════════════════════════ 3. Les refus de coupon

    public function test_un_coupon_epuise_est_refuse(): void
    {
        $coupon = $this->coupon(['max_uses' => 1, 'used_count' => 1]);

        try {
            app(CouponGateway::class)->ouvrir($this->candidat, ['code' => $coupon->code], (string) Str::uuid7());
            $this->fail('Un coupon épuisé doit être refusé.');
        } catch (PaiementRefuse $e) {
            $this->assertSame('epuise', $e->motif);
        }
    }

    public function test_un_coupon_expire_est_refuse(): void
    {
        $coupon = $this->coupon(['valid_from' => now()->subMonths(2), 'valid_until' => now()->subDay()]);

        try {
            app(CouponGateway::class)->ouvrir($this->candidat, ['code' => $coupon->code], (string) Str::uuid7());
            $this->fail('Un coupon expiré doit être refusé.');
        } catch (PaiementRefuse $e) {
            $this->assertSame('expire', $e->motif);
        }
    }

    public function test_un_lot_de_coupons_sert_plusieurs_candidats(): void
    {
        $coupon = $this->coupon(['max_uses' => 3]);

        foreach (['a@naja7i.ma', 'b@naja7i.ma', 'c@naja7i.ma'] as $email) {
            app(CouponGateway::class)
                ->ouvrir($this->compte($email), ['code' => $coupon->code], (string) Str::uuid7());
        }

        $this->assertSame(3, $coupon->fresh()->used_count);
        $this->assertSame('epuise', $coupon->fresh()->status);

        $this->expectException(PaiementRefuse::class);
        app(CouponGateway::class)
            ->ouvrir($this->compte('d@naja7i.ma'), ['code' => $coupon->code], (string) Str::uuid7());
    }

    // ═══════════════════════════ 4. Le prix figé, et le paiement simulé

    public function test_le_prix_fige_survit_a_un_changement_de_plan(): void
    {
        $commande = app(CouponGateway::class)
            ->ouvrir($this->candidat, ['code' => $this->coupon()->code], (string) Str::uuid7());

        $this->plan->update(['price_cents' => 99900]);

        $this->assertSame(
            19900,
            $commande->fresh()->amount_cents,
            'Un prix change ; une commande passée ne change pas.'
        );
    }

    public function test_le_paiement_simule_s_instancie_hors_production(): void
    {
        $this->assertInstanceOf(SimulatedGateway::class, app(SimulatedGateway::class));
    }

    public function test_le_paiement_simule_refuse_de_s_instancier_en_production(): void
    {
        /*
         * LA GARDE EST STRUCTURELLE : elle est au CONSTRUCTEUR. Un objet qui ne
         * peut pas exister ne peut pas être appelé par erreur — une commande
         * artisan, un job, un futur contrôleur.
         */
        app()->detectEnvironment(fn () => 'production');

        try {
            $this->expectException(RuntimeException::class);
            new SimulatedGateway($this->service());
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_le_paiement_simule_honore_par_le_meme_chemin(): void
    {
        $commande = app(SimulatedGateway::class)
            ->ouvrir($this->candidat, ['plan_code' => $this->plan->code], (string) Str::uuid7());

        $this->assertSame('honoree', $commande->status);
        $this->assertSame('simule', $commande->method);

        /* Aucun validateur humain : la piste d'audit doit distinguer un droit
         * ouvert par une personne d'un droit ouvert par un automate. */
        $this->assertNull($commande->validated_by);

        $this->assertSame(2, AccessGrantRecord::where('user_id', $this->candidat->id)->count());
    }

    // ═══════════════════════════════════════════ 5. Ce qui est servi

    public function test_le_motif_de_refus_ne_sort_jamais_vers_le_candidat(): void
    {
        $commande = app(CouponGateway::class)
            ->ouvrir($this->candidat, ['code' => $this->coupon()->code], (string) Str::uuid7());

        $this->service()->refuser($commande, $this->compte('valideur@naja7i.ma'), 'coupon revendu sur Facebook');

        $brut = $this->actingAs($this->candidat)
            ->getJson('/api/v1/me/orders')->assertOk()->getContent();

        $this->assertStringNotContainsString('revendu', $brut);
        $this->assertStringNotContainsString('refusal_reason', $brut);
    }

    public function test_les_commandes_d_un_autre_candidat_sont_invisibles(): void
    {
        app(CouponGateway::class)
            ->ouvrir($this->candidat, ['code' => $this->coupon()->code], (string) Str::uuid7());

        $autre = $this->compte('intrus@naja7i.ma');
        $this->flushSession();

        $this->actingAs($autre)
            ->getJson('/api/v1/me/orders')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_l_etat_lit_les_droits_et_non_les_commandes(): void
    {
        $commande = app(CouponGateway::class)
            ->ouvrir($this->candidat, ['code' => $this->coupon()->code], (string) Str::uuid7());

        $enAttente = $this->actingAs($this->candidat)
            ->getJson('/api/v1/me/subscription')->assertOk()->json('data');

        $this->assertSame([], $enAttente['capabilities'], 'Rien n\'est acquis avant validation.');
        $this->assertSame(1, $enAttente['pending_orders']);

        $this->service()->honorer($commande->fresh());

        $apres = $this->actingAs($this->candidat)
            ->getJson('/api/v1/me/subscription')->assertOk()->json('data');

        $this->assertContains(AccessGrant::CAUSE_REVEAL, $apres['capabilities']);
        $this->assertSame(0, $apres['pending_orders']);
    }

    public function test_les_tarifs_sont_publics(): void
    {
        /* Les plans du semis existent aussi : on cherche le NÔTRE plutôt que
         * de supposer une position, qui dépendrait de l'ordre de semis. */
        $plans = collect($this->getJson('/api/v1/plans')->assertOk()->json('data'));

        $mien = $plans->firstWhere('code', 'test-30j');

        $this->assertNotNull($mien, 'Un plan actif est proposé publiquement.');
        $this->assertSame(19900, $mien['price_cents']);
        $this->assertSame('MAD', $mien['currency']);
    }

    public function test_un_plan_desactive_ne_se_vend_plus(): void
    {
        $this->plan->update(['active' => false]);

        $codes = collect($this->getJson('/api/v1/plans')->assertOk()->json('data'))->pluck('code');

        $this->assertNotContains('test-30j', $codes, 'Un plan désactivé ne se vend plus…');
        $this->assertTrue($codes->isNotEmpty(), '…mais les autres restent proposés.');
    }

    public function test_un_plan_sans_capacite_connue_ne_peut_pas_etre_honore(): void
    {
        /* Une faute de frappe dans le tableau JSON du back-office ferait payer
         * un droit que rien ne lit. On refuse d'honorer plutôt que d'ouvrir un
         * abonnement vide. */
        $this->plan->update(['capabilities' => ['capacite.inventee']]);

        $commande = app(CouponGateway::class)
            ->ouvrir($this->candidat, ['code' => $this->coupon()->code], (string) Str::uuid7());

        $this->expectException(RuntimeException::class);
        $this->service()->honorer($commande);
    }

    public function test_un_code_de_coupon_est_lisible_et_non_devinable(): void
    {
        $codes = collect(range(1, 50))->map(fn () => Coupon::engendrer());

        $this->assertCount(50, $codes->unique(), 'Aucune collision sur cinquante tirages.');

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^NJ7(-[A-Z2-9]{4}){3}$/', $code);
            /* Ni O ni 0, ni I ni 1, ni L : un code se dicte au téléphone. */
            $this->assertDoesNotMatchRegularExpression('/[OI1L0]/', substr($code, 4));
        }
    }
}
