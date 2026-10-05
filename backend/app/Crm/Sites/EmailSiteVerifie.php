<?php

namespace App\Crm\Sites;

use App\Crm\Emails\QualificationEmail;
use App\Crm\Presse\LecturePageAccueil;
use App\Services\Email\MxEmailValidator;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * L'ADRESSE E-MAIL AFFICHÉE SUR UN SITE VÉRIFIÉ (décision du propriétaire,
 * 04/10/2026) — les règles de `crm:entreprises:email-site-verifie`, sans
 * réseau ni base.
 *
 * Sur 200 sites devinés, 74 % étaient ceux d'une autre entreprise : seul un
 * site PROUVÉ (SIREN sur l'accueil ou les mentions légales, `VerificationSite`)
 * ou de source fiable (jamais deviné, `SiteFiable::fiableSql`) est lu ici.
 *
 * ── LES PAGES (bornées) ──────────────────────────────────────────────────
 *
 * La page où le SIREN a été trouvé (preuve `siren-accueil` : l'accueil ;
 * sinon les mentions légales, lien de l'accueil ou `/mentions-legales`) et
 * AU PLUS `PAGES_CONTACT_MAX` (2) pages « contact » trouvées par LIEN sur
 * l'accueil, sur le MÊME site (`liensContact`). Une page dont l'adresse
 * d'ARRIVÉE n'est pas sur le domaine du site ne compte pas
 * (`VerificationSite::motifArrivee`).
 *
 * ── L'EXTRACTION ─────────────────────────────────────────────────────────
 *
 * `mailto:` et adresses en clair, avec une désobfuscation SIMPLE
 * (« [at] », « (arobase) », « [dot] », « (point) », « nom @ domaine »).
 * Scripts et styles ignorés. Au plus `ADRESSES_MAX_PAR_PAGE` adresses.
 *
 * ── LE JUGEMENT (`juger`) ────────────────────────────────────────────────
 *
 * Gardée SEULEMENT si son domaine est celui du site (sans `www.`) ou un
 * sous-domaine. Rejetée, avec un motif compté au bilan : image
 * (`logo@2x.png`), syntaxe, `noreply` et apparentés, exemple / gabarit,
 * jetable, autre domaine (hébergeur, agence, webmaster TIERS), adresse
 * technique (webmaster@, postmaster@, dpo@… du site lui-même : pas un
 * contact commercial).
 *
 * GÉNÉRIQUE (contact@, info@, accueil@, direction@, rh@, formation@…) ou
 * NOMINATIVE (tout le reste : dans le doute, on protège). Les mots de
 * `QualificationEmail`, complétés de quelques boîtes de service courantes
 * sur les sites, mais PLUS STRICTS que `QualificationEmail::type` : le mot
 * doit être SEUL, ou suivi uniquement de chiffres (`contact@`, `rh2@`).
 * `rh.marie.durand@`, `compta.jdupont@`, `commercial.pierre@` désignent une
 * personne : NOMINATIVES (relecture #328, remarque 2). La générique est
 * préférée (`preferee`). Une NOMINATIVE n'est JAMAIS écrite dans
 * `email_generic` : elle est comptée, et suit les règles des personnes,
 * qu'aucun automatisme ne contourne.
 *
 * Un site hébergé sur une PLATEFORME partagée (réseau social, constructeur
 * de sites, annuaire : `HOTES_PLATEFORMES`) n'est pas le domaine de
 * l'entreprise : aucune adresse n'y est « du site » (`estPlateforme`).
 */
final class EmailSiteVerifie
{
    /** Origine écrite dans `field_origins` et dans `propositions_champs`. */
    public const ORIGINE = 'site-verifie';

    /** Trace de l'écriture dans `companies.signals` : {url, le, type, preuve_site, v}. */
    public const CLE_SIGNAL = 'email_site_verifie';

    /** Version des règles, écrite dans la trace (`v`). */
    public const VERSION = 1;

    /** Clé du curseur persistant (`curseurs_traitements.traitement`). */
    public const TRAITEMENT = 'entreprises:email-site-verifie';

    public const PAGES_CONTACT_MAX = 2;

    public const ADRESSES_MAX_PAR_PAGE = 50;

    public const GENERIQUE = 'generique';

    public const NOMINATIF = 'nominatif';

    /** Preuve inscrite dans la trace pour un site de source fiable (jamais deviné). */
    public const PREUVE_SOURCE_FIABLE = 'source-fiable';

    public const MOTIF_IMAGE = 'image';

    public const MOTIF_SYNTAXE = 'syntaxe';

    public const MOTIF_NOREPLY = 'noreply';

    public const MOTIF_EXEMPLE = 'exemple';

    public const MOTIF_JETABLE = 'jetable';

    public const MOTIF_AUTRE_DOMAINE = 'autre-domaine';

    public const MOTIF_TECHNIQUE = 'technique';

    /** @var list<string> */
    public const MOTIFS = [
        self::MOTIF_AUTRE_DOMAINE, self::MOTIF_NOREPLY, self::MOTIF_EXEMPLE, self::MOTIF_IMAGE,
        self::MOTIF_TECHNIQUE, self::MOTIF_JETABLE, self::MOTIF_SYNTAXE,
    ];

    /**
     * Boîtes de service fréquentes sur les sites, absentes des listes de
     * `QualificationEmail` : jamais un prénom.
     *
     * @var list<string>
     */
    public const MOTS_GENERIQUES_SITE = [
        'rh', 'recrutement', 'emploi', 'commercial', 'commerciale', 'devis', 'compta', 'comptabilite', 'facturation',
        'factures', 'sav', 'commande', 'commandes', 'reservation', 'reservations', 'agence', 'magasin', 'boutique',
        'atelier', 'cabinet', 'etude', 'gestion', 'qualite', 'vente', 'ventes', 'export', 'boite', 'equipe',
    ];

    /**
     * Hôtes de PLATEFORMES partagées (relecture #328, remarque 5) : un site
     * « fiable » sur l'un d'eux ou un de ses sous-domaines (`xxx.wixsite.com`)
     * n'est pas celui de l'entreprise — `contact@facebook.com` n'en est pas
     * l'adresse. La fiche est ignorée, aucune page n'est demandée.
     *
     * @var list<string>
     */
    public const HOTES_PLATEFORMES = [
        'facebook.com', 'fb.com', 'm.facebook.com', 'instagram.com', 'linkedin.com', 'twitter.com', 'x.com',
        'youtube.com', 'tiktok.com', 'pinterest.com', 'sites.google.com', 'google.com', 'business.site',
        'wixsite.com', 'wix.com', 'weebly.com', 'jimdo.com', 'jimdosite.com', 'webnode.fr', 'webnode.com',
        'wordpress.com', 'blogspot.com', 'over-blog.com', 'e-monsite.com', 'site-solocal.com', 'squarespace.com',
        'pagesjaunes.fr', 'societe.com', 'doctolib.fr', 'tripadvisor.fr', 'tripadvisor.com', 'yelp.fr', 'linktr.ee',
    ];

    /** Ordre de préférence entre génériques (les autres suivent, dans l'ordre d'apparition). */
    private const PREFERENCE = ['contact', 'info', 'infos', 'accueil', 'bonjour', 'hello', 'secretariat', 'direction'];

    /** Domaines de documentation et gabarits (RFC 2606 et équivalents français). */
    private const DOMAINES_EXEMPLE = [
        'example.com', 'example.org', 'example.net', 'exemple.com', 'exemple.fr', 'exemple.org',
        'domaine.com', 'domaine.fr', 'mondomaine.com', 'mondomaine.fr', 'votredomaine.com', 'votredomaine.fr',
        'votre-domaine.com', 'votre-domaine.fr', 'monsite.com', 'monsite.fr', 'votresite.com', 'votresite.fr',
    ];

    /** Parties locales de gabarit (« votre-email@ », « nom@ »…). */
    private const LOCALES_EXEMPLE = [
        'exemple', 'example', 'votre-email', 'votreemail', 'votre.email', 'votre-adresse', 'votreadresse', 'votremail',
        'nom', 'prenom', 'prenom.nom', 'nom.prenom', 'john.doe', 'jane.doe', 'jean.dupont', 'test', 'email', 'adresse',
        'username', 'user', 'utilisateur', 'xxx', 'xxxx',
    ];

    private const MOTIF_NOREPLY_REGEX = '/^(?:no-?reply|do-?not-?reply|donotreply|ne-?pas-?repondre|nepasrepondre|noreponse|no-?response|mailer-daemon|bounces?)(?:$|[0-9._+-])/';

    private const MOTIF_TECHNIQUE_REGEX = '/^(?:webmaster|hostmaster|postmaster|abuse|root|dpo|rgpd|gdpr|privacy|security|spam|wordpress|wp|cpanel|www-data)(?:$|[0-9._+-])/';

    private const MOTIF_IMAGE_REGEX = '/\.(?:png|jpe?g|gif|svg|webp|bmp|ico|avif|tiff?|css|js)$/';

    /**
     * Les adresses de la page, normalisées (minuscules), sans doublon, dans
     * l'ordre : `mailto:` d'abord, puis le texte. Au plus
     * `ADRESSES_MAX_PAR_PAGE`.
     *
     * @return list<string>
     */
    public static function adresses(string $html): array
    {
        if (! mb_check_encoding($html, 'UTF-8')) {
            $html = (string) mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
        }
        $trouvees = [];
        $ajouter = static function (string $brut) use (&$trouvees): void {
            $email = QualificationEmail::normaliser($brut);
            if ($email !== '' && count($trouvees) < self::ADRESSES_MAX_PAR_PAGE) {
                $trouvees[$email] = true;
            }
        };

        $sansScripts = (string) preg_replace('#<(script|style|noscript|template)\b[^>]*>.*?</\1\s*>#is', ' ', $html);

        if (preg_match_all('#\bmailto:([^"\'<>\s?&]{3,254})#i', $sansScripts, $m) > 0) {
            foreach ($m[1] as $brut) {
                $brut = html_entity_decode(rawurldecode($brut), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (preg_match('/^' . self::motifAdresse() . '$/i', $brut) === 1) {
                    $ajouter($brut);
                }
            }
        }

        $texte = html_entity_decode((string) preg_replace('/<[^>]*>/', ' ', $sansScripts), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texte = self::desobfusquer($texte);
        if (preg_match_all('/(?<![a-z0-9._%+-])' . self::motifAdresse() . '/i', $texte, $m) > 0) {
            foreach ($m[0] as $brut) {
                $ajouter($brut);
            }
        }

        return array_keys($trouvees);
    }

    /**
     * Désobfuscation simple : « nom [at] domaine [dot] fr », « nom (arobase)
     * domaine.fr », « nom @ domaine.fr ».
     */
    public static function desobfusquer(string $texte): string
    {
        $texte = (string) preg_replace('/\s*[\[\(\{]\s*(?:at|arobase|@)\s*[\]\)\}]\s*/iu', '@', $texte);
        $texte = (string) preg_replace('/\s*[\[\(\{]\s*(?:dot|point)\s*[\]\)\}]\s*/iu', '.', $texte);

        return (string) preg_replace('/(?<=[a-z0-9])\s+@\s+(?=[a-z0-9])/iu', '@', $texte);
    }

    /**
     * Le jugement d'une adresse trouvée sur le site `$site` : `[type, null]`
     * si elle est gardée (`GENERIQUE` ou `NOMINATIF`), `[null, motif]` sinon.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function juger(string $email, string $site): array
    {
        $email = QualificationEmail::normaliser($email);
        if (preg_match(self::MOTIF_IMAGE_REGEX, $email) === 1) {
            return [null, self::MOTIF_IMAGE];
        }
        if (! QualificationEmail::syntaxeValide($email)) {
            return [null, self::MOTIF_SYNTAXE];
        }
        $at = (int) strrpos($email, '@');
        $local = substr($email, 0, $at);
        $domaine = (string) QualificationEmail::domaine($email);
        if (preg_match(self::MOTIF_NOREPLY_REGEX, $local) === 1) {
            return [null, self::MOTIF_NOREPLY];
        }
        if (in_array($domaine, self::DOMAINES_EXEMPLE, true) || in_array($local, self::LOCALES_EXEMPLE, true)) {
            return [null, self::MOTIF_EXEMPLE];
        }
        if (QualificationEmail::estJetable($domaine)) {
            return [null, self::MOTIF_JETABLE];
        }
        if (! self::surLeSite($domaine, $site)) {
            return [null, self::MOTIF_AUTRE_DOMAINE];
        }
        if (preg_match(self::MOTIF_TECHNIQUE_REGEX, $local) === 1) {
            return [null, self::MOTIF_TECHNIQUE];
        }

        return [self::type($email), null];
    }

    /**
     * Le domaine de l'adresse est celui du site (sans `www.`) ou un de ses
     * sous-domaines — jamais pour un site sur une plateforme partagée.
     */
    public static function surLeSite(string $domaineEmail, string $site): bool
    {
        $ds = QuarantaineSite::domaineSite($site);
        $de = strtolower(rtrim($domaineEmail, '.'));

        return $ds !== '' && $de !== '' && ! self::estPlateforme($site) && ($de === $ds || str_ends_with($de, '.' . $ds));
    }

    /** Le site est-il hébergé sur une plateforme partagée (`HOTES_PLATEFORMES`, sous-domaines compris) ? */
    public static function estPlateforme(string $site): bool
    {
        $ds = rtrim(QuarantaineSite::domaineSite($site), '.');
        if ($ds === '') {
            return false;
        }
        foreach (self::HOTES_PLATEFORMES as $hote) {
            if ($ds === $hote || str_ends_with($ds, '.' . $hote)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `GENERIQUE` ou `NOMINATIF`. Générique SEULEMENT si la partie locale est
     * un mot générique (`QualificationEmail`, `MxEmailValidator::ROLE_PREFIXES`,
     * `MOTS_GENERIQUES_SITE`) SEUL ou suivi uniquement de chiffres
     * (motif `^(mot)[0-9]*` avant l'arobase). Tout le reste est nominatif.
     */
    public static function type(string $email): string
    {
        $email = QualificationEmail::normaliser($email);
        $at = strrpos($email, '@');
        $local = $at === false ? $email : substr($email, 0, $at);

        return preg_match(self::motifGenerique(), $local) === 1 ? self::GENERIQUE : self::NOMINATIF;
    }

    private static function motifGenerique(): string
    {
        static $motif = null;
        if ($motif === null) {
            $mots = array_values(array_unique(array_merge(
                QualificationEmail::MOTS_GENERIQUES_FEDERATIONS,
                MxEmailValidator::ROLE_PREFIXES,
                self::MOTS_GENERIQUES_SITE,
            )));
            usort($mots, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            $motif = '/^(?:' . implode('|', array_map(static fn (string $m): string => preg_quote($m, '/'), $mots)) . ')[0-9]*$/';
        }

        return $motif;
    }

    /**
     * La générique à retenir : `contact@` d'abord, puis `info@`, `accueil@`…
     * (`PREFERENCE`), puis les autres dans l'ordre d'apparition.
     *
     * @param  list<string>  $generiques
     */
    public static function preferee(array $generiques): ?string
    {
        $choix = null;
        $meilleur = PHP_INT_MAX;
        foreach ($generiques as $i => $email) {
            $local = (string) strstr($email, '@', true);
            $tete = (string) preg_replace('/[0-9._+-].*$/', '', $local);
            $rang = array_search($tete, self::PREFERENCE, true);
            $score = ($rang === false ? count(self::PREFERENCE) : (int) $rang) * 1000 + $i;
            if ($score < $meilleur) {
                $meilleur = $score;
                $choix = $email;
            }
        }

        return $choix;
    }

    /**
     * Les liens « contact » de la page `$html` lue à `$base`, sur le MÊME site
     * (hôte égal, `www.` ignoré), dans l'ordre de la page, sans `$exclue` (les
     * mentions légales, déjà lues) : au plus `PAGES_CONTACT_MAX`.
     *
     * @return list<string>
     */
    public static function liensContact(string $html, string $base, ?string $exclue = null): array
    {
        if (preg_match_all('#<a\b[^>]{0,500}?\bhref\s*=\s*(["\'])([^"\'<>]{0,2000})\1[^>]{0,500}>(.{0,500}?)</a\s*>#is', $html, $liens, PREG_SET_ORDER) < 1) {
            return [];
        }
        $courante = LecturePageAccueil::cible($base);
        $choisis = [];
        foreach ($liens as $lien) {
            $href = html_entity_decode(trim($lien[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($href === '' || str_starts_with($href, '#') || preg_match('/^(?:mailto|tel|javascript|data):/i', $href) === 1) {
                continue;
            }
            $texte = Str::lower(Str::ascii(html_entity_decode(strip_tags($lien[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $cle = $texte . ' ' . Str::lower(Str::ascii(rawurldecode($href)));
            if (preg_match('/contact|nous[\s_-]*(?:joindre|contacter|ecrire)|coordonnees|ecrivez[\s_-]*nous/', $cle) !== 1) {
                continue;
            }
            $url = LecturePageAccueil::resoudre($base, $href);
            $url = $url === null ? null : LecturePageAccueil::cible($url);
            if ($url === null || $url === $courante || $url === $exclue || in_array($url, $choisis, true) || ! VerificationSite::memeSite($url, $base)) {
                continue;
            }
            $choisis[] = $url;
            if (count($choisis) >= self::PAGES_CONTACT_MAX) {
                break;
            }
        }

        return $choisis;
    }

    /**
     * La page de PREUVE à lire, selon le marqueur : l'accueil (`siren-accueil`)
     * ou les mentions légales (lien trouvé sur l'accueil, à défaut
     * `/mentions-legales`). Null : l'accueil lui-même.
     */
    public static function pagePreuve(?string $preuve, ?string $lienMentions, string $base): ?string
    {
        if ($preuve === VerificationSite::PREUVE_ACCUEIL) {
            return null;
        }

        return $lienMentions ?? VerificationSite::mentionsParDefaut($base);
    }

    /**
     * La requête d'un paquet : fiches vivantes de l'espace, au site FIABLE
     * (`SiteFiable::fiableSql` : vérifié, ou jamais deviné), sans e-mail
     * fiable (aucune générique, ou une générique vérifiée `invalide` /
     * `jetable`), après le curseur, dans l'ordre des identifiants.
     *
     * @return array{0: string, 1: list<int|string>}
     */
    public static function selectionSql(string $workspaceId, int $curseur, int $limite): array
    {
        return [
            "SELECT c.id, c.website, c.website_method, c.email_generic,
                    c.metadata -> '" . SiteFiable::CLE . "' AS marqueur,
                    c.field_origins ->> 'email_generic' AS origine_email
               FROM companies c
              WHERE c.workspace_id = ?
                AND c.deleted_at IS NULL
                AND c.id > ?
                AND c.website IS NOT NULL AND btrim(c.website) <> ''
                AND " . SiteFiable::fiableSql('c') . '
                AND ' . self::sansEmailFiableSql('c') . '
              ORDER BY c.id
              LIMIT ?',
            [$workspaceId, $curseur, $limite],
        ];
    }

    /** SQL : la fiche `$alias` n'a pas d'adresse générique fiable. */
    public static function sansEmailFiableSql(string $alias): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $alias) !== 1) {
            throw new InvalidArgumentException('Alias de table refusé : ' . json_encode($alias));
        }

        return "({$alias}.email_generic IS NULL OR btrim({$alias}.email_generic) = ''"
            . " OR COALESCE({$alias}.signals -> 'email_generic_verification' ->> 'statut', '') IN ('invalide', 'jetable'))";
    }

    /** Le motif d'une adresse électronique (partie locale ASCII, domaine à deux libellés au moins). */
    private static function motifAdresse(): string
    {
        return '[a-z0-9](?:[a-z0-9._%+-]{0,63})@(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.){1,8}[a-z]{2,24}';
    }
}
