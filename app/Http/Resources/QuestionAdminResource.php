<?php

namespace App\Http\Resources;

use App\Models\QuestionOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une question vue par la chaîne ÉDITORIALE, jamais par un candidat.
 *
 * Elle montre volontairement ce qu'`AttemptQuestionResource` cache — la bonne
 * réponse, les justifications, les causes. C'est la matière du rédacteur et du
 * relecteur : la leur cacher rendrait la relecture impossible.
 *
 * D'où l'importance de la SÉPARATION DES CLASSES, tenue depuis le PAS-6 : trois
 * ressources distinctes pour trois publics, et aucun drapeau qui ferait
 * basculer l'une en l'autre. Ces routes sont derrière une permission déclarée ;
 * cette classe ne doit jamais servir une réponse du parcours candidat.
 *
 * Liste blanche stricte : un champ ajouté demain au modèle n'apparaît pas ici
 * par accident. `author_uuid` est rendu opaque à dessein — filtrer par auteur
 * ne demande pas de publier l'identité du personnel.
 */
class QuestionAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'locale' => $this->locale,
            'kind' => $this->kind,
            'stem' => $this->stem,
            'explanation' => $this->explanation,
            'difficulty' => $this->difficulty,
            'version' => $this->version,
            'eligible_for_diagnostic' => $this->eligible_for_diagnostic,
            'eligible_for_simulation' => $this->eligible_for_simulation,
            'published_at' => $this->published_at?->toIso8601String(),
            'retired_at' => $this->retired_at?->toIso8601String(),
            'author_uuid' => $this->whenLoaded('author', fn () => $this->author?->uuid),
            'competency' => $this->whenLoaded('node', fn () => [
                'uuid' => $this->node?->uuid,
                'code' => $this->node?->code,
                'name' => $this->node?->localized('name'),
                'depth' => $this->node?->depth,
            ]),
            'exam' => $this->whenLoaded('exam', fn () => [
                'code' => $this->exam?->code,
                'name' => $this->exam?->localized('name'),
            ]),
            'options' => $this->whenLoaded('options', fn () => $this->options
                ->sortBy('position')
                ->map(fn (QuestionOption $o) => [
                    'uuid' => $o->uuid,
                    'position' => $o->position,
                    'content' => $o->content,
                    'is_correct' => $o->is_correct,
                    'rationale' => $o->rationale,
                    'cause' => $o->cause,
                ])->values()),
            'sources' => $this->whenLoaded('contentSources', fn () => $this->contentSources
                ->map(fn ($s) => [
                    'code' => $s->code,
                    'locator' => $s->pivot->locator,
                    /* Le rédacteur DOIT voir ce drapeau : c'est lui qui décide
                     * si la question pourra servir au diagnostic. */
                    'verification' => $s->pivot->verification,
                ])->values()),
        ];
    }
}
