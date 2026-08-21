<?php

namespace Tests\Feature\Abonnement;

use App\Contracts\AccessGrant;
use App\Models\Plan;
use App\Models\Tenant;
use App\Support\CapabilityRegistry;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CapabilityRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());
    }

    public function test_le_registre_contient_neuf_capacites_dont_huit_commercialisables(): void
    {
        $this->assertCount(9, CapabilityRegistry::ALL);
        $this->assertCount(9, array_unique(CapabilityRegistry::ALL));
        $this->assertCount(8, CapabilityRegistry::COMMERCIALIZABLE);
        $this->assertNotContains(AccessGrant::CERTIFICATION, CapabilityRegistry::COMMERCIALIZABLE);

        $this->assertSame(
            CapabilityRegistry::ALL,
            DB::table('capability_definitions')->orderBy('position')->pluck('code')->all(),
        );
    }

    public function test_chaque_capacite_a_un_referentiel_bilingue_complet(): void
    {
        $definitions = DB::table('capability_definitions')->get();

        $this->assertCount(9, $definitions);

        foreach ($definitions as $definition) {
            $this->assertNotSame('', trim($definition->label_fr));
            $this->assertNotSame('', trim($definition->label_ar));
            $this->assertNotSame('', trim($definition->description_fr));
            $this->assertNotSame('', trim($definition->description_ar));
            $this->assertTrue($definition->a_relire);
        }
    }

    public function test_la_certification_est_refusee_hors_filament(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('certification.take');

        Plan::create([
            'code' => 'certification-interdite',
            'name_fr' => 'Certification',
            'name_ar' => 'شهادة',
            'price_cents' => 60000,
            'currency' => 'MAD',
            'duration_days' => 30,
            'capabilities' => [AccessGrant::CERTIFICATION],
            'active' => true,
            'position' => 99,
        ]);
    }

    public function test_le_catalogue_public_ne_livre_pas_un_code_sans_libelle(): void
    {
        Plan::create([
            'code' => 'registre-public',
            'name_fr' => 'Offre registre',
            'name_ar' => 'عرض السجل',
            'price_cents' => 20000,
            'currency' => 'MAD',
            'duration_days' => 30,
            'capabilities' => [AccessGrant::QUESTIONS_ANSWER],
            'active' => true,
            'position' => 98,
        ]);

        $plan = collect($this->getJson('/api/v1/plans')->assertOk()->json('data'))
            ->firstWhere('code', 'registre-public');

        $this->assertSame(['questions.answer'], $plan['capabilities']);
        $this->assertSame('questions.answer', $plan['capability_details'][0]['code']);
        $this->assertSame('Répondre aux questions', $plan['capability_details'][0]['label']);
        $this->assertNotSame('', $plan['capability_details'][0]['description']);
    }
}
