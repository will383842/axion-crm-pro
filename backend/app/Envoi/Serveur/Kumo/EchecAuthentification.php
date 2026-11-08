<?php

namespace App\Envoi\Serveur\Kumo;

use RuntimeException;

/**
 * Échec d'AUTHENTIFICATION ou de CERTIFICAT du tunnel (AUTH 535, certificat ou nom refusé). Classé À PART : ce n'est
 * ni un report ordinaire ni un refus de message. Aucune cause chaînée et aucun détail (le message d'origine contient
 * l'identifiant) : rien à fuiter. L'adaptateur journalise en critique et PAUSE, jamais de réessai périodique qui
 * ressemblerait à du forçage d'identifiants.
 */
final class EchecAuthentification extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('échec d’authentification ou de certificat du tunnel d’envoi');
    }
}
