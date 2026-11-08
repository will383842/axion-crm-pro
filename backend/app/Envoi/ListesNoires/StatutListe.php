<?php

namespace App\Envoi\ListesNoires;

enum StatutListe: string
{
    case NonListe = 'non_liste';
    case Liste = 'liste';
    /** Contrôle impossible ou résolveur public : JAMAIS un vert. */
    case NonFiable = 'non_fiable';
}
