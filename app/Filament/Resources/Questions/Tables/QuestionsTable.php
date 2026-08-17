<?php

namespace App\Filament\Resources\Questions\Tables;

use App\Filament\Resources\Questions\Actions\ActesEditoriaux;
use App\Models\CompetencyNode;
use App\Models\Question;
use App\Models\User;
use App\Services\QuestionAuthoringService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * La banque, telle qu'un rédacteur la parcourt.
 *
 * Les filtres reprennent ceux de `GET admin/questions` (PAS-27) : statut,
 * compétence, langue, auteur. Le filtre par compétence porte sur le CODE, qui
 * est ce que le plan de rédaction désigne — le rédacteur passe de l'un à
 * l'autre sans traduction.
 */
class QuestionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('stem')
                    ->label('Énoncé')
                    ->wrap()
                    ->limit(80)
                    ->searchable(),

                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'draft' => 'gray',
                        'a_verifier' => 'warning',
                        'reviewed' => 'info',
                        'pedagogically_validated' => 'primary',
                        'published' => 'success',
                        'retired' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'draft' => 'Brouillon',
                        'a_verifier' => 'À relire',
                        'reviewed' => 'Relue',
                        'pedagogically_validated' => 'Validée',
                        'published' => 'Publiée',
                        'retired' => 'Retirée',
                        default => $state,
                    }),

                TextColumn::make('node.code')->label('Compétence')->sortable(),
                TextColumn::make('locale')->label('Langue')->badge(),

                /* Le drapeau qui décide de la valeur d'une question : servir au
                 * diagnostic exige la cause de chaque distracteur, une
                 * remédiation et une source vérifiée. */
                TextColumn::make('eligible_for_diagnostic')
                    ->label('Diagnostic')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'oui' : 'non')
                    ->color(fn ($state) => $state ? 'success' : 'gray'),

                TextColumn::make('updated_at')->label('Modifiée')->since()->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'draft' => 'Brouillon',
                        'a_verifier' => 'À relire',
                        'reviewed' => 'Relue',
                        'pedagogically_validated' => 'Validée',
                        'published' => 'Publiée',
                        'retired' => 'Retirée',
                    ]),

                SelectFilter::make('locale')
                    ->label('Langue')
                    ->options(['fr' => 'Français', 'ar' => 'العربية']),

                SelectFilter::make('competency_node_id')
                    ->label('Compétence')
                    ->options(fn () => CompetencyNode::where('depth', '>', 0)
                        ->orderBy('code')->pluck('code', 'id'))
                    ->searchable(),

                /*
                 * UN FILTRE N'EST PAS UN ANNUAIRE — D-04.
                 *
                 * `->relationship('author', 'email')` construisait ses options
                 * depuis le modèle lié, SANS CONTRAINTE : il ne demandait pas
                 * « qui a écrit une question » mais « quels utilisateurs
                 * existent ». Résultat mesuré en recette humaine le 17 août :
                 * les adresses des CANDIDATS s'affichaient dans le panneau des
                 * filtres, lisibles par un relecteur qui ne porte ni
                 * `members.view` ni `users.support` — les deux permissions qui
                 * gouvernent l'accès aux personnes.
                 *
                 * On ne restreint pas au personnel, ce serait viser à côté : un
                 * membre du personnel qui n'a jamais rien écrit n'a pas plus sa
                 * place ici qu'un candidat. Le bon ensemble est celui qui a un
                 * sens — les comptes qui ont EFFECTIVEMENT écrit. Il ne demande
                 * aucune liste à tenir et se rétrécit tout seul.
                 */
                SelectFilter::make('author_id')
                    ->label('Auteur')
                    ->options(fn () => User::query()
                        ->whereIn('id', Question::query()->select('author_id')->whereNotNull('author_id'))
                        ->orderBy('email')
                        ->pluck('email', 'id')),
            ])
            /*
             * LES ACTES DE LA CHAÎNE VIVENT ICI, pas seulement sur la page
             * d'édition. Celle-ci n'est atteignable qu'avec `questions.create`
             * et sur une question non gelée ; la liste, elle, s'ouvre à qui
             * porte `questions.view`. Un relecteur y trouve donc « Marquer
             * relue », et une question publiée y garde « Retirer ».
             *
             * C'est le précédent de `designerLeMiroir()` généralisé : elle avait
             * été déplacée dans le tableau pour cette raison exacte, et le
             * raisonnement n'avait servi qu'une fois sur six.
             */
            ->recordActions([
                ...ActesEditoriaux::tous(),
                self::designerLeMiroir(),
                EditAction::make(),
            ])
            /* Aucune action de masse, et surtout aucune suppression : une
             * question ne s'efface pas, elle se retire — `restrictOnDelete` sur
             * `attempt_items.question_id` l'impose déjà en base. */
            ->toolbarActions([]);
    }

    /**
     * Redésigner le miroir d'une question PUBLIÉE — DET-48.
     *
     * POURQUOI UNE ACTION ET PAS LE FORMULAIRE. Le champ vit dans le formulaire
     * de rédaction, mais la page d'édition ne s'ouvre pas sur une question
     * publiée : `QuestionPolicy::update()` la ferme, parce que tout le reste y
     * est gelé. Rouvrir la page pour cette seule colonne aurait exposé
     * quatorze champs que la base refuse — un écran plein de pièges. L'acte est
     * donc servi comme la vérification d'une source l'est : une action nommée,
     * un seul champ, un seul appel de service.
     *
     * L'ÉCRITURE PASSE PAR `QuestionAuthoringService::designerMiroir()`. Rien
     * n'est écrit ici, et le gel du contenu reste tenu par le déclencheur : si
     * cette action tentait autre chose que cette colonne, la base la refuserait.
     */
    private static function designerLeMiroir(): Action
    {
        return Action::make('designer_miroir')
            ->label('Question miroir')
            ->icon('heroicon-o-arrows-right-left')
            ->modalHeading('Désigner la question qui vérifiera celle-ci')
            ->modalDescription(
                'La désignation n\'est pas du contenu : elle ne change rien à ce qu\'un '
                .'candidat a lu, seulement quelle question lui retendra le même piège après '
                .'une erreur. Elle se modifie donc après publication (DET-48).'
            )
            ->visible(fn (Question $record) => auth()->user()->can('designateMirror', $record))
            ->fillForm(fn (Question $record) => ['mirror_question_id' => $record->mirror_question_id])
            ->schema([
                Select::make('mirror_question_id')
                    ->label('Question miroir désignée')
                    ->options(fn (Question $record) => Question::query()
                        ->where('competency_node_id', $record->competency_node_id)
                        ->where('locale', $record->locale)
                        ->whereKeyNot($record->getKey())
                        ->orderBy('id')
                        ->pluck('stem', 'id'))
                    ->searchable()
                    ->helperText(
                        'Même compétence et même langue. À défaut de désignation, le miroir '
                        .'est choisi parmi les questions qui tendent le même piège. Une '
                        .'désignée qui n\'est pas servable — brouillon, retirée — laisse ce '
                        .'repli opérer plutôt que de refuser.'
                    ),
            ])
            ->action(fn (Question $record, array $data) => app(QuestionAuthoringService::class)
                ->designerMiroir($record, Question::find($data['mirror_question_id'] ?? null)));
    }
}
