<?php

namespace App\Filament\Resources\ExamFamilies;

use App\Filament\Resources\ExamFamilies\Pages\CreateExamFamily;
use App\Filament\Resources\ExamFamilies\Pages\EditExamFamily;
use App\Filament\Resources\ExamFamilies\Pages\ListExamFamilies;
use App\Models\ExamFamily;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Les familles de concours — et l'interrupteur qui décide de tout.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI CET ÉCRAN EXISTE
 *
 * Le catalogue ne se modifiait que par migration. Onze épreuves du lycée sont
 * posées en `draft`, leurs trois familles en `waitlist` : personne dans le
 * produit ne pouvait les ouvrir, il fallait écrire du code et déployer. C'est
 * DET-102, et c'est une demande explicite du propriétaire — « la liste des
 * concours doit être paramétrable par l'expert pédagogue ou super admin ».
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * `availability` EST LE VRAI INTERRUPTEUR, ET IL N'EST PAS OÙ ON LE CHERCHE
 *
 * Le catalogue public ne sert que les familles `open` : c'est cette colonne,
 * portée par la FAMILLE et non par l'épreuve, qui décide si un candidat voit
 * une épreuve dans sa liste déroulante. Une épreuve publiée sous une famille
 * en liste d'attente reste invisible — et c'est exactement l'état du lycée.
 *
 * Ouvrir une famille dont les arbres sont vides ne mène nulle part : le
 * diagnostic n'aurait rien à composer. L'écran le dit à l'endroit du geste,
 * plutôt que de laisser découvrir le vide après le clic.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE SLUG EST FIGÉ APRÈS CRÉATION
 *
 * Il est l'adresse publique de la fiche — `/fr/concours/{slug}`. Le changer
 * casse tout lien déjà partagé et toute page déjà indexée, sans qu'aucune
 * erreur ne le signale : la page rend simplement 404.
 */
class ExamFamilyResource extends Resource
{
    /** LA PERMISSION QUI OUVRE CETTE SURFACE — D-13, voir `RefusNommeTest`. */
    public const PERMISSION_REQUISE = 'catalogue.view';

    protected static ?string $model = ExamFamily::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Familles de concours';

    protected static ?string $modelLabel = 'famille';

    protected static ?string $pluralModelLabel = 'familles de concours';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('filiere_id')
                ->label('Filière')
                ->relationship('filiere', 'name_fr')
                ->required(),

            Select::make('audience_id')
                ->label('Catégorie de public')
                ->relationship('audience', 'name_fr')
                ->helperText('À qui cette famille s’adresse. C’est elle que les offres visent.'),

            TextInput::make('slug')
                ->label('Adresse publique')
                ->required()
                ->maxLength(120)
                ->helperText('Minuscules et tirets. Figée après création : c’est l’adresse de la fiche publique, et la changer casse les liens déjà partagés.')
                ->disabled(fn (?ExamFamily $record) => $record !== null),

            TextInput::make('name_fr')->label('Nom (français)')->required(),
            TextInput::make('name_ar')->label('Nom (arabe)')->required(),

            TextInput::make('authority_fr')->label('Organisme (français)'),
            TextInput::make('authority_ar')->label('Organisme (arabe)'),

            Textarea::make('description_fr')->label('Présentation (français)')->rows(3),
            Textarea::make('description_ar')->label('Présentation (arabe)')->rows(3),

            Select::make('status')
                ->label('État éditorial')
                ->options(['draft' => 'Brouillon', 'published' => 'Publiée'])
                ->default('draft')
                ->required()
                ->helperText('Une famille en brouillon n’apparaît nulle part, même ouverte.'),

            Select::make('availability')
                ->label('Disponibilité')
                ->options(['waitlist' => 'Liste d’attente', 'open' => 'Ouverte'])
                ->default('waitlist')
                ->required()
                ->helperText('« Ouverte » la rend sélectionnable par les candidats. Ne l’ouvrez qu’une fois ses arbres remplis et ses questions publiées : sans elles, le diagnostic n’a rien à composer.'),

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
                TextColumn::make('name_fr')->label('Famille')->searchable()->wrap(),
                TextColumn::make('filiere.name_fr')->label('Filière')->searchable(),
                TextColumn::make('slug')->label('Adresse')->searchable()->toggleable(),
                TextColumn::make('status')->label('État')->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'published' ? 'Publiée' : 'Brouillon')
                    ->color(fn (string $state) => $state === 'published' ? 'success' : 'gray'),
                TextColumn::make('availability')->label('Disponibilité')->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'open' ? 'Ouverte' : 'Liste d’attente')
                    ->color(fn (string $state) => $state === 'open' ? 'success' : 'warning'),
                /* Le compte d'épreuves dit si l'ouverture aurait un sens. Une
                   famille ouverte sans épreuve est une porte sur un mur. */
                TextColumn::make('exams_count')->label('Épreuves')->counts('exams'),
            ])
            ->filters([
                SelectFilter::make('availability')->label('Disponibilité')
                    ->options(['open' => 'Ouverte', 'waitlist' => 'Liste d’attente']),
                SelectFilter::make('status')->label('État')
                    ->options(['published' => 'Publiée', 'draft' => 'Brouillon']),
            ])
            ->defaultSort('position');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExamFamilies::route('/'),
            'create' => CreateExamFamily::route('/create'),
            'edit' => EditExamFamily::route('/{record}/edit'),
        ];
    }
}
