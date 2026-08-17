<?php

namespace App\Policies;

use App\Models\Question;
use App\Models\User;
use App\Services\PermissionResolver;

/**
 * Traduction du référentiel de permissions vers l'interface — lot A4.
 *
 * CETTE CLASSE NE DÉCIDE RIEN. Elle relaie `PermissionResolver`, seul juge
 * depuis le PAS-9, et ajoute exactement une règle qui n'a pas de permission
 * associée parce qu'elle n'en est pas une : le relecteur n'est pas l'auteur.
 *
 * POURQUOI L'INTERFACE A BESOIN DE POLICIES ALORS QUE LES ROUTES ONT DÉJÀ LEUR
 * MIDDLEWARE. Le middleware refuse une ACTION ; une interface doit décider ce
 * qu'elle MONTRE. Un bouton « valider » affiché à qui ne peut pas valider est
 * une garde qui fonctionne et une interface qui ment — l'échec arrive après le
 * clic, sans que rien n'ait prévenu. Les deux ne se remplacent donc pas : le
 * middleware protège, la policy explique.
 *
 * LA GARDE MÉTIER RESTE DANS LE SERVICE. `QuestionTransitionService::validate()`
 * refuse toujours l'auteur, quoi qu'affiche l'écran. Ce qui est écrit ici n'en
 * est que le reflet, et un reflet ne protège personne.
 */
class QuestionPolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->peut($user, 'questions.view');
    }

    public function view(User $user, Question $question): bool
    {
        return $this->peut($user, 'questions.view');
    }

    public function create(User $user): bool
    {
        return $this->peut($user, 'questions.create');
    }

    /**
     * Amender, et seulement tant que rien n'est gelé.
     *
     * Le gel du contenu publié est tenu EN BASE par trigger (ADR-0015 §5) :
     * cette méthode ne le garantit pas, elle évite qu'un formulaire s'ouvre sur
     * une question que la base refusera d'enregistrer.
     */
    public function update(User $user, Question $question): bool
    {
        return $this->peut($user, 'questions.create')
            && ! in_array($question->status, ['published', 'retired'], true);
    }

    /**
     * Relire — et JAMAIS sa propre question.
     *
     * La paternité manquait ici comme dans le service. Reflet de la garde, pas
     * la garde : c'est `QuestionTransitionService::markReviewed()` qui refuse.
     */
    public function review(User $user, Question $question): bool
    {
        return $this->peut($user, 'questions.review')
            && $question->status === 'a_verifier'
            && $question->author_id !== $user->id;
    }

    /**
     * Valider pédagogiquement — et JAMAIS sa propre question.
     *
     * Règle permanente METHODE §7.2. Elle vit dans le service, qui la refuse en
     * transaction ; elle est répétée ici pour que le bouton n'existe pas, plutôt
     * que d'exister et d'échouer. Une relecture par son auteur n'est pas une
     * relecture — l'écran doit le dire avant le clic, pas après.
     */
    public function validate(User $user, Question $question): bool
    {
        return $this->peut($user, 'questions.validate')
            && $question->status === 'reviewed'
            && $question->author_id !== $user->id
            && $question->reviewer_id !== $user->id;
    }

    public function publish(User $user, Question $question): bool
    {
        return $this->peut($user, 'questions.publish')
            && $question->status === 'pedagogically_validated';
    }

    /**
     * Désigner la question miroir APRÈS publication — DET-48.
     *
     * L'acte n'existe que pour les questions publiées : tant que le contenu est
     * ouvert, le champ est dans le formulaire de rédaction, sous
     * `questions.create` comme le reste de ce qu'on y saisit.
     *
     * `questions.publish`, et pas `questions.create`. Sur une question déjà
     * servie, changer la désignation change ce que des candidats recevront en
     * vérification de leur erreur — c'est la même classe de décision que les
     * drapeaux d'éligibilité, que `publish()` gouverne, et c'est précisément le
     * précédent sur lequel l'arbitrage DET-48 s'appuie. Laisser tout auteur la
     * modifier après coup aurait dégelé plus que la colonne.
     */
    public function designateMirror(User $user, Question $question): bool
    {
        return $this->peut($user, 'questions.publish')
            && $question->status === 'published';
    }

    public function retire(User $user, Question $question): bool
    {
        return $this->peut($user, 'questions.retire')
            && in_array($question->status, ['draft', 'a_verifier', 'reviewed', 'pedagogically_validated', 'published'], true);
    }

    /**
     * Une question ne se supprime pas, elle se RETIRE.
     *
     * Rien dans ce dépôt n'efface une question : une tentative passée pointe
     * vers la version réellement présentée, et `restrictOnDelete` sur
     * `attempt_items.question_id` l'impose déjà en base. Le dire ici évite que
     * Filament n'affiche une corbeille qui ne peut pas fonctionner.
     */
    public function delete(User $user, Question $question): bool
    {
        return false;
    }

    private function peut(User $user, string $code): bool
    {
        return in_array($code, $this->permissions->forUser($user), true);
    }
}
