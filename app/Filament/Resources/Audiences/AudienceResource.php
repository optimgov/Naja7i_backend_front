<?php

namespace App\Filament\Resources\Audiences;

use App\Filament\Resources\Audiences\Pages\CreateAudience;
use App\Filament\Resources\Audiences\Pages\EditAudience;
use App\Filament\Resources\Audiences\Pages\ListAudiences;
use App\Models\Audience;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Les catégories de public, créées sans développeur.
 *
 * C'est le premier des trois gestes du test d'acceptation n°1 : « l'admin
 * commerciale crée une catégorie, un pack, sa version 1, et le met en vente —
 * sans intervention d'un développeur, sans migration ».
 *
 * LE LIBELLÉ EST BILINGUE ET OBLIGATOIRE. Une catégorie sans arabe finirait
 * rendue en français dans une interface RTL, ou pire, sous son code. Aucun code
 * d'énumération brut n'atteint un écran candidat — c'est la règle du produit,
 * pas une préférence d'affichage.
 */
class AudienceResource extends Resource
{
    /** LA PERMISSION QUI OUVRE CETTE SURFACE — D-13, voir `RefusNommeTest`. */
    public const PERMISSION_REQUISE = 'orders.view';

    protected static ?string $model = Audience::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Catégories de public';

    protected static ?string $modelLabel = 'catégorie de public';

    protected static ?string $pluralModelLabel = 'catégories de public';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Code')
                ->required()
                ->maxLength(32)
                ->helperText('Minuscules, sans espace : lycee, grandes-ecoles. Figé après création — des droits le désignent.')
                ->disabled(fn (?Audience $record) => $record !== null),

            TextInput::make('name_fr')->label('Libellé (français)')->required(),
            TextInput::make('name_ar')->label('Libellé (arabe)')->required(),

            Toggle::make('active')
                ->label('Proposée à la sélection')
                ->default(true)
                ->helperText('Retirer n’efface rien : les versions qui la désignent restent lisibles et honorables.'),

            TextInput::make('position')->label('Ordre d’affichage')->numeric()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Code')->searchable(),
                TextColumn::make('name_fr')->label('Libellé')->searchable(),
                TextColumn::make('name_ar')->label('Libellé (arabe)'),
                TextColumn::make('exam_families_count')->counts('examFamilies')->label('Familles rattachées'),
                TextColumn::make('plans_count')->counts('plans')->label('Offres'),
                IconColumn::make('active')->label('Proposée')->boolean(),
            ])
            ->defaultSort('position');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAudiences::route('/'),
            'create' => CreateAudience::route('/create'),
            'edit' => EditAudience::route('/{record}/edit'),
        ];
    }
}
