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
        // Si on filtre par département/commune, on doit utiliser /siret (champs *Etablissement).
        // Sinon /siren (champs *UniteLegale).
        $hasGeo = ! empty($criteria['department']) || ! empty($criteria['commune']);
        $endpoint = $hasGeo ? '/siret' : '/siren';
        $resultsKey = $hasGeo ? 'etablissements' : 'unitesLegales';

        $q = $this->buildQuery($criteria, $hasGeo);
        $results = [];
        $cursor = '*';
        $pageSize = 100;
        $seenSirens = []; // un dépt peut renvoyer plusieurs siret pour le même siren (établissements multiples)

        do {
            $resp = $this->authHttp()
                ->timeout(30)
                ->get(self::BASE_URL . $endpoint, [
                    'q'       => $q,
                    'curseur' => $cursor,
                    'nombre'  => $pageSize,
                    'tri'     => $hasGeo ? 'siret' : 'siren',
                ]);

            if ($resp->failed()) {
                $body = mb_substr((string) $resp->body(), 0, 1000);
                throw new \RuntimeException(
                    "INSEE search error {$resp->status()} on {$endpoint} (q={$q}) — " . $body
                );
            }
            $data = $resp->json();

            if ($hasGeo) {
                foreach ($data[$resultsKey] ?? [] as $etab) {
                    // Filtres post-API (Sirene v3.11 ne les accepte pas dans q) :
                    // 1. Sièges seulement → 1 résultat par entreprise (sinon doublons par établissement)
                    if (! ($etab['etablissementSiege'] ?? false)) continue;
                    $u = $etab['uniteLegale'] ?? [];
                    // 2. Unités légales actives uniquement (exclut radiées/cessées)
                    if (($u['etatAdministratifUniteLegale'] ?? null) !== 'A') continue;

                    $periodes = $u['periodesUniteLegale'][0] ?? $u;
                    $siren = (string) ($etab['siren'] ?? $u['siren'] ?? '');
                    if ($siren === '' || isset($seenSirens[$siren])) continue;
                    $seenSirens[$siren] = true;
                    $results[] = new InseeCompanyData(
                        siren: $siren,
                        denomination: $periodes['denominationUniteLegale']
                            ?? trim(($periodes['prenom1UniteLegale'] ?? '') . ' ' . ($periodes['nomUniteLegale'] ?? '')),
                        naf: $periodes['activitePrincipaleUniteLegale'] ?? null,
                        legalForm: $periodes['categorieJuridiqueUniteLegale'] ?? null,
                        effectifRange: $u['trancheEffectifsUniteLegale'] ?? null,
                        createdAt: $u['dateCreationUniteLegale'] ?? null,
                        raw: $etab,
                    );
                    if (count($results) >= (int) ($criteria['limit'] ?? 1000)) {
                        return $results;
                    }
                }
            } else {
                foreach ($data[$resultsKey] ?? [] as $u) {
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

    /**
     * Construit la query Lucene INSEE Sirene v3.11.
     *
     * @param  array<string,mixed>  $criteria
     * @param  bool  $forSiretEndpoint  true si endpoint /siret (champs *Etablissement),
     *                                   false si /siren (champs *UniteLegale)
     */
    private function buildQuery(array $criteria, bool $forSiretEndpoint = false): string
    {
        $parts = [];

        // NAF — champ different selon endpoint
        if (! empty($criteria['naf'])) {
            if ($forSiretEndpoint) {
                $parts[] = 'activitePrincipaleEtablissement:"' . $criteria['naf'] . '"';
            } else {
                $parts[] = 'periode(activitePrincipaleUniteLegale:"' . $criteria['naf'] . '")';
            }
        }

        // Effectif — uniqueLegale uniquement (les établissements n'ont pas de tranche effectif propre)
        if (! empty($criteria['effectif_min']) || ! empty($criteria['effectif_max'])) {
            $parts[] = 'trancheEffectifsUniteLegale:[' . ($criteria['effectif_min'] ?? '01') . ' TO ' . ($criteria['effectif_max'] ?? '53') . ']';
        }

        // Département — INSEE Sirene v3.11 n'a PAS de champ codeDepartementEtablissement.
        // Il faut filtrer via codeCommuneEtablissement avec wildcard préfixe.
        // Codes commune INSEE = 5 chars :
        //   - métropole : 2 chiffres dept + 3 chiffres commune  (Paris = 75001..75056)
        //   - DROM      : 3 chiffres dept + 2 chiffres commune  (Mayotte 976)
        // IMPORTANT : etablissementSiege + etatAdministratifEtablissement NE SONT PAS
        // autorisés dans le param `q` de Sirene v3.11 (testé via curl : HTTP 400).
        // Ils sont filtrés côté PHP après réception dans searchByCriteria().
        if (! empty($criteria['department']) && $forSiretEndpoint) {
            $dept = preg_replace('/[^0-9A-Za-z]/', '', (string) $criteria['department']);
            // Corse : codes 2A/2B → INSEE indexe en 2A/2B donc on garde tel quel
            $parts[] = 'codeCommuneEtablissement:' . $dept . '*';
        }

        // Commune (code INSEE 5 chars exact) — endpoint /siret
        if (! empty($criteria['commune']) && $forSiretEndpoint) {
            $commune = preg_replace('/[^0-9A-Za-z]/', '', (string) $criteria['commune']);
            $parts[] = 'codeCommuneEtablissement:' . $commune;
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
