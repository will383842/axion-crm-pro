<?php

namespace App\Services\Http;

/**
 * SSRF guard — refuse les URLs qui pointent vers des IPs privées, link-local,
 * AWS/GCP metadata, loopback. À appeler avant tout fetch HTTP externe.
 *
 * Cf. spec/17_rgpd_aiact_owasp.md § A10.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * C19-001 / C19-003 — CE QUI A ÉTÉ RÉPARÉ LE 2026-08-20, ET POURQUOI
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Cette classe était SAINE et INUTILE. Mesure du jour :
 *
 *     grep -rn "SsrfGuard" backend/app  →  5 appels, TOUS sur `self::BASE_URL`,
 *     c'est-à-dire sur une CONSTANTE de classe que personne ne peut influencer.
 *
 * Les trois services qui consomment une URL venue de la DONNÉE
 * (`MentionsLegalesScraperService` sur `companies.website`,
 * `DomainFinderService` sur `companies.website` / `signals.legal.siteweb`,
 * `ProxiedHttpClient` sur l'URL que lui passe son appelant) ne l'appelaient
 * PAS. 30 tests unitaires verts couvraient une garde branchée nulle part —
 * c'est le patron A-011 du dépôt dans sa forme la plus pure. La réparation
 * n'a donc rien réécrit ici : elle a BRANCHÉ.
 *
 * Un second défaut, lui, imposait du code neuf (C19-003) : `check()` ne
 * regarde que l'URL de DÉPART. Guzzle suit par défaut jusqu'à 5 redirections
 * (`RedirectMiddleware::$defaultSettings['max'] === 5`, mesuré dans le vendor
 * du dépôt). Un site public qui répond
 * `302 Location: http://169.254.169.254/latest/meta-data/` faisait donc lire
 * le service de métadonnées de l'hébergeur par le backend, dont le corps était
 * ensuite parsé et persisté — sans qu'aucun accès à la console soit nécessaire.
 * C'est `redirectOptions()` ci-dessous qui ferme cette voie : chaque saut est
 * re-vérifié AVANT d'être suivi.
 */
class SsrfGuard
{
    /**
     * Nombre de sauts autorisés. Identique au défaut Guzzle : on ne durcit pas
     * ce nombre ici, on durcit la CIBLE de chaque saut. Beaucoup de sites
     * français font 2 sauts légitimes (http → https, apex → www).
     */
    private const MAX_REDIRECTIONS = 5;

    /** Hôtes interdits exacts. */
    private const DENY_HOSTS = [
        '169.254.169.254',  // AWS / GCP metadata
        'metadata.google.internal',
        '100.100.100.200',  // Alibaba metadata
        'metadata.azure.com',
        'localhost',
        '127.0.0.1', '::1',
        '0.0.0.0',
    ];

    /** Plages CIDR refusées (RFC 1918 + link-local + multicast + loopback). */
    private const DENY_CIDR = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    public static function enabled(): bool
    {
        return (bool) env('SSRF_GUARD_DENY_PRIVATE', true);
    }

