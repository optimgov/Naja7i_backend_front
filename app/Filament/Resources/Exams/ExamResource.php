<?php

namespace App\Filament\Resources\Exams;

use App\Filament\Resources\Exams\Pages\CreateExam;
use App\Filament\Resources\Exams\Pages\EditExam;
use App\Filament\Resources\Exams\Pages\ListExams;
use App\Models\Exam;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Les épreuves — et les matières du lycée, qui sont le même objet.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE CET ÉCRAN FERME
 *
 * Le catalogue ne se modifiait que par migration : DET-102. Une épreuve ne se
 * créait pas, ne se renommait pas, ne changeait pas de statut sans un
 * développeur et un déploiement. C'est pourtant l'objet que l'expert
 * pédagogue gouverne — chaque arbre, chaque question et chaque diagnostic s'y
 * rattache.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * PUBLIER UNE ÉPREUVE NE LA REND PAS VISIBLE
 *
 * C'est la confusion que cet écran doit empêcher. Le catalogue public ne sert
 * que les familles `open` : une épreuve publiée sous une famille en liste
 * d'attente reste invisible pour un candidat. Les onze épreuves du lycée sont
 * dans cet état. La colonne « Servie ? » dit donc la vérité composée, pas le
 * statut seul — sans quoi on publierait onze épreuves en croyant les ouvrir.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE CODE EST FIGÉ APRÈS CRÉATION
 *
 * `exams.code` est l'identité de l'épreuve dans tout le produit : l'URL du
 * diagnostic, celle de l'entraînement, la clé du profil candidat, les
 * références des questions. Le changer ne casse rien visiblement — cela rend
 * simplement introuvables les tentatives déjà passées.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE NOMBRE D'OPTIONS A UN PLANCHER EN BASE
 *
 * `exams_options_count_plancher` refuse toute valeur inférieure à quatre. Un
 * QCM à trois propositions se devine à une chance sur trois, et fausserait la
 * mesure de maîtrise autant que le diagnostic. Le champ le dit avant la
 * saisie plutôt que de laisser la base répondre par une erreur SQL.
 */
class ExamResource extends Resource
{
    /** LA PERMISSION QUI OUVRE CETTE SURFACE — D-13, voir `RefusNommeTest`. */
    public const PERMISSION_REQUISE = 'catalogue.view';

    protected static ?string $model = Exam::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $navigationLabel = 'Épreuves et matières';

    protected static ?string $modelLabel = 'épreuve';

    protected static ?string $pluralModelLabel = 'épreuves et matières';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('track_id')
                ->label('Parcours')
                ->relationship('track', 'name_fr')
                ->searchable()
                ->preload()
                ->required()
                ->helperText('Le parcours porte la famille : c’est par lui que l’épreuve hérite de sa disponibilité.'),

            Select::make('specialty_id')
                ->label('Spécialité ou matière')
                ->relationship('specialty', 'name_fr')
                ->searchable()
                ->preload(),

            TextInput::make('code')
                ->label('Code')
                ->required()
                ->maxLength(64)
                ->helperText('Majuscules et tirets : CRMEF-FR-SPEC-2025. Figé après création — les tentatives déjà passées le désignent.')
                ->disabled(fn (?Exam $record) => $record !== null),

            TextInput::make('name_fr')->label('Nom (français)')->required(),
            TextInput::make('name_ar')->label('Nom (arabe)')->required(),

            TextInput::make('coefficient')
                ->label('Coefficient')
                ->numeric()
                ->helperText('Laissez vide si aucune pièce officielle ne le donne. Un coefficient inventé pèse sur la composition du diagnostic.'),

            TextInput::make('duration_minutes')
                ->label('Durée officielle (minutes)')
                ->numeric()
                ->helperText('Exigée pour ouvrir un examen blanc : c’est elle que le serveur fait respecter.'),

            TextInput::make('options_count')
                ->label('Nombre d’options par question')
                ->numeric()
                ->minValue(4)
                ->helperText('Quatre au minimum, et la base le refuse en deçà. Vide : le plancher de quatre s’applique sans nombre exact exigé.'),

            TextInput::make('first_question_number')->label('Première question numérotée')->numeric(),

            Select::make('status')
                ->label('État éditorial')
                ->options(['draft' => 'Brouillon', 'published' => 'Publiée'])
                ->default('draft')
                ->required(),

            Select::make('provenance')
                ->label('Provenance des informations')
                ->options(['unverified' => 'Non vérifiée', 'official' => 'Officielle'])
                ->default('unverified')
                ->required()
                ->helperText('« Officielle » atteste qu’une pièce a été consultée. Ne le cochez pas par défaut.'),

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
                TextColumn::make('code')->label('Code')->searchable()->sortable(),
                TextColumn::make('name_fr')->label('Nom')->searchable()->wrap(),
                TextColumn::make('track.family.name_fr')->label('Famille')->searchable()->toggleable(),
                TextColumn::make('status')->label('État')->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'published' ? 'Publiée' : 'Brouillon')
                    ->color(fn (string $state) => $state === 'published' ? 'success' : 'gray'),

                /*
                 * LA COLONNE QUI DIT LA VÉRITÉ COMPOSÉE. Publier une épreuve
                 * sous une famille en liste d'attente ne la rend visible pour
                 * personne : deux colonnes séparées laisseraient conclure
                 * l'inverse au premier coup d'œil.
                 */
                TextColumn::make('servie')->label('Servie au candidat ?')->badge()
                    ->state(fn (Exam $record) => $record->status === 'published'
                        && $record->track?->family?->availability === 'open'
                            ? 'oui'
                            : 'non')
                    ->color(fn (string $state) => $state === 'oui' ? 'success' : 'warning')
                    ->tooltip(fn (Exam $record) => $record->status !== 'published'
                        ? 'L’épreuve est en brouillon.'
                        : ($record->track?->family?->availability === 'open'
                            ? 'Publiée, et sa famille est ouverte.'
                            : 'Publiée, mais sa famille est en liste d’attente : aucun candidat ne la voit.')),

                TextColumn::make('coefficient')->label('Coef.')->toggleable(),
                TextColumn::make('duration_minutes')->label('Durée')->suffix(' min')->toggleable(),

                /* Sans arbre, aucune question ne peut s'y rattacher ; sans
                   question publiée, aucun diagnostic ne peut s'ouvrir. Les deux
                   comptes disent d'un coup d'œil si l'épreuve est utilisable. */
                TextColumn::make('competency_nodes_count')->counts('competencyNodes')->label('Nœuds'),
                TextColumn::make('questions_count')->counts('questions')->label('Questions'),
            ])
            ->filters([
                SelectFilter::make('status')->label('État')
                    ->options(['published' => 'Publiée', 'draft' => 'Brouillon']),
            ])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExams::route('/'),
            'create' => CreateExam::route('/create'),
            'edit' => EditExam::route('/{record}/edit'),
        ];
    }
}
