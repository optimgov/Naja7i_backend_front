<?php

namespace Tests\Feature\Abonnement;

use App\Contracts\AccessGrant;
use App\Filament\Pages\DroitTransitoire;
use App\Filament\Resources\Plans\Pages\CreatePlan;
use App\Filament\Resources\Plans\Pages\EditPlan;
use App\Models\AccessGrantRecord;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TransitionBatch;
use App\Models\User;
use App\Services\DroitTransitoireService;
use App\Support\CapabilityRegistry;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La garde qui manque au catalogue — pas 0 du lot 3A.9.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * DEUX DÉFAUTS QUI NE SE VOIENT QUE DEPUIS LES MURS
 *
 * Le lot des murs transforme un catalogue mal composé en incident candidat.
 * Tant que rien n'est fermé, une offre payante sans `questions.answer` ne se
 * remarque pas ; le jour de l'allumage, chaque conversion fait PAYER POUR
 * PERDRE l'accès principal, puisque l'ADR-0033 clôt l'essai au premier
 * paiement. Ce fichier tient les deux gardes que ce constat impose.
 *
 * Elles ne sont pas de même nature, et l'ADR-0032 en fait la frontière :
 *
 *   · une offre payante sans `questions.answer` est un MAUVAIS PRODUIT —
 *     légitime demain pour un module d'entraînement vendu seul. On AVERTIT ;
 *   · un droit transitoire composé par défaut est un ÉTAT QUE LE DOMAINE
 *     INTERDIT — il se présente comme l'égal d'un palier que personne n'a
 *     choisi. On REFUSE.
 */
class GardeDeCompositionTest extends TestCase
{
    use RefreshDatabase;

