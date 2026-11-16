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
}
