<?php

namespace App\Services\Domain;

use App\Models\Company;
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

        // Stratégie 3 : Pages Jaunes — uniquement quand scrapers réels activés
        if (config('services.scrapers.mock', true) === false) {
            return $this->searchPagesJaunes($company->denomination, $ville);
        }

        return null;
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
