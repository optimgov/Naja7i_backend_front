<?php

namespace Tests\Feature\BackOffice;

use App\Contracts\AccessGrant;
use App\Filament\Resources\CapabilityDefinitions\CapabilityDefinitionResource;
use App\Filament\Resources\CapabilityDefinitions\Pages\EditCapabilityDefinition;
use App\Models\CapabilityDefinition;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Filament\Schemas\Schema;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La table d'affichage des capacités — le code décide, le libellé s'édite.
 *
 * Le référentiel lui-même est livré depuis le lot 3A.3 ; ce fichier tient les
 * trois choses que le lot commercial y ajoute : la présentation s'édite sans
 * développeur, la liste des codes reste fermée, et une capacité privée de son
 * libellé n'est plus composable — un code brut ne doit jamais atteindre un
 * écran candidat.
 */
class PanneauLibellesDesCapacitesTest extends TestCase
{
    use RefreshDatabase;

    private User $commerciale;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->commerciale = User::create([
            'email' => 'commerciale-libelles@naja7i.ma', 'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr', 'status' => 'active',
        ]);
        $this->commerciale->markEmailAsVerified();
        $this->commerciale->memberships()->create([
            'role_id' => Role::where('code', 'finance')->whereNull('tenant_id')->value('id'),
        ]);
        $this->commerciale = $this->commerciale->fresh();
    }

    // ═══ Ce que l'écran permet ═════════════════════════════════════════════

    public function test_editer_un_libelle_retire_le_badge_a_relire(): void
    {
        $definition = CapabilityDefinition::findOrFail(AccessGrant::MASTERY_DETAIL);
        $this->assertTrue($definition->a_relire, 'Un libellé semé par une migration est provisoire.');

        Livewire::actingAs($this->commerciale)
            ->test(EditCapabilityDefinition::class, ['record' => $definition->code])
            ->fillForm(['label_fr' => 'Carte de maîtrise détaillée'])
            ->call('save')
            ->assertHasNoFormErrors();

        $relue = $definition->fresh();
        $this->assertSame('Carte de maîtrise détaillée', $relue->label_fr);
        $this->assertFalse($relue->a_relire, 'Enregistrer depuis l’écran EST le geste humain qui confirme.');
    }

    // ═══ Ce que l'écran ne permet pas ══════════════════════════════════════

    public function test_aucun_ecran_ne_cree_ni_ne_supprime_une_capacite(): void
    {
        $definition = CapabilityDefinition::findOrFail(AccessGrant::QUESTIONS_ANSWER);

        $this->assertFalse($this->commerciale->can('create', CapabilityDefinition::class));
        $this->assertFalse($this->commerciale->can('delete', $definition));
        $this->assertArrayNotHasKey('create', CapabilityDefinitionResource::getPages());
    }

    public function test_la_base_refuse_un_code_de_capacite_inconnu(): void
    {
        $this->expectException(QueryException::class);

        DB::table('capability_definitions')->insert([
            'code' => 'certification.virtuelle',
            'label_fr' => 'Certification virtuelle', 'label_ar' => 'شهادة افتراضية',
            'description_fr' => 'Inventée.', 'description_ar' => 'مخترعة.',
            'position' => 999, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_le_code_n_est_pas_modifiable_depuis_le_formulaire(): void
    {
        $champ = collect(CapabilityDefinitionResource::form(
            Schema::make()
        )->getComponents(withHidden: true))
            ->first(fn ($composant) => method_exists($composant, 'getName') && $composant->getName() === 'code');

        $this->assertNotNull($champ);
        $this->assertTrue($champ->isDisabled(), 'Le code est l’autorité : il se lit, il ne se saisit pas.');
    }

    // ═══ Le refus de fond : pas de libellé, pas de composition ═════════════

    public function test_une_capacite_absente_de_la_table_est_refusee_a_la_composition(): void
    {
        DB::table('capability_definitions')->where('code', AccessGrant::MEMORY_SESSIONS)->delete();

        try {
            Plan::create([
                'code' => 'sans-libelle',
                'name_fr' => 'Sans libellé', 'name_ar' => 'بدون تسمية',
                'price_cents' => 10000, 'currency' => 'MAD', 'duration_days' => 30,
                'capabilities' => [AccessGrant::QUESTIONS_ANSWER, AccessGrant::MEMORY_SESSIONS],
                'active' => true, 'position' => 1,
            ]);
            $this->fail('Une capacité sans référentiel bilingue rendrait un code brut au candidat.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                AccessGrant::MEMORY_SESSIONS,
                $exception->validator->errors()->first('capabilities'),
            );
        }
    }
}
