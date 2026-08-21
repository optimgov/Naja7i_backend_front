<?php

namespace App\Filament\Resources\Plans;

use App\Filament\Resources\Plans\Pages\CreatePlan;
use App\Filament\Resources\Plans\Pages\EditPlan;
use App\Filament\Resources\Plans\Pages\ListPlans;
use App\Models\Plan;
use App\Support\CapabilityRegistry;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

/**
 * Les offres, gérées sans déploiement.
 *
 * Un plan est une ligne en base précisément pour cela : ajouter une offre, en
 * changer le prix ou la durée relève du commerce, pas de l'ingénierie.
 *
 * LES CAPACITÉS SONT UNE LISTE À COCHER, jamais un champ libre. Une faute de
 * frappe dans un tableau JSON produirait un plan qui octroie une capacité que
 * rien ne lit — un candidat paierait pour un droit inexistant, et rien ne le
 * signalerait avant sa réclamation. `AbonnementService` refuse d'ailleurs
 * d'honorer un plan sans capacité connue : deux serrures.
 */
class PlanResource extends Resource
{
    /**
     * LA PERMISSION QUI OUVRE CETTE SURFACE — D-13.
     *
     * Déclarée ici parce qu'un `abort(403)` ne transporte aucun code : la
     * politique (PlanPolicy::viewAny) rend un booléen, et le nom de
     * ce qui manque est perdu au moment où l'on pourrait le dire. La page
     * 403 la lit pour nommer ce qu'il faut demander.
     *
     * Une déclaration à côté d'une politique dérive : `RefusNommeTest` la
     * tient contre elle, surface par surface.
     */
    public const PERMISSION_REQUISE = 'orders.view';

    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Offres';

    protected static ?string $modelLabel = 'offre';

    protected static ?string $pluralModelLabel = 'offres';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Code')
                ->required()
                ->maxLength(64)
                ->helperText('Stable et lisible : il apparaît dans les commandes et les octrois.')
                /* Le code identifie l'offre dans les commandes déjà passées :
                 * le changer réécrirait leur lecture. */
                ->disabled(fn (?Plan $record) => $record !== null),

            TextInput::make('name_fr')->label('Nom (français)')->required(),
            TextInput::make('name_ar')->label('Nom (arabe)')->required(),

            Textarea::make('description_fr')->label('Description (français)')->rows(2),
            Textarea::make('description_ar')->label('Description (arabe)')->rows(2),

            TextInput::make('price_cents')
                ->label('Prix en CENTIMES')
                ->numeric()
                ->required()
                ->minValue(0)
                ->helperText('19900 = 199,00 MAD. Jamais de virgule : la monnaie est un entier.'),

            TextInput::make('duration_days')
                ->label('Durée en jours')
                ->numeric()
                ->minValue(1)
                ->helperText('Vide = sans terme. La durée devient l’échéance de l’octroi.'),

            CheckboxList::make('capabilities')
                ->label('Capacités octroyées')
                ->options(fn (): array => app(CapabilityRegistry::class)
                    ->commercializableOptions(app()->getLocale()))
                ->required()
                ->nestedRecursiveRules([Rule::in(CapabilityRegistry::COMMERCIALIZABLE)])
                ->helperText('Ce que la commande honorée ouvrira, exactement.'),

            Toggle::make('active')
                ->label('Proposée à la vente')
                ->default(true)
                ->helperText('Désactiver retire l’offre du catalogue. Elle n’est jamais supprimée : des commandes y pointent.'),

            TextInput::make('position')->label('Ordre d’affichage')->numeric()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Code')->searchable(),
                TextColumn::make('name_fr')->label('Nom')->searchable(),
                TextColumn::make('price_cents')
                    ->label('Prix')
                    ->formatStateUsing(fn ($state, Plan $r) => number_format($state / 100, 2, ',', ' ').' '.$r->currency),
                TextColumn::make('duration_days')
                    ->label('Durée')
                    ->formatStateUsing(fn ($state) => $state === null ? 'sans terme' : $state.' j'),
                TextColumn::make('orders_count')->counts('orders')->label('Commandes'),
                IconColumn::make('active')->label('En vente')->boolean(),
            ])
            ->defaultSort('position');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlans::route('/'),
            'create' => CreatePlan::route('/create'),
            'edit' => EditPlan::route('/{record}/edit'),
        ];
    }
}