    /**
     * Faut-il exiger qu'un NOM D'HÔTE se résolve pour être accepté ?
     *
     * En production : OUI (fail-closed). Un hôte qui ne résout pas est refusé,
     * ce qui ne change rien au comportement observable — la requête HTTP aurait
     * de toute façon échoué — mais ferme la porte à un resolveur qui répondrait
     * autre chose au moment de la connexion.
     *
     * SUR LE BANC DE TEST : NON, et c'est une décision mesurée, pas une facilité.
     * Toute la suite existante utilise des domaines fictifs interceptés par
     * `Http::fake()` — `alive.test`, `gone.test`, `foo.fr`… Mesure du 2026-08-20
     * dans le conteneur du banc :
     *
     *     dns_get_record('alive.test', DNS_A)   →  array(0) {}
     *     dns_get_record('target.fr',  DNS_A)   →  80.92.65.144
     *
     * Exiger la résolution en test aurait donc fait deux dégâts : rougir une
     * douzaine de tests qui ne parlent pas de SSRF, et surtout METTRE LA SUITE
     * UNITAIRE SUR LE RÉSEAU — un CI sans DNS aurait vu rougir la sécurité au
     * lieu du produit. Une garde qui mesure l'atelier ne vaut rien.
     *
     * ⚠️ Cette détente ne touche QUE les noms d'hôte non résolus. Une ADRESSE
     * LITTÉRALE interne (127.0.0.1, 169.254.169.254, 10/8, 192.168/16) reste
     * refusée en test comme en production : c'est exactement ce que vérifient
     * les gardes de `tests/Unit/Http/SsrfGardeBrancheeTest.php`, qui n'emploient
     * que des IP littérales pour cette raison.
     *
     * La variable `SSRF_GUARD_REQUIRE_DNS` permet de forcer l'un ou l'autre.
     * ⚠️ Comme `enabled()` juste au-dessus, elle est lue par `env()` hors de
     * `config/` : sous `config:cache`, Laravel saute le chargement du `.env` et
     * `env()` rend le DÉFAUT. Ici le défaut est le comportement SÛR (exiger la
     * résolution), donc `config:cache` ne peut qu'affermir la garde, jamais la
     * relâcher. Le même raisonnement vaut pour `SSRF_GUARD_DENY_PRIVATE`
     * (défaut `true`).
     */
    public static function requireDnsResolution(): bool
    {
        return (bool) env('SSRF_GUARD_REQUIRE_DNS', ! self::surBancDeTest());
    }

    /**
     * ⚠️ PIÈGE PAYÉ, ET CONSIGNÉ POUR LE SUIVANT.
     *
     * La première version de cette méthode faisait `app()->runningUnitTests()`,
     * ce qui paraît évident. Mesuré sur le banc le 2026-08-20, à l'intérieur
     * d'un test qui tournait :
     *
     *     config('app.env')            →  "local"
     *     app()->runningUnitTests()    →  false
     *     env('APP_ENV')               →  "local"
     *
     * Le `<env name="APP_ENV" value="testing"/>` de la configuration PHPUnit
     * n'écrase PAS une variable déjà présente dans l'environnement du processus
     * (il lui manque `force="true"`, contrairement à `DB_DATABASE` et
     * `CACHE_STORE` juste à côté), et le conteneur porte `APP_ENV=local`.
     * Autrement dit : **la suite de ce dépôt tourne en environnement `local`**,
     * et tout code qui se croit protégé par `runningUnitTests()` ou par
     * `environment('testing')` se trompe ici. C'est un constat à part entière,
     * remonté au rapport ; il ne se répare pas dans ce lot.
     *
     * On détecte donc le banc par la SEULE chose qui soit vraie sur un banc et
     * fausse en production : la classe de base de PHPUnit est déjà chargée en
     * mémoire. `class_exists(..., false)` n'autocharge PAS — en production, où
     * l'image est bâtie sans les dépendances de développement, la classe
     * n'existe pas et le second membre est faux sans même toucher au disque.
     */
    private static function surBancDeTest(): bool
    {
        try {
            if (app()->runningUnitTests()) {
                return true;
            }
        } catch (\Throwable) {
            // Pas de conteneur applicatif → on se comporte comme en production.
        }

        return class_exists(\PHPUnit\Framework\TestCase::class, false);
    }

