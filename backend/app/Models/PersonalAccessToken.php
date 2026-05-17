<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Override Sanctum PAT model pour supporter `tokenable_id UUID` (cf. migration 000002).
 * Sans ce override, Sanctum cast tokenable_id en int et casse la résolution polymorphique
 * vers `App\Models\User` dont `id` est string UUID.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $casts = [
        'abilities'    => 'json',
        'last_used_at' => 'datetime',
        'expires_at'   => 'datetime',
        // tokenable_id reste string (UUID) — pas de cast int.
    ];
}
