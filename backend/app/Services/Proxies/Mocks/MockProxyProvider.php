<?php

namespace App\Services\Proxies\Mocks;

use App\Contracts\ProxyProvider;
use App\Data\Proxies\ProxyEndpointData;

class MockProxyProvider implements ProxyProvider
{
    public function listEndpoints(string $zone = 'eu'): array
    {
        return [
            new ProxyEndpointData('mock', 'datacenter', $zone, '127.0.0.1', 0, weight: 1, isHealthy: true),
        ];
    }

    public function pickEndpoint(string $zone = 'eu'): ProxyEndpointData
    {
        return $this->listEndpoints($zone)[0];
    }

    public function healthCheck(ProxyEndpointData $endpoint): bool
    {
        return true;
    }
}
