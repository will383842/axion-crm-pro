<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy extends BasePolicy
{
    /**
     * Le rôle « owner » ne se donne que depuis un owner. Un admin ne peut
     * JAMAIS nommer un owner, ni par invitation ni par changement de rôle.
     */
    public function nommerRole(User $acteur, string $role): bool
    {
        if ($role !== 'owner') {
            return true;
        }

        return $acteur->hasRole('owner');
    }

    /**
     * Un owner est PROTÉGÉ : seul un owner peut modifier son rôle, son profil
     * ou fermer son compte.
     */
    public function gererCompte(User $acteur, User $cible): bool
    {
        if (! $cible->hasRole('owner')) {
            return true;
        }

        return $acteur->hasRole('owner');
    }
}
