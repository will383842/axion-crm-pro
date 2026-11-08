<?php

namespace App\Envoi\ListesNoires;

/**
 * Lecture, côté autorisation d'envoi, des pauses posées par K13 (ou « non fiable » trop longtemps).
 *
 * Domaines : ressource = nom du domaine d'envoi de l'espace (table envoi_domaines).
 * IP : le CRM ne connaît PAS les IP d'envoi en base (aucune table) ; elles sont déclarées dans
 * `envoi.listes_noires.ips` (« ip@uuid-espace »). Une demande d'envoi ne désigne pas l'IP qui
 * l'enverra : l'espace est donc refusé dès qu'UNE de ses IP déclarées est en pause (prudent).
 * Une IP non déclarée n'est pas surveillée, donc pas couverte par cette garde.
 */
final class GardeListes
{
    public function __construct(private readonly DepotControles $depot) {}

    public function domaineEnPause(string $workspaceId, string $domaine): bool
    {
        return $this->depot->enPause(new Ressource($workspaceId, Ressource::DOMAINE, mb_strtolower(trim($domaine))));
    }

    public function ipEnPause(string $workspaceId): bool
    {
        foreach ((array) config('envoi.listes_noires.ips', []) as $entree) {
            [$ip, $ws] = array_pad(explode('@', (string) $entree, 2), 2, '');
            if (strtolower(trim($ws)) === strtolower($workspaceId) && trim($ip) !== ''
                && $this->depot->enPause(new Ressource($workspaceId, Ressource::IP, trim($ip)))) {
                return true;
            }
        }

        return false;
    }
}
