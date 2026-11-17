<?php

namespace App\Services\Auth\CodeCourriel;

use App\Models\User;

/** Le canal qui porte un code de connexion jusqu'au titulaire du compte. */
interface CanalCodeConnexion
{
    public function envoyer(User $u, string $code, string $objet): void;

    /** Vrai seulement si le canal envoie un vrai courriel. */
    public function estReel(): bool;
}
