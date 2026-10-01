<?php

namespace App\Crm\Presse;

use App\Services\Http\SsrfGuard;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;

/**
 * LIRE LA PAGE D'UN MÉDIA — poliment, et sans se faire déborder (chantier F,
 * 2026-10-01 ; durci après la relecture A09 de #270).
 *
 * La page lue est l'URL du média TELLE QU'ELLE EST STOCKÉE, chemin compris
 * (`france.tv/france-5/c-dans-l-air/`, `actu.fr/lyon`) : l'accueil d'un
 * groupe ne dit rien de l'émission ou de l'édition locale qu'il héberge.
 *
 * Pour un paquet d'URL :
 *
 *   1. `robots.txt` de chaque ORIGINE (schéma + hôte + port), une requête par
 *      origine. Chaque URL n'est lue que si `robots.txt` autorise SON chemin
 *      pour notre agent (`AGENT_ROBOTS`) ou pour `*` — correspondance la plus
 *      longue, `Allow` gagnant à égalité (RFC 9309). robots.txt absent (4xx) :
 *      autorisé ; serveur en erreur (5xx), robots.txt illisible ou trop gros,
 *      ou `Crawl-delay` supérieur à `CRAWL_DELAY_MAX` s : interdit (prudence).
 *   2. les pages autorisées, par TOURS : à chaque tour, au plus UNE page par
 *      origine, et un délai par domaine (`$delaiMs`, 1 s par défaut, ou le
 *      `Crawl-delay` s'il est plus long) avant chaque tour. Un domaine n'est
 *      donc jamais interrogé deux fois à la fois.
 *
 * Chaque requête :
 *   - User-Agent IDENTIFIABLE (`USER_AGENT`) ; ports 80 et 443 seulement ;
 *   - garde SSRF (`SsrfGuard::verifier`, IPv4 ET IPv6, toutes les adresses A
 *     et AAAA) et IP vérifiée ÉPINGLÉE sur la connexion (CURLOPT_RESOLVE) —
 *     curl ne résout pas le nom une seconde fois (rebinding) ;
 *   - redirections suivies À LA MAIN (3 au plus), chaque saut revérifié et
 *     épinglé de nouveau ;
 *   - en-têtes jugés DÈS leur arrivée (`on_headers`) : Content-Length au-delà
 *     du plafond, ou type autre que HTML pour une page → transfert coupé ;
 *   - corps écrit dans un `FluxBorne` : le transfert est INTERROMPU au premier
 *     octet au-delà de `CORPS_MAX` / `ROBOTS_MAX` ; rien n'est gardé au-delà ;
 *   - décompression automatique COUPÉE (`decode_content` faux) : gzip et
 *     deflate sont décompressés ici, morceau par morceau, et la lecture est
 *     abandonnée dès que la taille DÉCOMPRESSÉE dépasse le plafond (bombe
 *     gzip) ; tout autre encodage (br…) est refusé.
 *   Une page refusée pour ces raisons est `illisible` : la fiche est classée
 *   par son nom et MARQUÉE, donc sautée à la relance — jamais relue en boucle.
 *
 * Rien n'est gardé : la page est réduite en mémoire à quelques zones de texte
 * (`extraire`) que `ClassementMedia` réduit à son tour à des étiquettes, et
 * `SiteMedia` à un oui / non (le site porte-t-il le nom du média ?). Aucun
 * texte, aucune adresse, aucun nom ne sort de cette classe vers la base.
 */
final class LecturePageAccueil
{
    public const USER_AGENT = 'AxionCRM-ClassementMedias/1.0 (+https://axion-ia.com; contact@axion-ia.com)';

    /** Jeton cherché dans les groupes `User-agent` de robots.txt (minuscules). */
    public const AGENT_ROBOTS = 'axioncrm';

    public const CRAWL_DELAY_MAX = 10.0;

    /** Plafond d'une page, en octets reçus ET en octets décompressés. */
    public const CORPS_MAX = 1_500_000;

    public const ROBOTS_MAX = 500_000;

    /** @var list<int> */
    public const PORTS = [80, 443];

    private const REDIRECTIONS_MAX = 3;

