<?php

namespace App\Services\Proxies;

use App\Contracts\ProxyProvider;
use App\Data\Proxies\ProxyEndpointData;
use Illuminate\Support\Facades\Http;

/**
 * IPRoyal residential proxies — gateway unique authentifiée par user/pass.
 * Endpoint : `geo.iproyal.com:12321` ; rotation par user-suffix `{user}:{password}_country-fr_session-RAND`.
 */
class IPRoyalProvider implements ProxyProvider
{
    public function listEndpoints(string $zone = 'eu'): array
    {
        $user = (string) env('IPROYAL_USERNAME', '');
        $pass = (string) env('IPROYAL_PASSWORD', '');
        if ($user === '' || $pass === '') {
            throw new \LogicException('IPRoyal credentials not set');
        }

        // Génère 10 sessions distinctes pour rotation
        $endpoints = [];
        for ($i = 0; $i < 10; $i++) {
            $session = substr(bin2hex(random_bytes(4)), 0, 8);
            $country = match ($zone) {
                'fr' => 'fr',
                'eu' => ['fr','de','nl','be','it','es'][$i % 6],
                default => 'fr',
            };
            $endpoints[] = new ProxyEndpointData(
                provider: 'iproyal',
                type:     'residential',
                zone:     $zone,
                host:     'geo.iproyal.com',
                port:     12321,
                username: "{$user}_country-{$country}_session-{$session}",
                password: $pass,
                weight:   2, // residential pèse 2× datacenter
                isHealthy:true,
            );
        }
        return $endpoints;
    }

    public function pickEndpoint(string $zone = 'eu'): ProxyEndpointData
    {
        $list = $this->listEndpoints($zone);
        return $list[array_rand($list)];
    }

    public function healthCheck(ProxyEndpointData $endpoint): bool
    {
        try {
            $resp = Http::withOptions(['proxy' => $endpoint->toProxyUrl(), 'verify' => false])
                ->timeout(15)
                ->get('https://api.ipify.org?format=json');
            return $resp->ok();
        } catch (\Throwable) {
            return false;
        }
    }
}
