<?php

namespace App\Envoi\Sante;

use App\Envoi\Serveur\ClientServeurEnvoiHmac;
use App\Envoi\Serveur\ServeurEnvoiRefuse;

/** Sonde K1 : GET signé HMAC `/v1/sante` (mêmes garde d'hôte, TLS mutuel et interrupteurs que le reste de K1). */
final class SondeSanteK1 implements SondeSante
{
    public function __construct(private readonly ClientServeurEnvoiHmac $client) {}

    public function mesurer(string $workspaceId, string $source): MesuresSante
    {
        $mesures = $this->client->lireSante($workspaceId, $source)['mesures'] ?? null;
        $lues = MesuresSante::depuis(is_array($mesures) ? $mesures : []);
        // Contrat /v1/sante (envoi-serveur/contrat/README.md) : une réponse signée SANS mesure lisible n'est pas un battement.
        if ($lues->estVide()) {
            throw ServeurEnvoiRefuse::motif('réponse /v1/sante sans mesure valide.');
        }

        return $lues;
    }
}
