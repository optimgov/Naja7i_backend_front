<?php

namespace App\Filament\Resources\Questions\Pages;

use App\Filament\Resources\Questions\QuestionResource;
use App\Models\Question;
use App\Services\QuestionAuthoringService;
use App\Services\QuestionTransitionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

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

    protected function getHeaderActions(): array
    {
        return [
            $this->soumettreALaRelecture(),
            $this->marquerRelue(),
            $this->valider(),
            $this->publier(),
            $this->retirer(),
        ];
    }

    private function soumettreALaRelecture(): Action
    {
        return Action::make('soumettre')
            ->label('Soumettre à la relecture')
            ->icon('heroicon-o-paper-airplane')
            ->visible(fn (Question $record) => $record->status === 'draft')
            ->action(fn (Question $record) => $this->transition(
                fn () => app(QuestionTransitionService::class)->submitForReview($record),
                'Soumise à la relecture.'
            ));
    }

    private function marquerRelue(): Action
    {
        return Action::make('relire')
            ->label('Marquer relue')
            ->icon('heroicon-o-eye')
            ->visible(fn (Question $record) => auth()->user()->can('review', $record))
            ->action(fn (Question $record) => $this->transition(
                fn () => app(QuestionTransitionService::class)->markReviewed($record, auth()->user()),
                'Relecture enregistrée.'
            ));
    }

    /**
     * LE BOUTON N'EXISTE PAS POUR L'AUTEUR, il n'échoue pas devant lui.
     *
     * `QuestionPolicy::validate()` refuse l'auteur, comme le service. La garde
     * reste en dessous ; ce qui est gagné ici est qu'un rédacteur ne clique pas
     * sur un bouton conçu pour le refuser. Un bouton qui échoue en 422 est une
     * garde qui marche et une interface qui ment.
     */
    private function valider(): Action
    {
        return Action::make('valider')
            ->label('Valider pédagogiquement')
            ->icon('heroicon-o-check-badge')
            ->requiresConfirmation()
            ->visible(fn (Question $record) => auth()->user()->can('validate', $record))
            ->action(fn (Question $record) => $this->transition(
                fn () => app(QuestionTransitionService::class)->validate($record, auth()->user()),
                'Validée pédagogiquement.'
            ));
    }

    private function publier(): Action
    {
        return Action::make('publier')
            ->label('Publier')
            ->icon('heroicon-o-rocket-launch')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('La publication GÈLE le contenu. Le corriger ensuite demande une nouvelle version.')
            ->schema([
                Toggle::make('for_diagnostic')
                    ->label('Éligible au diagnostic')
                    ->helperText('Exige une cause sur chaque distracteur, une remédiation et une source vérifiée.'),
                Toggle::make('for_simulation')
                    ->label('Éligible à la simulation'),
            ])
            ->visible(fn (Question $record) => auth()->user()->can('publish', $record))
            ->action(fn (Question $record, array $data) => $this->transition(
                fn () => app(QuestionTransitionService::class)->publish(
                    $record,
                    forDiagnostic: (bool) ($data['for_diagnostic'] ?? false),
                    forSimulation: (bool) ($data['for_simulation'] ?? false),
                ),
                'Publiée.'
            ));
    }

    private function retirer(): Action
    {
        return Action::make('retirer')
            ->label('Retirer')
            ->icon('heroicon-o-archive-box-x-mark')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Une question retirée n\'est plus servie. Les tentatives passées continuent de pointer vers elle.')
            ->visible(fn (Question $record) => auth()->user()->can('retire', $record))
            ->action(fn (Question $record) => $this->transition(
                fn () => app(QuestionTransitionService::class)->retire($record),
                'Retirée.'
            ));
    }

    /**
     * Le refus du service devient un message, jamais une page d'erreur.
     *
     * `publish()` rend la liste complète des motifs de blocage : c'est
     * exactement ce que le rédacteur doit lire, et le laisser tomber sur une
     * 500 lui ferait perdre l'information la plus utile de la chaîne.
     */
    private function transition(callable $acte, string $succes): void
    {
        try {
            $acte();
        } catch (RuntimeException $e) {
            Notification::make()
                ->danger()
                ->title('Transition refusée')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        $this->refreshFormData(['status']);

        Notification::make()->success()->title($succes)->send();
    }
}
