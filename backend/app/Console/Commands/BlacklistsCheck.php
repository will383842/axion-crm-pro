<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * REMPLACÉE par K13 : `envoi:listes-noires:controler` (App\Envoi\ListesNoires). Plus planifiée.
 * Conservée pour l'appel manuel ; elle ne contrôle rien et ne se dit plus « propre ».
 */
class BlacklistsCheck extends Command
{
    protected $signature = 'blacklists:check';

    protected $description = 'Vérifie le statut blacklist des IPs sortantes auprès des DNSBL principaux.';

    public function handle(): int
    {
        $this->warn('blacklists:check est remplacée par envoi:listes-noires:controler (K13) : aucun contrôle effectué ici.');

        return self::FAILURE;
    }
}
