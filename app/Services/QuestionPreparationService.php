<?php

namespace App\Services;

use App\Enums\EditorialFlagKind;
use App\Enums\PreparedQuestionState;
use App\Enums\QuestionPreparationBatchStatus;
use App\Enums\QuestionPreparationEventType;
use App\Models\CompetencyNode;
use App\Models\EditorialFlag;
use App\Models\Exam;
use App\Models\PreparedQuestion;
use App\Models\Question;
use App\Models\QuestionPreparationBatch;
use App\Models\QuestionPreparationEvent;
use App\Models\User;
use App\Tenancy\TenantContext;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Point d'écriture du socle Q2.
 *
 * Il prépare et qualifie une file. Il ne sait volontairement ni créer une
 * Question, ni importer le corpus réel, ni valider pédagogiquement un contenu.
 */
final class QuestionPreparationService
{
    /** @param array<string, int|float|string|null> $counts */
    public function startBatch(User $actor, string $sourcePath, string $sha256, array $counts = []): QuestionPreparationBatch
    {
        $this->assertActorInCurrentTenant($actor);

        $sha256 = strtolower($sha256);
        if (preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            throw new DomainException('L’empreinte SHA-256 du lot est invalide.');
        }

        return QuestionPreparationBatch::firstOrCreate(
            ['sha256' => $sha256],
            [
                'source_path' => $sourcePath,
                'counts' => $counts,
                'created_by' => $actor->id,
                'started_at' => now(),
            ]
        );
    }

    public function resumeBatch(QuestionPreparationBatch $batch, User $actor): QuestionPreparationBatch
    {
        $this->assertActorInCurrentTenant($actor);

        if ($batch->status !== QuestionPreparationBatchStatus::INTERRUPTED) {
            throw new DomainException('Seul un lot interrompu peut être repris.');
        }

        $batch->forceFill([
            'status' => QuestionPreparationBatchStatus::IN_PROGRESS,
            'finished_at' => null,
        ])->save();

        return $batch->fresh();
    }

    public function interruptBatch(QuestionPreparationBatch $batch, User $actor): QuestionPreparationBatch
    {
        return $this->finishBatch($batch, $actor, QuestionPreparationBatchStatus::INTERRUPTED);
    }

    public function completeBatch(QuestionPreparationBatch $batch, User $actor): QuestionPreparationBatch
    {
        return $this->finishBatch($batch, $actor, QuestionPreparationBatchStatus::COMPLETED);
    }