    private User $commerciale;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->commerciale = $this->membre('commerciale-garde@naja7i.ma', 'finance');
    }

    private function membre(string $email, string $role): User
    {
        $user = User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr', 'status' => 'active',
        ]);
        $user->markEmailAsVerified();
        $user->memberships()->create([
            'role_id' => Role::where('code', $role)->whereNull('tenant_id')->value('id'),
        ]);

        return $user->fresh();
    }

    private function registre(): CapabilityRegistry
    {
        return app(CapabilityRegistry::class);
    }

    // ═══ 0.a — l'avertissement nommé à la composition ═══════════════════════

    public function test_la_regle_vit_au_registre_et_nomme_ce_qui_manque(): void
    {
        $sansAcces = [AccessGrant::SERIES_TARGETED, AccessGrant::SIMULATOR_FULL];

        $avertissements = $this->registre()->avertissementsDeComposition($sansAcces, payante: true);

        $this->assertCount(1, $avertissements);
        $this->assertStringContainsString('répondre aux questions', $avertissements[0]);
        $this->assertStringContainsString('clôt son essai', $avertissements[0]);

        $this->assertSame(
            [],
            $this->registre()->avertissementsDeComposition(
                [...$sansAcces, AccessGrant::QUESTIONS_ANSWER], payante: true,
            ),
            'Ajouter l’accès principal fait disparaître l’avertissement.',
        );

        $this->assertSame(
            [],
            $this->registre()->avertissementsDeComposition($sansAcces, payante: false),
            'L’offre gratuite ne clôt aucun essai : elle EST l’essai.',
        );
    }

    public function test_l_ecran_des_offres_avertit_sur_une_composition_payante_sans_acces_principal(): void
    {
        $formulaire = Livewire::actingAs($this->commerciale)
            ->test(CreatePlan::class)
            ->fillForm([
                'price_cents' => 19900,
                'capabilities' => [AccessGrant::SERIES_TARGETED, AccessGrant::SIMULATOR_FULL],
            ]);

        $formulaire->assertSee('Composition à vérifier');
        $formulaire->assertSee('perd l’accès principal', escape: false);

        /* L'ajouter le fait disparaître — c'est la moitié du test qui prouve
         * que l'avertissement suit la composition et n'est pas un bandeau. */
        $formulaire
            ->fillForm([
                'capabilities' => [
                    AccessGrant::SERIES_TARGETED,
                    AccessGrant::SIMULATOR_FULL,
                    AccessGrant::QUESTIONS_ANSWER,
                ],
            ])
            ->assertDontSee('Composition à vérifier');
    }

    public function test_l_offre_gratuite_ne_declenche_jamais_l_avertissement(): void
    {
        $gratuite = Plan::where('auto_granted', true)->sole();

        Livewire::actingAs($this->commerciale)
            ->test(EditPlan::class, ['record' => $gratuite->getRouteKey()])
            ->fillForm(['price_cents' => 0, 'capabilities' => [AccessGrant::SERIES_TARGETED]])
            ->assertDontSee('Composition à vérifier');
    }

    public function test_l_avertissement_ne_bloque_pas_l_enregistrement(): void
    {
        /* ADR-0032 : on prévient, on n'empêche pas. Un module d'entraînement
         * vendu seul peut être un choix commercial légitime demain. */
        Livewire::actingAs($this->commerciale)
            ->test(CreatePlan::class)
            ->fillForm([
                'code' => 'entrainement-seul',
                'audience_id' => Plan::where('auto_granted', true)->value('audience_id'),
                'name_fr' => 'Entraînement seul', 'name_ar' => 'التدريب وحده',
                'price_cents' => 9900, 'currency' => 'MAD', 'duration_days' => 30,
                'capabilities' => [AccessGrant::SERIES_TARGETED],
                'active' => true, 'position' => 50,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(
            [AccessGrant::SERIES_TARGETED],
            Plan::where('code', 'entrainement-seul')->sole()->capabilities,
        );
    }

    // ═══ 0.b — le droit transitoire ne devine plus son palier ═══════════════

    public function test_le_geste_refuse_sans_offre_nommee(): void
    {
        try {
            app(DroitTransitoireService::class)->previsualiser(['motif' => 'Allumage du mur payant.']);
            $this->fail('Un droit transitoire sans palier nommé se présente comme l’égal d’une devinette.');
        } catch (ValidationException $exception) {
            $motif = $exception->validator->errors()->first('offre');

            $this->assertStringContainsString('doit être nommée', $motif);
            $this->assertStringContainsString('repli', $motif, 'Le refus dit POURQUOI, pas seulement non.');
        }

        $this->assertSame(0, AccessGrantRecord::where('origin', 'transition')->count());
        $this->assertSame(0, TransitionBatch::query()->count());
    }

    public function test_la_pose_refuse_aussi_sans_offre_nommee(): void
    {
        $this->expectException(ValidationException::class);

        app(DroitTransitoireService::class)->poser($this->commerciale, [
            'motif' => 'Allumage du mur payant, sevrage annoncé.',
        ]);
    }

    public function test_la_commande_en_mode_sec_refuse_sans_offre_nommee(): void
    {
        $this->artisan('naja7i:poser-le-droit-transitoire', ['--dry-run' => true])
            ->assertFailed();

        $this->assertSame(0, TransitionBatch::query()->count());
    }

    public function test_l_ecran_exige_le_palier_de_reference(): void
    {
        Livewire::actingAs($this->commerciale)
            ->test(DroitTransitoire::class)
            ->callAction('poser', [
                'duree' => 60,
                'motif' => 'Allumage du mur payant, sevrage de soixante jours.',
            ])
            ->assertHasActionErrors(['offre']);

        $this->assertSame(0, TransitionBatch::query()->count());
    }

    public function test_l_offre_nommee_compose_bien_le_droit(): void
    {
        $apercu = app(DroitTransitoireService::class)->previsualiser([
            'offre' => 'session-180j',
            'motif' => 'Allumage du mur payant.',
        ]);

        $this->assertSame('session-180j', $apercu['offre']);
        $this->assertSame(
            Plan::where('code', 'session-180j')->sole()->capabilities,
            $apercu['capacites'],
            'Ce que le droit ouvrira est la composition de l’offre nommée, pas une liste écrite en code.',
        );
    }
}
