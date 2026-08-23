<?php

namespace App\Filament\Resources\DifficultyLevels;

use App\Filament\Resources\DifficultyLevels\Pages\EditDifficultyLevel;
use App\Filament\Resources\DifficultyLevels\Pages\ListDifficultyLevels;
use App\Models\DifficultyLevel;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * L'échelle de difficulté, éditable sans déploiement — Q-09.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * NI CRÉATION NI SUPPRESSION, ET C'EST LE POINT
 *
 * Cinq crans, fermés en code : une échelle dont le nombre de crans varie ne se
 * compare plus à elle-même d'une session à l'autre, et les difficultés déjà
 * posées perdraient leur sens. Le déclencheur `difficulty_levels_fixed_scale`
 * le tient en base ; cette ressource ne déclare simplement pas de page
 * « create », donc `ListeAvecCreation` n'affiche aucun bouton — la règle est
 * déduite, jamais recopiée.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUI S'ÉDITE EST CE QUI FAIT LE TRAVAIL
 *
 * Le libellé seul — « Transfert » — se lit différemment par chaque expert.
 * L'ANCRE dit ce qu'on observe chez le candidat, et c'est elle qu'on corrige
 * quand les difficultés déclarées dérivent. Une formulation approximative se
 * multiplie par 1 413 : la corriger ne doit pas demander un déploiement.
 */
class DifficultyLevelResource extends Resource
{
    /** LA PERMISSION QUI OUVRE CETTE SURFACE — D-13, voir `RefusNommeTest`. */
    public const PERMISSION_REQUISE = 'questions.validate';

    protected static ?string $model = DifficultyLevel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsVertical;

    protected static ?string $navigationLabel = 'Échelle de difficulté';

    protected static ?string $modelLabel = 'cran de difficulté';

    protected static ?string $pluralModelLabel = 'crans de difficulté';

    protected static string|\UnitEnum|null $navigationGroup = 'Référentiel';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('level')
                ->label('Cran')
                ->disabled()
                ->helperText('Fermé en code : les difficultés déjà posées s’y réfèrent.'),

            TextInput::make('label_fr')->label('Libellé (français)')->required()->maxLength(64),
            TextInput::make('label_ar')->label('Libellé (arabe)')->required()->maxLength(64),

            Textarea::make('anchor_fr')
                ->label('Ancre comportementale (français)')
                ->rows(3)
                ->required()
                ->helperText('Ce qu’on OBSERVE chez le candidat, jamais une définition abstraite. '
                    .'C’est cette phrase qui aligne deux experts sur le même cran.'),

            Textarea::make('anchor_ar')->label('Ancre comportementale (arabe)')->rows(3)->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('level')->label('Cran')->sortable(),
                TextColumn::make('label_fr')->label('Libellé'),
                TextColumn::make('anchor_fr')->label('Ancre')->wrap()->limit(120),
            ])
            ->defaultSort('level');
    }

    /** Pas de page « create » : l'échelle ne se rallonge pas. */
    public static function getPages(): array
    {
        return [
            'index' => ListDifficultyLevels::route('/'),
            'edit' => EditDifficultyLevel::route('/{record}/edit'),
        ];
    }
}
