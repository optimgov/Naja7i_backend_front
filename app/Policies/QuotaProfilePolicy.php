<?php

namespace App\Policies;

use App\Models\QuotaProfile;
use App\Models\User;
use App\Services\PermissionResolver;

/**
 * Qui définit les profils de quota — lot 3A.5.
 *
 * UNE SEULE PERMISSION POUR LIRE ET POUR ÉCRIRE, et c'est délibéré. Le
 * précédent de `PlanPolicy` sépare lecture et écriture parce que le rôle
 * finance a besoin de RELIRE une offre pour comprendre une commande. Ici,
 * personne n'a ce besoin : l'admin commerciale ne lira pas cette surface, elle
 * choisira un profil dans une liste que la version d'offre lui présentera.
 * Ouvrir la lecture à `orders.view` donnerait une audience à un écran qui n'en
 * a pas, et affaiblirait la seule chose que ce pas garantit — que le nombre
 * n'est pas décidé par celle qui vend.
 *
 * UN PROFIL NE SE SUPPRIME JAMAIS. Une version d'offre le désignera, et une
 * enveloppe en découlera ; ce qui a été vendu ne s'efface pas. Le déclencheur
 * `quota_profiles_never_deleted` le tient déjà en base — la politique dit la
 * même chose à l'écran, pour que le bouton n'existe pas plutôt qu'il échoue.
 */
class QuotaProfilePolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->peut($user);
    }

    public function view(User $user, QuotaProfile $profile): bool
    {
        return $this->peut($user);
    }

    public function create(User $user): bool
    {
        return $this->peut($user);
    }

    public function update(User $user, QuotaProfile $profile): bool
    {
        return $this->peut($user);
    }

    public function delete(User $user, QuotaProfile $profile): bool
    {
        return false;
    }

    private function peut(User $user): bool
    {
        return in_array('quotas.manage', $this->permissions->forUser($user), true);
    }
}
