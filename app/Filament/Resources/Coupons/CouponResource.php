<?php

namespace App\Filament\Resources\Coupons;

use App\Filament\Resources\Coupons\Pages\CreateCoupon;
use App\Filament\Resources\Coupons\Pages\ListCoupons;
use App\Models\Coupon;
use App\Models\Plan;
use App\Services\CouvertureOffre;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
/* `Filament\Actions\Action` ET NON `Filament\Tables\Actions\Action` : ce
 * second espace de noms N'EXISTE PAS en Filament 4 — le paquet `tables` n'a
 * plus de dossier `Actions`. La classe manquante ne levait qu'à L'OUVERTURE
 * de la page, et aucun test n'ouvrait `/admin/coupons` ni `/admin/orders` :
 * les deux rendaient 500 depuis le lot ABO. Les ressources qui marchaient —
 * questions, sources — importaient déjà la bonne. */
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Les coupons cadeaux — émettre un titre, et le tracer.
 *
 * ENGENDRER UN COUPON DEMANDE `orders.validate`, comme valider. Un lot de
 * cinquante coupons est cinquante abonnements en puissance : c'est le même
 * pouvoir, exercé plus tôt.
 *
 * LE CODE N'EST JAMAIS SAISI À LA MAIN. `Coupon::engendrer()` le tire sur un
 * alphabet sans O ni I ni 1 — un coupon se dicte au téléphone et se recopie
 * d'une capture d'écran, et chaque caractère ambigu est un appel au support.
 * Laisser saisir un code produirait « PROMO2026 », devinable par quiconque.
 *
 * LA NOTE EST LE JOURNAL COMPTABLE DU PAUVRE, et elle est ce qui rend ce moyen
 * défendable sans prestataire : « virement du 14/08 », « partenariat AREF
 * Oriental ». Sans elle, personne ne sait plus, six mois après, pourquoi ce
 * droit a été donné.
 */
class CouponResource extends Resource
{
    /**
     * LA PERMISSION QUI OUVRE CETTE SURFACE — D-13.
     *
     * Déclarée ici parce qu'un `abort(403)` ne transporte aucun code : la
     * politique (CouponPolicy::viewAny) rend un booléen, et le nom de
     * ce qui manque est perdu au moment où l'on pourrait le dire. La page
     * 403 la lit pour nommer ce qu'il faut demander.
     *
     * Une déclaration à côté d'une politique dérive : `RefusNommeTest` la
     * tient contre elle, surface par surface.
     */
    public const PERMISSION_REQUISE = 'orders.view';

    protected static ?string $model = Coupon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $navigationLabel = 'Coupons';

    protected static ?string $modelLabel = 'coupon';

    protected static ?string $pluralModelLabel = 'coupons';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('plan_id')
                ->label('Offre ouverte')
                ->options(fn () => Plan::active()->ordered()->pluck('name_fr', 'id'))
                ->live()
                ->required(),

            Placeholder::make('couverture_offre')
                ->label('Contenu couvert par cette offre')
                ->content(function (Get $get): string {
                    $plan = Plan::find($get('plan_id'));

                    return $plan === null
                        ? 'Sélectionnez une offre pour mesurer son contenu jouable.'
                        : app(CouvertureOffre::class)->message($plan);
                }),

            TextInput::make('max_uses')
                ->label('Nombre d’utilisations')
                ->numeric()->minValue(1)->default(1)
                ->helperText('1 pour un cadeau nominatif ; 50 pour un lot partenaire.'),

            DateTimePicker::make('valid_from')->label('Valide à partir du')->default(now())->required(),
            DateTimePicker::make('valid_until')
                ->label('Valide jusqu’au')
                ->helperText('Vide = sans expiration.'),

            Textarea::make('note')
                ->label('Note interne')
                ->rows(2)
                ->helperText('Pourquoi ce coupon existe : « virement du 14/08 », « partenariat AREF Oriental ». Jamais lue par le candidat.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Code')->searchable()->copyable()
                    ->fontFamily('mono'),
                TextColumn::make('plan.name_fr')->label('Offre'),
                TextColumn::make('utilisation')
                    ->label('Utilisations')
                    ->state(fn (Coupon $r) => "{$r->used_count} / {$r->max_uses}"),
                TextColumn::make('status')->label('État')->badge(),
                TextColumn::make('valid_until')->label('Expire le')->dateTime('d/m/Y')
                    ->placeholder('sans expiration'),
                TextColumn::make('creePar.email')->label('Créé par')->toggleable(),
                TextColumn::make('note')->label('Note')->wrap()->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                /* RÉVOQUER, JAMAIS SUPPRIMER : une commande peut pointer ici, et
                 * l'effacer effacerait la trace de ce qui a été donné à qui. */
                Action::make('revoquer')
                    ->label('Révoquer')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Le code cessera d’être utilisable. Les commandes déjà honorées ne changent pas.')
                    ->visible(fn (Coupon $r) => $r->status !== 'revoque')
                    ->authorize(fn (Coupon $r) => auth()->user()?->can('update', $r) ?? false)
                    ->action(fn (Coupon $r) => $r->update(['status' => 'revoque'])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCoupons::route('/'),
            'create' => CreateCoupon::route('/create'),
        ];
    }
}
