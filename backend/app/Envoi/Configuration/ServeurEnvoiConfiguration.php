<?php

namespace App\Envoi\Configuration;

/**
 * Contrat D4 : le serveur d'envoi vu par le CRM. Le CRM ne pose AUCUN fichier sur ce serveur (autre machine,
 * HTTPS signé, ADR 34 D7) : il lui remet un paquet par espace. Le retour arrière appartient au serveur
 * (il garde la version précédente) : il n'existe donc pas de retablir() ici.
 */
interface ServeurEnvoiConfiguration
{
    /** Validation à côté, sans rien appliquer. */
    public function validerConfiguration(Paquet $paquet): VerdictConfiguration;

    /**
     * @throws ConfigurationRefusee si le serveur refuse (il garde alors la version précédente)
     */
    public function appliquerConfiguration(Paquet $paquet): void;
}
