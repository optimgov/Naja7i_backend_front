<?php

namespace Tests\Feature\BackOffice;

use App\Filament\Resources\Questions\Pages\ListQuestions;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Remediation;
use App\Models\Role;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\QuestionAuthoringService;
use App\Services\QuestionsSoeurs;
use App\Services\QuestionTransitionService;
use App\Tenancy\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * DET-48 — le miroir désigné sort du gel, et rien d'autre avec lui.
 *
 * L'ARBITRAGE : le pointeur est de l'USAGE, pas du contenu. Il ne change rien à
 * ce qu'un candidat a lu — `mirror_available` se calcule à la lecture, et
 * aucune correction déjà servie n'y fait référence. Le précédent est
 * `eligible_for_diagnostic`, hors gel pour la même raison.
 *
 * CE QUI SE TESTE ICI EST DONC UNE FRONTIÈRE, pas une fonctionnalité. Un test
 * qui montrerait seulement que la désignation bouge laisserait passer une
 * exemption trop large — celle qui dégèlerait l'énoncé avec. Le second sens est
 * le vrai gardien, et la mutation qui élargit la soustraction du déclencheur
 * doit le faire rougir.
 */
class DesignationMiroirTest extends TestCase
{
    use RefreshDatabase;

    private Exam $epreuve;

    private CompetencyNode $noeud;

    private Source $source;

    private User $editeur;

    private User $auteur;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        Filament::setCurrentPanel('admin');

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->noeud = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();
        $this->source = Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail();

        $this->editeur = $this->membre('editeur@naja7i.ma', 'editeur');
        $this->auteur = $this->membre('auteur@naja7i.ma', 'auteur');
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

    /** Une question menée jusqu'au statut demandé, distracteur A de cause `calcul`. */
    private function question(string $statut = 'published', string $locale = 'fr'): Question
    {
        $remediation = Remediation::firstOrCreate(
            ['competency_node_id' => $this->noeud->id, 'locale' => $locale],
            ['title' => 'R', 'content' => 'x', 'estimated_minutes' => 5, 'status' => 'published'],
        );

        $question = Question::create([
            'exam_id' => $this->epreuve->id,
            'competency_node_id' => $this->noeud->id,
            'locale' => $locale,
            'sibling_group' => (string) Str::uuid7(),
            'stem' => 'Énoncé '.Str::uuid7(),
            'explanation' => 'Justification.',
            'remediation_id' => $remediation->id,
            'author_id' => $this->auteur->id,
        ]);

        foreach ([
            ['A', false, 'calcul'],
            ['B', true, null],
            ['C', false, 'lecture_enonce'],
            ['D', false, 'connaissance_absente'],
            ['Aucune des propositions précédentes', false, 'indetermine'],
        ] as $p => [$c, $juste, $cause]) {
            QuestionOption::create([
                'question_id' => $question->id, 'position' => $p + 1,
                'content' => $c, 'is_correct' => $juste, 'rationale' => 'r', 'cause' => $cause,
            ]);
        }

        $question->contentSources()->attach($this->source->id, ['verification' => 'verified']);

        if ($statut === 'draft') {
            return $question->fresh();
        }

        $transitions = app(QuestionTransitionService::class);
        $transitions->submitForReview($question);
        /* Trois actes, trois personnes : le relecteur n'est pas le valideur. */
        $transitions->markReviewed($question, $this->relecteurTiers());
        $transitions->validate($question, $this->editeur);
        $transitions->publish($question, forDiagnostic: true);

        if ($statut === 'retired') {
            $transitions->retire($question->fresh());
        }

        return $question->fresh();
    }

    // --- Le dégel --------------------------------------------------------------

    public function test_la_designation_se_modifie_sur_une_question_publiee(): void
    {
        $publiee = $this->question();
        $soeur = $this->question();

        $this->assertNull($publiee->mirror_question_id);

        Livewire::actingAs($this->editeur)
            ->test(ListQuestions::class)
            ->callAction(
                TestAction::make('designer_miroir')->table($publiee),
                ['mirror_question_id' => $soeur->id],
            );

        $this->assertSame(
            $soeur->id,
            $publiee->fresh()->mirror_question_id,
            'Le pointeur est de l\'usage : il se change après publication.'
        );
        $this->assertSame('published', $publiee->fresh()->status, 'Et rien d\'autre ne bouge.');
    }

