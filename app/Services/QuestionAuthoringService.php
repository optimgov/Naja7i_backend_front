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
                    'verification' => 'unverified',
                ]);
            }

            return $question->fresh(['options', 'node', 'exam']);
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
