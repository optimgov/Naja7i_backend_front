<?php

namespace App\Filament\Resources\Plans;

use App\Contracts\AccessGrant;
use App\Filament\Resources\Plans\Pages\CreatePlan;
use App\Filament\Resources\Plans\Pages\EditPlan;
use App\Filament\Resources\Plans\Pages\ListPlans;
use App\Filament\Resources\Plans\RelationManagers\VersionsRelationManager;
use App\Models\AccessGrantRecord;
use App\Models\Audience;
use App\Models\Plan;
use App\Models\QuotaProfile;
use App\Services\PorteeVendable;
use App\Services\QuotaProfileService;
use App\Support\CapabilityRegistry;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
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
use Illuminate\Validation\Rule;

/**
 * Les offres, composées sans déploiement — lot 3A.6.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LA RÈGLE QUI GOUVERNE TOUTE CETTE SURFACE
 *
 * « L'admin commerciale COMPOSE ce que le code DÉCLARE. Elle n'invente jamais
 * une brique — elle en assemble. » Trois registres lui sont inaccessibles en
 * écriture : les capacités, les commercialisables, les types de portée. Un
 * quatrième — les profils de quota — lui est ouvert en SÉLECTION seulement.
 *
 * Cet écran est donc fait de listes fermées et d'un seul champ libre : les
 * textes. Chaque liste a son refus serveur, parce qu'une requête forgée ne
 * passe pas par un formulaire.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * AUCUN CHAMP NUMÉRIQUE DE QUOTA, ET UN SEUL CHAMP DE QUOTA TOUT COURT
 *
 * `quota_profile_id` est une LISTE DÉROULANTE alimentée par
 * `QuotaProfileService::selectionnablesPour()`. Le nombre vient du registre
 * pédagogique, jamais du clavier de celle qui vend — c'est le partage de
 * responsabilité du §4 de la spécification, et `assertSelectionnable` le tient
 * côté serveur quoi que l'écran propose.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUI VERSIONNE ET CE QUI NE VERSIONNE PAS
 *
 * Le formulaire ne le décide pas : `PlanVersionService::CONTRACTUAL_FIELDS` le
 * décide, et l'écran se contente d'écrire la projection. La note de catalogue,
 * la mise en vente et le rang d'affichage n'y figurent pas — ils changent où et
 * quand l'offre se voit, pas ce qu'on obtient.
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

            Select::make('audience_id')
                ->label('Catégorie de public')
                ->options(fn (): array => Audience::query()->active()->ordered()->get()
                    ->mapWithKeys(fn (Audience $a): array => [$a->id => $a->localized('name')])->all())
                ->required()
                ->helperText('Qui a le droit de souscrire (Q-19). Contractuel : en changer crée une version.'),

            TextInput::make('name_fr')->label('Nom (français)')->required(),
            TextInput::make('name_ar')->label('Nom (arabe)')->required(),

            Textarea::make('description_fr')->label('Description (français)')->rows(2),
            Textarea::make('description_ar')->label('Description (arabe)')->rows(2),

            Textarea::make('internal_note')
                ->label('Note de catalogue (interne)')
                ->rows(2)
                ->helperText('Pour l’équipe seulement : le candidat ne la lit jamais, et elle ne versionne pas.'),

            TextInput::make('price_cents')
                ->label('Prix en CENTIMES')
                ->numeric()
                ->required()
                ->minValue(0)
                ->helperText('19900 = 199,00 MAD. Jamais de virgule : la monnaie est un entier.'),

            Select::make('currency')
                ->label('Devise')
                ->options(array_combine(Plan::DEVISES, Plan::DEVISES))
                ->default('MAD')
                ->required()
                ->helperText('Fermée en code : une devise sans canal de paiement est une promesse invendable.'),

            TextInput::make('duration_days')
                ->label('Durée en jours')
                ->numeric()
                ->minValue(1)
                ->helperText('Vide = sans terme. La durée devient l’échéance de l’octroi.'),

            DateTimePicker::make('sale_opens_at')
                ->label('Mise en vente le')
                ->seconds(false)
                ->helperText('Vide = déjà en vente. Avant cette date, l’offre n’apparaît pas au catalogue.'),

            DateTimePicker::make('sale_closes_at')
                ->label('Fin de vente le')
                ->seconds(false)
                ->after('sale_opens_at')
                ->helperText('Vide = sans fin. Après cette date, la souscription est refusée — jamais grisée.'),

            CheckboxList::make('capabilities')
                ->label('Capacités octroyées')
                ->options(fn (): array => app(CapabilityRegistry::class)
                    ->commercializableOptions(app()->getLocale()))
                ->required()
                ->nestedRecursiveRules([Rule::in(CapabilityRegistry::COMMERCIALIZABLE)])
                ->helperText('Ce que la commande honorée ouvrira, exactement.'),

            Select::make('quota_profile_id')
                ->label('Profil de quota')
                ->options(fn (): array => self::profilsSelectionnables())
                ->helperText(
                    'Aucun nombre ne se tape ici : l’admin pédagogique définit les profils et leurs '
                    .'bornes, vous en choisissez un. Vide = sans enveloppe, consommation libre.'
                ),

            Select::make('scope_type')
                ->label('Portée — type')
                ->options(self::typesDePortee())
                ->live()
                ->helperText('Vide = la plateforme entière. La liste est fermée en code : un type sans règle d’ascendance ne se résout pas.'),

            Select::make('scope_uuid')
                ->label('Portée — objet visé')
                ->options(fn ($get): array => app(PorteeVendable::class)
                    ->optionsPour($get('scope_type'), app()->getLocale()))
                ->searchable()
                ->helperText('L’objet du catalogue que le droit couvrira, avec toute son ascendance.'),

            Toggle::make('active')
                ->label('Proposée à la vente')
                ->default(true)
                ->helperText('Désactiver retire l’offre du catalogue. Elle n’est jamais supprimée : des commandes y pointent.'),

            TextInput::make('position')->label('Ordre d’affichage')->numeric()->default(0),
        ]);
    }

    /**
     * Les profils que l'admin commerciale peut sélectionner, par identifiant.
     *
     * La LISTE vient du service — profil actif, unité comptée par une capacité
     * du produit — et n'est pas réécrite ici : deux définitions de
     * « sélectionnable » divergeraient, et c'est le formulaire qui aurait tort.
     *
     * @return array<int, string>
     */
    private static function profilsSelectionnables(): array
    {
        $parCode = app(QuotaProfileService::class)
            ->selectionnablesPour(AccessGrant::QUESTIONS_ANSWER, app()->getLocale());

        if ($parCode === []) {
            return [];
        }

        $identifiants = QuotaProfile::query()
            ->whereIn('code', array_keys($parCode))
            ->pluck('id', 'code');

        $options = [];

        foreach ($parCode as $code => $libelle) {
            if ($identifiants->has($code)) {
                $options[$identifiants->get($code)] = $libelle;
            }
        }

        return $options;
    }

    /** @return array<string, string> */
    private static function typesDePortee(): array
    {
        return [
            AccessGrantRecord::SCOPE_AUDIENCE => 'Catégorie de public',
            AccessGrantRecord::SCOPE_FILIERE => 'Filière',
            AccessGrantRecord::SCOPE_EXAM_FAMILY => 'Famille de concours',
            AccessGrantRecord::SCOPE_EXAM => 'Épreuve',
            AccessGrantRecord::SCOPE_COMPETENCY_NODE => 'Nœud de compétence',
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Code')->searchable(),
                TextColumn::make('name_fr')->label('Nom')->searchable(),
                TextColumn::make('audience.name_fr')->label('Public')->placeholder('—'),
                TextColumn::make('price_cents')
                    ->label('Prix')
                    ->formatStateUsing(fn ($state, Plan $r) => number_format($state / 100, 2, ',', ' ').' '.$r->currency),
                TextColumn::make('duration_days')
                    ->label('Durée')
                    ->formatStateUsing(fn ($state) => $state === null ? 'sans terme' : $state.' j'),
                TextColumn::make('quotaProfile.code')->label('Quota')->placeholder('sans enveloppe'),
                TextColumn::make('scope_type')
                    ->label('Portée')
                    ->formatStateUsing(fn (?string $state) => $state === null
                        ? 'plateforme entière'
                        : (self::typesDePortee()[$state] ?? $state)),
                TextColumn::make('versions_count')->counts('versions')->label('Versions'),
                TextColumn::make('orders_count')->counts('orders')->label('Commandes'),
                IconColumn::make('active')->label('En vente')->boolean(),
            ])
            ->defaultSort('position');
    }

    /** L'historique des versions se lit sur la fiche de l'offre (§2.6). */
    public static function getRelations(): array
    {
        return [VersionsRelationManager::class];
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
