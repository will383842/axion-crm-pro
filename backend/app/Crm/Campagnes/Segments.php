<?php

namespace App\Crm\Campagnes;

use App\Crm\FichesProtegees;

/**
 * LES SEGMENTS QU'UNE CAMPAGNE PEUT VISER — liste FERMÉE (2026-09-27).
 *
 * Un seul mécanisme d'extraction pour tout le CRM, mais chaque catégorie de
 * contacts s'ouvre par une DÉCISION de Will, jamais par défaut :
 *
 *  - `organisateurs-evenements` : OUVERT (décision du 27/09) — les fiches de
 *    `FichesProtegees`. Les lever de leur protection pour CE chemin est
 *    précisément la décision explicite que `FichesProtegees` exige.
 *  - prospects INSEE, journalistes/médias : FERMÉS tant que Will ne les ouvre
 *    pas (volume, chauffe d'IP, autre usage).
 *  - vivier candidats, personnes de la lettre : JAMAIS — les premiers ne sont
 *    pas des prospects, les seconds partent du site sur leur propre liste.
 *
 * Aucune ligne de ce fichier n'envoie quoi que ce soit : le CRM PRÉPARE la
 * liste et ENREGISTRE les retours ; l'envoi appartient à l'outil de Will.
 */
final class Segments
{
    public const ORGANISATEURS_EVENEMENTS = 'organisateurs-evenements';

    /** @var list<string> */
    public const OUVERTS = [self::ORGANISATEURS_EVENEMENTS];

    /** Le tag qui désigne les fiches du segment « organisateurs ». */
    public static function tagOrganisateurs(): string
    {
        return FichesProtegees::TAGS[0];
    }
}
