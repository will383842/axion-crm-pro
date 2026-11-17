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

    /** Importer des contacts : même droit que l'ajout. */
    public function importer(User $user): bool
    {
        return $user->hasPermissionTo('companies.create');
    }

    /** Exporter des contacts : `data.export` ET `contacts.view_pii` (comme l'export des origines). */
    public function exporter(User $user): bool
    {
        return $user->hasPermissionTo('data.export') && $user->hasPermissionTo('contacts.view_pii');
    }
}
