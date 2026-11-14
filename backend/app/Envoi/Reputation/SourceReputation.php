<?php

namespace App\Envoi\Reputation;

use DateTimeImmutable;

/**
 * Une source de réputation : lit ce qu'un fournisseur dit d'une ressource d'envoi (IP ou domaine) pour un jour.
 * Les adaptateurs automatiques (Postmaster par API, SNDS par accès à clé) viendront après A11 ; en attendant,
 * la saisie manuelle des constats lus dans les consoles est la voie normale (Orange n'a pas d'API publique connue).
 */
interface SourceReputation
{
    /** @return list<ConstatReputation> constats normalisés ; liste vide si la source n'a rien pour ce jour */
    public function constats(string $workspaceId, string $ressourceType, string $ressource, DateTimeImmutable $jour): array;
}
