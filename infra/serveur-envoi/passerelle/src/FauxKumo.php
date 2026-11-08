<?php

declare(strict_types=1);

namespace Passerelle;

final class FauxKumo implements Kumo
{
    /** @var list<array{workspace_id:string,operation:string,params:array<string,mixed>}> */
    public array $appels = [];

    public bool $echec = false;

    public function executer(string $workspaceId, string $operation, array $params): array
    {
        if ($this->echec) {
            throw new \RuntimeException('faux_echec');
        }
        $this->appels[] = ['workspace_id' => $workspaceId, 'operation' => $operation, 'params' => $params];

        return ['accepte' => true, 'appel' => count($this->appels)];
    }
}
