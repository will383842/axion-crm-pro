<?php

namespace App\Envoi\Smtp;

use App\Envoi\Contrat\MessageAEnvoyer;

/**
 * Fournit le message DÉJÀ composé et signé DKIM (RFC 5322 brut). L'adaptateur
 * le transmet octet pour octet : il ne le réécrit, ne le re-signe, n'y ajoute
 * ni pixel ni lien traqué.
 */
interface SourceMessageSigne
{
    public function brut(MessageAEnvoyer $message): string;
}
