<?php

namespace App\Policies;

use App\Models\User;

class ContactPolicy extends BasePolicy
{
    /** Modifier un contact : même droit que l'entreprise (`BasePolicy` raisonne par rôles, ici par permission). */
    public function modifier(User $user): bool
    {
        return $user->hasPermissionTo('companies.update');
    }

    /** Retirer / restaurer un contact (corbeille douce) : même droit que la suppression d'une entreprise. */
    public function retirer(User $user): bool
    {
        return $user->hasPermissionTo('companies.delete');
    }

    /** Ajouter un contact : même droit que la création d'une entreprise. */
    public function creer(User $user): bool
    {
        return $user->hasPermissionTo('companies.create');
    }
}
