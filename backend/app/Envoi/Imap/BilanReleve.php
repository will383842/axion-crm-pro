<?php

namespace App\Envoi\Imap;

final class BilanReleve
{
    public int $lus = 0;

    public int $nouveaux = 0;

    public int $doublons = 0;

    public int $rattaches = 0;

    public int $aRattacher = 0;

    /** Messages écartés ou dont l'arrêt était inapplicable : « à rattacher », sans effet. */
    public int $enErreur = 0;

    /** Messages en échec pendant CE passage (relus au suivant, sauf mise à l'écart après N tentatives). */
    public int $echecs = 0;

    /** Il reste des UID bruts au-delà de ce lot (un lot filtré n'est pas la fin de la boîte). */
    public bool $lotPlein = false;

    public bool $uidValiditeChangee = false;

    /** @var array<string, int> */
    public array $parClasse = [];
}
