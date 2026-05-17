<?php

namespace App\Services\Insee;

use App\Contracts\InseeClient;
use App\Data\Sources\InseeCompanyData;
use App\Services\Http\SsrfGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

/**
 * INSEE Sirene API V3 — `https://api.insee.fr/entreprises/sirene/V3.11`.
 * Rate limit : 30 req/min anonyme, 500/min avec clé.
 */
class HttpInseeClient implements InseeClient
{
    private const BASE_URL = 'https://api.insee.fr/entreprises/sirene/V3.11';

    public function fetchBySiren(string $siren): ?InseeCompanyData
    {
        SsrfGuard::ensure(self::BASE_URL);
        $token = $this->getToken();
        $resp = Http::withToken($token)
            ->timeout(15)
            ->retry(2, 1000, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException)
            ->get(self::BASE_URL . "/siren/{$siren}");

        if ($resp->status() === 404) {
            return null;
        }
        if ($resp->failed()) {
            throw new \RuntimeException("INSEE API error {$resp->status()}");
        }

        $u = $resp->json('uniteLegale', []);
        $periodes = $u['periodesUniteLegale'][0] ?? [];

        return new InseeCompanyData(
            siren: $siren,
            denomination: $periodes['denominationUniteLegale']
                ?? trim(($periodes['prenom1UniteLegale'] ?? '') . ' ' . ($periodes['nomUniteLegale'] ?? '')),
            naf: $periodes['activitePrincipaleUniteLegale'] ?? null,
            legalForm: $periodes['categorieJuridiqueUniteLegale'] ?? null,
            effectifRange: $u['trancheEffectifsUniteLegale'] ?? null,
            createdAt: $u['dateCreationUniteLegale'] ?? null,
            raw: $u,
        );
    }

    public function searchByCriteria(array $criteria): array
    {
        $q = $this->buildQuery($criteria);
        $token = $this->getToken();
        $results = [];
        $cursor = '*';
        $pageSize = 100;

        do {
            $resp = Http::withToken($token)
                ->timeout(30)
                ->get(self::BASE_URL . '/siren', [
                    'q'        => $q,
                    'curseur'  => $cursor,
                    'nombre'   => $pageSize,
                    'tri'      => 'siren',
                ]);

            if ($resp->failed()) {
                throw new \RuntimeException("INSEE search error {$resp->status()}");
            }
            $data = $resp->json();
            foreach ($data['unitesLegales'] ?? [] as $u) {
                $periodes = $u['periodesUniteLegale'][0] ?? [];
                $results[] = new InseeCompanyData(
                    siren: (string) ($u['siren'] ?? ''),
                    denomination: $periodes['denominationUniteLegale'] ?? null,
                    naf: $periodes['activitePrincipaleUniteLegale'] ?? null,
                    legalForm: $periodes['categorieJuridiqueUniteLegale'] ?? null,
                    effectifRange: $u['trancheEffectifsUniteLegale'] ?? null,
                );
                if (count($results) >= (int) ($criteria['limit'] ?? 1000)) {
                    return $results;
                }
            }
            $cursor = $data['header']['curseurSuivant'] ?? null;
        } while ($cursor && $cursor !== '*');

        return $results;
    }

    private function buildQuery(array $criteria): string
    {
        $parts = [];
        if (! empty($criteria['naf'])) {
            $parts[] = 'periode(activitePrincipaleUniteLegale:"' . $criteria['naf'] . '")';
        }
        if (! empty($criteria['effectif_min']) || ! empty($criteria['effectif_max'])) {
            $parts[] = 'trancheEffectifsUniteLegale:[' . ($criteria['effectif_min'] ?? '01') . ' TO ' . ($criteria['effectif_max'] ?? '53') . ']';
        }
        if (! empty($criteria['department'])) {
            $parts[] = 'codeCommuneEtablissement:' . $criteria['department'] . '*';
        }
        return implode(' AND ', $parts) ?: '*';
    }

    private function getToken(): string
    {
        return Cache::remember('insee:token', 3500, function () {
            $client = (string) env('INSEE_CLIENT_ID', '');
            $secret = (string) env('INSEE_CLIENT_SECRET', '');
            if ($client === '' || $secret === '') {
                throw new \LogicException('INSEE_CLIENT_ID + INSEE_CLIENT_SECRET required');
            }
            $resp = Http::withBasicAuth($client, $secret)
                ->asForm()
                ->timeout(15)
                ->post('https://api.insee.fr/token', ['grant_type' => 'client_credentials']);
            if ($resp->failed()) {
                throw new \RuntimeException('INSEE auth error: ' . $resp->status());
            }
            return (string) $resp->json('access_token');
        });
    }
}
