<?php

namespace App\Envoi\Chauffe;

use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Depot\DepotSocle;
use App\Socle\Alertes\Alerte;

/**
 * « Tout arrêter » : arme le drapeau en base (journalisé) et met le serveur en pause.
 * La passerelle relit le drapeau À CHAQUE injection (jamais en cache, sous le verrou) :
 * dès le retour de `armer()`, plus aucune connexion sortante d'envoi n'est ouverte.
 */
final class ToutArreter
{
    public function __construct(
        private readonly DepotSocle $depot,
        private readonly ?ServeurEnvoi $serveur = null,
        private readonly ?Alerte $alerte = null,
    ) {}

    public function armer(string $workspaceId, string $auteur): void
    {
        $this->depot->armerToutArreter($workspaceId, $auteur);
        try {
            $this->serveur?->pause(); // ceinture : le drapeau en base suffit déjà
        } finally {
            $this->alerte?->envoyer('Tout arrêter armé', "Auteur : {$auteur}. Plus aucune injection pour cet espace.", 'envoi', 'critique');
        }
    }
}
