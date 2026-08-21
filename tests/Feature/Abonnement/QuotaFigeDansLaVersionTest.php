<?php

namespace Tests\Feature\Abonnement;

use App\Contracts\AccessGrant;
use App\Enums\QuotaPeriodicity;
use App\Enums\QuotaUnit;
use App\Models\AccessGrantRecord;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\QuotaProfile;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\Paiement\CouponGateway;
use App\Services\QuotaProfileService;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * P-Q — Ce que la version fige du profil, et ce qu'amender ne touche plus.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE DÉFAUT SOUS SURVEILLANCE
 *
 * `QuotaProfile` est amendable par construction : c'est le registre pédagogique
 * qui permet de calibrer la découverte sans déploiement (DET-89). La question
 * n'est donc pas « peut-on changer la valeur », mais « qu'arrive-t-il à ce qui
 * a DÉJÀ été vendu ». La réponse tenue ici : rien. La version porte
 * l'instantané, l'honoration l'ouvre, et le profil courant n'est plus jamais
 * relu en aval.
 */
class QuotaFigeDansLaVersionTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private QuotaProfile $profil;

    private User $candidate;

    private User $pedagogue;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->profil = QuotaProfile::where('code', 'decouverte')->firstOrFail();

        $this->candidate = User::create([
            'email' => 'quota-fige@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
        ]);
        $this->candidate->markEmailAsVerified();
        $this->candidate->grantCandidateRole();

        $this->pedagogue = User::create([
            'email' => 'pedagogue-quota-fige@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
            'status' => 'active',
        ]);
        $this->pedagogue->markEmailAsVerified();
        $this->pedagogue->memberships()->create([
            'role_id' => Role::where('code', 'editeur')->whereNull('tenant_id')->value('id'),
        ]);

        $this->plan = Plan::create([
            'code' => 'quota-fige',
            'name_fr' => 'Découverte encadrée',
            'name_ar' => 'اكتشاف مؤطر',
            'description_fr' => 'Promesse initiale',
            'description_ar' => 'الوعد الأول',
            'price_cents' => 0,
            'currency' => 'MAD',
            'duration_days' => 30,
            'capabilities' => [AccessGrant::QUESTIONS_ANSWER, AccessGrant::MASTERY_DETAIL],
            'quota_profile_id' => $this->profil->id,
            'active' => true,
            'position' => 1,
        ]);
    }

    /** Amender la valeur ET les deux bornes, avec des raisons neuves. */
    private function amenderLeProfil(): QuotaProfile
    {
        return app(QuotaProfileService::class)->amender($this->profil, $this->pedagogue, [
            'value' => 100,
            'min_value' => 60,
            'max_value' => 200,
            'min_justification' => 'La banque a doublé depuis la première calibration : '
                .'sous soixante questions, la carte de maîtrise reste vide sur les épreuves longues.',
            'max_justification' => 'Deux cents questions restent un aperçu sur une banque '
                .'de cette taille, et le palier payant garde de quoi se vendre.',
        ]);
    }

    // ═══ La composition copie, elle ne pointe pas ══════════════════════════

    public function test_la_composition_copie_le_profil_dans_la_version(): void
    {
        $version = $this->plan->currentVersion()->firstOrFail();

        $this->assertSame($this->profil->id, $version->quota_profile_id);
        $this->assertSame('decouverte', $version->quota_profile_code);
        $this->assertSame(QuotaUnit::QUESTIONS, $version->quota_unit);
        $this->assertSame(QuotaPeriodicity::CUMULATIVE_GRANT, $version->quota_periodicity);
        $this->assertSame(40, $version->quota_value);
        $this->assertSame(35, $version->quota_min_value);
        $this->assertSame(120, $version->quota_max_value);
        $this->assertSame($this->profil->min_justification, $version->quota_min_justification);
        $this->assertSame($this->profil->max_justification, $version->quota_max_justification);
    }

    public function test_une_offre_sans_profil_ne_pose_aucune_enveloppe(): void
    {
        $sansQuota = Plan::create([
            'code' => 'sans-quota',
            'name_fr' => 'Sans enveloppe',
            'name_ar' => 'بدون غلاف',
            'price_cents' => 60000,
            'currency' => 'MAD',
            'duration_days' => 30,
            'capabilities' => [AccessGrant::QUESTIONS_ANSWER],
            'active' => true,
            'position' => 2,
        ]);

        $version = $sansQuota->currentVersion()->firstOrFail();

        $this->assertNull($version->quota_value);
        $this->assertFalse($version->poseUneEnveloppe());
        $this->assertSame([], $version->enveloppePour(AccessGrant::QUESTIONS_ANSWER));
    }

    // ═══ Amender le profil ne touche pas ce qui est déjà vendu ═════════════

    public function test_amender_le_profil_ne_touche_aucune_version_existante(): void
    {
        $version = $this->plan->currentVersion()->firstOrFail();

        $this->amenderLeProfil();

        $relue = $version->fresh();
        $this->assertSame(40, $relue->quota_value);
        $this->assertSame(35, $relue->quota_min_value);
        $this->assertSame(120, $relue->quota_max_value);
        $this->assertSame(1, $this->plan->fresh()->versions()->count());
    }

    public function test_le_profil_amende_ne_sert_qu_aux_compositions_futures(): void
    {
        $premiere = $this->plan->currentVersion()->firstOrFail();

        $this->amenderLeProfil();
        $this->plan->update(['price_cents' => 12000]);

        $seconde = $this->plan->fresh()->currentVersion()->firstOrFail();
        $this->assertSame(40, $premiere->fresh()->quota_value);
        $this->assertSame(100, $seconde->quota_value);
        $this->assertSame(60, $seconde->quota_min_value);
        $this->assertNotSame($premiere->id, $seconde->id);
    }

    // ═══ L'honoration lit l'instantané, jamais le registre ═════════════════

    public function test_l_honoration_ouvre_l_enveloppe_figee_par_la_version(): void
    {
        $coupon = Coupon::create([
            'code' => Coupon::engendrer(),
            'plan_id' => $this->plan->id,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addMonth(),
            'max_uses' => 1,
            'used_count' => 0,
            'status' => 'actif',
        ]);
        $commande = app(CouponGateway::class)->ouvrir(
            $this->candidate,
            ['code' => $coupon->code],
            (string) Str::uuid7(),
        );

        $this->amenderLeProfil();
        app(AbonnementService::class)->honorer($commande);

        $droits = AccessGrantRecord::where('user_id', $this->candidate->id)->get();
        $compté = $droits->firstWhere('capability', AccessGrant::QUESTIONS_ANSWER);
        $autre = $droits->firstWhere('capability', AccessGrant::MASTERY_DETAIL);

        $this->assertSame(40, $compté->quota_value, 'Le droit ouvert doit porter la valeur VENDUE, pas la valeur courante.');
        $this->assertSame(QuotaUnit::QUESTIONS, $compté->quota_unit);
        $this->assertSame(QuotaPeriodicity::CUMULATIVE_GRANT, $compté->quota_periodicity);
        $this->assertNull($autre->quota_value, 'Une capacité que l’unité ne compte pas s’ouvre sans enveloppe.');
        $this->assertSame(100, $this->profil->fresh()->value, 'Le registre, lui, a bien été amendé.');
    }

    // ═══ Les gardes de la sélection ════════════════════════════════════════

    public function test_un_profil_retire_de_la_selection_ne_se_compose_plus(): void
    {
        app(QuotaProfileService::class)->amender($this->profil, $this->pedagogue, ['active' => false]);

        $this->expectException(ValidationException::class);

        Plan::create([
            'code' => 'quota-retire',
            'name_fr' => 'Profil retiré',
            'name_ar' => 'نمط مسحوب',
            'price_cents' => 0,
            'currency' => 'MAD',
            'duration_days' => 30,
            'capabilities' => [AccessGrant::QUESTIONS_ANSWER],
            'quota_profile_id' => $this->profil->id,
            'active' => true,
            'position' => 3,
        ]);
    }

    public function test_un_profil_qui_borne_une_capacite_non_vendue_est_refuse(): void
    {
        try {
            Plan::create([
                'code' => 'quota-hors-sujet',
                'name_fr' => 'Hors sujet',
                'name_ar' => 'خارج الموضوع',
                'price_cents' => 60000,
                'currency' => 'MAD',
                'duration_days' => 30,
                'capabilities' => [AccessGrant::MASTERY_DETAIL],
                'quota_profile_id' => $this->profil->id,
                'active' => true,
                'position' => 4,
            ]);
            $this->fail('Une enveloppe sans capacité vendue ne compte rien : la sélection doit être refusée.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                AccessGrant::QUESTIONS_ANSWER,
                $exception->validator->errors()->first('quota_profile_id'),
            );
        }
    }

    // ═══ L'instantané est du contrat, donc immuable ════════════════════════

    public function test_l_instantane_est_couvert_par_l_immuabilite_des_versions(): void
    {
        $version = $this->plan->currentVersion()->firstOrFail();

        $this->expectException(QueryException::class);

        DB::table('plan_versions')->where('id', $version->id)->update(['quota_value' => 500]);
    }
}
