<?php

namespace App\Services;

use App\Models\Attempt;
use App\Models\ExamSession;
use App\Models\QuestionOption;
use App\Models\Response;
use App\Models\ReviewSchedule;
use App\Models\User;
use App\Support\UniqueViolation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * F07 — Rendez-vous Mémoire : la planification de la répétition espacée.
 *
 * UN CALENDRIER À CASIERS, PAS UN FACTEUR D'AISANCE.
 *
 * Paliers fixes à la Leitner plutôt qu'un SM-2. SM-2 produit des nombres qui
 * ont l'air scientifiques et ne le sont pas sur une banque jeune, sans aucun
 * historique de calibration : le projet refuse déjà la fausse précision
 * (METHODE §7.3). Un palier nommé s'explique en outre au candidat — « revu dans
 * 3 jours » se comprend, « facteur d'aisance 2,36 » non.
 *
 * ON PLANIFIE UNE ERREUR, PAS UNE QUESTION. Le rendez-vous porte le couple
 * (compétence, cause). Resservir douze fois le même item apprendrait l'item.
 *
 * AUCUNE PRÉDICTION. Une date de prochaine revue, et rien d'autre. Ni
 * probabilité de rétention, ni « vous retiendrez 80 % » (METHODE §7.3).
 */
final class MemoryScheduler
{
    /**
     * Les cinq paliers, en jours.
     *
     * VALEURS D'ARCHITECTE, à réétalonner sur données réelles — de même nature
     * que le 0,35 de la certitude (DET-19) et le 0,5 des domaines jamais
     * évalués (DET-22). Elles sont nommées et groupées ici pour cette raison :
     * une constante dispersée dans le code ne se réétalonne pas.
     */
    public const PALIERS = [1 => 1, 2 => 3, 3 => 7, 4 => 16, 5 => 35];

    /** Deux réussites certaines consécutives font sortir du calendrier. */
    public const SORTIES_CONSECUTIVES = 2;

    /**
     * Marge avant l'épreuve, en jours.
     *
     * Un rendez-vous programmé la veille du concours n'aide plus : le candidat
     * révise, il ne découvre pas. Valeur d'architecte, calibrable comme les
     * paliers.
     */
    public const MARGE_AVANT_EPREUVE = 2;

    /**
     * Plafond d'une journée de révision, en rendez-vous.
     *
     * Un candidat revenu après trois semaines a des dizaines de rendez-vous
     * échus. Les lui servir tous ferait une séance de deux heures qu'il
     * n'entamerait pas — et le calendrier deviendrait la chose qu'on repousse.
     *
     * Le plafond n'est JAMAIS silencieux : ce qui n'est pas servi est compté et
     * annoncé. Valeur d'architecte, calibrable comme les paliers.
     */
    public const PLAFOND_LISTE = 20;

