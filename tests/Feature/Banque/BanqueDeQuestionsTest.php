<?php

namespace Tests\Feature\Banque;

use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Remediation;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\QuestionIntegrityChecker;
use App\Services\QuestionTransitionService;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BanqueDeQuestionsTest extends TestCase
{
    use RefreshDatabase;

    private Exam $epreuve;

    private CompetencyNode $sousDomaine;

    private Remediation $remediation;

    private Source $source;

    private User $auteur;

    private User $valideur;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->sousDomaine = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();
        $this->source = Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail();

        $this->remediation = Remediation::create([
            'competency_node_id' => $this->sousDomaine->id,
            'locale' => 'fr', 'title' => 'Les stades du développement',
            'content' => 'Rappel des grandes étapes.', 'estimated_minutes' => 8,
        ]);

        $this->auteur = $this->utilisateur('auteur@naja7i.ma');
        $this->valideur = $this->utilisateur('valideur@naja7i.ma');
    }

    private function utilisateur(string $email): User
    {
        return User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
    }

    /**
     * Reproduit la frontière de commit à l'intérieur de la transaction de test.
     *
     * Les gardes de la fiche F03 sont des CONSTRAINT TRIGGER différés — il le
     * faut, une question et ses options se créant dans la même transaction.
     * PostgreSQL ne les contrôle donc qu'au COMMIT. Or RefreshDatabase
     * enveloppe chaque test dans une transaction qui n'est jamais validée :
     * sans ce forçage, la garde ne se déclencherait jamais et les tests qui
     * l'exercent passeraient à vide.
     *
     * SET CONSTRAINTS ALL IMMEDIATE contrôle les gardes déjà en attente et rend
     * immédiates celles du reste de la transaction. Le trigger n'est pas
     * touché : c'est le test qui vient à sa rencontre.
     */
    private function auCommit(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    /**
     * Pose un état éditorial par le chemin d'écriture le plus direct.
     *
     * `status`, `published_at`, `validator_id`, `version` et les drapeaux
     * d'éligibilité ne sont plus assignables en masse (REVUE PAS-5 BLOC-1), et
     * c'est précisément l'objet du correctif : le chemin normal de publication
     * passe par `QuestionTransitionService`, éprouvé par son propre jeu de
     * tests.
     *
     * Les tests de ce fichier ne portent pas sur ce chemin-là. Ils portent sur
     * les gardes de la BASE et sur le checker — donc sur ce qui doit tenir même
     * quand l'écriture contourne le service. Poser l'état par `forceFill`, ici,
     * n'est pas un contournement de confort : c'est le scénario que ces gardes
     * ont pour rôle d'arbitrer.
     */
    private function etat(Question $question, array $attributs): Question
    {
        $question->forceFill($attributs)->save();

        return $question->fresh(['options', 'exam.taxonomyProfile', 'node']);
    }

    /**
     * Publie une question par le seul chemin désormais ouvert.
     *
     * PAS-11 : la publication est contrôlée EN BASE, quel que soit le chemin
     * d'écriture — un `forceFill` vers `published` depuis un brouillon est
     * refusé par le trigger, et c'est le correctif. Ces trois tests portent sur
     * les portées d'usage et le versionnement : ils ont besoin de questions
     * réellement publiées, donc du parcours éditorial complet.
     */
    private function publier(Question $question, bool $diagnostic = false, bool $simulation = false): Question
    {
        $transitions = app(QuestionTransitionService::class);

        if ($diagnostic || $simulation) {
            // Exigée par les contrôles de publication dès qu'une question sert
            // au diagnostic ou à la simulation.
            $question->contentSources()->syncWithoutDetaching([
                $this->source->id => ['verification' => 'verified'],
            ]);
        }

        $transitions->submitForReview($question);
        $transitions->markReviewed($question, $this->valideur);
        $transitions->validate($question, $this->valideur);

        return $transitions->publish($question, forDiagnostic: $diagnostic, forSimulation: $simulation);
    }

    /** Crée une question complète, saine par défaut. */
    private function question(array $overrides = [], bool $etiqueter = true): Question
    {
        $question = Question::create(array_merge([
            'exam_id' => $this->epreuve->id,
            'competency_node_id' => $this->sousDomaine->id,
            'locale' => 'fr',
            'sibling_group' => (string) Str::uuid7(),
            'stem' => 'Un professeur évalue en milieu de séquence pour ajuster son enseignement. De quelle évaluation s\'agit-il ?',
            'explanation' => 'L\'évaluation formative intervient en cours d\'apprentissage pour réguler.',
            'remediation_id' => $this->remediation->id,
            'author_id' => $this->auteur->id,
            // `status` n'est plus assignable : le brouillon est l'état par défaut.
        ], $overrides));

        $options = [
            ['Sommative',    false, 'La sommative clôt une séquence et certifie un niveau.',        'confusion_notions'],
            ['Formative',    true,  'Elle intervient en cours d\'apprentissage pour réguler.',       null],
            ['Diagnostique', false, 'La diagnostique précède l\'apprentissage, elle ne l\'ajuste pas.', 'confusion_notions'],
            ['Certificative', false, 'La certificative délivre une reconnaissance officielle.',      'connaissance_absente'],
            ['Aucune des propositions précédentes', false, 'Elle est fausse puisqu’une autre proposition est correcte.', 'indetermine'],
        ];

        foreach ($options as $i => [$contenu, $juste, $justification, $cause]) {
            QuestionOption::create([
                'question_id' => $question->id,
                'position' => $i + 1,
                'content' => $contenu,
                'is_correct' => $juste,
                'rationale' => $justification,
                'cause' => $etiqueter ? $cause : null,
            ]);
        }

        return $question->fresh(['options', 'exam.taxonomyProfile', 'node']);
    }

    // --- Intégrité garantie par la base ------------------------------------

    public function test_une_question_ne_peut_pas_avoir_deux_bonnes_reponses(): void
    {
        $question = $this->question();

        $this->expectException(QueryException::class);

        QuestionOption::create([
            'question_id' => $question->id, 'position' => 5,
            'content' => 'Autre', 'is_correct' => true, 'rationale' => 'Justification.',
        ]);
    }

    public function test_une_justification_vide_est_refusee(): void
    {
        $question = $this->question();

        $this->expectException(QueryException::class);

        QuestionOption::create([
            'question_id' => $question->id, 'position' => 5,
            'content' => 'Autre', 'is_correct' => false, 'rationale' => '   ',
        ]);
    }

    public function test_la_bonne_reponse_ne_peut_pas_porter_de_cause(): void
    {
        $question = $this->question();

        $this->expectException(QueryException::class);

        $question->correctOption()->update(['cause' => 'confusion_notions']);
    }

    public function test_une_question_ne_peut_pas_etre_son_propre_miroir(): void
    {
        $question = $this->question();

        $this->expectException(QueryException::class);

        DB::statement('UPDATE questions SET mirror_question_id = id WHERE id = ?', [$question->id]);
    }

    // --- Fiche F03 : la garde du diagnostic ---------------------------------

    public function test_une_question_non_etiquetee_ne_peut_pas_devenir_eligible_au_diagnostic(): void
    {
        $question = $this->question(etiqueter: false);
        $this->auCommit();

        $this->expectException(QueryException::class);

        $this->etat($question, ['eligible_for_diagnostic' => true]);
    }

    public function test_une_question_etiquetee_peut_devenir_eligible(): void
    {
        $question = $this->question();
        $this->auCommit();

        $this->etat($question, ['eligible_for_diagnostic' => true]);

        $this->assertTrue($question->fresh()->eligible_for_diagnostic);
    }

    public function test_retirer_une_etiquette_a_une_question_eligible_est_refuse(): void
    {
        $question = $this->question();
        $this->auCommit();
        $this->etat($question, ['eligible_for_diagnostic' => true]);

        $distracteur = $question->distractors()->first();

        $this->expectException(QueryException::class);

        $distracteur->update(['cause' => null]);
    }

    public function test_supprimer_un_distracteur_etiquete_ne_casse_pas_l_eligibilite(): void
    {
        // La suppression laisse les autres distracteurs étiquetés : le
        // contrôle structurel signalera le nombre d'options, mais la garde de
        // diagnostic ne doit pas se déclencher à tort.
        $question = $this->question();
        $this->auCommit();
        $this->etat($question, ['eligible_for_diagnostic' => true]);

        $question->distractors()->last()->delete();

        $this->assertTrue($question->fresh()->eligible_for_diagnostic);
    }

    // --- Contrôles éditoriaux ------------------------------------------------

    public function test_une_question_saine_ne_presente_aucun_defaut_structurel(): void
    {
        $this->assertSame([], app(QuestionIntegrityChecker::class)->structuralIssues($this->question()));
    }

    public function test_un_distracteur_non_etiquete_bloque_le_diagnostic(): void
    {
        $issues = app(QuestionIntegrityChecker::class)->diagnosticIssues($this->question(etiqueter: false));

        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('cause d\'erreur', implode(' ', $issues));
    }

    public function test_un_rattachement_trop_haut_est_refuse(): void
    {
        $domaine = CompetencyNode::where('code', 'SE-PSY')->firstOrFail();  // profondeur 0
        $question = $this->question(['competency_node_id' => $domaine->id]);

        $issues = app(QuestionIntegrityChecker::class)->structuralIssues($question);

        $this->assertStringContainsString('Sous-domaine', implode(' ', $issues));
    }

    public function test_le_valideur_ne_peut_pas_etre_l_auteur(): void
    {
        $question = $this->etat($this->question(), [
            'status' => 'pedagogically_validated',
            'validator_id' => $this->auteur->id,
        ]);

        $issues = app(QuestionIntegrityChecker::class)->publicationIssues($question);

        $this->assertStringContainsString('ne peut pas être l\'auteur', implode(' ', $issues));
    }

    public function test_une_question_de_diagnostic_exige_une_source_verifiee(): void
    {
        $question = $this->etat($this->question(), [
            'status' => 'pedagogically_validated',
            'validator_id' => $this->valideur->id,
            'eligible_for_diagnostic' => true,
        ]);

        $checker = app(QuestionIntegrityChecker::class);

        $this->assertStringContainsString(
            'source de contenu vérifiée',
            implode(' ', $checker->publicationIssues($question->fresh(['options', 'exam.taxonomyProfile', 'node'])))
        );

        $question->contentSources()->attach($this->source->id, ['verification' => 'verified']);

        $this->assertTrue(
            $checker->canBePublished($question->fresh(['options', 'exam.taxonomyProfile', 'node']))
        );
    }

    public function test_une_question_non_validee_ne_peut_pas_etre_publiee(): void
    {
        $question = $this->etat($this->question(), [
            'status' => 'reviewed', 'validator_id' => $this->valideur->id,
        ]);

        $this->assertFalse(app(QuestionIntegrityChecker::class)->canBePublished($question));
    }

    // --- Portées d'usage -----------------------------------------------------

    public function test_seules_les_questions_publiees_et_eligibles_alimentent_le_diagnostic(): void
    {
        $brouillon = $this->question();

        $publiee = $this->publier($this->question(), diagnostic: true);
        $publieeNonEligible = $this->publier($this->question());

        $identifiants = Question::forDiagnostic()->pluck('uuid');

        $this->assertTrue($identifiants->contains($publiee->uuid));
        $this->assertFalse($identifiants->contains($brouillon->uuid));
        $this->assertFalse($identifiants->contains($publieeNonEligible->uuid));
    }

    public function test_une_question_retiree_disparait_des_portees(): void
    {
        $question = $this->publier($this->question(), simulation: true);

        $this->assertSame(1, Question::forSimulation()->count());

        app(QuestionTransitionService::class)->retire($question);

        $this->assertSame(0, Question::forSimulation()->count());
    }

    // --- Bilinguisme : deux contenus, pas une traduction ---------------------

    public function test_les_versions_linguistiques_sont_deux_questions_liees(): void
    {
        $groupe = (string) Str::uuid7();

        $fr = $this->question(['locale' => 'fr', 'sibling_group' => $groupe]);
        $ar = $this->question([
            'locale' => 'ar', 'sibling_group' => $groupe,
            'stem' => 'يقوم أستاذ بتقويم في منتصف المقطع لتعديل تدريسه. عن أي تقويم يتعلق الأمر؟',
        ]);

        $this->assertNotSame($fr->uuid, $ar->uuid);
        $this->assertSame($groupe, $ar->sibling_group);
        $this->assertSame(2, Question::where('sibling_group', $groupe)->count());
    }

    public function test_une_locale_non_supportee_est_refusee(): void
    {
        $this->expectException(QueryException::class);

        $this->question(['locale' => 'es']);
    }

    // --- Versionnement --------------------------------------------------------

    public function test_une_correction_cree_une_version_sans_effacer_l_ancienne(): void
    {
        $transitions = app(QuestionTransitionService::class);

        $v1 = $this->publier($this->question());

        // Une question publiée ne se modifie pas : on en dérive une version.
        $v2 = $this->publier($transitions->createRevision($v1, []));

        $transitions->retire($v1);

        $this->assertNotNull(Question::find($v1->id), 'L\'ancienne version reste consultable.');
        $this->assertSame($v1->id, $v2->supersedes_id);
        $this->assertSame(1, Question::published()->count());
    }

    // --- Aucune clé interne ----------------------------------------------------

    public function test_aucune_cle_interne_dans_la_serialisation(): void
    {
        $payload = $this->question()->load('options')->toArray();

        foreach (['id', 'exam_id', 'competency_node_id', 'author_id', 'validator_id'] as $interdit) {
            $this->assertArrayNotHasKey($interdit, $payload);
        }

        foreach ($payload['options'] as $option) {
            $this->assertArrayNotHasKey('id', $option);
            $this->assertArrayNotHasKey('question_id', $option);
        }
    }
}
