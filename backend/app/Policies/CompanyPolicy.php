<?php

namespace App\Policies;

use App\Models\User;

class CompanyPolicy extends BasePolicy
{
    /**
     * Action de MASSE (poser/retirer une étiquette, changer d'étape sur une
     * sélection) : exige le droit d'ÉDITER les entreprises, comme l'action
     * unitaire. Un compte en lecture seule — ou sans rôle — ne modifie rien.
     */
    public function actionDeMasse(User $user): bool
    {
        return $user->hasPermissionTo('companies.update');
    }
}
