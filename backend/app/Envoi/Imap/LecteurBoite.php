<?php

namespace App\Envoi\Imap;

use DateTimeImmutable;

/**
 * Lecture (seule) d'une boîte d'envoi. Aucune écriture, aucun effacement,
 * aucun marquage « lu » dans la boîte : la relève ne modifie jamais la source.
 *
 * Implémentations : `LecteurBoiteMemoire` (tests, aucun réseau) et
 * `LecteurBoiteWebklex` (réelle : EXAMINE + BODY.PEEK[] seulement).
 *
 * Toute panne technique (réseau, protocole) est une EXCEPTION : elle n'écarte jamais un message.
 */
interface LecteurBoite
{
    /** UIDVALIDITY courant du dossier : il change si les UID sont renumérotés. */
    public function uidValidity(string $boite): int;

    /**
     * Au plus $limite UID BRUTS strictement supérieurs à $uidApres (triés), puis — seulement ensuite —
     * filtrés : si $depuis est donné, on ne garde que les messages reçus (INTERNALDATE) à cette date ou après.
     * `LotBoite::dernierUid` / `suite` portent sur les UID bruts, pas sur les messages gardés.
     */
    public function lire(string $boite, int $uidApres, ?DateTimeImmutable $depuis = null, int $limite = ReleveImap::LIMITE_LOT): LotBoite;
}
