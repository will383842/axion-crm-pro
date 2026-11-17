<?php

namespace App\Envoi\Serveur\Contrat;

interface CommandeurServeur
{
    /**
     * Faux si ce `commande_id` a déjà été émis (rien n'est renvoyé).
     * Contrat v3 : `retrait` EXIGE `$cibles` (1 à 100 UUID distincts, les `id_injection` à retirer) ; `pause` et `reprise` les interdisent.
     *
     * @param  list<string>  $cibles
     */
    public function commander(string $workspaceId, string $source, string $commandeId, string $commande, array $cibles = []): bool;
}
