<?php

namespace App\Services\Insee;

use App\Contracts\InseeClient;
use App\Data\Sources\InseeCompanyData;
use App\Services\Http\SsrfGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

/**
 * INSEE Sirene API V3.11 — `https://api.insee.fr/api-sirene/3.11`.
 *
 * Deux modes d'auth supportés selon le plan souscrit sur portail-api.insee.fr :
 *
 *  1. Plan "Accès public" (gratuit, 30 req/min) → API Key simple dans header
 *     `X-INSEE-Api-Key-Integration`. Configurable via `.env` :
 *        INSEE_API_KEY=<clé>
 *
 *  2. Plan "Accès authentifié" (gratuit, 500 req/min) → OAuth2 client_credentials.
 *        INSEE_CLIENT_ID=<consumer key>
 *        INSEE_CLIENT_SECRET=<consumer secret>
 *
 * Le client détecte automatiquement le mode selon les vars d'env présentes.
 */
class HttpInseeClient implements InseeClient
{
    private const BASE_URL = 'https://api.insee.fr/api-sirene/3.11';

    public function fetchBySiren(string $siren): ?InseeCompanyData
    {
        SsrfGuard::ensure(self::BASE_URL);

        $resp = $this->authHttp()
            ->timeout(15)
            ->retry(2, 1000, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException)
            ->get(self::BASE_URL . "/siren/{$siren}");

        if ($resp->status() === 404) {
            return null;
        }
        if ($resp->failed()) {
            throw new \RuntimeException("INSEE API error {$resp->status()}: " . $resp->body());
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
        $results = [];
        $cursor = '*';
        $pageSize = 100;

        do {
            $resp = $this->authHttp()
                ->timeout(30)
                ->get(self::BASE_URL . '/siren', [
                    'q'       => $q,
                    'curseur' => $cursor,
                    'nombre'  => $pageSize,
                    'tri'     => 'siren',
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

    /**
     * Construit un client HTTP authentifié selon le mode disponible (API Key prioritaire).
     */
    private function authHttp(): \Illuminate\Http\Client\PendingRequest
    {
        $apiKey = (string) env('INSEE_API_KEY', '');
        if ($apiKey !== '') {
            return Http::withHeaders(['X-INSEE-Api-Key-Integration' => $apiKey]);
        }
        // Fallback OAuth2 (plan authentifié, 500 req/min)
        $token = $this->getOAuthToken();
        return Http::withToken($token);
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

    private function getOAuthToken(): string
    {
        return Cache::remember('insee:token', 3500, function () {
            $client = (string) env('INSEE_CLIENT_ID', '');
            $secret = (string) env('INSEE_CLIENT_SECRET', '');
            if ($client === '' || $secret === '') {
                throw new \LogicException(
                    'INSEE auth requires either INSEE_API_KEY (plan public) ' .
                    'or INSEE_CLIENT_ID + INSEE_CLIENT_SECRET (plan authentifié).'
                );
            }
            $resp = Http::withBasicAuth($client, $secret)
                ->asForm()
                ->timeout(15)
                ->post('https://api.insee.fr/token', ['grant_type' => 'client_credentials']);
            if ($resp->failed()) {
                throw new \RuntimeException('INSEE OAuth error: ' . $resp->status());
            }
            return (string) $resp->json('access_token');
        });
    }
}
