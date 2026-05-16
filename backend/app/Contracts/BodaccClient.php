<?php

namespace App\Contracts;

use App\Data\Sources\BodaccAnnouncementData;

interface BodaccClient
{
    /** @return list<BodaccAnnouncementData> */
    public function fetchAnnouncementsBySiren(string $siren): array;
}
