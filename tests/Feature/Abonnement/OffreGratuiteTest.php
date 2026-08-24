<?php

namespace Tests\Feature\Abonnement;

use App\Contracts\AccessGrant;
use App\Enums\QuotaPeriodicity;
use App\Enums\QuotaUnit;
use App\Models\Audience;
use App\Models\Plan;
use App\Models\QuotaProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\QuotaProfileService;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le porteur du gratuit — ADR-0025.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE CE FICHIER TIENT
 *
 * « Le gratuit n'est ni l'absence d'offre, ni un réglage global, ni un paiement
 * nul. Il est porté par une offre gratuite versionnée et auto-attribuée. » Donc :
 * un `Plan` ordinaire, avec une version ordinaire, un instantané de quota
 * ordinaire — et un seul drapeau qui dit par quel chemin il s'obtient.
 *
 * Le prix est RÉELLEMENT zéro. Pas une remise, pas un centime symbolique : un
 * paiement nul ressemblerait à une vente, et l'ADR-0028 l'interdit.
 */
class OffreGratuiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());
    }

    private function gratuite(): Plan
    {
        return Plan::autoGranted()->sole();
    }

    // ═══ L'offre elle-même ═════════════════════════════════════════════════

    public function test_l_offre_gratuite_est_semee_avec_sa_version(): void
    {
        $offre = $this->gratuite();
        $version = $offre->currentVersion()->firstOrFail();

        $this->assertSame(0, $offre->price_cents, 'Zéro réellement : pas une remise.');
        $this->assertNull($offre->duration_days, 'Sans terme : le droit gratuit ne s’éteint pas.');
        $this->assertSame([AccessGrant::QUESTIONS_ANSWER], $offre->capabilities);
        $this->assertTrue($offre->active);
        $this->assertSame(1, $version->version);
    }

    public function test_la_version_gratuite_porte_l_instantane_du_profil_pedagogique(): void
    {
        $version = $this->gratuite()->currentVersion()->firstOrFail();
        $profil = QuotaProfile::where('code', 'decouverte-v11-10')->sole();

        $this->assertSame('decouverte-v11-10', $version->quota_profile_code);
        $this->assertSame(10, $version->quota_value);
        $this->assertSame(10, $version->quota_min_value);
        $this->assertSame(120, $version->quota_max_value);
        $this->assertSame(QuotaUnit::QUESTIONS, $version->quota_unit);
        $this->assertSame(QuotaPeriodicity::CUMULATIVE_GRANT, $version->quota_periodicity);
        $this->assertSame($profil->min_justification, $version->quota_min_justification);
    }

    public function test_le_semis_ne_fixe_aucun_nombre_lui_meme(): void
    {
        $version = $this->gratuite()->currentVersion()->firstOrFail();
        $profil = QuotaProfile::where('code', 'decouverte-v11-10')->sole();

        $this->assertSame(
            $profil->value, $version->quota_value,
            'Le semis SÉLECTIONNE un profil, exactement comme l’écran commercial : '
            .'les quatre nombres restent au registre pédagogique.',
        );
    }

    // ═══ On ne souscrit pas au gratuit ═════════════════════════════════════

    public function test_l_offre_gratuite_n_apparait_pas_au_catalogue_candidat(): void
    {
        $codes = collect($this->getJson('/api/v1/plans')->assertOk()->json('data'))->pluck('code');

        $this->assertNotContains('decouverte-gratuite', $codes->all());
        $this->assertContains('preparation-30j', $codes->all(), 'Les offres payantes y restent.');
        $this->assertCount(3, $codes);
    }

    public function test_elle_reste_active_et_administrable(): void
    {
        $offre = $this->gratuite();

        $this->assertTrue(
            $offre->active,
            'Elle n’est pas retirée de la vente : elle est distribuée. Un rapport qui compte '
            .'les offres actives doit la voir.',
        );
        $this->assertTrue($offre->auto_granted);
        $this->assertSame(1, Plan::query()->where('auto_granted', true)->count());
    }

    public function test_il_ne_peut_exister_qu_un_seul_porteur_du_gratuit(): void
    {
        $this->expectException(QueryException::class);

        Plan::create([
            'code' => 'second-gratuit',
            'audience_id' => Audience::where('code', 'crmef')->value('id'),
            'name_fr' => 'Second gratuit', 'name_ar' => 'مجاني ثان',
            'price_cents' => 0, 'currency' => 'MAD', 'duration_days' => null,
            'capabilities' => [AccessGrant::QUESTIONS_ANSWER],
            'active' => true, 'auto_granted' => true, 'position' => 5,
        ]);
    }

    // ═══ Elle s'administre comme les autres ════════════════════════════════

    public function test_changer_son_profil_de_quota_versionne(): void
    {
        $offre = $this->gratuite();
        $premiere = $offre->currentVersion()->firstOrFail();

        /* Par le service, comme partout : un profil écrit hors de lui serait
         * une borne sans journal (lot 3A.5). */
        $pedagogue = User::create([
            'email' => 'pedagogue-gratuite@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr', 'status' => 'active',
        ]);
        $autre = app(QuotaProfileService::class)->definir($pedagogue, [
            'code' => 'decouverte-large',
            'name_fr' => 'Découverte élargie', 'name_ar' => 'اكتشاف موسع',
            'unit' => QuotaUnit::QUESTIONS, 'periodicity' => QuotaPeriodicity::CUMULATIVE_GRANT,
            'value' => 60, 'min_value' => 40, 'max_value' => 150,
            'min_justification' => 'Sous quarante questions, la carte de maîtrise reste vide sur les épreuves longues.',
            'max_justification' => 'Au-delà de cent cinquante, la découverte cesse d’être un aperçu du produit.',
            'active' => true, 'position' => 20,
        ]);

        $offre->update(['quota_profile_id' => $autre->id]);

        $seconde = $offre->fresh()->currentVersion()->firstOrFail();
        $this->assertNotSame($premiere->id, $seconde->id);
        $this->assertSame(60, $seconde->quota_value);
        $this->assertSame(
            10, $premiere->fresh()->quota_value,
            'La version déjà distribuée garde son enveloppe : personne ne perd rien.',
        );
    }

    public function test_le_drapeau_de_catalogue_ne_versionne_pas(): void
    {
        $offre = $this->gratuite();
        $versionId = $offre->currentVersion()->firstOrFail()->id;

        $offre->update(['auto_granted' => false]);

        $this->assertSame($versionId, $offre->fresh()->current_version_id);
        $this->assertSame(
            1, $offre->versions()->count(),
            'Par quel chemin l’offre s’obtient ne change ni ce qu’on reçoit ni ce qu’on paie.',
        );
    }
}
