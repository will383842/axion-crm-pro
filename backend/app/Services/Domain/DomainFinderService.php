<?php

namespace App\Services\Domain;

use App\Models\Company;
use App\Models\Media;
use App\Services\Http\ProxiedHttpClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Trouve le site web officiel d'une entreprise en cascade (3 stratégies).
 *
 * Stratégie 1 : signals.legal.siteweb (déjà rempli par AnnuaireEntreprises)
 * Stratégie 2 : Brave Search API (sprint H1 — remplace DuckDuckGo scrape)
 * Stratégie 3 : Pages Jaunes HTML — uniquement si MOCK_SCRAPERS=false
 *               (passage via Webshare proxy si WEBSHARE_ENABLED=true)
 *
 * Timeout 10s par source. Fail silently et passe à la suivante.
 * Skip silently les réseaux sociaux et annuaires d'entreprises.
 *
 * Garantie graceful degradation : pas de BRAVE_SEARCH_API_KEY → skip Brave silently.
 */
class DomainFinderService
{
    private const BLACKLIST_HOSTS = [
        'linkedin.com', 'facebook.com', 'twitter.com', 'x.com',
        'youtube.com', 'instagram.com', 'tiktok.com', 'pinterest.com',
        'societe.com', 'verif.com', 'pappers.fr', 'manageo.fr',
        'infogreffe.fr', 'annuaire-entreprises.data.gouv.fr', 'pagesjaunes.fr',
        'duckduckgo.com', 'google.com', 'bing.com', 'brave.com',
    ];

    private const HTTP_TIMEOUT_SECONDS = 10;

    // Vérification de domaine deviné : court + fail-fast (des millions d'entreprises).
    private const GUESS_TIMEOUT = 4;
    private const GUESS_CONNECT_TIMEOUT = 2;

    private const BRAVE_SEARCH_URL = 'https://api.search.brave.com/res/v1/web/search';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    /**
     * Cherche le site web officiel d'une company.
     * Retourne l'URL canonique `https://domain.fr/` ou null.
     */
    public function find(Company $company): ?string
    {
        // Stratégie 1 : signals.legal.siteweb (toujours en priorité)
        $signals = $company->signals ?? [];
        $existing = $signals['legal']['siteweb'] ?? null;
        if ($existing && is_string($existing) && filter_var($existing, FILTER_VALIDATE_URL)) {
            return $this->canonicalize($existing);
        }

        if (!$company->denomination) {
            return null;
        }
        $ville = $company->city_name ?? $company->city ?? '';

        // Stratégie 2 : Brave Search API (graceful skip si pas de clé)
        $url = $this->searchBrave($company->denomination, $ville);
        if ($url) {
            return $url;
        }

        // Stratégie 3 : domain-guessing (DNS + HTTP) — 100% GRATUIT, sans clé, scalable.
        // Devine le domaine depuis le nom + vérifie que le site est bien l'entreprise.
        $url = $this->guessDomain($company);
        if ($url) {
            return $url;
        }

        // Stratégie 4 : Pages Jaunes — uniquement quand scrapers réels activés
        if (config('services.scrapers.mock', true) === false) {
            return $this->searchPagesJaunes($company->denomination, $ville);
        }

        return null;
    }

