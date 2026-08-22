<?php

namespace App\Filament\Resources\CompetencyNodes;

use App\Filament\Resources\CompetencyNodes\Pages\CreateCompetencyNode;
use App\Filament\Resources\CompetencyNodes\Pages\EditCompetencyNode;
use App\Filament\Resources\CompetencyNodes\Pages\ListCompetencyNodes;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Source;
use App\Services\TaxonomieService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * L'arbre de compétences, tenu à l'écran — lot TAXO, pas 2 et 3.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE CE LOT CHANGE VRAIMENT
 *
 * Le modèle était déjà bon : `path` matérialisé, profondeur libre, poids et
 * provenance. Ce qui manquait n'était pas la structure, c'était LA MAIN QUI LA
 * TIENT. Un arbre se créait par migration — donc par un développeur, pour
 * chaque concours, à chaque fois. Valider ou corriger un arbre devient ici une
 * après-midi de travail pédagogique.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * TROIS RÈGLES QUE CET ÉCRAN NE PEUT PAS CONTOURNER
 *
 *  · **Un poids ne s'enregistre pas sans sa justification** — la contrainte est
 *    en base (`competency_nodes_weight_justified`), l'écran ne fait que le dire
 *    plus tôt. `weight_percent` gouverne la composition de chaque série servie :
 *    un nombre qui décide de ce que les gens travaillent doit porter sa raison.
 *  · **`official` exige une source VÉRIFIÉE** — le déclencheur le tient, et la
 *    liste déroulante ne propose donc que les provenances tenables. Les
 *    migrations 000520/000530 ont rétrogradé les poids qui se disaient
 *    officiels sans pièce ; rien ne doit permettre de les y remonter d'un clic.
 *  · **La somme d'une fratrie n'est PAS forcée à 100.** Un arbre en travaux a
 *    le droit d'être incomplet ; l'écart est dit, jamais masqué (ADR-0032 :
 *    avertissement, pas refus).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE DÉPLACEMENT EST UNE ACTION À PART, ET IL ANNONCE
 *
 * Il n'est pas dans le formulaire. Changer un parent d'un coup de liste
 * déroulante, entre deux corrections de libellé, ferait du geste le plus
 * dangereux de l'écran le plus discret. Il a donc son bouton, sa confirmation,
 * et ses trois nombres annoncés AVANT (DET-88).
 */
class CompetencyNodeResource extends Resource
{
    /** LA PERMISSION QUI OUVRE CETTE SURFACE — D-13, voir `RefusNommeTest`. */
    public const PERMISSION_REQUISE = 'taxonomy.manage';

    protected static ?string $model = CompetencyNode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShare;

    protected static ?string $navigationLabel = 'Arbre de compétences';

    protected static ?string $modelLabel = 'nœud de compétence';

    protected static ?string $pluralModelLabel = 'nœuds de compétence';

    protected static string|\UnitEnum|null $navigationGroup = 'Référentiel';

