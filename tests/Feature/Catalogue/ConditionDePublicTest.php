<?php

namespace Tests\Feature\Catalogue;

use App\Contracts\AccessGrant;
use App\Models\Audience;
use App\Models\Plan;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La condition de public, dite au catalogue — DET-91.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE REFUS ARRIVAIT À LA CAISSE
 *
 * Depuis le lot 3A.9 pas 3, une souscription sur une offre dont le candidat ne
 * relève pas est refusée côté serveur. Le refus est correct, sobre, et il
 * renvoie au catalogue — sauf que le catalogue ne portait AUCUN champ de
 * public. L'écran n'avait donc rien à afficher, quoi qu'il veuille bien faire :
 * une porte qui montre sans ouvrir, et rien pour l'expliquer.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ON DIT LA CONDITION, ON NE CACHE PAS L'OFFRE
 *
 * La route reste publique et complète : un visiteur sans compte lit tout le
 * catalogue, c'est le levier d'acquisition. Filtrer ou ordonner selon le compte
 * connecté est une décision d'ÉCRAN, pas de contrat — elle appartient à M-009.
 * Ce lot livre la donnée, et rien d'autre.
 */
class ConditionDePublicTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());
    }

    /** @return array<string, mixed> */
    private function offreAuCatalogue(string $code): array
    {
        $catalogue = $this->getJson('/api/v1/plans')->assertOk()->json('data');

        $offre = collect($catalogue)->firstWhere('code', $code);

        $this->assertNotNull($offre, "L’offre « {$code} » devrait être au catalogue.");

        return $offre;
    }

    // ═══ Ce que le champ dit ═══════════════════════════════════════════════

    public function test_une_offre_reservee_a_un_public_rend_son_code_et_ses_libelles(): void
    {
        $crmef = Audience::where('code', 'crmef')->sole();

        $offre = $this->offreAuCatalogue('preparation-30j');

        $this->assertSame('crmef', $offre['audience']['code']);
        $this->assertSame($crmef->name_fr, $offre['audience']['label_fr']);
        $this->assertSame($crmef->name_ar, $offre['audience']['label_ar']);

        /* LES DEUX LIBELLÉS, et pas seulement celui de la locale courante : un
         * changement de langue ne doit pas obliger à redemander le catalogue
         * pour une phrase de trois mots. */
        $this->assertNotSame(
            $offre['audience']['label_fr'],
            $offre['audience']['label_ar'],
            'Sans deux libellés distincts, ce test passerait sur un produit monolingue.',
        );
    }

    public function test_une_offre_sans_public_ne_rend_pas_le_champ(): void
    {
        Plan::create([
            'code' => 'tout-public',
            'audience_id' => null,
            'name_fr' => 'Tout public', 'name_ar' => 'للجميع',
            'price_cents' => 9900, 'currency' => 'MAD', 'duration_days' => 30,
            'capabilities' => [AccessGrant::QUESTIONS_ANSWER],
            'active' => true, 'position' => 40,
        ]);

        $offre = $this->offreAuCatalogue('tout-public');

        /*
         * L'ABSENCE DE CONDITION EST L'ABSENCE DU CHAMP.
         *
         * Ni chaîne vide, ni « tous », ni `null`. C'est la règle des murs
         * appliquée au catalogue : un champ vide se lit comme une condition
         * qu'on n'a pas su nommer, et l'écran finirait par afficher une ligne
         * « Réservé à : — » qui n'informe personne.
         */
        $this->assertArrayNotHasKey('audience', $offre);
    }

    // ═══ Ce que ce lot ne change pas ═══════════════════════════════════════

    public function test_la_lecture_publique_du_catalogue_reste_ouverte_et_complete(): void
    {
        /* Sans session : c'est le levier d'acquisition, et la condition
         * affichée ne le referme pas — elle l'explique. */
        $catalogue = $this->getJson('/api/v1/plans')->assertOk()->json('data');

        $this->assertSame(
            Plan::enVente()->count(),
            count($catalogue),
            'Dire la condition ne masque aucune offre : filtrer est une décision d’écran.',
        );

        $codes = array_column($catalogue, 'code');

        foreach (['decouverte-7j', 'preparation-30j', 'session-180j'] as $attendu) {
            $this->assertContains($attendu, $codes);
        }
    }

    public function test_aucune_donnee_de_personne_n_apparait_dans_la_reponse(): void
    {
        $corps = $this->getJson('/api/v1/plans')->assertOk()->json();
        $aplati = json_encode($corps, JSON_UNESCAPED_UNICODE);

        /* La catégorie de public est une donnée de CATALOGUE : un code, deux
         * libellés. Rien de ce qui décrit une personne — ni identifiant, ni
         * compte, ni comptage de candidats — n'a de raison de sortir ici. */
        foreach (['email', 'user_id', 'audience_id', 'candidate', 'naja7i.ma'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $aplati);
        }

        $offre = collect($corps['data'])->firstWhere('code', 'preparation-30j');

        $this->assertSame(
            ['code', 'label_fr', 'label_ar'],
            array_keys($offre['audience']),
            'Liste blanche stricte : un champ ajouté demain à `audiences` ne doit pas '
            .'apparaître ici par accident.',
        );
    }

    public function test_la_condition_annoncee_est_celle_que_la_souscription_opposera(): void
    {
        /*
         * LA MOITIÉ QUI COMPTE : annoncer une condition qui n'est pas celle
         * qu'on appliquera serait pire que ne rien annoncer.
         *
         * `PlanVersionService::purchasable()` appelle `current($plan)`, qui
         * projette `audience_id` depuis l'OFFRE avant de juger. C'est donc la
         * projection de l'offre que la ressource annonce — et un changement de
         * public se voit au catalogue au même instant qu'il devient opposable.
         */
        $lycee = Audience::create([
            'code' => 'lycee', 'name_fr' => 'Lycée', 'name_ar' => 'الثانوي', 'position' => 20,
        ]);

        $offre = Plan::where('code', 'preparation-30j')->sole();
        $offre->update(['audience_id' => $lycee->id]);

        $this->assertSame('lycee', $this->offreAuCatalogue('preparation-30j')['audience']['code']);
        $this->assertSame(
            $lycee->id,
            $offre->fresh()->currentVersion()->firstOrFail()->audience_id,
            'La version composée porte la même condition que celle annoncée.',
        );
    }
}
