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
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlanVersioningTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private User $candidate;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->candidate = User::create([
            'email' => 'version@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
        ]);
        $this->candidate->markEmailAsVerified();
        $this->candidate->grantCandidateRole();

        $this->plan = Plan::create([
            'code' => 'version-test',
            'name_fr' => 'Version test',
            'name_ar' => 'اختبار النسخة',
            'description_fr' => 'Promesse initiale',
            'description_ar' => 'الوعد الأول',
            'price_cents' => 20000,
            'currency' => 'MAD',
            'duration_days' => 30,
            'capabilities' => [AccessGrant::QUESTIONS_ANSWER],
            'active' => true,
            'position' => 1,
        ]);
    }

    public function test_une_modification_contractuelle_cree_automatiquement_une_version(): void
    {
        $initiale = $this->plan->currentVersion()->firstOrFail();

        $this->plan->update(['price_cents' => 60000]);

        $courante = $this->plan->fresh()->currentVersion()->firstOrFail();
        $this->assertSame(1, $initiale->version);
        $this->assertSame(2, $courante->version);
        $this->assertSame(20000, $initiale->price_cents);
        $this->assertSame(60000, $courante->price_cents);
        $this->assertCount(2, $this->plan->versions()->get());
    }

    public function test_ordre_et_retrait_de_vente_ne_versionnent_pas(): void
    {
        $versionId = $this->plan->currentVersion()->firstOrFail()->id;

        $this->plan->update(['position' => 9, 'active' => false]);

        $this->assertSame($versionId, $this->plan->fresh()->current_version_id);
        $this->assertSame(1, $this->plan->versions()->count());
    }

    public function test_une_commande_est_honoree_selon_sa_version_figee(): void
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
        $order = app(CouponGateway::class)->ouvrir(
            $this->candidate,
            ['code' => $coupon->code],
            (string) Str::uuid7(),
        );
        $promisedVersion = $order->planVersion()->firstOrFail();

        $this->plan->update([
            'duration_days' => 90,
            'capabilities' => [AccessGrant::QUESTIONS_ANSWER, AccessGrant::MASTERY_DETAIL],
        ]);
        app(AbonnementService::class)->honorer($order);

        $grants = AccessGrantRecord::where('user_id', $this->candidate->id)->get();
        $this->assertSame($promisedVersion->id, $order->fresh()->plan_version_id);
        $this->assertSame([AccessGrant::QUESTIONS_ANSWER], $grants->pluck('capability')->all());
        $this->assertTrue($grants->first()->ends_at->equalTo($grants->first()->starts_at->copy()->addDays(30)));
    }

    public function test_une_version_ne_peut_etre_ni_modifiee_ni_supprimee(): void
    {
        $version = $this->plan->currentVersion()->firstOrFail();

        $this->expectException(QueryException::class);
        $version->update(['price_cents' => 1]);
    }

    public function test_une_version_ne_peut_pas_etre_supprimee(): void
    {
        $this->expectException(QueryException::class);

        $this->plan->currentVersion()->firstOrFail()->delete();
    }

    public function test_une_version_affichee_devenue_perimee_est_refusee_sans_substitution(): void
    {
        $displayed = $this->plan->currentVersion()->firstOrFail();
        $coupon = Coupon::create([
            'code' => Coupon::engendrer(),
            'plan_id' => $this->plan->id,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addMonth(),
            'max_uses' => 1,
            'used_count' => 0,
            'status' => 'actif',
        ]);
        $this->plan->update(['description_fr' => 'Nouvelle promesse']);

        try {
            app(CouponGateway::class)->ouvrir(
                $this->candidate,
                ['code' => $coupon->code, 'version_uuid' => $displayed->uuid],
                (string) Str::uuid7(),
            );
            $this->fail('Une version périmée ne doit jamais être remplacée par la version courante.');
        } catch (PaiementRefuse $exception) {
            $this->assertSame('version_indisponible', $exception->motif);
        }

        $this->assertSame(0, $coupon->fresh()->used_count);
    }

    public function test_le_catalogue_expose_l_identifiant_opaque_de_la_version_courante(): void
    {
        $expected = $this->plan->currentVersion()->firstOrFail()->uuid;
        $plans = collect($this->getJson('/api/v1/plans')->assertOk()->json('data'));

        $this->assertSame($expected, $plans->firstWhere('code', $this->plan->code)['version_uuid']);
    }
}
