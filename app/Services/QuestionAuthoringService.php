<?php

namespace App\Services;

use App\Models\Question;
use App\Models\Source;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Rédaction d'une question : assemblage, et RIEN D'AUTRE.
 *
 * AUCUNE RÈGLE MÉTIER NE NAÎT ICI, et c'est la contrainte du lot.
 * `QuestionIntegrityChecker` décide de ce qui est publiable,
 * `QuestionTransitionService` de ce qui peut changer d'état, et la base impose
 * le gel du contenu publié par trigger. Ce service ne fait qu'écrire un
 * brouillon et ses options dans une transaction — il n'a pas d'avis.
 *
 * UNE QUESTION NAÎT BROUILLON, TOUJOURS. `status`, `published_at`,
 * `validator_id` et les drapeaux d'éligibilité sont hors de `$fillable` depuis
 * la revue PAS-10 : aucune charge utile ne peut faire naître une question
 * publiée, quel que soit ce que le client envoie.
 *
 * UNE QUESTION EST MONOLINGUE. Le français et l'arabe sont deux questions
 * distinctes, pas deux champs d'une même — c'est ce que la couverture du PAS-22
 * a rendu visible : « une sœur en français, aucune en arabe » sont deux
 * travaux. `sibling_group` reçoit donc une valeur NEUVE à chaque rédaction :
 * aucun couplage entre langues n'est créé ici. Le jour où un lien sera voulu,
 * il devra être explicite.
 */
final class QuestionAuthoringService
{
    /**
     * @param  array<string, mixed>  $attributs
     * @param  list<array<string, mixed>>  $options
     */
    public function rediger(User $auteur, array $attributs, array $options, ?Source $source = null, ?string $locator = null): Question
    {
        return DB::transaction(function () use ($auteur, $attributs, $options, $source, $locator) {
            $question = Question::create(array_merge($attributs, [
                'author_id' => $auteur->id,
                'sibling_group' => (string) Str::uuid7(),
            ]));

            $this->ecrireOptions($question, $options);

            if ($source !== null) {
                /* `unverified` : citer une source n'est pas la vérifier. La
                 * publication pour diagnostic exige une source VÉRIFIÉE, et
                 * aucun chemin applicatif ne pose aujourd'hui ce drapeau —
                 * voir DET-46. Le poser ici reviendrait à laisser un rédacteur
                 * certifier sa propre source. */
                $question->contentSources()->attach($source->id, [
                    'locator' => $locator,
                    /* L'état de la SOURCE au moment où on la cite. Une source
                     * déjà contrôlée n'a pas à l'être une seconde fois parce
                     * qu'une question de plus s'y appuie (DET-46). */
                    'verification' => $source->estVerifiee() ? 'verified' : 'unverified',
                ]);
            }

            return $question->fresh(['options', 'node', 'exam']);
        });
    }