    /** Morceau d'entrée de la décompression : 1 Kio ne peut donner qu'environ 1 Mio. */
    private const MORCEAU_DECOMPRESSION = 1024;

    /** Bornes des zones gardées en mémoire (caractères). */
    private const BORNES = ['titre' => 4000, 'menu' => 8000, 'texte' => 20000, 'identite' => 2000];

    public const STATUT_LU = 'site';

    public const STATUT_ROBOTS = 'robots-interdit';

    public const STATUT_INJOIGNABLE = 'injoignable';

    public const STATUT_ILLISIBLE = 'illisible';

    /** @var callable(int): void */
    private $dormir;

    /**
     * @param  callable(int): void|null  $dormir  attente en millisecondes (remplaçable en test)
     */
    public function __construct(
        private readonly int $concurrence = 4,
        private readonly int $timeout = 6,
        private readonly int $delaiMs = 1000,
        ?callable $dormir = null,
    ) {
        $this->dormir = $dormir ?? static function (int $ms): void {
            if ($ms > 0) {
                usleep($ms * 1000);
            }
        };
    }

    /**
     * L'URL à lire pour un site tel que la base le stocke (`exemple.fr`,
     * `http://www.exemple.fr/lyon/`…) : schéma, hôte en minuscules, port s'il
     * est explicite, CHEMIN et requête gardés, fragment retiré. Null si elle
     * n'est pas lisible (schéma, hôte, port hors 80/443).
     */
    public static function cible(?string $site): ?string
    {
        $site = trim((string) $site);
        if ($site === '') {
            return null;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $site) !== 1) {
            $site = 'https://' . ltrim($site, '/');
        }
        $parts = parse_url($site);
        if ($parts === false) {
            return null;
        }
        $schema = strtolower($parts['scheme'] ?? '');
        $hote = strtolower($parts['host'] ?? '');
        if (! in_array($schema, ['http', 'https'], true) || $hote === '' || ! str_contains($hote, '.')
            || preg_match('/^[a-z0-9.-]+$/', $hote) !== 1) {
            return null;
        }
        $port = $parts['port'] ?? null;
        if ($port !== null && ! in_array($port, self::PORTS, true)) {
            return null;
        }
        $chemin = $parts['path'] ?? '';
        if ($chemin === '' || $chemin[0] !== '/') {
            $chemin = '/' . $chemin;
        }
        if (preg_match('/\s/', $chemin) === 1) {
            return null;
        }
        $requete = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

