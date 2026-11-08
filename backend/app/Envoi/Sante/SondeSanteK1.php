<?php

namespace App\Envoi\Sante;

use App\Envoi\Serveur\ClientServeurEnvoiHmac;

/** Sonde K1 : GET signé HMAC `/v1/sante` (mêmes garde d'hôte, TLS mutuel et interrupteurs que le reste de K1). */
final class SondeSanteK1 implements SondeSante
{
    public function __construct(private readonly ClientServeurEnvoiHmac $client) {}

    public function mesurer(string $workspaceId, string $source): MesuresSante
    {
        $mesures = $this->client->lireSante($workspaceId, $source)['mesures'] ?? null;

        return MesuresSante::depuis(is_array($mesures) ? $mesures : []);
    }
}
