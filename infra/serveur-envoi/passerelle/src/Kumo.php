<?php

declare(strict_types=1);

namespace Passerelle;

/** Appel local de KumoMTA. Les tests utilisent FauxKumo : aucun envoi réel. */
interface Kumo
{
    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     *
     * @throws \RuntimeException si KumoMTA refuse ou est injoignable
     */
    public function executer(string $workspaceId, string $operation, array $params): array;
}
