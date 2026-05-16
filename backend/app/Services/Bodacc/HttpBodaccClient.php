<?php

namespace App\Services\Bodacc;

use App\Contracts\BodaccClient;

class HttpBodaccClient implements BodaccClient
{
    public function fetchAnnouncementsBySiren(string $siren): array
    {
        throw new \LogicException('HttpBodaccClient implemented in Sprint 5.');
    }
}
