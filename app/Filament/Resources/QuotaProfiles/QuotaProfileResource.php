<?php

namespace App\Filament\Resources\QuotaProfiles;

use App\Enums\QuotaPeriodicity;
use App\Enums\QuotaUnit;
use App\Filament\Resources\QuotaProfiles\Pages\CreateQuotaProfile;
use App\Filament\Resources\QuotaProfiles\Pages\EditQuotaProfile;
use App\Filament\Resources\QuotaProfiles\Pages\ListQuotaProfiles;
use App\Models\QuotaProfile;
use App\Services\QuotaProfileService;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Le registre des profils de quota — surface de l'admin PÉDAGOGIQUE.
 *
 * C'EST LE SEUL ÉCRAN DU PRODUIT OÙ UN NOMBRE DE QUESTIONS SE TAPE AU CLAVIER,
 * et c'est le point de la spécification : « L'admin pédagogique définit les
 * profils de quota et leurs bornes. L'admin commerciale sélectionne un profil
 * autorisé ; elle ne saisit aucun nombre. » L'écran des offres n'aura donc
 * jamais de champ numérique de quota — il aura une liste déroulante alimentée
 * par `QuotaProfileService::selectionnablesPour()`.
 *
 * L'ÉCRITURE EST DÉTOURNÉE VERS LE SERVICE. Un `QuotaProfile::create()` de
 * Filament écrirait une borne sans journal et sans exiger la justification
 * renouvelée — deux garanties que ce pas existe pour tenir. Les pages de
 * création et d'édition passent par `QuotaProfileService`.
 */
class QuotaProfileResource extends Resource
{
    /** LA PERMISSION QUI OUVRE CETTE SURFACE — D-13, voir `RefusNommeTest`. */
    public const PERMISSION_REQUISE = 'quotas.manage';

    protected static ?string $model = QuotaProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Profils de quota';

    protected static ?string $modelLabel = 'profil de quota';

    protected static ?string $pluralModelLabel = 'profils de quota';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Code')
                ->required()
                ->maxLength(64)
                ->helperText('Stable et lisible : une version d’offre le désignera.')
                /* Figé après création : une offre vendue le référence. */
                ->disabled(fn (?QuotaProfile $record) => $record !== null),

            TextInput::make('name_fr')->label('Nom (français)')->required(),
            TextInput::make('name_ar')->label('Nom (arabe)')->required(),

            Select::make('unit')
                ->label('Unité comptée')
                ->options(QuotaUnit::options())
                ->default(QuotaUnit::QUESTIONS->value)
                ->required()
                ->helperText('Fermée en code : une unité n’existe que si une capacité la consomme.')
                ->disabled(fn (?QuotaProfile $record) => $record !== null),

            Select::make('periodicity')
                ->label('Fenêtre')
                ->options(QuotaPeriodicity::options())
                ->default(QuotaPeriodicity::CUMULATIVE_GRANT->value)
                ->required()
                ->helperText('Règle de consommation, pas réglage : cumulatif sur la durée du droit (Q-07).')
                ->disabled(fn (?QuotaProfile $record) => $record !== null),

            TextInput::make('value')
                ->label('Valeur')
                ->integer()
                ->required()
                ->minValue(1)
                ->helperText('Ce que l’enveloppe vaudra à l’ouverture du droit.'),

            TextInput::make('min_value')
                ->label('Borne basse')
                ->integer()
                ->required()
                ->minValue(1),

            Textarea::make('min_justification')
                ->label('Pourquoi cette borne basse')
                ->rows(3)
                ->required()
                ->minLength(QuotaProfileService::JUSTIFICATION_MINIMALE)
                ->helperText(
                    'Le produit n’affiche un score de maîtrise qu’à partir de cinq réponses '
                    .'par nœud : sous cette limite, la carte reste vide. Écrivez ce que cette '
                    .'borne protège — la déplacer demandera une nouvelle raison.'
                ),

            TextInput::make('max_value')
                ->label('Borne haute')
                ->integer()
                ->required()
                ->minValue(1),

            Textarea::make('max_justification')
                ->label('Pourquoi cette borne haute')
                ->rows(3)
                ->required()
                ->minLength(QuotaProfileService::JUSTIFICATION_MINIMALE),

            Toggle::make('active')
                ->label('Proposé à la sélection')
                ->default(true)
                ->helperText('Retirer n’efface rien : les offres qui le référencent restent lisibles.'),

            TextInput::make('position')->label('Ordre d’affichage')->numeric()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Code')->searchable(),
                TextColumn::make('name_fr')->label('Nom')->searchable(),
                TextColumn::make('unit')
                    ->label('Unité')
                    ->formatStateUsing(fn (QuotaUnit $state): string => $state->label()),
                TextColumn::make('value')->label('Valeur'),
                TextColumn::make('bornes')
                    ->label('Bornes')
                    ->state(fn (QuotaProfile $record): string => $record->min_value.' – '.$record->max_value),
                TextColumn::make('periodicity')
                    ->label('Fenêtre')
                    ->formatStateUsing(fn (QuotaPeriodicity $state): string => $state->label()),
                IconColumn::make('active')->label('Proposé')->boolean(),
            ])
            ->defaultSort('position');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuotaProfiles::route('/'),
            'create' => CreateQuotaProfile::route('/create'),
            'edit' => EditQuotaProfile::route('/{record}/edit'),
        ];
    }
}
