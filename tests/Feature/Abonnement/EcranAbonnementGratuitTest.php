<?php

namespace Tests\Feature\Abonnement;

use App\Contracts\AccessGrant;
use App\Models\AccessGrantRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OffreGratuiteService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ce que le candidat lit de son palier gratuit.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * DEUX EXIGENCES QUI SE CONTREDISENT EN APPARENCE
 *
 * L'écran doit dire l'enveloppe — sinon le candidat ne sait pas ce qu'il a — et
 * il ne doit rien laisser fuir de la mécanique interne : ni identifiant, ni
 * code d'énumération d'origine. La liste blanche les concilie : un mot du
 * produit (`gratuite` / `achetee`) accompagné de son libellé traduit, et pas
 * une ligne de plus.
 */
class EcranAbonnementGratuitTest extends TestCase
{
    use RefreshDatabase;

    private User $candidat;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->candidat = User::create([
            'email' => 'ecran-gratuit@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $this->candidat->markEmailAsVerified();
        $this->candidat->grantCandidateRole();
        $this->candidat = $this->candidat->fresh();

        app(OffreGratuiteService::class)->attribuer($this->candidat);
    }

    private function etat(): array
    {
        return $this->actingAs($this->candidat)
            ->getJson('/api/v1/me/subscription')
            ->assertOk()
            ->json('data');
    }

    public function test_l_ecran_rend_l_enveloppe_gratuite_sans_terme(): void
    {
        $etat = $this->etat();

        $this->assertContains(AccessGrant::QUESTIONS_ANSWER, $etat['capabilities']);
        $this->assertNull(
            $etat['expires_at'][AccessGrant::QUESTIONS_ANSWER],
            'Sans terme : l’écran ne fabrique pas une date.',
        );

        $this->assertCount(1, $etat['quotas']);
        $enveloppe = $etat['quotas'][0];

        $this->assertSame(AccessGrant::QUESTIONS_ANSWER, $enveloppe['capability']);
        $this->assertSame(10, $enveloppe['granted']);
        $this->assertSame(10, $enveloppe['remaining'], 'Rien ne consomme encore : le reliquat vaut l’enveloppe.');
        $this->assertSame('questions', $enveloppe['unit']);
        $this->assertNull($enveloppe['expires_at']);
    }

    public function test_la_nature_du_droit_est_dite_en_toutes_lettres(): void
    {
        $enveloppe = $this->etat()['quotas'][0];

        $this->assertSame('essai', $enveloppe['source']);
        $this->assertSame('Essai', $enveloppe['source_label']);
        $this->assertSame('questions', $enveloppe['unit_label']);
    }

    public function test_les_libelles_suivent_la_langue_du_candidat(): void
    {
        $this->candidat->update(['locale' => 'ar']);

        $enveloppe = $this->etat()['quotas'][0];

        $this->assertSame('تجربة', $enveloppe['source_label']);
        $this->assertSame('أسئلة', $enveloppe['unit_label']);
    }

    public function test_l_enveloppe_ne_laisse_fuir_aucun_identifiant(): void
    {
        $enveloppe = $this->etat()['quotas'][0];

        $this->assertSame([
            'capability', 'unit', 'unit_label', 'granted', 'remaining',
            'expires_at', 'source', 'source_label',
        ], array_keys($enveloppe));

        foreach (['id', 'uuid', 'user_id', 'origin', 'origin_reference', 'plan_version_id'] as $interne) {
            $this->assertArrayNotHasKey($interne, $enveloppe);
        }
    }

    public function test_un_droit_sans_enveloppe_n_en_invente_pas_une(): void
    {
        AccessGrantRecord::create([
            'user_id' => $this->candidat->id,
            'capability' => AccessGrant::MASTERY_DETAIL,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addMonth(),
            'origin' => 'support',
            'origin_reference' => 'dossier-support-1',
        ]);

        $etat = $this->etat();

        $this->assertContains(AccessGrant::MASTERY_DETAIL, $etat['capabilities']);
        $this->assertCount(
            1, $etat['quotas'],
            'L’illimité est une ABSENCE d’enveloppe, jamais un nombre (ADR-0027).',
        );
    }

    /**
     * DEUX ENVELOPPES PAYANTES NE S'ADDITIONNENT PAS — et depuis l'ADR-0033,
     * ce sont les seules qui peuvent coexister.
     *
     * La version d'origine de ce test faisait cohabiter l'essai et un achat.
     * Ce cas n'existe plus : la conversion clôt l'essai dans la transaction qui
     * ouvre le forfait. Ce qui reste vrai, et que ce test garde, c'est la règle
     * de l'ADR-0031 — deux enveloppes ne se somment jamais — entre droits
     * PAYANTS successifs, où la composition du lot 3A est conservée (D-U).
     */
    public function test_deux_enveloppes_payantes_ne_sont_jamais_additionnees(): void
    {
        AccessGrantRecord::where('user_id', $this->candidat->id)->delete();

        foreach ([[40, 'commande-payante-1'], [120, 'commande-payante-2']] as [$valeur, $reference]) {
            AccessGrantRecord::create([
                'user_id' => $this->candidat->id,
                'capability' => AccessGrant::QUESTIONS_ANSWER,
                'starts_at' => now()->subMinute(),
                'ends_at' => now()->addMonth(),
                'origin' => 'purchase',
                'origin_reference' => $reference,
                'quota_unit' => 'questions',
                'quota_periodicity' => 'cumulative_grant',
                'quota_value' => $valeur,
            ]);
        }

        $quotas = $this->etat()['quotas'];

        $this->assertCount(2, $quotas);
        $this->assertSame([40, 120], array_column($quotas, 'granted'));
        $this->assertSame(['achetee', 'achetee'], array_column($quotas, 'source'));
    }
}
