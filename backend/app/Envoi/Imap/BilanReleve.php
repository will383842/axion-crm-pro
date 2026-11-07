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

    /** Le lot était plein : il reste peut-être des messages pour le passage suivant. */
    public bool $lotPlein = false;

    public bool $uidValiditeChangee = false;

    /** @var array<string, int> */
    public array $parClasse = [];
}
