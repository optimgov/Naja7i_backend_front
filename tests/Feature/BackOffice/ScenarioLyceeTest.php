<?php

namespace Tests\Feature\BackOffice;

use App\Contracts\AccessGrant;
use App\Filament\Resources\Audiences\Pages\CreateAudience;
use App\Filament\Resources\CapabilityDefinitions\CapabilityDefinitionResource;
use App\Filament\Resources\Plans\Pages\CreatePlan;
use App\Filament\Resources\Plans\PlanResource;
use App\Models\AccessGrantRecord;
use App\Models\Audience;
use App\Models\CapabilityDefinition;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\Paiement\CouponGateway;
use App\Support\CapabilityRegistry;
use App\Tenancy\TenantContext;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * S-11 — L'offre lycée, créée de bout en bout sans développeur.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE CE FICHIER PROUVE
 *
 * Le premier test d'acceptation de la spécification : « L'admin commerciale crée
 * une catégorie, un pack, sa version 1, et le met en vente — SANS intervention
 * d'un développeur, SANS migration. » C'est la promesse commerciale du produit,
 * et elle ne se vérifie pas en lisant du code : elle se vérifie en faisant les
 * gestes, à l'écran, avec les permissions d'une admin commerciale.
 *
 * Le scénario S-11 précise la composition : catégorie `lycee`, « Suivi
 * mensuel », 30 jours, quatre capacités, portée `(audience, lycee)`. Les
 * assertions d'USAGE candidat (couverture des épreuves lycée, enveloppe neuve
 * au renouvellement) appartiennent aux lots 3A.9 et 3B ; ce qui se tient ici est
 * la CRÉATION, et le refus de vendre ce qui n'est pas livré.
 */
class ScenarioLyceeTest extends TestCase
{
    use RefreshDatabase;

