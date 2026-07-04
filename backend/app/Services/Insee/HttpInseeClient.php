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
            etatAdministratif: $periodes['etatAdministratifUniteLegale']
                ?? $u['etatAdministratifUniteLegale']
                ?? null,
        );
    }

    public function searchByCriteria(array $criteria): array
    {
        $limit = (int) ($criteria['limit'] ?? 1000);
        $results = [];
        foreach ($this->iterateByCriteria($criteria) as $company) {
            $results[] = $company;
            if ($limit > 0 && count($results) >= $limit) {
                break;
            }
        }
        return $results;
    }

    /**
     * Itère TOUTES les entreprises correspondant aux critères, en paginant par
     * curseur (générateur → pas de chargement total en mémoire). Permet de
     * récupérer un DÉPARTEMENT ENTIER avec sauvegarde au fil de l'eau
     * (cf. commande prospection:collect). Respecte le rate-limit INSEE.
     *
     * `$criteria['req_delay_ms']` : délai entre requêtes (défaut 2100ms ≈ 30 req/min,
     * plan « Accès public »). Baisser si plan « Accès authentifié » (500 req/min).
     *
     * @param  array<string,mixed>  $criteria
     * @return \Generator<int, InseeCompanyData>
     */
    public function iterateByCriteria(array $criteria): \Generator
    {
        // /siret (champs *Etablissement) si filtre géo, sinon /siren (*UniteLegale).
        $hasGeo = ! empty($criteria['department']) || ! empty($criteria['commune']);
        $endpoint = $hasGeo ? '/siret' : '/siren';
        $resultsKey = $hasGeo ? 'etablissements' : 'unitesLegales';

        $q = $this->buildQuery($criteria, $hasGeo);
        $cursor = '*';
        $pageSize = 1000; // max INSEE Sirene v3.11 → 10× moins de requêtes
        $delayMs = (int) ($criteria['req_delay_ms'] ?? 2100);
        $seenSirens = []; // dédup : un dépt renvoie plusieurs siret pour le même siren
        $retries = 0;     // tentatives sur le curseur courant (429/5xx) — BORNÉ

        do {
            $resp = $this->authHttp()
                ->timeout(30)
                ->retry(2, 2000, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException)
                ->get(self::BASE_URL . $endpoint, [
                    'q'       => $q,
                    'curseur' => $cursor,
                    'nombre'  => $pageSize,
                    'tri'     => $hasGeo ? 'siret' : 'siren',
                ]);

            // Rate-limit atteint → attendre puis retenter le même curseur (BORNÉ :
            // évite une boucle infinie si le quota est saturé durablement).
            if ($resp->status() === 429) {
                if (++$retries > 30) {
                    throw new \RuntimeException('INSEE 429 persistant (quota/limite atteint ?) après 30 tentatives.');
                }
                sleep(20);
                continue;
            }
            // Erreur serveur transitoire (5xx) → petit backoff + retry borné.
            if ($resp->serverError()) {
                if (++$retries > 8) {
                    throw new \RuntimeException("INSEE {$resp->status()} persistant après 8 tentatives sur {$endpoint}.");
                }
                sleep(5);
                continue;
            }
            if ($resp->failed()) {
                throw new \RuntimeException(
                    "INSEE search error {$resp->status()} on {$endpoint} (q={$q}) — "
                    . mb_substr((string) $resp->body(), 0, 1000)
                );
            }
            $retries = 0; // page réussie → on remet le compteur à zéro
            $data = $resp->json();

            if ($hasGeo) {
                foreach ($data[$resultsKey] ?? [] as $etab) {
                    // Filtres post-API (Sirene v3.11 les refuse dans q) :
                    if (! ($etab['etablissementSiege'] ?? false)) continue;     // sièges seulement
                    $u = $etab['uniteLegale'] ?? [];
                    if (($u['etatAdministratifUniteLegale'] ?? null) !== 'A') continue; // actives
                    // Diffusibles seulement (RGPD) : exclut les « [ND] » — personnes qui
                    // ont refusé la diffusion publique de leurs données INSEE.
                    if (($u['statutDiffusionUniteLegale'] ?? 'O') !== 'O') continue;
                    $periodes = $u['periodesUniteLegale'][0] ?? $u;
                    $siren = (string) ($etab['siren'] ?? $u['siren'] ?? '');
                    if ($siren === '' || isset($seenSirens[$siren])) continue;
                    $seenSirens[$siren] = true;
                    // Adresse de l'établissement (siège) — dispo dès la récupération INSEE.
                    $adr = $etab['adresseEtablissement'] ?? [];
                    $rue = trim(implode(' ', array_filter([
                        $adr['numeroVoieEtablissement'] ?? '',
                        $adr['typeVoieEtablissement'] ?? '',
                        $adr['libelleVoieEtablissement'] ?? '',
                    ])));
                    yield new InseeCompanyData(
                        siren: $siren,
                        denomination: $periodes['denominationUniteLegale']
                            ?? trim(($periodes['prenom1UniteLegale'] ?? '') . ' ' . ($periodes['nomUniteLegale'] ?? '')),
                        naf: $periodes['activitePrincipaleUniteLegale'] ?? null,
                        legalForm: $periodes['categorieJuridiqueUniteLegale'] ?? null,
                        effectifRange: $u['trancheEffectifsUniteLegale'] ?? null,
                        address: $rue !== '' ? $rue : null,
                        postcode: $adr['codePostalEtablissement'] ?? null,
                        city: $adr['libelleCommuneEtablissement'] ?? null,
                        insee: $adr['codeCommuneEtablissement'] ?? null,
                        createdAt: $u['dateCreationUniteLegale'] ?? null,
                        raw: $etab,
                        etatAdministratif: $u['etatAdministratifUniteLegale']
                            ?? $periodes['etatAdministratifUniteLegale'] ?? null,
                    );
                }
            } else {
                foreach ($data[$resultsKey] ?? [] as $u) {
                    $periodes = $u['periodesUniteLegale'][0] ?? [];
                    yield new InseeCompanyData(
                        siren: (string) ($u['siren'] ?? ''),
                        denomination: $periodes['denominationUniteLegale'] ?? null,
                        naf: $periodes['activitePrincipaleUniteLegale'] ?? null,
                        legalForm: $periodes['categorieJuridiqueUniteLegale'] ?? null,
                        effectifRange: $u['trancheEffectifsUniteLegale'] ?? null,
                        etatAdministratif: $periodes['etatAdministratifUniteLegale']
                            ?? $u['etatAdministratifUniteLegale'] ?? null,
                    );
                }
            }

            $nextCursor = $data['header']['curseurSuivant'] ?? null;
            // Fin de pagination : INSEE renvoie le MÊME curseur (ou null/'*') sur la
            // DERNIÈRE page. SANS ce test → boucle infinie qui re-traite la dernière page.
            if ($nextCursor === null || $nextCursor === '' || $nextCursor === '*' || $nextCursor === $cursor) {
                break;
            }
            $cursor = $nextCursor;
            if ($delayMs > 0) {
                usleep($delayMs * 1000); // respecte le rate-limit avant la page suivante
            }
        } while (true);
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