    /**
     * Devine le domaine officiel depuis le nom de l'entreprise, sans aucune API :
     * génère des candidats (`nomcomplet.fr`, `nom-complet.fr`, `premiermot.fr`…),
     * vérifie l'existence (DNS) puis que la page mentionne bien l'entreprise
     * (SIREN, ville, ou ≥2 mots du nom) pour éviter les faux positifs.
     */
    /**
     * Génère les domaines candidats pour un jeu de mots (nomcomplet.fr, nom-complet.fr,
     * premiermot.fr, + .com), filtrés (longueur, blacklist).
     *
     * Mode `$extended` = 2e passage (pass 2) sur les `not_found` : ajoute des variantes
     * secondaires (TLD alternatifs .com/.net/.eu, hyphen.com, premier mot .com, deux
     * premiers mots collés, acronyme des initiales) pour remonter la couverture.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function candidateDomains(array $tokens, bool $extended = false): array
    {
        if (count($tokens) === 0) {
            return [];
        }
        $joined = implode('', $tokens);
        $hyphen = implode('-', $tokens);
        $first = $tokens[0];
        // 4 candidats les plus probables (priorité, pass 1).
        $out = ["{$joined}.fr"];
        if ($hyphen !== $joined) {
            $out[] = "{$hyphen}.fr";
        }
        $out[] = "{$joined}.com";
        if (count($tokens) > 1 && mb_strlen($first) >= 4) {
            $out[] = "{$first}.fr";
        }

        if ($extended) {
            // Variantes secondaires — testées seulement au 2e passage (not_found).
            if ($hyphen !== $joined) {
                $out[] = "{$hyphen}.com";
            }
            $out[] = "{$joined}.net";
            $out[] = "{$joined}.eu";
            if (count($tokens) > 1 && mb_strlen($first) >= 4) {
                $out[] = "{$first}.com";
            }
            if (count($tokens) >= 2) {
                $two = $tokens[0] . $tokens[1];
                $out[] = "{$two}.fr";
                $out[] = "{$two}.com";
            }
            if (count($tokens) >= 3) {
                $acr = implode('', array_map(static fn ($t) => mb_substr($t, 0, 1), $tokens));
                if (mb_strlen($acr) >= 3) {
                    $out[] = "{$acr}.fr";
                    $out[] = "{$acr}.com";
                }
            }
        }

        return array_values(array_filter(
            array_unique($out),
            fn ($d) => mb_strlen($d) >= 5 && ! $this->isBlacklisted($d),
        ));
    }

    private function guessDomain(Company $company): ?string
    {
        $tokens = $this->nameTokens((string) $company->denomination);
        foreach ($this->candidateDomains($tokens) as $domain) {
            if (! @checkdnsrr($domain, 'A') && ! @checkdnsrr($domain, 'AAAA')) {
                continue;
            }
            if ($this->verifyCandidate($domain, $company, $tokens)) {
                return $this->canonicalize("https://{$domain}/");
            }
        }
        return null;
    }

    /**
     * Version CONCURRENTE (Http::pool) : teste les domaines de PLUSIEURS entreprises
     * EN PARALLÈLE — indispensable à l'échelle (4M en quelques heures). Pas de DNS
     * séquentiel : le pool gère résolution + connexion, les domaines morts échouent
     * vite (connectTimeout court). 1 requête par domaine = crawl poli.
     *
     * @param  iterable<Company>  $companies
     * @param  bool  $extended  2e passage (pass 2) : teste les variantes secondaires.
     * @return array<int, string|null>  id entreprise => url trouvée (ou null)
     */
    public function guessDomainsBatch(iterable $companies, bool $extended = false): array
    {
        $result = [];
        $reqs = [];
        $n = 0;
        foreach ($companies as $c) {
            $result[$c->id] = null;
            $tokens = $this->nameTokens((string) $c->denomination);
            foreach ($this->candidateDomains($tokens, $extended) as $domain) {
                // PAS de pré-filtre DNS ici (checkdnsrr) : mesuré en prod, il DIVISE
                // le débit par ~2,5 (~1/s/job au lieu de ~2,6/s). Le resolver du serveur
                // est lent et checkdnsrr est séquentiel → 4 lookups bloquants/entreprise
                // dominent. Le pool HTTP gère mieux : les NXDOMAIN échouent vite au
                // resolve (bien avant le connectTimeout) sans sérialiser.
                $reqs['k' . ($n++)] = [
                    'c'      => $c,
                    'domain' => $domain,
                    'tokens' => $tokens,
                ];
            }
        }
        if ($reqs === []) {
            return $result;
        }

        foreach (array_chunk($reqs, 400, true) as $chunk) {
            $responses = Http::pool(function ($pool) use ($chunk) {
                $out = [];
                foreach ($chunk as $key => $it) {
                    $out[] = $pool->as($key)
                        ->timeout(self::GUESS_TIMEOUT)
                        ->connectTimeout(self::GUESS_CONNECT_TIMEOUT)
                        ->withHeaders(['User-Agent' => self::USER_AGENT])
                        ->get("https://{$it['domain']}/");
                }
                return $out;
            });

            foreach ($chunk as $key => $it) {
                $cid = $it['c']->id;
                if ($result[$cid] !== null) {
                    continue; // déjà trouvé pour cette entreprise
                }
                $resp = $responses[$key] ?? null;
                if (! $resp || $resp instanceof \Throwable) {
                    continue;
                }
                try {
                    if ($resp->successful() && $this->verifyBody((string) $resp->body(), $it['c'], $it['tokens'])) {
                        $result[$cid] = $this->canonicalize("https://{$it['domain']}/");
                    }
                } catch (\Throwable $e) {
                    // réponse illisible → ignore
                }
            }
        }
        return $result;
    }

