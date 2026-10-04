<?php

namespace App\Crm\Sites;

use App\Console\Commands\CrmRelationsImporter;
use App\Crm\Presse\SiteMedia;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * UNE ADRESSE SUR UN DOMAINE NON VÉRIFIÉ EST EN QUARANTAINE — une seule
 * définition (lot N5 « filtre d'envoi », 03/10/2026).
 *
 * Le lot N4 (`SiteFiable`) a nommé les sites DEVINÉS et non vérifiés
 * (`website_method` `guess%` sans marqueur `metadata.site_entreprise`
 * vérifié). Les adresses qui en viennent sont peut-être celles d'un autre
 * (france.fr, maison.fr, paris.fr…). Elles sont mises EN QUARANTAINE :
 * jamais exportées, jamais comptées joignables, jamais envoyées. Rien n'est
 * effacé ni réécrit : la quarantaine est DÉDUITE à la lecture, et elle se
 * lève d'elle-même le jour où le site est vérifié (marqueur posé).
 *
 * ── LA RÈGLE ─────────────────────────────────────────────────────────────
 *
 * Sur une fiche au site NON VÉRIFIÉ (`SiteFiable::nonVerifieSql`), sont en
 * quarantaine :
 *   1. l'adresse générique de la fiche (`companies.email_generic`) ;
 *   2. les canaux typés relevés sur le site (`signals.contact_channels`) ;
 *   3. une personne trouvée SUR le site (`contacts.discovery_source` ∈
 *      `SOURCES_SITE` : page du site, mentions légales) ;
 *   4. toute adresse dont le domaine est celui du site (ou un sous-domaine,
 *      ou l'inverse) : adresse fabriquée ou relevée sur ce domaine.
 *
 * Une personne connue autrement (INSEE, annuaire, import…) dont l'adresse est
 * sur un AUTRE domaine n'est pas en quarantaine : elle n'en vient pas. Une
 * fiche jamais devinée, ou devinée puis vérifiée, n'a rien en quarantaine.
 *
 * Médias et journalistes (exports) : la ligne `media` porte sa propre
 * méthode (`media.website_method`) ; son site n'est vérifié que par le
 * marqueur de la fiche rattachée (`metadata.site_media` ou
 * `metadata.site_entreprise`, statut `verifie` / `trouve-verifie`). Sinon,
 * son adresse de rédaction, et tout journaliste relevé sur ce site, sont en
 * quarantaine. Un marqueur `site_entreprise` TROUVÉ PAR CANDIDAT prouve un
 * AUTRE site : il ne vérifie la ligne `media` que si `media.website` est sur
 * le même domaine que le site prouvé (`companies.website`).
 *
 * ── SITE TROUVÉ PAR CANDIDAT (04/10/2026) ────────────────────────────────
 *
 * `crm:entreprises:chercher-site-prouve` remplace un site deviné non
 * conforme par un candidat PROUVÉ (SIREN) : la fiche devient vérifiée
 * (`trouve-verifie`, `origine` = `candidat`), et l'ancien site deviné reste
 * dans le marqueur (`ancien.url`). Ce qui a été relevé AVANT venait de
 * l'ancien site — celui d'une autre entreprise. Sur une telle fiche, seul le
 * domaine prouvé est libéré :
 *   - générique et canaux typés : en quarantaine SAUF sur le domaine du site
 *     prouvé (`companies.website`) ;
 *   - personne : en quarantaine si relevée sur un site (`SOURCES_SITE`) ou
 *     sur le domaine de l'ANCIEN site, SAUF sur le domaine du site prouvé ;
 *     une personne connue autrement, à une adresse d'un autre domaine, n'est
 *     pas en quarantaine (comme partout ailleurs).
 * Une fiche vérifiée par N6 (même site, pas d'`ancien`) n'est pas touchée.
 *
 * ── LE SQL ───────────────────────────────────────────────────────────────
 *
 * Les conditions sont toujours posées sur des lignes DÉJÀ restreintes (la
 * fiche courante d'une audience, la personne d'un export, une clé primaire) :
 * aucune ne déclenche un balayage de `companies`. Aucun argument n'est une
 * donnée utilisateur (alias seulement, gardés : exception sinon). Alias
 * internes `qs_*` réservés.
 */
final class QuarantaineSite
{
    /** Le motif (`EligibiliteAdresse`) et le compteur des bilans. */
    public const MOTIF = 'site_non_verifie';

    /** Les `contacts.discovery_source` qui désignent une personne relevée SUR le site. */
    public const SOURCES_SITE = ['site', 'mentions-legales'];

    // ── Miroir en mémoire ────────────────────────────────────────────────

    /** La fiche porte-t-elle un site deviné non vérifié ? (`SiteFiable::estNonVerifie`). */
    public static function ficheNonVerifiee(?string $methode, mixed $metadata): bool
    {
        return SiteFiable::estNonVerifie($methode, $metadata);
    }

    /**
     * L'ancien site deviné d'une fiche dont le site a été TROUVÉ par
     * candidat (marqueur vérifié, `origine` = `candidat`), ou null. Miroir de
     * `siteAncienSql()`.
     */
    public static function siteAncien(mixed $metadata): ?string
    {
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }
        $m = is_array($metadata) ? ($metadata[SiteFiable::CLE] ?? null) : null;
        if (! is_array($m) || ($m['origine'] ?? null) !== CandidatsSite::ORIGINE
            || ! in_array($m['statut'] ?? null, SiteFiable::STATUTS_VERIFIES, true)) {
            return null;
        }
        $url = is_array($m['ancien'] ?? null) ? ($m['ancien']['url'] ?? null) : null;

        return is_string($url) ? $url : '';
    }

    /**
     * Une personne de la fiche est-elle en quarantaine ?
     *
     * @param  bool  $ficheNonVerifiee  `ficheNonVerifiee()` de SA fiche
     * @param  ?string  $siteAncien  `siteAncien()` de SA fiche (site trouvé par candidat)
     */
    public static function personne(bool $ficheNonVerifiee, ?string $source, string $email, ?string $site, ?string $siteAncien = null): bool
    {
        if ($ficheNonVerifiee) {
            return in_array($source, self::SOURCES_SITE, true) || self::memeDomaine($email, $site);
        }
        if ($siteAncien === null) {
            return false;
        }

        return ! self::memeDomaine($email, $site)
            && (in_array($source, self::SOURCES_SITE, true) || self::memeDomaine($email, $siteAncien));
    }

    /**
     * Une adresse de la FICHE (générique, canal typé relevé sur le site) est-
     * elle en quarantaine ? Fiche non vérifiée : oui ; site trouvé par
     * candidat : oui, sauf sur le domaine du site prouvé ; sinon non.
     */
    public static function adresseFiche(bool $ficheNonVerifiee, string $email, ?string $site, ?string $siteAncien = null): bool
    {
        if ($ficheNonVerifiee) {
            return true;
        }

        return $siteAncien !== null && ! self::memeDomaine($email, $site);
    }

    /**
     * L'adresse est-elle sur le domaine du site (égal, sous-domaine de l'un
     * ou de l'autre) ? Miroir de `memeDomaineSql()`.
     */
    public static function memeDomaine(string $email, ?string $site): bool
    {
        return self::domainesLies(self::domaineEmail($email), self::domaineSite($site));
    }

    /** Domaine d'une adresse, en minuscules (`''` si aucun). Miroir du SQL. */
    public static function domaineEmail(string $email): string
    {
        $email = mb_strtolower(trim($email));
        $at = strpos($email, '@');
        if ($at === false) {
            return '';
        }
        $reste = substr($email, $at + 1);
        $fin = strpos($reste, '@');

        return $fin === false ? $reste : substr($reste, 0, $fin);
    }

    /**
     * Domaine d'un site : sans schéma, sans `www.`, sans chemin ni port, en
     * minuscules — miroir de `CrmRelationsImporter::expressionDomaineDuSite`.
     */
    public static function domaineSite(?string $site): string
    {
        if ($site === null) {
            return '';
        }
        $d = preg_replace('/^([a-zA-Z][a-zA-Z0-9+.-]*:\/\/)?(www\.)?/i', '', trim($site)) ?? '';
        $d = preg_replace('/[\/:?#].*$/s', '', $d) ?? '';

        return mb_strtolower($d);
    }

    /**
     * La ligne `media` (méthode, site) et le marqueur de sa fiche (site de la
     * fiche) : site non vérifié ? Miroir de `mediaSql()`. Un site d'entreprise
     * trouvé par candidat ne vérifie la ligne que sur le même domaine.
     */
    public static function mediaNonVerifie(?string $methode, mixed $metadataFiche, ?string $siteMedia = null, ?string $siteFiche = null): bool
    {
        if (! SiteFiable::estMethodeDevinee($methode)) {
            return false;
        }
        if (is_string($metadataFiche)) {
            $metadataFiche = json_decode($metadataFiche, true);
        }
        $media = is_array($metadataFiche) ? ($metadataFiche[SiteMedia::CLE] ?? null) : null;
        $entreprise = SiteFiable::marqueurVerifie($metadataFiche)
            && (self::siteAncien($metadataFiche) === null || self::memeSite($siteMedia, $siteFiche));

        return ! $entreprise
            && ! (is_array($media) && in_array($media['statut'] ?? null, SiteMedia::STATUTS_VERIFIES, true));
    }

    /**
     * Rendre le site de la fiche FIABLE (méthode non devinée, ou marqueur
     * `verifie`) avec `$siteGaranti` libérerait-il une adresse que rien ne
     * garantit ? (relecture #329, défaut 1)
     *
     * La quarantaine ne regarde que l'ÉTAT du site : le jour où il devient
     * fiable, TOUTES les adresses de la fiche sortent — l'adresse générique,
     * les personnes relevées sur l'ancien site (deviné, peut-être celui d'un
     * autre) ou sur son domaine. Une adresse n'est GARANTIE que si elle est
     * `$emailGaranti` (l'adresse de la source officielle) ou sur le domaine
     * de `$siteGaranti` — générique, canaux typés `signals.contact_channels`
     * (`emails` et clés de `details`), personnes. Sur une fiche au site déjà fiable, rien n'est en
     * quarantaine : false.
     *
     * Lecture des personnes de LA fiche (index `idx_contacts_company`,
     * `idx_personnes_company`) ; aucun balayage.
     */
    public static function liberationNonGarantie(
        int $companyId,
        ?string $methode,
        mixed $metadata,
        ?string $emailGeneric,
        mixed $signals,
        ?string $siteActuel,
        string $siteGaranti,
        ?string $emailGaranti = null,
    ): bool {
        if (! self::ficheNonVerifiee($methode, $metadata)) {
            return false;
        }
        $garantie = static function (string $email) use ($siteGaranti, $emailGaranti): bool {
            return ($emailGaranti !== null && mb_strtolower(trim($email)) === mb_strtolower(trim($emailGaranti)))
                || self::memeDomaine($email, $siteGaranti);
        };

        $generique = trim((string) $emailGeneric);
        if ($generique !== '' && ! $garantie($generique)) {
            return true;
        }

        // Les canaux typés relevés sur le site (règle n°2) : la liste `emails`
        // et les clés de `details`, comme `ResolveurDestinataires`.
        if (is_string($signals)) {
            $signals = json_decode($signals, true);
        }
        $canaux = is_array($signals) && is_array($signals['contact_channels'] ?? null) ? $signals['contact_channels'] : [];
        $adressesCanaux = array_map('strval', array_keys(is_array($canaux['details'] ?? null) ? $canaux['details'] : []));
        foreach (is_array($canaux['emails'] ?? null) ? $canaux['emails'] : [] as $e) {
            if (is_string($e)) {
                $adressesCanaux[] = $e;
            }
        }
        foreach ($adressesCanaux as $e) {
            if (trim($e) !== '' && ! $garantie($e)) {
                return true;
            }
        }

        $contacts = DB::table('contacts')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereNotNull('email')
            ->get(['email', 'discovery_source']);
        foreach ($contacts as $c) {
            $email = (string) $c->email;
            if (self::personne(true, is_string($c->discovery_source) ? $c->discovery_source : null, $email, $siteActuel)
                && ! $garantie($email)) {
                return true;
            }
        }

        $personnes = DB::table('personnes')
            ->where('company_id', $companyId)
            ->whereNotNull('email')
            ->get(['email', 'contact_id']);
        // La source d'une personne est celle du contact lié, SUPPRIMÉ ou non :
        // miroir de `personneLettreSql()` (clé primaire). Lecture CONSCIENTE de
        // la corbeille (`deleted_at` lu, jamais filtré) : un contact mis à la
        // corbeille n'efface pas l'origine de l'adresse de la personne
        // (garde `EffacementDouxPorteeAgent35Test`).
        $idsContacts = $personnes->pluck('contact_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $relevesSurLeSite = $idsContacts === [] ? [] : DB::table('contacts')
            ->whereIn('id', $idsContacts)
            ->whereIn('discovery_source', self::SOURCES_SITE)
            ->get(['id', 'deleted_at'])
            ->mapWithKeys(fn ($c): array => [(int) $c->id => true])
            ->all();
        foreach ($personnes as $p) {
            $email = (string) $p->email;
            $enQuarantaine = self::memeDomaine($email, $siteActuel)
                || ($p->contact_id !== null && isset($relevesSurLeSite[(int) $p->contact_id]));
            if ($enQuarantaine && ! $garantie($email)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les deux sites sont-ils sur le même domaine (égal, sous-domaine de l'un
     * ou de l'autre) ? Miroir de `memeSiteExpr()`.
     */
    public static function memeSite(?string $siteA, ?string $siteB): bool
    {
        return self::domainesLies(self::domaineSite($siteA), self::domaineSite($siteB));
    }

    /** Deux domaines (déjà normalisés) égaux, ou l'un sous-domaine de l'autre. */
    private static function domainesLies(string $da, string $db): bool
    {
        if ($da === '' || $db === '') {
            return false;
        }

        return $da === $db || str_ends_with($da, '.' . $db) || str_ends_with($db, '.' . $da);
    }

    // ── SQL ──────────────────────────────────────────────────────────────

    /**
     * SQL (jamais NULL) : l'adresse générique de la fiche `$alias` est en
     * quarantaine.
     */
    public static function generiqueSql(string $alias = 'companies'): string
    {
        self::alias($alias);

        return "COALESCE({$alias}.email_generic IS NOT NULL AND (" . SiteFiable::nonVerifieSql($alias)
            . ' OR (' . self::trouveParCandidatSql($alias) . ' AND NOT ' . self::memeDomaineSql("{$alias}.email_generic", "{$alias}.website") . ')), false)';
    }

    /**
     * SQL : l'ancien site deviné de la fiche `$alias` si son site a été
     * TROUVÉ par candidat, sinon NULL. Miroir de `siteAncien()`.
     */
    public static function siteAncienSql(string $alias = 'companies'): string
    {
        self::alias($alias);

        return 'CASE WHEN ' . self::trouveParCandidatSql($alias)
            . " THEN COALESCE({$alias}.metadata -> '" . SiteFiable::CLE . "' -> 'ancien' ->> 'url', '') END";
    }

    /** SQL (jamais NULL) : le site de la fiche `$alias` a été trouvé par candidat et prouvé. */
    private static function trouveParCandidatSql(string $alias): string
    {
        $statuts = "'" . implode("', '", SiteFiable::STATUTS_VERIFIES) . "'";

        return "(COALESCE({$alias}.metadata -> '" . SiteFiable::CLE . "' ->> 'origine', '') = '" . CandidatsSite::ORIGINE . "'"
            . " AND COALESCE({$alias}.metadata -> '" . SiteFiable::CLE . "' ->> 'statut', '') IN ({$statuts}))";
    }

    /**
     * SQL (jamais NULL) : la personne `$aliasContact`, rattachée à la fiche
     * `$aliasFiche` (déjà jointe ou corrélée), est en quarantaine.
     */
    public static function personneSql(string $aliasContact = 'contacts', string $aliasFiche = 'companies'): string
    {
        self::alias($aliasContact);
        self::alias($aliasFiche);
        $sources = "'" . implode("', '", self::SOURCES_SITE) . "'";

        return 'COALESCE((' . SiteFiable::nonVerifieSql($aliasFiche)
            . " AND (COALESCE({$aliasContact}.discovery_source, '') IN ({$sources})"
            . ' OR ' . self::memeDomaineSql("{$aliasContact}.email", "{$aliasFiche}.website") . '))'
            . ' OR (' . self::trouveParCandidatSql($aliasFiche)
            . ' AND NOT ' . self::memeDomaineSql("{$aliasContact}.email", "{$aliasFiche}.website")
            . " AND (COALESCE({$aliasContact}.discovery_source, '') IN ({$sources})"
            . ' OR ' . self::memeDomaineExpr("{$aliasContact}.email", self::ancienExpr($aliasFiche)) . ')), false)';
    }

    /**
     * SQL (jamais NULL) : la `personnes` `$aliasPersonne` (lettre, formulaires),
     * rattachée à la fiche `$aliasFiche` (LEFT JOIN, peut être nulle), est en
     * quarantaine : même règle que `personneSql()`, la source étant celle de
     * la personne du CRM liée (`personnes.contact_id`, clé primaire).
     */
    public static function personneLettreSql(string $aliasPersonne = 'personnes', string $aliasFiche = 'companies'): string
    {
        self::alias($aliasPersonne);
        self::alias($aliasFiche);
        $sources = "'" . implode("', '", self::SOURCES_SITE) . "'";

        $releve = "EXISTS (SELECT 1 FROM contacts qs_ct WHERE qs_ct.id = {$aliasPersonne}.contact_id"
            . " AND qs_ct.discovery_source IN ({$sources}))";

        return 'COALESCE((' . SiteFiable::nonVerifieSql($aliasFiche)
            . ' AND (' . self::memeDomaineSql("{$aliasPersonne}.email", "{$aliasFiche}.website")
            . " OR {$releve}))"
            . ' OR (' . self::trouveParCandidatSql($aliasFiche)
            . ' AND NOT ' . self::memeDomaineSql("{$aliasPersonne}.email", "{$aliasFiche}.website")
            . ' AND (' . self::memeDomaineExpr("{$aliasPersonne}.email", self::ancienExpr($aliasFiche)) . " OR {$releve})), false)";
    }

    /**
     * SQL (jamais NULL) : l'adresse `$colonneEmail` est sur le domaine du site
     * `$colonneSite`. `right()` plutôt que `LIKE` : un `_` dans un domaine
     * n'est pas un joker.
     */
    public static function memeDomaineSql(string $colonneEmail, string $colonneSite): string
    {
        self::colonne($colonneEmail);
        self::colonne($colonneSite);

        return self::memeDomaineExpr($colonneEmail, $colonneSite);
    }

    /** L'ancien site (expression SQL interne, jamais une donnée) de la fiche `$alias`. */
    private static function ancienExpr(string $alias): string
    {
        return "({$alias}.metadata -> '" . SiteFiable::CLE . "' -> 'ancien' ->> 'url')";
    }

    /**
     * `memeDomaineSql()` sur des expressions INTERNES (colonnes gardées, ou
     * `ancienExpr`) — jamais une donnée.
     */
    private static function memeDomaineExpr(string $colonneEmail, string $colonneSite): string
    {
        return self::domainesLiesExpr(
            "lower(split_part(btrim({$colonneEmail}), '@', 2))",
            CrmRelationsImporter::expressionDomaineDuSite($colonneSite),
        );
    }

    /**
     * SQL (jamais NULL) : les sites `$colonneA` et `$colonneB` (expressions
     * INTERNES) sont sur le même domaine. Miroir de `memeSite()`.
     */
    private static function memeSiteExpr(string $colonneA, string $colonneB): string
    {
        return self::domainesLiesExpr(
            CrmRelationsImporter::expressionDomaineDuSite($colonneA),
            CrmRelationsImporter::expressionDomaineDuSite($colonneB),
        );
    }

    /** Deux expressions de domaine (internes) égales, ou l'une sous-domaine de l'autre. */
    private static function domainesLiesExpr(string $da, string $db): string
    {
        return "COALESCE(({$da} <> '' AND {$db} <> '' AND ({$da} = {$db}"
            . " OR right({$da}, length({$db}) + 1) = '.' || {$db}"
            . " OR right({$db}, length({$da}) + 1) = '.' || {$da})), false)";
    }

    /**
     * SQL (jamais NULL) : la ligne `media` `$aliasMedia` porte un site deviné
     * que sa fiche n'a pas vérifié. Sous-requête par clé primaire de
     * `companies` (une ligne). Un marqueur `site_entreprise` trouvé par
     * candidat (autre site que celui deviné) ne vérifie la ligne que si
     * `media.website` est sur le domaine du site prouvé. Miroir de
     * `mediaNonVerifie()`.
     */
    public static function mediaSql(string $aliasMedia = 'media'): string
    {
        self::alias($aliasMedia);
        $statuts = "'" . implode("', '", SiteMedia::STATUTS_VERIFIES) . "'";

        return "COALESCE({$aliasMedia}.website_method LIKE '" . SiteFiable::PREFIXE_DEVINE . "%'"
            . " AND NOT EXISTS (SELECT 1 FROM companies qs_c WHERE qs_c.id = {$aliasMedia}.company_id"
            . " AND ((COALESCE(qs_c.metadata -> '" . SiteFiable::CLE . "' ->> 'statut', '') IN ({$statuts})"
            . " AND (COALESCE(qs_c.metadata -> '" . SiteFiable::CLE . "' ->> 'origine', '') <> '" . CandidatsSite::ORIGINE . "'"
            . ' OR ' . self::memeSiteExpr("{$aliasMedia}.website", 'qs_c.website') . '))'
            . " OR COALESCE(qs_c.metadata -> '" . SiteMedia::CLE . "' ->> 'statut', '') IN ({$statuts}))), false)";
    }

    /**
     * SQL (jamais NULL) : le journaliste `$aliasJournaliste` a été relevé sur
     * le site de son média (ours, mentions légales, signature…) et ce site
     * est deviné non vérifié (`mediaSql`) : la personne est peut-être celle
     * d'un autre titre. Média par clé primaire.
     */
    public static function journalisteSql(string $aliasJournaliste = 'journalists'): string
    {
        self::alias($aliasJournaliste);

        return "EXISTS (SELECT 1 FROM media qs_m WHERE qs_m.id = {$aliasJournaliste}.media_id AND "
            . self::mediaSql('qs_m') . ')';
    }

    /**
     * Garde : un alias de table est un identifiant SQL simple, jamais une
     * donnée.
     *
     * @throws InvalidArgumentException
     */
    private static function alias(string $alias): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $alias) !== 1) {
            throw new InvalidArgumentException('Alias de table refusé : ' . json_encode($alias));
        }
    }

    /** @throws InvalidArgumentException */
    private static function colonne(string $colonne): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*$/', $colonne) !== 1) {
            throw new InvalidArgumentException('Colonne refusée : ' . json_encode($colonne));
        }
    }
}
