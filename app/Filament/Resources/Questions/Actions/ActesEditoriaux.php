<?php

namespace App\Filament\Resources\Questions\Actions;

use App\Models\Question;
use App\Services\QuestionTransitionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use RuntimeException;

/**
 * Les cinq actes de la chaîne éditoriale, servis là où on peut les atteindre.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LA RÈGLE QUE CETTE CLASSE APPLIQUE
 *
 *   Une action n'est jamais hébergée sur une surface dont l'accès est gouverné
 *   par une AUTRE permission que la sienne.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI ELLE EXISTE — deux défauts, une seule cause
 *
 * Les cinq actes vivaient sur `EditQuestion`, et sur elle seule. L'accès à
 * cette page est gouverné par `QuestionPolicy::update()`, qui exige
 * `questions.create` et refuse tout statut gelé. Ni l'une ni l'autre de ces
 * conditions n'a de rapport avec relire, valider ou retirer. Deux conséquences,
 * mesurées en recette humaine le 17 août sur une instance qui tourne :
 *
 *   · LE RELECTEUR NE POUVAIT RIEN RELIRE. Le rôle `reviseur` porte
 *     `questions.review` et pas `questions.create` : la page qui héberge
 *     « Marquer relue » lui rendait 403. Il voyait la file, sans une seule
 *     action — ni de ligne, ni d'en-tête. Le rôle dont c'est le seul métier
 *     regardait le travail passer.
 *
 *   · UNE QUESTION PUBLIÉE NE POUVAIT PLUS ÊTRE RETIRÉE. `update()` refuse le
 *     statut `published`, à juste titre — le contenu est gelé — et emportait
 *     avec elle l'action `retirer`, qui est pourtant la seule transition que la
 *     table autorise depuis cet état.
 *
 * Le dépôt portait déjà le bon précédent sans qu'on l'ait généralisé :
 * `designerLeMiroir()` avait été déplacée dans le tableau POUR CETTE RAISON
 * EXACTE — « la page d'édition ne s'ouvre pas sur une question publiée ». Le
 * raisonnement était juste et n'avait été appliqué qu'à un acte sur six.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE CETTE CLASSE NE FAIT PAS
 *
 * Elle n'écrit rien et ne décide rien. Chaque acte appelle
 * `QuestionTransitionService`, qui refuse en transaction. `visible()` ne
 * protège personne : il évite qu'un bouton conçu pour refuser s'affiche. Les
 * deux ne se remplacent pas — retirer `visible()` ne casse aucune garantie,
 * retirer le service les casse toutes.
 */
final class ActesEditoriaux
{
    /**
     * Les cinq actes, dans l'ordre de la chaîne.
     *
     * @return array<int, Action>
     */
    public static function tous(): array
    {
        return [
            self::soumettre(),
            self::relire(),
            self::valider(),
            self::publier(),
            self::retirer(),
        ];
    }

    public static function soumettre(): Action
    {
        return Action::make('soumettre')
            ->label('Soumettre à la relecture')
            ->icon('heroicon-o-paper-airplane')
            ->visible(fn (Question $record) => $record->status === 'draft'
                && auth()->user()?->can('update', $record))
            ->action(fn (Question $record) => self::executer(
                fn () => app(QuestionTransitionService::class)->submitForReview($record),
                'Soumise à la relecture.'
            ));
    }

    public static function relire(): Action
    {
        return Action::make('relire')
            ->label('Marquer relue')
            ->icon('heroicon-o-eye')
            ->visible(fn (Question $record) => auth()->user()?->can('review', $record))
            ->action(fn (Question $record) => self::executer(
                fn () => app(QuestionTransitionService::class)->markReviewed($record, auth()->user()),
                'Relecture enregistrée.'
            ));
    }

    public static function valider(): Action
    {
        return Action::make('valider')
            ->label('Valider pédagogiquement')
            ->icon('heroicon-o-check-badge')
            ->requiresConfirmation()
            ->modalDescription(
                'La validation pédagogique engage le fond : elle déclare que cette question '
                .'mesure bien la compétence qu\'elle annonce. L\'identité du valideur est enregistrée.'
            )
            ->visible(fn (Question $record) => auth()->user()?->can('validate', $record))
            ->action(fn (Question $record) => self::executer(
                fn () => app(QuestionTransitionService::class)->validate($record, auth()->user()),
                'Validée pédagogiquement.'
            ));
    }

    public static function publier(): Action
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
            ->visible(fn (Question $record) => auth()->user()?->can('publish', $record))
            ->action(fn (Question $record, array $data) => self::executer(
                fn () => app(QuestionTransitionService::class)->publish(
                    $record,
                    forDiagnostic: (bool) ($data['for_diagnostic'] ?? false),
                    forSimulation: (bool) ($data['for_simulation'] ?? false),
                ),
                'Publiée.'
            ));
    }

    public static function retirer(): Action
    {
        return Action::make('retirer')
            ->label('Retirer')
            ->icon('heroicon-o-archive-box-x-mark')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Une question retirée n\'est plus servie. Les tentatives passées continuent de pointer vers elle.')
            ->visible(fn (Question $record) => auth()->user()?->can('retire', $record))
            ->action(fn (Question $record) => self::executer(
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
    private static function executer(callable $acte, string $succes): void
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

        Notification::make()->success()->title($succes)->send();
    }
}
