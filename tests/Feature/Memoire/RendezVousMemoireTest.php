<?php

namespace Tests\Feature\Memoire;

use App\Contracts\AccessGrant;
use App\Models\AccessGrantRecord;
use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\CauseRevealCounter;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\ExamSession;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Remediation;
use App\Models\ReviewSchedule;
use App\Models\Role;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\MemoryScheduler;
use App\Services\QuestionTransitionService;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
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

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->source = Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail();
        $this->noeud = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();

        $this->valideur = $this->utilisateur('valideur@naja7i.ma');
        $this->candidat = $this->utilisateur('candidat@naja7i.ma');
        $this->candidat->grantCandidateRole();

        /* Les routes du parcours exigent un e-mail vérifié. `email_verified_at`
         * n'est pas assignable en masse — on passe par le contrat. */
        $this->candidat->markEmailAsVerified();
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
                ['Aucune des propositions précédentes', false, 'Elle est fausse puisqu’une autre proposition est correcte.', 'indetermine'],
            ] as $p => [$c, $juste, $justif, $cause]) {
                QuestionOption::create([
                    'question_id' => $question->id, 'position' => $p + 1,
                    'content' => $c, 'is_correct' => $juste, 'rationale' => $justif, 'cause' => $cause,
                ]);
            }

            $question->contentSources()->attach($this->source->id, ['verification' => 'verified']);
            $transitions->submitForReview($question);
            $transitions->markReviewed($question, $this->relecteurDeControle());
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

        /* `submit()` planifie LUI-MÊME depuis l'audit BLOC-1 : les effets de
         * bord vivent dans le service, derrière la garde de transition. Le
         * montage n'a plus à les déclencher — et ne le doit plus, sous peine de
         * planifier deux fois ce qu'une seule séance a produit. */
        return $service->submit($attempt->fresh());
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
        /* Depuis DET-35, le vivier n'a plus à être réduit pour que la réussite
         * atteigne le rendez-vous : c'est le COUPLE (compétence, cause) qui
         * avance, quelle que soit la question servie. Six reste suffisant. */
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

    // --- DET-35 : le couple avance, plus la question tracée --------------------

    /**
     * Deux séances : la première fait naître le rendez-vous sur une question,
     * la seconde le fait avancer sur d'AUTRES questions.
     *
     * L'anti-répétition sert le neuf d'abord : avec dix questions et cinq par
     * séance, la seconde ne peut pas resservir celles de la première. C'est
     * exactement la situation où l'ancien appariement par `last_question_id`
     * ne faisait rien avancer.
     */
    private function deuxSeances(): ReviewSchedule
    {
        $this->peupler(10);

        $this->passer([[false, 'hesitant'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure']]);

        $rdv = $this->rdv();
        $this->assertNotNull($rdv, 'La première séance fait naître le rendez-vous.');
        $this->premiereQuestion = $rdv->last_question_id;

        $this->passer(array_fill(0, 5, [true, 'sure']));

        return $this->rdv()->fresh();
    }

    private ?int $premiereQuestion = null;

    public function test_une_reussite_sur_une_autre_question_fait_avancer_le_palier(): void
    {
        $rdv = $this->deuxSeances();

        $this->assertNotNull($rdv, "Deux réussites certaines n'ont pas encore ouvert la porte de sortie.");
        $this->assertSame(2, $rdv->palier, 'Le couple a avancé, servi par une autre question.');
        $this->assertNotSame(
            $this->premiereQuestion, $rdv->last_question_id,
            "L'avancement ne vient pas de la question tracée : c'est tout l'objet de DET-35."
        );
    }

    public function test_un_couple_ne_bouge_qu_une_fois_par_seance(): void
    {
        $rdv = $this->deuxSeances();

        /* Les cinq questions de la seconde séance tendent toutes le même piège.
         * Sans dédoublonnage, le rendez-vous aurait avancé cinq fois — et deux
         * réussites certaines suffisant à sortir, il aurait disparu. */
        $this->assertSame(1, $rdv->consecutive_sure, 'Le candidat a rencontré le couple une fois, pas cinq.');
        $this->assertSame(1, ReviewSchedule::count());
    }

    public function test_l_echec_du_jour_l_emporte_sur_les_reussites_du_meme_jour(): void
    {
        $this->peupler(6);

        // Tombé dans le piège à la première question, évité aux cinq suivantes.
        $this->passer([[false, 'hesitant'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure']]);

        $rdv = $this->rdv();

        $this->assertNotNull($rdv, "L'erreur vient d'être démontrée : la séance ne l'efface pas elle-même.");
        $this->assertSame(1, $rdv->palier);
        $this->assertSame(0, $rdv->consecutive_sure);
    }

    // --- L'énoncé resservi : plafonné, ni gelé ni libre --------------------------

    /**
     * Pose un rendez-vous déjà servi par une question donnée, à un palier donné.
     */
    private function rdvResservi(int $palier, int $questionId): ReviewSchedule
    {
        return ReviewSchedule::create([
            'user_id' => $this->candidat->id,
            'exam_id' => $this->epreuve->id,
            'competency_node_id' => $this->noeud->id,
            'cause' => 'confusion_notions',
            'last_question_id' => $questionId,
            'palier' => $palier,
            'consecutive_sure' => 0,
            'due_on' => now(config('naja7i.timezone_candidat'))->toDateString(),
        ]);
    }

    /** Sert UNE question précise et la réussit avec certitude. */
    private function reussirSur(Question $question): void
    {
        $service = app(AttemptService::class);

        $attempt = Attempt::create([
            'user_id' => $this->candidat->id, 'exam_id' => $this->epreuve->id,
            'locale' => 'fr', 'idempotency_key' => (string) Str::uuid7(),
            'kind' => 'review', 'status' => 'in_progress', 'started_at' => now(),
            'item_count' => 1,
        ]);

        $item = AttemptItem::create([
            'attempt_id' => $attempt->id, 'question_id' => $question->id,
            'competency_node_id' => $question->competency_node_id, 'position' => 1,
        ]);

        $service->answer($item, $question->correctOption(), 'sure');
        $service->submit($attempt->fresh());
    }

    public function test_un_enonce_resservi_monte_le_palier_jusqu_au_plafond_seulement(): void
    {
        $this->peupler(1);
        $question = Question::where('competency_node_id', $this->noeud->id)->firstOrFail();

        // Au palier 2, la réussite sur l'énoncé déjà vu doit mener à 3.
        $this->rdvResservi(2, $question->id);
        $this->reussirSur($question);

        $this->assertSame(
            MemoryScheduler::PLAFOND_ENONCE_RESSERVI,
            $this->rdv()->palier,
            "L'intervalle s'allonge : sans cela le couple reviendrait tous les jours, "
            .'indéfiniment, et saturerait la liste plafonnée.'
        );

        // Au plafond, une réussite de plus ne le dépasse pas.
        $this->reussirSur($question);

        $this->assertSame(
            MemoryScheduler::PLAFOND_ENONCE_RESSERVI,
            $this->rdv()->palier,
            'Reconnaître un énoncé ne mène pas à 35 jours : ce serait sortir par la petite porte.'
        );
        $this->assertNotNull($this->rdv(), 'Et la sortie reste fermée.');
        $this->assertSame(0, $this->rdv()->consecutive_sure);
    }

    public function test_le_plafond_ne_rabaisse_pas_un_palier_deja_plus_haut(): void
    {
        $this->peupler(1);
        $question = Question::where('competency_node_id', $this->noeud->id)->firstOrFail();

        /* Palier 4, gagné avec de vraies sœurs avant que la banque ne s'épuise.
         * Il faut qu'il soit AU-DESSUS du plafond et que la réussite tente de
         * le faire monter : partir de 5 ne prouverait rien, le palier maximal
         * ne montant plus, et la clause de garde ne serait pas exercée. */
        $this->rdvResservi(4, $question->id);
        $this->reussirSur($question);

        $this->assertSame(
            4, $this->rdv()->palier,
            'Le plafond borne la MONTÉE ; il ne RABAISSE pas un palier déjà mérité.'
        );
    }

    // --- Le trou éditorial, nommé pour un rédacteur, compté pour un candidat -----

    public function test_la_liste_du_candidat_compte_les_couples_sans_soeur_sans_les_nommer(): void
    {
        $this->peupler(1);   // une seule question : aucune sœur
        $question = Question::where('competency_node_id', $this->noeud->id)->firstOrFail();
        $this->rdvResservi(1, $question->id);

        $reponse = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/memory/{$this->epreuve->code}/due");

        $reponse->assertOk();
        $this->assertSame(1, $reponse->json('meta.without_sibling'));

        $this->assertStringNotContainsString(
            'confusion_notions',
            json_encode($reponse->json('meta'), JSON_UNESCAPED_UNICODE),
            'Le nombre se dit, la cause NON : c\'est un champ payant.'
        );
    }

    public function test_le_plan_de_redaction_nomme_les_couples_et_les_ordonne(): void
    {
        $this->peupler(1);
        $question = Question::where('competency_node_id', $this->noeud->id)->firstOrFail();
        $this->rdvResservi(1, $question->id);

        // Un second couple, celui-là sans AUCUNE question qui tende son piège.
        ReviewSchedule::create([
            'user_id' => $this->candidat->id,
            'exam_id' => $this->epreuve->id,
            'competency_node_id' => $this->noeud->id,
            'cause' => 'calcul',
            'palier' => 1,
            'due_on' => now(config('naja7i.timezone_candidat'))->toDateString(),
        ]);

        $editeur = $this->utilisateur('editeur@naja7i.ma');
        $editeur->markEmailAsVerified();
        $editeur->memberships()->create([
            'role_id' => Role::where('code', 'editeur')->whereNull('tenant_id')->value('id'),
        ]);

        $reponse = $this->actingAs($editeur)
            ->getJson("/api/v1/admin/banque/couverture/{$this->epreuve->code}");

        $reponse->assertOk();
        $this->assertSame(2, $reponse->json('meta.gaps'));

        $couples = collect($reponse->json('data'));

        $this->assertSame(
            'none',
            $couples->firstWhere('cause', 'calcul')['coverage']['fr']['severity'],
            'Aucune question ne tend ce piège : la séance ne peut même pas se composer.'
        );
        $this->assertSame(
            'no_sibling',
            $couples->firstWhere('cause', 'confusion_notions')['coverage']['fr']['severity'],
            'Une seule question : l\'énoncé revient à l\'identique.'
        );
        $this->assertSame(
            1, $couples->firstWhere('cause', 'confusion_notions')['coverage']['fr']['published_questions']
        );

        /* La banque arabe est vide : les DEUX couples y manquent entièrement,
         * et c'est un travail distinct de celui du français. */
        $this->assertSame(2, $reponse->json('meta.to_write.ar'));
        $this->assertSame(1, $reponse->json('meta.to_write.fr'));
        $this->assertSame(1, $reponse->json('meta.to_complete.fr'));

        $this->assertSame(1, $couples->firstWhere('cause', 'calcul')['waiting_candidates']);
        $this->assertSame('SE-PSY-DEV', $couples->firstWhere('cause', 'calcul')['competency']['code']);
    }

    public function test_le_plan_de_redaction_est_refuse_sans_permission(): void
    {
        $this->actingAs($this->candidat)
            ->getJson("/api/v1/admin/banque/couverture/{$this->epreuve->code}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PERMISSION_DENIED');
    }

    // --- La séance de révision, par la route ------------------------------------

    /** Rendez-vous échus fabriqués directement : on teste la liste, pas le jeu. */
    private function planifier(int $combien): void
    {
        $noeuds = CompetencyNode::where('exam_id', $this->epreuve->id)->pluck('id');
        $causes = [
            'confusion_notions', 'lecture_enonce', 'regle_mal_appliquee', 'connaissance_absente',
            'source_perimee', 'calcul', 'piege_formulation', 'indetermine',
        ];

        $couples = $noeuds->crossJoin($causes)->take($combien);

        $this->assertCount($combien, $couples, 'Pas assez de couples (compétence, cause) pour cette sonde.');

        foreach ($couples as [$nodeId, $cause]) {
            ReviewSchedule::create([
                'user_id' => $this->candidat->id,
                'exam_id' => $this->epreuve->id,
                'competency_node_id' => $nodeId,
                'cause' => $cause,
                'palier' => 1,
                'due_on' => now(config('naja7i.timezone_candidat'))->toDateString(),
            ]);
        }
    }

    public function test_rien_d_echu_repond_une_liste_vide_et_la_prochaine_date(): void
    {
        $this->peupler(6);
        $this->passer([[false, 'hesitant'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure']]);

        // Le rendez-vous est né aujourd'hui, échéance au premier palier : demain.
        $reponse = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/memory/{$this->epreuve->code}/due");

        $reponse->assertOk();   // rien d'échu n'est PAS une erreur
        $this->assertSame([], $reponse->json('data'));
        $this->assertSame(0, $reponse->json('meta.due_total'));
        $this->assertNotNull(
            $reponse->json('meta.next_due_on'),
            "« Rien aujourd'hui, prochain le 14 » est une information ; un 404 n'en est pas une."
        );
    }

    public function test_le_plafond_sert_vingt_rendez_vous_et_annonce_le_reste(): void
    {
        $this->planifier(60);

        $reponse = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/memory/{$this->epreuve->code}/due");

        $reponse->assertOk();
        $this->assertCount(MemoryScheduler::PLAFOND_LISTE, $reponse->json('data'));
        $this->assertSame(60, $reponse->json('meta.due_total'));
        $this->assertSame(20, $reponse->json('meta.served'));
        $this->assertSame(
            40, $reponse->json('meta.pending'),
            'Aucun plafond silencieux : à qui on cache quarante rendez-vous, il croit avoir fini.'
        );
    }

    public function test_la_cause_reste_fermee_hors_abonnement(): void
    {
        $this->planifier(1);

        $ligne = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/memory/{$this->epreuve->code}/due")
            ->json('data.0');

        $this->assertNull($ligne['cause'], 'La cause est le diagnostic vendu : elle ne fuit pas par la liste.');
        $this->assertTrue($ligne['cause_locked']);
        $this->assertNotNull($ligne['competency']['name'], 'Ce qui reste visible suffit à agir.');

        AccessGrantRecord::create([
            'user_id' => $this->candidat->id,
            'capability' => AccessGrant::CAUSE_REVEAL,
            'starts_at' => now()->subDay(), 'origin' => 'purchase',
        ]);

        $abonne = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/memory/{$this->epreuve->code}/due")
            ->json('data.0');

        $this->assertNotNull($abonne['cause']);
        $this->assertFalse($abonne['cause_locked']);
    }

    /**
     * La garantie que le produit donne déjà, tenue jusque dans la liste.
     *
     * `ParcoursController::correction()` promet que le quota est décompté une
     * seule fois et que revenir sur sa correction ne recoûte rien. Une cause
     * payée qui réapparaît fermée trois jours plus tard n'est pas un mur
     * payant, c'est une promesse rompue.
     */
    public function test_une_cause_deja_revelee_reste_ouverte_dans_la_liste(): void
    {
        $this->peupler(6);
        $clos = $this->passer([[false, 'hesitant'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure']]);

        // La correction révèle la cause, et consomme une unité de quota.
        $correction = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/attempts/{$clos->uuid}/correction");

        $correction->assertOk();
        $this->assertSame(1, $correction->json('meta.cause_quota.revealed'));

        $compteurApres = CauseRevealCounter::where('user_id', $this->candidat->id)->value('revealed_total');

        $this->rdv()->update(['due_on' => now(config('naja7i.timezone_candidat'))->toDateString()]);

        $ligne = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/memory/{$this->epreuve->code}/due")
            ->json('data.0');

        $this->assertSame('confusion_notions', $ligne['cause'], 'Le candidat a déjà donné pour cette cause.');
        $this->assertFalse($ligne['cause_locked']);

        $this->assertSame(
            $compteurApres,
            CauseRevealCounter::where('user_id', $this->candidat->id)->value('revealed_total'),
            "Lire une liste n'achète rien : aucune unité ne se consomme ici."
        );
    }

    public function test_une_cause_jamais_revelee_reste_fermee_dans_la_liste(): void
    {
        $this->peupler(6);
        $this->passer([[false, 'hesitant'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure']]);

        // Aucune correction consultée : rien n'a été payé.
        $this->rdv()->update(['due_on' => now(config('naja7i.timezone_candidat'))->toDateString()]);

        $ligne = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/memory/{$this->epreuve->code}/due")
            ->json('data.0');

        $this->assertNull($ligne['cause'], 'Le mur payant tient sur ce qui n\'a jamais été révélé.');
        $this->assertTrue($ligne['cause_locked']);
    }

    public function test_la_seance_sert_une_soeur_et_non_la_question_tracee(): void
    {
        $this->peupler(10);
        $this->passer([[false, 'hesitant'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure']]);

        $tracee = $this->rdv()->last_question_id;
        $this->rdv()->update(['due_on' => now(config('naja7i.timezone_candidat'))->toDateString()]);

        $reponse = $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/memory/{$this->epreuve->code}/session");

        $reponse->assertCreated();
        $this->assertSame('review', $reponse->json('data.kind'));
        $this->assertSame(1, $reponse->json('data.item_count'), 'Un rendez-vous échu, une question.');
        $this->assertSame(1, $reponse->json('meta.due_total'));
        $this->assertSame(1, $reponse->json('meta.covered'));

        $servie = Question::where('uuid', $reponse->json('data.items.0.question.uuid'))->firstOrFail();

        $this->assertNotSame(
            $tracee, $servie->id,
            "Resservir le même énoncé apprendrait l'énoncé, pas le raisonnement."
        );
        $this->assertSame($this->noeud->id, $servie->competency_node_id);
    }

    public function test_ouvrir_deux_fois_reprend_la_seance_sans_en_creer_une_seconde(): void
    {
        $this->peupler(10);
        $this->passer([[false, 'hesitant'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure']]);
        $this->rdv()->update(['due_on' => now(config('naja7i.timezone_candidat'))->toDateString()]);

        $premiere = $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/memory/{$this->epreuve->code}/session");
        $premiere->assertCreated();

        $seconde = $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/memory/{$this->epreuve->code}/session");

        $seconde->assertOk();   // 200 : on reprend, on n'ouvre pas
        $this->assertSame($premiere->json('data.uuid'), $seconde->json('data.uuid'));
        $this->assertSame(1, Attempt::where('kind', 'review')->count());
    }

    public function test_ouvrir_sans_rien_d_echu_refuse_avec_un_code_propre(): void
    {
        $this->peupler(6);
        $this->passer([[false, 'hesitant'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure'], [true, 'sure']]);

        $reponse = $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/memory/{$this->epreuve->code}/session");

        $reponse->assertStatus(409);
        $this->assertSame('MEMORY_NOTHING_DUE', $reponse->json('error.code'));
        $this->assertNotNull(
            $reponse->json('error.details.next_due_on'),
            "Le refus dit lui-même quand revenir, plutôt que d'exiger un second appel."
        );
    }

    public function test_des_rendez_vous_sans_question_soeur_sont_dits_et_non_remplaces(): void
    {
        // Aucune question en banque : le calendrier a du travail, pas la banque.
        $this->planifier(3);

        $reponse = $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/memory/{$this->epreuve->code}/session");

        $reponse->assertStatus(409);
        $this->assertSame(
            'MEMORY_NO_SIBLING_QUESTION', $reponse->json('error.code'),
            'Distinct de « rien d\'échu » : le candidat n\'a rien à corriger de son côté.'
        );
        $this->assertSame(3, $reponse->json('error.details.due_total'));
    }

    public function test_une_question_couvre_plusieurs_rendez_vous_du_meme_piege(): void
    {
        $this->peupler(10);

        /* Deux causes échues sur la même compétence. Les questions du montage
         * portent les deux sur leurs distracteurs : une seule question suffit
         * à servir les deux rendez-vous. */
        foreach (['confusion_notions', 'lecture_enonce'] as $cause) {
            ReviewSchedule::create([
                'user_id' => $this->candidat->id,
                'exam_id' => $this->epreuve->id,
                'competency_node_id' => $this->noeud->id,
                'cause' => $cause,
                'palier' => 1,
                'due_on' => now(config('naja7i.timezone_candidat'))->toDateString(),
            ]);
        }

        $reponse = $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/memory/{$this->epreuve->code}/session");

        $reponse->assertCreated();
        $this->assertSame(1, $reponse->json('data.item_count'), 'Une question, servie une fois.');
        $this->assertSame(2, $reponse->json('meta.covered'), 'Elle couvre les deux rendez-vous.');
        $this->assertSame(0, $reponse->json('meta.pending'));
    }
}
