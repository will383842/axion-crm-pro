<?php

namespace App\Envoi\Imap;

use DateTimeImmutable;

/**
 * Lecture (seule) d'une boîte d'envoi. Aucune écriture, aucun effacement,
 * aucun marquage « lu » dans la boîte : la relève ne modifie jamais la source.
 *
 * Implémentations : `LecteurBoiteMemoire` (tests, aucun réseau) et
 * `LecteurBoiteWebklex` (réelle).
 */
interface LecteurBoite
{
    /** UIDVALIDITY courant du dossier : il change si les UID sont renumérotés. */
    public function uidValidity(string $boite): int;

    /**
     * Messages dont l'UID est strictement supérieur à $uidApres et, si $depuis
     * est donné, reçus (INTERNALDATE) à cette date ou après. Triés par UID
     * croissant, au plus $limite messages (le reste vient au passage suivant).
     *
     * @return list<MessageBrut>
     */
    public function lire(string $boite, int $uidApres, ?DateTimeImmutable $depuis = null, int $limite = ReleveImap::LIMITE_LOT): array;
}
