<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Services\AbonnementService;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * La file des commandes — et les deux gestes qui coûtent de l'argent.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * AUCUNE RÈGLE MÉTIER ICI, comme au lot A4
 *
 * Valider appelle `AbonnementService::honorer()`, refuser appelle
 * `::refuser()`. Cette classe ne pose aucun octroi, ne calcule aucune échéance
 * et ne touche à aucun compteur. Si elle le faisait, le back-office et l'API
 * ouvriraient deux abonnements subtilement différents — et le troisième moyen
 * de paiement révélerait l'écart un an plus tard.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * DEUX PERMISSIONS, ET LA DISTINCTION EST LE CŒUR DU LOT
 *
 * `orders.view` consulte. `orders.validate` ouvre un droit qui vaut de
 * l'argent, sur un compte nommé, sans qu'aucun prestataire n'en garde trace.
 * Les boutons ne s'affichent pas sans la seconde — pas grisés : absents, comme
 * partout dans ce produit.
 *
 * LA CONFIRMATION EST OBLIGATOIRE sur la validation, et elle nomme le candidat
 * et l'offre. Un clic distrait dans une file de trente lignes donne un
 * abonnement à la mauvaise personne, et rien ne le rattrape.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $navigationLabel = 'Commandes';

    protected static ?string $modelLabel = 'commande';

    protected static ?string $pluralModelLabel = 'commandes';

    /** Le nombre de commandes EN ATTENTE, visible sans ouvrir l'écran. */
    public static function getNavigationBadge(): ?string
    {
        $enAttente = Order::enAttente()->count();

        return $enAttente > 0 ? (string) $enAttente : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Reçue le')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('user.email')->label('Candidat')->searchable(),
                TextColumn::make('plan.name_fr')->label('Offre'),
                TextColumn::make('amount_cents')
                    ->label('Montant')
                    /* LE MONTANT FIGÉ de la commande, jamais le prix courant du
                     * plan : relire le prix d'aujourd'hui réécrirait le passé. */
                    ->formatStateUsing(fn ($state, Order $r) => number_format($state / 100, 2, ',', ' ').' '.$r->currency),
                TextColumn::make('method')->label('Moyen')->badge(),
                TextColumn::make('external_reference')->label('Référence')->fontFamily('mono')->toggleable(),
                TextColumn::make('status')->label('État')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'honoree' => 'success',
                        'en_attente' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('validePar.email')->label('Validée par')->toggleable()
                    ->placeholder('—'),
                TextColumn::make('validated_at')->label('Validée le')->dateTime('d/m/Y H:i')->toggleable(),
                TextColumn::make('refusal_reason')->label('Motif du refus')->wrap()->toggleable()
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('État')->options([
                    'en_attente' => 'En attente',
                    'honoree' => 'Honorée',
                    'annulee' => 'Refusée',
                    'expiree' => 'Expirée',
                ])->default('en_attente'),
            ])
            ->recordActions([
                Action::make('valider')
                    ->label('Valider')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Ouvrir l’abonnement ?')
                    /* La confirmation NOMME le candidat et l'offre : dans une
                     * file de trente lignes, un clic distrait donne un
                     * abonnement à la mauvaise personne. */
                    ->modalDescription(fn (Order $r) => "Le compte {$r->user->email} obtiendra « {$r->plan->name_fr} » "
                        .'immédiatement. L’échéance court à partir de maintenant.')
                    ->visible(fn (Order $r) => $r->status === 'en_attente')
                    ->authorize(fn (Order $r) => auth()->user()?->can('validate', $r) ?? false)
                    ->action(function (Order $r) {
                        app(AbonnementService::class)->honorer($r, auth()->user());

                        Notification::make()->success()
                            ->title('Abonnement ouvert')
                            ->body("« {$r->plan->name_fr} » pour {$r->user->email}.")
                            ->send();
                    }),

                Action::make('refuser')
                    ->label('Refuser')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Refuser cette commande ?')
                    ->schema([
                        Textarea::make('motif')
                            ->label('Motif (interne)')
                            ->required()
                            ->rows(2)
                            /* INTERNE, et l'interface le dit à qui l'écrit :
                             * sans cette phrase, quelqu'un rédigera un jour un
                             * motif à destination du candidat. */
                            ->helperText('Lu par l’équipe uniquement. Le candidat ne le verra jamais.'),
                    ])
                    ->visible(fn (Order $r) => $r->status === 'en_attente')
                    ->authorize(fn (Order $r) => auth()->user()?->can('validate', $r) ?? false)
                    ->action(function (Order $r, array $data) {
                        app(AbonnementService::class)->refuser($r, auth()->user(), $data['motif']);

                        Notification::make()->warning()->title('Commande refusée')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListOrders::route('/')];
    }
}
