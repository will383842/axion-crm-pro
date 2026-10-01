<?php

namespace App\Crm\Presse;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LE SITE D'UN MÉDIA EST-IL LE SIEN ? — une seule définition (constat en
 * production du 2026-10-01 ; durci après la relecture A09 de #273).
 *
 * `companies.website_method` vaut `guess` (≈ 563 000 fiches) ou `guess2`
 * (≈ 261 000) : des sites DEVINÉS depuis le nom, souvent faux (« PARIS LIVE »
 * → paris.fr, « BOURSE DU TEXTILE » → bourse.fr, « CHOLET REPRO SERVICES » →
 * cholet.fr), que `media` (source `naf-extract`) a recopiés. Le classement des
 * médias lisait donc de faux sites. `crm:presse:verifier-sites` vérifie le
 * site de chaque média et en cherche un quand il n'en a pas (ou un faux) ;
 * `crm:presse:classer-medias` ne lit plus QUE des sites vérifiés.
 *
 * ── LE MARQUEUR (à réutiliser) ───────────────────────────────────────────
 *
 * `companies.metadata.site_media` = {statut, url, motif?, le, v} — AUCUN
 * texte de la page, aucun nom :
 *   - `verifie`          le site existant (media.website, à défaut
 *                        companies.website) porte le nom du média ;
 *   - `trouve-verifie`   un site TROUVÉ (candidat tiré du nom ou d'une source
 *                        ouverte) porte le nom du média ;
 *   - `a-confirmer`      le nom correspond, mais la page n'a aucune preuve de
 *                        média hors liens (`preuveMedia`) : NON fiable ;
 *   - `non-conforme`     motif `nom`, `domaine-partage`, `liste-noire`,
 *                        `sans-mot-distinctif`, `redirection` (arrivée sur un
 *                        autre domaine) ou `parking` (domaine à vendre) ;
 *   - `injoignable` (dont toute réponse non 2xx), `robots-interdit`,
 *     `illisible` : pas lu, donc PAS vérifié ;
 *   - `sans-site`        aucun site, rien trouvé.
 * Un site n'est FIABLE que si `statut` ∈ `STATUTS_VERIFIES` (`verifie`,
 * `trouve-verifie`) : `estVerifie()` / `conditionSql()` / `urlVerifiee()`.
 * `url` est alors l'URL vérifiée (celle qu'il faut lire), même si
 * `media.website` est faux — on ne l'efface jamais (marquer, pas supprimer).
 *
 * ── LA PAGE N'EST JUGÉE QUE SI ───────────────────────────────────────────
 *
 *   - la réponse est 2xx (une 404 personnalisée qui reprend l'hôte n'est pas
 *     un site) ;
 *   - l'URL d'ARRIVÉE, après redirections, est sur le même domaine
 *     enregistrable que l'adresse essayée (`domaineEnregistrable`) et n'est
 *     pas un parkeur (`HOTES_PARKING` : sedo, dan.com, afternic, bodis…) ;
 *   - la page n'est pas une page de PARKING (`estParking` : « domaine à
 *     vendre », « for sale », « this domain », noms de parkeurs…).
 *
 * ── LA RÈGLE DE CORRESPONDANCE (`correspond()`) ──────────────────────────
 *
 * Mots DISTINCTIFS du nom (`motsDistinctifs`) : minuscules sans accent,
 * ponctuation → espace ; hors `MOTS_VIDES` (le, la, de…) et `MOTS_GENERIQUES`
 * (editions, media, presse, journal, sas, sarl, paris, france, radio, tv…) ;
 * 3 caractères au moins (ou 2 avec un chiffre : « m6 ») ; six au plus. Une
 * MARQUE À CHIFFRE garde son bloc : un mot générique suivi d'un nombre
 * (« France 3 », « France 24 ») donne le mot distinctif `france 3`.
 *
 * IDENTITÉ de la page : <title>, og:site_name, og:title, <h1> (zone
 * `identite`) — pas la méta description. On en RETIRE d'abord ce qui
 * ressemble à l'ADRESSE de l'hôte essayé et de l'hôte d'arrivée
 * (`sansHote`), extension comprise : l'hôte exact (avec ou sans `www.`) et
 * l'hôte points/tirets changés en espaces (`ouest france fr`). Une page qui
 * ne fait que recopier son adresse (« euronews.fr ») ne prouve donc rien.
 * L'étiquette NUE (« Ouest-France », « Paris-Normandie », « Libération »)
 * n'est pas retirée : c'est le nom même du titre ; le parking est arrêté par
 * `estParking`, la réponse 2xx et l'hôte d'arrivée.
 *
 * PARKING (`estParking`) : tous les signaux dans le titre (title, h1, méta,
 * og) ; les PHRASES de parkeur (`PARKING_FORTS` : « this domain may be for
 * sale », « buy this domain », « sedo parking », afternic…) dans TOUT le
 * corps, quelle que soit sa longueur ; les signaux AMBIGUS
 * (`PARKING_AMBIGUS` : « ce domaine est », « domaine à vendre », « parked »,
 * « godaddy ») dans le corps d'une page COURTE seulement (moins de
 * `PARKING_CORPS_MAX_MOTS` mots, moins de 3 <article>).
 *
 * DOMAINE ENREGISTRABLE : deux dernières étiquettes, trois sous un suffixe
 * double (`SUFFIXES_DOUBLES` : co.uk, tm.fr, com.au…) ; sur un hébergeur
 * PARTAGÉ (`HEBERGEURS_PARTAGES` : wixsite.com, blogspot.*, github.io…),
 * le sous-domaine du site.
 *
 * Un mot est TROUVÉ dans la page en mot entier (pluriel s/x admis), ou comme
 * DÉBUT d'un mot collé (« bfm » dans « BFMTV ») s'il a 3 caractères au moins
 * ET se lit aussi dans l'adresse. Il est trouvé dans l'ADRESSE (étiquette du
 * domaine, chemin) en sous-chaîne s'il a 4 caractères au moins, sinon comme
 * début, fin ou segment.
 *
 *   n = nombre de mots distinctifs, p = mots trouvés dans la PAGE,
 *   t = mots trouvés dans la page OU dans l'adresse.
 *   - n = 0 : jamais conforme (`sans-mot-distinctif`) ;
 *   - p = 0 : jamais conforme — l'adresse seule ne prouve rien ;
 *   - n = 1 : le mot est dans la page ET dans l'adresse ;
 *   - n = 2 : t = 2 ;
 *   - n ≥ 3 : t ≥ ⌈2n/3⌉.
 * Le nom est essayé sous chacune de ses formes (dénomination de la fiche, nom
 * de chaque ligne `media`) : une seule qui passe suffit.
 *
 * PREUVE DE MÉDIA (`preuveMedia`), exigée pour TOUT `verifie` /
 * `trouve-verifie`, HORS LIENS : un indice de média (actualité, rédaction,
 * abonnement, article…) dans le titre / h1 / méta / og ou dans le texte des
 * paragraphes sans leurs liens (zone `corps`), ou au moins 3 <article>, ou au
 * moins 3 dates. Sinon `a-confirmer` (« LA MONTAGNE » → station de ski ; page
 * « site en construction » ; parking à liens sponsorisés).
 *
 * Limites connues (faux NÉGATIFS, sûrs) : « FRANCE INFO », « RADIO FRANCE »
 * (que des mots génériques, sans chiffre) restent `sans-mot-distinctif` ;
 * « FRANCE 3 ALSACE » sur …/grand-est est refusé ; « M6 » sur 6play.fr aussi.
 *
 * DOMAINE PARTAGÉ : une adresse SANS chemin dont l'hôte est le site de plus
 * de `PARTAGE_MAX` fiches est non conforme D'OFFICE (sans lecture), sauf si
 * les mots distinctifs, accolés, se lisent dans l'étiquette du domaine.
 *
 * LISTE NOIRE (`estGenerique()`) : domaines génériques (paris.fr, france.fr,
 * media.fr…), plateformes et annuaires — jamais le site d'un média.
 */
