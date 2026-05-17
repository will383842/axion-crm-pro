<?php

namespace App\Services\Bodacc;

use App\Contracts\BodaccClient;
use App\Data\Sources\BodaccAnnouncementData;
use App\Services\Http\SsrfGuard;
use Illuminate\Support\Facades\Http;

class HttpBodaccClient implements BodaccClient
{
    private const BASE_URL = 'https://bodacc-datafluide.opendatasoft.com/api/records/1.0';

    public function fetchAnnouncementsBySiren(string $siren): array
    {
        SsrfGuard::ensure(self::BASE_URL);
        $resp = Http::timeout(15)
            ->get(self::BASE_URL . '/search/', [
                'dataset' => 'annonces-commerciales',
                'q'       => "registre:\"{$siren}\"",
                'rows'    => 50,
                'sort'    => '-dateparution',
            ]);

        if ($resp->failed()) {
            return [];
        }

        $out = [];
        foreach ($resp->json('records', []) as $rec) {
            $f = $rec['fields'] ?? [];
            $out[] = new BodaccAnnouncementData(
                siren: $siren,
                type: $this->mapType((string) ($f['familleavis'] ?? '')),
                publishedAt: (string) ($f['dateparution'] ?? ''),
                tribunal: $f['tribunal'] ?? null,
                reference: $f['numeroannonce'] ?? null,
                rawText: $f['contenu'] ?? null,
            );
        }
        return $out;
    }

    private function mapType(string $famille): string
    {
        return match (true) {
            str_contains($famille, 'création')      => 'creation',
            str_contains($famille, 'modification')  => 'modification',
            str_contains($famille, 'radiation')     => 'radiation',
            str_contains($famille, 'procédure')     => 'procedure',
            default                                  => 'modification',
        };
    }
}
