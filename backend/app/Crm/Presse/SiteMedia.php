<?php

namespace App\Crm\Presse;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LE SITE D'UN MÉDIA EST-IL LE SIEN ? — une seule définition (constat en
 * production du 2026-10-01).
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
 * texte de la page, aucun nom, aucune adresse :
 *   - `verifie`          le site existant (media.website, à défaut
 *                        companies.website) porte le nom du média ;
 *   - `trouve-verifie`   un site TROUVÉ (candidat tiré du nom ou d'une source
 *                        ouverte) porte le nom du média ;
 *   - `non-conforme`     le site existant ne porte pas le nom (motif : `nom`,
 *                        `domaine-partage`, `liste-noire`,
 *                        `sans-mot-distinctif`) et rien n'a été trouvé ;
 *   - `injoignable`, `robots-interdit`, `illisible` : le site existant n'a pas
 *                        pu être lu (il n'est PAS vérifié pour autant) ;
 *   - `sans-site`        aucun site, rien trouvé.
 * Un site n'est FIABLE que si `statut` ∈ `STATUTS_VERIFIES` (`verifie`,
 * `trouve-verifie`) : `estVerifie()` / `conditionSql()`. `url` est alors
 * l'URL vérifiée (celle qu'il faut lire), même si `media.website` est faux —
 * on ne l'efface jamais (ordre permanent de Will : marquer, pas supprimer).
 *
 * ── LA RÈGLE DE CORRESPONDANCE (`correspond()`) ──────────────────────────
 *
 * Mots DISTINCTIFS du nom (`motsDistinctifs`) : minuscules sans accent,
 * ponctuation → espace ; hors `MOTS_VIDES` (le, la, de…) et `MOTS_GENERIQUES`
 * (editions, media, presse, journal, sas, sarl, paris, france, radio, tv…) ;
 * 3 caractères au moins (ou 2 avec un chiffre : « m6 ») ; six au plus.
 *
 * IDENTITÉ de la page : <title>, og:site_name, og:title, <h1> (zone
 * `identite` de `LecturePageAccueil::extraire`) — pas la méta description,
 * qui peut citer n'importe quoi. Un mot y est TROUVÉ en mot entier (pluriel
 * en s/x admis). Un mot est aussi trouvé dans l'ADRESSE (étiquette du domaine
 * sans `www.` ni extension, ou chemin de l'URL) : en sous-chaîne s'il a 4
 * lettres au moins, sinon comme début, fin ou segment.
 *
 *   n = nombre de mots distinctifs, p = mots trouvés dans la PAGE,
 *   t = mots trouvés dans la page OU dans l'adresse.
 *   - n = 0 : jamais conforme (`sans-mot-distinctif`) ;
 *   - p = 0 : jamais conforme — l'adresse seule ne prouve rien (un candidat
 *     tiré du nom contient toujours le nom) ;
 *   - n = 1 : le mot est dans la page ET dans l'adresse ;
 *   - n = 2 : t = 2 ;
 *   - n ≥ 3 : t ≥ ⌈2n/3⌉.
 * Le nom est essayé sous chacune de ses formes (dénomination de la fiche, nom
 * de chaque ligne `media`) : une seule qui passe suffit.
 *
 * DOMAINE PARTAGÉ : une adresse SANS chemin dont l'hôte est le site de plus
 * de `PARTAGE_MAX` fiches est non conforme D'OFFICE (sans lecture), sauf si
 * les mots distinctifs, accolés, se lisent dans l'étiquette du domaine
 * (`leprogres.fr` partagé par les éditions du Progrès). Une adresse avec un
 * chemin (`france.tv/france-5/emission/`) est lue normalement.
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

    public const NON_CONFORME = 'non-conforme';

    public const INJOIGNABLE = 'injoignable';

    public const ROBOTS_INTERDIT = 'robots-interdit';

    public const ILLISIBLE = 'illisible';

    public const SANS_SITE = 'sans-site';

    /** @var list<string> */
    public const STATUTS_VERIFIES = [self::VERIFIE, self::TROUVE_VERIFIE];

    /** @var list<string> */
    public const STATUTS = [self::VERIFIE, self::TROUVE_VERIFIE, self::NON_CONFORME, self::INJOIGNABLE, self::ROBOTS_INTERDIT, self::ILLISIBLE, self::SANS_SITE];

    public const MOTIF_NOM = 'nom';

    public const MOTIF_PARTAGE = 'domaine-partage';

    public const MOTIF_LISTE_NOIRE = 'liste-noire';

    public const MOTIF_SANS_MOT = 'sans-mot-distinctif';

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
        $t = Str::ascii(mb_strtolower(html_entity_decode($texte, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return ' ' . trim((string) preg_replace('/[^a-z0-9]+/', ' ', $t)) . ' ';
    }

    /** @return list<string> */
    public static function motsDistinctifs(string $nom): array
    {
        $mots = [];
        foreach (explode(' ', trim(self::normaliser($nom))) as $mot) {
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
        $parts = explode('.', (string) preg_replace('/^www\./', '', strtolower($hote)));
        if (count($parts) > 1) {
            array_pop($parts);
        }

        return (string) preg_replace('/[^a-z0-9]/', '', implode('', $parts));
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
     * Le nom (sous l'une de ses formes) correspond-il à la page lue à cette
     * adresse ? Voir la règle en tête de classe.
     *
     * @param  list<string>  $noms  formes du nom (dénomination, noms des lignes `media`)
     * @param  string  $identite  zone `identite` de la page (texte brut)
     * @param  string  $url  adresse lue (sortie de `LecturePageAccueil::cible`)
     * @return array{ok: bool, motif: ?string}
     */
    public static function correspond(array $noms, string $identite, string $url): array
    {
        $page = self::normaliser($identite);
        $hote = (string) parse_url($url, PHP_URL_HOST);
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
                $dansPage = ClassementMedia::contient($page, $mot);
                $adresse = self::dansAdresse($mot, $etiquette, $cheminCompact, $segments);
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
                return ['ok' => true, 'motif' => null];
            }
        }

        return ['ok' => false, 'motif' => $auMoinsUnMot ? self::MOTIF_NOM : self::MOTIF_SANS_MOT];
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
            if ($mots !== [] && str_contains($etiquette, implode('', $mots))) {
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
     * l'article de tête (le, la, les, l'). Jamais un domaine générique ; étiquette de 4 à 63
     * caractères ; au plus `$max`.
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
            if (strlen($etiquette) < 4 || strlen($etiquette) > 63 || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $etiquette) !== 1
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
