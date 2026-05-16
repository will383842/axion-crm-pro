<?php

namespace App\Services\Proxies;

use App\Contracts\ProxyProvider;
use App\Data\Proxies\ProxyEndpointData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Webshare API v2 — `https://proxy.webshare.io/api/v2/proxy/list/?mode=direct&page_size=100`.
 * Liste cached 10 min ; health check via probe `https://api.ipify.org`.
 */
class WebshareProvider implements ProxyProvider
{
    private const API_BASE = 'https://proxy.webshare.io/api/v2';

    /** @return list<ProxyEndpointData> */
    public function listEndpoints(string $zone = 'eu'): array
    {
        $apiKey = (string) env('WEBSHARE_API_KEY', '');
        if ($apiKey === '') {
            throw new \LogicException('WEBSHARE_API_KEY not set');
        }

        return Cache::remember("webshare:list:{$zone}", 600, function () use ($apiKey, $zone) {
            $resp = Http::withHeaders(['Authorization' => "Token {$apiKey}"])
                ->timeout(15)
                ->get(self::API_BASE . '/proxy/list/', [
                    'mode'      => 'direct',
                    'page_size' => 100,
                    'country_code__in' => $this->zoneToCountries($zone),
                ]);

            if ($resp->failed()) {
                throw new \RuntimeException('Webshare API error: ' . $resp->status());
            }

            $endpoints = [];
            foreach ($resp->json('results', []) as $row) {
                $endpoints[] = new ProxyEndpointData(
                    provider: 'webshare',
                    type:     'datacenter',
                    zone:     $zone,
                    host:     (string) ($row['proxy_address'] ?? ''),
                    port:     (int) ($row['port'] ?? 0),
                    username: (string) ($row['username'] ?? '') ?: null,
                    password: (string) ($row['password'] ?? '') ?: null,
                    weight:   1,
                    isHealthy:(bool) ($row['valid'] ?? true),
                );
            }
            return $endpoints;
        });
    }

    public function pickEndpoint(string $zone = 'eu'): ProxyEndpointData
    {
        $list = $this->listEndpoints($zone);
        $list = array_values(array_filter($list, fn ($e) => $e->isHealthy));
        if (empty($list)) {
            throw new \RuntimeException('No healthy Webshare endpoint for zone ' . $zone);
        }
        return $list[array_rand($list)];
    }

    public function healthCheck(ProxyEndpointData $endpoint): bool
    {
        try {
            $resp = Http::withOptions(['proxy' => $endpoint->toProxyUrl(), 'verify' => false])
                ->timeout(10)
                ->get('https://api.ipify.org?format=json');
            return $resp->ok() && filter_var($resp->json('ip'), FILTER_VALIDATE_IP) !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function zoneToCountries(string $zone): string
    {
        return match ($zone) {
            'fr'    => 'FR',
            'eu'    => 'FR,DE,NL,BE,IT,ES,PL,SE,FI,DK,IE',
            default => '',
        };
    }
}
