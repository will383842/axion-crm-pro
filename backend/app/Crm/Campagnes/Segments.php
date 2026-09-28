<?php

namespace App\Crm\Campagnes;

use App\Crm\FichesProtegees;
use InvalidArgumentException;

/**
 * LES SEGMENTS QU'UNE CAMPAGNE PEUT VISER — liste FERMÉE (2026-09-27).
 *
 * Un seul mécanisme d'extraction pour tout le CRM, mais chaque catégorie de
 * contacts s'ouvre par une DÉCISION de Will, jamais par défaut :
 *
 *  - `organisateurs-evenements` : OUVERT (décision du 27/09) — les fiches de
 *    `FichesProtegees` venues des événements. Les lever de leur protection
 *    pour CE chemin est précisément la décision explicite que
 *    `FichesProtegees` exige.
 *  - `federations` : OUVERT (décision du 28/09, « ouvert dès l'import ») — les
 *    fédérations, ordres, chambres, syndicats. Les organismes de pertinence
 *    FAIBLE en sont écartés par défaut (décision du 28/09 : « petits
 *    organismes sans salarié ni site… exclus des campagnes par défaut ») ;
 *    `--avec-pertinence-faible` les réintègre, en connaissance de cause.
 *    Les SYNDICATS DE SALARIÉS en sont écartés aussi par défaut (décision de
 *    Will du 28/09) : l'appartenance syndicale est une donnée de l'article 9
 *    du RGPD. Base retenue pour les viser, sur option explicite
 *    (`--avec-syndicats-salaries`) : art. 9.2.e — coordonnées rendues
 *    manifestement publiques par les responsables syndicaux, message lié à
 *    leur fonction. Une fiche sans classement connu n'est jamais visée.
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

    public const FEDERATIONS = 'federations';

    /** @var list<string> */
    public const OUVERTS = [self::ORGANISATEURS_EVENEMENTS, self::FEDERATIONS];

    /**
     * Le tag qui désigne les fiches d'un segment — désigné par son NOM.
     *
     * Jusqu'au 2026-09-29, le segment « organisateurs » était « le premier tag
     * de `FichesProtegees::TAGS` » : ajouter une catégorie protégée en tête de
     * liste aurait fait viser à la campagne des fiches que Will n'avait pas
     * ouvertes (constat P2 de l'audit du 28/09).
     */
    public static function tag(string $segment): string
    {
        return match ($segment) {
            self::ORGANISATEURS_EVENEMENTS => FichesProtegees::TAG_ORGANISATEURS,
            self::FEDERATIONS => FichesProtegees::TAG_FEDERATIONS,
            default => throw new InvalidArgumentException("Segment inconnu : « {$segment} »."),
        };
    }

    /** Le tag qui désigne les fiches du segment « organisateurs ». */
    public static function tagOrganisateurs(): string
    {
        return self::tag(self::ORGANISATEURS_EVENEMENTS);
    }
}