    /**
     * Prépare une ligne générique. Aucun appel vers le corpus réel n'existe ici.
     *
     * @param  array<string, mixed>  $sourceFacts
     * @param  array<string, mixed>  $provisional
     * @param  list<array<string, mixed>|string>  $anomalies
     */
    public function prepare(
        QuestionPreparationBatch $batch,
        string $importRef,
        array $sourceFacts,
        array $provisional = [],
        array $anomalies = [],
        ?PreparedQuestion $duplicateOf = null,
        ?Exam $arbre = null,
    ): PreparedQuestion {
        if ($batch->status !== QuestionPreparationBatchStatus::IN_PROGRESS) {
            throw new DomainException('Une ligne ne peut être préparée que dans un lot en cours.');
        }

        $importRef = trim($importRef);
        if ($importRef === '') {
            throw new DomainException('La référence d’import est obligatoire.');
        }

        $sourceStatus = $sourceFacts['statut'] ?? $provisional['statut'] ?? null;
        if ($sourceStatus !== null && ! array_key_exists('statut', $sourceFacts)) {
            $sourceFacts['statut'] = $sourceStatus;
        }
        unset($provisional['statut']);

        $provisionalDifficulty = $provisional['difficulte'] ?? null;
        unset($provisional['difficulte']);
        $provisionalDifficulty = $this->nullableDifficulty($provisionalDifficulty);

        $proposedAnswer = $this->nullableAnswer($sourceFacts['suggestion_reponse'] ?? null);
        $sourceSha256 = $this->sourceHash($sourceFacts);

        if ($sourceStatus !== null && ! in_array($sourceStatus, ['a_saisir', 'saisi', 'valide', 'source_illisible'], true)) {
            $anomalies[] = ['code' => 'source_status_unknown', 'value' => $sourceStatus];
        }

        return DB::transaction(function () use (
            $batch,
            $importRef,
            $sourceFacts,
            $provisional,
            $anomalies,
            $duplicateOf,
            $arbre,
            $sourceStatus,
            $provisionalDifficulty,
            $proposedAnswer,
            $sourceSha256,
        ) {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $current = PreparedQuestion::query()
                    ->where('import_ref', $importRef)
                    ->where('active', true)
                    ->lockForUpdate()
                    ->first();

                if ($current?->source_sha256 === $sourceSha256) {
                    return $current;
                }

                if ($current?->state === PreparedQuestionState::TRANSFERRED) {
                    throw new DomainException(
                        'La source diffère d’une ligne déjà transférée : la chaîne éditoriale doit arbitrer.'
                    );
                }

                if ($duplicateOf !== null
                    && (! $duplicateOf->active || $duplicateOf->state === PreparedQuestionState::DUPLICATE)) {
                    throw new DomainException('L’original d’un doublon doit être une ligne active non doublon.');
                }

                if ($current !== null) {
                    $current->forceFill([
                        'state' => PreparedQuestionState::REPLACED,
                        'active' => false,
                    ])->save();
                }

                $state = $duplicateOf === null
                    ? PreparedQuestionState::fromSourceStatus(is_string($sourceStatus) ? $sourceStatus : null)
                    : PreparedQuestionState::DUPLICATE;

                $prepared = new PreparedQuestion;
                $prepared->fill([
                    'batch_id' => $batch->id,
                    'import_ref' => $importRef,
                    'source_sha256' => $sourceSha256,
                    'source_facts' => $sourceFacts,
                    'provisional' => $provisional,
                    'provisional_difficulty' => $provisionalDifficulty,
                    'proposed_answer' => $proposedAnswer,
                    'human_fields' => [],
                    'anomalies' => array_values($anomalies),
                ]);
                $prepared->forceFill([
                    'state' => $state,
                    'active' => true,
                    'supersedes_ref' => $current?->uuid,
                    'duplicate_of_ref' => $duplicateOf?->uuid,
                    /* L'ARBRE, ET PAS LE NŒUD. Ranger une ligne sous son
                     * épreuve n'est pas la qualifier : le nœud reste nul
                     * jusqu'à ce qu'un expert le pose. Un pré-classement de
                     * script écrit dans `provisional` se relit comme une aide ;
                     * écrit dans `competency_node_id`, il se lirait comme le
                     * travail d'un humain qui n'a rien fait. */
                    'exam_id' => $arbre?->getKey(),
                ]);

                try {
                    /* La transaction imbriquée crée un savepoint. Si deux
                     * processus n'ont vu aucune ligne, l'index partiel en
                     * laisse passer un seul; le perdant revient au début et
                     * relit la ligne gagnante au lieu de laisser sa transaction
                     * PostgreSQL en état d'échec. */
                    DB::transaction(fn () => $prepared->save());

                    return $prepared->fresh();
                } catch (QueryException $exception) {
                    if ($attempt === 0 && $this->isActiveImportRefCollision($exception)) {
                        continue;
                    }

                    throw $exception;
                }
            }

            throw new DomainException('La référence d’import est restée en collision après récupération.');
        });
    }

    public function assign(PreparedQuestion $prepared, User $actor, User $assignee): PreparedQuestion
    {
        $this->assertMutable($prepared);
        $this->assertActorInCurrentTenant($actor);
        $this->assertActorInCurrentTenant($assignee);

        return DB::transaction(function () use ($prepared, $actor, $assignee) {
            $before = ['assignee_uuid' => $prepared->assignee?->uuid];
            $prepared->forceFill([
                'assigned_to' => $assignee->id,
                'assigned_at' => now(),
            ])->save();
            $this->recordGesture(
                $prepared,
                $actor,
                QuestionPreparationEventType::ASSIGNMENT_CHANGED,
                $before,
                ['assignee_uuid' => $assignee->uuid],
            );

            return $prepared->fresh();
        });
    }

    public function qualify(
        PreparedQuestion $prepared,
        User $actor,
        CompetencyNode $node,
        ?int $declaredDifficulty = null,
    ): PreparedQuestion {
        $this->assertMutable($prepared, [PreparedQuestionState::IMPORTED, PreparedQuestionState::QUALIFIED]);
        $this->assertActorInCurrentTenant($actor);

        $values = [
            'competency_node_id' => $node->id,
            'qualified_by' => $actor->id,
            'qualified_at' => now(),
            'state' => PreparedQuestionState::QUALIFIED,
        ];

        if ($declaredDifficulty !== null) {
            /* Même garde qu'à `declareDifficulty` : qualifier et noter la
             * difficulté sont deux gestes, et le second a sa permission. Sans
             * ce contrôle, il suffirait de passer par la qualification pour
             * contourner Q-10 — une porte fermée à côté d'une porte ouverte. */
            $this->assertPeutPoserLaDifficulte($actor);

            $values += [
                'declared_difficulty' => $this->nullableDifficulty($declaredDifficulty),
                'difficulty_set_by' => $actor->id,
                'difficulty_set_at' => now(),
            ];
        }

        return DB::transaction(function () use ($prepared, $actor, $node, $declaredDifficulty, $values) {
            $beforeNode = $prepared->node?->uuid;
            $beforeDifficulty = $prepared->declared_difficulty;
            $prepared->forceFill($values)->save();
            $this->recordGesture(
                $prepared,
                $actor,
                QuestionPreparationEventType::QUALIFICATION_CHANGED,
                ['competency_node_uuid' => $beforeNode],
                ['competency_node_uuid' => $node->uuid],
            );

            if ($declaredDifficulty !== null) {
                $this->recordGesture(
                    $prepared,
                    $actor,
                    QuestionPreparationEventType::DIFFICULTY_CHANGED,
                    ['difficulty' => $beforeDifficulty],
                    ['difficulty' => $prepared->declared_difficulty],
                );
            }

            return $prepared->fresh();
        });
    }

    /**
     * LA DIFFICULTÉ SE POSE SOUS PERMISSION DÉDIÉE — Q-10.
     *
     * `questions.difficulty`, distincte de `questions.create` : poser une
     * difficulté est un JUGEMENT PÉDAGOGIQUE, pas une saisie de rédaction. Un
     * rédacteur connaît sa question ; il ne connaît pas la population qui la
     * passera. La confondre avec le droit d'écrire ferait poser l'échelle par
     * qui n'a aucun moyen de l'étalonner.
     */
    public const PERMISSION_DIFFICULTE = 'questions.difficulty';

    public function declareDifficulty(PreparedQuestion $prepared, User $actor, int $difficulty): PreparedQuestion
    {
        $this->assertPeutPoserLaDifficulte($actor);
        $this->assertMutable($prepared, [
            PreparedQuestionState::IMPORTED,
            PreparedQuestionState::QUALIFIED,
            PreparedQuestionState::ANSWERED,
        ]);
        $this->assertActorInCurrentTenant($actor);

        $difficulty = $this->nullableDifficulty($difficulty);

        return DB::transaction(function () use ($prepared, $actor, $difficulty) {
            $before = $prepared->declared_difficulty;
            $prepared->forceFill([
                'declared_difficulty' => $difficulty,
                'difficulty_set_by' => $actor->id,
                'difficulty_set_at' => now(),
            ])->save();
            $this->recordGesture(
                $prepared,
                $actor,
                QuestionPreparationEventType::DIFFICULTY_CHANGED,
                ['difficulty' => $before],
                ['difficulty' => $difficulty],
            );

            return $prepared->fresh();
        });
    }

    public function confirmAnswer(PreparedQuestion $prepared, User $actor, string $answer): PreparedQuestion
    {
        $this->assertMutable($prepared, [PreparedQuestionState::QUALIFIED, PreparedQuestionState::ANSWERED]);
        $this->assertActorInCurrentTenant($actor);

        if ($prepared->competency_node_id === null) {
            throw new DomainException('Le domaine doit être qualifié avant la confirmation d’une réponse.');
        }

        $confirmedAnswer = $this->nullableAnswer($answer);
        if ($confirmedAnswer === null) {
            throw new DomainException('La confirmation doit désigner explicitement une option.');
        }
        $this->assertAnswerExistsInSource($prepared, $confirmedAnswer);

        return DB::transaction(function () use ($prepared, $actor, $confirmedAnswer) {
            $before = $prepared->confirmed_answer;
            $prepared->forceFill([
                'confirmed_answer' => $confirmedAnswer,
                'answer_confirmed_by' => $actor->id,
                'answer_confirmed_at' => now(),
                'state' => PreparedQuestionState::ANSWERED,
            ])->save();
            $this->recordGesture(
                $prepared,
                $actor,
                QuestionPreparationEventType::ANSWER_CONFIRMED,
                ['answer' => $before],
                ['answer' => $confirmedAnswer],
            );

            return $prepared->fresh();
        });
    }

    public function markDuplicate(PreparedQuestion $prepared, User $actor, PreparedQuestion $original): PreparedQuestion
    {
        $this->assertMutable($prepared, [PreparedQuestionState::IMPORTED, PreparedQuestionState::QUALIFIED]);
        $this->assertActorInCurrentTenant($actor);
        if ($prepared->is($original) || ! $original->active || $original->state === PreparedQuestionState::DUPLICATE) {
            throw new DomainException('Le doublon doit référencer une autre ligne active non doublon.');
        }

        return DB::transaction(function () use ($prepared, $actor, $original) {
            $prepared->forceFill([
                'state' => PreparedQuestionState::DUPLICATE,
                'duplicate_of_ref' => $original->uuid,
            ])->save();
            $this->recordGesture(
                $prepared,
                $actor,
                QuestionPreparationEventType::MARKED_DUPLICATE,
                ['duplicate_of_uuid' => null],
                ['duplicate_of_uuid' => $original->uuid],
            );

            return $prepared->fresh();
        });
    }

    public function markIllegible(PreparedQuestion $prepared, User $actor): PreparedQuestion
    {
        return $this->close(
            $prepared,
            $actor,
            PreparedQuestionState::ILLEGIBLE,
            QuestionPreparationEventType::MARKED_ILLEGIBLE,
        );
    }

    public function reject(PreparedQuestion $prepared, User $actor, string $reason): PreparedQuestion
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Le rejet doit porter un motif.');
        }

        $humanFields = $prepared->human_fields ?? [];
        $humanFields['rejection'] = [
            'reason' => $reason,
            'actor_uuid' => $actor->uuid,
            'at' => now()->toIso8601String(),
        ];

        return $this->close(
            $prepared,
            $actor,
            PreparedQuestionState::REJECTED,
            QuestionPreparationEventType::REJECTED,
            $humanFields,
        );
    }

    /**
     * RETRANSCRIRE une question illisible — correction C-A.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * `ILLEGIBLE` AVAIT UNE ENTRÉE ET AUCUNE SORTIE
     *
     * Une question dont le scan est coupé y entrait et n'en ressortait jamais.
     * L'état devenait un cimetière — et les cinq illisibles du corpus, une
     * perte définitive pour une raison purement matérielle : personne n'a
     * jamais décidé qu'elles ne valaient rien, seulement que le PDF était
     * mauvais.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * ELLE RÉÉCRIT UN FAIT DE SOURCE, DONC ELLE SE TRACE
     *
     * `source_facts` est gelé en base : c'est ce que le document DIT, et rien
     * ne doit le réécrire en silence. La retranscription ne le touche donc pas.
     * Elle pose le texte relu dans `human_fields.retranscription`, avec son
     * auteur, sa date et la PIÈCE d'où il vient — sans quoi on ne saurait plus,
     * dans six mois, si l'énoncé vient du sujet officiel ou de la mémoire de
     * quelqu'un.
     *
     * La ligne revient à `IMPORTED` : elle reprend la file au début, et repasse
     * par la qualification comme les autres. Lui faire sauter des étapes parce
     * qu'un humain l'a touchée serait exactement la validation parallèle que la
     * zone de préparation existe pour empêcher.
     */
    public function retranscribe(
        PreparedQuestion $prepared,
        User $actor,
        string $stem,
        string $sourceReference,
    ): PreparedQuestion {
        $this->assertActorInCurrentTenant($actor);

        if ($prepared->state !== PreparedQuestionState::ILLEGIBLE) {
            throw new DomainException('Seule une question illisible se retranscrit.');
        }

        $stem = trim($stem);
        $sourceReference = trim($sourceReference);

        if (mb_strlen($stem) < 10) {
            throw new DomainException('Une retranscription sans énoncé lisible n’en est pas une.');
        }

        if ($sourceReference === '') {
            throw new DomainException(
                'La retranscription doit nommer sa pièce : sans elle, on ne saura plus si '
                .'l’énoncé vient du sujet officiel ou de la mémoire de quelqu’un.'
            );
        }

        $humanFields = $prepared->human_fields ?? [];
        $humanFields['retranscription'] = [
            'stem' => $stem,
            'source_reference' => $sourceReference,
            'actor_uuid' => $actor->uuid,
            'at' => now()->toIso8601String(),
        ];

        return DB::transaction(function () use ($prepared, $actor, $humanFields, $sourceReference) {
            $prepared->forceFill([
                'human_fields' => $humanFields,
                'state' => PreparedQuestionState::IMPORTED,
                'active' => true,
            ])->save();

            $this->recordGesture(
                $prepared,
                $actor,
                QuestionPreparationEventType::RETRANSCRIBED,
                ['state' => PreparedQuestionState::ILLEGIBLE->value],
                ['state' => PreparedQuestionState::IMPORTED->value, 'source_reference' => $sourceReference],
            );

            return $prepared->fresh();
        });
    }

    /**
     * SIGNALER un défaut éditorial — lot Q2, pas 6.
     *
     * Un GENRE nommé, et le texte libre en supplément. Les experts lisent 1 413
     * questions une par une : c'est la relecture la moins chère du projet, et la
     * recueillir en commentaire libre la rendrait inexploitable — cinquante
     * phrases ne se dépouillent pas, quatre colonnes se comptent.
     *
     * Le signalement N'ARRÊTE PAS la file. Un expert qui doit choisir entre
     * signaler et avancer ne signale pas : la ligne garde son état, et le tri
     * des signalements est un travail éditorial à part.
     */
    public function flagEditorially(
        PreparedQuestion $prepared,
        User $actor,
        EditorialFlagKind $kind,
        ?string $note = null,
    ): EditorialFlag {
        $this->assertActorInCurrentTenant($actor);

        $note = $note === null ? null : (trim($note) ?: null);

        return DB::transaction(function () use ($prepared, $actor, $kind, $note): EditorialFlag {
            $flag = new EditorialFlag;
            $flag->forceFill([
                'prepared_question_id' => $prepared->id,
                'actor_id' => $actor->id,
                'kind' => $kind,
                'note' => $note,
                'occurred_at' => now(),
            ])->save();

            /* `['kind' => null]` et non `[]` : la contrainte
             * `question_preparation_events_payload_objects` exige des OBJETS
             * des deux côtés, et un tableau PHP vide se sérialise en `[]`.
             * « Avant, aucun genre » se dit donc avec la clé, pas sans elle. */
            $this->recordGesture(
                $prepared,
                $actor,
                QuestionPreparationEventType::EDITORIALLY_FLAGGED,
                ['kind' => null],
                ['kind' => $kind->value, 'has_note' => $note !== null],
            );

            return $flag->fresh();
        });
    }

    private function finishBatch(
        QuestionPreparationBatch $batch,
        User $actor,
        QuestionPreparationBatchStatus $status,
    ): QuestionPreparationBatch {
        $this->assertActorInCurrentTenant($actor);

        if ($batch->status !== QuestionPreparationBatchStatus::IN_PROGRESS) {
            throw new DomainException('Seul un lot en cours peut être clôturé ou interrompu.');
        }

        $batch->forceFill(['status' => $status, 'finished_at' => now()])->save();

        return $batch->fresh();
    }

    /** @param list<PreparedQuestionState> $allowed */
    private function assertMutable(PreparedQuestion $prepared, array $allowed = []): void
    {
        if (! $prepared->active || $prepared->state->isTerminal()) {
            throw new DomainException('Cette ligne de préparation est terminale et ne peut plus être modifiée.');
        }

        if ($allowed !== [] && ! in_array($prepared->state, $allowed, true)) {
            throw new DomainException("La transition depuis l’état {$prepared->state->value} est interdite.");
        }
    }

    /** @param array<string, mixed>|null $humanFields */
    private function close(
        PreparedQuestion $prepared,
        User $actor,
        PreparedQuestionState $state,
        QuestionPreparationEventType $eventType,
        ?array $humanFields = null,
    ): PreparedQuestion {
        $this->assertMutable($prepared);
        $this->assertActorInCurrentTenant($actor);

        $values = ['state' => $state];
        if ($humanFields !== null) {
            $values['human_fields'] = $humanFields;
        }

        return DB::transaction(function () use ($prepared, $actor, $state, $eventType, $values) {
            $before = $prepared->state->value;
            $prepared->forceFill($values)->save();
            $this->recordGesture(
                $prepared,
                $actor,
                $eventType,
                ['state' => $before],
                ['state' => $state->value],
            );

            return $prepared->fresh();
        });
    }

    private function assertPeutPoserLaDifficulte(User $actor): void
    {
        if (! in_array(self::PERMISSION_DIFFICULTE, app(PermissionResolver::class)->forUser($actor), true)) {
            throw new DomainException(
                'Poser la difficulté déclarée demande la permission « '.self::PERMISSION_DIFFICULTE.' » : '
                .'c’est un jugement pédagogique, pas une saisie de rédaction (Q-10).'
            );
        }
    }

    private function assertActorInCurrentTenant(User $actor): void
    {
        if (! app(TenantContext::class)->isPlatform()) {
            throw new DomainException('La préparation de la banque globale est réservée au tenant plateforme.');
        }

        if (! $actor->memberships()->exists()) {
            throw new DomainException('L’acteur ne possède aucune appartenance dans le tenant courant.');
        }
    }

    private function nullableDifficulty(mixed $difficulty): ?int
    {
        if ($difficulty === null || $difficulty === '') {
            return null;
        }

        $difficulty = filter_var($difficulty, FILTER_VALIDATE_INT);
        if ($difficulty === false || ! in_array($difficulty, Question::DIFFICULTY_SCALE, true)) {
            throw new DomainException('La difficulté doit appartenir à l’échelle déclarée de Question.');
        }

        return $difficulty;
    }

    private function nullableAnswer(mixed $answer): ?string
    {
        if ($answer === null || $answer === '') {
            return null;
        }

        $answer = strtoupper(trim((string) $answer));
        if (! in_array($answer, ['A', 'B', 'C', 'D', 'E'], true)) {
            throw new DomainException('La réponse doit désigner une option de A à E.');
        }

        return $answer;
    }

    private function assertAnswerExistsInSource(PreparedQuestion $prepared, string $answer): void
    {
        $options = $prepared->source_facts['options'] ?? null;
        if (! is_array($options)) {
            throw new DomainException('Les options de source sont absentes : aucune réponse ne peut être confirmée.');
        }

        $option = array_is_list($options)
            ? ($options[ord($answer) - ord('A')] ?? null)
            : ($options[$answer] ?? null);

        $content = is_array($option)
            ? ($option['content'] ?? $option['texte'] ?? null)
            : $option;

        if (! is_string($content) || trim($content) === '') {
            throw new DomainException("L’option {$answer} n’existe pas dans les faits de source.");
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function recordGesture(
        PreparedQuestion $prepared,
        User $actor,
        QuestionPreparationEventType $eventType,
        array $before,
        array $after,
    ): void {
        $event = new QuestionPreparationEvent;
        $event->forceFill([
            'prepared_question_id' => $prepared->id,
            'actor_id' => $actor->id,
            'event_type' => $eventType,
            'before' => $before,
            'after' => $after,
            'occurred_at' => now(),
        ])->save();
    }

    private function isActiveImportRefCollision(QueryException $exception): bool
    {
        return $exception->getCode() === '23505'
            && str_contains($exception->getMessage(), 'prepared_questions_active_import_ref_unique');
    }

    /** @param array<string, mixed> $sourceFacts */
    private function sourceHash(array $sourceFacts): string
    {
        try {
            return hash('sha256', json_encode(
                $this->canonicalize($sourceFacts),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
        } catch (JsonException $exception) {
            throw new DomainException('Les faits de source ne sont pas sérialisables.', previous: $exception);
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item) => $this->canonicalize($item), $value);
    }
}
