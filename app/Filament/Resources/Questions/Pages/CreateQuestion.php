<?php

namespace App\Filament\Resources\Questions\Pages;

use App\Filament\Resources\Questions\QuestionResource;
use App\Models\Source;
use App\Services\QuestionAuthoringService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * L'écriture passe par le SERVICE, jamais par Filament.
 *
 * `CreateRecord` appellerait `Question::create($data)` puis écrirait les
 * options par la relation. Ce chemin contournerait `QuestionAuthoringService`,
 * et avec lui trois choses que six pas ont construites : le `sibling_group`
 * neuf qui garantit qu'une question reste monolingue, la cause refusée sur la
 * bonne réponse, et l'état de vérification de la source recopié au moment de la
 * citation.
 *
 * On détourne donc l'enregistrement. C'est le seul point du panneau où Filament
 * voulait écrire lui-même — le reste n'est que de la saisie.
 */
class CreateQuestion extends CreateRecord
{
    protected static string $resource = QuestionResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $source = isset($data['source_code'])
            ? Source::where('code', $data['source_code'])->first()
            : null;

        return app(QuestionAuthoringService::class)->rediger(
            auth()->user(),
            [
                'exam_id' => $data['exam_id'],
                'competency_node_id' => $data['competency_node_id'],
                'locale' => $data['locale'],
                'stem' => $data['stem'],
                'explanation' => $data['explanation'],
                'kind' => $data['kind'] ?? 'qcm_single',
                'difficulty' => $data['difficulty'] ?? null,
                'remediation_id' => $data['remediation_id'] ?? null,
                'mirror_question_id' => $data['mirror_question_id'] ?? null,
            ],
            $data['options'] ?? [],
            $source,
            $data['source_locator'] ?? null,
        );
    }

    /**
     * Le dépôt de la relation `options` est déjà écrit par le service : sans ce
     * retrait, Filament le rejouerait après coup et doublerait les options.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data;
    }

    protected function getRedirectUrl(): string
    {
        /* Vers l'édition, et non vers la liste : ce qui bloque la publication
         * s'affiche sur la fiche, et c'est ce que le rédacteur doit lire juste
         * après avoir écrit. */
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