    /**
     * PASSE 3 — RE-VALIDATION concurrente des sites déjà trouvés (Http::pool).
     *
     * Re-teste l'URL EXISTANTE `$company->website` de chaque entreprise (1 requête
     * par entreprise) pour détecter les sites disparus (domaine expiré, hébergement
     * coupé). Même pattern concurrent + timeouts courts que `guessDomainsBatch`.
     *
     * RÈGLE « vivant » CONSERVATRICE : l'entreprise est VIVANTE dès qu'on obtient
     * N'IMPORTE QUELLE réponse HTTP (l'objet réponse existe — même 4xx/5xx = le
     * serveur répond). Elle est MORTE seulement si la requête LÈVE une exception
     * (connexion refusée / DNS introuvable / timeout) → pas de réponse du tout.
     * On préfère un faux « vivant » à un faux « mort » (on ne jette pas un lead).
     *
     * @param  iterable<Company>  $companies
     * @return array<int, bool>  id entreprise => vivant (true) / mort (false)
     */
    public function revalidateBatch(iterable $companies): array
    {
        $result = [];
        $reqs = [];
        $n = 0;
        foreach ($companies as $c) {
            $url = is_string($c->website) ? trim($c->website) : '';
            if ($url === '') {
                continue; // pas de site à re-valider → on ne se prononce pas
            }
            $result[$c->id] = false;
            $reqs['k' . ($n++)] = ['id' => $c->id, 'url' => $url];
        }
        if ($reqs === []) {
            return $result;
        }

        foreach (array_chunk($reqs, 400, true) as $chunk) {
            $responses = Http::pool(function ($pool) use ($chunk) {
                $out = [];
                foreach ($chunk as $key => $it) {
                    $out[] = $pool->as($key)
                        ->timeout(self::GUESS_TIMEOUT)
                        ->connectTimeout(self::GUESS_CONNECT_TIMEOUT)
                        ->withHeaders(['User-Agent' => self::USER_AGENT])
                        ->get($it['url']);
                }
                return $out;
            });

            foreach ($chunk as $key => $it) {
                $resp = $responses[$key] ?? null;
                // Une exception (ConnectionException, DNS, timeout) arrive ici sous
                // forme de Throwable dans le pool → MORT. Un objet réponse (2xx…5xx)
                // = le serveur a répondu → VIVANT (règle conservatrice).
                $result[$it['id']] = ($resp !== null && ! $resp instanceof \Throwable);
            }
        }

        return $result;
    }

    /**
     * Récupère la page d'accueil et confirme qu'elle appartient bien à l'entreprise :
     * SIREN présent, OU ≥2 mots du nom, OU (1 mot du nom + la ville). Anti-parking.
     *
     * @param  list<string>  $tokens
     */
    private function verifyCandidate(string $domain, Company $company, array $tokens): bool
    {
        try {
            $resp = Http::timeout(self::GUESS_TIMEOUT)
                ->connectTimeout(self::GUESS_CONNECT_TIMEOUT)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->get("https://{$domain}/");
        } catch (\Throwable $e) {
            return false;
        }
        return $resp->successful() && $this->verifyBody((string) $resp->body(), $company, $tokens);
    }

    /**
     * Confirme qu'une page HTML appartient bien à l'entreprise (anti-faux-positif) :
     * SIREN présent, OU ≥2 mots du nom, OU (1 mot + la ville). Écarte pages vides/parking.
     *
     * @param  list<string>  $tokens
     */
    private function verifyBody(string $rawBody, Company|Media $company, array $tokens): bool
    {
        $body = mb_strtolower(strip_tags($rawBody));
        if (mb_strlen($body) < 200) {
            return false;
        }
        $siren = (string) $company->siren;
        if ($siren !== '' && str_contains(preg_replace('/\s+/', '', $body), $siren)) {
            return true;
        }
        $hits = 0;
        foreach ($tokens as $t) {
            if (mb_strlen($t) >= 3 && str_contains($body, $t)) {
                $hits++;
            }
        }
        $ville = $this->stripAccents(mb_strtolower((string) ($company->city_name ?? $company->city ?? '')));
        $villeOk = mb_strlen($ville) >= 3 && str_contains($this->stripAccents($body), $ville);

        return $hits >= 2 || ($hits >= 1 && $villeOk);
    }

    /**
     * Découpe le nom en mots normalisés (sans accents, sans forme juridique).
     *
     * @return list<string>
     */
    private function nameTokens(string $name): array
    {
        $s = $this->stripAccents(mb_strtolower($name));
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        $stop = [
            'sarl', 'sas', 'sasu', 'sa', 'eurl', 'snc', 'sci', 'sarlu', 'scop',
            'earl', 'gie', 'sccv', 'et', 'de', 'du', 'des', 'la', 'le', 'les', 'l', 'd',
        ];
        $words = array_filter(
            explode(' ', (string) $s),
            fn ($w) => $w !== '' && mb_strlen($w) >= 2 && ! in_array($w, $stop, true),
        );
        return array_values(array_slice($words, 0, 4));
    }