    /**
     * CORRIGER UNE QUESTION GELÉE — en ouvrir une nouvelle version.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * LE CHEMIN QUE LE PRODUIT ANNONÇAIT SANS L'OFFRIR
     *
     * La modale de publication dit « la publication GÈLE le contenu ; le
     * corriger ensuite demande une nouvelle version ». `amender()` refuse avec
     * la même phrase. La migration `000250` a posé `version` et
     * `supersedes_id` pour cela, en écrivant : « une correction crée une
     * nouvelle version, l'ancienne est retirée ; les tentatives passées
     * continuent de pointer vers la version réellement présentée au candidat ».
     *
     * Ce chemin n'existait pas. Mesuré avant de l'écrire : sur la
     * préproduction, 83 questions, TOUTES en version 1, AUCUNE avec un
     * `supersedes_id`. Corriger une coquille imposait donc de retirer la
     * question et de tout retaper — énoncé, options, une justification par
     * option, une cause par distracteur, le nœud, la source, la difficulté.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * CE QUE LA COPIE EMPORTE, ET CE QU'ELLE LAISSE
     *
     * Elle emporte tout ce qui est du CONTENU : l'énoncé, l'explication, les
     * options avec leurs justifications et leurs causes, le nœud, la
     * difficulté, la remédiation, le miroir désigné, les citations de source.
     *
     * Elle laisse ce qui appartient à l'HISTOIRE de l'ancienne : son
     * relecteur, son valideur, ses dates de publication et de retrait. La
     * nouvelle version repart en brouillon et refait la chaîne — c'est le
     * propos même du gel, et une copie qui arriverait « déjà validée »
     * contournerait la relecture au lieu de la rejouer.
     *
     * `sibling_group` EST CONSERVÉ : les deux versions restent sœurs, donc
     * interchangeables pour la révision espacée.
     *
     * L'ANCIENNE N'EST PAS RETIRÉE ICI. Retirer est un acte distinct, tracé,
     * soumis à `questions.retire` — et tant que la nouvelle version n'est pas
     * publiée, retirer l'ancienne priverait les candidats des deux.
     */
    public function nouvelleVersion(User $auteur, Question $ancienne): Question
    {
        if (! in_array($ancienne->status, ['published', 'retired'], true)) {
            throw new RuntimeException(
                "Une question {$ancienne->status} s’amende directement : une nouvelle version "
                .'ne s’ouvre que sur un contenu gelé.'
            );
        }

        return DB::transaction(function () use ($auteur, $ancienne) {
            $copie = Question::create([
                'exam_id' => $ancienne->exam_id,
                'competency_node_id' => $ancienne->competency_node_id,
                'locale' => $ancienne->locale,
                'sibling_group' => $ancienne->sibling_group,
                'stem' => $ancienne->stem,
                'explanation' => $ancienne->explanation,
                'difficulty' => $ancienne->difficulty,
                'cognitive_level' => $ancienne->cognitive_level,
                'kind' => $ancienne->kind,
                'authoring' => $ancienne->authoring,
                'remediation_id' => $ancienne->remediation_id,
                'mirror_question_id' => $ancienne->mirror_question_id,
                'delayed_review_days' => $ancienne->delayed_review_days,
                'author_id' => $auteur->id,
            ]);

            /*
             * LES CHAMPS DE TRANSITION S'ÉCRIVENT EN FORCE, ET LE MODÈLE A EU
             * RAISON DE ME LE RAPPELER.
             *
             * `version`, `supersedes_id`, `status` et les deux drapeaux
             * d'éligibilité sont hors de `$fillable` depuis le PAS-5 BLOC-1 :
             * ils y étaient assignables en masse, ce qui permettait de créer
             * une question directement en `published` sans qu'aucun contrôle
             * éditorial ne s'exécute. Les passer à `create()` les fait donc
             * ignorer EN SILENCE — mon premier jet posait une copie en version
             * 1 sans lien vers l'originale, et seul le test l'a dit.
             *
             * `forceFill()` est le chemin que `QuestionTransitionService`
             * emprunte déjà pour les mêmes colonnes. Le statut reste `draft` :
             * on force le LIEN de version, jamais un visa.
             */
            $copie->forceFill([
                'version' => $ancienne->version + 1,
                'supersedes_id' => $ancienne->id,
                'eligible_for_diagnostic' => $ancienne->eligible_for_diagnostic,
                'eligible_for_simulation' => $ancienne->eligible_for_simulation,
            ])->save();

            foreach ($ancienne->options()->orderBy('position')->get() as $option) {
                $copie->options()->create([
                    'position' => $option->position,
                    'content' => $option->content,
                    'is_correct' => $option->is_correct,
                    'rationale' => $option->rationale,
                    'cause' => $option->is_correct ? null : $option->cause,
                    'cause_note' => $option->is_correct ? null : $option->cause_note,
                ]);
            }

            /*
             * LES CITATIONS SUIVENT, ET LEUR ÉTAT DE CONTRÔLE EST RELU.
             *
             * On ne recopie pas le `verification` de l'ancienne : une source
             * vérifiée depuis, ou invalidée depuis, doit valoir pour la copie.
             * C'est l'état ACTUEL de la source qui décide, comme à la
             * rédaction (DET-46).
             */
            foreach ($ancienne->contentSources()->get() as $source) {
                $copie->contentSources()->attach($source->id, [
                    'locator' => $source->pivot->locator,
                    'note' => $source->pivot->note,
                    'verification' => $source->estVerifiee() ? 'verified' : 'unverified',
                ]);
            }

            return $copie->fresh(['options', 'node', 'exam']);
        });
    }

