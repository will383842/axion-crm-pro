<?php

namespace App\Contracts;

use App\Data\Proxies\ProxyEndpointData;

interface ProxyProvider
{
    /** @return list<ProxyEndpointData> */
    public function listEndpoints(string $zone = 'eu'): array;

    public function pickEndpoint(string $zone = 'eu'): ProxyEndpointData;

    public function healthCheck(ProxyEndpointData $endpoint): bool;
}
