<?php

namespace App\Filament\Resources\Questions\Tables;

use App\Models\CompetencyNode;
use App\Models\Question;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * La banque, telle qu'un rédacteur la parcourt.
 *
 * Les filtres reprennent ceux de `GET admin/questions` (PAS-27) : statut,
 * compétence, langue, auteur. Le filtre par compétence porte sur le CODE, qui
 * est ce que le plan de rédaction désigne — le rédacteur passe de l'un à
 * l'autre sans traduction.
 */
class QuestionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('stem')
                    ->label('Énoncé')
                    ->wrap()
                    ->limit(80)
                    ->searchable(),

                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'draft' => 'gray',
                        'a_verifier' => 'warning',
                        'reviewed' => 'info',
                        'pedagogically_validated' => 'primary',
                        'published' => 'success',
                        'retired' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'draft' => 'Brouillon',
                        'a_verifier' => 'À relire',
                        'reviewed' => 'Relue',
                        'pedagogically_validated' => 'Validée',
                        'published' => 'Publiée',
                        'retired' => 'Retirée',
                        default => $state,
                    }),

                TextColumn::make('node.code')->label('Compétence')->sortable(),
                TextColumn::make('locale')->label('Langue')->badge(),

                /* Le drapeau qui décide de la valeur d'une question : servir au
                 * diagnostic exige la cause de chaque distracteur, une
                 * remédiation et une source vérifiée. */
                TextColumn::make('eligible_for_diagnostic')
                    ->label('Diagnostic')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'oui' : 'non')
                    ->color(fn ($state) => $state ? 'success' : 'gray'),

                TextColumn::make('updated_at')->label('Modifiée')->since()->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'draft' => 'Brouillon',
                        'a_verifier' => 'À relire',
                        'reviewed' => 'Relue',
                        'pedagogically_validated' => 'Validée',
                        'published' => 'Publiée',
                        'retired' => 'Retirée',
                    ]),

                SelectFilter::make('locale')
                    ->label('Langue')
                    ->options(['fr' => 'Français', 'ar' => 'العربية']),

                SelectFilter::make('competency_node_id')
                    ->label('Compétence')
                    ->options(fn () => CompetencyNode::where('depth', '>', 0)
                        ->orderBy('code')->pluck('code', 'id'))
                    ->searchable(),

                SelectFilter::make('author_id')
                    ->label('Auteur')
                    ->relationship('author', 'email'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            /* Aucune action de masse, et surtout aucune suppression : une
             * question ne s'efface pas, elle se retire — `restrictOnDelete` sur
             * `attempt_items.question_id` l'impose déjà en base. */
            ->toolbarActions([]);
    }
}