    /**
     * C19-003 — options Guzzle qui RE-VÉRIFIENT CHAQUE SAUT de redirection.
     *
     * À passer à `->withOptions(...)` sur toute requête bâtie depuis une URL de
     * la donnée. Le rappel `on_redirect` est appelé par
     * `GuzzleHttp\RedirectMiddleware::checkRedirect()` AVANT que la requête
     * suivante ne parte (vérifié dans le vendor du dépôt) : y lever une
     * exception avorte la chaîne, la cible interne n'est jamais contactée.
     *
     * Pourquoi ce point d'accroche et pas un middleware de requête Laravel :
     * dans `HandlerStack::resolve()`, la pile est parcourue à l'envers, donc le
     * PREMIER poussé est le plus EXTÉRIEUR. `HandlerStack::create()` pousse
     * `allow_redirects` avant que `PendingRequest::pushHandlers()` ne pousse
     * ceux de Laravel : le middleware de redirection enveloppe tout le reste, et
     * `on_redirect` est le seul point qui voie TOUS les sauts, quel que soit
     * l'ordre des middlewares Laravel.
     *
     * @return array{allow_redirects: array<string, mixed>}
     */
    public static function redirectOptions(int $max = self::MAX_REDIRECTIONS): array
    {
        return [
            'allow_redirects' => [
                'max' => $max,
                // ⚠️ On NE touche PAS à `strict` ni à `referer` : ce sont des
                // réglages de COMPORTEMENT (un POST reste-t-il un POST après un
                // 301 ?), pas de sécurité. Les passer à `true` « pendant qu'on y
                // est » changerait la sémantique de redirection de tous les
                // appelants de `ProxiedHttpClient` pour un bénéfice nul. Un
                // correctif de sécurité ne déplace que ce qu'il doit déplacer.
                //
                // Un `Location: file:///etc/passwd` ou `gopher://…` ne doit même
                // pas être tenté : Guzzle refuse tout schéma hors de cette liste.
                'protocols' => ['http', 'https'],
                'track_redirects' => false,
                'on_redirect' => static function ($request, $response, $uri): void {
                    self::ensure((string) $uri);
                },
            ],
        ];
    }

    /**
     * @return array{ok: bool, reason: ?string}
     */
    public static function check(string $url): array
    {
        if (! self::enabled()) {
            return ['ok' => true, 'reason' => null];
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return ['ok' => false, 'reason' => 'invalid_url'];
        }

        $host = strtolower($parts['host']);

        if (in_array($host, self::DENY_HOSTS, true)) {
            return ['ok' => false, 'reason' => "deny_host:{$host}"];
        }

        // Résoudre toutes les IPs A + AAAA et vérifier chacune
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $a = @dns_get_record($host, DNS_A);
            foreach ($a ?: [] as $r) {
                if (! empty($r['ip'])) $ips[] = $r['ip'];
            }
            $aaaa = @dns_get_record($host, DNS_AAAA);
            foreach ($aaaa ?: [] as $r) {
                if (! empty($r['ipv6'])) $ips[] = $r['ipv6'];
            }
        }

        if (empty($ips)) {
            // Hôte qui ne résout pas : fail-closed en production, toléré sur le
            // banc de test (cf. requireDnsResolution() et sa mesure).
            return self::requireDnsResolution()
                ? ['ok' => false, 'reason' => 'dns_no_records']
                : ['ok' => true, 'reason' => null];
        }

        foreach ($ips as $ip) {
            if (self::ipInDenyCidr($ip)) {
                return ['ok' => false, 'reason' => "deny_cidr:{$ip}"];
            }
        }

        return ['ok' => true, 'reason' => null];
    }

    public static function ensure(string $url): void
    {
        $check = self::check($url);
        if (! $check['ok']) {
            throw new \RuntimeException("SSRF guard rejected URL: {$check['reason']}");
        }
    }

    private static function ipInDenyCidr(string $ip): bool
    {
        $packedIp = @inet_pton($ip);
        if ($packedIp === false) {
            return true; // fail-closed
        }

        foreach (self::DENY_CIDR as $cidr) {
            [$range, $bits] = explode('/', $cidr);
            $packedRange = inet_pton($range);
            if ($packedRange === false || strlen($packedRange) !== strlen($packedIp)) {
                continue;
            }
            $bytes = intdiv((int) $bits, 8);
            $remainingBits = ((int) $bits) % 8;
            if (substr($packedIp, 0, $bytes) !== substr($packedRange, 0, $bytes)) {
                continue;
            }
            if ($remainingBits === 0) {
                return true;
            }
            $maskByte = chr(0xff << (8 - $remainingBits) & 0xff);
            if ((ord($packedIp[$bytes]) & ord($maskByte)) === (ord($packedRange[$bytes]) & ord($maskByte))) {
                return true;
            }
        }
        return false;
    }
}
