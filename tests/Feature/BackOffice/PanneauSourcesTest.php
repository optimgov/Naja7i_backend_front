<?php

namespace Tests\Feature\BackOffice;

use App\Filament\Resources\Sources\Pages\EditSource;
use App\Filament\Resources\Sources\Pages\ListSources;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Permission;
use App\Models\Question;
use App\Models\Role;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\QuestionAuthoringService;
use App\Services\SourceVerificationService;
use App\Tenancy\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Lot A4 — le registre documentaire.
 *
 * DEUX GARANTIES S'Y ÉPROUVENT, ET AUCUNE N'EST UNE RÈGLE NEUVE.
 *
 * La première est que la vérification passe par `SourceVerificationService` :
 * elle enregistre QUI et QUAND, et propage l'état aux citations encore
 * modifiables. La seconde est plus subtile — l'écran doit dire la vérité APRÈS
 * une modification qui vient d'annuler la vérification, sans qu'on recharge.
 * Le déclencheur du PAS-29 est un `BEFORE UPDATE` : il change la ligne, pas
 * l'instance PHP. Sans `SourceObserver`, l'écran affiche « vérifiée » sur une
 * source qui ne l'est plus.
 */
class PanneauSourcesTest extends TestCase
{
    use RefreshDatabase;

    private User $relecteur;

    private User $auteur;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        Filament::setCurrentPanel('admin');

