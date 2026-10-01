<?php

namespace App\Crm\Campagnes;

use App\Crm\FichesProtegees;
use InvalidArgumentException;

/**
 * LES SEGMENTS QU'UNE CAMPAGNE PEUT VISER — liste FERMÉE (2026-09-27) :
 * le CRM n'extrait que les segments qu'il CONNAÎT (`CONNUS`).
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
 *  - `presse` : OUVERT (décision de Will du 01/10/2026 ; défini le 30/09) —
 *    les médias et journalistes harmonisés (`crm:presse:harmoniser`,
 *    `crm:presse:importer`), désignés par leur tag de provenance protégé.
 *    Une seule porte : `crm:campagne:destinataires presse`. Et, dans ce
 *    segment, une adresse ne part que si sa PROVENANCE est fiable
 *    (`AdressePresseFiable` : jamais une adresse tirée d'un site DEVINÉ non
 *    vérifié, jamais un journaliste sans la porte `email_redaction`).
 *    Ouvrir la presse ne la fait entrer dans AUCUN autre chemin : audiences,
 *    listes manuelles, export, waterfall, autres segments l'écartent
 *    toujours (`GardePresse`) — un journaliste n'entre que par SON segment.
 *  - prospects INSEE : FERMÉS tant que Will ne les ouvre pas (volume, chauffe
 *    d'IP).
 *  - vivier candidats, personnes de la lettre : JAMAIS — les premiers ne sont
 *    pas des prospects, les seconds partent du site sur leur propre liste.
 *
 * L'OUVERTURE se règle sans déployer de code : `config('crm.segments_ouverts')`
 * (`CRM_SEGMENTS_OUVERTS`, liste séparée par des virgules ; absente ou vide =
 * `OUVERTS_PAR_DEFAUT`, `aucun` = tout fermé). Refermer la presse :
 * `CRM_SEGMENTS_OUVERTS=organisateurs-evenements,federations`.
 *
 * Aucune ligne de ce fichier n'envoie quoi que ce soit : le CRM PRÉPARE la
 * liste et ENREGISTRE les retours ; l'envoi appartient à l'outil de Will.
 */
final class Segments
{
    public const ORGANISATEURS_EVENEMENTS = 'organisateurs-evenements';

    public const FEDERATIONS = 'federations';

    /** Médias et journalistes — OUVERT par défaut (décision de Will du 01/10/2026). */
    public const PRESSE = 'presse';

    /** Tous les segments que le CRM sait extraire. @var list<string> */
    public const CONNUS = [self::ORGANISATEURS_EVENEMENTS, self::FEDERATIONS, self::PRESSE];

    /** Ouverts quand `crm.segments_ouverts` n'est pas réglé. @var list<string> */
    public const OUVERTS_PAR_DEFAUT = self::CONNUS;

    /** Valeur de `crm.segments_ouverts` qui ferme TOUS les segments. */
    public const AUCUN = 'aucun';

    /**
     * Les segments OUVERTS, lus dans la configuration (`crm.segments_ouverts`).
     *
     * Une valeur inconnue est ignorée : une faute de frappe ne peut que
     * FERMER, jamais ouvrir un segment que le CRM ne connaît pas.
     *
     * @return list<string>
     */
    public static function ouverts(): array
    {
        $brut = config('crm.segments_ouverts');
        if (is_array($brut)) {
            $valeurs = array_map(static fn ($v): string => is_string($v) ? trim($v) : '', $brut);
        } else {
            $texte = is_string($brut) ? trim($brut) : '';
            if ($texte === '') {
                return self::OUVERTS_PAR_DEFAUT;
            }
            $valeurs = array_map('trim', explode(',', $texte));
        }

        return array_values(array_filter(self::CONNUS, static fn (string $s): bool => in_array($s, $valeurs, true)));
    }

    public static function ouvert(string $segment): bool
    {
        return in_array($segment, self::ouverts(), true);
    }

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
            self::PRESSE => FichesProtegees::TAG_PRESSE,
            default => throw new InvalidArgumentException("Segment inconnu : « {$segment} »."),
        };
    }

    /** Le tag qui désigne les fiches du segment « organisateurs ». */
    public static function tagOrganisateurs(): string
    {
        return self::tag(self::ORGANISATEURS_EVENEMENTS);
    }
}