    /**
     * Amende un brouillon. Le contenu publié est gelé (ADR-0015 §5).
     *
     * Le contrôle de statut ici est un CONFORT, pas la garantie : le trigger
     * `questions_published_frozen` refuse de toute façon, quel que soit le
     * chemin d'écriture. On le devance seulement pour rendre un refus lisible
     * plutôt qu'une exception de base.
     *
     * @param  array<string, mixed>  $attributs
     * @param  list<array<string, mixed>>|null  $options  remplace TOUTES les options si fourni
     */
    public function amender(Question $question, array $attributs, ?array $options = null): Question
    {
        if (in_array($question->status, ['published', 'retired'], true)) {
            throw new RuntimeException(
                "Le contenu d'une question {$question->status} est gelé : créez une nouvelle version (ADR-0015 §5)."
            );
        }

        return DB::transaction(function () use ($question, $attributs, $options) {
            if ($attributs !== []) {
                $question->update($attributs);
            }

            if ($options !== null) {
                /* Remplacement en bloc plutôt que fusion par position : une
                 * fusion laisserait vivre une option que le rédacteur croyait
                 * avoir supprimée, et l'unicité « une seule bonne réponse » se
                 * jugerait sur un état que personne n'a voulu. */
                $question->options()->delete();
                $this->ecrireOptions($question, $options);
            }

            return $question->fresh(['options', 'node', 'exam']);
        });
    }

    /**
     * Désigner la question miroir — un acte à part, et pas un amendement.
     *
     * POURQUOI CE N'EST PAS `amender()`. Cette méthode-là refuse une question
     * publiée, et ce refus doit rester entier : c'est lui qui empêche qu'un
     * énoncé déjà servi soit récrit par la porte de derrière. Or le pointeur de
     * miroir n'est plus du contenu depuis DET-48 — il désigne l'USAGE, comme
     * `eligible_for_diagnostic`. Deux actes différents, deux méthodes ; élargir
     * `amender()` d'une exception aurait fait dépendre une garantie forte du
     * contenu exact d'un tableau d'attributs.
     *
     * CE QUE CETTE MÉTHODE NE VÉRIFIE PAS, ET OÙ CELA SE VÉRIFIE. Elle ne
     * contrôle ni que la désignée existe, ni qu'elle diffère de la question
     * elle-même : la clé étrangère et `questions_mirror_not_self` le tiennent en
     * base depuis le PAS-5, et les redoubler ici créerait une seconde règle à
     * maintenir. Elle ne contrôle pas non plus la langue ni le statut de la
     * désignée : `QuestionsSoeurs::designee()` l'exige À LA LECTURE et se replie
     * sur le couple sinon (PAS-30) — désigner d'avance une sœur encore en
     * relecture est légitime, et refuser ici l'interdirait.
     *
     * LE RETRAIT RESTE UN MUR. `assert_retired_question_frozen` refuse toute
     * écriture sur une question retirée, celle-ci comprise. Le dire ici évite
     * de rendre une erreur de base à un rédacteur qui n'y comprendrait rien.
     */
    public function designerMiroir(Question $question, ?Question $miroir): Question
    {
        if ($question->status === 'retired') {
            throw new RuntimeException(
                'Une question retirée n\'est plus servie : lui désigner un miroir ne désignerait rien.'
            );
        }

        $question->update(['mirror_question_id' => $miroir?->id]);

        return $question->fresh();
    }

    /** @param  list<array<string, mixed>>  $options */
    private function ecrireOptions(Question $question, array $options): void
    {
        foreach ($options as $i => $option) {
            $question->options()->create([
                'position' => $option['position'] ?? $i + 1,
                'content' => $option['content'],
                'is_correct' => (bool) ($option['is_correct'] ?? false),
                'rationale' => $option['rationale'] ?? null,
                /* La cause est INTERDITE sur la bonne réponse (PAS-5, garde en
                 * base) : on ne la transmet pas plutôt que de laisser la base
                 * refuser une charge utile que le client croyait valide. */
                'cause' => ($option['is_correct'] ?? false) ? null : ($option['cause'] ?? null),
            ]);
        }
    }
}