    /**
     * Planifie les suites d'une tentative soumise.
     *
     * Appelée à la soumission, quand `is_correct` vient d'être figé, pour
     * TOUTE tentative close — révision, entraînement ou diagnostic. C'est la
     * condition de l'arbitrage ci-dessous : si seules les révisions passaient
     * par ici, un candidat qui travaille beaucoup ne ferait avancer aucun
     * palier.
     *
     * @return array{crees: int, avances: int, recules: int, sortis: int}
     */
    public function planFromAttempt(Attempt $attempt): array
    {
        $bilan = ['crees' => 0, 'avances' => 0, 'recules' => 0, 'sortis' => 0];

        $reponses = Response::query()
            ->join('attempt_items', 'attempt_items.id', '=', 'responses.attempt_item_id')
            ->join('question_options', 'question_options.id', '=', 'responses.selected_option_id')
            ->where('attempt_items.attempt_id', $attempt->id)
            ->whereNotNull('responses.is_correct')
            ->select([
                'responses.is_correct',
                'responses.confidence',
                'attempt_items.competency_node_id',
                'attempt_items.question_id',
                'question_options.cause',
            ])
            ->get();

        if ($reponses->isEmpty()) {
            return $bilan;
        }

        $echeance = $this->plafondDeSession($attempt);
        $pieges = $this->causesDesDistracteurs($reponses->pluck('question_id')->unique()->all());

        /*
         * UN COUPLE NE BOUGE QU'UNE FOIS PAR TENTATIVE, ET L'ÉCHEC PASSE EN
         * PREMIER. Les deux règles tombent du même constat, et sans elles
         * l'arbitrage DET-35 se retourne contre lui-même.
         *
         * Une série de six questions d'une même compétence tend six fois les
         * mêmes pièges. Sans dédoublonnage, six bonnes réponses feraient
         * avancer six fois le même rendez-vous — deux réussites certaines
         * suffisant à sortir du calendrier, une seule séance le viderait. Le
         * candidat a rencontré le couple UNE fois, pas six.
         *
         * Et sans la priorité à l'échec, une séance où le candidat tombe dans
         * le piège à la question 1 puis l'évite aux questions 2 à 6 créerait le
         * rendez-vous pour l'effacer aussitôt. L'erreur vient d'être démontrée :
         * elle ne s'annule pas par des réussites du même moment.
         *
         * @var array<string, true> $traites
         */
        $traites = [];

        foreach ($reponses->where('is_correct', '===', false) as $reponse) {
            /*
             * Sans cause, pas de rendez-vous. Un item resté SANS RÉPONSE n'a
             * pas de ligne ici du tout : F07 révise une erreur diagnostiquée,
             * et une question laissée vide n'en est pas une. Resservir « ce à
             * quoi tu n'as pas répondu » sans savoir pourquoi serait du
             * bachotage, pas de la remédiation.
             */
            if ($reponse->cause === null) {
                continue;
            }

            $couple = $reponse->competency_node_id.'|'.$reponse->cause;

            if (isset($traites[$couple])) {
                continue;   // déjà retombé au premier palier dans cette séance
            }

            $traites[$couple] = true;

            // Une réponse FAUSSE porte la cause du distracteur choisi : elle
            // identifie l'erreur directement.
            $existant = $this->rendezVous($attempt, (int) $reponse->competency_node_id, $reponse->cause);

            if ($existant === null) {
                $bilan[$this->creerOuRejoindre($attempt, $reponse, $echeance)]++;

                continue;
            }

            $bilan[$this->appliquer($existant, false, $reponse->confidence, $reponse->question_id, $echeance)]++;
        }

        /*
         * LES RÉUSSITES SONT REGROUPÉES PAR COUPLE AVANT D'ÊTRE APPLIQUÉES.
         *
         * Une même séance peut toucher un couple par plusieurs questions. Les
         * traiter dans l'ordre des items ferait dépendre le résultat du hasard
         * de la composition : si l'énoncé DÉJÀ VU tombait en premier, son gel
         * du compteur de sorties l'emporterait sur quatre sœurs réussies juste
         * après — alors que ces quatre-là sont la vraie preuve.
         *
         * On retient donc la MEILLEURE preuve du couple, puis on l'applique une
         * seule fois.
         *
         * @var array<string, list<object>> $reussites
         */
        $reussites = [];

        foreach ($reponses->where('is_correct', '===', true) as $reponse) {
            foreach ($pieges[$reponse->question_id] ?? [] as $cause) {
                $couple = $reponse->competency_node_id.'|'.$cause;

                if (isset($traites[$couple])) {
                    continue;   // l'échec du jour l'emporte
                }

                $reussites[$couple][] = $reponse;
            }
        }

        foreach ($reussites as $couple => $candidates) {
            [$nodeId, $cause] = explode('|', $couple, 2);

            $rdv = $this->rendezVous($attempt, (int) $nodeId, $cause);

            if ($rdv === null) {
                continue;   // jamais manqué, donc jamais planifié : rien à avancer
            }

            $preuve = $this->meilleurePreuve($candidates, $rdv);

            $bilan[$this->appliquer($rdv, true, $preuve->confidence, $preuve->question_id, $echeance)]++;
        }

        return $bilan;
    }