final class SiteMedia
{
    public const CLE = 'site_media';

    /** Version des règles : la monter fait revérifier toutes les fiches. */
    public const VERSION = 1;

    public const VERIFIE = 'verifie';

    public const TROUVE_VERIFIE = 'trouve-verifie';

    public const A_CONFIRMER = 'a-confirmer';

    public const NON_CONFORME = 'non-conforme';

    public const INJOIGNABLE = 'injoignable';

    public const ROBOTS_INTERDIT = 'robots-interdit';

    public const ILLISIBLE = 'illisible';

    public const SANS_SITE = 'sans-site';

    /** @var list<string> */
    public const STATUTS_VERIFIES = [self::VERIFIE, self::TROUVE_VERIFIE];

    /** @var list<string> */
    public const STATUTS = [
        self::VERIFIE, self::TROUVE_VERIFIE, self::A_CONFIRMER, self::NON_CONFORME, self::INJOIGNABLE,
        self::ROBOTS_INTERDIT, self::ILLISIBLE, self::SANS_SITE,
    ];

    public const MOTIF_NOM = 'nom';

    public const MOTIF_PARTAGE = 'domaine-partage';

    public const MOTIF_LISTE_NOIRE = 'liste-noire';

    public const MOTIF_SANS_MOT = 'sans-mot-distinctif';

