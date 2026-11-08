<?php

declare(strict_types=1);

namespace Passerelle;

/** Défaut du palier : aucun appel réel (ENVOI_REEL_ACTIF=false). Le vrai client viendra avec K4c. */
final class KumoInactif implements Kumo
{
    public function delaiMaximal(): int
    {
        return self::DELAI_MAX_SECONDES;
    }

    public function executer(string $workspaceId, string $operation, array $params): array
    {
        throw new \RuntimeException('kumo_inactif');
    }
}
