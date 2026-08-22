<?php

namespace App\Filament\Resources\CapabilityDefinitions;

use App\Filament\Resources\CapabilityDefinitions\Pages\EditCapabilityDefinition;
use App\Filament\Resources\CapabilityDefinitions\Pages\ListCapabilityDefinitions;
use App\Models\CapabilityDefinition;
use App\Support\CapabilityRegistry;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * La table d'affichage des capacités — ce que le candidat lit d'un code.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE CODE EST L'AUTORITÉ, LE LIBELLÉ EST UNE DONNÉE
 *
 * ADR-0030 : « Le code d'une capacité est un identifiant technique non
 * éditable. Son libellé, sa description et sa position sont des données de
 * référentiel éditables en français et en arabe. » Cet écran n'ouvre donc que
 * la seconde moitié — et ni la création ni la suppression n'y existent, la
 * liste des neuf codes étant fermée dans `CapabilityRegistry`.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ÉDITER, C'EST RELIRE — LA RÈGLE DU BADGE (ADR-0032)
 *
 * Les neuf libellés ont été semés par une migration : ce sont des valeurs
 * d'architecte, marquées `a_relire`. « Une valeur posée par un architecte
 * n'entre jamais en production sans un geste humain qui la confirme, et le
 * système sait toujours distinguer les deux. » Enregistrer depuis cet écran EST
 * ce geste : le marqueur tombe, et il ne se repose pas à la main — sinon le
 * badge dirait ce qu'on veut, donc rien.
 */
class CapabilityDefinitionResource extends Resource
{
    /** LA PERMISSION QUI OUVRE CETTE SURFACE — D-13, voir `RefusNommeTest`. */
    public const PERMISSION_REQUISE = 'orders.view';

    protected static ?string $model = CapabilityDefinition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static ?string $navigationLabel = 'Libellés des capacités';

    protected static ?string $modelLabel = 'libellé de capacité';

    protected static ?string $pluralModelLabel = 'libellés des capacités';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Code technique')
                ->disabled()
                ->helperText('Fermé en code : une capacité n’existe que si un point du produit l’applique.'),

            TextInput::make('label_fr')->label('Libellé (français)')->required(),
            TextInput::make('label_ar')->label('Libellé (arabe)')->required(),

            Textarea::make('description_fr')
                ->label('Description (français)')
                ->rows(2)
                ->required()
                ->helperText('Ce que le candidat lit avant d’acheter. Aucun code brut ne lui parvient.'),

            Textarea::make('description_ar')->label('Description (arabe)')->rows(2)->required(),

            TextInput::make('position')->label('Ordre d’affichage')->numeric()->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Code')->searchable(),
                TextColumn::make('label_fr')->label('Libellé')->searchable(),
                TextColumn::make('label_ar')->label('Libellé (arabe)'),
                IconColumn::make('commercialisable')
                    ->label('Vendable')
                    ->boolean()
                    ->state(fn (CapabilityDefinition $record): bool => in_array(
                        $record->code, CapabilityRegistry::COMMERCIALIZABLE, true,
                    )),
                IconColumn::make('a_relire')
                    ->label('À relire')
                    ->boolean()
                    ->tooltip('Libellé posé par une migration, pas encore confirmé par un humain.'),
            ])
            ->defaultSort('position');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCapabilityDefinitions::route('/'),
            'edit' => EditCapabilityDefinition::route('/{record}/edit'),
        ];
    }
}
