<?php

namespace App\Envoi\Serveur\Kumo;

use App\Envoi\Contrat\MessageAEnvoyer;

interface TransportInjection
{
    /**
     * Injecte et rend le code SMTP FINAL (2xx, 4xx, 5xx).
     *
     * @throws CoupureTransport coupure ou délai de 30 s sans réponse
     */
    public function envoyer(MessageAEnvoyer $message, string $jeton): int;
}
