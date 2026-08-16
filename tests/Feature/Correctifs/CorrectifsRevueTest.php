<?php

namespace Tests\Feature\Correctifs;

use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\LegalDocument;
use App\Models\LegalEvent;
use App\Models\MasteryScore;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Remediation;
use App\Models\Response;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VerificationToken;
use App\Services\AttemptService;
use App\Services\CauseRevealService;
use App\Services\EmailVerificationService;
use App\Services\LegalConsentService;
use App\Services\QuestionTransitionService;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * PAS-10 — Correctifs de la revue du 9 août.
 *
 * Chaque test vise le SYSTÈME, pas le service qui détecte. C'est la leçon du
 * BLOC-1 du PAS-5 : tester qu'un contrôleur sait dire non ne prouve rien tant
 * qu'on n'a pas tenté la mutation interdite par le chemin le plus direct.
 */
class CorrectifsRevueTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $plateforme;

    private User $candidat;

    private Exam $epreuve;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plateforme = Tenant::where('kind', 'platform')->firstOrFail();
        app(TenantContext::class)->set($this->plateforme);

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->source = Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail();

        $this->candidat = User::create([
            'email' => 'candidat@naja7i.ma', 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $this->candidat->grantCandidateRole();
    }

    // ===================================================================
    // PAS-2 BLOC-1 — une acceptation FR ne satisfait pas le document AR
    // ===================================================================

    public function test_accepter_les_cgu_francaises_ne_satisfait_pas_les_arabes(): void
    {
        $legal = app(LegalConsentService::class);

        $legal->recordTermsAcceptance($this->candidat, 'fr', request());

        $this->assertTrue($legal->hasAcceptedCurrent($this->candidat, LegalDocument::KIND_TERMS, 'fr'));
        $this->assertFalse(
            $legal->hasAcceptedCurrent($this->candidat, LegalDocument::KIND_TERMS, 'ar'),
            'Le candidat n\'a jamais reçu le texte arabe : affirmer qu\'il l\'a accepté serait faux.'
        );
    }

    public function test_accepter_les_deux_langues_satisfait_les_deux(): void
    {
        $legal = app(LegalConsentService::class);

        $legal->recordTermsAcceptance($this->candidat, 'fr', request());
        $legal->recordTermsAcceptance($this->candidat, 'ar', request());

        $this->assertTrue($legal->hasAcceptedCurrent($this->candidat, LegalDocument::KIND_TERMS, 'fr'));
        $this->assertTrue($legal->hasAcceptedCurrent($this->candidat, LegalDocument::KIND_TERMS, 'ar'));
    }

    public function test_les_actes_en_attente_sont_calcules_par_langue(): void
    {
        $legal = app(LegalConsentService::class);

        $legal->recordTermsAcceptance($this->candidat, 'fr', request());
        $legal->recordPrivacyAcknowledgement($this->candidat, 'fr', request());

        $this->assertSame([], $legal->pendingActions($this->candidat, 'fr'));
        $this->assertCount(2, $legal->pendingActions($this->candidat, 'ar'));
    }

    // ===================================================================
    // PAS-2 BLOC-2 — l'historique juridique est en ajout seul
    // ===================================================================

    public function test_un_acte_juridique_ne_se_modifie_pas_par_eloquent(): void
    {
        $acte = app(LegalConsentService::class)
            ->recordTermsAcceptance($this->candidat, 'fr', request());

        $this->expectException(RuntimeException::class);
        $acte->update(['action' => LegalEvent::MARKETING_GRANTED]);
    }

    public function test_un_acte_juridique_ne_se_supprime_pas_par_eloquent(): void
    {
        $acte = app(LegalConsentService::class)
            ->recordTermsAcceptance($this->candidat, 'fr', request());

        $this->expectException(RuntimeException::class);
        $acte->delete();
    }

    public function test_un_acte_juridique_resiste_au_sql_brut(): void
    {
        app(LegalConsentService::class)->recordTermsAcceptance($this->candidat, 'fr', request());

        // Le chemin le plus direct : la garde applicative ne s'applique pas ici.
        $this->expectException(QueryException::class);
        DB::statement("UPDATE legal_events SET action = 'marketing_granted'");
    }

    public function test_un_acte_juridique_resiste_a_la_suppression_en_sql_brut(): void
    {
        app(LegalConsentService::class)->recordTermsAcceptance($this->candidat, 'fr', request());

        $this->expectException(QueryException::class);
        DB::statement('DELETE FROM legal_events');
    }

    public function test_l_insertion_d_un_nouvel_acte_reste_possible(): void
    {
        $legal = app(LegalConsentService::class);

        $legal->setMarketing($this->candidat, true, 'fr', request());
        $legal->setMarketing($this->candidat, false, 'fr', request());

        $this->assertSame(2, LegalEvent::where('user_id', $this->candidat->id)->count());
    }

    // ===================================================================
    // PAS-3 BLOC-1 — la consommation du jeton est atomique
    // ===================================================================

    public function test_un_jeton_deja_consomme_est_refuse(): void
    {
        $service = app(EmailVerificationService::class);
        $service->send($this->candidat);

        $clair = $this->dernierJetonClair();

        $this->assertNotNull($service->consume($clair));
        $this->assertNull($service->consume($clair), 'Le second appel doit échouer.');
    }

    public function test_la_consommation_ne_depend_pas_d_une_lecture_prealable(): void
    {
        $service = app(EmailVerificationService::class);
        $service->send($this->candidat);
        $clair = $this->dernierJetonClair();

        // Simule le gagnant d'une course : la ligne est marquée entre la
        // lecture et l'écriture qu'aurait faites l'ancienne implémentation.
        VerificationToken::query()->update(['consumed_at' => now()]);

        $this->assertNull(
            $service->consume($clair),
            'L\'UPDATE conditionnel doit constater que la ligne n\'est plus libre.'
        );
    }

    public function test_un_jeton_expire_est_refuse_par_la_meme_condition(): void
    {
        $service = app(EmailVerificationService::class);
        $service->send($this->candidat);
        $clair = $this->dernierJetonClair();

        VerificationToken::query()->update(['expires_at' => now()->subMinute()]);

        $this->assertNull($service->consume($clair));
    }

    // ===================================================================
    // PAS-5 BLOC-1 — le contrôle éditorial est opposé, pas seulement détecté
    // ===================================================================

    public function test_le_statut_n_est_pas_assignable_en_masse(): void
    {
        $question = $this->questionBrouillon();

        // Le chemin exact du scénario de revue : création directe en publié.
        $question->update([
            'status' => 'published',
            'eligible_for_diagnostic' => true,
        ]);

        $this->assertSame('draft', $question->fresh()->status, 'status doit être ignoré par fill().');
        $this->assertFalse((bool) $question->fresh()->eligible_for_diagnostic);
    }

    public function test_publier_sans_source_verifiee_est_refuse(): void
    {
        $question = $this->questionBrouillon();
        $this->amenerAValidation($question);

        $this->expectException(RuntimeException::class);
        app(QuestionTransitionService::class)->publish($question->fresh(), forDiagnostic: true);
    }

    public function test_publier_avec_auteur_egal_valideur_est_refuse(): void
    {
        $question = $this->questionBrouillon();
        $service = app(QuestionTransitionService::class);

        $service->submitForReview($question);
        $service->markReviewed($question->fresh(), $this->candidat);

        $this->expectException(RuntimeException::class);
        $service->validate($question->fresh(), $this->candidat);   // auteur = valideur
    }

    public function test_une_transition_hors_sequence_est_refusee(): void
    {
        $question = $this->questionBrouillon();

        $this->expectException(RuntimeException::class);
        app(QuestionTransitionService::class)->publish($question);   // draft → published
    }

    public function test_une_question_complete_se_publie_par_le_service(): void
    {
        $question = $this->questionBrouillon();
        $question->contentSources()->attach($this->source->id, ['verification' => 'verified']);
        $this->amenerAValidation($question);

        $publiee = app(QuestionTransitionService::class)
            ->publish($question->fresh(), forDiagnostic: true);

        $this->assertSame('published', $publiee->status);
        $this->assertTrue((bool) $publiee->eligible_for_diagnostic);
        $this->assertSame(1, Question::forDiagnostic()->count());
    }

    // ===================================================================
    // PAS-5 BLOC-2 — le contenu publié est gelé
    // ===================================================================

    public function test_l_enonce_d_une_question_publiee_ne_se_modifie_pas(): void
    {
        $question = $this->questionPubliee();

        $this->expectException(QueryException::class);
        DB::statement('UPDATE questions SET stem = ? WHERE id = ?', ['Énoncé réécrit', $question->id]);
    }

    public function test_une_option_de_question_publiee_ne_se_modifie_pas(): void
    {
        $question = $this->questionPubliee();
        $option = $question->options->first();

        $this->expectException(QueryException::class);
        DB::statement('UPDATE question_options SET is_correct = NOT is_correct WHERE id = ?', [$option->id]);
    }

    public function test_aucune_option_ne_s_ajoute_a_une_question_publiee(): void
    {
        $question = $this->questionPubliee();

        $this->expectException(QueryException::class);
        QuestionOption::create([
            'question_id' => $question->id, 'position' => 5,
            'content' => 'Cinquième', 'is_correct' => false, 'rationale' => 'Ajoutée après coup.',
        ]);
    }

    public function test_le_retrait_d_une_question_publiee_reste_possible(): void
    {
        $question = $this->questionPubliee();

        $retiree = app(QuestionTransitionService::class)->retire($question);

        $this->assertSame('retired', $retiree->status);
        $this->assertSame(0, Question::published()->count());
    }

    public function test_une_revision_cree_une_version_et_laisse_l_originale_intacte(): void
    {
        $v1 = $this->questionPubliee();
        $enonceOriginal = $v1->stem;

        $v2 = app(QuestionTransitionService::class)
            ->createRevision($v1, ['stem' => 'Énoncé corrigé']);

        $this->assertSame(2, $v2->version);
        $this->assertSame($v1->id, $v2->supersedes_id);
        $this->assertSame('draft', $v2->status);
        $this->assertSame($enonceOriginal, $v1->fresh()->stem);
        $this->assertCount(5, $v2->options);
    }

    // ===================================================================
    // PAS-6 / PAS-7 — les unicités tiennent compte du tenant
    // ===================================================================

    public function test_un_meme_compte_peut_ouvrir_un_diagnostic_dans_deux_organismes(): void
    {
        $organisme = Tenant::create(['slug' => 'centre-fes', 'name' => 'Centre de Fès']);
        $cle = (string) Str::uuid7();

        app(TenantContext::class)->set($this->plateforme);
        $a = Attempt::create($this->attributsTentative($cle));

        app(TenantContext::class)->set($organisme);
        $b = Attempt::create($this->attributsTentative($cle));

        $this->assertNotSame($a->id, $b->id, 'Deux organismes doivent produire deux tentatives indépendantes.');
    }

    public function test_la_clé_d_idempotence_reste_unique_dans_un_meme_tenant(): void
    {
        $cle = (string) Str::uuid7();
        Attempt::create($this->attributsTentative($cle));

        $this->expectException(QueryException::class);
        Attempt::create($this->attributsTentative($cle));
    }

    public function test_la_maitrise_d_un_compte_existe_independamment_dans_deux_organismes(): void
    {
        $organisme = Tenant::create(['slug' => 'centre-agadir', 'name' => 'Centre d\'Agadir']);
        $noeud = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();

        app(TenantContext::class)->set($this->plateforme);
        MasteryScore::create($this->attributsMaitrise($noeud->id));

        app(TenantContext::class)->set($organisme);
        MasteryScore::create($this->attributsMaitrise($noeud->id));

        $this->assertSame(1, MasteryScore::count(), 'Chaque contexte ne voit que la sienne.');

        app(TenantContext::class)->set($this->plateforme);
        $this->assertSame(1, MasteryScore::count());
    }

    // ===================================================================
    // PAS-6 BLOC-2 — quota et réponse idempotents
    // ===================================================================

    /*
     * PAS-11 : ces deux contrôles ont changé de service et de vocabulaire.
     * `reveal()` répond à « la cause est-elle visible ? », là où
     * `markCauseRevealed()` répondait à « viens-tu de consommer une unité ? ».
     * L'invariant vérifié, lui, est inchangé : une cause ne coûte qu'une unité.
     */
    public function test_reveler_deux_fois_la_meme_cause_ne_consomme_qu_une_unite(): void
    {
        $response = $this->reponseFausse();
        $service = app(CauseRevealService::class);

        $this->assertTrue($service->reveal($this->candidat, $response, false));
        $this->assertTrue(
            $service->reveal($this->candidat, $response->fresh(), false),
            'La cause reste visible au second appel.'
        );

        $this->assertSame(
            1, $service->status($this->candidat, false)['revealed'],
            'Le second appel ne doit rien consommer.'
        );
    }

    public function test_le_decompte_repose_sur_l_etat_de_la_ligne_et_non_sur_une_lecture(): void
    {
        $response = $this->reponseFausse();
        $service = app(CauseRevealService::class);

        // Simule le gagnant d'une course concurrente.
        Response::where('id', $response->id)->update(['cause_revealed' => true]);

        // La cause est payée par le gagnant : elle est visible, et le perdant
        // ne décompte rien.
        $this->assertTrue($service->reveal($this->candidat, $response->fresh(), false));
        $this->assertSame(0, $service->status($this->candidat, false)['revealed']);
    }

    public function test_repondre_deux_fois_n_incremente_le_compteur_qu_une_fois(): void
    {
        $item = $this->itemDeTentative();
        $service = app(AttemptService::class);

        $service->answer($item, $item->question->options->first(), 'guess');
        $service->answer($item->fresh(), $item->question->options->last(), 'sure');

        $this->assertSame(1, $item->attempt->fresh()->answered_count);
        $this->assertSame(1, Response::where('attempt_item_id', $item->id)->count());
    }

    // ===================================================================
    // Utilitaires
    // ===================================================================

    private function dernierJetonClair(): string
    {
        // Le jeton clair n'est pas stocké : on le retrouve par la notification.
        // Ici on régénère un couple connu pour le test.
        $clair = Str::random(64);

        VerificationToken::query()->update(['token_hash' => hash('sha256', $clair)]);

        return $clair;
    }

    private function questionBrouillon(): Question
    {
        $noeud = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();

        $remediation = Remediation::firstOrCreate(
            ['competency_node_id' => $noeud->id, 'locale' => 'fr'],
            ['title' => 'Remédiation', 'content' => 'Contenu.', 'estimated_minutes' => 8, 'status' => 'published']
        );

        $question = Question::create([
            'exam_id' => $this->epreuve->id, 'competency_node_id' => $noeud->id,
            'locale' => 'fr', 'sibling_group' => (string) Str::uuid7(),
            'stem' => 'Énoncé de contrôle éditorial ?', 'explanation' => 'Justification.',
            'remediation_id' => $remediation->id, 'author_id' => $this->candidat->id,
        ]);

        foreach ([
            ['A', false, 'A est fausse.', 'confusion_notions'],
            ['B', true,  'B est juste.',  null],
            ['C', false, 'C est fausse.', 'lecture_enonce'],
            ['D', false, 'D est fausse.', 'connaissance_absente'],
            ['Aucune des propositions précédentes', false, 'Elle est fausse puisqu’une autre proposition est correcte.', 'indetermine'],
        ] as $p => [$c, $juste, $justif, $cause]) {
            QuestionOption::create([
                'question_id' => $question->id, 'position' => $p + 1,
                'content' => $c, 'is_correct' => $juste, 'rationale' => $justif, 'cause' => $cause,
            ]);
        }

        return $question->fresh('options');
    }

    private function amenerAValidation(Question $question): void
    {
        $valideur = User::create([
            'email' => 'valideur-'.Str::random(6).'@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);

        $service = app(QuestionTransitionService::class);
        $service->submitForReview($question);
        $service->markReviewed($question->fresh(), $valideur);
        $service->validate($question->fresh(), $valideur);
    }

    private function questionPubliee(): Question
    {
        $question = $this->questionBrouillon();
        $question->contentSources()->attach($this->source->id, ['verification' => 'verified']);
        $this->amenerAValidation($question);

        return app(QuestionTransitionService::class)->publish($question->fresh(), forDiagnostic: true)
            ->load('options');
    }

    /** @return array<string, mixed> */
    private function attributsTentative(string $cle): array
    {
        return [
            'user_id' => $this->candidat->id, 'exam_id' => $this->epreuve->id,
            'locale' => 'fr', 'idempotency_key' => $cle,
            'kind' => 'training', 'status' => 'in_progress',
            'started_at' => now(), 'item_count' => 1,
        ];
    }

    /** @return array<string, mixed> */
    private function attributsMaitrise(int $nodeId): array
    {
        return [
            'user_id' => $this->candidat->id, 'exam_id' => $this->epreuve->id,
            'competency_node_id' => $nodeId,
            'score' => null, 'evidence' => 'insufficient',
            'answered_count' => 2, 'correct_count' => 1, 'computed_at' => now(),
        ];
    }

    private function itemDeTentative(): AttemptItem
    {
        $question = $this->questionPubliee();

        $attempt = Attempt::create($this->attributsTentative((string) Str::uuid7()));

        return AttemptItem::create([
            'attempt_id' => $attempt->id, 'question_id' => $question->id,
            'competency_node_id' => $question->competency_node_id, 'position' => 1,
        ])->fresh(['attempt', 'question.options']);
    }

    private function reponseFausse(): Response
    {
        $item = $this->itemDeTentative();
        $service = app(AttemptService::class);

        $service->answer($item, $item->question->distractors()->first(), 'hesitant');
        $service->submit($item->attempt);

        return $item->fresh()->response;
    }
}
