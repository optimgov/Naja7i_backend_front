<?php

namespace App\Services;

use App\Models\Question;
use App\Models\Source;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Contrôle documentaire d'une source — DET-46, tranché au PAS-28.
 *
 * LA VÉRIFICATION QUALIFIE LA SOURCE, PAS LA CITATION. Une source est citée par
 * plusieurs questions ; la vérifier une fois profite à toutes. L'acte est donc
 * porté par `sources`, et propagé aux citations qui peuvent encore l'être.
 *
 * CE QUI NE SE PROPAGE PAS : les citations des questions PUBLIÉES. Elles sont
 * gelées par trigger depuis la contre-revue du PAS-12, et pour une raison qui
 * tient toujours — la correction déjà servie au candidat s'appuyait sur l'état
 * d'alors. Une question publiée pour l'entraînement seul, dont la source est
 * vérifiée après coup, ne devient pas rétroactivement éligible au diagnostic :
 * elle donne lieu à une nouvelle version, comme toute modification de contenu
 * publié (ADR-0015 §5).
 */
final class SourceVerificationService
{
    /**
     * @return array{source: Source, citations_mises_a_jour: int}
     */
    public function verifier(Source $source, User $verificateur): array
    {
        return DB::transaction(function () use ($source, $verificateur) {
            $source->forceFill([
                'verified_at' => now(),
                'verified_by' => $verificateur->id,
            ])->save();

            /* Propagation aux citations encore modifiables. Le filtre sur le
             * statut n'est pas une précaution : sans lui, le trigger de gel
             * refuserait l'UPDATE et la vérification entière échouerait pour
             * une citation qu'on n'avait pas besoin de toucher.
             *
             * Par Eloquent, et non par le query builder : la garde
             * architecturale du PAS-10 refuse `DB::table(` dans `app/`, et elle
             * a raison de le faire même ici où le catalogue est global — la
             * règle vaut par sa constance, pas par ses exceptions. */
            $questions = Question::whereNotIn('status', ['published', 'retired'])
                ->whereHas('contentSources', fn ($q) => $q->where('sources.id', $source->id))
                ->get();

            foreach ($questions as $question) {
                $question->contentSources()
                    ->updateExistingPivot($source->id, ['verification' => 'verified']);
            }

            return ['source' => $source->fresh(), 'citations_mises_a_jour' => $questions->count()];
        });
    }

    /** Une source vérifiée l'est pour toute citation faite APRÈS son contrôle. */
    public function etatPourUneCitation(?Source $source): string
    {
        return $source?->verified_at !== null ? 'verified' : 'unverified';
    }
}
