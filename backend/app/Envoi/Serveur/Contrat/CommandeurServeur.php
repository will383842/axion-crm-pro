<?php

namespace App\Envoi\Serveur\Contrat;

interface CommandeurServeur
{
    /** Faux si ce `commande_id` a déjà été émis (rien n'est renvoyé). */
    public function commander(string $workspaceId, string $source, string $commandeId, string $commande): bool;
}
