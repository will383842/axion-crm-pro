<?php

declare(strict_types=1);

namespace Passerelle\Evenements;

/**
 * Confirmation des jetons du socle K6 : état « reserve » -> « confirme » quand le log hook remonte l'enregistrement
 * de RÉCEPTION du message. DETTE K7-D1 : le lot K6a (jetons.lua) n'est pas fusionné ; seule l'interface existe.
 * Idempotente : rendre TRUE si le jeton est confirmé (à l'instant ou déjà : le rejeu est sans effet), FALSE s'il est inconnu
 * ou si K6 n'est pas disponible ; lever une exception si K6 est injoignable. Tant que ce n'est pas TRUE, le jeton est rejoué.
 */
interface JetonsConfirmation
{
    public function confirmer(string $workspaceId, string $jeton, string $idMessage): bool;
}
