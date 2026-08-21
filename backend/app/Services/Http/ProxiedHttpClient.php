<?php

namespace App\Services\Http;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

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

    /**
     * C19-001 / C19-003 — ce client est le TROISIÈME consommateur d'URL issue de
     * la donnée, et le plus insidieux : il ne voit jamais l'URL lui-même, il la
     * reçoit de son appelant (`DomainFinderService::searchPagesJaunes()` la
     * fabrique depuis la dénomination et la ville d'une entreprise). Mesure du
     * 2026-08-20 : zéro appel à `SsrfGuard` dans ce fichier.
     *
     * Deux accroches, volontairement redondantes :
     *
     *  1. `withRequestMiddleware()` — vérifie l'URL de CHAQUE requête sortante.
     *     Comme le middleware de redirection de Guzzle enveloppe les middlewares
     *     de Laravel (cf. l'ordre de pile expliqué dans `SsrfGuard`), ce rappel
     *     est REJOUÉ à chaque saut : il couvre déjà C19-003 pour ce client.
     *  2. `SsrfGuard::redirectOptions()` — ceinture ET bretelles. Si un jour
     *     l'ordre de la pile Laravel change (il a déjà changé entre deux
     *     majeures), le point 1 cesse silencieusement de voir les redirections ;
     *     `on_redirect`, lui, est appelé par Guzzle lui-même et ne dépend
     *     d'aucun ordre. Une garde de sécurité qui repose sur un détail
     *     d'implémentation d'un tiers n'est pas une garde.
     */
    public function request(int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): PendingRequest
    {
        $client = Http::timeout($timeoutSeconds)
            ->withOptions(SsrfGuard::redirectOptions())
            ->withRequestMiddleware(function (RequestInterface $request): RequestInterface {
                SsrfGuard::ensure((string) $request->getUri());

                return $request;
            });

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
