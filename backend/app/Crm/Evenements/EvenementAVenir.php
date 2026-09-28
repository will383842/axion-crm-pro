<?php

namespace App\Crm\Evenements;

/**
 * « A UN ÉVÉNEMENT À VENIR » — une seule définition (relecture de la PR #255,
 * 2026-09-29), lue par l'onglet Fédérations (filtre et pastille) ET par la
 * liste de campagne (événement cité pour personnaliser).
 *
 * Jusqu'ici les deux ne disaient pas la même chose : l'onglet ne retenait que
 * les événements datés, la campagne tout événement sans date. Un club qui se
 * réunit chaque mardi apparaissait « sans événement » à l'écran et « avec »
 * dans le message.
 *
 * Un événement est À VENIR quand :
 *   - il est daté et pas terminé (un salon de trois jours reste à venir
 *     jusqu'à son dernier jour) ;
 *   - ou il est RÉCURRENT sans date (`recurrence` renseignée : « chaque
 *     mardi »). Un événement sans date NI récurrence est une fiche
 *     incomplète, pas un rendez-vous : il n'est pas « à venir ».
 */
final class EvenementAVenir
{
    /**
     * La condition SQL, sur l'alias (ou le nom) de la table `events`. L'alias
     * n'est jamais une donnée utilisateur.
     */
    public static function conditionSql(string $alias = 'events'): string
    {
        return "(COALESCE({$alias}.date_fin, {$alias}.date_debut) >= CURRENT_DATE"
            . " OR ({$alias}.date_debut IS NULL AND {$alias}.recurrence IS NOT NULL))";
    }
}
