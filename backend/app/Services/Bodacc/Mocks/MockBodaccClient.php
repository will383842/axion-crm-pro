<?php

namespace App\Services\Bodacc\Mocks;

use App\Contracts\BodaccClient;

class MockBodaccClient implements BodaccClient
{
    public function fetchAnnouncementsBySiren(string $siren): array
    {
        return [];
    }
}
