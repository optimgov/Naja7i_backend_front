<?php

namespace App\Services;

use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\Exam;
use App\Models\QuestionOption;
use App\Models\Response;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cycle de vie d'une tentative.
 *
 * REVUE PAS-6 BLOC-2 — deux séquences « lire puis écrire » sans verrou :
 *
 *  - `answer()` faisait `doesntExist()` puis `updateOrCreate()`. Deux requêtes
 *    concurrentes sur le même item pouvaient toutes deux conclure à l'absence :
 *    l'une échouait sur l'unicité, ou `answered_count` était incrémenté deux
 *    fois pour une seule réponse.
 * Corrigé par verrouillage de ligne et par incrément conditionnel : la base
 * arbitre, l'application compte les lignes affectées.
 *
 * PAS-11 — le décompte des causes révélées a quitté ce service pour
 * `CauseRevealService` : la revue PAS-10 BLOC-3 a montré que l'atomicité y
 * portait sur la réponse alors que la ressource rare est le quota. Les deux
 * sujets n'avaient pas à cohabiter dans la même classe.
 */
final class AttemptService
{
    public function __construct(private readonly DiagnosticComposer $composer) {}

    public function startDiagnostic(
        User $user,
        Exam $exam,
        string $locale,
        string $idempotencyKey,
        int $total = 10,
        ?int $durationMinutes = null,
    ): Attempt {
        $existante = Attempt::where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existante !== null) {
            return $existante;
        }

        $enCours = Attempt::where('user_id', $user->id)
            ->where('exam_id', $exam->id)
            ->where('kind', 'diagnostic')
            ->open()
            ->first();

        if ($enCours !== null && ! $enCours->hasExpired()) {
            return $enCours;
        }

        $questions = $this->composer->compose($exam, $locale, $total);

        if ($questions->count() < $total) {
            throw new RuntimeException(
                "Série incomplète : {$questions->count()} questions disponibles sur {$total} pour l'épreuve {$exam->code}."
            );
        }

        return DB::transaction(function () use ($user, $exam, $locale, $idempotencyKey, $questions, $durationMinutes) {
            $attempt = Attempt::create([
                'user_id' => $user->id,
                'exam_id' => $exam->id,
                'locale' => $locale,
                'idempotency_key' => $idempotencyKey,
                'kind' => 'diagnostic',
                'status' => 'in_progress',
                'started_at' => now(),
                'expires_at' => $durationMinutes ? now()->addMinutes($durationMinutes) : null,
                'item_count' => $questions->count(),
            ]);

            foreach ($questions as $i => $question) {
                AttemptItem::create([
                    'attempt_id' => $attempt->id,
                    'question_id' => $question->id,
                    'competency_node_id' => $question->competency_node_id,
                    'position' => $i + 1,
                ]);
            }

            return $attempt->fresh('items');
        });
    }

    /**
     * Enregistre une réponse, de façon idempotente même sous concurrence.
     *
     * L'item est verrouillé pour la durée de la transaction : deux requêtes
     * simultanées se sérialisent, la seconde voit la réponse de la première et
     * la met à jour au lieu d'en créer une seconde.
     */
    public function answer(
        AttemptItem $item,
        ?QuestionOption $option,
        string $confidence,
        ?int $elapsedMs = null,
        ?string $clientReportedAt = null,
    ): Response {
        $attempt = $item->attempt;

        if (! $attempt->isOpen()) {
            throw new RuntimeException('Cette tentative est close : aucune réponse ne peut plus être enregistrée.');
        }

        if ($option !== null && $option->question_id !== $item->question_id) {
            throw new RuntimeException('L\'option choisie n\'appartient pas à la question présentée.');
        }

        return DB::transaction(function () use ($item, $option, $confidence, $elapsedMs, $clientReportedAt, $attempt) {
            // Verrou de ligne : sérialise les écritures concurrentes sur cet item.
            AttemptItem::where('id', $item->id)->lockForUpdate()->first();

            $existante = Response::where('attempt_item_id', $item->id)->lockForUpdate()->first();

            $response = Response::updateOrCreate(
                ['attempt_item_id' => $item->id],
                [
                    'selected_option_id' => $option?->id,
                    'confidence' => $confidence,
                    'answered_at' => now(),
                    'client_reported_at' => $clientReportedAt,
                    'elapsed_ms' => $elapsedMs,
                ]
            );

            // L'incrément n'a lieu que si la réponse est réellement nouvelle,
            // constaté sous verrou et non par une lecture préalable.
            if ($existante === null) {
                Attempt::where('id', $attempt->id)->increment('answered_count');
            }

            if ($item->presented_at === null) {
                $item->update(['presented_at' => now()]);
            }

            return $response;
        });
    }

    public function submit(Attempt $attempt): Attempt
    {
        if ($attempt->status !== 'in_progress') {
            return $attempt;
        }

        return DB::transaction(function () use ($attempt) {
            $verrouillee = Attempt::where('id', $attempt->id)->lockForUpdate()->first();

            if ($verrouillee->status !== 'in_progress') {
                return $verrouillee;   // une soumission concurrente a gagné
            }

            $justes = 0;

            foreach ($verrouillee->items()->with(['response.selectedOption'])->get() as $item) {
                $response = $item->response;

                if ($response === null) {
                    continue;
                }

                $correcte = $response->selectedOption?->is_correct === true;
                $response->update(['is_correct' => $correcte]);

                if ($correcte) {
                    $justes++;
                }
            }

            $verrouillee->update([
                'status' => $verrouillee->hasExpired() ? 'expired' : 'submitted',
                'submitted_at' => now(),
                'correct_count' => $justes,
            ]);

            return $verrouillee->fresh();
        });
    }
}