    public const MOTIF_REDIRECTION = 'redirection';

    public const MOTIF_PARKING = 'parking';

    /** Au-delà de ce nombre de fiches, un domaine est « partagé ». */
    public const PARTAGE_MAX = 3;

    /** Valeur de `media.website_method` d'un site trouvé et vérifié (16 caractères au plus). */
    public const METHODE = 'nom-verifie';

    /** @var list<string> */
    public const MOTS_VIDES = [
        'le', 'la', 'les', 'l', 'de', 'du', 'des', 'd', 'un', 'une', 'et', 'en', 'a', 'au', 'aux',
        'pour', 'par', 'sur', 'dans', 'avec', 'the', 'of', 'and',
    ];

    /** @var list<string> */
    public const MOTS_GENERIQUES = [
        // formes juridiques
        'sas', 'sasu', 'sarl', 'eurl', 'sa', 'sci', 'scop', 'snc', 'selarl', 'societe', 'ste', 'cie', 'compagnie',
        'holding', 'groupe', 'group', 'sem', 'gie', 'association', 'asso',
        // métier de l'édition et des médias
        'editions', 'edition', 'editeur', 'editeurs', 'media', 'medias', 'presse', 'press', 'journal',
        'journaux', 'magazine', 'magazines', 'mag', 'revue', 'revues', 'hebdo', 'hebdomadaire', 'quotidien',
        'mensuel', 'bimensuel', 'trimestriel', 'news', 'info', 'infos', 'information', 'informations', 'actu',
        'actus', 'actualite', 'actualites', 'tv', 'tele', 'television', 'radio', 'radios', 'fm', 'webradio', 'web',
        'site', 'online', 'digital', 'numerique', 'officiel', 'officielle', 'publication', 'publications',
        'production', 'productions', 'communication', 'communications', 'agence', 'studio', 'studios',
        'services', 'service', 'conseil', 'conseils', 'diffusion', 'multimedia', 'emission', 'chaine',
        // lieux trop larges
        'paris', 'france', 'francais', 'francaise', 'international', 'internationale', 'national', 'nationale',
        'regional', 'regionale', 'local', 'locale',
    ];

    /** Domaines génériques : jamais le site d'un média (suffixe compris). */
    public const DOMAINES_GENERIQUES = [
        'paris.fr', 'france.fr', 'media.fr', 'medias.fr', 'atelier.fr', 'bourse.fr', 'presse.fr', 'journal.fr',
        'info.fr', 'infos.fr', 'actu.com', 'actualite.fr', 'actualites.fr', 'news.fr', 'magazine.fr', 'radio.fr',
        'tv.fr', 'television.fr', 'edition.fr', 'editions.fr', 'groupe.fr', 'entreprise.fr', 'societe.fr',
        'france.com', 'paris.com', 'media.com', 'service-public.fr', 'gouv.fr', 'europa.eu',
        'facebook.com', 'fb.com', 'linkedin.com', 'instagram.com', 'twitter.com', 'x.com', 'youtube.com',
        'tiktok.com', 'wikipedia.org', 'wikidata.org', 'google.com', 'google.fr', 'pagesjaunes.fr',
        'societe.com', 'pappers.fr', 'verif.com', 'infogreffe.fr', 'manageo.fr', 'annuaire-entreprises.data.gouv.fr',
        'wordpress.com', 'blogspot.com', 'over-blog.com',
    ];

    /** Parkeurs et places de marché de domaines : une arrivée chez eux = parking. */
    public const HOTES_PARKING = [
        'sedo.com', 'sedoparking.com', 'dan.com', 'afternic.com', 'bodis.com', 'parkingcrew.net', 'hugedomains.com',
        'godaddy.com', 'above.com', 'undeveloped.com', 'domainmarket.com', 'buydomains.com', 'parklogic.com',
        'namecheap.com', 'atom.com', 'squadhelp.com', 'domainlore.co.uk', 'efty.com', 'brandbucket.com',
    ];

