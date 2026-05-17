<?php

namespace App\Services\FranceTravail;

use App\Contracts\InseeClient;
use App\Data\Sources\InseeCompanyData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client de DECOUVERTE via France Travail : recherche d'entreprises qui recrutent
 * dans un departement donne (signal intent fort).
 *
 * Distinct de HttpFranceTravailClient (qui fait fetchOffersBySiren sur une entreprise donnee).
 * Utilise l'API offres d'emploi v2 et dedoublonne par SIREN.
 */
class FranceTravailDiscoveryClient
{
    private const OAUTH_TOKEN_URL = 'https://entreprise.francetravail.fr/connexion/oauth2/access_token';

    private const OAUTH_REALM = 'partenaire';

    private const SEARCH_URL = 'https://api.francetravail.io/partenaire/offresdemploi/v2/offres/search';

    private const TOKEN_CACHE_KEY = 'ft_discovery_oauth_token';

    private const HTTP_TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly ?string $clientId = null,
        private readonly ?string $clientSecret = null,
    ) {}

    /**
     * Recherche les entreprises ayant publie des offres d'emploi dans un departement.
     *
     * @return array<int, InseeCompanyData>
     */
    public function searchEntreprisesByDept(string $department, int $limit = 100): array
    {
        $token = $this->getAccessToken();
        if ($token === null) {
            Log::warning('FranceTravailDiscovery: OAuth token unavailable, returning empty');

            return [];
        }

        $limit = max(1, min($limit, 150));
        $range = '0-' . ($limit - 1);

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->withToken($token)
                ->get(self::SEARCH_URL, [
                    'departement' => $department,
                    'range'       => $range,
                ]);

            // 204 No Content = pas d'offres
            if ($response->status() === 204) {
                return [];
            }
            if (! $response->successful()) {
                Log::warning('FranceTravailDiscovery search failed', [
                    'status'     => $response->status(),
                    'department' => $department,
                ]);

                return [];
            }

            $offres = $response->json('resultats', []);

            $candidates = $this->extractUniqueEntreprises(is_array($offres) ? $offres : []);

            // Sprint H3 — Filtre etatAdministratif='A' via INSEE.
            // Entreprises radiées ne doivent pas être enrichies (waterfall économisé).
            return $this->filterActiveByInsee($candidates);
        } catch (\Throwable $e) {
            if (class_exists(\Sentry\State\Hub::class)) {
                \Sentry\captureException($e);
            }
            Log::warning('FranceTravailDiscovery exception', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Sprint H3 — Pour chaque candidat France Travail, valide via INSEE que
     * l'entreprise n'est pas radiée (etatAdministratifUniteLegale='A').
     *
     * Si INSEE indisponible (mock désactivé ou clé manquante) → graceful pass-through
     * (mieux que tout filtrer et perdre 100% du résultat).
     *
     * @param  array<int, InseeCompanyData>  $candidates
     * @return array<int, InseeCompanyData>
     */
    private function filterActiveByInsee(array $candidates): array
    {
        if (empty($candidates)) {
            return [];
        }

        try {
            $insee = app(InseeClient::class);
        } catch (\Throwable $e) {
            Log::debug('FranceTravailDiscovery: InseeClient unavailable, skip filter', ['error' => $e->getMessage()]);
            return $candidates;
        }

        $valid = [];
        foreach ($candidates as $candidate) {
            try {
                $data = $insee->fetchBySiren($candidate->siren);
                if (! $data) {
                    // Siren inconnu INSEE → skip (probablement erreur de saisie côté offre FT)
                    continue;
                }
                if ($data->etatAdministratif !== null && $data->etatAdministratif !== 'A') {
                    // Radiée → skip
                    continue;
                }
            } catch (\Throwable $e) {
                // INSEE error sur un siren → on garde le candidat (graceful) mais log
                Log::debug('FranceTravailDiscovery INSEE check failed', [
                    'siren' => $candidate->siren,
                    'error' => $e->getMessage(),
                ]);
            }
            // On préserve le candidat FT tel quel (FT = source pour la discovery,
            // INSEE consulté uniquement pour filtrer les radiées). L'enrichissement
            // INSEE des autres champs (denomination, naf, effectif) se fait plus
            // tard dans WaterfallOrchestrator::step1_insee.
            $valid[] = $candidate;
        }

        return $valid;
    }

    /**
     * @param  array<int, array<string, mixed>>  $offres
     * @return array<int, InseeCompanyData>
     */
    private function extractUniqueEntreprises(array $offres): array
    {
        $bySiren = [];
        foreach ($offres as $offre) {
            $entreprise = $offre['entreprise'] ?? null;
            if (! is_array($entreprise)) {
                continue;
            }
            $siret = $entreprise['siret'] ?? ($offre['lieuTravail']['libelle'] ?? null);
            if (! is_string($siret) || strlen((string) preg_replace('/\D/', '', $siret)) < 14) {
                // Pas de SIRET utilisable
                continue;
            }
            $siretClean = substr((string) preg_replace('/\D/', '', $siret), 0, 14);
            $siren = substr($siretClean, 0, 9);

            if (isset($bySiren[$siren])) {
                continue;
            }

            $lieu = $offre['lieuTravail'] ?? [];
            $bySiren[$siren] = new InseeCompanyData(
                siren: $siren,
                denomination: isset($entreprise['nom']) && is_string($entreprise['nom']) ? $entreprise['nom'] : null,
                naf: isset($entreprise['activitePrincipale']) && is_string($entreprise['activitePrincipale']) ? $entreprise['activitePrincipale'] : null,
                legalForm: null,
                effectifRange: null,
                address: is_array($lieu) && isset($lieu['libelle']) && is_string($lieu['libelle']) ? $lieu['libelle'] : null,
                postcode: is_array($lieu) && isset($lieu['codePostal']) && is_string($lieu['codePostal']) ? $lieu['codePostal'] : null,
                city: null,
                insee: null,
                createdAt: null,
                raw: [
                    'discovery_source' => 'france_travail',
                    'first_offre_id'   => $offre['id'] ?? null,
                    'siret'            => $siretClean,
                ],
            );
        }

        return array_values($bySiren);
    }

    private function getAccessToken(): ?string
    {
        $clientId = $this->clientId ?? (string) config('services.france_travail.client_id', env('FRANCE_TRAVAIL_CLIENT_ID', ''));
        $clientSecret = $this->clientSecret ?? (string) config('services.france_travail.client_secret', env('FRANCE_TRAVAIL_CLIENT_SECRET', ''));

        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        return Cache::remember(self::TOKEN_CACHE_KEY, now()->addMinutes(55), function () use ($clientId, $clientSecret) {
            try {
                $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                    ->asForm()
                    ->post(self::OAUTH_TOKEN_URL . '?realm=' . urlencode('/' . self::OAUTH_REALM), [
                        'grant_type'    => 'client_credentials',
                        'client_id'     => $clientId,
                        'client_secret' => $clientSecret,
                        'scope'         => 'api_offresdemploiv2 o2dsoffre',
                    ]);
                if (! $response->successful()) {
                    return null;
                }
                $token = $response->json('access_token');

                return is_string($token) && $token !== '' ? $token : null;
            } catch (\Throwable $e) {
                Log::warning('FranceTravailDiscovery OAuth failed', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }
}
