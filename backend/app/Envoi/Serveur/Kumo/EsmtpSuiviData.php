<?php

namespace App\Envoi\Serveur\Kumo;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/** Transport ESMTP qui note le moment où DATA est accepté (354) : à partir de là, le sort du message est incertain. */
final class EsmtpSuiviData extends EsmtpTransport
{
    public bool $donneesEngagees = false;

    public function executeCommand(string $command, array $codes): string
    {
        $reponse = parent::executeCommand($command, $codes);
        if (trim($command) === 'DATA') {
            $this->donneesEngagees = true;
        }

        return $reponse;
    }
}
