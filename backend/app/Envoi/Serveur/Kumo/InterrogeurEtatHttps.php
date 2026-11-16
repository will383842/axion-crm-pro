<?php

namespace App\Envoi\Serveur\Kumo;

use App\Envoi\Contrat\EtatMessage;
use App\Envoi\Serveur\ClientServeurEnvoiHmac;

/** Interroge la passerelle (`GET /v1/injections/{id}`) : lecture seule, jamais d'envoi. */
final class InterrogeurEtatHttps implements InterrogeurEtat
{
    public function __construct(private readonly ClientServeurEnvoiHmac $client, private readonly string $workspaceId, private readonly string $source) {}

    public function etat(string $idInjection): EtatMessage
    {
        $json = $this->client->etatInjection($this->workspaceId, $this->source, $idInjection)['json'];

        return match ($json['etat'] ?? null) {
            'en_file' => EtatMessage::EnFile,
            'refuse', 'retire' => EtatMessage::Refuse,
            default => EtatMessage::Inconnu, // incertain, inconnu, valeur imprévue
        };
    }
}
