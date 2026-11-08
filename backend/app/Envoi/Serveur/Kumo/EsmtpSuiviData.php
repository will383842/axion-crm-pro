<?php

namespace App\Envoi\Serveur\Kumo;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/** Transport ESMTP qui note l'émission de MAIL FROM (réponses de connexion ≠ réponses du message) et le moment où DATA est accepté (354) : à partir de là, le sort du message est incertain. */
final class EsmtpSuiviData extends EsmtpTransport
{
    public bool $donneesEngagees = false;

    /** Vrai dès que MAIL FROM a été émis : avant, un 5xx concerne la connexion, pas le message. */
    public bool $mailEmis = false;

    public function executeCommand(#[\SensitiveParameter] string $command, array $codes): string
    {
        if (str_starts_with(strtoupper(ltrim($command)), 'MAIL FROM')) {
            $this->mailEmis = true;
        }
        $reponse = parent::executeCommand($command, $codes);
        if (trim($command) === 'DATA') {
            $this->donneesEngagees = true;
        }

        return $reponse;
    }
}
