<?php

namespace Tests\Feature\Abonnement;

use App\Contracts\AccessGrant;
use App\Enums\QuotaPeriodicity;
use App\Enums\QuotaProfileEventType;
use App\Enums\QuotaUnit;
use App\Models\MasteryScore;
use App\Models\QuotaProfile;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\QuotaProfileService;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Lot 3A.5 — « Aucune valeur de quota n'existe dans le produit sans avoir
 * franchi une borne pédagogique justifiée » (scénario S-16).
 */
class ProfilsDeQuotaTest extends TestCase
{
    use RefreshDatabase;

    private User $pedagogue;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->pedagogue = User::create([
            'email' => 'pedagogue@naja7i.ma',
            'password' => 'Profils-De-Quota-2026!',
            'locale' => 'fr',
            'status' => 'active',
        ]);

        $this->pedagogue->memberships()->create([
            'role_id' => Role::where('code', 'editeur')->whereNull('tenant_id')->value('id'),
        ]);
    }

    private function service(): QuotaProfileService
    {
        return app(QuotaProfileService::class);
    }

    /** @param array<string, mixed> $remplacements */
    private function attributs(array $remplacements = []): array
    {
        return array_merge([
            'code' => 'essai',
            'name_fr' => 'Essai',
            'name_ar' => 'تجربة',
            'unit' => QuotaUnit::QUESTIONS->value,
            'periodicity' => QuotaPeriodicity::CUMULATIVE_GRANT->value,
            'value' => 50,
            'min_value' => 40,
            'max_value' => 100,
            'min_justification' => 'Sous quarante questions la carte de maîtrise reste muette sur la majorité des nœuds.',
            'max_justification' => 'Au-delà de cent questions, la découverte couvre déjà l’essentiel de l’épreuve.',
        ], $remplacements);
    }

    // ═══ Le profil décidé existe, avec ses quatre nombres ═══════════════════

    public function test_le_profil_decouverte_est_seme_avec_ses_bornes_justifiees(): void
    {
        $profil = QuotaProfile::where('code', 'decouverte')->firstOrFail();

        $this->assertSame(40, $profil->value);
        $this->assertSame(35, $profil->min_value);
        $this->assertSame(120, $profil->max_value);
        $this->assertSame(QuotaUnit::QUESTIONS, $profil->unit);
        $this->assertSame(QuotaPeriodicity::CUMULATIVE_GRANT, $profil->periodicity);
        $this->assertTrue($profil->active);

        /* La borne basse n'est pas un nombre nu : elle porte sa raison, et
         * cette raison est celle que la spécification donne. */
        $this->assertStringContainsString('maîtrise', $profil->min_justification);
        $this->assertGreaterThan(
            MasteryScore::SEUIL_FAIBLE, $profil->min_value,
            'Une borne basse sous le seuil de maîtrise rendrait la carte invisible avant achat — piège P5.'
        );
    }

    public function test_l_unite_est_liee_a_la_capacite_qui_la_consomme(): void
    {
        $this->assertSame(AccessGrant::QUESTIONS_ANSWER, QuotaUnit::QUESTIONS->capability());
        $this->assertSame([QuotaUnit::QUESTIONS], QuotaUnit::cases());
        $this->assertSame([QuotaPeriodicity::CUMULATIVE_GRANT], QuotaPeriodicity::cases());
    }

    // ═══ Les bornes sont tenues par la base, pas seulement par le service ═══

    public function test_la_base_refuse_une_valeur_hors_bornes(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('quota_profiles_value_within_bounds');

        $profil = new QuotaProfile;
        $profil->forceFill($this->attributs(['code' => 'hors-bornes', 'value' => 5]))->save();
    }

    public function test_la_base_refuse_une_borne_sans_justification(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('quota_profiles_bounds_justified');

        $profil = new QuotaProfile;
        $profil->forceFill($this->attributs(['code' => 'sans-raison', 'min_justification' => 'parce que']))->save();
    }

    /**
     * DEUX SERRURES, ET ON LES ÉPROUVE SÉPARÉMENT.
     *
     * Le cast Eloquent refuse déjà « corrections » avant toute requête — c'est
     * la première. Mais un cast se retire d'une ligne ; le type PostgreSQL,
     * non. On écrit donc en contournant le modèle, ce qui est exactement le
     * chemin qu'un correctif à chaud emprunterait.
     */
    public function test_la_base_refuse_une_unite_que_le_code_ne_compte_pas(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('quota_unit');

        DB::table('quota_profiles')->insert(
            $this->attributs(['code' => 'unite-inventee', 'unit' => 'corrections'])
            + ['uuid' => (string) Str::uuid7(), 'active' => true, 'position' => 99]
        );
    }

    public function test_un_profil_ne_se_supprime_jamais(): void
    {
        $profil = $this->service()->definir($this->pedagogue, $this->attributs(['code' => 'indelebile']));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ne se supprime jamais');

        $profil->delete();
    }

    // ═══ Le service refuse en nommant ce qu'il refuse ═══════════════════════

    public function test_le_service_refuse_une_valeur_sous_la_borne_basse_en_la_nommant(): void
    {
        try {
            $this->service()->definir($this->pedagogue, $this->attributs(['value' => 12]));
            $this->fail('Une valeur sous la borne basse a été acceptée.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('40', $exception->getMessage());
            $this->assertStringContainsString('borne basse', $exception->getMessage());
        }

        $this->assertDatabaseMissing('quota_profiles', ['code' => 'essai']);
    }

    public function test_le_service_refuse_une_unite_inconnue(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('n’est comptée par aucune capacité');

        $this->service()->definir($this->pedagogue, $this->attributs(['unit' => 'minutes']));
    }

    public function test_le_service_refuse_une_justification_trop_courte(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Une borne sans raison écrite');

        $this->service()->definir($this->pedagogue, $this->attributs(['max_justification' => 'trop court']));
    }

    /**
     * S-16 GESTE 4 — le cœur du pas.
     *
     * Abaisser une borne en gardant la raison de l'ancienne, c'est l'abaisser
     * sans raison. Le refus doit tomber MÊME si l'ancienne justification est
     * longue et sérieuse : c'est le renouvellement qui est exigé, pas la
     * présence d'un texte.
     */
    public function test_abaisser_une_borne_sans_justification_nouvelle_est_refuse(): void
    {
        $profil = QuotaProfile::where('code', 'decouverte')->firstOrFail();
        $justificationInchangee = $profil->min_justification;

        try {
            $this->service()->amender($profil, $this->pedagogue, [
                'value' => 5,
                'min_value' => 5,
                'min_justification' => $justificationInchangee,
            ]);
            $this->fail('Une borne a été abaissée en conservant la justification de l’ancienne.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('sans justification', $exception->getMessage());
        }

        $profil->refresh();
        $this->assertSame(35, $profil->min_value);
        $this->assertSame(40, $profil->value);
    }

    public function test_abaisser_une_borne_avec_une_justification_nouvelle_est_accepte_et_journalise(): void
    {
        $profil = QuotaProfile::where('code', 'decouverte')->firstOrFail();

        $amende = $this->service()->amender($profil, $this->pedagogue, [
            'min_value' => 30,
            'min_justification' => 'La banque a doublé de taille : trente questions couvrent désormais '
                .'cinq réponses sur chacun des nœuds racines de l’épreuve.',
        ]);

        $this->assertSame(30, $amende->min_value);

        $evenement = $amende->events()
            ->where('event_type', QuotaProfileEventType::BOUNDS_CHANGED)
            ->latest('occurred_at')
            ->firstOrFail();

        $this->assertSame($this->pedagogue->id, $evenement->actor_id);
        $this->assertSame(35, $evenement->before['min_value']);
        $this->assertSame(30, $evenement->after['min_value']);
        $this->assertStringContainsString('doublé de taille', $evenement->after['min_justification']);
    }

    public function test_le_code_l_unite_et_la_fenetre_sont_figes_apres_creation(): void
    {
        $profil = $this->service()->definir($this->pedagogue, $this->attributs(['code' => 'fige']));

        foreach ([
            ['code' => 'autre-code'],
            ['unit' => 'minutes'],
            ['periodicity' => 'mensuel'],
        ] as $tentative) {
            try {
                $this->service()->amender($profil->fresh(), $this->pedagogue, $tentative);
                $this->fail('Un champ figé a été modifié : '.array_key_first($tentative));
            } catch (ValidationException) {
                // attendu
            }
        }

        $profil->refresh();
        $this->assertSame('fige', $profil->code);
        $this->assertSame(QuotaUnit::QUESTIONS, $profil->unit);
    }

    // ═══ Le journal ════════════════════════════════════════════════════════

    public function test_la_definition_et_chaque_changement_laissent_leur_auteur(): void
    {
        $profil = $this->service()->definir($this->pedagogue, $this->attributs(['code' => 'journalise']));

        $definition = $profil->events()->firstOrFail();
        $this->assertSame(QuotaProfileEventType::DEFINED, $definition->event_type);
        $this->assertSame([], $definition->before);
        $this->assertSame(50, $definition->after['value']);
        $this->assertSame($this->pedagogue->id, $definition->actor_id);

        $this->service()->amender($profil, $this->pedagogue, ['value' => 60, 'active' => false]);

        $types = $profil->fresh()->events()->get()->pluck('event_type')->all();

        $this->assertContains(QuotaProfileEventType::VALUE_CHANGED, $types);
        $this->assertContains(QuotaProfileEventType::AVAILABILITY_CHANGED, $types);
        $this->assertNotContains(QuotaProfileEventType::BOUNDS_CHANGED, $types);
    }

    public function test_le_journal_est_en_ajout_seul(): void
    {
        $profil = $this->service()->definir($this->pedagogue, $this->attributs(['code' => 'ajout-seul']));
        $evenement = $profil->events()->firstOrFail();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ajout seul');

        $evenement->forceFill(['after' => ['value' => 999]])->save();
    }

    // ═══ La garde que le pas commercial appellera ═══════════════════════════

    public function test_une_valeur_forgee_hors_profil_est_refusee_en_nommant_la_borne(): void
    {
        $profil = QuotaProfile::where('code', 'decouverte')->firstOrFail();

        try {
            $this->service()->assertSelectionnable($profil, AccessGrant::QUESTIONS_ANSWER, 12);
            $this->fail('Une valeur forgée hors profil a été acceptée.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('ne se saisit pas', $exception->getMessage());
            $this->assertStringContainsString('40', $exception->getMessage());
        }
    }

    public function test_un_profil_est_refuse_pour_une_capacite_qui_ne_compte_pas_son_unite(): void
    {
        $profil = QuotaProfile::where('code', 'decouverte')->firstOrFail();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('mastery.detail');

        $this->service()->assertSelectionnable($profil, AccessGrant::MASTERY_DETAIL);
    }

    public function test_un_profil_retire_de_la_selection_est_refuse_sans_etre_efface(): void
    {
        $profil = $this->service()->definir($this->pedagogue, $this->attributs(['code' => 'retire']));
        $this->service()->amender($profil, $this->pedagogue, ['active' => false]);

        try {
            $this->service()->assertSelectionnable($profil->fresh(), AccessGrant::QUESTIONS_ANSWER);
            $this->fail('Un profil retiré a été sélectionné.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('n’est plus proposé', $exception->getMessage());
        }

        $this->assertDatabaseHas('quota_profiles', ['code' => 'retire']);
    }

    public function test_la_selection_ne_propose_que_les_profils_actifs_de_la_bonne_unite(): void
    {
        $retire = $this->service()->definir($this->pedagogue, $this->attributs(['code' => 'invisible']));
        $this->service()->amender($retire, $this->pedagogue, ['active' => false]);

        $options = $this->service()->selectionnablesPour(AccessGrant::QUESTIONS_ANSWER);

        $this->assertArrayHasKey('decouverte', $options);
        $this->assertArrayNotHasKey('invisible', $options);
        $this->assertStringContainsString('40 questions', $options['decouverte']);

        /* Aucune capacité ne compte d'unité en dehors de `questions.answer` :
         * proposer une liste vide vaut mieux que proposer un profil qui ne
         * bornerait rien. */
        $this->assertSame([], $this->service()->selectionnablesPour(AccessGrant::MASTERY_DETAIL));
        $this->assertSame([], $this->service()->selectionnablesPour(AccessGrant::CAUSE_REVEAL));
    }
}
