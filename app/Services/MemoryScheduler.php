<?php

namespace App\Services;

use App\Models\Attempt;
use App\Models\ExamSession;
use App\Models\Response;
use App\Models\ReviewSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;

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
     * Planifie les suites d'une tentative soumise.
     *
     * Appelée depuis le même endroit que le recalcul de maîtrise : à la
     * soumission, quand `is_correct` vient d'être figé.
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

        $echeance = $this->plafondDeSession($attempt);

        foreach ($reponses as $reponse) {
            /*
             * Sans cause, pas de rendez-vous. Une bonne réponse n'en porte
             * jamais (contrainte du PAS-5), et un item resté SANS RÉPONSE n'a
             * pas de ligne ici du tout : F07 révise une erreur diagnostiquée,
             * et une question laissée vide n'en est pas une. Resservir « ce à
             * quoi tu n'as pas répondu » sans savoir pourquoi serait du
             * bachotage, pas de la remédiation.
             */
            if ($reponse->cause === null && ! $reponse->is_correct) {
                continue;
            }

            /*
             * Retrouver le rendez-vous concerné n'est pas symétrique.
             *
             * Une réponse FAUSSE porte la cause du distracteur choisi : elle
             * identifie l'erreur directement.
             *
             * Une réponse JUSTE n'en porte aucune — la bonne option ne peut pas
             * avoir de cause (contrainte du PAS-5). Elle ne dit donc pas QUELLE
             * erreur elle vient de résoudre. On passe alors par
             * `last_question_id` : le rendez-vous se souvient de la question
             * qu'il a fait servir, et c'est ce lien qui permet à une réussite de
             * le faire avancer.
             */
            $existant = $reponse->is_correct
                ? ReviewSchedule::where('user_id', $attempt->user_id)
                    ->where('competency_node_id', $reponse->competency_node_id)
                    ->where('last_question_id', $reponse->question_id)
                    ->first()
                : ReviewSchedule::where('user_id', $attempt->user_id)
                    ->where('competency_node_id', $reponse->competency_node_id)
                    ->where('cause', $reponse->cause)
                    ->first();

            // N'ENTRE AU CALENDRIER QUE CE QUI A ÉTÉ MANQUÉ. On ne fait pas
            // réviser ce qui n'a jamais posé problème.
            if ($existant === null) {
                if ($reponse->is_correct) {
                    continue;
                }

                $this->creer($attempt, $reponse, $echeance);
                $bilan['crees']++;

                continue;
            }

            $issue = $this->appliquer($existant, (bool) $reponse->is_correct, $reponse->confidence, $reponse->question_id, $echeance);
            $bilan[$issue]++;
        }

        return $bilan;
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

        $consecutifs = $suite['sure'] ? $rdv->consecutive_sure + 1 : 0;

        // PORTE DE SORTIE. Sans elle la liste grossit indéfiniment.
        if ($consecutifs >= self::SORTIES_CONSECUTIVES) {
            $rdv->delete();

            return 'sortis';
        }

        $rdv->update([
            'palier' => $suite['palier'],
            'due_on' => $this->echeance($suite['palier'], $plafond),
            'consecutive_sure' => $consecutifs,
            'blind_error' => $suite['aveugle'],
            'last_question_id' => $questionId,
            'last_reviewed_at' => now(),
        ]);

        return $suite['palier'] > $rdv->getOriginal('palier') ? 'avances' : 'recules';
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
