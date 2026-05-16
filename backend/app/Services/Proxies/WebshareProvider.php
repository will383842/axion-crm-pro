<?php

namespace App\Services\Proxies;

use App\Contracts\ProxyProvider;
use App\Data\Proxies\ProxyEndpointData;

class WebshareProvider implements ProxyProvider
{
    public function listEndpoints(string $zone = 'eu'): array
    {
        throw new \LogicException('WebshareProvider requires MOCK_PROXIES=false + Sprint 4 implementation.');
    }

    public function pickEndpoint(string $zone = 'eu'): ProxyEndpointData
    {
        throw new \LogicException('WebshareProvider requires MOCK_PROXIES=false + Sprint 4 implementation.');
    }

    public function healthCheck(ProxyEndpointData $endpoint): bool
    {
        throw new \LogicException('WebshareProvider requires MOCK_PROXIES=false + Sprint 4 implementation.');
    }
}
