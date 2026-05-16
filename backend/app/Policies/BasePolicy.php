<?php

namespace App\Policies;

use App\Models\User;

/**
 * Politique commune : owners + admins ont accès complet,
 * operators ont CRUD sauf delete, viewers ont lecture seule.
 * Les policies spécifiques peuvent override ce comportement.
 */
abstract class BasePolicy
{
    public function viewAny(User $user): bool { return $user->hasAnyRole(['owner', 'admin', 'operator', 'viewer']); }
    public function view(User $user, $model): bool { return $this->sameWorkspace($user, $model); }
    public function create(User $user): bool { return $user->hasAnyRole(['owner', 'admin', 'operator']); }
    public function update(User $user, $model): bool { return $this->sameWorkspace($user, $model) && $user->hasAnyRole(['owner', 'admin', 'operator']); }
    public function delete(User $user, $model): bool { return $this->sameWorkspace($user, $model) && $user->hasAnyRole(['owner', 'admin']); }

    protected function sameWorkspace(User $user, $model): bool
    {
        if (! isset($model->workspace_id)) {
            return true;
        }
        return (int) $user->current_workspace_id === (int) $model->workspace_id;
    }
}
