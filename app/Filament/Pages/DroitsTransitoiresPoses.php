<?php

namespace App\Filament\Pages;

use App\Filament\Support\ExpliqueSonEcran;
use App\Filament\Support\GuideDEcran;
use App\Models\Plan;
use App\Models\User;
use App\Services\DroitTransitoireService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Les droits transitoires en cours — ajuster, révoquer (Q-17).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LES GESTES PORTENT SUR LE DROIT D'UN COMPTE, PAS SUR UNE LIGNE
 *
 * Un droit transitoire est fait d'autant d'octrois que de capacités. Offrir
 * d'en révoquer un seul produirait un sevrage en escalier — l'examen blanc
 * fermé lundi, la carte de maîtrise jeudi — que personne n'a décidé. La table
 * liste donc des COMPTES, et chaque geste couvre tout leur droit transitoire.
 *
 * ELLE NE MONTRE QUE LE TRANSITOIRE. Un droit acheté et le palier gratuit
 * n'apparaissent pas ici, et le service refuse de les toucher même si un
 * identifiant y menait.
 */
class DroitsTransitoiresPoses extends Page implements ExpliqueSonEcran, HasTable
{
    public static function guideDeLEcran(): GuideDEcran
    {
        return new GuideDEcran(
            titre: __('guides.droits_transitoires_poses.titre'),
            role: __('guides.droits_transitoires_poses.role'),
            gestes: __('guides.droits_transitoires_poses.gestes'),
            quandCEstVide: __('guides.droits_transitoires_poses.vide'),
            ensuite: [
                ['libelle' => __('guides.droits_transitoires_poses.ensuite_poser'), 'url' => DroitTransitoire::getUrl()],
            ],
        );
    }

    use InteractsWithTable;

    /** LA PERMISSION QUI OUVRE CETTE SURFACE — D-13, voir `RefusNommeTest`. */
    public const PERMISSION_REQUISE = 'orders.validate';

    protected string $view = 'filament.pages.droits-transitoires-poses';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Droits transitoires en cours';

    protected static ?string $title = 'Les droits transitoires posés';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('create', Plan::class) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Chaque geste porte sur le droit transitoire ENTIER du compte. Révoquer ne supprime '
            .'rien : le droit est clos, la ligne subsiste, et le candidat retombe immédiatement au '
            .'palier qu’il détient par ailleurs.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                User::query()->whereHas('accessGrants', fn (Builder $q) => $q->where('origin', 'transition'))
            )
            ->columns([
                TextColumn::make('email')->label('Compte')->searchable(),
                TextColumn::make('capacites')
                    ->label('Capacités ouvertes')
                    ->state(fn (User $record): int => $this->octrois($record)->count()),
                TextColumn::make('fin')
                    ->label('Fin')
                    ->state(fn (User $record): ?string => $this->octrois($record)
                        ->max('ends_at')?->format('d/m/Y H:i')),
                TextColumn::make('etat')
                    ->label('État')
                    ->badge()
                    ->color(fn (User $record): string => $this->encoreOuvert($record) ? 'success' : 'gray')
                    ->state(fn (User $record): string => $this->encoreOuvert($record) ? 'en cours' : 'clos'),
            ])
            ->recordActions([
                $this->ajuster(),
                $this->revoquer(),
            ])
            ->emptyStateHeading('Aucun droit transitoire posé')
            ->emptyStateDescription('Rien n’a encore été distribué depuis l’écran de pose.');
    }

    private function ajuster(): Action
    {
        return Action::make('ajusterLaFin')
            ->label('Ajuster la fin')
            ->color('gray')
            ->modalHeading('Ajuster la fin du droit transitoire')
            ->modalDescription('La nouvelle échéance s’applique à toutes les capacités du droit. '
                .'L’avant et l’après restent au journal.')
            ->visible(fn (User $record): bool => $this->encoreOuvert($record))
            ->schema([
                DatePicker::make('fin')->label('Nouvelle fin')->required(),
                Textarea::make('motif')->label('Motif')->rows(2)->required()
                    ->minLength(DroitTransitoireService::MOTIF_MINIMAL),
            ])
            ->action(function (User $record, array $data): void {
                $touches = app(DroitTransitoireService::class)
                    ->ajusterLaFin($record, auth()->user(), $data['fin'], $data['motif']);

                Notification::make()
                    ->title('Fin ajustée')
                    ->body("{$touches} capacité(s) alignée(s) sur la nouvelle échéance.")
                    ->success()
                    ->send();
            });
    }

    private function revoquer(): Action
    {
        return Action::make('revoquer')
            ->label('Révoquer')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Révoquer le droit transitoire')
            ->modalDescription('Le droit est CLOS, jamais effacé : la ligne subsiste et reste '
                .'lisible. Le candidat retombe immédiatement au palier qu’il détient par ailleurs.')
            ->visible(fn (User $record): bool => $this->encoreOuvert($record))
            ->schema([
                Textarea::make('motif')->label('Motif')->rows(2)->required()
                    ->minLength(DroitTransitoireService::MOTIF_MINIMAL),
            ])
            ->action(function (User $record, array $data): void {
                $clos = app(DroitTransitoireService::class)
                    ->revoquer($record, auth()->user(), $data['motif']);

                Notification::make()
                    ->title('Droit transitoire révoqué')
                    ->body("{$clos} capacité(s) close(s). Rien n’a été supprimé.")
                    ->success()
                    ->send();
            });
    }

    private function octrois(User $compte)
    {
        return app(DroitTransitoireService::class)->octroisTransitoiresDe($compte);
    }

    private function encoreOuvert(User $compte): bool
    {
        return $this->octrois($compte)->contains(
            fn ($octroi): bool => $octroi->ends_at === null || $octroi->ends_at->isFuture()
        );
    }
}
