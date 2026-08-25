<?php

namespace Tests\Feature\Banque;

use App\Contracts\AccessGrant;
use App\Models\ExamFamily;
use App\Models\Plan;
use App\Models\Question;
use App\Services\CouvertureOffre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BanqueTestCrmefTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_lot_couvre_les_matrices_et_est_rejouable(): void
    {
        $this->artisan('naja7i:peupler-banque-test-crmef')->assertSuccessful();
        $this->artisan('naja7i:peupler-banque-test-crmef')->assertSuccessful();

        $questions = Question::where('import_ref', 'like', 'TEST-CRMEF-V1-%')->get();

        $this->assertCount(40, $questions);
        $this->assertSame(25, $questions->where('locale', 'fr')->pluck('competency_node_id')->unique()->count());
        $this->assertSame(6, $questions->where('locale', 'ar')->pluck('competency_node_id')->unique()->count());
        $this->assertTrue($questions->every(fn (Question $q): bool => $q->status === 'published'
            && $q->eligible_for_diagnostic
            && $q->authoring === 'ai_assisted'));
    }

    public function test_finance_est_informee_sans_que_le_coupon_soit_bloque(): void
    {
        $famille = ExamFamily::where('slug', 'crmef')->firstOrFail();
        $plan = Plan::create([
            'code' => 'test-couverture-crmef',
            'name_fr' => 'Test couverture CRMEF',
            'name_ar' => 'اختبار تغطية CRMEF',
            'price_cents' => 1000,
            'currency' => 'MAD',
            'duration_days' => 30,
            'capabilities' => [AccessGrant::QUESTIONS_ANSWER],
            'scope_type' => 'exam_family',
            'scope_uuid' => $famille->uuid,
            'active' => true,
        ]);

        $avant = app(CouvertureOffre::class)->mesurer($plan);
        $this->assertSame(3, $avant['epreuves']);
        $this->assertSame(0, $avant['jouables']);

        $this->artisan('naja7i:peupler-banque-test-crmef')->assertSuccessful();

        $apres = app(CouvertureOffre::class)->mesurer($plan);
        $this->assertSame(3, $apres['jouables']);
        $this->assertSame(40, $apres['questions']);
        $this->assertStringContainsString('Le coupon peut être généré', app(CouvertureOffre::class)->message($plan));
    }

    public function test_la_commande_refuse_la_production(): void
    {
        $ancien = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->artisan('naja7i:peupler-banque-test-crmef')->assertFailed();
        } finally {
            $this->app['env'] = $ancien;
        }

        $this->assertSame(0, Question::where('import_ref', 'like', 'TEST-CRMEF-V1-%')->count());
    }
}
