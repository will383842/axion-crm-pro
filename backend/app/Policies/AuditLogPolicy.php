<?php

namespace App\Policies;

use App\Models\User;

class AuditLogPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('owner');
    }
}