    private User $commerciale;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->commerciale = User::create([
            'email' => 'commerciale-lycee@naja7i.ma', 'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr', 'status' => 'active',
        ]);
        $this->commerciale->markEmailAsVerified();
        $this->commerciale->memberships()->create([
            'role_id' => Role::where('code', 'finance')->whereNull('tenant_id')->value('id'),
        ]);
        $this->commerciale = $this->commerciale->fresh();
    }

    // ═══ Test d'acceptation n°1 ════════════════════════════════════════════

    public function test_l_admin_commerciale_cree_la_categorie_le_pack_et_sa_version_sans_developpeur(): void
    {
        $migrations = DB::table('migrations')->count();

        Livewire::actingAs($this->commerciale)
            ->test(CreateAudience::class)
            ->fillForm([
                'code' => 'lycee', 'name_fr' => 'Lycée', 'name_ar' => 'الثانوي',
                'active' => true, 'position' => 20,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $lycee = Audience::where('code', 'lycee')->sole();

        Livewire::actingAs($this->commerciale)
            ->test(CreatePlan::class)
            ->fillForm([
                'code' => 'suivi-mensuel',
                'audience_id' => $lycee->id,
                'name_fr' => 'Suivi mensuel',
                'name_ar' => 'المتابعة الشهرية',
                'description_fr' => 'Un mois de suivi complet, renouvelable.',
                'description_ar' => 'شهر من المتابعة الكاملة، قابل للتجديد.',
                'price_cents' => 14900,
                'currency' => 'MAD',
                'duration_days' => 30,
                'capabilities' => [
                    AccessGrant::QUESTIONS_ANSWER,
                    AccessGrant::MASTERY_DETAIL,
                    AccessGrant::REMEDIATION_PLAN,
                    AccessGrant::MEMORY_SESSIONS,
                ],
                'scope_type' => AccessGrantRecord::SCOPE_AUDIENCE,
                'scope_uuid' => $lycee->uuid,
                'active' => true,
                'position' => 10,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $pack = Plan::where('code', 'suivi-mensuel')->sole();
        $version = $pack->currentVersion()->firstOrFail();

        $this->assertSame($lycee->id, $pack->audience_id);
        $this->assertSame(30, $pack->duration_days);
        $this->assertTrue($pack->active, 'Le pack est mis en vente dans le même geste.');

        $this->assertSame(1, $version->version);
        $this->assertSame(14900, $version->price_cents);
        $this->assertSame(AccessGrantRecord::SCOPE_AUDIENCE, $version->scope_type);
        $this->assertSame($lycee->uuid, $version->scope_uuid);
        $this->assertSame([
            AccessGrant::QUESTIONS_ANSWER,
            AccessGrant::MASTERY_DETAIL,
            AccessGrant::REMEDIATION_PLAN,
            AccessGrant::MEMORY_SESSIONS,
        ], $version->capabilities);
        $this->assertSame($this->commerciale->id, $version->composed_by);

        $this->assertSame(
            $migrations,
            DB::table('migrations')->count(),
            'Sans migration : c’est la moitié de la promesse.',
        );

        /* Et le catalogue le rend immédiatement au candidat. */
        $codes = collect($this->getJson('/api/v1/plans')->assertOk()->json('data'))->pluck('code');
        $this->assertContains('suivi-mensuel', $codes->all());
    }

    // ═══ Test d'acceptation n°2 — la fonction non livrée ═══════════════════

    public function test_certification_take_n_est_ni_proposee_ni_acceptee(): void
    {
        $capacites = collect(PlanResource::form(Schema::make())->getComponents(withHidden: true))
            ->first(fn ($composant) => method_exists($composant, 'getName')
                && $composant->getName() === 'capabilities');

        $this->assertNotContains(
            AccessGrant::CERTIFICATION,
            array_keys($capacites->getOptions()),
            'Une fonction non livrée ne se coche pas.',
        );

        /* Et le refus ne dépend pas de l'écran : une requête forgée le
         * rencontre aussi, avec la capacité et la raison nommées. */
        try {
            Plan::create([
                'code' => 'certification-lycee',
                'audience_id' => Audience::where('code', 'crmef')->value('id'),
                'name_fr' => 'Certification', 'name_ar' => 'شهادة',
                'price_cents' => 10000, 'currency' => 'MAD', 'duration_days' => 30,
                'capabilities' => [AccessGrant::QUESTIONS_ANSWER, AccessGrant::CERTIFICATION],
                'active' => true, 'position' => 1,
            ]);
            $this->fail('P6 : certification.take est cochable alors que la fonction arrive au lot 11.');
        } catch (ValidationException $exception) {
            $message = $exception->validator->errors()->first('capabilities');
            $this->assertStringContainsString(AccessGrant::CERTIFICATION, $message);
            $this->assertStringContainsString('livrée', $message);
        }
    }

    // ═══ Test d'acceptation n°5 — la commande en attente garde sa version ══

    public function test_changer_le_prix_cree_une_version_et_la_commande_en_attente_garde_la_sienne(): void
    {
        $candidat = User::create([
            'email' => 'candidat-lycee@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $candidat->markEmailAsVerified();
        $candidat->grantCandidateRole();

        $pack = Plan::create([
            'code' => 'prix-change',
            'audience_id' => Audience::where('code', 'crmef')->value('id'),
            'name_fr' => 'Prix', 'name_ar' => 'ثمن',
            'price_cents' => 20000, 'currency' => 'MAD', 'duration_days' => 30,
            'capabilities' => [AccessGrant::QUESTIONS_ANSWER],
            'active' => true, 'position' => 1,
        ]);
        $coupon = Coupon::create([
            'code' => Coupon::engendrer(), 'plan_id' => $pack->id,
            'valid_from' => now()->subDay(), 'valid_until' => now()->addMonth(),
            'max_uses' => 1, 'used_count' => 0, 'status' => 'actif',
        ]);
        $commande = app(CouponGateway::class)->ouvrir(
            $candidat, ['code' => $coupon->code], (string) Str::uuid7(),
        );
        $this->assertSame('en_attente', $commande->status);

        $pack->update(['price_cents' => 99000]);

        $this->assertSame(2, $pack->fresh()->versions()->count());
        $this->assertSame(20000, $commande->fresh()->planVersion()->firstOrFail()->price_cents);
        $this->assertSame(20000, $commande->fresh()->amount_cents);

        $honoree = app(AbonnementService::class)->honorer($commande);
        $this->assertSame('honoree', $honoree->status);
        $this->assertSame(
            [AccessGrant::QUESTIONS_ANSWER],
            AccessGrantRecord::where('user_id', $candidat->id)->pluck('capability')->all(),
        );
    }

    // ═══ Test d'acceptation n°12 — ce qu'aucun écran ne permet ═════════════

    public function test_aucun_ecran_ne_cree_une_capacite_un_type_de_portee_ni_une_regle_de_consommation(): void
    {
        $champs = collect(PlanResource::form(Schema::make())->getComponents(withHidden: true))
            ->filter(fn ($c) => method_exists($c, 'getName'))
            ->keyBy(fn ($c) => $c->getName());

        /* Les deux registres fermés se rendent en LISTE : aucune saisie libre
         * ne peut y introduire un code que le produit n'applique pas. */
        $this->assertInstanceOf(Select::class, $champs->get('scope_type'));
        $this->assertSame(
            ['audience', 'filiere', 'exam_family', 'exam', 'competency_node'],
            array_keys($champs->get('scope_type')->getOptions()),
        );
        $this->assertSame(
            CapabilityRegistry::COMMERCIALIZABLE,
            array_keys($champs->get('capabilities')->getOptions()),
        );

        /* Aucune surface ne crée une capacité. */
        $this->assertArrayNotHasKey('create', CapabilityDefinitionResource::getPages());
        $this->assertFalse($this->commerciale->can('create', CapabilityDefinition::class));

        /* Et aucune règle de consommation n'est exposée : la fenêtre d'un quota
         * est figée après création du profil, et l'écran commercial n'en parle
         * même pas. */
        foreach (['periodicity', 'consommation', 'idempotence'] as $interdit) {
            $this->assertNull($champs->get($interdit));
        }
    }
}
