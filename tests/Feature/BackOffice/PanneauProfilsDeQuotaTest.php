<?php

namespace Tests\Feature\BackOffice;

use App\Contracts\AccessGrant;
use App\Filament\Resources\Plans\PlanResource;
use App\Filament\Resources\QuotaProfiles\Pages\CreateQuotaProfile;
use App\Filament\Resources\QuotaProfiles\Pages\EditQuotaProfile;
use App\Filament\Resources\QuotaProfiles\QuotaProfileResource;
use App\Models\QuotaProfile;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\QuotaProfileService;
use App\Tenancy\TenantContext;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Le registre des profils de quota, vu de l'écran.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE CE FICHIER PROUVE, ET QUE LE TEST DE SERVICE NE PEUT PAS PROUVER
 *
 * Le service refuse un nombre hors bornes ; il ne dit rien de ce qu'un écran
 * PROPOSE. Or la spécification d'administration commerciale ne demande pas
 * seulement un refus : elle demande que la saisie soit IMPOSSIBLE côté
 * commerce — « Saisir une valeur de quota au clavier est impossible : seule la
 * sélection d'un profil autorisé existe. »
 *
 * On le vérifie donc là où il se joue : sur le formulaire des offres, qui ne
 * doit porter aucun champ numérique de quota. Le jour où quelqu'un en ajoutera
 * un, ce test rougira — et lui seul.
 */
class PanneauProfilsDeQuotaTest extends TestCase
{
    use RefreshDatabase;

    private User $pedagogue;

    private User $commerciale;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->pedagogue = $this->membre('pedagogue-bo@naja7i.ma', 'super_admin');
        $this->commerciale = $this->membre('commerciale-bo@naja7i.ma', 'finance');
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

    private function composantDuFormulaire(string $resource, string $nom): mixed
    {
        foreach ($resource::form(Schema::make())->getComponents(withHidden: true) as $composant) {
            if (method_exists($composant, 'getName') && $composant->getName() === $nom) {
                return $composant;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function champsDuFormulaire(string $resource): array
    {
        $schema = $resource::form(Schema::make());

        return array_values(array_filter(array_map(
            fn ($composant) => method_exists($composant, 'getName') ? $composant->getName() : null,
            $schema->getComponents(withHidden: true),
        )));
    }

    // ═══ Qui entre, et qui ne peut pas ═════════════════════════════════════

    public function test_l_admin_commerciale_n_ouvre_pas_le_registre_pedagogique(): void
    {
        $this->actingAs($this->pedagogue)
            ->get(QuotaProfileResource::getUrl('index', panel: 'admin'))
            ->assertOk();

        $this->flushSession();

        $this->actingAs($this->commerciale)
            ->get(QuotaProfileResource::getUrl('index', panel: 'admin'))
            ->assertForbidden();
    }

    // ═══ S-16 geste 2 : le commerce ne saisit aucun nombre ═════════════════

    public function test_le_formulaire_des_offres_ne_porte_aucun_champ_de_saisie_de_quota(): void
    {
        $champs = $this->champsDuFormulaire(PlanResource::class);

        /*
         * CE QUE CE TEST GARDE, ET CE QUI A CHANGÉ AU LOT 3A.6.
         *
         * La formulation d'origine — « aucun champ dont le nom contient quota »
         * — tenait tant que l'écran commercial ne SÉLECTIONNAIT rien. Le lot
         * 3A.6 livre la sélection que la spécification demande depuis le début
         * (« elle choisit parmi une liste ; elle ne saisit aucun nombre »), et
         * ce champ s'appelle `quota_profile_id`.
         *
         * L'intention, elle, n'a pas bougé d'un pouce, et le test la garde plus
         * étroitement qu'avant : le seul champ de quota est une LISTE, ses
         * options viennent du registre pédagogique, et aucun champ de valeur ou
         * de borne n'existe ici. Un `TextInput::make('quota_value')` ajouté
         * demain rougirait sur les trois assertions.
         */
        $champsDeQuota = array_values(array_filter(
            $champs, fn (string $champ): bool => str_contains($champ, 'quota'),
        ));

        $this->assertSame(['quota_profile_id'], $champsDeQuota);

        $selection = $this->composantDuFormulaire(PlanResource::class, 'quota_profile_id');
        $this->assertInstanceOf(
            Select::class, $selection,
            'Un quota se sélectionne dans une liste ; un champ de saisie ici rendrait le nombre libre.'
        );

        $this->assertSame(
            array_values(app(QuotaProfileService::class)->selectionnablesPour(AccessGrant::QUESTIONS_ANSWER)),
            array_values($selection->getOptions()),
            'Les options de l’écran commercial sont celles du registre pédagogique, pas une seconde liste.'
        );

        foreach (['value', 'min_value', 'max_value', 'min_justification', 'max_justification'] as $interdit) {
            $this->assertNotContains($interdit, $champs);
        }

        /* Et le registre pédagogique, lui, porte bien la valeur et ses bornes :
         * sans cette moitié, l'assertion ci-dessus serait vraie d'un produit
         * qui ne sait pas borner du tout — genre 6 du bestiaire. */
        $registre = $this->champsDuFormulaire(QuotaProfileResource::class);

        foreach (['value', 'min_value', 'max_value', 'min_justification', 'max_justification'] as $attendu) {
            $this->assertContains($attendu, $registre);
        }
    }

    // ═══ L'écriture passe par le service, avec son journal ═════════════════

    public function test_la_creation_par_l_ecran_journalise_son_auteur(): void
    {
        Livewire::actingAs($this->pedagogue)
            ->test(CreateQuotaProfile::class)
            ->fillForm([
                'code' => 'approfondissement',
                'name_fr' => 'Approfondissement',
                'name_ar' => 'تعميق',
                'unit' => 'questions',
                'periodicity' => 'cumulative_grant',
                'value' => 80,
                'min_value' => 60,
                'max_value' => 150,
                'min_justification' => 'Sous soixante questions, aucun chapitre ne réunit les cinq réponses '
                    .'qu’exige l’affichage d’un score.',
                'max_justification' => 'Au-delà de cent cinquante questions, l’offre d’approfondissement '
                    .'dépasse ce qu’une session de préparation consomme réellement.',
                'active' => true,
                'position' => 20,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $profil = QuotaProfile::where('code', 'approfondissement')->firstOrFail();

        $this->assertSame(80, $profil->value);
        $this->assertSame(
            $this->pedagogue->id,
            $profil->events()->firstOrFail()->actor_id,
            'Une écriture sans auteur au journal est une écriture qui a contourné le service.'
        );
    }

    public function test_l_ecran_refuse_une_borne_deplacee_sans_raison_nouvelle(): void
    {
        $profil = QuotaProfile::where('code', 'decouverte')->firstOrFail();

        Livewire::actingAs($this->pedagogue)
            ->test(EditQuotaProfile::class, ['record' => $profil->getRouteKey()])
            ->fillForm([
                'min_value' => 5,
                'value' => 5,
                /* La justification d'origine, laissée telle quelle. */
                'min_justification' => $profil->min_justification,
            ])
            ->call('save')
            ->assertHasFormErrors(['min_justification']);

        $this->assertSame(35, $profil->fresh()->min_value);
    }
}
