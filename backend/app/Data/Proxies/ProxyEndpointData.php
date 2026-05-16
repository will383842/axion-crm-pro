<?php

namespace App\Data\Proxies;

use Spatie\LaravelData\Data;

class ProxyEndpointData extends Data
{
    public function __construct(
        public string $provider,
        public string $type,            // residential | datacenter | mobile
        public string $zone,            // eu, fr, ww
        public string $host,
        public int $port,
        public ?string $username = null,
        public ?string $password = null,
        public int $weight = 1,
        public bool $isHealthy = true,
    ) {}

    public function toProxyUrl(): string
    {
        $auth = $this->username
            ? rawurlencode($this->username).':'.rawurlencode((string) $this->password).'@'
            : '';
        return "http://{$auth}{$this->host}:{$this->port}";
    }
}
