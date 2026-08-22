<?php

namespace App\Filament\Resources\Plans\RelationManagers;

use App\Models\PlanVersion;
use App\Services\PlanVersionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

/**
 * L'historique des versions et ce qui en dépend — spécification §2.6.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI CETTE LECTURE EXISTE
 *
 * « C'est cette lecture qui rend le §3 compréhensible plutôt que frustrant. »
 * Sans elle, l'admin commerciale constate qu'elle ne peut pas modifier une
 * version et n'a aucun moyen de savoir POURQUOI — combien de commandes la
 * référencent, combien de droits en découlent, combien courent encore. Un refus
 * qu'on ne peut pas expliquer se lit comme une panne.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE BOUTON DE CORRECTION N'EST PAS UN CHAMP MODIFIABLE
 *
 * La seule écriture possible sur une version est le canal éditorial livré au
 * préalable P-E : une action nommée, un champ textuel choisi dans une liste,
 * un motif obligatoire, et un journal écrit dans la même transaction que le
 * texte. Elle n'apparaît que pour qui porte `plans.editorial_fix` — et si elle
 * apparaissait à tort, la fonction SQL refuserait quand même.
 */
class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Versions';

    protected static ?string $modelLabel = 'version';

    protected static ?string $pluralModelLabel = 'versions';

    /** Une version ne se crée pas à la main : elle est la conséquence d'une composition. */
    public function canCreate(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('version')
            ->columns([
                TextColumn::make('version')->label('N°')->sortable(),
                TextColumn::make('created_at')->label('Date d’effet')->dateTime('d/m/Y H:i'),
                TextColumn::make('composedBy.email')
                    ->label('Auteur')
                    ->placeholder('aucun humain n’a signé'),
                TextColumn::make('triggered_by')
                    ->label('Déclenchée par')
                    ->formatStateUsing(fn (PlanVersion $record): string => self::declencheur($record)),
                TextColumn::make('price_cents')
                    ->label('Prix')
                    ->formatStateUsing(fn ($state, PlanVersion $r) => number_format($state / 100, 2, ',', ' ').' '.$r->currency),
                TextColumn::make('commandes')
                    ->label('Commandes')
                    ->state(fn (PlanVersion $record): int => $record->orders()->count()),
                TextColumn::make('droits')
                    ->label('Droits')
                    ->state(fn (PlanVersion $record): int => $record->droitsIssus()->count()),
                TextColumn::make('droits_actifs')
                    ->label('Dont actifs')
                    ->state(fn (PlanVersion $record): int => $record->droitsIssus()->active()->count()),
            ])
            ->defaultSort('version', 'desc')
            ->recordActions([$this->correctionEditoriale()])
            ->headerActions([])
            ->toolbarActions([]);
    }

    /** « Déclenchée par » lisible : la création n'a pas de champ déclencheur. */
    private static function declencheur(PlanVersion $version): string
    {
        if ($version->reconstructed) {
            return 'reconstruite au versionnement';
        }

        $champs = $version->triggered_by ?? [];

        return $champs === [] ? 'création de l’offre' : implode(', ', $champs);
    }

    private function correctionEditoriale(): Action
    {
        return Action::make('corrigerLaCoquille')
            ->label('Corriger une coquille')
            ->modalHeading('Corriger une coquille sans créer de version')
            ->modalDescription(
                'Réservé aux fautes de langue. Le sens ne change pas, et le journal le rend '
                .'vérifiable : auteur, avant, après, motif.'
            )
            ->visible(fn (PlanVersion $record): bool => auth()->user()?->can('editorialFix', $record) ?? false)
            ->schema([
                Select::make('champ')
                    ->label('Champ à corriger')
                    ->options([
                        'name_fr' => 'Nom (français)',
                        'name_ar' => 'Nom (arabe)',
                        'description_fr' => 'Description (français)',
                        'description_ar' => 'Description (arabe)',
                    ])
                    ->required(),
                Textarea::make('texte')->label('Texte corrigé')->rows(3)->required(),
                Textarea::make('motif')
                    ->label('Motif')
                    ->rows(2)
                    ->required()
                    ->minLength(10)
                    ->helperText('Ce qui est corrigé, en une phrase. Sans motif, la correction est refusée.'),
            ])
            ->action(function (PlanVersion $record, array $data): void {
                try {
                    app(PlanVersionService::class)->corrigerLeTexte(
                        $record,
                        $data['champ'],
                        $data['texte'],
                        auth()->user(),
                        $data['motif'],
                    );
                } catch (ValidationException $exception) {
                    /* Le refus vient de la base ou de l'autorisation : on le
                     * rend tel quel plutôt que de le traduire en « erreur ». */
                    throw $exception;
                }
            });
    }
}
