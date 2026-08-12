<?php

namespace App\Policies;

use App\Models\Source;
use App\Models\User;
use App\Services\PermissionResolver;

/**
 * Autorisations d'interface sur les sources — lot A4.
 *
 * `questions.review` porte la vérification par COMMODITÉ et non par principe,
 * décision du PAS-28 : le relecteur a la source sous les yeux, et créer un rôle
 * de documentaliste pour une équipe qui n'en a pas serait de la cérémonie. Le
 * jour où quelqu'un vérifie des sources sans relire de questions, cette classe
 * est le seul endroit à changer, plus `SourceAdminController`.
 */
class SourcePolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->peut($user, 'questions.view');
    }

    public function view(User $user, Source $source): bool
    {
        return $this->peut($user, 'questions.view');
    }

    public function create(User $user): bool
    {
        return $this->peut($user, 'questions.create');
    }

    /**
     * Modifier une source, en sachant ce que cela coûte.
     *
     * Une modification portant sur une colonne de sens ANNULE la vérification
     * et rétrograde les citations non gelées (PAS-29). L'interface doit le dire
     * avant l'enregistrement, pas le laisser découvrir : c'est la différence
     * entre montrer cet état et le subir.
     */
    public function update(User $user, Source $source): bool
    {
        return $this->peut($user, 'questions.create');
    }

    public function verify(User $user, Source $source): bool
    {
        return $this->peut($user, 'questions.review');
    }

    /**
     * Une source citée ne s'efface pas : `restrictOnDelete` l'impose déjà en
     * base, et une correction déjà servie s'appuie dessus.
     */
    public function delete(User $user, Source $source): bool
    {
        return false;
    }

    private function peut(User $user, string $code): bool
    {
        return in_array($code, $this->permissions->forUser($user), true);
    }
}
