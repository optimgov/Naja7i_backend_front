<?php

namespace App\Filament\Resources\Questions\Pages;

use App\Filament\Resources\Questions\Actions\ActesEditoriaux;
use App\Filament\Resources\Questions\QuestionResource;
use App\Models\Question;
use App\Services\QuestionAuthoringService;
use App\Services\QuestionTransitionService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Amender, et faire circuler la question dans la chaîne.
 *
 * AUCUNE TRANSITION N'EST ÉCRITE ICI. Chaque bouton appelle
 * `QuestionTransitionService`, qui refuse en transaction ce qui doit l'être :
 * une transition interdite, un valideur qui est l'auteur, une publication dont
 * les contrôles éditoriaux ne passent pas. Ce que ces actions ajoutent, c'est
 * la VISIBILITÉ — un bouton absent vaut mieux qu'un bouton qui échoue.
 *
 * Les deux ne se remplacent pas. `visible()` décide de ce qu'on montre, le
 * service décide de ce qui se produit ; retirer le premier ne casse aucune
 * garantie, retirer le second les casse toutes.
 */
class EditQuestion extends EditRecord
{
    protected static string $resource = QuestionResource::class;

    /**
     * Les options sont hydratées à la lecture — le répéteur n'a plus de
     * `->relationship()` pour le faire à notre place (audit tournée 3, BLOC-3).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['options'] = $this->record->options()
            ->orderBy('position')
            ->get(['content', 'is_correct', 'rationale', 'cause'])
            ->map(fn ($o) => $o->only(['content', 'is_correct', 'rationale', 'cause']))
            ->all();

        return $data;
    }

    /**
     * TOUT PASSE PAR LE SERVICE, ET DANS LA MÊME TRANSACTION.
     *
     * Cette méthode ne transmettait ni `locale` ni les OPTIONS. La langue était
     * éditable au formulaire, acceptée à l'écran, et perdue en base — un succès
     * partiel silencieux. Les options, elles, étaient sauvegardées par le
     * répéteur AVANT d'arriver ici, hors du service : la cause posée sur la
     * bonne réponse y survivait.
     *
     * `exam_id` reste absent, et volontairement : déplacer une question vers
     * une autre épreuve laisserait son nœud de compétence pointer sur l'arbre
     * de l'ancienne. L'API le refuse explicitement ; le formulaire le fige.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $attributs = [];

        foreach ([
            'stem', 'explanation', 'kind', 'difficulty', 'locale',
            'competency_node_id', 'remediation_id', 'mirror_question_id',
        ] as $champ) {
            if (array_key_exists($champ, $data)) {
                $attributs[$champ] = $data[$champ];
            }
        }

        return app(QuestionAuthoringService::class)->amender(
            $record,
            $attributs,
            $data['options'] ?? null,
        );
    }

    /**
     * LES ACTES SONT PARTAGÉS AVEC LE TABLEAU, et ce n'est pas une commodité.
     *
     * Cette page n'est atteignable qu'avec `questions.create` et sur une
     * question non gelée — deux conditions étrangères à relire, valider ou
     * retirer. Les héberger ICI SEULEMENT enfermait le relecteur dehors et
     * rendait une question publiée non retirable. Voir `ActesEditoriaux`.
     */
    protected function getHeaderActions(): array
    {
        return ActesEditoriaux::tous();
    }
}
