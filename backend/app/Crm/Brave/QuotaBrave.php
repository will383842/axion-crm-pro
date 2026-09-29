<?php

namespace App\Crm\Brave;

/**
 * LE CRÉDIT GRATUIT MENSUEL DE BRAVE — le contrat du compteur.
 *
 * Budget de Will : ZÉRO euro. Toute requête à l'API Brave Search est RÉSERVÉE
 * ici avant d'être envoyée, et elle ne part pas si la réservation échoue.
 * `RechercheBrave` est le SEUL émetteur de requêtes Brave du dépôt
 * (`BraveUnSeulEmetteurTest` le garde), et il appelle `reserver()`.
 *
 * Deux usages, chacun avec son sous-quota mensuel, sous un plafond GLOBAL que
 * leur total ne dépasse jamais :
 *  - `federations`    : `crm:federations:trouver-sites` (CRM_BRAVE_QUOTA_FEDERATIONS, 900) ;
 *  - `enrichissement` : `DomainFinderService::find()`, donc l'enrichissement
 *    (CRM_BRAVE_QUOTA_ENRICHISSEMENT, **0** : l'enrichissement saute Brave,
 *    comme avant que la clé soit posée) ;
 *  - plafond global   : CRM_BRAVE_QUOTA_MENSUEL (900).
 *
 * Deux implémentations : `QuotaBraveEnBase` (production, table
 * `brave_quota_mensuel`) et un compteur en mémoire pour les tests unitaires,
 * qui n'ont pas de base. La RÈGLE est commune (`PlafondsBrave`).
 */
interface QuotaBrave
{
    public const FEDERATIONS = 'federations';

    public const ENRICHISSEMENT = 'enrichissement';

    /** @var list<string> */
    public const USAGES = [self::FEDERATIONS, self::ENRICHISSEMENT];

    /**
     * Réserve UNE requête du mois pour cet usage. `false` = sous-quota ou
     * plafond global atteint (ou usage inconnu) : NE PAS envoyer.
     */
    public function reserver(string $usage): bool;

    /** Requêtes du mois : pour cet usage, ou au total si `null`. */
    public function consommees(?string $usage = null): int;

    /** Plafond du mois : le sous-quota de cet usage, ou le plafond global si `null`. */
    public function plafond(?string $usage = null): int;

    /** Ce que cet usage peut encore envoyer ce mois-ci (sous-quota ET plafond global). */
    public function restantes(string $usage): int;
}
