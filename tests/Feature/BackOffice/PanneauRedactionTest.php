<?php

namespace Tests\Feature\BackOffice;

use App\Filament\Resources\Questions\Pages\CreateQuestion;
use App\Filament\Resources\Questions\Pages\EditQuestion;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Question;
use App\Models\Remediation;
use App\Models\Role;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\QuestionTransitionService;
use App\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Lot A4 — le panneau de rédaction.
 *
 * LES GARDES S'ÉPROUVENT À TRAVERS L'INTERFACE, pas seulement en dessous. Une
 * garde de service qui tient pendant qu'un bouton l'ignore produit un écran qui
 * ment : l'échec arrive après le clic, sans que rien n'ait prévenu. Ces tests
 * passent donc par les composants Livewire de Filament, comme un rédacteur.
 */
class PanneauRedactionTest extends TestCase
{
    use RefreshDatabase;

    private Exam $epreuve;

    private CompetencyNode $noeud;

    private Source $source;

    private User $auteur;

    private User $editeur;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        Filament::setCurrentPanel('admin');

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->noeud = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();
        $this->source = Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail();

        Remediation::firstOrCreate(
            ['competency_node_id' => $this->noeud->id, 'locale' => 'fr'],
            ['title' => 'R', 'content' => 'x', 'estimated_minutes' => 5, 'status' => 'published'],
        );

