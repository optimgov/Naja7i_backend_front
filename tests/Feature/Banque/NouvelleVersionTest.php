<?php

namespace Tests\Feature\Banque;

use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Question;
use App\Models\Remediation;
use App\Models\Role;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\QuestionAuthoringService;
use App\Services\QuestionTransitionService;
use App\Services\SourceVerificationService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * CORRIGER UNE QUESTION GELÉE — le chemin que le produit annonçait sans l'offrir.
 *
 * La modale de publication dit « le corriger ensuite demande une nouvelle
 * version ». `amender()` refuse avec la même phrase. La migration `000250` a
 * posé `version` et `supersedes_id` en écrivant « une correction crée une
 * nouvelle version, l'ancienne est retirée ».
 *
 * Mesuré avant d'écrire : sur la préproduction, 83 questions, TOUTES en
 * version 1, AUCUNE avec un `supersedes_id`. Corriger une coquille imposait de
 * retirer la question et de tout retaper.
 */
class NouvelleVersionTest extends TestCase
{
    use RefreshDatabase;

    private User $expert;

    private Question $publiee;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->expert = User::create([
            'email' => 'version@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
            'status' => 'active',
        ]);
        $this->expert->markEmailAsVerified();
        $this->expert->memberships()->create([
            'role_id' => Role::where('code', 'expert_pedagogue')->whereNull('tenant_id')->value('id'),
        ]);
        $this->expert = $this->expert->fresh();

        $this->publiee = $this->questionPubliee();
    }

    private function questionPubliee(): Question
    {
        $exam = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $noeud = CompetencyNode::where('exam_id', $exam->id)->where('depth', 1)->firstOrFail();
        $source = Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail();

        app(SourceVerificationService::class)->verifier($source, $this->expert);

        /* CINQ OPTIONS ET UNE REMÉDIATION — le refus de publication me l'a
         * appris, et il avait raison : cette épreuve DÉCLARE cinq options
         * (DET-66), et une question servie au diagnostic doit renvoyer vers
         * quelque chose à travailler. */
        $remediation = Remediation::create([
            'competency_node_id' => $noeud->id,
            'locale' => 'fr',
            'title' => 'Revoir la notion',
            'content' => 'Reformuler la règle, puis l’appliquer à un exemple neuf.',
            'estimated_minutes' => 8,
            'status' => 'published',
        ]);

        $question = app(QuestionAuthoringService::class)->rediger(
            $this->expert,
            [
                'exam_id' => $exam->id,
                'competency_node_id' => $noeud->id,
                'locale' => 'fr',
                'stem' => 'Énoncé d’origine, celui que le candidat a lu.',
                'explanation' => 'Explication d’origine.',
                'difficulty' => 3,
                'authoring' => 'human',
                'remediation_id' => $remediation->id,
            ],
            [
                ['content' => 'La bonne', 'is_correct' => true, 'rationale' => 'Parce que.'],
                ['content' => 'Le piège A', 'is_correct' => false, 'rationale' => 'Confusion.', 'cause' => 'confusion_notions'],
                ['content' => 'Le piège B', 'is_correct' => false, 'rationale' => 'Lecture.', 'cause' => 'lecture_enonce'],
                ['content' => 'Le piège C', 'is_correct' => false, 'rationale' => 'Calcul.', 'cause' => 'calcul'],
                ['content' => 'Aucune des propositions', 'is_correct' => false, 'rationale' => 'Méthode.', 'cause' => 'piege_formulation'],
            ],
            $source->fresh(),
            'p. 12',
        );

        $transitions = app(QuestionTransitionService::class);
        $question = $transitions->submitForReview($question);
        $question = $transitions->markReviewed($question, $this->expert);
        $question = $transitions->validate($question, $this->expert);

        return $transitions->publish($question, forDiagnostic: true)->fresh(['options']);
    }

    private function service(): QuestionAuthoringService
    {
        return app(QuestionAuthoringService::class);
    }

    public function test_la_copie_emporte_tout_le_contenu_et_repart_en_brouillon(): void
    {
        $copie = $this->service()->nouvelleVersion($this->expert, $this->publiee);

        $this->assertSame('draft', $copie->status);
        $this->assertSame(2, $copie->version);
        $this->assertSame($this->publiee->id, $copie->supersedes_id);

        $this->assertSame($this->publiee->stem, $copie->stem);
        $this->assertSame($this->publiee->explanation, $copie->explanation);
        $this->assertSame($this->publiee->difficulty, $copie->difficulty);
        $this->assertSame($this->publiee->competency_node_id, $copie->competency_node_id);
        $this->assertSame($this->publiee->remediation_id, $copie->remediation_id);

        /* LES SŒURS RESTENT SŒURS : les deux versions doivent demeurer
         * interchangeables pour la révision espacée. */
        $this->assertSame($this->publiee->sibling_group, $copie->sibling_group);
    }

    /**
     * LE TEST QUI DISCRIMINE — sans lui, une copie qui perdrait ses causes
     * passerait, et la question deviendrait impubliable sans qu'on sache
     * pourquoi.
     */
    public function test_chaque_distracteur_garde_sa_justification_et_sa_cause(): void
    {
        $copie = $this->service()->nouvelleVersion($this->expert, $this->publiee);

        $avant = $this->publiee->options()->orderBy('position')->get()
            ->map(fn ($o) => [$o->content, $o->is_correct, $o->rationale, $o->cause])->all();
        $apres = $copie->options()->orderBy('position')->get()
            ->map(fn ($o) => [$o->content, $o->is_correct, $o->rationale, $o->cause])->all();

        $this->assertSame($avant, $apres);
        $this->assertCount(5, $apres);
    }

    /** La citation suit, et son contrôle est relu sur l'état ACTUEL de la source. */
    public function test_la_citation_de_source_suit_la_copie(): void
    {
        $copie = $this->service()->nouvelleVersion($this->expert, $this->publiee);

        $this->assertSame(
            $this->publiee->contentSources()->pluck('sources.id')->all(),
            $copie->contentSources()->pluck('sources.id')->all(),
        );
        $this->assertSame('verified', $copie->contentSources()->first()->pivot->verification);
    }

    /**
     * LA COPIE NE NAÎT PAS VALIDÉE, et c'est le propos même du gel.
     *
     * Une copie qui arriverait déjà relue contournerait la relecture au lieu
     * de la rejouer — un contenu corrigé n'a été vérifié par personne.
     */
    public function test_la_copie_refait_la_chaine_et_n_herite_d_aucun_visa(): void
    {
        $copie = $this->service()->nouvelleVersion($this->expert, $this->publiee);

        $this->assertNull($copie->reviewer_id);
        $this->assertNull($copie->validator_id);
        $this->assertNull($copie->published_at);
        $this->assertSame($this->expert->id, $copie->author_id);
    }

    /** L'ancienne n'est pas touchée : elle reste servie tant qu'on ne la retire pas. */
    public function test_l_ancienne_reste_publiee(): void
    {
        $this->service()->nouvelleVersion($this->expert, $this->publiee);

        $this->assertSame('published', $this->publiee->fresh()->status);
        $this->assertNotNull($this->publiee->fresh()->published_at);
    }

    /** Un brouillon s'amende directement : lui ouvrir une version n'a pas de sens. */
    public function test_une_question_non_gelee_est_refusee(): void
    {
        $brouillon = Question::where('status', 'draft')->first()
            ?? $this->service()->nouvelleVersion($this->expert, $this->publiee);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('s’amende directement');

        $this->service()->nouvelleVersion($this->expert, $brouillon);
    }
}
