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
 * CONTRE-REVUE BLOC-1 — la course réponse/soumission était toujours ouverte,
 * après deux tentatives de correction.
 *
 * Ce qui n'allait pas : `answer()` lisait l'état de la tentative AVANT la
 * transaction, puis ne verrouillait que l'item. `submit()` verrouillait la
 * tentative. Les deux ne se disputaient donc jamais la même ligne :
 *
 *     A lit « in_progress »
 *     B verrouille la tentative, corrige, clôt
 *     A entre en transaction, verrouille l'item, écrit
 *     → une réponse existe après une correction qui l'ignore
 *
 * Et le test censé le couvrir était séquentiel : il soumettait entièrement
 * avant d'appeler `answer()`. Il vérifiait « une tentative close refuse une
 * réponse », pas l'entrelacement.
 *
 * Correction : `answer()` verrouille et RELIT la tentative en tête de
 * transaction, puis vérifie son état sous verrou. L'ordre de verrouillage est
 * identique dans les deux méthodes — tentative, puis items — pour qu'aucun
 * interblocage ne remplace la course.
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
     * Enregistre une réponse.
     *
     * Le contrôle préalable hors transaction est conservé pour produire un
     * message immédiat dans le cas courant — mais il ne DÉCIDE rien : la seule
     * vérification qui fait foi est celle effectuée sous verrou.
     */
    public function answer(
        AttemptItem $item,
        ?QuestionOption $option,
        string $confidence,
        ?int $elapsedMs = null,
        ?string $clientReportedAt = null,
    ): Response {
        if ($option !== null && $option->question_id !== $item->question_id) {
            throw new RuntimeException('L\'option choisie n\'appartient pas à la question présentée.');
        }

        return DB::transaction(function () use ($item, $option, $confidence, $elapsedMs, $clientReportedAt) {
            // 1. La tentative d'abord — même ordre que submit(), pas d'interblocage.
            $attempt = Attempt::where('id', $item->attempt_id)->lockForUpdate()->first();

            if ($attempt === null) {
                throw new RuntimeException('Tentative introuvable.');
            }

            // 2. L'état est relu sous verrou : une soumission concurrente est
            //    désormais visible, et elle gagne.
            if ($attempt->status !== 'in_progress') {
                throw new RuntimeException(
                    'Cette tentative est close : aucune réponse ne peut plus être enregistrée.'
                );
            }

            if ($attempt->hasExpired()) {
                throw new RuntimeException('Cette tentative a expiré.');
            }

            // 3. Puis l'item.
            $verrouille = AttemptItem::where('id', $item->id)->lockForUpdate()->first();
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

            if ($existante === null) {
                Attempt::where('id', $attempt->id)->increment('answered_count');
            }

            if ($verrouille !== null && $verrouille->presented_at === null) {
                AttemptItem::where('id', $item->id)->update(['presented_at' => now()]);
            }

            return $response;
        });
    }

    /**
     * Clôt la tentative et fige les corrections.
     * Même ordre de verrouillage que `answer()` : tentative, puis items.
     */
    public function submit(Attempt $attempt): Attempt
    {
        return DB::transaction(function () use ($attempt) {
            $verrouillee = Attempt::where('id', $attempt->id)->lockForUpdate()->first();

            if ($verrouillee === null || $verrouillee->status !== 'in_progress') {
                return $verrouillee ?? $attempt;   // rejeu, ou soumission concurrente
            }

            $justes = 0;

            $items = $verrouillee->items()
                ->lockForUpdate()
                ->with(['response.selectedOption'])
                ->get();

            foreach ($items as $item) {
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
