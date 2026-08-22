<?php

namespace App\Filament\Pages;

use App\Models\Audience;
use App\Models\Plan;
use App\Models\TransitionBatch;
use App\Services\DroitTransitoireService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Le droit transitoire, posé depuis l'écran — Q-17.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * DEUX BOUTONS, ET L'ORDRE COMPTE
 *
 * « Prévisualiser » annonce l'impact sans rien écrire ; « Poser » écrit. Les
 * deux prennent exactement les mêmes paramètres, et c'est délibéré : une
 * prévisualisation qui ne porterait pas les mêmes valeurs que la pose
 * annoncerait un nombre pour en produire un autre.
 *
 * LA PAGE MONTRE D'ABORD CE QUI A DÉJÀ ÉTÉ POSÉ. Un geste de distribution est
 * rare et lourd ; ouvrir sur le journal des poses passées évite le second
 * geste par inadvertance, qui est le vrai risque de cet écran.
 */
class DroitTransitoire extends Page implements HasTable
{
    use InteractsWithTable;

    /** LA PERMISSION QUI OUVRE CETTE SURFACE — D-13, voir `RefusNommeTest`. */
    public const PERMISSION_REQUISE = 'orders.validate';

    protected string $view = 'filament.pages.droit-transitoire';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Droit transitoire';

    protected static ?string $title = 'Le droit transitoire des comptes existants';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('create', Plan::class) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'À l’allumage du mur payant, tout compte déjà inscrit reçoit un droit équivalent '
            .'au palier NOMMÉ ici, pour une durée bornée, nommé et visible. Un sevrage '
            .'annoncé, jamais subi — et posé par un geste tracé, jamais par une migration.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(TransitionBatch::query())
            ->columns([
                TextColumn::make('occurred_at')->label('Posé le')->dateTime('d/m/Y H:i'),
                TextColumn::make('actor.email')->label('Par'),
                TextColumn::make('plan.code')->label('Palier de référence'),
                TextColumn::make('duration_days')->label('Durée')->suffix(' j'),
                TextColumn::make('audience.name_fr')->label('Public')->placeholder('tous'),
                TextColumn::make('accounts_granted')->label('Posés'),
                TextColumn::make('accounts_skipped')->label('Déjà porteurs'),
                TextColumn::make('reason')->label('Motif')->wrap()->limit(80),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->emptyStateHeading('Aucune pose à ce jour')
            ->emptyStateDescription('Le droit transitoire n’a encore été posé sur aucun compte.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('previsualiser')
                ->label('Prévisualiser')
                ->color('gray')
                ->modalHeading('Ce que la pose ferait')
                ->modalSubmitActionLabel('Calculer')
                ->schema($this->parametres(motifRequis: false))
                ->action(function (array $data): void {
                    $this->annoncer(app(DroitTransitoireService::class)->previsualiser($data));
                }),

            Action::make('poser')
                ->label('Poser le droit transitoire')
                ->icon(Heroicon::OutlinedClock)
                ->requiresConfirmation()
                ->modalHeading('Poser le droit transitoire')
                ->modalDescription(
                    'Ce geste distribue des droits à des comptes réels. Il est tracé — auteur, '
                    .'motif, périmètre, nombres — et ne se défait pas : on révoque, on n’efface pas.'
                )
                ->schema($this->parametres(motifRequis: true))
                ->action(function (array $data): void {
                    $trace = app(DroitTransitoireService::class)->poser(auth()->user(), $data);

                    Notification::make()
                        ->title('Droit transitoire posé')
                        ->body("{$trace->accounts_granted} compte(s) servi(s), "
                            ."{$trace->accounts_skipped} déjà porteur(s).")
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * Les paramètres du geste — les mêmes pour les deux boutons.
     *
     * @return list<mixed>
     */
    private function parametres(bool $motifRequis): array
    {
        $champs = [
            /* NOMMÉE, jamais devinée. Le service refuse sans elle : « le palier
             * le plus complet du catalogue » a donné trois capacités là où
             * Q-17 en promet huit, et un droit qui se présente comme l'égal
             * d'un palier que personne n'a choisi est un droit qui ment. */
            Select::make('offre')
                ->label('Palier de référence')
                ->options(fn (): array => Plan::query()
                    ->where('active', true)->where('auto_granted', false)
                    ->orderByDesc('price_cents')->pluck('name_fr', 'code')->all())
                ->required()
                ->helperText('Sa composition définit, capacité par capacité, ce que le droit ouvre. '
                    .'Sans elle, le geste refuse : il n’y a pas de palier par défaut.'),

            TextInput::make('duree')
                ->label('Durée en jours')
                ->numeric()
                ->default(DroitTransitoireService::DUREE_DEFAUT)
                ->minValue(DroitTransitoireService::DUREE_MINIMALE)
                ->maxValue(DroitTransitoireService::DUREE_MAXIMALE)
                ->required()
                ->helperText('Bornée : sous une semaine le sevrage est subi, au-delà de six mois '
                    .'ce n’est plus une transition.'),

            Select::make('public')
                ->label('Public visé')
                ->options(fn (): array => Audience::query()->active()->ordered()->pluck('name_fr', 'code')->all())
                ->helperText('Vide = tous les comptes candidats. Sinon, ceux dont l’épreuve déclarée '
                    .'relève de cette catégorie.'),

            DatePicker::make('pose_le')
                ->label('Poser le')
                ->helperText('Vide = maintenant. Jamais dans le passé : un droit rétroactif serait '
                    .'déjà entamé sans que personne ne l’ait vu.'),
        ];

        $champs[] = Textarea::make('motif')
            ->label('Motif')
            ->rows(2)
            ->required($motifRequis)
            ->minLength($motifRequis ? DroitTransitoireService::MOTIF_MINIMAL : 0)
            ->helperText('Ce que ce geste accompagne, en une phrase. Il reste au journal.');

        return $champs;
    }

    /** @param array<string, mixed> $apercu */
    private function annoncer(array $apercu): void
    {
        Notification::make()
            ->title('Impact annoncé — rien n’a été écrit')
            ->body(
                "Palier « {$apercu['offre']} » v{$apercu['version']} · {$apercu['duree_jours']} jours · "
                ."{$apercu['comptes_vises']} compte(s) visé(s), {$apercu['deja_porteurs']} déjà porteur(s), "
                ."{$apercu['a_poser']} à servir."
            )
            ->info()
            ->persistent()
            ->send();
    }
}
