<?php

namespace Tests\Feature\Memoire;

use App\Models\Attempt;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\ExamSession;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Remediation;
use App\Models\ReviewSchedule;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\MemoryScheduler;
use App\Services\QuestionTransitionService;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\Crmef2025Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F07 — Rendez-vous Mémoire, le calendrier.
 *
 * Ce que ces tests défendent : un candidat ne révise que ce qu'il a MANQUÉ, la
 * certitude déclarée décide du palier, et la liste finit par se vider.
 */
class RendezVousMemoireTest extends TestCase
{
    use RefreshDatabase;

    private Exam $epreuve;

    private User $candidat;

    private CompetencyNode $noeud;

    private User $valideur;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->seed(CatalogueSeeder::class);
        $this->seed(Crmef2025Seeder::class);

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->source = Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail();
        $this->noeud = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();

        $this->valideur = $this->utilisateur('valideur@naja7i.ma');
        $this->candidat = $this->utilisateur('candidat@naja7i.ma');
        $this->candidat->grantCandidateRole();
    }

    private function utilisateur(string $email): User
    {
        return User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
    }

    /** Questions publiées, toutes de cause `confusion_notions` sur le distracteur A. */
    private function peupler(int $combien): void
    {
        $remediation = Remediation::firstOrCreate(
            ['competency_node_id' => $this->noeud->id, 'locale' => 'fr'],
            ['title' => 'R', 'content' => 'x', 'estimated_minutes' => 5, 'status' => 'published'],
        );

        $transitions = app(QuestionTransitionService::class);

        for ($i = 1; $i <= $combien; $i++) {
            $question = Question::create([
                'exam_id' => $this->epreuve->id,
                'competency_node_id' => $this->noeud->id,
                'locale' => 'fr',
                'sibling_group' => (string) Str::uuid7(),
                'stem' => "Question {$i}",
                'explanation' => 'Justification.',
                'remediation_id' => $remediation->id,
                'author_id' => $this->utilisateur("auteur-{$i}@naja7i.ma")->id,
            ]);

            foreach ([
                ['A', false, 'A est fausse.', 'confusion_notions'],
                ['B', true, 'B est juste.', null],
                ['C', false, 'C est fausse.', 'lecture_enonce'],
                ['D', false, 'D est fausse.', 'connaissance_absente'],
            ] as $p => [$c, $juste, $justif, $cause]) {
                QuestionOption::create([
                    'question_id' => $question->id, 'position' => $p + 1,
                    'content' => $c, 'is_correct' => $juste, 'rationale' => $justif, 'cause' => $cause,
                ]);
            }

            $question->contentSources()->attach($this->source->id, ['verification' => 'verified']);
            $transitions->submitForReview($question);
            $transitions->markReviewed($question, $this->valideur);
            $transitions->validate($question, $this->valideur);
            $transitions->publish($question, forDiagnostic: true);
        }
    }

    private function scheduler(): MemoryScheduler
    {
        return app(MemoryScheduler::class);
    }

    /**
     * Passe une session d'entraînement : `$reponses` donne, pour chaque item,
     * [juste ?, certitude] ou null pour ne pas répondre.
     *
     * @param  list<array{0: bool, 1: string}|null>  $reponses
     */
    private function passer(array $reponses): Attempt
    {
        $service = app(AttemptService::class);

        $attempt = $service->startTraining(
            $this->candidat, $this->epreuve, [$this->noeud->id], 'fr',
            (string) Str::uuid7(), count($reponses)
        )['attempt'];

        foreach ($attempt->items()->with('question.options')->get() as $i => $item) {
            $consigne = $reponses[$i] ?? null;

            if ($consigne === null) {
                continue;   // item laissé sans réponse
            }

            [$juste, $certitude] = $consigne;

            $option = $juste
                ? $item->question->correctOption()
                // Distracteur A : cause `confusion_notions`.
                : $item->question->options->firstWhere('position', 1);

            $service->answer($item, $option, $certitude);
        }

        $clos = $service->submit($attempt->fresh());
        $this->scheduler()->planFromAttempt($clos);

        return $clos;
    }

    private function rdv(): ?ReviewSchedule
    {
        return ReviewSchedule::where('user_id', $this->candidat->id)
            ->where('competency_node_id', $this->noeud->id)
            ->first();
    }

    // --- Ce qui entre au calendrier -----------------------------------------

    public function test_une_question_jamais_manquee_n_entre_jamais_au_calendrier(): void
    {
        $this->peupler(6);

        $this->passer(array_fill(0, 6, [true, 'sure']));

        $this->assertSame(0, ReviewSchedule::count(),
            "On ne fait pas réviser ce qui n'a jamais posé problème.");
    }

    public function test_un_item_sans_reponse_n_entre_pas_au_calendrier(): void
    {
        $this->peupler(6);

        // Cinq justes, un item SAUTÉ — aucune cause diagnostiquée.
        $this->passer([[true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure'], null]);

        $this->assertSame(0, ReviewSchedule::count(),
            'Une question laissée vide ne porte aucune cause : rien à réviser.');
    }

    public function test_une_erreur_entre_au_premier_palier(): void
    {
        $this->peupler(6);

        $this->passer([[false, 'hesitant'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure']]);

        $rdv = $this->rdv();

        $this->assertNotNull($rdv);
        $this->assertSame(1, $rdv->palier);
        $this->assertSame('confusion_notions', $rdv->cause);
        $this->assertFalse($rdv->blind_error);
    }

    // --- La certitude module le palier ---------------------------------------

    public function test_juste_avec_certitude_monte_d_un_palier(): void
    {
        $suite = $this->scheduler()->prochainPalier(2, juste: true, certitude: 'sure');

        $this->assertSame(3, $suite['palier']);
        $this->assertTrue($suite['sure']);
    }

    public function test_juste_en_hesitant_reste_au_meme_palier(): void
    {
        $suite = $this->scheduler()->prochainPalier(3, juste: true, certitude: 'hesitant');

        $this->assertSame(3, $suite['palier']);
        $this->assertFalse($suite['sure']);
    }

    public function test_juste_au_hasard_redescend_le_palier(): void
    {
        $suite = $this->scheduler()->prochainPalier(4, juste: true, certitude: 'guess');

        $this->assertSame(3, $suite['palier'], "Une réussite au hasard n'est pas une acquisition.");
        $this->assertFalse($suite['sure'], 'Elle ne compte pas vers la sortie du calendrier.');
    }

    public function test_faux_avec_certitude_revient_au_premier_palier_et_passe_en_priorite(): void
    {
        $suite = $this->scheduler()->prochainPalier(5, juste: false, certitude: 'sure');

        $this->assertSame(1, $suite['palier']);
        $this->assertTrue($suite['aveugle'], "L'erreur aveugle : le candidat ne sait pas qu'il ne sait pas.");
    }

    public function test_faux_en_hesitant_revient_au_premier_palier_sans_priorite(): void
    {
        $suite = $this->scheduler()->prochainPalier(4, juste: false, certitude: 'hesitant');

        $this->assertSame(1, $suite['palier']);
        $this->assertFalse($suite['aveugle']);
    }

    public function test_l_erreur_aveugle_remonte_dans_la_liste_du_jour(): void
    {
        $this->peupler(8);

        $this->passer([[false, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure']]);

        $rdv = $this->rdv();

        $this->assertNotNull($rdv);
        $this->assertTrue($rdv->blind_error);
        $this->assertSame(1, $rdv->palier);
    }

    // --- La porte de sortie ---------------------------------------------------

    public function test_deux_reussites_certaines_consecutives_font_sortir_du_calendrier(): void
    {
        /* Vivier réduit à SIX pour six items servis : toutes les questions
         * reviennent à chaque session, y compris celle que le rendez-vous a
         * tracée. Sans cela l'anti-répétition la repousse tant qu'il reste du
         * neuf, et la réussite ne peut pas atteindre le rendez-vous — le
         * calendrier n'avance que sur la question qu'il a fait servir. */
        $this->peupler(6);

        // Première session : une erreur, le rendez-vous naît.
        $this->passer([[false, 'hesitant'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure']]);
        $this->assertSame(1, ReviewSchedule::count());

        // Première réussite certaine.
        $this->passer([[true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure']]);
        $this->assertSame(1, ReviewSchedule::count(), 'Une seule réussite ne suffit pas à sortir.');
        $this->assertSame(1, $this->rdv()->consecutive_sure);

        // Seconde réussite certaine : la porte s'ouvre.
        $this->passer([[true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure']]);

        $this->assertSame(0, ReviewSchedule::count(),
            'Sans porte de sortie, la liste grossirait indéfiniment.');
    }

    // --- La date de session borne les intervalles ------------------------------

    public function test_un_intervalle_qui_depasserait_la_session_est_raccourci(): void
    {
        $tz = config('naja7i.timezone_candidat');

        // Sans plafond, le cinquième palier tombe à 35 jours.
        $sansPlafond = $this->scheduler()->echeance(5);
        $this->assertSame(35, (int) CarbonImmutable::now($tz)->startOfDay()->diffInDays($sansPlafond));

        // Avec une épreuve dans 10 jours, marge de 2 : le rendez-vous est ramené.
        $plafond = CarbonImmutable::now($tz)->startOfDay()->addDays(8);
        $avecPlafond = $this->scheduler()->echeance(5, $plafond);

        $this->assertTrue($avecPlafond->equalTo($plafond),
            "Un rendez-vous programmé après l'épreuve ne sert à rien.");
        $this->assertTrue($avecPlafond->lessThan($sansPlafond));
    }

    public function test_la_session_reelle_de_l_epreuve_borne_le_rendez_vous(): void
    {
        $this->peupler(6);

        // L'épreuve écrite dans 5 jours : même le premier palier tient, mais le
        // plafond doit s'appliquer aux paliers longs.
        $famille = $this->epreuve->track->family;
        ExamSession::where('exam_family_id', $famille->id)->delete();
        ExamSession::create([
            'exam_family_id' => $famille->id,
            'label_fr' => 'Session sonde',
            'label_ar' => 'دورة',
            'year' => (int) now()->addDays(5)->format('Y'),
            'written_exam_on' => now()->addDays(5)->toDateString(),
            'dates_confirmed' => true,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->passer([[false, 'hesitant'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure']]);

        $rdv = $this->rdv();
        $limite = CarbonImmutable::now(config('naja7i.timezone_candidat'))
            ->startOfDay()
            ->addDays(5 - MemoryScheduler::MARGE_AVANT_EPREUVE);

        $this->assertNotNull($rdv);
        $this->assertTrue(
            CarbonImmutable::parse($rdv->due_on)->lessThanOrEqualTo($limite),
            "Aucun rendez-vous n'est programmé au-delà de la veille utile de l'épreuve."
        );
    }

    // --- Aucune prédiction ------------------------------------------------------

    public function test_le_calendrier_ne_produit_aucune_probabilite(): void
    {
        $this->peupler(6);
        $this->passer([[false, 'hesitant'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure']]);

        $charge = strtolower(json_encode($this->rdv()->toArray(), JSON_UNESCAPED_UNICODE));

        foreach (['probab', 'retention', 'prediction', 'chance'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $charge);
        }
    }
}
