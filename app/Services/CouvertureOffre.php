<?php

namespace App\Services;

use App\Models\AccessGrantRecord;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Plan;
use App\Models\Question;
use Illuminate\Database\Eloquent\Builder;

/** Mesure informative du contenu réellement utilisable dans la portée d'une offre. */
final class CouvertureOffre
{
    public function __construct(private readonly DiagnosticComposer $diagnostics) {}

    /** @return array{epreuves:int,jouables:int,questions:int} */
    public function mesurer(Plan $plan): array
    {
        $epreuves = $this->epreuves($plan)->get();
        $jouables = $epreuves->filter(function (Exam $exam): bool {
            foreach ($exam->languages_allowed ?? ['fr'] as $locale) {
                if ($this->diagnostics->isReady($exam, $locale)) {
                    return true;
                }
            }

            return false;
        })->count();

        return [
            'epreuves' => $epreuves->count(),
            'jouables' => $jouables,
            'questions' => Question::forDiagnostic()->whereIn('exam_id', $epreuves->pluck('id'))->count(),
        ];
    }

    public function message(Plan $plan): string
    {
        $mesure = $this->mesurer($plan);

        return "Phase de test — {$mesure['epreuves']} épreuve(s) configurée(s), "
            ."{$mesure['jouables']} jouable(s), {$mesure['questions']} question(s) publiée(s). "
            .'Le coupon peut être généré même si aucune épreuve n’est encore jouable.';
    }

    /** @return Builder<Exam> */
    private function epreuves(Plan $plan): Builder
    {
        $query = Exam::query()->published();

        return match ($plan->scope_type) {
            null => $query,
            AccessGrantRecord::SCOPE_EXAM => $query->where('uuid', $plan->scope_uuid),
            AccessGrantRecord::SCOPE_EXAM_FAMILY => $query->whereHas(
                'track.family',
                fn (Builder $famille): Builder => $famille->where('uuid', $plan->scope_uuid),
            ),
            AccessGrantRecord::SCOPE_FILIERE => $query->whereHas(
                'track.family.filiere',
                fn (Builder $filiere): Builder => $filiere->where('uuid', $plan->scope_uuid),
            ),
            AccessGrantRecord::SCOPE_AUDIENCE => $query->whereHas(
                'track.family.audience',
                fn (Builder $audience): Builder => $audience->where('uuid', $plan->scope_uuid),
            ),
            AccessGrantRecord::SCOPE_COMPETENCY_NODE => $query->whereKey(
                CompetencyNode::where('uuid', $plan->scope_uuid)->value('exam_id'),
            ),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
