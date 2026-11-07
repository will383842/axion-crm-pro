<?php

namespace App\Envoi\Smtp;

/** Résolution DNS d'un hôte SMTP. Remplaçable en test : aucun réseau réel. */
interface ResolveurHote
{
    /** @return list<string> adresses IP (v4 et v6) de l'hôte ; liste vide si introuvable */
    public function resoudre(string $hote): array;
}
