<?php

namespace Tests\Feature\Tentatives;

use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Remediation;
use App\Models\Response;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\DiagnosticComposer;
use App\Services\QuestionTransitionService;
use App\Tenancy\TenantContext;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\Crmef2025Seeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class TentativesTest extends TestCase
{
    use RefreshDatabase;

    private Exam $epreuve;

    private User $candidat;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->seed(CatalogueSeeder::class);
        $this->seed(Crmef2025Seeder::class);

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->source = Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail();

        $this->candidat = User::create([
            'email' => 'candidat@naja7i.ma', 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $this->candidat->grantCandidateRole();

        $this->peuplerBanque();
    }

    /** Une question éligible au diagnostic par sous-domaine, plusieurs fois. */
    private function peuplerBanque(int $parSousDomaine = 4): void
    {
        $valideur = User::create([
            'email' => 'valideur@naja7i.ma', 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);

        $sousDomaines = CompetencyNode::where('exam_id', $this->epreuve->id)->where('depth', 1)->get();

        foreach ($sousDomaines as $noeud) {
            $remediation = Remediation::create([
                'competency_node_id' => $noeud->id, 'locale' => 'fr',
                'title' => "Remédiation {$noeud->code}", 'content' => 'Contenu.',
            ]);

            for ($i = 1; $i <= $parSousDomaine; $i++) {
                /* Les champs de transition ne sont plus assignables en masse
                 * (REVUE PAS-5 BLOC-1) : la question naît en brouillon et gagne
                 * sa publication par le service, seul chemin autorisé. */
                $question = Question::create([
                    'exam_id' => $this->epreuve->id, 'competency_node_id' => $noeud->id,
                    'locale' => 'fr', 'sibling_group' => (string) Str::uuid7(),
                    'stem' => "Question {$i} sur {$noeud->code} ?",
                    'explanation' => 'Justification de la bonne réponse.',
                    'remediation_id' => $remediation->id,
                ]);

                $options = [
                    ['Option A', false, 'A est fausse parce que…', 'confusion_notions'],
                    ['Option B', true,  'B est juste parce que…',  null],
                    ['Option C', false, 'C est fausse parce que…', 'lecture_enonce'],
                    ['Option D', false, 'D est fausse parce que…', 'connaissance_absente'],
                ];

                foreach ($options as $p => [$contenu, $juste, $justif, $cause]) {
                    QuestionOption::create([
                        'question_id' => $question->id, 'position' => $p + 1,
                        'content' => $contenu, 'is_correct' => $juste,
                        'rationale' => $justif, 'cause' => $cause,
                    ]);
                }

                $question->contentSources()->attach($this->source->id, ['verification' => 'verified']);

                $this->publier($question, $valideur);
            }
        }
    }

    /**
     * Conduit une question du brouillon à la publication éligible au
     * diagnostic, par le seul chemin désormais ouvert.
     *
     * Le fixture emprunte exactement le parcours qu'un rédacteur suivra : c'est
     * ce qui fait que les contrôles éditoriaux — quatre options, cause sur
     * chaque distracteur, source vérifiée, valideur distinct de l'auteur — sont
     * réellement opposés à ces questions de test, et non contournés.
     */
    private function publier(Question $question, User $valideur): Question
    {
        $transitions = app(QuestionTransitionService::class);

        $transitions->submitForReview($question);
        $transitions->markReviewed($question, $valideur);
        $transitions->validate($question, $valideur);

        return $transitions->publish($question, forDiagnostic: true);
    }

    private function service(): AttemptService
    {
        return app(AttemptService::class);
    }

    // --- Idempotence -------------------------------------------------------

    public function test_rejouer_la_meme_cle_ne_cree_pas_deux_tentatives(): void
    {
        $cle = (string) Str::uuid7();

        $a = $this->service()->startDiagnostic($this->candidat, $this->epreuve, 'fr', $cle);
        $b = $this->service()->startDiagnostic($this->candidat, $this->epreuve, 'fr', $cle);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Attempt::count());
    }

    public function test_une_seconde_cle_reprend_la_tentative_ouverte(): void
    {
        $a = $this->service()->startDiagnostic($this->candidat, $this->epreuve, 'fr', (string) Str::uuid7());
        $b = $this->service()->startDiagnostic($this->candidat, $this->epreuve, 'fr', (string) Str::uuid7());

        $this->assertSame($a->id, $b->id, 'Un candidat ne doit pas pouvoir ouvrir dix diagnostics et garder le meilleur.');
    }

    public function test_deux_diagnostics_ouverts_sur_la_meme_epreuve_sont_impossibles_en_base(): void
    {
        $this->service()->startDiagnostic($this->candidat, $this->epreuve, 'fr', (string) Str::uuid7());

        $this->expectException(QueryException::class);

        Attempt::create([
            'user_id' => $this->candidat->id, 'exam_id' => $this->epreuve->id,
            'locale' => 'fr', 'idempotency_key' => (string) Str::uuid7(),
            'kind' => 'diagnostic', 'status' => 'in_progress', 'started_at' => now(),
        ]);
    }

    public function test_repondre_deux_fois_met_a_jour_sans_dupliquer(): void
    {
        $attempt = $this->service()->startDiagnostic($this->candidat, $this->epreuve, 'fr', (string) Str::uuid7());
        $item = $attempt->items->first();
        $options = $item->question->options;

        $this->service()->answer($item, $options[0], 'guess');
        $this->service()->answer($item, $options[1], 'sure');

        $this->assertSame(1, Response::where('attempt_item_id', $item->id)->count());
        $this->assertSame('sure', $item->fresh()->response->confidence);
        $this->assertSame(1, $attempt->fresh()->answered_count);
    }

    // --- Composition pondérée ----------------------------------------------

    public function test_la_serie_suit_les_poids_officiels_des_domaines(): void
    {
        $attempt = $this->service()->startDiagnostic($this->candidat, $this->epreuve, 'fr', (string) Str::uuid7(), total: 10);

        $parRacine = $attempt->items->groupBy(fn (AttemptItem $i) => $i->node->parent_id)
            ->map(fn ($groupe) => $groupe->count());

        // Psychologie 40 %, Approches 30 %, Sociologie 30 % → 4 / 3 / 3.
        $this->assertSame(10, $attempt->items->count());
        $this->assertSame(3, $parRacine->count(), 'Les trois domaines doivent être représentés.');
        $this->assertSame(4, $parRacine->max(), 'Le domaine à 40 % doit recevoir 4 questions sur 10.');
    }

    public function test_une_epreuve_sans_banque_suffisante_ne_lance_pas_de_diagnostic(): void
    {
        $didactique = Exam::where('code', 'CRMEF-FR-DID-2025')->firstOrFail();

        $this->assertFalse(app(DiagnosticComposer::class)->isReady($didactique, 'fr'));

        $this->expectException(RuntimeException::class);
        $this->service()->startDiagnostic($this->candidat, $didactique, 'fr', (string) Str::uuid7());
    }

    public function test_une_question_n_apparait_jamais_deux_fois(): void
    {
        $attempt = $this->service()->startDiagnostic($this->candidat, $this->epreuve, 'fr', (string) Str::uuid7());

        $this->assertSame(
            $attempt->items->count(),
            $attempt->items->pluck('question_id')->unique()->count()
        );
    }

    // --- Le temps appartient au serveur ------------------------------------

    public function test_l_heure_du_client_n_arbitre_jamais(): void
    {
        $attempt = $this->service()->startDiagnostic($this->candidat, $this->epreuve, 'fr', (string) Str::uuid7());
        $item = $attempt->items->first();

        $mensonge = now()->subDays(3)->toIso8601String();
        $response = $this->service()->answer($item, $item->question->options->first(), 'sure', 1200, $mensonge);

        $this->assertTrue($response->answered_at->isToday(), 'answered_at doit venir du serveur.');
        $this->assertTrue($response->client_reported_at->isBefore(now()->subDay()), 'L\'heure du client est conservée telle quelle.');
    }

    public function test_une_tentative_expiree_refuse_toute_reponse(): void
    {
        $attempt = $this->service()->startDiagnostic(
            $this->candidat, $this->epreuve, 'fr', (string) Str::uuid7(), durationMinutes: 30
        );
        $attempt->update(['expires_at' => now()->subMinute()]);

        $this->expectException(RuntimeException::class);
        $this->service()->answer($attempt->fresh()->items->first(), null, 'guess');
    }

    // --- Soumission ---------------------------------------------------------

    public function test_la_correction_n_est_figee_qu_a_la_soumission(): void
    {
        $attempt = $this->service()->startDiagnostic($this->candidat, $this->epreuve, 'fr', (string) Str::uuid7());

        foreach ($attempt->items as $item) {
            $this->service()->answer($item, $item->question->correctOption(), 'sure');
        }

        // Pendant la tentative, aucun verdict : le candidat ne doit rien déduire.
        $this->assertNull(Response::first()->is_correct);

        $clos = $this->service()->submit($attempt);

        $this->assertSame('submitted', $clos->status);
        $this->assertSame(10, $clos->correct_count);
        $this->assertTrue(Response::first()->fresh()->is_correct);
    }

    public function test_rejouer_la_soumission_est_sans_effet(): void
    {
        $attempt = $this->service()->startDiagnostic($this->candidat, $this->epreuve, 'fr', (string) Str::uuid7());
        $this->service()->answer($attempt->items->first(), $attempt->items->first()->question->correctOption(), 'sure');

        $premier = $this->service()->submit($attempt);
        $second = $this->service()->submit($premier);

        $this->assertSame($premier->submitted_at->timestamp, $second->submitted_at->timestamp);
        $this->assertSame(1, $second->correct_count);
    }

    public function test_une_question_sans_reponse_ne_compte_pas_comme_fausse_avant_soumission(): void
    {
        $attempt = $this->service()->startDiagnostic($this->candidat, $this->epreuve, 'fr', (string) Str::uuid7());
        $clos = $this->service()->submit($attempt);

        $this->assertSame(0, $clos->correct_count);
        $this->assertSame(0, Response::count(), 'Aucune réponse fantôme ne doit être créée.');
    }

    // --- Certitude+ (F02) ---------------------------------------------------

    public function test_juste_par_hasard_se_distingue_de_juste_par_maitrise(): void
    {
        $attempt = $this->service()->startDiagnostic($this->candidat, $this->epreuve, 'fr', (string) Str::uuid7());
        $items = $attempt->items;

        $this->service()->answer($items[0], $items[0]->question->correctOption(), 'guess');
        $this->service()->answer($items[1], $items[1]->question->correctOption(), 'sure');
        $this->service()->answer($items[2], $items[2]->question->distractors()->first(), 'sure');

        $this->service()->submit($attempt);

        $this->assertTrue($items[0]->fresh()->response->isLuckyGuess());
        $this->assertFalse($items[1]->fresh()->response->isLuckyGuess());
        $this->assertTrue($items[2]->fresh()->response->isConfidentError());
    }

    // --- Quota de causes (fiche F03) ---------------------------------------

    public function test_le_compte_gratuit_voit_deux_causes_puis_l_invitation(): void
    {
        $attempt = $this->service()->startDiagnostic($this->candidat, $this->epreuve, 'fr', (string) Str::uuid7());

        foreach ($attempt->items->take(3) as $item) {
            $this->service()->answer($item, $item->question->distractors()->first(), 'hesitant');
        }
        $this->service()->submit($attempt);

        $service = $this->service();

        $this->assertTrue($service->canRevealCause($this->candidat, false)['allowed']);
        $service->markCauseRevealed($this->candidat, $attempt->items[0]->fresh()->response);

        $this->assertTrue($service->canRevealCause($this->candidat, false)['allowed']);
        $service->markCauseRevealed($this->candidat, $attempt->items[1]->fresh()->response);

        $etat = $service->canRevealCause($this->candidat, false);
        $this->assertFalse($etat['allowed'], 'La troisième cause doit être derrière l\'abonnement.');
        $this->assertSame(2, $etat['revealed']);
    }

    public function test_revoir_une_cause_deja_revelee_ne_recoute_rien(): void
    {
        $attempt = $this->service()->startDiagnostic($this->candidat, $this->epreuve, 'fr', (string) Str::uuid7());
        $item = $attempt->items->first();
        $this->service()->answer($item, $item->question->distractors()->first(), 'guess');
        $this->service()->submit($attempt);

        $response = $item->fresh()->response;

        $this->service()->markCauseRevealed($this->candidat, $response);
        $this->service()->markCauseRevealed($this->candidat, $response->fresh());

        $this->assertSame(1, $this->service()->canRevealCause($this->candidat, false)['revealed']);
    }

    public function test_le_quota_ne_se_remet_pas_a_zero_avec_le_temps(): void
    {
        $attempt = $this->service()->startDiagnostic($this->candidat, $this->epreuve, 'fr', (string) Str::uuid7());
        $item = $attempt->items->first();
        $this->service()->answer($item, $item->question->distractors()->first(), 'guess');
        $this->service()->submit($attempt);

        $this->service()->markCauseRevealed($this->candidat, $item->fresh()->response);

        $this->travel(40)->days();

        $this->assertSame(1, $this->service()->canRevealCause($this->candidat, false)['revealed']);
    }

    public function test_un_abonne_n_est_pas_plafonne(): void
    {
        $etat = $this->service()->canRevealCause($this->candidat, true);

        $this->assertTrue($etat['allowed']);
        $this->assertSame(0, $etat['quota']);
    }

    // --- Isolation tenant ---------------------------------------------------

    public function test_les_tentatives_sont_isolees_par_tenant(): void
    {
        $attempt = $this->service()->startDiagnostic($this->candidat, $this->epreuve, 'fr', (string) Str::uuid7());

        $centre = Tenant::create(['slug' => 'centre-fes', 'name' => 'Centre de Fès']);
        app(TenantContext::class)->set($centre);

        $this->assertSame(0, Attempt::count());
        $this->assertNull(Attempt::where('uuid', $attempt->uuid)->first());
        $this->assertSame(0, AttemptItem::count());
        $this->assertSame(0, Response::count());
    }

    public function test_une_option_d_une_autre_question_est_refusee(): void
    {
        $attempt = $this->service()->startDiagnostic($this->candidat, $this->epreuve, 'fr', (string) Str::uuid7());

        $this->expectException(RuntimeException::class);

        $this->service()->answer(
            $attempt->items[0],
            $attempt->items[1]->question->options->first(),
            'sure'
        );
    }

    // --- Aucune clé interne -------------------------------------------------

    public function test_aucune_cle_interne_dans_la_serialisation(): void
    {
        $attempt = $this->service()->startDiagnostic($this->candidat, $this->epreuve, 'fr', (string) Str::uuid7());
        $payload = $attempt->load('items')->toArray();

        foreach (['id', 'tenant_id', 'user_id', 'exam_id', 'idempotency_key'] as $interdit) {
            $this->assertArrayNotHasKey($interdit, $payload);
        }
        foreach ($payload['items'] as $item) {
            $this->assertArrayNotHasKey('question_id', $item);
            $this->assertArrayNotHasKey('competency_node_id', $item);
        }
    }
}