    /**
     * Signes FORTS de parking — des PHRASES de parkeur et des noms de
     * parkeurs, qu'aucun vrai média n'écrit sur sa page d'accueil : cherchés
     * dans TOUTE la page, quelle que soit sa longueur.
     */
    public const PARKING_FORTS = [
        'sedoparking', 'sedo parking', 'sedo domain parking', 'afternic', 'bodis', 'parkingcrew', 'hugedomains',
        'domain is for sale', 'domain may be for sale', 'this domain may be for sale', 'domain for sale',
        'buy this domain', 'buy this domain name', 'make an offer on this domain', 'this domain is parked',
        'domain parking', 'parked free', 'parked domain', 'nom de domaine a vendre', 'ce nom de domaine est a vendre',
        'acheter ce domaine', 'acheter ce nom de domaine', 'domaine parke', 'parking de domaine',
    ];

    /**
     * Signes AMBIGUS (un vrai média peut les écrire : « ce domaine est classé
     * grand cru », « GoDaddy rachète… ») : cherchés seulement dans le titre et
     * dans le corps d'une page COURTE sans articles.
     */
    public const PARKING_AMBIGUS = [
        'sedo', 'godaddy', 'dan com', 'parked', 'this domain', 'ce domaine est', 'domaine a vendre', 'ce nom de domaine',
    ];

    /** Signes de parking cherchés dans le TITRE seulement (titre, h1, méta). */
    public const PARKING_TITRE = ['a vendre', 'for sale', 'ce domaine', 'nom de domaine', 'domain name'];

    /** Indices de MÉDIA (mot entier, pluriel admis). */
    public const INDICES_MEDIA = [
        'actualite', 'actu', 'redaction', 'abonnement', 'abonnez', 'abonner', 'article', 'journal', 'journaux',
        'magazine', 'hebdomadaire', 'quotidien', 'mensuel', 'edition', 'emission', 'podcast', 'replay',
        'journaliste', 'newsletter', 'rubrique', 'reportage', 'chronique', 'a la une',
        // radio, télévision, presse (relecture A09) : un lecteur « écoutez en
        // direct » n'a souvent aucun paragraphe
        'radio', 'direct', 'en direct', 'ecoutez', 'ecouter', 'chaine', 'tv', 'tele', 'television', 'info',
        'presse', 'revue', 'numero', 'programme', 'antenne', 'grille',
    ];

    /** Suffixes publics à deux niveaux (domaine enregistrable sur trois étiquettes). */
    private const SUFFIXES_DOUBLES = [
        'co.uk', 'org.uk', 'ac.uk', 'me.uk', 'ltd.uk', 'plc.uk', 'net.uk', 'gov.uk',
        'com.fr', 'asso.fr', 'gouv.fr', 'tm.fr', 'nom.fr', 'presse.fr', 'prd.fr',
        'com.au', 'net.au', 'org.au', 'co.nz', 'org.nz', 'co.za', 'org.za', 'co.jp', 'ne.jp',
        'com.br', 'com.mx', 'com.ar', 'co.in', 'com.cn', 'com.tr', 'com.es', 'co.il', 'co.ma', 'qc.ca',
    ];

    /**
     * Hébergeurs PARTAGÉS (motifs de suffixe, regex) : le domaine enregistrable
     * y est le sous-domaine du site (`zz.wixsite.com`), pas l'hébergeur.
     */
    private const HEBERGEURS_PARTAGES = [
        'wixsite\.com', 'wordpress\.com', 'blogspot\.[a-z]{2,3}(?:\.[a-z]{2})?', 'over-blog\.[a-z]{2,3}', 'github\.io', 'netlify\.app',
        'webflow\.io', 'e-monsite\.com', 'jimdofree\.com', 'jimdosite\.com', 'weebly\.com', 'canalblog\.com',
        'hautetfort\.com', 'vercel\.app', 'pages\.dev', 'wix\.com', 'squarespace\.com', 'webnode\.fr',
        'webnode\.com', 'site123\.me', 'tumblr\.com', 'substack\.com', 'medium\.com', 'free\.fr', 'unblog\.fr',
        'skyrock\.com', 'eklablog\.com', 'kazeo\.com', 'centerblog\.net',
    ];

    /** Au-delà de ce nombre de mots dans le corps, la page n'est pas un parking. */
    private const PARKING_CORPS_MAX_MOTS = 120;

    /** Formes juridiques retirées d'un nom avant d'en tirer un domaine candidat. */
    private const FORMES_JURIDIQUES = ['sas', 'sasu', 'sarl', 'eurl', 'sa', 'sci', 'scop', 'snc', 'selarl', 'ste'];

    private const ARTICLES = ['le', 'la', 'les', 'l'];

    // ── Le marqueur ───────────────────────────────────────────────────────

    /** La fiche a-t-elle un site VÉRIFIÉ (ou trouvé et vérifié) ? */
    public static function estVerifie(int $companyId): bool
    {
        return DB::table('companies as c')->where('c.id', $companyId)->whereNull('c.deleted_at')
            ->whereRaw(self::conditionSql('c'))
            ->exists();
    }

