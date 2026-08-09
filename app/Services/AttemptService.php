<?php

namespace App\Services;

use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\CauseRevealCounter;
use App\Models\Exam;
use App\Models\QuestionOption;
use App\Models\Response;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cycle de vie d'une tentative.
 *
 * Trois garanties, chacune adressant une panne réelle :
 *
 *  - Un double envoi n'ouvre pas deux tentatives (clé d'idempotence).
 *  - Une réponse est écrite immédiatement, seule, et ne dépend pas de la
 *    soumission finale. Un réseau qui coupe en plein examen ne fait perdre que
 *    la question en cours.
 *  - Le temps est décidé par le serveur. L'heure du client est conservée pour
 *    diagnostic, jamais pour arbitrer.
 */
final class AttemptService
{
    public function __construct(private readonly DiagnosticComposer $composer) {}

    /**
     * Ouvre un diagnostic, ou retourne celui déjà ouvert.
     *
     * Deux niveaux d'idempotence : la clé fournie par le client, et l'index
     * unique « une seule tentative de diagnostic en cours par épreuve ».
     */
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
            return $existante;   // rejeu : on rend la même, on n'en crée pas une seconde
        }

        $enCours = Attempt::where('user_id', $user->id)
            ->where('exam_id', $exam->id)
            ->where('kind', 'diagnostic')
            ->open()
            ->first();

        if ($enCours !== null && ! $enCours->hasExpired()) {
            return $enCours;     // on reprend, on ne recommence pas
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
                    // Copié, pas lu : re-rattacher la question plus tard ne
                    // doit pas réécrire l'historique du candidat.
                    'competency_node_id' => $question->competency_node_id,
                    'position' => $i + 1,
                ]);
            }

            return $attempt->fresh('items');
        });
    }

    /**
     * Enregistre une réponse. Écrite seule, immédiatement.
     * Rejouable : renvoyer la même réponse ne crée pas de doublon et ne
     * change rien ; changer d'avis met à jour tant que la tentative est ouverte.
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
            $nouvelle = $item->response()->doesntExist();

            $response = Response::updateOrCreate(
                ['attempt_item_id' => $item->id],
                [
                    'selected_option_id' => $option?->id,
                    'confidence' => $confidence,
                    'answered_at' => now(),             // horloge serveur
                    'client_reported_at' => $clientReportedAt, // conservée, non autoritative
                    'elapsed_ms' => $elapsedMs,
                ]
            );

            if ($nouvelle) {
                $attempt->increment('answered_count');
            }

            if ($item->presented_at === null) {
                $item->update(['presented_at' => now()]);
            }

            return $response;
        });
    }

    /**
     * Clôt la tentative et fige les corrections.
     *
     * La correction n'est calculée qu'ICI, jamais pendant : le candidat ne
     * doit pas pouvoir déduire la bonne réponse de la réaction du serveur.
     */
    public function submit(Attempt $attempt): Attempt
    {
        if ($attempt->status !== 'in_progress') {
            return $attempt;   // rejeu de soumission : sans effet
        }

        return DB::transaction(function () use ($attempt) {
            $justes = 0;

            foreach ($attempt->items()->with(['response.selectedOption'])->get() as $item) {
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

            $attempt->update([
                'status' => $attempt->hasExpired() ? 'expired' : 'submitted',
                'submitted_at' => now(),
                'correct_count' => $justes,
            ]);

            return $attempt->fresh();
        });
    }

    /**
     * Le candidat peut-il voir la cause de cette erreur ?
     * Fiche F03 : deux causes en compte gratuit, cumulatif, jamais réinitialisé.
     *
     * @return array{allowed: bool, revealed: int, quota: int}
     */
    public function canRevealCause(User $user, bool $hasPremiumAccess): array
    {
        $quota = (int) config('naja7i.free_cause_quota', 2);
        $compteur = CauseRevealCounter::firstOrCreate(['user_id' => $user->id]);

        if ($hasPremiumAccess) {
            return ['allowed' => true, 'revealed' => $compteur->revealed_total, 'quota' => 0];
        }

        return [
            'allowed' => $compteur->revealed_total < $quota,
            'revealed' => $compteur->revealed_total,
            'quota' => $quota,
        ];
    }

    /** Consomme une unité du quota. Idempotent par réponse. */
    public function markCauseRevealed(User $user, Response $response): void
    {
        if ($response->cause_revealed) {
            return;   // déjà décomptée : revoir sa correction ne recoûte rien
        }

        DB::transaction(function () use ($user, $response) {
            $response->update(['cause_revealed' => true]);

            $compteur = CauseRevealCounter::firstOrCreate(['user_id' => $user->id]);
            $compteur->increment('revealed_total');
            $compteur->update([
                'first_revealed_at' => $compteur->first_revealed_at ?? now(),
                'last_revealed_at' => now(),
            ]);
        });
    }
}
