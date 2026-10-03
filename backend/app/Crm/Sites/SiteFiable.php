<?php

namespace App\Crm\Sites;

use App\Crm\Presse\SiteMedia;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * LE SITE D'UNE ENTREPRISE EST-IL VÉRIFIÉ ? — une seule définition (lot N4
 * « fermer le robinet », 03/10/2026).
 *
 * Constat mesuré en production : 824 306 fiches portent un site DEVINÉ
 * (`companies.website_method` = `guess` 562 946, `guess2` 261 360), contre
 * ~6 000 trouvés autrement. Beaucoup sont faux (france.fr sur 3 878 fiches,
 * maison.fr 2 974, paris.fr 2 232…) : l'ancienne vérification de
 * `DomainFinderService::verifyBody()` acceptait une page dès que deux mots du
 * nom y apparaissaient n'importe où.
 *
 * ── LA RÈGLE ─────────────────────────────────────────────────────────────
 *
 * Un site est NON VÉRIFIÉ si :
 *   1. `website_method` commence par `guess` (`guess`, `guess2`, et toute
 *      future méthode de devinette, qui DOIT garder ce préfixe) ;
 *   2. ET la fiche ne porte pas de marqueur de vérification positif :
 *      `companies.metadata.site_entreprise.statut` ∈ (`verifie`,
 *      `trouve-verifie`) — le MÊME vocabulaire que `metadata.site_media`
 *      ({@see SiteMedia::STATUTS_VERIFIES}). Ce marqueur sera posé par le
 *      futur lot de vérification ; aujourd'hui, personne ne l'écrit.
 *
 * La règle est DÉDUITE : aucune des 824 000 lignes n'est réécrite, rien
 * n'est effacé. Un site jamais deviné (Brave, annuaire, import, saisie) n'est
 * pas « non vérifié » au sens de cette règle.
 *
 * ── LE SQL ───────────────────────────────────────────────────────────────
 *
 * `nonVerifieSql()` est reprise MOT POUR MOT par le prédicat de l'index
 * partiel `idx_companies_site_non_verifie` (migration
 * `2026_10_03_000060_companies_index_site_non_verifie`). Les opérateurs JSON
 * ne sont pas « leakproof » : sous la sécurité par espace forcée
 * (`axion_app`), Postgres ne peut évaluer la condition qu'après la politique,
 * ligne à ligne, sur le tas — sauf si un index partiel la porte déjà. Toute
 * réécriture de cette condition (`COALESCE` déplacé, `IN` au lieu de
 * `NOT IN`, préfixe changé) DOIT être reportée dans l'index, sinon il cesse
 * de servir, sans aucune erreur. Le test `SitesDevinesNonVerifiesTest` lit le
 * plan sous `axion_app` et rougit si c'est le cas.
 *
 * Aucun argument des méthodes SQL n'est une donnée utilisateur (alias de
 * table seulement, gardés par `^[a-z_][a-z0-9_]*$` : exception sinon).
 * Alias internes `sf_*` réservés.
 */
final class SiteFiable
{
    /** Clé du marqueur dans `companies.metadata`. */
    public const CLE = 'site_entreprise';

    /** Les statuts qui valent vérification — même vocabulaire que `metadata.site_media`. */
    public const STATUTS_VERIFIES = SiteMedia::STATUTS_VERIFIES;

    /** Préfixe commun à TOUTES les méthodes de découverte par devinette. */
    public const PREFIXE_DEVINE = 'guess';

    /** Devinette, 1er passage (`prospection:find-websites`, `media:find-websites`, enrichissement). */
    public const METHODE_DEVINEE = 'guess';

    /** Devinette, 2e passage (`prospection:find-websites --retry`, candidats étendus). */
    public const METHODE_DEVINEE_ETENDUE = 'guess2';

    /** La méthode de découverte est une devinette (préfixe `guess`). */
    public static function estMethodeDevinee(?string $methode): bool
    {
        return $methode !== null && str_starts_with($methode, self::PREFIXE_DEVINE);
    }

    /**
     * Le marqueur `metadata.site_entreprise` (tableau, JSON brut ou null)
     * vaut-il vérification ?
     */
    public static function marqueurVerifie(mixed $metadata): bool
    {
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }
        $marqueur = is_array($metadata) ? ($metadata[self::CLE] ?? null) : null;

