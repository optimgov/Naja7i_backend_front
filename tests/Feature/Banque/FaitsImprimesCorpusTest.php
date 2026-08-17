<?php

namespace Tests\Feature\Banque;

use App\Models\Attempt;
use App\Models\BlueprintModel;
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
use App\Services\SimulationReport;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Les trois faits IMPRIMÉS sur les sujets — corpus §4.2.
 *
 * F1 cinq options · F2 pénalité négative · F3 numérotation réelle.
 *
 * Ce ne sont pas des choix de conception à éprouver : ce sont des consignes
 * lues sur les épreuves, que le produit ignorait. Chaque test cite le fait qu'il
 * défend.
 */
class FaitsImprimesCorpusTest extends TestCase
{
    use RefreshDatabase;

    private Exam $epreuve;

    private Source $source;

    private User $auteur;

    private User $valideur;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->source = Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail();
        $this->auteur = $this->personne('auteur.corpus@naja7i.ma');
        $this->valideur = $this->personne('valideur.corpus@naja7i.ma');
    }

    // ═══════════════════════════════════════════════ F1 — cinq options

    /**
     * LE FAIT : « اقتُرحت لكل سؤال خمسة أجوبة (A ; B ; C ; D ; E) واحد منها هو
     * الصحيح، فقط » — 2024 et 2025, les trois voies.
     *
     * Avant ce lot, cette question était REFUSÉE à la publication : la garde
     * exigeait exactement quatre options. Une question fidèle à l'épreuve
     * réelle ne pouvait pas être publiée.
     */
    public function test_une_question_a_cinq_options_se_publie_quand_l_epreuve_les_annonce(): void
    {
        $this->epreuve->update(['options_count' => 5]);

        $question = $this->questionValidee(5);

        app(QuestionTransitionService::class)->publish($question, forDiagnostic: true);

        $this->assertSame('published', $question->fresh()->status);
        $this->assertSame(5, $question->options()->count());
    }

    /**
     * L'EXACTITUDE N'EST PAS PERDUE. La règle n'est pas devenue « au moins
     * quatre » partout : là où l'épreuve annonce son nombre, il est exigé à
     * l'unité. Une question à quatre options sur une épreuve qui en annonce
     * cinq n'est pas fidèle, et ne se publie pas.
     */
    public function test_quatre_options_sont_refusees_quand_l_epreuve_en_annonce_cinq(): void
    {
        $this->epreuve->update(['options_count' => 5]);

        $question = $this->questionValidee(4);

        $defauts = app(QuestionIntegrityChecker::class)->structuralIssues($question);

        $this->assertNotEmpty($defauts);
        $this->assertStringContainsString('annonce 5 options', implode(' ', $defauts));
    }

    /**
     * SANS DÉCLARATION DE L'ÉPREUVE, UN PLANCHER — et pas l'anarchie.
     *
     * Quatre n'est plus un nombre officiel, c'est un seuil de structure : aucun
     * sujet du corpus n'en compte moins. Une question à trois options reste
     * refusée exactement comme avant ce lot.
     */
    public function test_sans_declaration_le_plancher_de_quatre_tient(): void
    {
        /* L'épreuve DÉCLARE cinq options depuis ce lot : on la remet à nul
         * pour éprouver le plancher, qui ne vaut que sans déclaration. */
        $this->epreuve->update(['options_count' => null]);

        $trois = app(QuestionIntegrityChecker::class)->structuralIssues($this->questionValidee(3));
        $this->assertStringContainsString('au moins 4 options', implode(' ', $trois));

        $cinq = app(QuestionIntegrityChecker::class)->structuralIssues($this->questionValidee(5));
        $this->assertSame([], array_filter($cinq, fn ($d) => str_contains($d, 'options')));
    }

    /**
     * LA GARANTIE DE DERNIER RECOURS DIT LA MÊME CHOSE.
     *
     * Le déclencheur PostgreSQL portait lui aussi « exactement 4 ». On écrit
     * ici EN SQL DIRECT, sans passer par le service : c'est le seul moyen de
     * prouver que la base refuse d'elle-même, et non que le service l'a
     * devancée.
     */
    public function test_le_declencheur_postgresql_refuse_aussi_le_mauvais_compte(): void
    {
        $this->epreuve->update(['options_count' => 5]);

        $question = $this->questionValidee(4);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('annonce 5 options');

        DB::statement(
            "UPDATE questions SET status = 'published', published_at = now() WHERE id = ?",
            [$question->id]
        );
    }

    /** Le plancher est porté par la base, pas seulement par le service. */
    public function test_la_base_refuse_une_epreuve_a_moins_de_quatre_options(): void
    {
        $this->expectException(QueryException::class);

        DB::statement('UPDATE exams SET options_count = 3 WHERE id = ?', [$this->epreuve->id]);
    }

    /**
     * UNE CONDITION DE PUBLICATION, JAMAIS D'EXISTENCE — et c'est ce qui rendra
     * l'import des annales possible.
     *
     * Les 1 411 annales du corpus arrivent avec ce que le sujet imprime : les
     * blocs 2023 comptent QUATRE options. Si le nombre déclaré par l'épreuve
     * gardait la création, l'import de la phase 3 se heurterait au même mur que
     * la banque de démonstration, et il faudrait affaiblir la règle pour le
     * laisser passer.
     *
     * Vérifié plutôt que supposé : `structuralIssues` n'est atteint que par
     * `publicationIssues` et `diagnosticIssues`. Rien ne garde ni la création,
     * ni la soumission à relecture, ni la validation pédagogique.
     *
     * CE TEST TIENT LE CÔTÉ « ÇA EXISTE ».
     */
    public function test_un_brouillon_a_quatre_options_existe_meme_si_l_epreuve_en_annonce_cinq(): void
    {
        $this->epreuve->update(['options_count' => 5]);

        $question = $this->questionBrouillon(4);

        $this->assertSame('draft', $question->status);
        $this->assertSame(4, $question->options()->count());
        $this->assertDatabaseHas('questions', ['id' => $question->id, 'status' => 'draft']);

        /* Et il monte jusqu'à « validée pédagogiquement » sans obstacle : la
         * chaîne éditoriale peut le relire, ce qui est bien l'objet d'une file
         * de relecture. */
        $question->contentSources()->attach($this->source->id, ['verification' => 'verified']);

        $service = app(QuestionTransitionService::class);
        $service->submitForReview($question);
        $service->markReviewed($question->fresh(), $this->relecteurDeControle());
        $service->validate($question->fresh(), $this->valideur);

        $this->assertSame('pedagogically_validated', $question->fresh()->status);
    }

    /**
     * CE TEST TIENT L'AUTRE CÔTÉ : le même brouillon ne devient ni publiable ni
     * éligible au diagnostic. Une file de relecture n'est pas une banque.
     */
    public function test_le_meme_brouillon_n_est_ni_publiable_ni_eligible(): void
    {
        $this->epreuve->update(['options_count' => 5]);

        $question = $this->questionValidee(4);
        $checker = app(QuestionIntegrityChecker::class);

        $this->assertFalse($checker->canBePublished($question));

        $this->assertStringContainsString(
            'annonce 5 options',
            implode(' ', $checker->publicationIssues($question))
        );

        $this->assertStringContainsString(
            'annonce 5 options',
            implode(' ', $checker->diagnosticIssues($question))
        );

        /* Et le service refuse pour de bon, pas seulement en avis. */
        $this->expectException(\RuntimeException::class);
        app(QuestionTransitionService::class)->publish($question, forDiagnostic: true);
    }

    // ═══════════════════════════════════════════ F2 — pénalité négative

    /**
     * LE FAIT : « تنقط الإجابة بنقطة واحدة (1ن)، وصفر (0) في حالة عدم الإجابة؛
     * يعتمد تنقيط سالب عن كل إجابة خاطئة أو ملغاة » — 2025, les trois voies.
     *
     * TROIS ÉTATS, et le troisième est le plus important : `null` veut dire
     * « le descriptif ne le dit pas », jamais « pas de pénalité ». Un booléen
     * par défaut à `false` aurait affirmé l'absence de pénalité sur toutes les
     * épreuves dont la page de garde manque.
     */
    public function test_la_penalite_a_trois_etats_et_le_defaut_est_l_inconnu(): void
    {
        $didactique = BlueprintModel::whereRelation('exam', 'code', 'CRMEF-FR-DID-2025')->firstOrFail();

        /* Aucun sujet 2025 de didactique française n'est dans le corpus. */
        $this->assertNull($didactique->negative_marking);

        /* Le sujet de la voie B l'imprime : true. */
        $voieB = BlueprintModel::whereRelation('exam', 'code', 'CRMEF-SE-2025')->firstOrFail();
        $this->assertTrue($voieB->negative_marking);

        /* Et `false` reste disponible : les sujets 2023 impriment
         * « Chaque réponse incorrecte sera notée par zéro (0) ». */
        $didactique->update(['negative_marking' => false]);
        $this->assertFalse($didactique->fresh()->negative_marking);
    }

    /**
     * LE MONTANT NE S'INVENTE PAS. Seule l'EXISTENCE de la pénalité est
     * imprimée dans le corpus ; sa valeur ne l'est nulle part. La base refuse
     * donc un montant posé sans pénalité déclarée.
     */
    public function test_aucun_montant_de_penalite_sans_penalite_declaree(): void
    {
        $didactique = BlueprintModel::whereRelation('exam', 'code', 'CRMEF-FR-DID-2025')->firstOrFail();

        $this->expectException(QueryException::class);

        DB::statement(
            'UPDATE blueprints SET negative_marking_points = 0.25 WHERE id = ?',
            [$didactique->id]
        );
    }

    /**
     * LE RAPPORT SÉPARE LES DEUX FAÇONS DE NE PAS AVOIR DE POINT.
     *
     * Une réponse fausse COÛTE quand une pénalité s'applique ; une question
     * laissée blanche ne rapporte rien mais ne coûte rien. Le contrat déclare
     * la distinction au lieu de laisser chaque écran la recalculer — et se
     * tromper de soustraction une fois sur deux.
     *
     * AUCUN POINT N'EST CALCULÉ : le montant n'étant pas publié, le rapport
     * rend des dénombrements. Multiplier par un montant supposé serait inventer
     * un barème.
     */
    public function test_le_rapport_distingue_l_erreur_de_l_abstention(): void
    {
        $candidat = $this->personne('candidat.corpus@naja7i.ma');

        $tentative = Attempt::create([
            'user_id' => $candidat->id, 'exam_id' => $this->epreuve->id,
            'kind' => 'simulation', 'status' => 'submitted', 'locale' => 'fr',
            'idempotency_key' => (string) Str::uuid7(),
            'item_count' => 3, 'correct_count' => 1,
            'started_at' => now()->subHour(), 'submitted_at' => now(),
        ]);

        $noeud = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();

        /* Une juste, une fausse, une sans réponse. */
        foreach ([true, false, null] as $rang => $juste) {
            $question = $this->questionBrouillon(4);

            $item = $tentative->items()->create([
                'question_id' => $question->id,
                'competency_node_id' => $noeud->id,
                'position' => $rang + 1,
            ]);

            if ($juste !== null) {
                $item->response()->create([
                    'user_id' => $candidat->id,
                    'selected_option_id' => $question->options()->first()->id,
                    'is_correct' => $juste,
                    'confidence' => 'guess',
                    'answered_at' => now(),
                ]);
            }
        }

        $rapport = app(SimulationReport::class)->build($tentative->fresh());

        $this->assertSame(3, $rapport['brut']['asked']);
        $this->assertSame(2, $rapport['brut']['answered']);
        $this->assertSame(1, $rapport['brut']['wrong'], 'Une réponse fausse — celle qu\'une pénalité frappe.');
        $this->assertSame(1, $rapport['brut']['unanswered'], 'Une abstention — zéro point, jamais de pénalité.');
    }

    // ═══════════════════════════════════════════ F3 — numérotation réelle

    /**
     * LE FAIT : le collège répond de Q61 à Q120, le primaire de Q101 à Q125,
     * sur une feuille de réponses COMMUNE à plusieurs blocs. Le corpus est net :
     * « Un décalage de report d'une seule ligne invalide la totalité du bloc. »
     *
     * Un examen blanc qui numérote 1, 2, 3 entraîne au report sur la mauvaise
     * ligne — il apprend un geste faux.
     */
    public function test_l_epreuve_porte_le_premier_numero_imprime_du_bloc(): void
    {
        $this->assertSame(61, $this->epreuve->fresh()->first_question_number);
    }

    /**
     * NUL = NON DOCUMENTÉ, et le client repart de 1. Ce n'est pas un défaut :
     * c'est l'aveu qu'on ne connaît pas la numérotation de cette épreuve-là.
     * Aucun sujet 2025 de didactique française n'est dans le corpus.
     */
    public function test_une_epreuve_sans_numerotation_connue_reste_nulle(): void
    {
        $didactique = Exam::where('code', 'CRMEF-FR-DID-2025')->firstOrFail();

        $this->assertNull($didactique->first_question_number);
    }

    // ═══════════════════════════════════════════════════════ fabrique

    private function personne(string $email): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            ['password' => 'Corpus-2026!', 'locale' => 'fr', 'status' => 'active']
        );
    }

    private function questionBrouillon(int $options): Question
    {
        $noeud = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();

        $remediation = Remediation::firstOrCreate(
            ['competency_node_id' => $noeud->id, 'locale' => 'fr'],
            ['title' => 'Remédiation', 'content' => 'Contenu.', 'estimated_minutes' => 8, 'status' => 'published']
        );

        $question = Question::create([
            'exam_id' => $this->epreuve->id, 'competency_node_id' => $noeud->id,
            'locale' => 'fr', 'sibling_group' => (string) Str::uuid7(),
            'stem' => 'Énoncé '.Str::random(6).' ?', 'explanation' => 'Justification.',
            'remediation_id' => $remediation->id, 'author_id' => $this->auteur->id,
        ]);

        /*
         * La dernière option est « Aucune des propositions précédentes » quand
         * il y en a cinq — c'est la forme réelle de l'option E dans le corpus,
         * et c'est elle qui rend l'élimination progressive inopérante.
         */
        for ($i = 0; $i < $options; $i++) {
            $juste = $i === 1;
            $dernier = $i === $options - 1;

            QuestionOption::create([
                'question_id' => $question->id,
                'position' => $i + 1,
                'content' => $dernier && $options >= 5
                    ? 'Aucune des propositions précédentes'
                    : 'Proposition '.chr(65 + $i),
                'is_correct' => $juste,
                'rationale' => $juste ? 'Celle-ci est exacte.' : 'Celle-ci est fausse.',
                'cause' => $juste ? null : 'confusion_notions',
            ]);
        }

        return $question->fresh('options');
    }

    private function questionValidee(int $options): Question
    {
        $question = $this->questionBrouillon($options);
        $question->contentSources()->attach($this->source->id, ['verification' => 'verified']);

        $service = app(QuestionTransitionService::class);
        $service->submitForReview($question);
        $service->markReviewed($question->fresh(), $this->relecteurDeControle());
        $service->validate($question->fresh(), $this->valideur);

        return $question->fresh('options');
    }
}