    /**
     * La réussite qui prouve le plus : une SŒUR plutôt que l'énoncé déjà vu.
     *
     * Reconnaître un énoncé n'est pas maîtriser une cause — mais si le candidat
     * a aussi réussi une autre question du même piège dans la séance, c'est
     * celle-là qui compte, et le rendez-vous progresse pour de bon.
     *
     * @param  list<object>  $candidates
     */
    private function meilleurePreuve(array $candidates, ReviewSchedule $rdv): object
    {
        foreach ($candidates as $reponse) {
            if ($reponse->question_id !== $rdv->last_question_id) {
                return $reponse;
            }
        }

        return $candidates[0];
    }

    /**
     * Le rendez-vous d'un couple, VERROUILLÉ pour la durée de la transaction.
     *
     * ORDRE DE VERROUILLAGE : tentative, puis items, puis rendez-vous. Le même
     * que dans `AttemptService::answer()` et `submit()`, d'où cette méthode est
     * appelée. Un ordre implicite finit toujours par s'inverser, et
     * l'interblocage qui en résulte ne se reproduit qu'en charge — d'où ce
     * commentaire plutôt qu'une convention orale.
     *
     * Sans ce verrou, deux tentatives du même candidat soumises simultanément
     * sur le même couple lisaient toutes deux le même palier, et l'une des deux
     * progressions se perdait — lecture, calcul, écriture, sans rien entre les
     * trois.
     */
    private function rendezVous(Attempt $attempt, int $nodeId, string $cause): ?ReviewSchedule
    {
        return ReviewSchedule::where('user_id', $attempt->user_id)
            ->where('competency_node_id', $nodeId)
            ->where('cause', $cause)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Crée le rendez-vous, ou rejoint celui qu'une transaction concurrente
     * vient de créer.
     *
     * UNE VIOLATION D'INDEX EST UNE RELECTURE, PAS UNE ERREUR. Deux tentatives
     * simultanées sur le même couple lisent toutes deux « absent » : l'une
     * gagne, l'autre butait sur `review_schedules_unique_erreur` APRÈS que sa
     * propre tentative soit close. Le candidat perdait sa soumission pour une
     * course qu'il n'a pas provoquée.
     *
     * LE POINT DE REPRISE N'EST PAS DÉCORATIF. En PostgreSQL, une erreur avorte
     * la transaction ENTIÈRE : sans `SAVEPOINT`, rattraper l'exception ne
     * servirait à rien, la clôture échouerait quand même. `DB::transaction`
     * imbriquée en pose un et y revient toute seule.
     *
     * @return 'crees'|'avances'|'recules'|'sortis'
     */
    private function creerOuRejoindre(Attempt $attempt, object $reponse, ?CarbonImmutable $echeance): string
    {
        try {
            DB::transaction(fn () => $this->creer($attempt, $reponse, $echeance));

            return 'crees';
        } catch (QueryException $e) {
            if (! UniqueViolation::on($e, 'review_schedules_unique_erreur')) {
                throw $e;
            }

            $rdv = $this->rendezVous($attempt, (int) $reponse->competency_node_id, $reponse->cause);

            if ($rdv === null) {
                throw $e;   // l'index a parlé, la ligne n'est pas là : on ne devine pas
            }

            return $this->appliquer($rdv, false, $reponse->confidence, $reponse->question_id, $echeance);
        }
    }

    /**
     * Causes portées par les distracteurs, par question.
     *
     * Une seule requête pour toute la tentative : la lecture par question
     * aurait coûté un aller-retour par réponse, sur le chemin de soumission.
     *
     * @param  list<int>  $questionIds
     * @return array<int, list<string>>
     */
    private function causesDesDistracteurs(array $questionIds): array
    {
        return QuestionOption::whereIn('question_id', $questionIds)
            ->where('is_correct', false)
            ->whereNotNull('cause')
            ->get(['question_id', 'cause'])
            ->groupBy('question_id')
            ->map(fn ($options) => $options->pluck('cause')->unique()->values()->all())
            ->all();
    }

    /**
     * Palier suivant, selon la justesse ET la certitude déclarée.
     *
     * La certitude est déjà collectée à la réponse : elle doit servir ici, sans
     * quoi « juste au hasard » vaudrait « juste, je savais ».
     *
     * @return array{palier: int, sure: bool, aveugle: bool}
     */
    public function prochainPalier(int $palierActuel, bool $juste, string $certitude): array
    {
        if (! $juste) {
            return [
                'palier' => 1,
                'sure' => false,
                // Faux ALORS QU'ON ÉTAIT SÛR : le candidat ne sait pas qu'il ne
                // sait pas. C'est l'erreur qu'il ne réviserait jamais seul.
                'aveugle' => $certitude === 'sure',
            ];
        }

        return match ($certitude) {
            'sure' => ['palier' => min($palierActuel + 1, count(self::PALIERS)), 'sure' => true, 'aveugle' => false],
            'hesitant' => ['palier' => $palierActuel, 'sure' => false, 'aveugle' => false],
            // Juste au hasard : ce n'est pas une acquisition, on redescend.
            default => ['palier' => max($palierActuel - 1, 1), 'sure' => false, 'aveugle' => false],
        };
    }

    /**
     * Date d'échéance d'un palier, bornée par la session d'examen.
     *
     * Un rendez-vous programmé après l'épreuve ne sert à rien : on le ramène
     * avant. Si même le premier palier tombe après, la date de l'épreuve fait
     * foi — mieux vaut un dernier rappel tardif que pas de rappel.
     */
    public function echeance(int $palier, ?CarbonImmutable $plafond = null): CarbonImmutable
    {
        $tz = config('naja7i.timezone_candidat');
        $date = CarbonImmutable::now($tz)->startOfDay()->addDays(self::PALIERS[$palier]);

        if ($plafond !== null && $date->greaterThan($plafond)) {
            return $plafond;
        }

        return $date;
    }

    /**
     * Dernière date utile avant l'épreuve, ou null si aucune session datée.
     *
     * `dates_confirmed` n'est pas exigé : une date annoncée mais non confirmée
     * reste une meilleure borne que pas de borne du tout. Le catalogue signale
     * déjà l'incertitude au candidat (ADR-0014).
     */
    private function plafondDeSession(Attempt $attempt): ?CarbonImmutable
    {
        $exam = $attempt->exam;

        if ($exam === null) {
            return null;
        }

        $date = ExamSession::query()
            ->join('exam_families', 'exam_families.id', '=', 'exam_sessions.exam_family_id')
            ->join('tracks', 'tracks.exam_family_id', '=', 'exam_families.id')
            ->where('tracks.id', $exam->track_id)
            ->whereNotNull('exam_sessions.written_exam_on')
            ->where('exam_sessions.written_exam_on', '>=', now()->toDateString())
            ->orderBy('exam_sessions.written_exam_on')
            ->value('exam_sessions.written_exam_on');

        if ($date === null) {
            return null;
        }

        $tz = config('naja7i.timezone_candidat');

        return CarbonImmutable::parse($date, $tz)
            ->startOfDay()
            ->subDays(self::MARGE_AVANT_EPREUVE);
    }

    private function creer(Attempt $attempt, object $reponse, ?CarbonImmutable $plafond): void
    {
        $aveugle = $reponse->confidence === 'sure';

        ReviewSchedule::create([
            'user_id' => $attempt->user_id,
            'exam_id' => $attempt->exam_id,
            'competency_node_id' => $reponse->competency_node_id,
            'cause' => $reponse->cause,
            'last_question_id' => $reponse->question_id,
            'palier' => 1,
            'due_on' => $this->echeance(1, $plafond),
            'consecutive_sure' => 0,
            'blind_error' => $aveugle,
            'last_reviewed_at' => now(),
        ]);
    }

    /** @return 'avances'|'recules'|'sortis' */
    private function appliquer(
        ReviewSchedule $rdv,
        bool $juste,
        string $certitude,
        int $questionId,
        ?CarbonImmutable $plafond,
    ): string {
        $suite = $this->prochainPalier($rdv->palier, $juste, $certitude);

        /*
         * RECONNAÎTRE UN ÉNONCÉ N'EST PAS MAÎTRISER UNE CAUSE.
         *
         * Quand la banque ne compte qu'une seule question pour un couple,
         * `ReviewComposer` ressert celle que le rendez-vous a déjà fait servir
         * — mieux vaut retravailler le même énoncé que sauter une échéance. Le
         * candidat le reconnaît alors, et le reconnaître ne prouve rien.
         *
         * Sa réussite ne fait donc PAS progresser le compteur de sorties : elle
         * le gèle. Un couple servi indéfiniment par la même question ne peut
         * jamais quitter le calendrier. L'échec, lui, remet toujours à zéro —
         * se tromper sur un énoncé déjà vu est un signal plus fort encore.
         */
        $memeEnonce = $rdv->last_question_id !== null && $rdv->last_question_id === $questionId;

        $consecutifs = match (true) {
            ! $suite['sure'] => 0,
            $memeEnonce => $rdv->consecutive_sure,
            default => $rdv->consecutive_sure + 1,
        };

        // PORTE DE SORTIE. Sans elle la liste grossit indéfiniment.
        if ($consecutifs >= self::SORTIES_CONSECUTIVES) {
            $rdv->delete();

            return 'sortis';
        }

        /* Le palier d'AVANT, retenu ici et non relu après coup : `update()`
         * resynchronise les attributs d'origine, si bien que
         * `getOriginal('palier')` rendait déjà la nouvelle valeur et que le
         * bilan comptait toujours « reculé ». Personne ne lisait ce retour
         * jusqu'ici ; la route de session le sert désormais au client. */
        $avant = $rdv->palier;

        $rdv->update([
            'palier' => $suite['palier'],
            'due_on' => $this->echeance($suite['palier'], $plafond),
            'consecutive_sure' => $consecutifs,
            'blind_error' => $suite['aveugle'],
            'last_question_id' => $questionId,
            'last_reviewed_at' => now(),
        ]);

        return $suite['palier'] > $avant ? 'avances' : 'recules';
    }

    /**
     * Rendez-vous échus, les plus urgents d'abord, plafonnés.
     *
     * AUCUN PLAFOND SILENCIEUX. On sert au plus `PLAFOND_LISTE`, et l'appelant
     * annonce le reste : « 20 aujourd'hui, 47 en attente » dit au candidat où
     * il en est. À qui on cache 47, il croit avoir fini.
     *
     * @return Collection<int, ReviewSchedule>
     */
    public function due(User $user, int $examId, ?int $limite = null): Collection
    {
        return ReviewSchedule::where('user_id', $user->id)
            ->where('exam_id', $examId)
            ->due()
            ->urgentFirst()
            ->with('node')
            ->limit($limite ?? self::PLAFOND_LISTE)
            ->get();
    }

    /** Nombre de rendez-vous échus, sans en composer la liste. */
    public function dueCount(User $user, int $examId): int
    {
        return ReviewSchedule::where('user_id', $user->id)
            ->where('exam_id', $examId)
            ->due()
            ->count();
    }

    /** Prochaine échéance à venir, pour dire « rien aujourd'hui, prochain le 14 ». */
    public function prochaineEcheance(User $user, int $examId): ?CarbonImmutable
    {
        $tz = config('naja7i.timezone_candidat');

        $date = ReviewSchedule::where('user_id', $user->id)
            ->where('exam_id', $examId)
            ->whereDate('due_on', '>', now($tz)->toDateString())
            ->orderBy('due_on')
            ->value('due_on');

        return $date === null ? null : CarbonImmutable::parse($date, $tz);
    }
}
