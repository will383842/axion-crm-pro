<?php

namespace App\Crm\ProvenanceTiers;

use App\Crm\Taxonomy;
use App\Models\User;
use InvalidArgumentException;

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

    /**
     * Le texte de version tel que le contrat Partners l'émet
     * (`VERSION_INFORMATION_ARTICLE_14` = `information-article-14/v5`). La
     * version est stockée TELLE QUE REÇUE ; seul ce préfixe suivi d'un numéro
     * est reconnu. Tout autre format vaut « inconnue » : un « 5 » venu d'un
     * autre texte ne passe pas pour suffisant.
     */
    public const PREFIXE_VERSION = 'information-article-14/v';

    /** Le motif reconnu, en PHP (`/u` absent : octets ASCII seulement) et en SQL (POSIX). */
    private const MOTIF_VERSION_PHP = '#^information-article-14/v([0-9]{1,6})$#D';

    private const MOTIF_VERSION_SQL = '^information-article-14/v([0-9]{1,6})$';

    /** Le numéro d'une version reçue, ou null si elle est absente ou d'un autre format. */
    public static function numeroVersion(?string $version): ?int
    {
        if ($version === null || preg_match(self::MOTIF_VERSION_PHP, $version, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /** Vrai quand la version d'information ne suffit pas : inconnue, d'un autre format, ou < 5. */
    public static function informationInsuffisante(?string $version): bool
    {
        $numero = self::numeroVersion($version);

        return $numero === null || $numero < self::VERSION_INFORMATION_MINIMALE;
    }

    /**
     * Expression SQL booléenne : la personne désignée par `$alias.$colonne`
     * (par défaut `contacts.id` ; `personnes.contact_id` pour l'export des
     * personnes) a AU MOINS une provenance tiers dont l'information est
     * insuffisante. Une seule suffit — même règle que les occurrences d'une
     * adresse. Miroir SQL d'`informationInsuffisante()` : `substring()` rend
     * NULL hors format, donc 0. Lue par l'index
     * `idx_contacts_provenances_tiers_contact`, sous la RLS de l'appelant.
     *
     * @throws InvalidArgumentException alias ou colonne hors identifiant simple
     */
    public static function informationInsuffisanteSql(string $alias, string $colonne = 'id'): string
    {
        self::identifiant($alias);
        self::identifiant($colonne);

        return 'EXISTS (SELECT 1 FROM contacts_provenances_tiers cpt WHERE cpt.contact_id = ' . $alias . '.' . $colonne
            . " AND COALESCE(substring(cpt.information_tiers_version FROM '" . self::MOTIF_VERSION_SQL . "')::int, 0) < "
            . self::VERSION_INFORMATION_MINIMALE . ')';
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

    /**
     * Un alias ou une colonne concaténé dans du SQL : identifiant simple
     * seulement (patron `QuarantaineSite::alias`). Les appelants sont internes ;
     * la garde ferme quand même la porte.
     *
     * @throws InvalidArgumentException
     */
    private static function identifiant(string $nom): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $nom) !== 1) {
            throw new InvalidArgumentException('Identifiant SQL refusé : ' . json_encode($nom));
        }
    }
}
