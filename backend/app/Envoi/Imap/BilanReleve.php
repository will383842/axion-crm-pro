<?php

namespace App\Envoi\Imap;

final class BilanReleve
{
    public int $lus = 0;

    public int $nouveaux = 0;

    public int $doublons = 0;

    public int $rattaches = 0;

    public int $aRattacher = 0;

    public bool $uidValiditeChangee = false;

    /** @var array<string, int> */
    public array $parClasse = [];
}
