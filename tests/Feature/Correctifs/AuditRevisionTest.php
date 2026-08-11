<?php

namespace Tests\Feature\Correctifs;

use App\Models\Attempt;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Remediation;
use App\Models\ReviewSchedule;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\QuestionTransitionService;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audit externe de la révision 490fc53 — les cinq bloquants.
 *
 * QUATRE DE CES TESTS EXIGENT DEUX SESSIONS POSTGRESQL. Une exécution
 * séquentielle ne prouve rien sur une course : elle vérifie l'état final,
 * jamais l'entrelacement qui le produit. C'est précisément pourquoi la suite
 * était verte pendant que les cinq défauts étaient là.
 *
 * COMMENT L'ENTRELACEMENT EST IMPOSÉ, puisqu'un processus PHP est
 * mono-thread : `DB::listen` observe les requêtes de la connexion principale
 * et, au moment exact où elle vient de lire « rien à cet endroit », une SECONDE
 * connexion écrit et valide. La suite du code principal s'exécute alors sur un
 * état qui a changé sous ses pieds — c'est la définition de la course, et elle
 * est ici déterministe plutôt que probabiliste.
 */
class AuditRevisionTest extends TestCase
{
    /**
     * `DatabaseMigrations`, et non `RefreshDatabase` : la seconde connexion doit
     * VOIR les données du montage. `RefreshDatabase` enveloppe chaque test dans
     * une transaction jamais validée — la seconde connexion n'y verrait rien, et
     * le test conclurait à tort qu'aucune course n'existe.
     *
     * Même raisonnement, et même prix, qu'à `CorrectifsContreRevueTest`.
     */
    use DatabaseMigrations;

    private Exam $epreuve;

    private User $candidat;

    private CompetencyNode $noeud;

    private Source $source;

