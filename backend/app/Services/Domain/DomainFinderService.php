<?php

namespace App\Services\Domain;

use App\Models\Company;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Trouve le site web officiel d'une entreprise en cascade (3 stratégies).
 *
 * Stratégie 1 : signals.legal.siteweb (déjà rempli par AnnuaireEntreprises)
 * Stratégie 2 : DuckDuckGo HTML — première URL non-blacklist
 * Stratégie 3 : Pages Jaunes HTML — <a class="company-website">
 *
 * Timeout 10s par source. Fail silently et passe à la suivante.
 * Skip silently les réseaux sociaux et annuaires d'entreprises.
 */
class DomainFinderService
{
    private const BLACKLIST_HOSTS = [
        'linkedin.com', 'facebook.com', 'twitter.com', 'x.com',
        'youtube.com', 'instagram.com', 'tiktok.com', 'pinterest.com',
        'societe.com', 'verif.com', 'pappers.fr', 'manageo.fr',
        'infogreffe.fr', 'annuaire-entreprises.data.gouv.fr', 'pagesjaunes.fr',
        'duckduckgo.com', 'google.com', 'bing.com',
    ];

    private const HTTP_TIMEOUT_SECONDS = 10;

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

        // Stratégie 2 : DuckDuckGo HTML
        $url = $this->searchDuckDuckGo($company->denomination, $ville);
        if ($url) {
            return $url;
        }

        // Stratégie 3 : Pages Jaunes HTML
        return $this->searchPagesJaunes($company->denomination, $ville);
    }

    private function searchDuckDuckGo(string $denomination, string $ville): ?string
    {
        $query = trim($denomination . ' ' . $ville);
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->get('https://html.duckduckgo.com/html/', ['q' => $query]);

            if (!$response->successful()) {
                return null;
            }

            // Parse <a class="result__url"> — retourne premier URL non-blacklist
            if (preg_match_all('/<a[^>]+class="result__url"[^>]+href="([^"]+)"/i', $response->body(), $matches)) {
                foreach ($matches[1] as $href) {
                    // DuckDuckGo retourne souvent //duckduckgo.com/l/?uddg=https%3A%2F%2Ftarget.com → décoder
                    $href = $this->decodeDuckDuckGoRedirect($href);
                    if (!$href) {
                        continue;
                    }
                    $host = parse_url($href, PHP_URL_HOST);
                    if (!$host || $this->isBlacklisted($host)) {
                        continue;
                    }
                    return $this->canonicalize($href);
                }
            }
        } catch (\Throwable $e) {
            Log::debug('DomainFinder DuckDuckGo failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function searchPagesJaunes(string $denomination, string $ville): ?string
    {
        $denomSlug = $this->slugify($denomination);
        $villeSlug = $this->slugify($ville);
        if (!$denomSlug || !$villeSlug) {
            return null;
        }

        $url = sprintf('https://www.pagesjaunes.fr/recherche/%s/%s', $villeSlug, $denomSlug);
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
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
            Log::debug('DomainFinder PagesJaunes failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function decodeDuckDuckGoRedirect(string $href): ?string
    {
        if (str_starts_with($href, '//duckduckgo.com/l/?') || str_starts_with($href, '/l/?')) {
            $parts = parse_url('https:' . ltrim($href, '/'));
            parse_str($parts['query'] ?? '', $query);
            $target = $query['uddg'] ?? null;
            return is_string($target) ? urldecode($target) : null;
        }
        if (str_starts_with($href, 'http')) {
            return $href;
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