        $this->auteur = $this->membre('auteur@naja7i.ma', 'auteur');
        $this->editeur = $this->membre('editeur@naja7i.ma', 'editeur');
    }

    private function membre(string $email, ?string $role): User
    {
        $user = User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $user->markEmailAsVerified();

        if ($role !== null) {
            $user->memberships()->create([
                'role_id' => Role::where('code', $role)->whereNull('tenant_id')->value('id'),
            ]);
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function saisie(array $remplace = []): array
    {
        return array_replace([
            'exam_id' => $this->epreuve->id,
            'competency_node_id' => $this->noeud->id,
            'locale' => 'fr',
            'kind' => 'qcm_single',
            'stem' => 'Quel est le stade préopératoire selon Piaget ?',
            'explanation' => 'La justification générale de la bonne réponse.',
            'remediation_id' => Remediation::where('competency_node_id', $this->noeud->id)->value('id'),
            'source_code' => $this->source->code,
            'options' => [
                ['content' => 'A', 'is_correct' => false, 'rationale' => 'A est fausse.', 'cause' => 'confusion_notions'],
                ['content' => 'B', 'is_correct' => true, 'rationale' => 'B est juste.', 'cause' => null],
                ['content' => 'C', 'is_correct' => false, 'rationale' => 'C est fausse.', 'cause' => 'lecture_enonce'],
                ['content' => 'D', 'is_correct' => false, 'rationale' => 'D est fausse.', 'cause' => 'connaissance_absente'],
                ['content' => 'Aucune des propositions précédentes', 'is_correct' => false, 'rationale' => 'Elle est fausse puisqu’une autre proposition est correcte.', 'cause' => 'indetermine'],
            ],
        ], $remplace);
    }

    private function rediger(array $remplace = [], ?User $qui = null): Question
    {
        Livewire::actingAs($qui ?? $this->auteur)
            ->test(CreateQuestion::class)
            ->fillForm($this->saisie($remplace))
            ->call('create')
            ->assertHasNoFormErrors();

        return Question::latest('id')->firstOrFail();
    }

    // --- L'accès au panneau ----------------------------------------------------

    public function test_un_candidat_n_entre_pas_dans_le_panneau(): void
    {
        $candidat = $this->membre('candidat@naja7i.ma', null);
        $candidat->grantCandidateRole();

        $this->assertFalse(
            $candidat->canAccessPanel(Filament::getPanel('admin')),
            'Le panneau ne s\'ouvre pas à qui n\'y verrait que des refus.'
        );

        $this->assertTrue($this->auteur->canAccessPanel(Filament::getPanel('admin')));
    }

    // --- Rédaction -------------------------------------------------------------

    public function test_le_formulaire_ecrit_par_le_service_et_non_par_filament(): void
    {
        $question = $this->rediger();

        $this->assertSame('draft', $question->status, 'Une question naît brouillon.');
        $this->assertSame($this->auteur->id, $question->author_id);
        $this->assertCount(5, $question->options);

        /* Ces trois faits ne viennent PAS du formulaire : ils viennent du
         * service, et leur absence signerait une écriture directe par
         * Filament. */
        $this->assertNotNull($question->sibling_group, 'Le service pose un groupe neuf : la question reste monolingue.');
        $this->assertSame(
            'unverified',
            $question->contentSources->first()?->pivot->verification,
            'Citer une source n\'est pas la vérifier.'
        );
        $this->assertNull(
            $question->options->firstWhere('is_correct', true)->cause,
            'La cause est refusée sur la bonne réponse, même si le formulaire l\'envoie.'
        );
    }

    /*
     * ══════════════════════════════════════════════════════════════════════
     * BLOC-3 DE L'AUDIT TOURNÉE 3, VERSANT FILAMENT.
     *
     * Deux brèches dans « toute écriture passe par le service » :
     *
     *   1. `handleRecordUpdate` ne transmettait ni `locale` ni les OPTIONS ;
     *   2. le répéteur portait `->relationship()`, donc Filament sauvegardait
     *      les options LUI-MÊME, avant `handleRecordUpdate` et hors de
     *      `amender()`.
     *
     * Résultat mesuré par l'audit : dans une même sauvegarde, le contenu d'une
     * option passait bien, la langue non — sans la moindre erreur à l'écran.
     *
     * Le test A4a annoncé ne jouait que la CRÉATION, jamais l'amendement.
     * ══════════════════════════════════════════════════════════════════════
     */

    public function test_l_amendement_filament_applique_la_langue(): void
    {
        $question = $this->rediger();

        Livewire::actingAs($this->auteur)
            ->test(EditQuestion::class, ['record' => $question->uuid])
            ->fillForm([
                'exam_id' => $this->epreuve->id,
                'competency_node_id' => $this->noeud->id,
                'locale' => 'ar',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('ar', $question->fresh()->locale);
    }

    public function test_l_amendement_filament_passe_les_options_par_le_service(): void
    {
        $question = $this->rediger();

        $options = $this->saisie()['options'];
        $options[0]['content'] = 'A modifiée';
        /* Cause posée sur la BONNE réponse : le service doit la retirer. Si
         * Filament écrit la relation lui-même, elle survivrait. */
        $options[1]['cause'] = 'confusion_notions';

        Livewire::actingAs($this->auteur)
            ->test(EditQuestion::class, ['record' => $question->uuid])
            ->fillForm([
                'exam_id' => $this->epreuve->id,
                'competency_node_id' => $this->noeud->id,
                'options' => $options,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fraiche = $question->fresh()->load('options');

        $this->assertTrue(
            $fraiche->options->contains('content', 'A modifiée'),
            'L\'amendement des options doit être persisté.'
        );

        $this->assertNull(
            $fraiche->options->firstWhere('is_correct', true)->cause,
            'La cause sur la bonne réponse est retirée PAR LE SERVICE — sa survie '
            .'signerait une écriture directe de Filament.'
        );
    }

    public function test_l_amendement_filament_applique_langue_et_options_ensemble(): void
    {
        /* LE SCÉNARIO EXACT DE L'AUDIT : une seule sauvegarde qui change les
         * deux. L'un passait, l'autre était perdu en silence. */
        $question = $this->rediger();

        $options = $this->saisie()['options'];
        $options[0]['content'] = 'A vraiment modifiée';

        Livewire::actingAs($this->auteur)
            ->test(EditQuestion::class, ['record' => $question->uuid])
            ->fillForm([
                'exam_id' => $this->epreuve->id,
                'competency_node_id' => $this->noeud->id,
                'locale' => 'ar',
                'options' => $options,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fraiche = $question->fresh()->load('options');

        $this->assertSame('ar', $fraiche->locale);
        $this->assertTrue($fraiche->options->contains('content', 'A vraiment modifiée'));
    }

    public function test_la_cause_envoyee_sur_la_bonne_reponse_est_ignoree(): void
    {
        $saisie = $this->saisie();
        $saisie['options'][1]['cause'] = 'confusion_notions';   // la bonne réponse

        $question = $this->rediger($saisie);

        $this->assertNull($question->options->firstWhere('is_correct', true)->cause);
    }

    // --- Relecture -------------------------------------------------------------

    /**
     * LE BOUTON N'EXISTE PAS POUR L'AUTEUR.
     *
     * La garde vit dans `QuestionTransitionService` et y reste. Ce test vérifie
     * l'autre moitié : que l'interface ne propose pas une action conçue pour
     * refuser celui qui la voit.
     */
    public function test_l_auteur_ne_voit_pas_le_bouton_de_validation_de_sa_propre_question(): void
    {
        /*
         * C'EST UN ÉDITEUR QUI RÉDIGE, ET C'EST TOUT LE TEST.
         *
         * Le faire écrire par le rôle `auteur` ne prouverait rien : il n'a pas
         * `questions.validate`, donc le bouton serait caché de toute façon — le
         * test passerait sans que la règle « le valideur n'est pas l'auteur »
         * soit jamais exercée. Vérifié par mutation : en retirant cette règle
         * de la policy, la version précédente restait verte.
         *
         * Il faut donc quelqu'un qui PEUT valider et qui a écrit la question.
         */
        $question = $this->rediger(qui: $this->editeur);

        $second = $this->membre('editeur2@naja7i.ma', 'editeur');

        $transitions = app(QuestionTransitionService::class);
        $transitions->submitForReview($question);
        $transitions->markReviewed($question->fresh(), $second);

        Livewire::actingAs($this->editeur)
            ->test(EditQuestion::class, ['record' => $question->fresh()->getRouteKey()])
            ->assertActionHidden('valider');

        /* $second a RELU : depuis le 17 aout il ne peut pas valider non plus.
         * C'est un TROISIEME compte qui doit voir le bouton — et le verifier
         * ainsi rend la nouvelle regle opposable au lieu de la contourner. */
        Livewire::actingAs($second)
            ->test(EditQuestion::class, ['record' => $question->fresh()->getRouteKey()])
            ->assertActionHidden('valider');

        $troisieme = $this->membre('editeur3@naja7i.ma', 'editeur');

        Livewire::actingAs($troisieme)
            ->test(EditQuestion::class, ['record' => $question->fresh()->getRouteKey()])
            ->assertActionVisible('valider');
    }

    public function test_la_validation_par_l_editeur_fait_avancer_la_question(): void
    {
        $question = $this->rediger();

        $transitions = app(QuestionTransitionService::class);
        $transitions->submitForReview($question);
        $transitions->markReviewed($question->fresh(), $this->membre('relecteur-tiers@naja7i.ma', 'editeur'));

        Livewire::actingAs($this->editeur)
            ->test(EditQuestion::class, ['record' => $question->fresh()->getRouteKey()])
            ->callAction('valider');

        $this->assertSame('pedagogically_validated', $question->fresh()->status);
        $this->assertSame($this->editeur->id, $question->fresh()->validator_id);
    }

    // --- La publication depuis l'interface -------------------------------------

    /**
     * Un distracteur sans cause bloque la publication DEPUIS LE BOUTON.
     *
     * Le refus du service devient un message lisible, pas une page d'erreur :
     * la liste des motifs est l'information la plus utile de la chaîne.
     */
    public function test_un_distracteur_sans_cause_bloque_la_publication_depuis_l_interface(): void
    {
        $saisie = $this->saisie();
        $saisie['options'][2]['cause'] = null;   // le distracteur C

        $question = $this->rediger($saisie);

        $transitions = app(QuestionTransitionService::class);
        $transitions->submitForReview($question);
        $transitions->markReviewed($question->fresh(), $this->membre('relecteur-tiers-'.uniqid().'@naja7i.ma', 'editeur'));
        $transitions->validate($question->fresh(), $this->editeur);

        Livewire::actingAs($this->editeur)
            ->test(EditQuestion::class, ['record' => $question->fresh()->getRouteKey()])
            ->callAction('publier', ['for_diagnostic' => true]);

        $this->assertSame(
            'pedagogically_validated', $question->fresh()->status,
            'La publication est refusée, et la question reste où elle était.'
        );
    }

    public function test_le_formulaire_verrouille_une_question_publiee(): void
    {
        $question = $this->rediger();

        $transitions = app(QuestionTransitionService::class);
        $transitions->submitForReview($question);
        $transitions->markReviewed($question->fresh(), $this->membre('relecteur-tiers-'.uniqid().'@naja7i.ma', 'editeur'));
        $transitions->validate($question->fresh(), $this->editeur);
        $transitions->publish($question->fresh());

        $this->assertFalse(
            $this->editeur->can('update', $question->fresh()),
            'Le contenu d\'une question publiée est gelé : le formulaire ne doit pas s\'ouvrir.'
        );
    }
}