    /**
     * LE SECOND SENS, ET C'EST LUI QUI GARDE LA PORTE.
     *
     * Le dégel doit porter sur UNE colonne. Toute exemption plus large
     * rouvrirait ce que le PAS-12 a fermé : un énoncé déjà lu par des candidats
     * ne se récrit pas, il se remplace par une nouvelle version.
     *
     * C'est le test que la mutation doit faire tomber — élargir la
     * soustraction du déclencheur à `stem` le rend vert à tort.
     */
    public function test_le_reste_du_contenu_demeure_gele(): void
    {
        $publiee = $this->question();

        foreach ([
            'stem' => 'Un énoncé récrit après coup',
            'explanation' => 'Une justification récrite après coup',
            'difficulty' => 4,
            'remediation_id' => null,
        ] as $colonne => $valeur) {
            try {
                /* SAVEPOINT, et il n'est pas décoratif : le refus vient d'un
                 * `RAISE EXCEPTION`, qui AVORTE la transaction PostgreSQL en
                 * cours — celle de `RefreshDatabase`. Sans transaction
                 * imbriquée, la requête suivante du test échouerait en 25P02
                 * pour une raison qui n'a rien à voir avec le gel, et les trois
                 * colonnes suivantes ne seraient jamais éprouvées. */
                DB::transaction(fn () => $publiee->update([$colonne => $valeur]));
                $this->fail("`{$colonne}` a été modifiée sur une question publiée : le gel est troué.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('est gelée', $e->getMessage(), $colonne);
            }

            /* On relit : le modèle en mémoire porte encore la valeur que la base
             * vient de refuser. */
            $publiee = $publiee->fresh();
        }
    }

    public function test_une_question_retiree_ne_se_redesigne_pas(): void
    {
        $retiree = $this->question('retired');
        $soeur = $this->question();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('n\'est plus servie');

        app(QuestionAuthoringService::class)->designerMiroir($retiree, $soeur);
    }

    // --- Ce que le dégel sert à faire ------------------------------------------

    /**
     * LE POINT DE TOUT LE PAS.
     *
     * Une bonne sœur écrite APRÈS la publication devient réellement le miroir
     * servi. Sans le dégel, il fallait retirer la question déjà servie et en
     * republier une — payer en contenu une décision d'usage.
     */
    public function test_la_designation_posee_apres_publication_est_effectivement_servie(): void
    {
        $publiee = $this->question();
        $deduite = $this->question();      // sœur par le couple, sans désignation
        $choisie = $this->question();

        $selecteur = app(QuestionsSoeurs::class);

        /* La cause est désormais un argument : une désignation n'est servie que
         * si elle tend LE piège raté (audit tournée 3, BLOC-2). Le fixture de
         * ce test donne `calcul` au distracteur A de chaque question, c'est
         * donc celle-là qu'on demande. */
        $cause = 'calcul';

        $this->assertNull(
            $selecteur->designee($publiee->fresh(), 'fr', $cause),
            'Rien n\'est désigné au départ : le repli par couple ferait foi.'
        );

        app(QuestionAuthoringService::class)->designerMiroir($publiee, $choisie);

        $servie = $selecteur->designee($publiee->fresh(), 'fr', $cause);

        $this->assertNotNull($servie);
        $this->assertSame($choisie->id, $servie->id);
        $this->assertNotSame($deduite->id, $servie->id, 'La désignation l\'emporte sur la déduction.');
    }

    // --- Qui peut le faire -----------------------------------------------------

    public function test_un_auteur_ne_redesigne_pas_une_question_publiee(): void
    {
        $publiee = $this->question();

        /* Le rôle `auteur` porte `questions.create` mais pas `questions.publish` :
         * sur une question déjà servie, changer la désignation change ce que des
         * candidats recevront, et c'est la classe de décision que `publish`
         * gouverne. */
        Livewire::actingAs($this->auteur)
            ->test(ListQuestions::class)
            ->assertActionHidden(TestAction::make('designer_miroir')->table($publiee));

        Livewire::actingAs($this->editeur)
            ->test(ListQuestions::class)
            ->assertActionVisible(TestAction::make('designer_miroir')->table($publiee));
    }

    public function test_l_action_ne_s_offre_pas_sur_un_brouillon(): void
    {
        $brouillon = $this->question('draft');

        /* Tant que le contenu est ouvert, le champ est dans le formulaire de
         * rédaction. Deux chemins vers la même colonne feraient douter de celui
         * qui fait foi. */
        Livewire::actingAs($this->editeur)
            ->test(ListQuestions::class)
            ->assertActionHidden(TestAction::make('designer_miroir')->table($brouillon));
    }

    /**
     * Un relecteur DISTINCT du valideur.
     *
     * Trois actes, trois personnes : depuis le 17 aout, le valideur n'est ni
     * l'auteur ni le relecteur. Cette fixture faisait jouer les deux roles au
     * meme compte ; on la migre plutot que de relacher la regle.
     */
    private function relecteurTiers(): User
    {
        /* PAS DE MÉMOÏSATION `static` ICI. La première écriture en gardait une,
         * et `RefreshDatabase` annule la transaction entre deux tests : le
         * compte mémorisé survivait en mémoire et plus en base, d'où une
         * violation de clé étrangère sur le test suivant. `firstOrCreate` coûte
         * une requête et ne ment jamais. */
        $compte = User::firstOrCreate(
            ['email' => 'relecteur-tiers@naja7i.ma'],
            ['password' => 'une-phrase-de-passe-solide', 'locale' => 'fr'],
        );

        $compte->markEmailAsVerified();

        $role = Role::where('code', 'editeur')->whereNull('tenant_id')->value('id');

        if (! $compte->memberships()->where('role_id', $role)->exists()) {
            $compte->memberships()->create(['role_id' => $role]);
        }

        return $compte->fresh();
    }
}
