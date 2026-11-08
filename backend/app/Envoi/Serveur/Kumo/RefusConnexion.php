<?php

namespace App\Envoi\Serveur\Kumo;

use RuntimeException;

/**
 * Réponse 5xx reçue AVANT toute commande MAIL FROM (bannière, EHLO, STARTTLS) : refus de la CONNEXION (IP non
 * autorisée, serveur indisponible), pas du message. Rien n'a pu partir : report + alerte critique + pause,
 * JAMAIS « echec » terminal du message.
 */
final class RefusConnexion extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('refus 5xx au niveau de la connexion');
    }
}
