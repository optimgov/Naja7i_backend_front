<?php

namespace App\Filament\Pages;

use App\Enums\EditorialFlagKind;
use App\Enums\PreparedQuestionState;
use App\Models\CompetencyNode;
use App\Models\DifficultyLevel;
use App\Models\PreparedQuestion;
use App\Models\Question;
use App\Services\DifficulteObservee;
use App\Services\PermissionResolver;
use App\Services\QuestionPreparationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * La file de qualification — le poste de travail des experts, lot Q2.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE N'EST PAS UN BACK-OFFICE, C'EST UN POSTE DE TRAVAIL
 *
 * Des gens qui ne sont pas informaticiens vont y passer des heures, et chaque
 * friction s'y multiplie par 1 413. D'où la forme : **une question à la fois**,
 * pas un tableau de cinquante lignes où l'on perd sa place. Une file, un état,
 * un geste. Le tableau existe ailleurs pour qui pilote ; celui qui qualifie n'a
 * pas à piloter.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * AUCUN CHAMP N'ARRIVE PRÉ-REMPLI — LA RÈGLE CENTRALE DU LOT
 *
 * Ni le corrigé, ni la cause, ni la difficulté, ni la justification.
 *
 * Un champ pré-rempli est accepté sans être lu : c'est le mécanisme exact par
 * lequel une erreur d'import devient une vérité éditoriale, et il est d'autant
 * plus dangereux que la source est RICHE — plus elle propose, moins on relit.
 * Le corpus porte des `suggestion_reponse` qu'aucun humain n'a établies ; les
 * verser dans le formulaire les transformerait en réponses confirmées au
 * premier clic de validation.
 *
 * Ce que l'écran montre À CÔTÉ, jamais dedans : ce que la source dit, en
 * lecture seule et signalé comme tel. Voir sans hériter.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * DÉCLARÉE ET OBSERVÉE COEXISTENT
 *
 * L'écart entre l'hypothèse de l'expert et le taux de réussite réel dit quelque
 * chose sur la question ET sur l'expert. Les fusionner détruirait les deux. Et
 * sous le seuil, l'observée ne s'affiche PAS comme un nombre : un taux sur sept
 * réponses est du bruit mis en forme, et le montrer ferait corriger une
 * déclaration juste pour suivre un hasard.
 */
class FileDeQualification extends Page
{
    /** LA PERMISSION QUI OUVRE CETTE SURFACE — D-13, voir `RefusNommeTest`. */
    public const PERMISSION_REQUISE = 'questions.review';

    protected string $view = 'filament.pages.file-de-qualification';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $navigationLabel = 'File de qualification';

    protected static ?string $title = 'La file de qualification';

