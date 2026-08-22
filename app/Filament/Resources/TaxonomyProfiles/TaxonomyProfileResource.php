<?php

namespace App\Filament\Resources\TaxonomyProfiles;

use App\Filament\Resources\TaxonomyProfiles\Pages\CreateTaxonomyProfile;
use App\Filament\Resources\TaxonomyProfiles\Pages\EditTaxonomyProfile;
use App\Filament\Resources\TaxonomyProfiles\Pages\ListTaxonomyProfiles;
use App\Models\Exam;
use App\Models\TaxonomyProfile;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Les profils de taxonomie — lot TAXO, pas 1.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * NOMMER LES NIVEAUX N'EST PAS DE LA DÉCORATION
 *
 * Un arbre dont les niveaux n'ont pas de nom produit des écrans candidats qui
 * disent « niveau 2 ». Le profil est donc refusé sans nom de niveau — et sans
 * nom ARABE aussi : le produit est bilingue, et une moitié manquante se
 * découvre le jour où un arabophone ouvre sa carte de maîtrise.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * UN PROFIL PAR ÉPREUVE, JAMAIS PAR FAMILLE — ADR-0014
 *
 * Deux épreuves d'une même famille nomment leurs niveaux différemment : la
 * didactique dit « Bloc », les sciences de l'éducation disent « Domaine ». Les
 * rattacher à la famille aurait forcé à fusionner trois matrices en une, et
 * c'est la faute que l'ADR-0014 a corrigée au PAS-4.1. L'écran ne la refait
 * pas : il ne propose que des ÉPREUVES.
 */
class TaxonomyProfileResource extends Resource
{
    /** LA PERMISSION QUI OUVRE CETTE SURFACE — D-13, voir `RefusNommeTest`. */
    public const PERMISSION_REQUISE = 'taxonomy.manage';

    protected static ?string $model = TaxonomyProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Profils de taxonomie';

    protected static ?string $modelLabel = 'profil de taxonomie';

    protected static ?string $pluralModelLabel = 'profils de taxonomie';

    protected static string|\UnitEnum|null $navigationGroup = 'Référentiel';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('exam_id')
                ->label('Épreuve')
                ->options(fn (): array => Exam::query()->orderBy('code')->pluck('code', 'id')->all())
                ->required()
                ->searchable()
                ->helperText('Chaque épreuve nomme ses propres niveaux (ADR-0014).'),

            /*
             * LES NIVEAUX, DANS L'ORDRE. Un répéteur et non des champs
             * numérotés : la profondeur est LIBRE (ADR-0012), et poser six
             * paires de champs en dur redirait en formulaire ce que le modèle
             * a justement rendu variable.
             */
            Repeater::make('levels')
                ->label('Niveaux, du plus large au plus fin')
                ->schema([
                    TextInput::make('name_fr')->label('Nom (français)')->required()->maxLength(64),
                    TextInput::make('name_ar')->label('Nom (arabe)')->required()->maxLength(64),
                ])
                ->required()
                ->minItems(1)
                ->maxItems(TaxonomyProfile::MAX_DEPTH)
                ->reorderable()
                ->helperText('« Domaine », « Sous-domaine », « Chapitre »… Ce sont ces mots que le '
                    .'candidat lit sur sa carte de maîtrise. Sans eux, elle dit « niveau 2 ».'),

            TextInput::make('min_depth_for_publication')
                ->label('Profondeur minimale de publication')
                ->numeric()
                ->required()
                ->minValue(0)
                ->maxValue(TaxonomyProfile::MAX_DEPTH - 1)
                ->helperText('En dessous, une question ne se publie pas : elle serait rattachée '
                    .'trop haut pour que la carte dise quoi que ce soit d’utile.'),

            Textarea::make('source_note_fr')
                ->label('D’où vient cette découpe (français)')
                ->rows(2)
                ->helperText('Le descriptif, la page, la date. Une découpe sans origine se relit mal.'),

            Textarea::make('source_note_ar')->label('D’où vient cette découpe (arabe)')->rows(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('exam.code')->label('Épreuve')->searchable(),
                /* `state()` et non `formatStateUsing()` : `levels` est un tableau,
                 * et Filament formate alors CHAQUE élément séparément — le
                 * rendu recevait une chaîne là où il attendait une paire. On
                 * compose donc la ligne entière une fois. */
                TextColumn::make('levels')
                    ->label('Niveaux')
                    ->state(fn (TaxonomyProfile $record): string => implode(' › ', array_map(
                        fn (array $n): string => $n['name_fr'] ?? '—', $record->levels ?? [],
                    )))
                    ->wrap(),
                TextColumn::make('min_depth_for_publication')->label('Publication dès la profondeur'),
                TextColumn::make('source_note_fr')->label('Origine')->limit(60)->placeholder('—'),
            ])
            ->defaultSort('exam_id');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTaxonomyProfiles::route('/'),
            'create' => CreateTaxonomyProfile::route('/create'),
            'edit' => EditTaxonomyProfile::route('/{record}/edit'),
        ];
    }
}