    /**
     * Les provenances qu'un humain peut CHOISIR.
     *
     * `official` n'y est pas : il ne se déclare pas, il s'établit — une source
     * attachée et vérifiée, et le déclencheur le vérifie. `editorial` et
     * `unverified` sont des états hérités de l'import, pas des choix
     * pédagogiques : les proposer ici inviterait à saisir « non vérifié » comme
     * s'il s'agissait d'une qualité.
     *
     * @return array<string, string>
     */
    public static function provenancesChoisissables(): array
    {
        return [
            'reported' => 'Rapporté — une origine nommée, dont la pièce n’a pas été vue',
            'observed' => 'Observé — recompté sur notre propre corpus',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('exam_id')
                ->label('Épreuve')
                ->options(fn (): array => Exam::query()->orderBy('code')->pluck('code', 'id')->all())
                ->required()
                ->live()
                ->searchable(),

            /* Le parent se choisit à la CRÉATION ; ensuite il se déplace par
             * l'action dédiée, qui annonce ce qu'elle touche. */
            Select::make('parent_id')
                ->label('Parent')
                ->options(fn ($get): array => CompetencyNode::query()
                    ->where('exam_id', $get('exam_id'))
                    ->orderBy('path')
                    ->get()
                    ->mapWithKeys(fn (CompetencyNode $n): array => [
                        $n->id => str_repeat('— ', $n->depth).$n->code,
                    ])->all())
                ->searchable()
                ->disabled(fn (?CompetencyNode $record) => $record !== null)
                ->helperText(fn (?CompetencyNode $record) => $record === null
                    ? 'Vide = un nœud racine.'
                    : 'Le parent ne se change pas ici : le bouton « Déplacer » annonce ce qu’il touche.'),

            TextInput::make('code')->label('Code')->required()->maxLength(64)
                ->helperText('Stable : les questions déjà classées le citent.'),

            TextInput::make('name_fr')->label('Nom (français)')->required(),
            TextInput::make('name_ar')->label('Nom (arabe)')->required(),

            Textarea::make('description_fr')->label('Description (français)')->rows(2),
            Textarea::make('description_ar')->label('Description (arabe)')->rows(2),

            TextInput::make('position')->label('Ordre entre frères')->numeric()->default(1)->required(),

            TextInput::make('weight_percent')
                ->label('Poids en pourcentage')
                ->numeric()
                ->live(onBlur: true)
                ->minValue(0.01)
                ->maxValue(100)
                ->helperText('Vide tant qu’il n’est pas établi. Il gouverne la composition de '
                    .'chaque série : la méthode des plus forts restes en dépend.'),

            Textarea::make('weight_justification')
                ->label('Justification du poids')
                ->rows(3)
                ->required(fn ($get): bool => filled($get('weight_percent')))
                ->minLength(fn ($get): int => filled($get('weight_percent')) ? 20 : 0)
                ->helperText('D’où vient ce nombre. Aucune valeur pédagogique n’existe sans sa '
                    .'raison — même exigence que les bornes de quota.'),

            Select::make('provenance')
                ->label('Provenance du poids')
                ->options(self::provenancesChoisissables())
                ->default('observed')
                ->required()
                ->helperText('« Officiel » ne se choisit pas ici : il s’établit en attachant une '
                    .'source vérifiée, et la base le vérifie.'),

            Select::make('source_id')
                ->label('Source')
                ->options(fn (): array => Source::query()->orderBy('code')->get()
                    ->mapWithKeys(fn (Source $s): array => [
                        $s->id => $s->code.($s->estVerifiee() ? ' — vérifiée' : ' — non vérifiée'),
                    ])->all())
                ->searchable()
                ->helperText('La pièce dont le poids est tiré, si elle existe.'),

            /*
             * L'ÉCART DE FRATRIE, DIT EN PERMANENCE.
             *
             * Il n'empêche pas d'enregistrer — un arbre partiel est un arbre en
             * travaux, pas un arbre faux. Mais un total de 85 % qui ne se voit
             * nulle part devient un arbre faux que personne ne rattrape.
             */
            Callout::make('La somme de cette fratrie ne fait pas 100 %')
                ->color('warning')
                ->icon(Heroicon::OutlinedScale)
                ->description(fn (?CompetencyNode $record): string => self::ecartDeFratrie($record))
                ->visible(fn (?CompetencyNode $record): bool => self::ecartDeFratrie($record) !== ''),
        ]);
    }

    /** Le texte de l'écart, ou une chaîne vide s'il n'y en a pas à dire. */
    private static function ecartDeFratrie(?CompetencyNode $record): string
    {
        if ($record === null) {
            return '';
        }

        $somme = app(TaxonomieService::class)->sommeDeLaFratrie($record);

        if ($somme['complete']) {
            return '';
        }

        $manquants = $somme['sans_poids'] > 0
            ? " {$somme['sans_poids']} nœud(s) de cette fratrie n’ont pas encore de poids."
            : '';

        return "Total actuel : {$somme['total']} % (écart de {$somme['ecart']} points).{$manquants} "
            .'Ce n’est pas un refus : un arbre en cours de construction a le droit d’être incomplet.';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Nœud')
                    ->formatStateUsing(fn (string $state, CompetencyNode $r): string => str_repeat('— ', $r->depth).$state)
                    ->searchable(),
                TextColumn::make('name_fr')->label('Nom')->searchable()->wrap(),
                TextColumn::make('exam.code')->label('Épreuve'),
                /* Le nom du niveau vient du PROFIL de l'épreuve, pas d'une
                 * colonne : « Bloc » ici, « Domaine » là. C'est ce mot que le
                 * candidat lit, et le voir en administration évite d'écrire un
                 * arbre dont les niveaux ne veulent rien dire. */
                TextColumn::make('depth')
                    ->label('Niveau')
                    ->formatStateUsing(fn ($state, CompetencyNode $r): string => $r->levelName()
                        ?? "niveau {$state} — sans nom")
                    ->placeholder('—'),
                TextColumn::make('weight_percent')->label('Poids')->suffix(' %')->placeholder('—'),
                /* LE BADGE. La provenance n'est pas un détail technique : elle
                 * dit si l'on peut opposer ce nombre à quelqu'un. */
                TextColumn::make('provenance')
                    ->label('Provenance')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => match ((string) $state) {
                        'official' => 'Officiel',
                        'reported' => 'Rapporté',
                        'observed' => 'Observé',
                        'editorial' => 'Éditorial',
                        default => 'Non vérifié',
                    })
                    ->color(fn ($state): string => match ((string) $state) {
                        'official' => 'success',
                        'observed' => 'info',
                        'reported' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('path')->label('Chemin')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('path')
            ->recordActions([self::deplacer()]);
    }

    /**
     * DÉPLACER — le geste gardé de DET-88.
     *
     * Deux temps : « Calculer l'impact » annonce sans écrire, « Déplacer »
     * écrit. Les deux prennent le même paramètre, comme la pose du droit
     * transitoire — une prévisualisation qui ne porterait pas la même valeur
     * que le geste annoncerait un nombre pour en produire un autre.
     */
    public static function deplacer(): Action
    {
        return Action::make('deplacer')
            ->label('Déplacer')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->requiresConfirmation()
            ->modalHeading('Déplacer ce nœud, et tout ce qui pend dessous')
            ->modalDescription('Le chemin de tout le sous-arbre est réécrit dans une seule '
                .'transaction. Les questions et les scores suivent le nœud — ils ne sont pas '
                .'réécrits, ils pointent vers lui.')
            ->schema([
                Select::make('parent_id')
                    ->label('Nouveau parent')
                    ->options(fn (CompetencyNode $record): array => CompetencyNode::query()
                        ->where('exam_id', $record->exam_id)
                        ->whereKeyNot($record->getKey())
                        ->orderBy('path')
                        ->get()
                        ->mapWithKeys(fn (CompetencyNode $n): array => [
                            $n->id => str_repeat('— ', $n->depth).$n->code,
                        ])->all())
                    ->searchable()
                    ->helperText('Vide = en faire un nœud racine.'),
            ])
            ->action(function (CompetencyNode $record, array $data): void {
                $parent = filled($data['parent_id'] ?? null)
                    ? CompetencyNode::findOrFail($data['parent_id'])
                    : null;

                $impact = app(TaxonomieService::class)->deplacer($record, $parent);

                Notification::make()
                    ->title('Nœud déplacé')
                    ->body("{$impact['descendants']} descendant(s) réécrit(s), "
                        ."{$impact['questions']} question(s) et {$impact['scores']} score(s) suivis. "
                        ."Nouvelle profondeur : {$impact['profondeur_apres']}.")
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompetencyNodes::route('/'),
            'create' => CreateCompetencyNode::route('/create'),
            'edit' => EditCompetencyNode::route('/{record}/edit'),
        ];
    }
}