        $this->relecteur = $this->membre('relecteur@naja7i.ma', 'expert_pedagogue');
        $this->auteur = $this->membreAvecPermissions(
            'redacteur@naja7i.ma',
            ['questions.view', 'questions.create', 'catalogue.view'],
        );
    }

    private function membre(string $email, string $role): User
    {
        $user = User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
            'status' => 'active',
        ]);
        $user->markEmailAsVerified();
        $user->memberships()->create([
            'role_id' => Role::where('code', $role)->whereNull('tenant_id')->value('id'),
        ]);

        return $user;
    }

    /** Acteur de contrôle : il rédige et consulte, sans droit de relecture. */
    private function membreAvecPermissions(string $email, array $permissions): User
    {
        $role = Role::create([
            'code' => 'redacteur-sans-relecture',
            'label_fr' => 'Rédacteur sans relecture',
            'label_ar' => 'محرر دون مراجعة',
            'is_staff' => true,
        ]);
        $role->permissions()->attach(Permission::whereIn('code', $permissions)->pluck('id'));

        return $this->membre($email, $role->code);
    }

    private function source(array $remplace = []): Source
    {
        return Source::create(array_replace([
            'code' => 'SRC-A4-'.Source::count(),
            'kind' => 'ouvrage',
            'title_fr' => 'Manuel de psychologie du développement',
            'authority_fr' => 'Éditions du CRMEF',
            'session_label' => 'Novembre 2025',
            'url' => 'https://exemple.ma/manuel',
            'location_note_fr' => 'Pages 40-52',
        ], $remplace));
    }

    /** Un brouillon qui cite la source : c'est lui que la propagation touche. */
    private function questionCitant(Source $source): Question
    {
        $exam = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $noeud = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();

        return app(QuestionAuthoringService::class)->rediger(
            $this->auteur,
            [
                'exam_id' => $exam->id,
                'competency_node_id' => $noeud->id,
                'locale' => 'fr',
                'stem' => 'Une question qui cite cette source.',
                'explanation' => 'Sa justification.',
                'kind' => 'qcm_single',
            ],
            [
                ['content' => 'A', 'is_correct' => true, 'rationale' => 'juste'],
                ['content' => 'B', 'is_correct' => false, 'rationale' => 'faux', 'cause' => 'calcul'],
            ],
            $source,
            'p. 42',
        );
    }

    /**
     * Une requête HTTP COMPLÈTE, et pas seulement le composant.
     *
     * Un test de composant Livewire ne rend pas la mise en page du panneau : sa
     * barre supérieure, son menu de compte, sa navigation. C'est exactement là
     * que le lot A4 s'est cassé une fois — Filament exigeait un nom
     * d'utilisateur que nos comptes n'ont pas — sans qu'aucun des tests de
     * composants ne s'en aperçoive. Une page rendue pour de bon par écran est
     * le prix de cette classe de défaut.
     */
    public function test_la_liste_des_sources_se_rend_entierement(): void
    {
        $this->source();

        $this->actingAs($this->relecteur)
            ->get('/admin/sources')
            ->assertOk()
            ->assertSee('Non vérifiée');
    }

    // --- L'acte de vérification ------------------------------------------------

    public function test_la_verification_passe_par_le_service_et_enregistre_qui_et_quand(): void
    {
        $source = $this->source();
        $question = $this->questionCitant($source);

        $this->assertSame(
            'unverified',
            $question->contentSources->first()->pivot->verification,
            'Citer une source n\'est pas la vérifier.'
        );

        Livewire::actingAs($this->relecteur)
            ->test(ListSources::class)
            ->callAction(TestAction::make('verifier')->table($source));

        $source->refresh();

        $this->assertNotNull($source->verified_at, 'Le QUAND.');
        $this->assertSame($this->relecteur->id, $source->verified_by, 'Le QUI.');

        /* La propagation est le fait que SEUL le service produit : un bouton
         * qui écrirait les deux colonnes lui-même laisserait la citation
         * derrière lui, et la question resterait inéligible au diagnostic sans
         * qu'on comprenne pourquoi. */
        $this->assertSame(
            'verified',
            $question->fresh()->contentSources->first()->pivot->verification,
            'La vérification qualifie la SOURCE, et redescend à ses citations modifiables.'
        );
    }

    public function test_un_redacteur_sans_la_permission_de_relecture_ne_verifie_pas(): void
    {
        $source = $this->source();

        Livewire::actingAs($this->auteur)
            ->test(ListSources::class)
            ->assertActionHidden(TestAction::make('verifier')->table($source));

        Livewire::actingAs($this->relecteur)
            ->test(ListSources::class)
            ->assertActionVisible(TestAction::make('verifier')->table($source));
    }

    public function test_le_bouton_disparait_une_fois_la_source_verifiee(): void
    {
        $source = $this->source();

        $composant = Livewire::actingAs($this->relecteur)->test(ListSources::class);
        $composant->callAction(TestAction::make('verifier')->table($source));

        Livewire::actingAs($this->relecteur)
            ->test(ListSources::class)
            ->assertActionHidden(TestAction::make('verifier')->table($source->fresh()));
    }

    // --- L'invalidation, VUE sans rechargement ---------------------------------

    /**
     * LE CŒUR DE CETTE SURFACE.
     *
     * On modifie un champ d'identification depuis le formulaire, et l'écran
     * doit AVOIR CHANGÉ à la fin de la même requête. Le test lit le rendu, pas
     * la base : la base a raison depuis le PAS-29, la question ici est de
     * savoir si l'écran le sait.
     */
    public function test_modifier_un_champ_d_identification_se_voit_immediatement(): void
    {
        $source = $this->source();
        $question = $this->questionCitant($source);

        app(SourceVerificationService::class)->verifier($source, $this->relecteur);

        $composant = Livewire::actingAs($this->relecteur)
            ->test(EditSource::class, ['record' => $source->fresh()->getRouteKey()]);

        $composant->assertSee('Source vérifiée');

        $composant
            ->fillForm(['title_fr' => 'Manuel de psychologie du développement, 2e édition'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSee('Source non vérifiée')
            ->assertDontSee('Source vérifiée');

        $this->assertNull($source->fresh()->verified_at);
        $this->assertSame(
            'unverified',
            $question->fresh()->contentSources->first()->pivot->verification,
            'La citation redescend avec la source.'
        );
    }

    /**
     * L'AUTRE SENS DE LA GARANTIE, et il n'est pas décoratif.
     *
     * Un écran qui affiche « non vérifiée » à chaque enregistrement serait
     * « toujours juste » sur le test précédent tout en étant faux. Modifier un
     * repère de lecture ne coûte rien, et cela doit se voir aussi.
     */
    public function test_un_repere_de_lecture_ne_coute_pas_la_verification(): void
    {
        $source = $this->source();
        app(SourceVerificationService::class)->verifier($source, $this->relecteur);

        Livewire::actingAs($this->relecteur)
            ->test(EditSource::class, ['record' => $source->fresh()->getRouteKey()])
            ->fillForm(['location_note_fr' => 'Pages 40-55'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSee('Source vérifiée');

        $this->assertNotNull(
            $source->fresh()->verified_at,
            'Préciser OÙ lire ne change pas QUEL document a été contrôlé.'
        );
    }

    /**
     * La liste `Source::COLONNES_DE_SENS` DÉCRIT le déclencheur du PAS-29. Une
     * description peut mentir : ce test la confronte à la base, colonne par
     * colonne et dans les deux sens.
     *
     * C'est ce qui autorise le formulaire à annoncer le coût avant
     * l'enregistrement sans devenir une seconde source de vérité.
     */
    public function test_les_colonnes_annoncees_sont_exactement_celles_que_la_base_sanctionne(): void
    {
        $nouvelles = [
            'code' => 'SRC-A4-RENOMME',
            'kind' => 'annale',
            'title_fr' => 'Un autre titre',
            'title_ar' => 'عنوان آخر',
            'authority_fr' => 'Une autre autorité',
            'authority_ar' => 'سلطة أخرى',
            'session_label' => 'Juin 2026',
            'url' => 'https://exemple.ma/autre',
        ];

        $this->assertSame(
            Source::COLONNES_DE_SENS,
            array_keys($nouvelles),
            'Le test doit couvrir la liste entière, sans en oublier une au fil des ajouts.'
        );

        foreach ($nouvelles as $colonne => $valeur) {
            $source = $this->source();
            app(SourceVerificationService::class)->verifier($source, $this->relecteur);

            $source->update([$colonne => $valeur]);

            $this->assertNull(
                $source->fresh()->verified_at,
                "Modifier `{$colonne}` doit annuler la vérification : le formulaire l'annonce."
            );
        }

        /* Le sens inverse : une colonne absente de la liste ne sanctionne pas.
         * Sans lui, un déclencheur qui annulerait sur TOUT passerait ce test. */
        foreach (['location_note_fr', 'location_note_ar', 'languages'] as $colonne) {
            $source = $this->source();
            app(SourceVerificationService::class)->verifier($source, $this->relecteur);

            $source->update([$colonne => $colonne === 'languages' ? ['fr'] : 'ailleurs']);

            $this->assertNotNull(
                $source->fresh()->verified_at,
                "`{$colonne}` n'est pas annoncée comme porteuse de sens : elle ne doit rien coûter."
            );
        }
    }
}
