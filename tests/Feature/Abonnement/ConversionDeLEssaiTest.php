<?php

namespace Tests\Feature\Abonnement;

use App\Contracts\AccessGrant;
use App\Models\AccessGrantRecord;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\OffreGratuiteService;
use App\Services\Paiement\CouponGateway;
use App\Services\Paiement\SimulatedGateway;
use App\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-0033 — le gratuit est un essai, clos au premier paiement.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE CE FICHIER REND IMPOSSIBLE
 *
 * Trois choses, et chacune serait invisible sans son test :
 *
 * 1. QUE L'ESSAI SURVIVE SOUS UN FORFAIT. `allows()` est un `exists()` sur les
 *    octrois actifs, sans notion de priorité : un essai non clos continuerait
 *    d'ouvrir la capacité, et 3B aurait deux enveloppes à départager.
 * 2. QU'UNE CONVERSION LAISSE UNE FENÊTRE SANS DROIT. Clôture et octroi sont
 *    dans la même transaction : si l'octroi échoue, l'essai est toujours là.
 * 3. QUE L'ÉLIGIBILITÉ REVIENNE. Un forfait finit par expirer ; la garde lit
 *    donc des faits DURABLES, jamais un droit actif.
 */
class ConversionDeLEssaiTest extends TestCase
{
    use RefreshDatabase;

