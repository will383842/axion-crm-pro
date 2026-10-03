<?php

namespace App\Crm\ProvenanceTiers;

use App\Crm\Taxonomy;
use App\Models\User;

/**
 * PROVENANCE DES INFORMATIONS VENUES DE TIERS (N12, 03/10/2026) — la règle,
 * écrite UNE fois.
 *
 * Préparation du futur canal Axion Partners : rien n'écrit encore dans
 * `contacts_provenances_tiers`. Ce qui est déjà vrai :
 *
 *  - une personne apportée par un tiers ne part dans AUCUNE campagne tant que
 *    la version du texte d'information qu'elle a reçu (art. 14 RGPD,
 *    `information_tiers_version`) n'est pas au moins
 *    `VERSION_INFORMATION_MINIMALE` — une version inconnue n'est pas une
 *    version suffisante (`EligibiliteAdresse`, motif
 *    `information_tiers_insuffisante`) ;
 *  - ces données ne se lisent qu'avec le rôle owner : la table n'est servie
 *    que par `ProvenancesTiersController`, et les origines tiers de
 *    `field_origins` sont ôtées des fiches sérialisées pour tout autre rôle
 *    (`MasqueProvenanceTiers`).
 *
 * Les personnes apportées sont ACQUISES par Axion-IA : aucune échéance, aucune
 * mise à l'écart, aucun archivage (décision de Will). Rien ici ne supprime.
 */
final class ProvenanceTiers
{
    /** @var list<string> */
    public const ORIGINES = Taxonomy::FIELD_ORIGINS_TIERS;

    public const VERSION_INFORMATION_MINIMALE = 5;

    /** Vrai quand la version d'information ne suffit pas : inconnue, ou < 5. */
    public static function informationInsuffisante(?int $version): bool
    {
        return $version === null || $version < self::VERSION_INFORMATION_MINIMALE;
    }

    /**
     * Expression SQL booléenne : la personne `$alias.id` a AU MOINS une
     * provenance tiers dont l'information est insuffisante. Une seule suffit
     * — même règle que les occurrences d'une adresse. Lue par l'index
     * `idx_contacts_provenances_tiers_contact`, sous la RLS de l'appelant.
     */
    public static function informationInsuffisanteSql(string $alias): string
    {
        return 'EXISTS (SELECT 1 FROM contacts_provenances_tiers cpt WHERE cpt.contact_id = ' . $alias . '.id'
            . ' AND (cpt.information_tiers_version IS NULL OR cpt.information_tiers_version < '
            . self::VERSION_INFORMATION_MINIMALE . '))';
    }

    /** Seul le rôle owner lit la provenance tiers. */
    public static function lisiblePar(?User $user): bool
    {
        return $user !== null && $user->hasRole('owner');
    }

    /** Le compte courant (requête HTTP) peut-il lire la provenance tiers ? */
    public static function lisible(): bool
    {
        $user = auth()->user();

        return self::lisiblePar($user instanceof User ? $user : null);
    }

    /**
     * `field_origins` sans ses origines tiers : chaque champ dont l'origine est
     * `apporteur`, `commercial` ou `societe` disparaît de la carte — les autres
     * (`declared`, `collected`…) restent. Accepte la carte décodée ou le JSON
     * brut, et rend le même type.
     */
    public static function sansOriginesTiers(mixed $origines): mixed
    {
        $brut = is_string($origines);
        $carte = $brut ? json_decode($origines, true) : $origines;
        if (! is_array($carte)) {
            return $origines;
        }
        $filtree = array_filter($carte, static fn (mixed $o): bool => ! in_array($o, self::ORIGINES, true));
        if (! $brut) {
            return $filtree;
        }

        return (string) json_encode($filtree === [] ? new \stdClass : $filtree, JSON_UNESCAPED_UNICODE);
    }
}
