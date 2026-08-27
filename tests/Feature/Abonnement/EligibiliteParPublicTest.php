<?php

namespace Tests\Feature\Abonnement;

use App\Contracts\AccessGrant;
use App\Models\Audience;
use App\Models\Coupon;
use App\Models\Exam;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Le refus de souscription pour public non éligible — Q-19.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI IL ARRIVE AVEC LES MURS, ET PAS AVANT
 *
 * Le champ existe depuis le lot 3A.6 et il versionne. Ce qui manquait n'était
 * pas le champ mais le CALCUL de la catégorie d'un candidat : le rattachement
 * famille → audience livré en M-004 le rend possible pour la première fois.
 * Le refus est reporté ici parce qu'il ferme une porte, comme le reste de ce
 * lot.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * TROIS CAS, ET LE TROISIÈME EST LE PLUS IMPORTANT
 *
 *   · public connu et DIFFÉRENT → refusé, sobrement ;
 *   · public connu et identique → ouvert ;
 *   · public INCONNU → ouvert. Un compte sans épreuve déclarée n'a pas de
 *     catégorie ; lui en supposer une pour lui refuser un achat serait opposer
 *     une déduction inventée à quelqu'un qui paie.
 */
class EligibiliteParPublicTest extends TestCase
{
    use RefreshDatabase;

    private User $candidat;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->candidat = User::create([
            'email' => 'eligibilite@naja7i.ma', 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $this->candidat->markEmailAsVerified();
        $this->candidat->grantCandidateRole();
        $this->candidat = $this->candidat->fresh();
    }

    /** Le candidat déclare préparer une épreuve du concours CRMEF. */
    private function declarerCrmef(): void
    {
        $this->candidat->candidateProfile()->create([
            'exam_id' => Exam::where('code', 'CRMEF-SE-2025')->value('id'),
        ]);
    }

    /** Une offre qui vise une autre catégorie de public. */
    private function offreLycee(): Plan
    {
        $lycee = Audience::create([
            'code' => 'public-de-test', 'name_fr' => 'Public de test', 'name_ar' => 'جمهور اختباري', 'position' => 20,
        ]);

        return Plan::create([
            'code' => 'suivi-lycee',
            'audience_id' => $lycee->id,
            'name_fr' => 'Suivi lycée', 'name_ar' => 'المتابعة الثانوية',
            'price_cents' => 14900, 'currency' => 'MAD', 'duration_days' => 30,
            'capabilities' => [AccessGrant::QUESTIONS_ANSWER, AccessGrant::CAUSE_REVEAL],
            'active' => true, 'position' => 20,
        ]);
    }

    private function souscrire(string $code): TestResponse
    {
        return $this->actingAs($this->candidat)
            ->postJson('/api/v1/me/orders/simulated', ['plan_code' => $code]);
    }

    // ═══ Le refus ══════════════════════════════════════════════════════════

    public function test_une_souscription_sur_une_offre_d_un_autre_public_est_refusee(): void
    {
        $this->declarerCrmef();
        $offre = $this->offreLycee();

        $this->souscrire($offre->code)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PAIEMENT_REFUSE')
            ->assertJsonPath('error.details.motif', 'public_non_eligible');

        $this->assertSame(0, Order::where('user_id', $this->candidat->id)->count());
        $this->assertSame(0, $this->candidat->accessGrants()->count());
    }

    public function test_le_message_du_refus_est_sobre_et_n_expose_aucun_autre_compte(): void
    {
        $this->declarerCrmef();
        $offre = $this->offreLycee();

        $message = $this->souscrire($offre->code)->assertStatus(422)->json('error.message');

        $this->assertStringContainsString('catalogue', $message);

        /* Ni identifiant, ni adresse, ni comptage : « une autre catégorie » se
         * suffit, et chaque offre porte déjà sa catégorie au catalogue. */
        foreach ([$this->candidat->email, 'public-de-test', 'crmef'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $message);
        }
    }

    public function test_le_refus_vaut_aussi_par_coupon(): void
    {
        $this->declarerCrmef();
        $offre = $this->offreLycee();

        Coupon::create([
            'code' => 'NJ7-LYCEE', 'plan_id' => $offre->id,
            'valid_from' => now()->subDay(), 'valid_until' => now()->addMonth(),
            'max_uses' => 5, 'used_count' => 0, 'status' => 'actif',
        ]);

        /* Les deux moyens passent par `purchasable()` : le contrôle est au
         * point de passage commun, pas recopié dans chaque passerelle. */
        $this->actingAs($this->candidat)
            ->postJson('/api/v1/me/orders/coupon', ['code' => 'NJ7-LYCEE'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.motif', 'public_non_eligible');

        $this->assertSame(0, Order::where('user_id', $this->candidat->id)->count());
    }

    // ═══ Ce que le refus ne doit PAS attraper ══════════════════════════════

    public function test_une_souscription_sur_l_offre_de_son_propre_public_passe(): void
    {
        $this->declarerCrmef();

        $this->souscrire('preparation-30j')->assertStatus(201);

        $this->assertSame('honoree', Order::where('user_id', $this->candidat->id)->sole()->status);
    }

    public function test_un_compte_sans_epreuve_declaree_n_est_pas_refuse(): void
    {
        $offre = $this->offreLycee();

        /* On ne lui suppose aucune catégorie. Le geste ciblé du droit
         * transitoire s'abstient de DONNER dans le même cas ; s'abstenir de
         * VENDRE serait plus grave encore. */
        $this->souscrire($offre->code)->assertStatus(201);
    }

    public function test_une_offre_sans_public_n_est_refusee_a_personne(): void
    {
        $this->declarerCrmef();

        $sansPublic = Plan::create([
            'code' => 'tout-public',
            'audience_id' => null,
            'name_fr' => 'Tout public', 'name_ar' => 'للجميع',
            'price_cents' => 9900, 'currency' => 'MAD', 'duration_days' => 30,
            'capabilities' => [AccessGrant::QUESTIONS_ANSWER],
            'active' => true, 'position' => 30,
        ]);

        $this->souscrire($sansPublic->code)->assertStatus(201);
    }

    // ═══ Le public se lit sur la VERSION, pas sur l'offre courante ═════════

    public function test_la_demande_est_jugee_sur_le_public_de_la_version_vendue(): void
    {
        $this->declarerCrmef();
        $offre = $this->offreLycee();

        /* L'offre change de public : une version neuve naît, et c'est ELLE que
         * la souscription suivante juge. Lire `Plan::audience_id` plutôt que la
         * version referait le défaut V-3 à l'envers — appliquer à une demande
         * d'hier la règle d'aujourd'hui. */
        $offre->update(['audience_id' => Audience::where('code', 'crmef')->value('id')]);

        $this->assertSame(2, $offre->fresh()->versions()->count());
        $this->souscrire($offre->code)->assertStatus(201);
    }
}
