<?php

namespace Tests\Feature\BackOffice;

use App\Models\Exam;
use App\Models\Filiere;
use App\Models\Plan;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\Crmef2025Seeder;
use Database\Seeders\PlansSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ce que les semis d'amorçage font quand on les rejoue — M-018 pas 2.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE TEST EXISTE POUR QUE `docs/AMORCAGE.md` NE MENTE PAS
 *
 * Ce document sera suivi sur une machine réelle, par quelqu'un qui ne relira
 * pas le code des semis. Il promet un comportement en cas de rejeu ; si ce
 * comportement change un jour, c'est ici qu'on l'apprend — pas sur la
 * préproduction.
 */
class SemisDAmorcageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());
    }

    public function test_rejouer_le_catalogue_echoue_et_n_ecrit_rien(): void
    {
        $avant = [Filiere::count(), Exam::count()];

        $this->assertGreaterThan(0, $avant[0], 'Le catalogue est semé : sinon ce test mesure le vide.');

        /*
         * IL NE DOUBLE PAS : IL REFUSE.
         *
         * `filieres.slug` est unique et `CatalogueSeeder::run()` est enveloppé
         * dans une transaction. Le premier `create()` d'un rejeu heurte donc
         * l'index, la transaction est annulée en entier, et la base reste
         * exactement dans l'état où elle était.
         *
         * C'est mieux que ce qu'on craignait — mais ce n'est PAS une invitation
         * à rejouer : le refus est un filet, pas une garantie que le geste soit
         * anodin. `docs/AMORCAGE.md` demande de compter avant, et c'est cette
         * discipline-là qui protège, pas l'index.
         */
        try {
            $this->seed(CatalogueSeeder::class);
            $this->fail('Un rejeu du catalogue devrait heurter l’unicité de `filieres.slug`.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('slug', strtolower($e->getMessage()));
        }

        $this->assertSame($avant, [Filiere::count(), Exam::count()], 'Rien n’a été écrit.');
    }

    public function test_rejouer_le_referentiel_crmef_echoue_et_n_ecrit_rien(): void
    {
        $avant = Exam::count();

        try {
            $this->seed(Crmef2025Seeder::class);
            $this->fail('Un rejeu du référentiel devrait heurter l’unicité de `sources.code`.');
        } catch (QueryException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertSame($avant, Exam::count());
    }

    public function test_rejouer_les_offres_est_sans_effet(): void
    {
        /* Celui-ci EST idempotent — `updateOrCreate` — et c'est pourquoi
         * `AMORCAGE.md` le distingue des deux autres : il se rejoue sans
         * précaution, et c'est le seul. */
        $avant = Plan::count();
        $composition = Plan::orderBy('code')->pluck('capabilities', 'code');

        $this->seed(PlansSeeder::class);

        $this->assertSame($avant, Plan::count());
        $this->assertEquals($composition, Plan::orderBy('code')->pluck('capabilities', 'code'));
    }
}
