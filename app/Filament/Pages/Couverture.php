<?php

namespace App\Filament\Pages;

use App\Filament\Libelles;
use App\Models\Exam;
use App\Models\Question;
use App\Services\CouvertureBanque;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/**
 * Ce qui manque à la banque, du plus attendu au moins — lot A4.
 *
 * C'EST LA PAGE D'ACCUEIL, ET C'EST TOUT L'ARGUMENT. Un back-office éditorial
 * qui ouvre sur une liste de questions demande au rédacteur de choisir sur quoi
 * travailler ; celui-ci le lui dit. Les couples (compétence, cause) sont
 * ordonnés par CANDIDATS EN ATTENTE : ce sont exactement les questions qui
 * manquent le plus au produit, établies par l'usage et non par une intuition de
 * comité.
 *
 * ELLE NE CALCULE RIEN. `CouvertureBanque` (PAS-22) rend les trous et leur
 * ordre ; cette page les affiche. Le seuil, la sévérité par langue, le tri —
 * tout vient du service, et le déplacer ici l'aurait dupliqué entre l'écran et
 * la route qui le sert déjà.
 *
 * ON PART DE LA DEMANDE, PAS DU CATALOGUE. Un couple qui n'a jamais fait
 * échouer personne n'est pas un trou : énumérer toutes les compétences croisées
 * avec les huit causes produirait des milliers de lignes que personne ne lit.
 * D'où une page qui peut légitimement être vide, et qui le dit.
 */
class Couverture extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.couverture';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $navigationLabel = 'Couverture';

    protected static ?string $title = 'Ce qui manque à la banque';

    protected static ?int $navigationSort = -2;

    /** L'accueil du panneau : elle prend la racine que le tableau de bord occupait. */
    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', Question::class) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Couples (compétence, cause) attendus par des candidats et servis par moins de '
            .CouvertureBanque::SOEURS_MINIMUM.' questions. En dessous de deux, la séance de '
            .'révision ressert l\'énoncé déjà vu : le calendrier encaisse, mais ce qui manque '
            .'reste une question à écrire.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?array $filters): Collection => $this->trous($filters))
            ->columns([
                TextColumn::make('competency.code')
                    ->label('Compétence')
                    ->description(fn (array $record) => $record['competency']['name_fr']),

                TextColumn::make('cause')
                    ->label('Cause')
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(fn (?string $state) => Libelles::cause($state)),

                /* Ce qui donne son ordre à la page, donc affiché en clair : un
                 * classement dont on ne voit pas le critère se lit comme
                 * arbitraire, et un rédacteur qui ne comprend pas l'ordre
                 * travaille dans l'ordre qui lui plaît. */
                TextColumn::make('waiting_candidates')
                    ->label('Candidats en attente')
                    ->badge()
                    ->color(fn (array $record) => $record['waiting_candidates'] > 1 ? 'danger' : 'gray')
                    ->alignEnd(),

                self::langue('fr', 'Français'),
                self::langue('ar', 'العربية'),
            ])
            ->filters([
                SelectFilter::make('exam')
                    ->label('Épreuve')
                    ->options(fn () => Exam::published()->orderBy('name_fr')->pluck('name_fr', 'id'))
                    ->default(fn () => Exam::published()->orderBy('name_fr')->value('id')),
            ])
            /* Le service rend une collection déjà ordonnée par la demande.
             * Pagination et tri de colonne la réordonneraient sur un critère
             * qui n'est pas celui de la page. */
            ->paginated(false)
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->emptyStateHeading('Aucun trou')
            ->emptyStateDescription(
                'Chaque couple attendu par un candidat est servi par au moins deux questions. '
                .'La liste se remplira d\'elle-même : elle suit les erreurs réellement commises.'
            );
    }

    /**
     * LA COUVERTURE EST PAR LANGUE, et une colonne par langue le dit mieux
     * qu'une sévérité unique. Une question est monolingue : « une sœur en
     * français, aucune en arabe » désigne deux travaux distincts, dont un seul
     * est urgent.
     */
    private static function langue(string $locale, string $libelle): TextColumn
    {
        return TextColumn::make("coverage.{$locale}.published_questions")
            ->label($libelle)
            ->badge()
            ->alignEnd()
            ->formatStateUsing(fn ($state) => $state.' / '.CouvertureBanque::SOEURS_MINIMUM)
            ->color(fn (array $record) => match ($record['coverage'][$locale]['severity']) {
                'none' => 'danger',
                'no_sibling' => 'warning',
                default => 'success',
            });
    }

    /**
     * @param  array<string, mixed>|null  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function trous(?array $filters): Collection
    {
        $exam = Exam::published()->find($filters['exam']['value'] ?? null);

        if ($exam === null) {
            return collect();
        }

        /* La clé de ligne est le couple lui-même : c'est ce qui identifie un
         * trou, et il n'y a pas d'entité en base à laquelle l'accrocher. */
        return app(CouvertureBanque::class)->trous($exam)
            ->map(fn (array $trou) => $trou + [
                '__key' => $trou['competency']['code'].'::'.$trou['cause'],
            ]);
    }
}