    private function stripAccents(string $s): string
    {
        $from = ['à', 'â', 'ä', 'á', 'ã', 'å', 'é', 'è', 'ê', 'ë', 'î', 'ï', 'í', 'ì', 'ô', 'ö', 'ò', 'ó', 'õ', 'ù', 'û', 'ü', 'ú', 'ç', 'ñ'];
        $to = ['a', 'a', 'a', 'a', 'a', 'a', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'c', 'n'];
        return str_replace($from, $to, $s);
    }

    /**
     * Brave Search API — remplace l'ancien scrape DuckDuckGo (banni rapidement).
     * Free tier : 2000 req/mois. Renvoie le 1er résultat non-blacklist.
     */
    private function searchBrave(string $denomination, string $ville): ?string
    {
        $apiKey = config('services.brave.api_key');
        if (!$apiKey) {
            // Graceful degradation : pas de clé → skip silently
            Log::debug('DomainFinder Brave skipped (no API key)');
            return null;
        }

        $query = sprintf('%s %s site officiel', $denomination, $ville);

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->withHeaders([
                    'X-Subscription-Token' => $apiKey,
                    'Accept'               => 'application/json',
                ])
                ->retry(2, 500, function (\Throwable $e) {
                    return $e instanceof \Illuminate\Http\Client\ConnectionException;
                })
                ->get(self::BRAVE_SEARCH_URL, [
                    'q'          => $query,
                    'count'      => 5,
                    'country'    => 'fr',
                    'safesearch' => 'moderate',
                ]);

            if (!$response->successful()) {
                Log::debug('DomainFinder Brave HTTP error', ['status' => $response->status()]);
                return null;
            }

            $results = $response->json('web.results', []);
            if (!is_array($results)) {
                return null;
            }

            foreach ($results as $r) {
                $url = $r['url'] ?? null;
                if (!is_string($url)) {
                    continue;
                }
                $host = parse_url($url, PHP_URL_HOST);
                if (!$host || $this->isBlacklisted($host)) {
                    continue;
                }
                return $this->canonicalize($url);
            }
        } catch (\Throwable $e) {
            if (class_exists(\Sentry\State\Hub::class)) {
                \Sentry\captureException($e);
            }
            Log::warning('DomainFinder Brave exception', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Pages Jaunes scraping — uniquement Phase B (Will valide).
     * Passe via Webshare proxy si activé pour éviter blacklist IP Hetzner.
     */
    private function searchPagesJaunes(string $denomination, string $ville): ?string
    {
        $denomSlug = $this->slugify($denomination);
        $villeSlug = $this->slugify($ville);
        if (!$denomSlug || !$villeSlug) {
            return null;
        }

        $url = sprintf('https://www.pagesjaunes.fr/recherche/%s/%s', $villeSlug, $denomSlug);

        try {
            // Sprint H1 — Webshare proxy si activé (évite blacklist IP Hetzner sur PJ)
            $response = app(ProxiedHttpClient::class)->request(self::HTTP_TIMEOUT_SECONDS)
                ->withHeaders([
                    'User-Agent' => self::USER_AGENT,
                    'Accept'     => 'text/html,application/xhtml+xml',
                ])
                ->get($url);

            if (!$response->successful()) {
                return null;
            }

            if (preg_match('/<a[^>]+class="[^"]*company-website[^"]*"[^>]+href="([^"]+)"/i', $response->body(), $m)) {
                $href = $m[1];
                $host = parse_url($href, PHP_URL_HOST);
                if ($host && !$this->isBlacklisted($host)) {
                    return $this->canonicalize($href);
                }
            }
        } catch (\Throwable $e) {
            if (class_exists(\Sentry\State\Hub::class)) {
                \Sentry\captureException($e);
            }
            Log::debug('DomainFinder PagesJaunes failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function isBlacklisted(string $host): bool
    {
        $host = strtolower($host);
        foreach (self::BLACKLIST_HOSTS as $b) {
            if (str_contains($host, $b)) {
                return true;
            }
        }
        return false;
    }

    private function canonicalize(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return $url;
        }
        $scheme = $parts['scheme'] ?? 'https';
        $host = preg_replace('/^www\./i', '', strtolower($parts['host']));
        return sprintf('%s://%s/', $scheme, $host);
    }

    private function slugify(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
        return trim((string) $s, '-');
    }
}
