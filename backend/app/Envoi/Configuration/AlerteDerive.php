<?php

namespace App\Envoi\Configuration;

interface AlerteDerive
{
    /** @param list<string> $cles clés du socle visées par le paquet */
    public function signaler(string $workspaceId, array $cles): void;
}
