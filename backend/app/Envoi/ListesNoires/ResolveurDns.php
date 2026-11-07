<?php

namespace App\Envoi\ListesNoires;

/** Résolveur DNS injecté. Aucun réseau réel dans les tests : on y met un faux. */
interface ResolveurDns
{
    /** Adresse du résolveur interrogé (sert à détecter un résolveur public). */
    public function adresse(): string;

    /**
     * Enregistrements A de $nom ; liste vide si NXDOMAIN (« non listé »).
     *
     * @return list<string>
     */
    public function interroger(string $nom): array;
}
