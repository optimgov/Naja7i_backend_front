<?php

namespace App\Filament\Resources\Sources\Tables;

use App\Models\Source;
use App\Services\SourceVerificationService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Le registre documentaire, et l'acte qui le qualifie.
 *
 * LE BOUTON « VÉRIFIER » N'ÉCRIT RIEN LUI-MÊME. Il appelle
 * `SourceVerificationService`, qui enregistre QUI et QUAND puis propage l'état
 * aux citations encore modifiables. Ce détour n'est pas une politesse
 * d'architecture : `verified_at` et `verified_by` sont hors de `$fillable`, et
 * Filament ne peut donc pas les écrire même s'il essayait. La garantie est
 * structurelle, pas conventionnelle.
 *
 * L'ORDRE PAR DÉFAUT EST CELUI DU TRAVAIL À FAIRE : les sources non vérifiées
 * d'abord, les plus citées en tête. Une source citée par trente questions et
 * jamais contrôlée bloque trente questions à l'entrée du diagnostic ; une
 * source orpheline n'en bloque aucune.
 */
class SourcesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('kind')
                    ->label('Nature')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'descriptif_officiel' => 'Descriptif officiel',
                        'texte_reglementaire' => 'Texte réglementaire',
                        'ouvrage' => 'Ouvrage',
                        'annale' => 'Annale',
                        default => 'Autre',
                    })
                    ->color(fn (string $state) => in_array($state, ['descriptif_officiel', 'texte_reglementaire'], true)
                        ? 'primary'
                        : 'gray'),

                TextColumn::make('title_fr')
                    ->label('Titre')
                    ->wrap()
                    ->limit(60)
                    ->searchable(),

                /* QUI ET QUAND, dans la liste et pas seulement sur la fiche :
                 * c'est l'information qu'un relecteur cherche en arrivant, et
                 * l'obliger à ouvrir chaque source pour l'obtenir la rendrait
                 * inutilisable. */
                TextColumn::make('verified_at')
                    ->label('Vérification')
                    ->badge()
                    ->state(fn (Source $record) => $record->estVerifiee() ? 'Vérifiée' : 'Non vérifiée')
                    ->color(fn (Source $record) => $record->estVerifiee() ? 'success' : 'warning')
                    ->description(fn (Source $record) => $record->estVerifiee()
                        ? ($record->verificateur?->email ?? 'compte supprimé')
                          .' — '.$record->verified_at->translatedFormat('j M Y')
                        : null)
                    ->sortable(),

                TextColumn::make('questions_count')
                    ->label('Citée par')
                    ->counts('questions')
                    ->suffix(' question(s)')
                    ->sortable(),
            ])
            /* Les non vérifiées en tête, écrit explicitement plutôt que confié à
             * la place des NULL dans un ORDER BY : sous PostgreSQL elle dépend
             * du sens du tri, et un tri qui change de sens en changeant de
             * direction est un piège pour qui relira. */
            ->defaultSort(fn (Builder $query) => $query
                ->orderByRaw('verified_at is null desc')
                ->orderBy('code'))
            ->filters([
                TernaryFilter::make('verified_at')
                    ->label('Contrôle documentaire')
                    ->placeholder('Toutes')
                    ->trueLabel('Vérifiées')
                    ->falseLabel('Non vérifiées')
                    ->nullable(),

                SelectFilter::make('kind')
                    ->label('Nature')
                    ->options([
                        'descriptif_officiel' => 'Descriptif officiel',
                        'texte_reglementaire' => 'Texte réglementaire',
                        'ouvrage' => 'Ouvrage',
                        'annale' => 'Annale',
                        'autre' => 'Autre',
                    ]),
            ])
            ->recordActions([
                self::verifier(),
                EditAction::make()->label('Modifier'),
            ])
            /* Ni suppression ni action de masse. Une source citée ne s'efface
             * pas — `restrictOnDelete` l'impose en base — et vérifier trente
             * sources d'un clic serait un contrôle documentaire qui n'en est
             * pas un : l'acte engage celui qui le signe. */
            ->toolbarActions([]);
    }

    /**
     * L'acte de contrôle — DET-46.
     *
     * Il se confirme, et la confirmation NOMME ce qu'il engage : la vérification
     * porte le nom du relecteur, et rend éligibles au diagnostic les questions
     * qui citent la source. Un clic qui engage une signature ne se donne pas
     * sans une phrase.
     */
    private static function verifier(): Action
    {
        return Action::make('verifier')
            ->label('Vérifier')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Confirmer le contrôle documentaire')
            ->modalDescription(
                'La vérification sera enregistrée à votre nom et à cette date. Les '
                .'citations des questions non encore publiées passeront à « vérifiée » ; '
                .'celles des questions publiées restent gelées et demandent une nouvelle version.'
            )
            ->visible(fn (Source $record) => ! $record->estVerifiee()
                && auth()->user()->can('verify', $record))
            ->action(function (Source $record) {
                $resultat = app(SourceVerificationService::class)->verifier($record, auth()->user());

                Notification::make()
                    ->success()
                    ->title('Source vérifiée')
                    ->body($resultat['citations_mises_a_jour'].' citation(s) mise(s) à jour.')
                    ->send();
            });
    }
}
