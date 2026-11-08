<?php

declare(strict_types=1);

namespace Passerelle\Evenements;

/** Défaut tant que K6a n'est pas fusionné : ne confirme rien (le jeton reste « jeton_en_attente » au journal). */
final class JetonsNonDisponibles implements JetonsConfirmation
{
    public function confirmer(string $workspaceId, string $jeton, string $idMessage): bool
    {
        return false;
    }
}
