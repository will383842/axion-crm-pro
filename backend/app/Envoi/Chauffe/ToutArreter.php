<?php

namespace App\Envoi\Chauffe;

use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Depot\DepotSocle;
use App\Socle\Alertes\Alerte;

/**
 * « Tout arrêter » : arme le drapeau en base (journalisé) et met le serveur en pause.
 * La passerelle relit le drapeau À CHAQUE injection (jamais en cache, sous le verrou) :
 * dès le retour de `armer()`, plus aucune connexion sortante d'envoi n'est ouverte.
 *
 * PAR ESPACE : le drapeau en base est celui d'UN espace. La pause du serveur (`ServeurEnvoi::pause()`),
 * elle, est GLOBALE : le contrat ne prend pas d'espace. C'est une ceinture assumée (« Tout arrêter » =
 * tout). `reprendre()` rend la main au serveur ; les autres espaces armés restent bloqués par leur drapeau.
 * La reprise est une commande locale (`envoi:reprendre`), jamais une route.
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

    /** @return bool faux si rien n'était armé pour cet espace */
    public function reprendre(string $workspaceId, string $auteur): bool
    {
        if (! $this->depot->leverToutArreter($workspaceId, $auteur)) {
            return false;
        }
        $this->serveur?->reprise();
        $this->alerte?->envoyer('Tout arrêter levé', "Auteur : {$auteur}. L’envoi peut reprendre pour cet espace (les freins et le quota restent appliqués).", 'envoi', 'critique');

        return true;
    }
}
