<?php

namespace App\Crm\Emails\Dns;

use RuntimeException;

/**
 * Le résolveur de la suite de tests : il REFUSE. Aucun appel réseau réel en
 * test (même règle que les `MOCK_*`) — un test qui a besoin du DNS installe
 * son résolveur simulé, explicitement (`Tests\Support\ResolveurDnsSimule`).
 */
final class ResolveurDnsInterdit implements ResolveurDns
{
    public function resoudre(array $domaines): array
    {
        throw new RuntimeException(
            'Résolution DNS refusée en environnement de test : installer un résolveur simulé '
            . '(app()->instance(ResolveurDns::class, …)).',
        );
    }

    public function nom(): string
    {
        return 'interdit';
    }
}
