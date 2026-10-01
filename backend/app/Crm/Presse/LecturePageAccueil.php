<?php

namespace App\Crm\Presse;

use App\Services\Http\SsrfGuard;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * LIRE LA PAGE D'ACCUEIL D'UN MÉDIA — poliment (chantier F, 2026-10-01).
 *
 * Pour un paquet de sites, deux passes, chacune CONCURRENTE mais bornée
 * (`$concurrence`, 4 par défaut : le serveur du CRM a 2 CPU) :
 *
 *   1. `robots.txt` de chaque hôte. On ne lit la page d'accueil QUE si
 *      `robots.txt` l'autorise pour notre agent (`AGENT_ROBOTS`) ou pour `*` —
 *      règle de la correspondance la plus longue, `Allow` gagnant à égalité
 *      (RFC 9309). Un `robots.txt` absent (4xx) autorise ; un serveur en
 *      erreur (5xx) ou un `Crawl-delay` supérieur à `CRAWL_DELAY_MAX` secondes
 *      interdit (prudence). Hôte injoignable : rien d'autre n'est tenté.
 *   2. la page d'accueil des hôtes autorisés, APRÈS un délai par domaine
 *      (`$delaiMs`, 1 s par défaut, ou le `Crawl-delay` demandé s'il est plus
 *      long) compté depuis la requête `robots.txt` du même hôte. Un hôte n'est
 *      jamais interrogé deux fois en même temps : chaque passe ne contient
 *      qu'une requête par hôte.
 *
 * Chaque requête : User-Agent IDENTIFIABLE (`USER_AGENT`, avec un moyen de
 * nous joindre), délai court (`$timeout` s), garde SSRF à l'entrée et à
 * chaque redirection (`SsrfGuard`), 3 redirections au plus, corps tronqué à
 * `CORPS_MAX` octets, HTML seulement.
 *
 * Rien n'est gardé : la page est réduite en mémoire à quatre zones de texte
 * (`extraire`) que `ClassementMedia` réduit à son tour à des étiquettes. Aucun
 * texte, aucune adresse, aucun nom ne sort de cette classe vers la base.
 */
final class LecturePageAccueil
{
    public const USER_AGENT = 'AxionCRM-ClassementMedias/1.0 (+https://axion-ia.com; contact@axion-ia.com)';

    /** Jeton cherché dans les groupes `User-agent` de robots.txt (minuscules). */
    public const AGENT_ROBOTS = 'axioncrm';

    public const CRAWL_DELAY_MAX = 10.0;

    public const CORPS_MAX = 1_500_000;

    public const ROBOTS_MAX = 500_000;

    private const REDIRECTIONS_MAX = 3;

    /** Bornes des zones gardées en mémoire (caractères). */
    private const BORNES = ['titre' => 4000, 'menu' => 8000, 'texte' => 20000];

    public const STATUT_LU = 'site';

    public const STATUT_ROBOTS = 'robots-interdit';

