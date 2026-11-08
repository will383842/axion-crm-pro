<?php

namespace App\Envoi\ListesNoires;

/** Résolveur DNS injecté. Aucun réseau réel dans les tests : on y met un faux. */
interface ResolveurDns
{
    /**
     * Serveurs de noms RÉELLEMENT utilisés pour interroger (ceux du système, pas une valeur de
     * configuration). Le contrôle n'est fiable que si TOUS sont locaux et non relayés.
     *
     * @return list<string>
     */
    public function adresses(): array;

    /**
     * Enregistrements A de $nom ; liste vide si NXDOMAIN (« non listé »).
     *
     * @return list<string>
     */
    public function interroger(string $nom): array;
}