        return $schema . '://' . $hote . ($port !== null ? ':' . $port : '') . $chemin . $requete;
    }

    /** L'origine `schéma://hôte[:port]` d'une URL rendue par `cible()`. */
    public static function origine(string $url): string
    {
        $schema = (string) parse_url($url, PHP_URL_SCHEME);
        $hote = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        return $schema . '://' . $hote . (is_int($port) ? ':' . $port : '');
    }

    /** Le chemin (et la requête) qu'évalue robots.txt pour une URL. */
    public static function cheminRobots(string $url): string
    {
        $chemin = (string) (parse_url($url, PHP_URL_PATH) ?? '/');
        $requete = parse_url($url, PHP_URL_QUERY);

        return ($chemin === '' ? '/' : $chemin) . (is_string($requete) && $requete !== '' ? '?' . $requete : '');
    }

    /**
     * Lit les URL données (dédoublonnées), sorties de `cible()`.
     *
     * @param  list<string>  $cibles
     * @return array<string, array{statut: string, zones: array<string, string>, structure: array{articles: int, dates: int}}>
     */
    public function lire(array $cibles): array
    {
        $parOrigine = [];
        foreach (array_values(array_unique($cibles)) as $cible) {
            $parOrigine[self::origine($cible)][] = $cible;
        }
        $origines = array_keys($parOrigine);
        $resultats = [];

        // ── 1. robots.txt, une requête par origine ─────────────────────────
        $debut = hrtime(true);
        $robots = $this->recuperer(array_map(static fn (string $o): string => $o . '/robots.txt', $origines), self::ROBOTS_MAX, false);
        $attenteMs = $this->delaiMs;
        $files = [];
        foreach ($origines as $i => $origine) {
            $r = $robots[$i];
            $statutOrigine = null;
            $contenu = '';
            if ($r['statut'] === self::STATUT_INJOIGNABLE) {
                $statutOrigine = self::STATUT_INJOIGNABLE;
            } elseif ($r['statut'] === self::STATUT_ILLISIBLE || $r['code'] >= 500) {
                $statutOrigine = self::STATUT_ROBOTS;
            } elseif ($r['code'] >= 200 && $r['code'] < 300) {
                $contenu = $r['corps'];
            }
            $delai = $statutOrigine === null ? self::robotsAutorise($contenu, self::AGENT_ROBOTS, '/')['delai'] : 0.0;
            if ($delai > self::CRAWL_DELAY_MAX) {
                $statutOrigine = self::STATUT_ROBOTS;
            }
            foreach ($parOrigine[$origine] as $cible) {
                if ($statutOrigine !== null) {
                    $resultats[$cible] = self::echec($statutOrigine);
                } elseif (! self::robotsAutorise($contenu, self::AGENT_ROBOTS, self::cheminRobots($cible))['autorise']) {
                    $resultats[$cible] = self::echec(self::STATUT_ROBOTS);
                } else {
                    $files[$origine][] = $cible;
                    $attenteMs = max($attenteMs, (int) ceil($delai * 1000));
                }
            }
        }

        // ── 2. les pages, par tours : une page par origine et par tour ──────
        $ecoule = (int) ((hrtime(true) - $debut) / 1_000_000);
        $attente = max(0, $attenteMs - $ecoule);
        while ($files !== []) {
            ($this->dormir)($attente);
            $tour = [];
            foreach (array_keys($files) as $origine) {
                $tour[] = (string) array_shift($files[$origine]);
                if ($files[$origine] === []) {
                    unset($files[$origine]);
                }
            }
            $pages = $this->recuperer($tour, self::CORPS_MAX, true);
            foreach ($tour as $i => $cible) {
                $p = $pages[$i];
                if ($p['statut'] !== self::STATUT_LU) {
                    $resultats[$cible] = self::echec($p['statut']);
                } elseif ($p['code'] < 200 || $p['code'] >= 300) {
                    $resultats[$cible] = self::echec(self::STATUT_INJOIGNABLE);
                } else {
                    $resultats[$cible] = ['statut' => self::STATUT_LU] + self::extraire($p['corps']);
                }
            }
            $attente = $attenteMs;
        }

        return $resultats;
    }

    /**
     * GET concurrents (au plus `$concurrence` à la fois), redirections suivies
     * à la main, chaque saut vérifié et épinglé. Rend, dans l'ordre donné :
     * `site` (lu : code et corps décompressé, plafonné), `illisible` (trop
     * gros, type refusé, encodage inconnu) ou `injoignable`.
     *
     * @param  list<string>  $urls
     * @return array<int, array{statut: string, code: int, corps: string}>
     */
    private function recuperer(array $urls, int $max, bool $html): array
    {
        $sorties = [];
        $courantes = $urls;
        $sauts = array_fill_keys(array_keys($urls), 0);
        while ($courantes !== []) {
            $lot = [];
            foreach ($courantes as $i => $url) {
                $v = SsrfGuard::verifier($url, self::PORTS);
                if (! $v['ok']) {
                    $sorties[$i] = ['statut' => self::STATUT_INJOIGNABLE, 'code' => 0, 'corps' => ''];

                    continue;
                }
                $lot[$i] = ['url' => $url, 'ip' => $v['ip']];
            }
            if ($lot === []) {
                break;
            }

            $flux = [];
            $refus = [];
            $timeout = max(1, $this->timeout);
            try {
                $reponses = Http::pool(function (Pool $pool) use ($lot, $max, $html, $timeout, &$flux, &$refus): array {
                    $requetes = [];
                    foreach ($lot as $i => $l) {
                        [$ressource, $id] = FluxBorne::ouvrir($max);
                        $flux[$i] = $id;
                        $requetes[] = $pool->as('r' . $i)
                            ->timeout($timeout)
                            ->connectTimeout(min(3, $timeout))
                            ->withOptions([
                                'allow_redirects' => false,
                                'decode_content' => false,
                                'sink' => $ressource,
                                'on_headers' => static function (ResponseInterface $r) use ($i, $max, $html, &$refus): void {
                                    if (! self::entetesAcceptables($r, $max, $html)) {
                                        $refus[$i] = true;

                                        throw new \RuntimeException('Réponse refusée dès les en-têtes.');
                                    }
                                },
                            ] + SsrfGuard::optionsEpinglage($l['url'], $l['ip']))
                            ->withHeaders([
                                'User-Agent' => self::USER_AGENT,
                                'Accept' => $html ? 'text/html,application/xhtml+xml;q=0.9' : 'text/plain,*/*;q=0.1',
                                'Accept-Encoding' => 'gzip, deflate',
                            ])
                            ->get($l['url']);
                    }

                    return $requetes;
                }, max(1, $this->concurrence));
            } catch (\Throwable) {
                $reponses = [];
            }

            $suivantes = [];
            foreach ($lot as $i => $l) {
                $id = $flux[$i] ?? null;
                $depasse = $id !== null && FluxBorne::depasse($id);
                $r = $reponses['r' . $i] ?? null;
                if ($depasse || isset($refus[$i])) {
                    $sorties[$i] = ['statut' => self::STATUT_ILLISIBLE, 'code' => 0, 'corps' => ''];
                } elseif (! $r instanceof Response) {
                    $sorties[$i] = ['statut' => self::STATUT_INJOIGNABLE, 'code' => 0, 'corps' => ''];
                } elseif ($r->status() >= 300 && $r->status() < 400 && $r->header('Location') !== '') {
                    if ($sauts[$i] >= self::REDIRECTIONS_MAX) {
                        $sorties[$i] = ['statut' => self::STATUT_INJOIGNABLE, 'code' => 0, 'corps' => ''];
                    } else {
                        $sauts[$i]++;
                        $suivantes[$i] = (string) UriResolver::resolve(new Uri($l['url']), new Uri($r->header('Location')));
                    }
                } elseif (! self::entetesAcceptables($r->toPsrResponse(), $max, $html)) {
                    $sorties[$i] = ['statut' => self::STATUT_ILLISIBLE, 'code' => 0, 'corps' => ''];
                } else {
                    $corps = self::corps($r->toPsrResponse(), $max);
                    $sorties[$i] = $corps === null
                        ? ['statut' => self::STATUT_ILLISIBLE, 'code' => 0, 'corps' => '']
                        : ['statut' => self::STATUT_LU, 'code' => $r->status(), 'corps' => $corps];
                }
                if ($id !== null) {
                    FluxBorne::liberer($id);
                }
            }
            $courantes = $suivantes;
        }
        ksort($sorties);

        return $sorties;
    }

    /**
     * Les en-têtes suffisent-ils à refuser ? Content-Length au-delà du plafond ;
     * pour une page, un type déclaré qui n'est pas du HTML. (Les redirections
     * passent : elles n'ont pas de corps utile.)
     */
    public static function entetesAcceptables(ResponseInterface $r, int $max, bool $html): bool
    {
        $code = $r->getStatusCode();
        if ($code >= 300 && $code < 400) {
            return true;
        }
        $longueur = $r->getHeaderLine('Content-Length');
        if ($longueur !== '' && ctype_digit($longueur) && (int) $longueur > $max) {
            return false;
        }
        $type = strtolower($r->getHeaderLine('Content-Type'));

        return ! $html || $code >= 400 || $type === '' || str_contains($type, 'html');
    }

    /**
     * Le corps reçu, lu au plus `$max` octets, puis décompressé au plus `$max`
     * octets (gzip, deflate). Null : trop gros, ou encodage refusé.
     */
    public static function corps(ResponseInterface $r, int $max): ?string
    {
        $flux = $r->getBody();
        if ($flux->isSeekable()) {
            $flux->rewind();
        }
        $brut = '';
        while (! $flux->eof()) {
            $morceau = $flux->read(65536);
            if ($morceau === '') {
                break;
            }
            $brut .= $morceau;
            if (strlen($brut) > $max) {
                return null;
            }
        }

        $encodage = strtolower(trim($r->getHeaderLine('Content-Encoding')));
        if ($encodage === '' || $encodage === 'identity') {
            return $brut;
        }
        $mode = match ($encodage) {
            'gzip', 'x-gzip' => ZLIB_ENCODING_GZIP,
            'deflate' => ZLIB_ENCODING_DEFLATE,
            default => null,
        };
        if ($mode === null) {
            return null;
        }

        return self::decompresser($brut, $mode, $max);
    }

    /** Décompression morceau par morceau, abandonnée au-delà de `$max` octets. */
    public static function decompresser(string $brut, int $mode, int $max): ?string
    {
        $contexte = @inflate_init($mode);
        if ($contexte === false) {
            return null;
        }
        $sortie = '';
        $longueur = strlen($brut);
        for ($i = 0; $i < $longueur; $i += self::MORCEAU_DECOMPRESSION) {
            $morceau = @inflate_add($contexte, substr($brut, $i, self::MORCEAU_DECOMPRESSION), ZLIB_SYNC_FLUSH);
            if ($morceau === false) {
                return null;
            }
            $sortie .= $morceau;
            if (strlen($sortie) > $max) {
                return null;
            }
        }

        return $sortie;
    }

    /** @return array{statut: string, zones: array<string, string>, structure: array{articles: int, dates: int}} */
    private static function echec(string $statut): array
    {
        return ['statut' => $statut, 'zones' => [], 'structure' => ['articles' => 0, 'dates' => 0]];
    }

    /**
     * robots.txt autorise-t-il `$chemin` pour l'agent ? Groupes dont la ligne
     * `User-agent` est contenue dans notre jeton, sinon groupes `*` ; règle de
     * la correspondance la plus longue, `Allow` gagnant à égalité ; jokers `*`
     * et `$` (RFC 9309).
     *
     * @return array{autorise: bool, delai: float}
     */
    public static function robotsAutorise(string $contenu, string $agent, string $chemin): array
    {
        $agent = strtolower($agent);
        // Trois tableaux parallèles, un indice par groupe `User-agent`.
        /** @var list<list<string>> $agents */
        $agents = [];
        /** @var array<int, list<array{autorise: bool, motif: string}>> $regles */
        $regles = [];
        /** @var array<int, float> $delais */
        $delais = [];
        $courant = -1;
        $dansAgents = false;
        foreach (preg_split('/
|
|
/', $contenu) ?: [] as $ligne) {
            $ligne = trim((string) preg_replace('/#.*$/', '', $ligne));
            $deuxPoints = strpos($ligne, ':');
            if ($ligne === '' || $deuxPoints === false) {
                continue;
            }
            $cle = strtolower(trim(substr($ligne, 0, $deuxPoints)));
            $valeur = trim(substr($ligne, $deuxPoints + 1));
            if ($cle === 'user-agent') {
                if (! $dansAgents || $courant < 0) {
                    $agents[] = [];
                    $courant = count($agents) - 1;
                    $regles[$courant] = [];
                    $delais[$courant] = 0.0;
                }
                $agents[$courant][] = strtolower($valeur);
                $dansAgents = true;

                continue;
            }
            $dansAgents = false;
            if ($courant < 0) {
                continue;
            }
            if ($cle === 'allow' || $cle === 'disallow') {
                if ($valeur !== '') {
                    $regles[$courant][] = ['autorise' => $cle === 'allow', 'motif' => $valeur];
                }
            } elseif ($cle === 'crawl-delay' && is_numeric($valeur)) {
                $delais[$courant] = max(0.0, (float) $valeur);
            }
        }

        $retenus = [];
        foreach ($agents as $i => $liste) {
            foreach ($liste as $a) {
                if ($a !== '*' && $a !== '' && str_contains($agent, $a)) {
                    $retenus[] = $i;
                    break;
                }
            }
        }
        if ($retenus === []) {
            foreach ($agents as $i => $liste) {
                if (in_array('*', $liste, true)) {
                    $retenus[] = $i;
                }
            }
        }

        $meilleur = -1;
        $autorise = true;
        $delai = 0.0;
        foreach ($retenus as $i) {
            $delai = max($delai, $delais[$i] ?? 0.0);
            foreach ($regles[$i] ?? [] as $regle) {
                $motif = $regle['motif'];
                $regex = '#^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($motif, '#')) . '#';
                if (preg_match($regex, $chemin) !== 1) {
                    continue;
                }
                $longueur = strlen($motif);
                if ($longueur > $meilleur || ($longueur === $meilleur && $regle['autorise'])) {
                    $meilleur = $longueur;
                    $autorise = $regle['autorise'];
                }
            }
        }

        return ['autorise' => $autorise, 'delai' => $delai];
    }

    /**
     * Réduit une page HTML aux zones lues par `ClassementMedia` (titre, menu,
     * texte), à la zone `identite` lue par `SiteMedia` et à deux compteurs de
     * structure. Rien d'autre n'est gardé.
     *
     * @return array{zones: array<string, string>, structure: array{articles: int, dates: int}}
     */
    public static function extraire(string $html): array
    {
        if (! mb_check_encoding($html, 'UTF-8')) {
            $html = (string) mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
        }
        $dom = new \DOMDocument;
        $avant = libxml_use_internal_errors(true);
        $ok = $html !== '' && $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($avant);
        if (! $ok) {
            return ['zones' => [], 'structure' => ['articles' => 0, 'dates' => 0]];
        }
        $xp = new \DOMXPath($dom);

        $minuscule = "translate(@%s, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')";
        $titre = array_merge(
            self::textes($xp, '//title', 2),
            self::textes($xp, '//meta[' . sprintf($minuscule, 'name') . "='description' or "
                . sprintf($minuscule, 'property') . " = 'og:description' or "
                . sprintf($minuscule, 'property') . " = 'og:title' or "
                . sprintf($minuscule, 'property') . " = 'og:site_name']/@content", 6),
            self::textes($xp, '//h1', 5),
        );
        // L'IDENTITÉ de la page (`SiteMedia::correspond`) : ce que le site dit
        // de lui-même — titre, og:site_name, og:title, h1 ; jamais la méta
        // description, qui peut citer n'importe quoi.
        $identite = array_merge(
            self::textes($xp, '//title', 2),
            self::textes($xp, '//meta[' . sprintf($minuscule, 'property') . " = 'og:site_name' or "
                . sprintf($minuscule, 'property') . " = 'og:title']/@content", 4),
            self::textes($xp, '//h1', 5),
        );
        $menu = array_merge(
            self::textes($xp, "//nav//a | //header//a | //*[@role='navigation']//a", 80),
            self::textes($xp, '//h2', 30),
        );
        $texte = array_merge(
            self::textes($xp, '//p', 40, 400),
            self::textes($xp, '//h3', 30),
        );

        $joint = implode(' ', $texte);
        $dates = (int) preg_match_all(
            '/\b\d{1,2}(?:er)?\s+(?:janvier|f[ée]vrier|mars|avril|mai|juin|juillet|ao[uû]t|septembre|octobre|novembre|d[ée]cembre)\s+\d{4}\b|\b\d{1,2}\/\d{1,2}\/\d{4}\b|\b\d{4}-\d{2}-\d{2}\b/iu',
            $joint,
        );
        $time = $xp->query('//time');

        $articles = $xp->query('//article');

        return [
            'zones' => [
                'titre' => mb_substr(implode(' . ', $titre), 0, self::BORNES['titre']),
                'menu' => mb_substr(implode(' . ', $menu), 0, self::BORNES['menu']),
                'texte' => mb_substr(implode(' . ', $texte), 0, self::BORNES['texte']),
                'identite' => mb_substr(implode(' . ', $identite), 0, self::BORNES['identite']),
            ],
            'structure' => [
                'articles' => $articles === false ? 0 : $articles->length,
                'dates' => max($dates, $time === false ? 0 : $time->length),
            ],
        ];
    }

    /** @return list<string> */
    private static function textes(\DOMXPath $xp, string $requete, int $max, int $longueur = 200): array
    {
        $noeuds = $xp->query($requete);
        if ($noeuds === false) {
            return [];
        }
        $sortie = [];
        foreach ($noeuds as $noeud) {
            if (count($sortie) >= $max) {
                break;
            }
            $t = trim((string) preg_replace('/\s+/u', ' ', (string) $noeud->nodeValue));
            if ($t !== '') {
                $sortie[] = mb_substr($t, 0, $longueur);
            }
        }

        return $sortie;
    }
}