    public const STATUT_INJOIGNABLE = 'injoignable';

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
     * La base `https://hote` d'un site tel que la base le stocke (`exemple.fr`,
     * `http://www.exemple.fr/accueil`…), ou null s'il n'est pas lisible.
     */
    public static function base(?string $site): ?string
    {
        $site = trim((string) $site);
        if ($site === '') {
            return null;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $site) !== 1) {
            $site = 'https://' . ltrim($site, '/');
        }
        $schema = strtolower((string) parse_url($site, PHP_URL_SCHEME));
        $hote = strtolower((string) parse_url($site, PHP_URL_HOST));
        if (! in_array($schema, ['http', 'https'], true) || $hote === '' || ! str_contains($hote, '.')
            || preg_match('/^[a-z0-9.-]+$/', $hote) !== 1) {
            return null;
        }
        $port = parse_url($site, PHP_URL_PORT);

        return $schema . '://' . $hote . (is_int($port) ? ':' . $port : '');
    }

    /**
     * Lit les pages d'accueil des bases données (dédoublonnées).
     *
     * @param  list<string>  $bases  sorties de `base()`
     * @return array<string, array{statut: string, zones: array<string, string>, structure: array{articles: int, dates: int}}>
     */
    public function lire(array $bases): array
    {
        $bases = array_values(array_unique($bases));
        $resultats = [];
        $aLire = [];
        foreach ($bases as $base) {
            if (! SsrfGuard::check($base . '/')['ok']) {
                $resultats[$base] = self::echec(self::STATUT_INJOIGNABLE);

                continue;
            }
            $aLire[] = $base;
        }

        // ── 1. robots.txt ─────────────────────────────────────────────────
        $debut = hrtime(true);
        $robots = $this->recuperer(array_map(static fn (string $b): string => $b . '/robots.txt', $aLire));
        $autorises = [];
        $attenteMs = $this->delaiMs;
        foreach ($aLire as $i => $base) {
            $r = $robots[$i] ?? null;
            if (! $r instanceof Response) {
                $resultats[$base] = self::echec(self::STATUT_INJOIGNABLE);

                continue;
            }
            if ($r->serverError()) {
                $resultats[$base] = self::echec(self::STATUT_ROBOTS);

                continue;
            }
            $regle = $r->successful()
                ? self::robotsAutorise(substr($r->body(), 0, self::ROBOTS_MAX), self::AGENT_ROBOTS, '/')
                : ['autorise' => true, 'delai' => 0.0];
            if (! $regle['autorise'] || $regle['delai'] > self::CRAWL_DELAY_MAX) {
                $resultats[$base] = self::echec(self::STATUT_ROBOTS);

                continue;
            }
            $attenteMs = max($attenteMs, (int) ceil($regle['delai'] * 1000));
            $autorises[] = $base;
        }
        if ($autorises === []) {
            return $resultats;
        }

        // ── 2. pages d'accueil, après le délai par domaine ──────────────────
        $ecoule = (int) ((hrtime(true) - $debut) / 1_000_000);
        ($this->dormir)(max(0, $attenteMs - $ecoule));

        $pages = $this->recuperer(array_map(static fn (string $b): string => $b . '/', $autorises));
        foreach ($autorises as $i => $base) {
            $r = $pages[$i] ?? null;
            $type = $r instanceof Response ? strtolower((string) $r->header('Content-Type')) : '';
            if (! $r instanceof Response || ! $r->successful() || ($type !== '' && ! str_contains($type, 'html'))) {
                $resultats[$base] = self::echec(self::STATUT_INJOIGNABLE);

                continue;
            }
            $lu = self::extraire(substr($r->body(), 0, self::CORPS_MAX));
            $resultats[$base] = ['statut' => self::STATUT_LU] + $lu;
        }

        return $resultats;
    }

    /**
     * GET concurrents (au plus `$concurrence` à la fois), dans l'ordre donné.
     *
     * @param  list<string>  $urls
     * @return array<int, Response|null>
     */
    private function recuperer(array $urls): array
    {
        if ($urls === []) {
            return [];
        }
        $timeout = max(1, $this->timeout);
        $reponses = Http::pool(static function (Pool $pool) use ($urls, $timeout): array {
            $requetes = [];
            foreach ($urls as $i => $url) {
                $requetes[] = $pool->as('r' . $i)
                    ->timeout($timeout)
                    ->connectTimeout(min(3, $timeout))
                    ->withOptions(SsrfGuard::redirectOptions(self::REDIRECTIONS_MAX))
                    ->withHeaders(['User-Agent' => self::USER_AGENT, 'Accept' => 'text/html,text/plain;q=0.9,*/*;q=0.1'])
                    ->get($url);
            }

            return $requetes;
        }, max(1, $this->concurrence));

        $sortie = [];
        foreach (array_keys($urls) as $i) {
            $r = $reponses['r' . $i] ?? null;
            $sortie[$i] = $r instanceof Response ? $r : null;
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
        /** @var list<array{agents: list<string>, regles: list<array{bool, string}>, delai: float}> $groupes */
        $groupes = [];
        $courant = null;
        $dansAgents = false;
        foreach (preg_split('/\r\n|\r|\n/', $contenu) ?: [] as $ligne) {
            $ligne = trim((string) preg_replace('/#.*$/', '', $ligne));
            if ($ligne === '' || ! str_contains($ligne, ':')) {
                continue;
            }
            [$cle, $valeur] = array_map('trim', explode(':', $ligne, 2));
            $cle = strtolower($cle);
            if ($cle === 'user-agent') {
                if (! $dansAgents || $courant === null) {
                    $groupes[] = ['agents' => [], 'regles' => [], 'delai' => 0.0];
                    $courant = count($groupes) - 1;
                }
                $groupes[$courant]['agents'][] = strtolower($valeur);
                $dansAgents = true;

                continue;
            }
            $dansAgents = false;
            if ($courant === null) {
                continue;
            }
            if ($cle === 'allow' || $cle === 'disallow') {
                if ($valeur !== '') {
                    $groupes[$courant]['regles'][] = [$cle === 'allow', $valeur];
                }
            } elseif ($cle === 'crawl-delay' && is_numeric($valeur)) {
                $groupes[$courant]['delai'] = max(0.0, (float) $valeur);
            }
        }

        $retenus = array_values(array_filter($groupes, static function (array $g) use ($agent): bool {
            foreach ($g['agents'] as $a) {
                if ($a !== '*' && $a !== '' && str_contains($agent, $a)) {
                    return true;
                }
            }

            return false;
        }));
        if ($retenus === []) {
            $retenus = array_values(array_filter($groupes, static fn (array $g): bool => in_array('*', $g['agents'], true)));
        }

        $meilleur = -1;
        $autorise = true;
        $delai = 0.0;
        foreach ($retenus as $g) {
            $delai = max($delai, $g['delai']);
            foreach ($g['regles'] as [$allow, $motif]) {
                $regex = '#^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($motif, '#')) . '#';
                if (preg_match($regex, $chemin) !== 1) {
                    continue;
                }
                $longueur = strlen($motif);
                if ($longueur > $meilleur || ($longueur === $meilleur && $allow)) {
                    $meilleur = $longueur;
                    $autorise = $allow;
                }
            }
        }

        return ['autorise' => $autorise, 'delai' => $delai];
    }

    /**
     * Réduit une page HTML aux zones lues par `ClassementMedia` et à deux
     * compteurs de structure. Rien d'autre n'est gardé.
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
            $t = trim((string) preg_replace('/\s+/u', ' ', $noeud->textContent));
            if ($t !== '') {
                $sortie[] = mb_substr($t, 0, $longueur);
            }
        }

        return $sortie;
    }
}