    /**
     * SQL : la fiche `$alias` a un site vérifié — `metadata.site_media.statut`
     * ∈ (`verifie`, `trouve-verifie`). Aucun argument n'est une donnée
     * utilisateur.
     */
    public static function conditionSql(string $alias = 'companies'): string
    {
        $statuts = "'" . implode("','", self::STATUTS_VERIFIES) . "'";

        return "(({$alias}.metadata -> '" . self::CLE . "' ->> 'statut') IN ({$statuts}))";
    }

    /**
     * L'URL vérifiée d'un marqueur (`metadata.site_media`, tableau ou JSON
     * brut), prête pour `LecturePageAccueil::cible()` ; null si le site n'est
     * pas vérifié.
     */
    public static function urlVerifiee(mixed $marqueur): ?string
    {
        if (is_string($marqueur)) {
            $marqueur = json_decode($marqueur, true);
        }
        if (! is_array($marqueur) || ! in_array($marqueur['statut'] ?? null, self::STATUTS_VERIFIES, true)) {
            return null;
        }

        return is_string($marqueur['url'] ?? null) ? LecturePageAccueil::cible($marqueur['url']) : null;
    }

    // ── La règle de correspondance ───────────────────────────────────────

    /** Minuscules, sans accent, ponctuation → espace, entouré d'espaces. */
    public static function normaliser(string $texte): string
    {
        // Apostrophes (droite, courbes, modificateur) et élision : « l’actualité »
        // donne « l actualite » — jamais « lactualite ».
        $texte = str_replace(["'", '’', '‘', 'ʼ', '`', '´'], ' ', html_entity_decode($texte, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $t = Str::ascii(mb_strtolower($texte));

        return ' ' . trim((string) preg_replace('/[^a-z0-9]+/', ' ', $t)) . ' ';
    }

    /** @return list<string> */
    public static function motsDistinctifs(string $nom): array
    {
        $mots = [];
        $liste = explode(' ', trim(self::normaliser($nom)));
        foreach ($liste as $i => $mot) {
            $suivant = $liste[$i + 1] ?? '';
            // Marque à chiffre : « france 3 », « france 24 » — le bloc entier.
            if (in_array($mot, self::MOTS_GENERIQUES, true) && preg_match('/^\d{1,3}$/', $suivant) === 1) {
                $mots[$mot . ' ' . $suivant] = true;

                continue;
            }
            $assez = strlen($mot) >= 3 || (strlen($mot) === 2 && preg_match('/\d/', $mot) === 1);
            if ($assez && ! ctype_digit($mot) && ! in_array($mot, self::MOTS_VIDES, true)
                && ! in_array($mot, self::MOTS_GENERIQUES, true)) {
                $mots[$mot] = true;
            }
        }

        return array_slice(array_keys($mots), 0, 6);
    }

    /** L'hôte en minuscules, sans `www.` ; null si l'URL n'en a pas. */
    public static function hote(?string $url): ?string
    {
        $cible = LecturePageAccueil::cible($url);
        $hote = $cible === null ? null : parse_url($cible, PHP_URL_HOST);

        return is_string($hote) && $hote !== '' ? (string) preg_replace('/^www\./', '', $hote) : null;
    }

    /** L'étiquette du domaine : l'hôte sans `www.` ni extension, sans points ni tirets (`le-progres.fr` → `leprogres`). */
    public static function etiquette(string $hote): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', self::sansExtension($hote));
    }

    /** L'hôte sans `www.` ni extension, tirets et points gardés (`le-progres.fr` → `le-progres`). */
    private static function sansExtension(string $hote): string
    {
        $parts = explode('.', (string) preg_replace('/^www\./', '', strtolower($hote)));
        if (count($parts) > 1) {
            array_pop($parts);
        }

        return implode('.', $parts);
    }

    /** Le domaine ENREGISTRABLE d'un hôte (`www.edition.leprogres.fr` → `leprogres.fr`). */
    public static function domaineEnregistrable(string $hote): string
    {
        $hote = (string) preg_replace('/^www\./', '', strtolower(rtrim($hote, '.')));
        // Hébergeur partagé : chaque sous-domaine est un site distinct
        // (`a.wixsite.com` ≠ `b.wixsite.com`).
        foreach (self::HEBERGEURS_PARTAGES as $motif) {
            if (preg_match('/^([a-z0-9-]+\.)*?([a-z0-9-]+\.' . $motif . ')$/', $hote, $m) === 1) {
                return $m[2];
            }
        }
        $parts = explode('.', $hote);
        $n = count($parts);
        if ($n <= 2) {
            return implode('.', $parts);
        }
        $garde = in_array($parts[$n - 2] . '.' . $parts[$n - 1], self::SUFFIXES_DOUBLES, true) ? 3 : 2;

        return implode('.', array_slice($parts, -$garde));
    }

    /** L'hôte est-il celui d'un parkeur / d'une place de marché de domaines ? */
    public static function estHoteParking(string $hote): bool
    {
        return in_array(self::domaineEnregistrable($hote), self::HOTES_PARKING, true);
    }

    /** Le domaine est-il générique (liste noire, plateforme, annuaire, administration) ? */
    public static function estGenerique(string $hote): bool
    {
        $hote = (string) preg_replace('/^www\./', '', strtolower($hote));
        if (str_ends_with($hote, '.gouv.fr') || str_contains($hote, 'annuaire')) {
            return true;
        }
        foreach (self::DOMAINES_GENERIQUES as $g) {
            if ($hote === $g || str_ends_with($hote, '.' . $g)) {
                return true;
            }
        }

        return false;
    }

    /** L'URL a-t-elle un chemin (autre que la racine) ? */
    public static function aUnChemin(string $url): bool
    {
        $chemin = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        return trim($chemin, '/') !== '';
    }

    /**
     * L'identité de la page, NORMALISÉE, privée de toute forme des hôtes
     * donnés : une page qui recopie son adresse ne prouve rien.
     *
     * @param  list<string>  $hotes
     */
    public static function sansHote(string $identite, array $hotes): string
    {
        $brutes = [];
        $normalisees = [];
        foreach ($hotes as $hote) {
            $hote = (string) preg_replace('/^www\./', '', strtolower(trim($hote)));
            if ($hote === '') {
                continue;
            }
            // Seulement ce qui RESSEMBLE À UNE ADRESSE (extension comprise) :
            // l'étiquette nue (« Ouest-France », « Paris-Normandie ») est le
            // nom même du titre et reste. Le parking est arrêté ailleurs
            // (`estParking`, réponse 2xx, hôte d'arrivée).
            $brutes[] = 'www.' . $hote;
            $brutes[] = $hote;
            $normalisees[] = self::normaliser($hote);
        }
        usort($brutes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $texte = str_ireplace($brutes, ' ', html_entity_decode($identite, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $page = self::normaliser($texte);
        foreach ($normalisees as $forme) {
            if (trim($forme) === '') {
                continue;
            }
            while (str_contains($page, $forme)) {
                $page = str_replace($forme, ' ', $page);
            }
        }

        return $page;
    }

    /**
     * La page est-elle une page de PARKING (domaine à vendre) ?
     *
     * @param  array<string, string>  $zones  zones de `LecturePageAccueil::extraire`
     */
    public static function estParking(array $zones, int $articles = 0): bool
    {
        $titre = self::normaliser(($zones['identite'] ?? '') . ' . ' . ($zones['titre'] ?? ''));
        foreach (array_merge(self::PARKING_FORTS, self::PARKING_AMBIGUS, self::PARKING_TITRE) as $signe) {
            if (str_contains($titre, ' ' . $signe . ' ')) {
                return true;
            }
        }
        // Corps complet (liens compris) : les phrases FORTES partout…
        $corps = self::normaliser(($zones['menu'] ?? '') . ' . ' . ($zones['texte'] ?? '') . ' . ' . ($zones['corps'] ?? ''));
        foreach (self::PARKING_FORTS as $signe) {
            if (str_contains($corps, ' ' . $signe . ' ')) {
                return true;
            }
        }
        // … les AMBIGUS seulement sur une page COURTE sans articles.
        if ($articles >= 3 || str_word_count(trim($corps)) >= self::PARKING_CORPS_MAX_MOTS) {
            return false;
        }
        foreach (self::PARKING_AMBIGUS as $signe) {
            if (str_contains($corps, ' ' . $signe . ' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * La page porte-t-elle un indice de MÉDIA ?
     *
     * @param  array<string, string>  $zones
     */
    public static function indiceMedia(array $zones): bool
    {
        // HORS LIENS : titre, h1, méta, og, et le corps des paragraphes sans
        // le texte de leurs liens (`corps`) — jamais le menu ni des liens
        // sponsorisés « Actualités ».
        $tout = self::normaliser(($zones['identite'] ?? '') . ' . ' . ($zones['titre'] ?? '') . ' . ' . ($zones['corps'] ?? ''));
        foreach (self::INDICES_MEDIA as $mot) {
            if (ClassementMedia::contient($tout, $mot)) {
                return true;
            }
        }

        return false;
    }

    /**
     * PREUVE DE MÉDIA hors liens, exigée pour tout `verifie` : un indice de
     * média (`indiceMedia`), ou au moins 3 <article> portant chacun au moins
     * `LecturePageAccueil::MOTS_ARTICLE` mots HORS LIENS (`articles_texte`),
     * ou au moins 3 dates écrites HORS LIENS (`dates_texte` : texte des
     * paragraphes sans leurs liens, <time> hors <a>). Les compteurs bruts
     * (`articles`, `dates`, liens compris) ne prouvent rien ici : trois
     * liens « Offre 01/10/2026 » ou trois <article> vides autour de liens
     * sponsorisés ne font pas un média.
     *
     * @param  array<string, string>  $zones
     * @param  array{articles: int, dates: int, articles_texte?: int, dates_texte?: int}  $structure
     */
    public static function preuveMedia(array $zones, array $structure): bool
    {
        return self::indiceMedia($zones) || ($structure['articles_texte'] ?? 0) >= 3 || ($structure['dates_texte'] ?? 0) >= 3;
    }

    /**
     * Le nom (sous l'une de ses formes) correspond-il à la page lue à cette
     * adresse ? Voir la règle en tête de classe. `n` : nombre de mots
     * distinctifs de la forme qui a passé (0 sinon).
     *
     * @param  list<string>  $noms  formes du nom (dénomination, noms des lignes `media`)
     * @param  string  $identite  zone `identite` de la page (texte brut)
     * @param  string  $url  adresse essayée (sortie de `LecturePageAccueil::cible`)
     * @param  list<string>  $autresHotes  hôtes à retirer aussi de la page (hôte d'arrivée)
     * @return array{ok: bool, motif: ?string, n: int}
     */
    public static function correspond(array $noms, string $identite, string $url, array $autresHotes = []): array
    {
        $hote = (string) parse_url($url, PHP_URL_HOST);
        $page = self::sansHote($identite, array_merge([$hote], $autresHotes));
        $etiquette = self::etiquette($hote);
        $chemin = trim(self::normaliser((string) (parse_url($url, PHP_URL_PATH) ?? '')));
        $segments = array_merge(
            explode('-', (string) preg_replace('/^www\./', '', strtolower($hote))),
            explode('.', strtolower($hote)),
            explode(' ', $chemin),
        );
        $cheminCompact = str_replace(' ', '', $chemin);

        $auMoinsUnMot = false;
        foreach ($noms as $nom) {
            $mots = self::motsDistinctifs($nom);
            $n = count($mots);
            if ($n === 0) {
                continue;
            }
            $auMoinsUnMot = true;
            $p = 0;
            $t = 0;
            $dansAdresse = 0;
            foreach ($mots as $mot) {
                $adresse = self::dansAdresse(str_replace(' ', '', $mot), $etiquette, $cheminCompact, $segments);
                $dansPage = ClassementMedia::contient($page, $mot)
                    // « bfm » dans « BFMTV » : début d'un mot collé, ET dans l'adresse.
                    || ($adresse && strlen($mot) >= 3 && ! str_contains($mot, ' ')
                        && preg_match('/ ' . preg_quote($mot, '/') . '[a-z0-9]+ /', $page) === 1);
                $p += $dansPage ? 1 : 0;
                $t += ($dansPage || $adresse) ? 1 : 0;
                $dansAdresse += $adresse ? 1 : 0;
            }
            if ($p === 0) {
                continue;
            }
            $ok = match (true) {
                $n === 1 => $dansAdresse === 1,
                $n === 2 => $t === 2,
                default => $t >= (int) ceil(2 * $n / 3),
            };
            if ($ok) {
                return ['ok' => true, 'motif' => null, 'n' => $n];
            }
        }

        return ['ok' => false, 'motif' => $auMoinsUnMot ? self::MOTIF_NOM : self::MOTIF_SANS_MOT, 'n' => 0];
    }

    /**
     * Le jugement COMPLET d'une page lue à l'adresse `$cible` : code 2xx,
     * arrivée sur le même domaine et hors parkeur, pas de page de parking,
     * puis `correspond()` et la preuve de média hors liens (`preuveMedia`).
     *
     * @param  list<string>  $noms
     * @param  array{statut: string, zones: array<string, string>, structure: array{articles: int, dates: int, articles_texte?: int, dates_texte?: int}, code?: int, finale?: string}  $lu
     * @return array{0: string, 1: string, 2: ?string} [statut, url, motif]
     */
    public static function juger(array $noms, string $cible, array $lu): array
    {
        $code = $lu['code'] ?? 0;
        if ($code < 200 || $code >= 300) {
            return [self::INJOIGNABLE, $cible, null];
        }
        $hote = (string) parse_url($cible, PHP_URL_HOST);
        $arrivee = (string) parse_url($lu['finale'] ?? $cible, PHP_URL_HOST);
        if ($arrivee === '' || self::estHoteParking($arrivee)) {
            return [self::NON_CONFORME, $cible, self::MOTIF_PARKING];
        }
        if (self::domaineEnregistrable($arrivee) !== self::domaineEnregistrable($hote)) {
            return [self::NON_CONFORME, $cible, self::MOTIF_REDIRECTION];
        }
        if (self::estParking($lu['zones'], $lu['structure']['articles'])) {
            return [self::NON_CONFORME, $cible, self::MOTIF_PARKING];
        }
        $r = self::correspond($noms, (string) ($lu['zones']['identite'] ?? ''), $cible, [$arrivee]);
        if (! $r['ok']) {
            return [self::NON_CONFORME, $cible, $r['motif']];
        }
        if (! self::preuveMedia($lu['zones'], $lu['structure'])) {
            return [self::A_CONFIRMER, $cible, null];
        }

        return [self::VERIFIE, $cible, null];
    }

    /**
     * Domaine partagé SANS correspondance : les mots distinctifs d'aucune
     * forme du nom, accolés, ne se lisent dans l'étiquette du domaine.
     *
     * @param  list<string>  $noms
     */
    public static function partageSansCorrespondance(array $noms, string $hote): bool
    {
        $etiquette = self::etiquette($hote);
        foreach ($noms as $nom) {
            $mots = self::motsDistinctifs($nom);
            if ($mots !== [] && str_contains($etiquette, str_replace(' ', '', implode('', $mots)))) {
                return false;
            }
        }

        return true;
    }

    /** @param  list<string>  $segments */
    private static function dansAdresse(string $mot, string $etiquette, string $cheminCompact, array $segments): bool
    {
        if (in_array($mot, $segments, true)) {
            return true;
        }
        if (strlen($mot) >= 4) {
            return str_contains($etiquette, $mot) || ($cheminCompact !== '' && str_contains($cheminCompact, $mot));
        }

        return str_starts_with($etiquette, $mot) || str_ends_with($etiquette, $mot);
    }

    // ── Les candidats ────────────────────────────────────────────────────

    /**
     * Des adresses CANDIDATES tirées du nom, sans service payant : le nom
     * réduit à ses mots (formes juridiques retirées) ; en `.fr` d'abord puis
     * `.com` ; mots accolés d'abord puis avec des tirets ; avec puis sans
     * l'article de tête (le, la, les, l'). Jamais un domaine générique ;
     * étiquette de 4 à 63 caractères (2 si elle contient un chiffre : m6.fr) ;
     * au plus `$max`.
     *
     * @param  list<string>  $noms
     * @return list<string> URL `https://hôte/`
     */
    public static function candidats(array $noms, int $max): array
    {
        $hotes = [];
        foreach ($noms as $nom) {
            $mots = array_values(array_filter(
                explode(' ', trim(self::normaliser($nom))),
                static fn (string $m): bool => $m !== '' && ! in_array($m, self::FORMES_JURIDIQUES, true),
            ));
            if ($mots === [] || self::motsDistinctifs($nom) === []) {
                continue;
            }
            $formes = [$mots];
            if (count($mots) > 1 && in_array($mots[0], self::ARTICLES, true)) {
                $formes[] = array_slice($mots, 1);
            }
            // Ordre : accolé en .fr (avec, puis sans article), avec tirets en
            // .fr, puis les mêmes en .com.
            foreach (['fr', 'com'] as $extension) {
                foreach (['', '-'] as $joint) {
                    foreach ($formes as $forme) {
                        $hotes[implode($joint, $forme) . '.' . $extension] = true;
                    }
                }
            }
        }

        $sortie = [];
        foreach (array_keys($hotes) as $hote) {
            $etiquette = substr($hote, 0, (int) strrpos($hote, '.'));
            $longueurMin = preg_match('/\d/', $etiquette) === 1 ? 2 : 4;
            if (strlen($etiquette) < $longueurMin || strlen($etiquette) > 63 || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $etiquette) !== 1
                || in_array($etiquette, self::MOTS_GENERIQUES, true) || self::estGenerique($hote)) {
                continue;
            }
            $sortie[] = 'https://' . $hote . '/';
            if (count($sortie) >= $max) {
                break;
            }
        }

        return $sortie;
    }
}
