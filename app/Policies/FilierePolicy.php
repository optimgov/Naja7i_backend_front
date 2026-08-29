<?php

namespace App\Policies;

use App\Models\Filiere;
use App\Models\User;
use App\Services\PermissionResolver;

/**
 * LIRE ET ÉCRIRE SONT DEUX PERMISSIONS DISTINCTES.
 *
 * `catalogue.view` consulte. `catalogue.manage` modifie ce que les candidats
 * verront : ouvrir une famille, publier une épreuve, changer un coefficient.
 * Les confondre donnerait le second pouvoir à quiconque a le premier.
 *
 * LA SUPPRESSION EST REFUSÉE, TOUJOURS. Une épreuve porte son arbre, ses
 * questions et toutes les tentatives déjà passées ; une famille porte ses
 * épreuves. Effacer l'un de ces objets rendrait l'historique illisible sans
 * qu'aucune erreur ne le signale. Pour retirer une famille de la vue, on la
 * repasse en liste d'attente — le geste est réversible, et il ne détruit rien.
 */
class FilierePolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->peut($user, 'catalogue.view');
    }

    public function view(User $user, Filiere $filiere): bool
    {
        return $this->peut($user, 'catalogue.view');
    }

    public function create(User $user): bool
    {
        return $this->peut($user, 'catalogue.manage');
    }

    public function update(User $user, Filiere $filiere): bool
    {
        return $this->peut($user, 'catalogue.manage');
    }

    public function delete(User $user, Filiere $filiere): bool
    {
        return false;
    }

    private function peut(User $user, string $code): bool
    {
        return in_array($code, $this->permissions->forUser($user), true);
    }
}
