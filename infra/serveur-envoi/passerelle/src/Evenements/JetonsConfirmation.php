<?php

declare(strict_types=1);

namespace Passerelle\Evenements;

/**
 * Confirmation des jetons du socle K6 : état « reserve » -> « confirme » quand le log hook remonte l'enregistrement
 * de RÉCEPTION du message. DETTE K7-D1 : le lot K6a (jetons.lua) n'est pas fusionné ; seule l'interface existe.
 * Idempotente : rendre false si le jeton est inconnu ou déjà confirmé ; lever une exception si K6 est injoignable.
 */
interface JetonsConfirmation
{
    public function confirmer(string $workspaceId, string $jeton, string $idMessage): bool;
}
