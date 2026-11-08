<?php

namespace App\Envoi\Serveur\Kumo;

use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Serveur\ServeurEnvoiRefuse;

interface TransportInjection
{
    /**
     * Injecte et rend le code SMTP FINAL (2xx, 4xx, 5xx).
     *
     * @param  string  $source  source du serveur d'envoi (en-tête X-Envoi-Source, contrat K4b)
     *
     * @throws CoupureTransport coupure ou délai de 30 s sans réponse
     * @throws EchecAuthentification AUTH ou certificat refusé
     * @throws ServeurEnvoiRefuse refus local (enveloppe, jeton, transaction)
     */
    public function envoyer(MessageAEnvoyer $message, string $jeton, string $source = ''): int;
}