    private User $candidat;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->candidat = User::create([
            'email' => 'converti@naja7i.ma', 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $this->candidat->markEmailAsVerified();
        $this->candidat->grantCandidateRole();
        $this->candidat = $this->candidat->fresh();

        app(OffreGratuiteService::class)->attribuer($this->candidat);
    }

    private function forfait(): Plan
    {
        return Plan::where('code', 'preparation-30j')->sole();
    }

    private function commandeCoupon(): Order
    {
        $coupon = Coupon::create([
            'code' => Coupon::engendrer(),
            'plan_id' => $this->forfait()->id,
            'valid_from' => now()->subDay(), 'valid_until' => now()->addMonth(),
            'max_uses' => 1, 'used_count' => 0, 'status' => 'actif',
        ]);

        return app(CouponGateway::class)->ouvrir(
            $this->candidat, ['code' => $coupon->code], (string) Str::uuid7(),
        );
    }

    private function essais()
    {
        $offre = Plan::autoGranted()->sole();

        return AccessGrantRecord::where('user_id', $this->candidat->id)
            ->whereIn('origin_reference', $offre->versions()->selectRaw('uuid::text'))
            ->get();
    }

    private function etat(): array
    {
        return $this->actingAs($this->candidat)
            ->getJson('/api/v1/me/subscription')->assertOk()->json('data');
    }

    // ═══ S-01 réécrit ══════════════════════════════════════════════════════

    /**
     * S-01 — l'essai ne se retrouve pas après le forfait.
     *
     * La version livrée en M-005 vérifiait l'ancienne règle : un reliquat
     * d'essai survivant à l'achat, puis retrouvé à l'expiration. C'est
     * exactement le cas n°4 du bestiaire — un test vert sur une règle périmée.
     * Réécrit ici sur la règle en vigueur.
     */
    public function test_s01_l_essai_est_clos_par_l_achat_et_ne_revient_pas_a_l_expiration(): void
    {
        $depart = $this->etat();
        $this->assertSame('essai', $depart['etat']);
        $this->assertSame(10, $depart['quotas'][0]['granted']);

        $commande = $this->commandeCoupon();
        app(AbonnementService::class)->honorer($commande);

        // L'essai est clos, définitivement.
        $essai = $this->essais()->sole();
        $this->assertNotNull($essai->ends_at);
        $this->assertFalse($essai->ends_at->isFuture());
        $this->assertStringContainsString(
            OffreGratuiteService::MARQUE_CONVERSION, $essai->note,
            'La ligne close porte la référence de la commande : c’est la preuve de conversion.',
        );

        // Le forfait ouvre ses propres capacités, sans enveloppe d'essai dessous.
        $apresAchat = $this->etat();
        $this->assertSame('actif', $apresAchat['etat']);
        $this->assertSame([], $apresAchat['quotas'], 'Aucun reliquat d’essai ne survit au forfait.');
        $this->assertSame(['achetee'], collect($apresAchat['droits'])->pluck('source')->unique()->all());

        // À l'expiration : épuisé, jamais un retour à l'essai.
        AccessGrantRecord::where('user_id', $this->candidat->id)
            ->where('origin', 'purchase')
            ->update(['starts_at' => now()->subMonths(2), 'ends_at' => now()->subDay()]);

        $expire = $this->etat();
        $this->assertSame('epuise', $expire['etat']);
        $this->assertSame([], $expire['capabilities']);
        $this->assertSame([], $expire['quotas'], 'Jamais un reliquat d’essai retrouvé.');
        $this->assertSame([], $expire['droits']);
        $this->assertNotNull($expire['sortie'], 'Un compte épuisé lit sa sortie, pas une page morte.');
    }

    // ═══ S-17 — l'atomicité ════════════════════════════════════════════════

    public function test_s17_si_l_octroi_payant_echoue_l_essai_reste_actif(): void
    {
        $commande = $this->commandeCoupon();

        /* On fait échouer l'octroi par la serrure de base : un octroi portant
         * déjà la référence de cette commande existe. C'est le cas réel de deux
         * validations concurrentes, et le seul moyen honnête de provoquer
         * l'échec là où il se produirait. */
        AccessGrantRecord::create([
            'user_id' => $this->candidat->id,
            /* Une capacité que le forfait vend réellement — sinon la collision
             * ne se produit pas et le test mesurerait le vide. */
            'capability' => AccessGrant::CAUSE_REVEAL,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addMonth(),
            'origin' => 'purchase',
            'origin_reference' => $commande->uuid,
        ]);

        try {
            app(AbonnementService::class)->honorer($commande);
            $this->fail('L’octroi devait échouer sur la serrure de base.');
        } catch (UniqueConstraintViolationException) {
            // c'est l'échec qu'on voulait provoquer
        }

        $this->assertNull(
            $this->essais()->sole()->ends_at,
            'La clôture est annulée avec l’octroi : aucune fenêtre sans droit.',
        );
        $this->assertSame('en_attente', $commande->fresh()->status);
        $this->assertTrue(
            app(AccessGrant::class)->allows($this->candidat, AccessGrant::QUESTIONS_ANSWER),
            'L’essai autorise toujours : le candidat n’a rien perdu dans l’échec.',
        );

        /* L'état commercial n'est pas asserté ici : l'octroi payant planté pour
         * provoquer la collision survit au rollback — il a été créé HORS de la
         * transaction d'honoration — et le compte se lit donc « actif ». C'est
         * un artefact du montage, pas un fait du produit ; ce que le scénario
         * demande est que l'ESSAI tienne, et c'est ce qui est vérifié. */
    }

    // ═══ S-18 — la non-réattribution ═══════════════════════════════════════

    public function test_s18_un_compte_converti_puis_expire_ne_recoit_aucun_essai_neuf(): void
    {
        app(AbonnementService::class)->honorer($this->commandeCoupon());

        AccessGrantRecord::where('user_id', $this->candidat->id)
            ->where('origin', 'purchase')
            ->update(['starts_at' => now()->subMonths(2), 'ends_at' => now()->subDay()]);

        $this->assertSame('epuise', $this->candidat->fresh()->etatCommercial());

        // Le chemin d'inscription…
        $this->assertFalse(app(OffreGratuiteService::class)->attribuer($this->candidat));

        // …et celui du rattrapage.
        $this->artisan('naja7i:rattraper-le-gratuit')
            ->expectsOutput('poses=0')
            ->expectsOutput('deja_convertis=1')
            ->assertSuccessful();

        $this->assertCount(1, $this->essais(), 'Aucun essai neuf, sur aucun des deux chemins.');
        $this->assertNotNull($this->essais()->sole()->ends_at, 'Et l’ancien reste clos.');
        $this->assertSame('epuise', $this->candidat->fresh()->etatCommercial());
    }

    public function test_un_compte_ayant_paye_sans_jamais_avoir_eu_d_essai_n_en_recoit_pas(): void
    {
        /* Le cas des comptes d'avant l'offre gratuite : ils ont payé, ils n'ont
         * jamais eu d'essai, et le rattrapage ne doit pas leur en offrir un. */
        $ancien = User::create([
            'email' => 'paye-sans-essai@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $ancien->markEmailAsVerified();
        $ancien->grantCandidateRole();
        $ancien = $ancien->fresh();

        $coupon = Coupon::create([
            'code' => Coupon::engendrer(), 'plan_id' => $this->forfait()->id,
            'valid_from' => now()->subDay(), 'valid_until' => now()->addMonth(),
            'max_uses' => 1, 'used_count' => 0, 'status' => 'actif',
        ]);
        app(AbonnementService::class)->honorer(app(CouponGateway::class)->ouvrir(
            $ancien, ['code' => $coupon->code], (string) Str::uuid7(),
        ));

        $this->assertTrue(app(OffreGratuiteService::class)->aDejaConverti($ancien));
        $this->assertFalse(app(OffreGratuiteService::class)->attribuer($ancien));
    }

    // ═══ « Payante » se lit sur la méthode ═════════════════════════════════

    public function test_une_commande_coupon_convertit(): void
    {
        app(AbonnementService::class)->honorer($this->commandeCoupon());

        $this->assertNotNull($this->essais()->sole()->ends_at, 'D-C : le coupon convertit.');
    }

    public function test_un_paiement_simule_ne_convertit_pas(): void
    {
        app(SimulatedGateway::class)->ouvrir(
            $this->candidat, ['plan_code' => $this->forfait()->code], (string) Str::uuid7(),
        );

        $this->assertNull(
            $this->essais()->sole()->ends_at,
            'Le simulé n’existe pas en production : le laisser convertir ferait perdre '
            .'en recette ce qu’aucun candidat n’a acheté.',
        );
    }

    // ═══ Rejeu et concurrence ══════════════════════════════════════════════

    public function test_honorer_deux_fois_ne_clot_qu_une_fois_et_n_octroie_qu_une_serie(): void
    {
        $commande = $this->commandeCoupon();
        $service = app(AbonnementService::class);

        $service->honorer($commande);
        $ferméeLe = $this->essais()->sole()->ends_at;
        $octrois = AccessGrantRecord::where('user_id', $this->candidat->id)
            ->where('origin', 'purchase')->count();

        $service->honorer($commande->fresh());

        $this->assertTrue($this->essais()->sole()->ends_at->equalTo($ferméeLe), 'Une seule clôture.');
        $this->assertSame(
            $octrois,
            AccessGrantRecord::where('user_id', $this->candidat->id)->where('origin', 'purchase')->count(),
            'Une seule série d’octrois.',
        );
    }

    public function test_l_essai_clos_n_est_plus_un_droit_effectif_mais_reste_lisible(): void
    {
        app(AbonnementService::class)->honorer($this->commandeCoupon());

        $etat = $this->etat();

        $this->assertSame(
            [], collect($etat['droits'])->where('source', 'essai')->all(),
            'L’essai clos n’apparaît jamais comme droit effectif.',
        );
        $this->assertCount(1, $this->essais(), 'Mais la ligne subsiste : elle est la preuve.');
    }

    public function test_la_succession_entre_forfaits_payants_ne_change_pas(): void
    {
        app(AbonnementService::class)->honorer($this->commandeCoupon());
        $premier = AccessGrantRecord::where('user_id', $this->candidat->id)
            ->where('capability', AccessGrant::CAUSE_REVEAL)
            ->where('origin', 'purchase')->sole();

        $coupon = Coupon::create([
            'code' => Coupon::engendrer(), 'plan_id' => $this->forfait()->id,
            'valid_from' => now()->subDay(), 'valid_until' => now()->addMonth(),
            'max_uses' => 1, 'used_count' => 0, 'status' => 'actif',
        ]);
        app(AbonnementService::class)->honorer(app(CouponGateway::class)->ouvrir(
            $this->candidat, ['code' => $coupon->code], (string) Str::uuid7(),
        ));

        $second = AccessGrantRecord::where('user_id', $this->candidat->id)
            ->where('capability', AccessGrant::CAUSE_REVEAL)
            ->where('origin', 'purchase')
            ->where('id', '<>', $premier->id)->sole();

        $this->assertTrue(
            $second->starts_at->equalTo($premier->ends_at),
            'D-U : la succession entre forfaits payants est conservée telle quelle.',
        );
    }
}
