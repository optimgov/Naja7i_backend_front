<?php

namespace App\Filament\Resources\Filieres;

use App\Filament\Resources\Filieres\Pages\CreateFiliere;
use App\Filament\Resources\Filieres\Pages\EditFiliere;
use App\Filament\Resources\Filieres\Pages\ListFilieres;
use App\Models\Filiere;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Les filières — le premier des trois verrous, et le plus facile à oublier.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * TROIS VERROUS, ET IL FAUT LES TROIS
 *
 * Le catalogue public ne sert une épreuve que si TOUT est ouvert au-dessus
 * d'elle : la filière publiée, la famille publiée ET ouverte, l'épreuve
 * publiée. Trois interrupteurs sur trois écrans, et rien ne le disait.
 *
 * Ce n'est pas une conjecture : le test d'acceptation du lot a d'abord échoué
 * ici. On avait livré les écrans de la famille et de l'épreuve en croyant que
 * l'expert pourrait ouvrir le lycée — la filière `lycee` est en brouillon, et
 * elle seule bloquait tout. Un écran manquant ne se voit pas : la colonne
 * s'enregistre, et rien n'apparaît.
 *
 * Chaque écran de la chaîne annonce donc le verrou suivant, plutôt que de
 * laisser découvrir l'absence d'effet après coup.
 */
class FiliereResource extends Resource
{
    /** LA PERMISSION QUI OUVRE CETTE SURFACE — D-13, voir `RefusNommeTest`. */
    public const PERMISSION_REQUISE = 'catalogue.view';

    protected static ?string $model = Filiere::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static ?string $navigationLabel = 'Filières';

    protected static ?string $modelLabel = 'filière';

    protected static ?string $pluralModelLabel = 'filières';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('slug')
                ->label('Adresse publique')
                ->required()
                ->maxLength(120)
                ->helperText('Minuscules et tirets. Figée après création : c’est l’adresse de la page publique.')
                ->disabled(fn (?Filiere $record) => $record !== null),

            TextInput::make('name_fr')->label('Nom (français)')->required(),
            TextInput::make('name_ar')->label('Nom (arabe)')->required(),

            TextInput::make('tagline_fr')->label('Accroche (français)'),
            TextInput::make('tagline_ar')->label('Accroche (arabe)'),

            Select::make('status')
                ->label('État éditorial')
                ->options(['draft' => 'Brouillon', 'published' => 'Publiée'])
                ->default('draft')
                ->required()
                ->helperText('Une filière en brouillon masque TOUT ce qu’elle contient, familles et épreuves comprises, quels que soient leurs propres réglages.'),

            /*
             * PUBLIER SANS DATE NE PUBLIE RIEN, et c'est le piège que ce champ
             * ferme. `scopePublished` exige TROIS choses : le statut, une date
             * de publication non nulle, et cette date déjà passée. Un écran
             * qui n'aurait montré que le statut aurait laissé publier dans le
             * vide — la ligne s'enregistre, et le catalogue reste muet.
             */
            DateTimePicker::make('published_at')
                ->label('Visible à partir du')
                ->seconds(false)
                ->default(fn () => now())
                ->helperText('Obligatoire pour publier. Une date future programme la parution ; tant qu’elle n’est pas atteinte, rien n’apparaît.')
                ->required(fn (Get $etat) => $etat('status') === 'published'),

            TextInput::make('position')->label('Ordre d’affichage')->numeric()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name_fr')->label('Filière')->searchable(),
                TextColumn::make('slug')->label('Adresse')->searchable()->toggleable(),
                TextColumn::make('status')->label('État')->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'published' ? 'Publiée' : 'Brouillon')
                    ->color(fn (string $state) => $state === 'published' ? 'success' : 'gray'),
                TextColumn::make('exam_families_count')->counts('families')->label('Familles'),
            ])
            ->defaultSort('position');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFilieres::route('/'),
            'create' => CreateFiliere::route('/create'),
            'edit' => EditFiliere::route('/{record}/edit'),
        ];
    }
}
