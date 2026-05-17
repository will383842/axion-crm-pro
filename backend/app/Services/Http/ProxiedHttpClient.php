<?php

namespace App\Services\Http;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Wrapper Http client qui applique automatiquement le proxy Webshare
 * quand WEBSHARE_ENABLED=true.
 *
 * Sprint H1 — Évite blacklist IP Hetzner sur Pages Jaunes / autres scrapes risqués.
 * Coût Webshare : ~$30/mo flat. Désactivé par défaut.
 *
 * Usage :
 *   app(ProxiedHttpClient::class)->request()->get('https://pagesjaunes.fr/...');
 */
class ProxiedHttpClient
{
    private const DEFAULT_TIMEOUT_SECONDS = 15;

    public function request(int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): PendingRequest
    {
        $client = Http::timeout($timeoutSeconds);

        if ($this->isProxyEnabled()) {
            $proxy = $this->buildProxyUrl();
            if ($proxy !== null) {
                $client = $client->withOptions(['proxy' => $proxy]);
            }
        }

        return $client;
    }

    public function isProxyEnabled(): bool
    {
        return (bool) config('services.webshare.enabled', false);
    }

    private function buildProxyUrl(): ?string
    {
        $user = config('services.webshare.username');
        $pass = config('services.webshare.password');
        $endpoint = config('services.webshare.endpoint', 'p.webshare.io:80');

        if (! is_string($user) || $user === '' || ! is_string($pass) || $pass === '') {
            return null;
        }

        return sprintf('http://%s:%s@%s', rawurlencode($user), rawurlencode($pass), $endpoint);
    }
}