    protected static string|\UnitEnum|null $navigationGroup = 'Rédaction';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        /* La permission se lit directement plutôt que par la politique :
         * `QuestionPolicy::review()` exige une question, et il n'y en a pas
         * encore à l'entrée de la file. */
        return $user !== null
            && in_array(self::PERMISSION_REQUISE, app(PermissionResolver::class)->forUser($user), true);
    }

    public function getSubheading(): ?string
    {
        return 'Une question à la fois. Ce que la source dit s’affiche à côté de vos champs, '
            .'jamais dedans : un champ pré-rempli est accepté sans être lu.';
    }

    /** La prochaine ligne à traiter — celle qui attend depuis le plus longtemps. */
    public function laSuivante(): ?PreparedQuestion
    {
        return PreparedQuestion::query()
            ->where('active', true)
            ->whereIn('state', [PreparedQuestionState::IMPORTED, PreparedQuestionState::QUALIFIED])
            ->orderBy('id')
            ->first();
    }

    /**
     * CE QUE LA SOURCE DIT — hors des champs, et signalé comme tel.
     *
     * Rendu par une méthode distincte de tout ce qui alimente un formulaire :
     * la séparation est structurelle, pas une convention d'affichage. Aucun
     * appel de cette méthode ne doit jamais alimenter un `default()`.
     *
     * @return array<string, mixed>
     */
    public function ceQueLaSourceDit(PreparedQuestion $ligne): array
    {
        $faits = $ligne->source_facts ?? [];

        return [
            'avertissement' => __('preparation.source_lecture_seule'),
            'enonce' => $faits['enonce'] ?? null,
            'options' => $faits['options'] ?? [],
            'suggestion_reponse' => $ligne->proposed_answer,
            'difficulte_provisoire' => $ligne->provisional_difficulty,
            'anomalies' => $ligne->anomalies ?? [],
        ];
    }

    /**
     * Les cinq crans, tels que le registre les porte — jamais écrits ici.
     *
     * @return array<int, string>
     */
    public function cransDeDifficulte(?string $locale = null): array
    {
        return DifficultyLevel::query()
            ->orderBy('level')
            ->get()
            ->mapWithKeys(fn (DifficultyLevel $c): array => [
                $c->level => $c->localized('label', $locale).' — '.$c->localized('anchor', $locale),
            ])
            ->all();
    }

    /**
     * La difficulté observée, prête à afficher.
     *
     * @return array<string, mixed>
     */
    public function difficulteObservee(PreparedQuestion $ligne): array
    {
        if ($ligne->question_id === null) {
            return ['significative' => false, 'texte' => null];
        }

        $mesure = app(DifficulteObservee::class)->pour($ligne->question()->firstOrFail());

        return $mesure + [
            'texte' => $mesure['significative']
                ? __('preparation.observee_significative', [
                    'tentatives' => $mesure['tentatives'],
                    'taux' => round($mesure['taux_reussite'] * 100),
                ])
                : __('preparation.observee_non_significative', [
                    'tentatives' => $mesure['tentatives'],
                    'seuil' => DifficulteObservee::SEUIL_SIGNIFICATIF,
                ]),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->qualifier(),
            $this->signaler(),
            $this->retranscrire(),
        ];
    }

    /**
     * QUALIFIER — et rien n'y est pré-rempli.
     *
     * Aucun `default()` sur aucun champ, et c'est la règle la plus facile à
     * défaire par inadvertance : il suffirait d'un `->default($ligne->
     * proposed_answer)` pour que la suggestion d'import devienne, au premier
     * clic, une réponse confirmée par un humain qui ne l'a pas lue.
     */
    private function qualifier(): Action
    {
        return Action::make('qualifier')
            ->label('Qualifier cette question')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->visible(fn (): bool => $this->laSuivante() !== null)
            ->schema([
                Select::make('competency_node_id')
                    ->label('Nœud de compétence')
                    ->options(fn (): array => CompetencyNode::query()
                        ->orderBy('path')->get()
                        ->mapWithKeys(fn (CompetencyNode $n): array => [
                            $n->id => str_repeat('— ', $n->depth).$n->code.' · '.$n->name_fr,
                        ])->all())
                    ->searchable()
                    ->required(),

                Select::make('declared_difficulty')
                    ->label('Difficulté déclarée')
                    ->options(fn (): array => $this->cransDeDifficulte())
                    ->helperText('Votre hypothèse. Elle sera comparée au taux de réussite réel, '
                        .'et l’écart est une information — sur la question comme sur nous.'),
            ])
            ->action(function (array $data): void {
                $ligne = $this->laSuivante();

                if ($ligne === null) {
                    return;
                }

                app(QuestionPreparationService::class)->qualify(
                    $ligne,
                    auth()->user(),
                    CompetencyNode::findOrFail($data['competency_node_id']),
                    filled($data['declared_difficulty'] ?? null) ? (int) $data['declared_difficulty'] : null,
                );

                Notification::make()->title('Question qualifiée')->success()->send();
            });
    }

    /** SIGNALER — un genre nommé, le texte libre en supplément. */
    private function signaler(): Action
    {
        return Action::make('signaler')
            ->label('Signaler un défaut')
            ->color('warning')
            ->icon(Heroicon::OutlinedFlag)
            ->visible(fn (): bool => $this->laSuivante() !== null)
            ->schema([
                Select::make('kind')
                    ->label('Ce qui ne va pas')
                    ->options(fn (): array => EditorialFlagKind::options())
                    ->required()
                    ->helperText('Un genre nommé se compte et se trie ; cinquante phrases libres '
                        .'ne se dépouillent pas.'),

                Textarea::make('note')
                    ->label('Précision (facultative)')
                    ->rows(3)
                    ->helperText('En SUPPLÉMENT du genre, jamais à la place.'),
            ])
            ->action(function (array $data): void {
                $ligne = $this->laSuivante();

                if ($ligne === null) {
                    return;
                }

                app(QuestionPreparationService::class)->flagEditorially(
                    $ligne,
                    auth()->user(),
                    EditorialFlagKind::from($data['kind']),
                    $data['note'] ?? null,
                );

                Notification::make()
                    ->title('Signalement enregistré')
                    ->body('La file n’est pas interrompue : le tri des signalements est un travail à part.')
                    ->success()
                    ->send();
            });
    }

    /** RETRANSCRIRE — la sortie de `ILLEGIBLE`, qui n'en avait aucune. */
    private function retranscrire(): Action
    {
        return Action::make('retranscrire')
            ->label('Retranscrire une illisible')
            ->color('gray')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->schema([
                Select::make('prepared_question_id')
                    ->label('Question illisible')
                    ->options(fn (): array => PreparedQuestion::query()
                        ->where('state', PreparedQuestionState::ILLEGIBLE)
                        ->orderBy('id')
                        ->pluck('import_ref', 'id')->all())
                    ->required(),

                Textarea::make('stem')
                    ->label('Énoncé relu')
                    ->rows(4)
                    ->required()
                    ->minLength(10),

                Textarea::make('source_reference')
                    ->label('D’où vient cet énoncé')
                    ->rows(2)
                    ->required()
                    ->helperText('Le sujet, la page, l’exemplaire. Sans elle, on ne saura plus dans '
                        .'six mois si l’énoncé vient de la pièce ou de la mémoire de quelqu’un.'),
            ])
            ->action(function (array $data): void {
                app(QuestionPreparationService::class)->retranscribe(
                    PreparedQuestion::findOrFail($data['prepared_question_id']),
                    auth()->user(),
                    $data['stem'],
                    $data['source_reference'],
                );

                Notification::make()
                    ->title('Question retranscrite')
                    ->body('Elle reprend la file au début et repassera par la qualification, comme les autres.')
                    ->success()
                    ->send();
            });
    }
}