        return is_array($marqueur) && in_array($marqueur['statut'] ?? null, self::STATUTS_VERIFIES, true);
    }

    /** Miroir en mémoire de `nonVerifieSql()`. */
    public static function estNonVerifie(?string $methode, mixed $metadata): bool
    {
        return self::estMethodeDevinee($methode) && ! self::marqueurVerifie($metadata);
    }

    /**
     * SQL : la fiche `$alias` porte un site DEVINÉ et NON VÉRIFIÉ.
     *
     * ⚠️ Texte repris à l'identique par le prédicat de l'index partiel
     * `idx_companies_site_non_verifie` — voir l'en-tête.
     */
    public static function nonVerifieSql(string $alias = 'companies'): string
    {
        self::alias($alias);

        return "({$alias}.website_method LIKE '" . self::PREFIXE_DEVINE . "%'"
            . " AND COALESCE({$alias}.metadata -> '" . self::CLE . "' ->> 'statut', '') NOT IN (" . self::statutsSql() . '))';
    }

    /**
     * SQL : le site de la fiche `$alias` n'est PAS « non vérifié » — jamais
     * deviné (ou absent), ou deviné puis vérifié. Négation exacte de
     * `nonVerifieSql()`, `website_method` NULL compris.
     */
    public static function fiableSql(string $alias = 'companies'): string
    {
        self::alias($alias);

        return "(COALESCE({$alias}.website_method, '') NOT LIKE '" . self::PREFIXE_DEVINE . "%'"
            . " OR COALESCE({$alias}.metadata -> '" . self::CLE . "' ->> 'statut', '') IN (" . self::statutsSql() . '))';
    }

    /**
     * SQL : la personne `$aliasContact` a été trouvée dans les mentions
     * légales (`discovery_source = 'mentions-legales'`) d'une fiche au site
     * NON VÉRIFIÉ — son adresse vient peut-être du site d'un autre.
     *
     * Ce lot ne FILTRE rien (le filtrage des envois est le lot N5) : la
     * condition est fournie pour lui. Rien n'est effacé.
     */
    public static function contactIssuSiteNonVerifieSql(string $aliasContact = 'contacts'): string
    {
        self::alias($aliasContact);

        return "({$aliasContact}.discovery_source = 'mentions-legales'"
            . " AND EXISTS (SELECT 1 FROM companies sf_c WHERE sf_c.id = {$aliasContact}.company_id"
            . ' AND ' . self::nonVerifieSql('sf_c') . '))';
    }

    /**
     * SQL : l'adresse générique (`email_generic`) de la fiche `$alias` a pu
     * être extraite d'un site NON VÉRIFIÉ. Même statut que la condition
     * ci-dessus : fournie pour le lot N5, rien n'est filtré ni effacé ici.
     */
    public static function emailGeneriqueIssuSiteNonVerifieSql(string $alias = 'companies'): string
    {
        self::alias($alias);

        return "({$alias}.email_generic IS NOT NULL AND " . self::nonVerifieSql($alias) . ')';
    }

    /**
     * Les fiches vivantes de l'espace au site non vérifié — la requête de
     * comptage du lot, servie par `idx_companies_site_non_verifie`.
     * `workspace_id` est explicite : sous `axion_app`, la politique de
     * sécurité le filtre aussi.
     */
    public static function fichesNonVerifiees(string $workspaceId): Builder
    {
        return DB::table('companies AS c')
            ->where('c.workspace_id', $workspaceId)
            ->whereNull('c.deleted_at')
            ->whereRaw(self::nonVerifieSql('c'));
    }

    /**
     * Garde : un alias de table est un identifiant SQL simple, jamais une
     * donnée. Il est interpolé dans le SQL : tout autre texte est refusé.
     *
     * @throws InvalidArgumentException
     */
    private static function alias(string $alias): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $alias) !== 1) {
            throw new InvalidArgumentException('Alias de table refusé : ' . json_encode($alias));
        }
    }

    private static function statutsSql(): string
    {
        return "'" . implode("', '", self::STATUTS_VERIFIES) . "'";
    }
}
