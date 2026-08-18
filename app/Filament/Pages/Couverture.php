<?php

namespace App\Filament\Pages;

use App\Filament\Libelles;
use App\Filament\Resources\Questions\QuestionResource;
use App\Models\Exam;
use App\Models\Question;
use App\Services\CouvertureBanque;
use BackedEnum;
use Filament\Actions\Action;
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
    /**
     * LA PERMISSION QUI OUVRE CETTE SURFACE — D-13.
     *
     * Déclarée ici parce qu'un `abort(403)` ne transporte aucun code : la
     * politique (QuestionPolicy::viewAny, par `canAccess()`) rend un booléen, et le nom de
     * ce qui manque est perdu au moment où l'on pourrait le dire. La page
     * 403 la lit pour nommer ce qu'il faut demander.
     *
     * Une déclaration à côté d'une politique dérive : `RefusNommeTest` la
     * tient contre elle, surface par surface.
     */
    public const PERMISSION_REQUISE = 'questions.view';

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
                    /* D-03 — L'ÉPREUVE PAR DÉFAUT EST CELLE QUI A DU TRAVAIL.
                     * L'ordre alphabétique ouvrait sur une épreuve sans
                     * contenu ni candidat, et la page concluait « Aucun trou »
                     * en regardant ailleurs. Le critère vit dans le service,
                     * avec le reste de la mesure. */
                    ->default(fn () => $this->epreuveParDefaut()?->id),
            ])
            /* Le service rend une collection déjà ordonnée par la demande.
             * Pagination et tri de colonne la réordonneraient sur un critère
             * qui n'est pas celui de la page. */
            ->paginated(false)
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->emptyStateHeading(fn () => $this->titreDuVide())
            ->emptyStateDescription(fn () => $this->texteDuVide())
            /* RÈGLE DES PORTES, CLAUSES 1 ET 2. Cette page mesure ce qui
             * manque à la banque ; ce qui la remplit est une question écrite.
             * Vide, elle ne renvoyait nulle part — même famille que le D-01,
             * transposée au personnel.
             *
             * L'action n'apparaît QUE si le compte peut écrire : la règle du
             * dépôt veut qu'une action soit proposée ou absente, jamais grisée.
             * Un relecteur n'a pas `questions.create`, et le bouton n'existe
             * pas pour lui. */
            ->emptyStateActions([
                Action::make('ecrire')
                    ->label('Écrire une question')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn () => QuestionResource::getUrl('create'))
                    ->visible(fn () => auth()->user()?->can('create', Question::class) ?? false),

                Action::make('file')
                    ->label('Voir la file de rédaction')
                    ->url(fn () => QuestionResource::getUrl('index'))
                    ->link(),
            ]);
    }

    /**
     * L'épreuve sur laquelle la page ouvre — D-03.
     *
     * Mémorisée le temps de la requête : le filtre l'appelle une fois, l'état
     * vide une ou deux fois de plus, et chaque appel parcourt les trous de
     * toutes les épreuves publiées.
     */
    private function epreuveParDefaut(): ?Exam
    {
        return $this->classement()['exam'] ?? null;
    }

    /** @return array{exam: Exam, trous: int, attente: int, questions: int}|array{} */
    private function classement(): array
    {
        return $this->classementMemorise ??= (app(CouvertureBanque::class)->epreuveAOuvrir() ?? []);
    }

    /** @var array{exam: Exam, trous: int, attente: int, questions: int}|array{}|null */
    private ?array $classementMemorise = null;

    /**
     * L'ÉPREUVE REGARDÉE EST CELLE DU FILTRE, PAS CELLE DU DÉFAUT.
     *
     * L'état vide doit nommer ce qu'il a examiné, sans quoi « Aucun trou » est
     * une affirmation sans sujet — le défaut exact du D-03. Le filtre est
     * modifiable : on lit donc SA valeur, et on ne retombe sur le défaut que
     * tant qu'il n'a pas été touché.
     */
    private function epreuveRegardee(): ?Exam
    {
        $choisie = $this->getTableFilterState('exam')['value'] ?? null;

        return Exam::published()->find($choisie) ?? $this->epreuveParDefaut();
    }

    private function titreDuVide(): string
    {
        $exam = $this->epreuveRegardee();

        if ($exam === null) {
            return 'Aucune épreuve publiée';
        }

        /* UNE ÉPREUVE SANS BANQUE N'A PAS « AUCUN TROU » : elle n'a rien du
         * tout. Les deux phrases se ressemblent et ne disent pas la même
         * chose — la première rassure à tort. */
        return Question::where('exam_id', $exam->id)->where('status', 'published')->exists()
            ? 'Aucun trou'
            : 'Rien à mesurer sur cette épreuve';
    }

    private function texteDuVide(): string
    {
        $exam = $this->epreuveRegardee();

        if ($exam === null) {
            return 'Le catalogue ne publie aucune épreuve : il n\'y a pas de banque à couvrir.';
        }

        $publiees = Question::where('exam_id', $exam->id)->where('status', 'published')->count();

        if ($publiees === 0) {
            return "« {$exam->name_fr} » ne compte aucune question publiée. Rien n\'y est donc "
                .'attendu par un candidat, et l\'absence de trou ne dit rien de l\'état de la '
                .'banque. Changez d\'épreuve, ou écrivez la première question de celle-ci.';
        }

        return "« {$exam->name_fr} » : chaque couple attendu par un candidat est servi par au "
            .'moins deux questions. La liste se remplira d\'elle-même — elle suit les erreurs '
            .'réellement commises.';
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
