<?php

namespace App\Filament\Resources\Questions\Schemas;

use App\Filament\Libelles;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Question;
use App\Models\Remediation;
use App\Models\Source;
use App\Services\QuestionIntegrityChecker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * Le formulaire de rédaction — lot A4.
 *
 * IL N'ÉCRIT RIEN. Les pages `CreateQuestion` et `EditQuestion` détournent
 * l'enregistrement vers `QuestionAuthoringService`, qui passe lui-même par les
 * gardes existantes. Ce fichier ne décrit que ce qu'on saisit, jamais ce qu'on
 * en fait — un formulaire qui écrirait le modèle contournerait six pas de
 * règles en une ligne de configuration.
 *
 * CE QUI EST ABSENT EST AUSSI DÉLIBÉRÉ : ni `status`, ni `published_at`, ni
 * `validator_id`, ni les drapeaux d'éligibilité. Ces champs sont hors de
 * `$fillable` depuis la revue PAS-10 et ne changent que par
 * `QuestionTransitionService`. Les exposer ici aurait rouvert le trou que ce
 * pas-là avait fermé.
 */
class QuestionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            self::blocages(),
            self::rattachement(),
            self::enonce(),
            self::options(),
            self::source(),
        ]);
    }

    /**
     * CE QUI BLOQUE LA PUBLICATION, EN PERMANENCE ET SANS RIEN TENTER.
     *
     * Les motifs viennent de `QuestionIntegrityChecker` — exactement ceux que
     * `publish()` opposera. Le rédacteur n'a pas à cliquer sur « publier » pour
     * apprendre qu'il manque une cause : un bouton qui échoue est une garde qui
     * marche et une interface qui ment.
     */
    private static function blocages(): Callout
    {
        return Callout::make('blocages')
            ->warning()
            ->heading('Ce qui empêche encore la publication')
            ->visible(fn (?Model $record) => $record !== null && self::motifs($record) !== [])
            ->schema([
                Html::make(
                    fn (?Model $record) => '<ul class="list-disc ps-5 space-y-1">'
                        .collect(self::motifs($record))
                            ->map(fn (string $m) => '<li>'.e($m).'</li>')
                            ->implode('')
                        .'</ul>'
                ),
            ]);
    }

    /** @return list<string> */
    private static function motifs(?Model $record): array
    {
        if (! $record instanceof Question) {
            return [];
        }

        $checker = app(QuestionIntegrityChecker::class);

        /* Les deux listes, parce qu'elles ne disent pas la même chose : la
         * seconde ajoute ce qu'exige le DIAGNOSTIC — la cause de chaque
         * distracteur, la remédiation — et c'est l'usage qui fait la valeur
         * d'une question. */
        return array_values(array_unique(array_merge(
            $checker->publicationIssues($record),
            $checker->diagnosticIssues($record),
        )));
    }

    private static function rattachement(): Section
    {
        return Section::make('Rattachement')
            ->description('Une question appartient à une épreuve et à une compétence, et à une seule langue.')
            ->columns(2)
            ->schema([
                Select::make('exam_id')
                    ->label('Épreuve')
                    ->options(fn () => Exam::published()->orderBy('name_fr')->pluck('name_fr', 'id'))
                    ->required()
                    ->live()
                    ->disabled(fn (?Model $record) => self::gelee($record)),

                Select::make('competency_node_id')
                    ->label('Compétence')
                    /* Les nœuds de l'épreuve choisie seulement : rattacher une
                     * question à la compétence d'une autre épreuve est un motif
                     * de blocage du checker, autant ne pas le rendre saisissable. */
                    ->options(fn ($get) => $get('exam_id') === null ? [] : CompetencyNode::query()
                        ->where('exam_id', $get('exam_id'))
                        ->where('depth', '>', 0)
                        ->orderBy('code')
                        ->pluck('name_fr', 'id'))
                    ->required()
                    ->searchable()
                    ->disabled(fn (?Model $record) => self::gelee($record)),

                Select::make('locale')
                    ->label('Langue')
                    ->options(['fr' => 'Français', 'ar' => 'العربية'])
                    ->default('fr')
                    ->required()
                    ->live()
                    ->helperText('Une question est monolingue. La version arabe est une question distincte.')
                    ->disabled(fn (?Model $record) => self::gelee($record)),

                Select::make('kind')
                    ->label('Type')
                    ->options([
                        'qcm_single' => 'QCM à réponse unique',
                        'qcm_multiple' => 'QCM à réponses multiples',
                        'true_false' => 'Vrai / faux',
                    ])
                    ->default('qcm_single')
                    ->required()
                    ->disabled(fn (?Model $record) => self::gelee($record)),

                Select::make('remediation_id')
                    ->label('Remédiation')
                    ->options(fn ($get) => $get('competency_node_id') === null ? [] : Remediation::query()
                        ->where('competency_node_id', $get('competency_node_id'))
                        ->pluck('title', 'id'))
                    ->helperText('Exigée pour servir au diagnostic.')
                    ->disabled(fn (?Model $record) => self::gelee($record)),

                TextInput::make('difficulty')
                    ->label('Difficulté')
                    ->numeric()->minValue(1)->maxValue(5)
                    ->disabled(fn (?Model $record) => self::gelee($record)),

                /*
                 * DET-45 : le miroir DÉSIGNÉ l'emporte sur le choix par couple.
                 *
                 * PLUS DE VERROU APRÈS PUBLICATION — DET-48 tranchée. Le
                 * pointeur désigne l'USAGE de la question, pas ce qu'elle dit :
                 * le déclencheur de gel l'exempte désormais comme il exempte
                 * `eligible_for_diagnostic`. Ce champ n'a donc plus de raison
                 * d'être grisé, et il ne l'est plus.
                 *
                 * Sur une question publiée, ce formulaire ne s'ouvre pas —
                 * `QuestionPolicy::update()` le ferme parce que TOUT LE RESTE y
                 * est gelé. La redésignation passe alors par l'action
                 * `designer_miroir` de la liste, qui n'expose que cette colonne.
                 * Le champ le dit, pour que le rédacteur sache où aller.
                 *
                 * La langue est filtrée en plus de la compétence : une question
                 * d'une autre langue ne pourra JAMAIS servir de miroir —
                 * `QuestionsSoeurs::designee()` l'exige à la lecture — et
                 * l'offrir au choix serait proposer une désignation morte.
                 */
                Select::make('mirror_question_id')
                    ->label('Question miroir désignée')
                    ->options(fn (?Model $record, $get) => Question::query()
                        ->where('competency_node_id', $get('competency_node_id'))
                        ->when($get('locale') !== null, fn ($q) => $q->where('locale', $get('locale')))
                        ->when($record !== null, fn ($q) => $q->whereKeyNot($record->getKey()))
                        ->orderBy('id')
                        ->pluck('stem', 'id'))
                    ->searchable()
                    ->helperText(
                        'Facultatif. À défaut, le miroir est choisi parmi les questions de la '
                        .'même compétence qui tendent le même piège. Il reste modifiable APRÈS '
                        .'publication, depuis l\'action « Question miroir » de la liste : la '
                        .'désignation n\'est pas du contenu (DET-48).'
                    ),
            ]);
    }

    private static function enonce(): Section
    {
        return Section::make('Énoncé')
            ->schema([
                Textarea::make('stem')
                    ->label('Énoncé')
                    ->required()->rows(3)
                    ->disabled(fn (?Model $record) => self::gelee($record)),

                Textarea::make('explanation')
                    ->label('Justification générale')
                    ->required()->rows(3)
                    ->helperText('Ce que le candidat lit après correction, quelle que soit sa réponse.')
                    ->disabled(fn (?Model $record) => self::gelee($record)),
            ]);
    }

    private static function options(): Section
    {
        return Section::make('Options')
            ->description('Une seule bonne réponse. Chaque distracteur porte sa justification ET sa cause — sans quoi la question ne servira pas au diagnostic (fiche F03).')
            ->schema([
                Repeater::make('options')
                    ->label('')
                    /*
                     * PAS DE `->relationship()` — audit tournée 3, BLOC-3.
                     *
                     * Avec lui, Filament sauvegarde la relation LUI-MÊME, avant
                     * `handleRecordUpdate` et donc hors de
                     * `QuestionAuthoringService::amender()`. L'affirmation d'A4a
                     * — « l'écriture passe par le service » — était vraie à la
                     * création et fausse à l'amendement des options : la cause
                     * posée sur la bonne réponse survivait, et rien ne pouvait
                     * plus la retirer.
                     *
                     * Les options sont donc hydratées à la lecture et passées à
                     * `amender()` dans la MÊME transaction que les attributs.
                     * Voir `EditQuestion::handleRecordUpdate` et
                     * `CreateQuestion`.
                     */
                    ->orderColumn('position')
                    ->minItems(2)->maxItems(6)
                    ->defaultItems(4)
                    ->disabled(fn (?Model $record) => self::gelee($record))
                    ->schema([
                        Textarea::make('content')->label('Contenu')->required()->rows(2),
                        Toggle::make('is_correct')->label('Bonne réponse')->live(),
                        Textarea::make('rationale')
                            ->label('Justification')
                            ->required()->rows(2)
                            ->helperText('Obligatoire sur CHAQUE option, y compris la bonne.'),
                        Select::make('cause')
                            ->label('Cause de l\'erreur')
                            ->options(Libelles::causes())
                            /* La cause est INTERDITE sur la bonne réponse — une
                             * garde en base le refuse depuis le PAS-5. On la
                             * masque plutôt que de laisser la base répondre. */
                            ->visible(fn ($get) => ! $get('is_correct'))
                            /*
                             * PAS OBLIGATOIRE ICI, et c'est la décision du
                             * PAS-27 : la cause est exigée à la PUBLICATION
                             * pour diagnostic, pas à la rédaction. L'imposer
                             * dans le formulaire empêcherait d'enregistrer un
                             * brouillon en cours d'écriture — un rédacteur qui
                             * ne peut pas sauvegarder à mi-chemin travaille
                             * ailleurs, puis colle.
                             *
                             * Ce qui manque est dit par l'encart de blocages,
                             * en permanence et sans rien empêcher.
                             */
                            ->helperText('Exigée pour servir au diagnostic : c\'est elle qui devient le rendez-vous de révision.'),
                    ])
                    ->columns(2),
            ]);
    }

    private static function source(): Section
    {
        return Section::make('Source de contenu')
            ->description('Ce qui FONDE la bonne réponse, distinct de la source du blueprint. Une source vérifiée est exigée pour le diagnostic.')
            ->schema([
                Select::make('source_code')
                    ->label('Source')
                    ->options(fn () => Source::orderBy('code')->pluck('code', 'code'))
                    ->searchable()
                    ->dehydrated()
                    ->visible(fn (?Model $record) => $record === null)
                    ->helperText('Citer une source n\'est pas la vérifier : le contrôle documentaire est un acte à part.'),

                TextInput::make('source_locator')
                    ->label('Localisation dans la source')
                    ->placeholder('p. 42, article 7…')
                    ->dehydrated()
                    ->visible(fn (?Model $record) => $record === null),
            ]);
    }

    /** Le contenu d'une question publiée ou retirée est gelé (ADR-0015 §5). */
    private static function gelee(?Model $record): bool
    {
        return $record instanceof Question
            && in_array($record->status, ['published', 'retired'], true);
    }
}