    private User $valideur;

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
        $this->candidat->markEmailAsVerified();
    }

    private function utilisateur(string $email): User
    {
        return User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
    }

    /** Questions publiées : distracteur A de cause `confusion_notions`. */
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
                ['A', false, 'confusion_notions'],
                ['B', true, null],
                ['C', false, 'lecture_enonce'],
                ['D', false, 'connaissance_absente'],
            ] as $p => [$c, $juste, $cause]) {
                QuestionOption::create([
                    'question_id' => $question->id, 'position' => $p + 1,
                    'content' => $c, 'is_correct' => $juste, 'rationale' => 'r', 'cause' => $cause,
                ]);
            }

            $question->contentSources()->attach($this->source->id, ['verification' => 'verified']);
            $transitions->submitForReview($question);
            $transitions->markReviewed($question, $this->valideur);
            $transitions->validate($question, $this->valideur);
            $transitions->publish($question, forDiagnostic: true);
        }
    }

    /**
     * Ouvre un entraînement et répond, SANS soumettre.
     *
     * @param  list<array{0: bool, 1: string}>  $reponses
     */
    private function repondreSansSoumettre(array $reponses): Attempt
    {
        $service = app(AttemptService::class);

        $attempt = $service->startTraining(
            $this->candidat, $this->epreuve, [$this->noeud->id], 'fr',
            (string) Str::uuid7(), count($reponses)
        )['attempt'];

        foreach ($attempt->items()->with('question.options')->get() as $i => $item) {
            [$juste, $certitude] = $reponses[$i];

            $service->answer(
                $item,
                $juste ? $item->question->correctOption() : $item->question->options->firstWhere('position', 1),
                $certitude
            );
        }

        return $attempt->fresh();
    }

    /**
     * Fait intervenir une SECONDE connexion au moment où la principale exécute
     * une requête reconnaissable — l'instant précis d'une course.
     */
    private function pendantLaRequete(string $motif, callable $intervention): void
    {
        $dejaFait = false;

        DB::listen(function ($requete) use (&$dejaFait, $motif, $intervention) {
            if ($dejaFait || ! str_contains($requete->sql, $motif)) {
                return;
            }

            $dejaFait = true;
            $intervention(DB::connection('pgsql_concurrent'));
        });
    }

    // ===================================================================
    // BLOC-1 — rejouer submit ne rejoue plus la planification
    // ===================================================================

    /**
     * Le dommage exact décrit par l'audit : un renvoi réseau vide le calendrier.
     *
     * LA SÉANCE PORTE UNE RÉUSSITE, ET C'EST DÉLIBÉRÉ. Rejouer un ÉCHEC est
     * idempotent par nature — l'échec ramène toujours au premier palier avec le
     * compteur à zéro, si bien qu'un second passage ne se voit pas. Ce n'est
     * donc pas ce qu'il faut mesurer. Une RÉUSSITE, elle, incrémente : partant
     * de zéro, un premier passage porte le compteur à un, un second à deux — et
     * deux réussites certaines consécutives font SORTIR du calendrier. Une
     * seule séance réelle suffisait à effacer un rendez-vous.
     *
     * Les horodatages ne prouvent rien ici : les colonnes `timestampTz` sont à
     * la seconde, et les deux appels tombent dans la même. C'est le compteur
     * qui discrimine, pas `updated_at` (DET-40).
     */
    public function test_soumettre_deux_fois_ne_bouge_le_rendez_vous_qu_une_fois(): void
    {
        $this->peupler(6);

        /* Un rendez-vous déjà là, au compteur à zéro. La séance qui suit le
         * portera à un ; un rejeu le porterait à deux et l'effacerait. */
        ReviewSchedule::create([
            'user_id' => $this->candidat->id,
            'exam_id' => $this->epreuve->id,
            'competency_node_id' => $this->noeud->id,
            'cause' => 'confusion_notions',
            'palier' => 1,
            'consecutive_sure' => 0,
            'due_on' => now(config('naja7i.timezone_candidat'))->toDateString(),
        ]);

        $attempt = $this->repondreSansSoumettre(array_fill(0, 6, [true, 'sure']));

        $url = "/api/v1/me/attempts/{$attempt->uuid}/submit";

        $this->actingAs($this->candidat)->postJson($url)->assertOk();

        $apres = ReviewSchedule::where('user_id', $this->candidat->id)->firstOrFail();
        $this->assertSame(1, $apres->consecutive_sure, 'Une séance, un pas.');

        // Le rejeu : même POST, même UUID. Un renvoi réseau est la normalité.
        $this->actingAs($this->candidat)->postJson($url)->assertOk();

        $rejeu = ReviewSchedule::where('user_id', $this->candidat->id)->first();

        $this->assertNotNull(
            $rejeu,
            'Un rejeu de soumission a fait SORTIR le rendez-vous du calendrier : '
            .'les effets de bord ne sont pas gardés par la transition.'
        );
        $this->assertSame(
            $apres->toArray(), $rejeu->toArray(),
            'Un rejeu ne doit toucher AUCUNE colonne du rendez-vous.'
        );
        $this->assertSame(1, ReviewSchedule::count());
    }

    /**
     * La garde vit dans le SERVICE, donc protège toutes les voies.
     *
     * Le test précédent passe par HTTP ; celui-ci appelle le service
     * directement — commande de clôture, back-office, futur abonné d'événement
     * emprunteraient ce chemin-là. C'est l'objet de DET-36.
     */
    public function test_une_tentative_deja_close_ne_declenche_aucun_effet(): void
    {
        $this->peupler(6);

        ReviewSchedule::create([
            'user_id' => $this->candidat->id,
            'exam_id' => $this->epreuve->id,
            'competency_node_id' => $this->noeud->id,
            'cause' => 'confusion_notions',
            'palier' => 1,
            'consecutive_sure' => 0,
            'due_on' => now(config('naja7i.timezone_candidat'))->toDateString(),
        ]);

        $attempt = $this->repondreSansSoumettre(array_fill(0, 6, [true, 'sure']));

        $service = app(AttemptService::class);

        $service->submit($attempt);
        $premier = ReviewSchedule::where('user_id', $this->candidat->id)->firstOrFail()->toArray();

        $service->submit($attempt->fresh());

        $apres = ReviewSchedule::where('user_id', $this->candidat->id)->first();

        $this->assertNotNull(
            $apres,
            'Hors HTTP aussi, une tentative close ne déclenche plus rien.'
        );
        $this->assertSame($premier, $apres->toArray());
    }

    // ===================================================================
    // BLOC-2 — le planificateur sous verrou et sérialisé
    // ===================================================================

    /**
     * La course exacte : A lit « aucun rendez-vous », B en crée un et valide,
     * A tente de créer et bute sur l'index unique.
     *
     * Avant correctif, A prenait une violation d'index APRÈS avoir clos sa
     * propre tentative : le candidat perdait sa soumission pour une course
     * qu'il n'a pas provoquée.
     */
    public function test_une_creation_concurrente_est_relue_et_non_une_erreur(): void
    {
        $this->peupler(6);
        $attempt = $this->repondreSansSoumettre(array_fill(0, 6, [false, 'hesitant']));

        $tenantId = app(TenantContext::class)->id();
        $candidatId = $this->candidat->id;
        $noeudId = $this->noeud->id;
        $epreuveId = $this->epreuve->id;

        $this->pendantLaRequete(
            'from "review_schedules"',
            function ($seconde) use ($tenantId, $candidatId, $noeudId, $epreuveId) {
                $seconde->table('review_schedules')->insert([
                    'uuid' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'user_id' => $candidatId,
                    'exam_id' => $epreuveId,
                    'competency_node_id' => $noeudId,
                    'cause' => 'confusion_notions',
                    'palier' => 3,
                    'due_on' => now()->addDays(7)->toDateString(),
                    'consecutive_sure' => 1,
                    'blind_error' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        );

        $clos = app(AttemptService::class)->submit($attempt);

        $this->assertSame('submitted', $clos->status, 'La soumission aboutit malgré la course.');

        $rdv = ReviewSchedule::where('user_id', $this->candidat->id)
            ->where('cause', 'confusion_notions')->get();

        $this->assertCount(1, $rdv, 'Une seule ligne : l\'index a tenu.');
        $this->assertSame(
            1, $rdv->first()->palier,
            'La violation a été relue puis APPLIQUÉE : l\'échec ramène au premier palier, '
            .'au lieu de laisser la ligne du concurrent intacte.'
        );
        $this->assertSame(0, $rdv->first()->consecutive_sure);
    }

    /**
     * Le verrou est réclamé DÈS LA LECTURE, et ce test le DISCRIMINE.
     *
     * Attention au piège : constater que le planificateur « attend » ne prouve
     * rien. Sans verrou de lecture il attendrait quand même — sa mise à jour
     * finale bute sur le même verrou détenu par la seconde connexion, et le
     * test virerait au vert sur la mauvaise raison. C'est exactement le défaut
     * que le PAS-14.1 a corrigé sur les tests de verrou de rôle.
     *
     * Ce qui discrimine est l'INSTRUCTION qui expire. Avec le verrou de lecture,
     * c'est le `select ... for update` ; sans lui, l'`update`. PostgreSQL
     * renvoie l'instruction fautive dans le message, on la lit.
     *
     * Pourquoi ce détour plutôt qu'un vrai test de mise à jour perdue : le
     * dommage à prouver est qu'une seconde transaction lise le MÊME palier
     * pendant que la première calcule. L'imposer demanderait que l'écriture
     * concurrente tombe ENTRE la lecture et l'écriture de la principale — or le
     * verrou de lecture, précisément, l'en empêche : la seconde connexion
     * bloquerait, et un processus PHP mono-thread s'interbloquerait avec
     * lui-même. Deux processus réels seraient nécessaires ; ce montage n'en a
     * pas. On prouve donc la PRISE du verrou, qui est la cause, plutôt que
     * l'absence de perte, qui en est la conséquence.
     */
    public function test_le_planificateur_reclame_le_verrou_des_la_lecture(): void
    {
        $this->peupler(6);

        // Une première séance fait naître le rendez-vous.
        $premiere = $this->repondreSansSoumettre(array_fill(0, 6, [false, 'hesitant']));
        app(AttemptService::class)->submit($premiere);

        $rdvId = ReviewSchedule::where('user_id', $this->candidat->id)->firstOrFail()->id;

        $seconde = DB::connection('pgsql_concurrent');
        $seconde->beginTransaction();
        $seconde->table('review_schedules')->where('id', $rdvId)->lockForUpdate()->get();

        $deuxieme = $this->repondreSansSoumettre(array_fill(0, 6, [false, 'hesitant']));

        DB::statement("SET lock_timeout = '400ms'");

        try {
            app(AttemptService::class)->submit($deuxieme);
            $this->fail('Le planificateur a traversé sans réclamer le verrou du rendez-vous.');
        } catch (QueryException $e) {
            $message = strtolower($e->getMessage());

            $this->assertStringContainsString('lock timeout', $message);
            $this->assertStringContainsString(
                'for update', $message,
                'L\'instruction bloquée doit être la LECTURE verrouillante. Si c\'est '
                .'l\'update qui expire, le verrou n\'est pas pris à la lecture, et deux '
                .'transactions peuvent partir du même palier.'
            );
            $this->assertStringContainsString('review_schedules', $message);
        } finally {
            DB::statement("SET lock_timeout = '0'");
            $seconde->rollBack();
        }
    }

    // ===================================================================
    // BLOC-3 — l'énoncé resservi ne fait pas sortir du calendrier
    // ===================================================================

    public function test_un_enonce_resservi_est_annonce_et_ne_fait_pas_sortir(): void
    {
        // UNE SEULE question pour ce couple : la sœur n'existe pas.
        $this->peupler(1);

        $question = Question::where('competency_node_id', $this->noeud->id)->firstOrFail();

        /* Le rendez-vous est posé directement : une séance d'entraînement exige
         * cinq questions (MINIMUM_UTILE), et c'est justement le vivier d'UNE
         * question qu'on veut ici.
         *
         * `consecutive_sure` à 1 rend le test tranchant : avec une vraie sœur,
         * la réussite qui suit ferait SORTIR du calendrier. Avec l'énoncé déjà
         * vu, elle ne doit rien faire. */
        $rdv = ReviewSchedule::create([
            'user_id' => $this->candidat->id,
            'exam_id' => $this->epreuve->id,
            'competency_node_id' => $this->noeud->id,
            'cause' => 'confusion_notions',
            'last_question_id' => $question->id,
            'palier' => 2,
            'consecutive_sure' => 1,
            'due_on' => now(config('naja7i.timezone_candidat'))->toDateString(),
        ]);

        $reponse = $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/memory/{$this->epreuve->code}/session");

        $reponse->assertCreated();
        $this->assertSame(1, $reponse->json('data.item_count'));
        $this->assertSame(
            1, $reponse->json('meta.reserved_identical'),
            'Le repli est assumé, mais jamais tu : meta l\'annonce.'
        );

        // Le candidat réussit — sur l'énoncé qu'il a déjà vu.
        $revision = Attempt::where('uuid', $reponse->json('data.uuid'))->firstOrFail();
        $service = app(AttemptService::class);

        foreach ($revision->items()->with('question.options')->get() as $item) {
            $service->answer($item, $item->question->correctOption(), 'sure');
        }

        $service->submit($revision->fresh());

        $apres = ReviewSchedule::where('user_id', $this->candidat->id)->first();

        $this->assertNotNull(
            $apres,
            'Reconnaître un énoncé ne fait pas quitter le calendrier — même à une réussite de la sortie.'
        );
        $this->assertSame(
            1, $apres->consecutive_sure,
            'Le compteur de sorties est GELÉ : une réussite sur l\'énoncé déjà vu ne prouve rien.'
        );
    }

    // ===================================================================
    // BLOC-4 — deux ouvertures simultanées : un 201, un 200, jamais un 500
    // ===================================================================

    public function test_deux_ouvertures_simultanees_rendent_la_meme_session(): void
    {
        $this->peupler(6);

        $attempt = $this->repondreSansSoumettre(array_fill(0, 6, [false, 'hesitant']));
        app(AttemptService::class)->submit($attempt);

        ReviewSchedule::where('user_id', $this->candidat->id)
            ->update(['due_on' => now(config('naja7i.timezone_candidat'))->toDateString()]);

        $tenantId = app(TenantContext::class)->id();
        $candidatId = $this->candidat->id;
        $epreuveId = $this->epreuve->id;
        $uuidConcurrent = (string) Str::uuid7();

        /* Au moment où le service vient de lire « aucune session ouverte », une
         * seconde connexion en ouvre une et valide. L'INSERT du service butera
         * alors sur `attempts_single_open_review`. */
        /* `scopeOpen` qualifie la colonne : `attempts.status`. C'est la
         * PREMIÈRE requête de `startReview` à la mentionner — la recherche par
         * clé d'idempotence, qui la précède, ne regarde pas le statut. */
        $this->pendantLaRequete(
            '"attempts"."status" =',
            function ($seconde) use ($tenantId, $candidatId, $epreuveId, $uuidConcurrent) {
                $seconde->table('attempts')->insert([
                    'uuid' => $uuidConcurrent,
                    'tenant_id' => $tenantId,
                    'user_id' => $candidatId,
                    'exam_id' => $epreuveId,
                    'locale' => 'fr',
                    'idempotency_key' => (string) Str::uuid7(),
                    'kind' => 'review',
                    'status' => 'in_progress',
                    'started_at' => now(),
                    /* Insertion BRUTE : elle contourne le modèle, donc le
                     * défaut posé par `Attempt::booted()`. La colonne est
                     * NOT NULL — c'est ce qu'on veut d'une seconde connexion
                     * qui simule un autre processus. */
                    'last_activity_at' => now(),
                    'item_count' => 1,
                    'answered_count' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        );

        $reponse = $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/memory/{$this->epreuve->code}/session");

        $reponse->assertOk();   // 200 : on reprend le gagnant, on n'échoue pas
        $this->assertSame(
            $uuidConcurrent, $reponse->json('data.uuid'),
            'La session rendue est celle qui a gagné la course, pas une seconde.'
        );
        $this->assertSame(
            1,
            Attempt::where('user_id', $this->candidat->id)
                ->where('kind', 'review')->where('status', 'in_progress')->count(),
            'Une seule session ouverte : l\'index a tenu l\'invariant.'
        );
    }

    // ===================================================================
    // BLOC-5 — une clé d'idempotence identifie une opération
    // ===================================================================

    public function test_une_cle_reutilisee_pour_une_autre_operation_est_refusee(): void
    {
        $this->peupler(20);

        $cle = (string) Str::uuid7();

        $this->actingAs($this->candidat)
            ->withHeaders(['Idempotency-Key' => $cle])
            ->postJson("/api/v1/me/training/{$this->epreuve->code}", ['total' => 6])
            ->assertCreated();

        // Même clé, autre genre d'opération.
        $diagnostic = $this->actingAs($this->candidat)
            ->withHeaders(['Idempotency-Key' => $cle])
            ->postJson("/api/v1/me/diagnostics/{$this->epreuve->code}", ['total' => 6]);

        $diagnostic->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_KEY_REUSED', $diagnostic->json('error.code'));

        // Même clé, même genre, mais AUTRE paramètre.
        $autreTotal = $this->actingAs($this->candidat)
            ->withHeaders(['Idempotency-Key' => $cle])
            ->postJson("/api/v1/me/training/{$this->epreuve->code}", ['total' => 8]);

        $autreTotal->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_KEY_REUSED', $autreTotal->json('error.code'));

        // Même clé, même genre, autre CONCOURS.
        $autreEpreuve = Exam::where('code', '!=', $this->epreuve->code)->published()->first();

        if ($autreEpreuve !== null) {
            $ailleurs = $this->actingAs($this->candidat)
                ->withHeaders(['Idempotency-Key' => $cle])
                ->postJson("/api/v1/me/training/{$autreEpreuve->code}", ['total' => 6]);

            $ailleurs->assertStatus(409);
            $this->assertSame('IDEMPOTENCY_KEY_REUSED', $ailleurs->json('error.code'));
        }
    }

    public function test_le_rejeu_strictement_identique_rend_la_meme_tentative(): void
    {
        $this->peupler(20);

        $cle = (string) Str::uuid7();

        $premier = $this->actingAs($this->candidat)
            ->withHeaders(['Idempotency-Key' => $cle])
            ->postJson("/api/v1/me/training/{$this->epreuve->code}", ['total' => 6]);

        $premier->assertCreated();

        $rejeu = $this->actingAs($this->candidat)
            ->withHeaders(['Idempotency-Key' => $cle])
            ->postJson("/api/v1/me/training/{$this->epreuve->code}", ['total' => 6]);

        $rejeu->assertOk();
        $this->assertSame($premier->json('data.uuid'), $rejeu->json('data.uuid'));
        $this->assertSame(1, Attempt::where('kind', 'training')->count());
    }
}
