<?php

namespace App\Envoi\Imap;

/** Fabrique qui rend toujours le même lecteur (tests et lecteurs mémoire, qui savent déjà tenir plusieurs boîtes). */
final class FabriqueLecteurFixe implements FabriqueLecteurs
{
    public function __construct(private readonly LecteurBoite $lecteur) {}

    public function fabrique(string $boite): LecteurBoite
    {
        return $this->lecteur;
    }
}
