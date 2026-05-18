<?php

namespace App\Services\Scraping;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Places API (New) — wrapper officiel server-side (Sprint H9 — 2026-05-18).
 *
 * Remplace le scraping Google Maps via Playwright worker Node :
 *  - Légal à 100% (API officielle Google)
 *  - Pas de proxy résidentiel requis
 *  - Pas de CAPTCHA à résoudre
 *  - Sélecteurs stables (Google maintient la spec)
 *  - JSON natif, pas de parsing HTML fragile
 *
 * Pricing (https://developers.google.com/maps/billing-and-pricing/pricing) :
 *  - Place Details : $17 / 1000 requêtes
 *  - $200 / mois de crédit gratuit Maps Platform → ~12K requêtes gratuites/mois
 *
 * Cache 30 jours (Redis prod) pour ne pas re-payer les mêmes lookups.
 * Graceful : pas de GOOGLE_PLACES_API_KEY → retourne null silencieusement.
 */
class GooglePlacesClient
{
    private const SEARCH_TEXT_ENDPOINT = 'https://places.googleapis.com/v1/places:searchText';

    private const CACHE_TTL_DAYS = 30;

    private const HTTP_TIMEOUT_SECONDS = 15;

    /**
     * Champs demandés à Google Places (FieldMask).
     * Garder cette liste minimaliste car la facturation Place Details dépend
     * du nombre de champs SKU demandés (Basic, Contact, Atmosphere).
     */
    private const FIELDS = [
        'places.id',
        'places.displayName',
        'places.formattedAddress',
        'places.location',
        'places.businessStatus',
        'places.types',
        'places.primaryType',
        'places.internationalPhoneNumber',
        'places.nationalPhoneNumber',
        'places.websiteUri',
        'places.rating',
        'places.userRatingCount',
        'places.regularOpeningHours.weekdayDescriptions',
    ];

    /**
     * Cherche un établissement par texte libre ("Boulangerie Dupont Paris").
     *
     * Sprint H12 — Retourne :
     *  - array place (succès)
     *  - null avec context dans le 2e arg byref si pas trouvé / erreur
     *  - null si quota mensuel free dépassé (et $reason='quota_exceeded')
     *
     * @param  string  $query
     * @param  string|null  $regionCode
     * @param  string|null  $reason  byref : si null retourné, indique pourquoi
     *                                ('no_api_key', 'quota_exceeded', 'not_found',
     *                                'http_error', 'exception')
     * @return array<string,mixed>|null
     */
    public function searchText(string $query, ?string $regionCode = 'FR', ?string &$reason = null): ?array
    {
        $reason = null;
        $query = trim($query);
        if ($query === '') {
            $reason = 'empty_query';
            return null;
        }

        $apiKey = config('services.google.places.api_key');
        if (! $apiKey) {
            Log::debug('GooglePlacesClient skipped (no API key)');
            $reason = 'no_api_key';
            return null;
        }

        $cacheKey = 'gplaces:searchText:' . md5($query . '|' . ($regionCode ?? ''));
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached === '__null__' ? null : $cached;
        }

        // Sprint H12 — Quota mensuel garde-fou : ne dépasse jamais le seuil gratuit
        // Google Maps Platform ($200/mois crédit ≈ 11500 Place Details "Pro Text Search").
        // Si dépassé : on ne fait PAS l'appel, on marque la company comme "pending"
        // côté caller pour retraitement le mois suivant.
        if ($this->isQuotaExceeded()) {
            Log::info('GooglePlacesClient quota exceeded, skipping call', [
                'query' => $query,
                'used'  => $this->currentMonthUsage(),
                'limit' => $this->monthlyQuotaLimit(),
            ]);
            $reason = 'quota_exceeded';
            return null;
        }

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->withHeaders([
                    'X-Goog-Api-Key'    => (string) $apiKey,
                    'X-Goog-FieldMask'  => implode(',', self::FIELDS),
                    'Accept'            => 'application/json',
                    'Content-Type'      => 'application/json',
                ])
                ->retry(2, 1000, function (\Throwable $e) {
                    return $e instanceof \Illuminate\Http\Client\ConnectionException;
                })
                ->post(self::SEARCH_TEXT_ENDPOINT, [
                    'textQuery'    => $query,
                    'languageCode' => 'fr',
                    'regionCode'   => $regionCode ?? 'FR',
                    'maxResultCount' => 1,
                ]);

            // Sprint H12 — Incrémente le compteur quota (même sur 4xx/5xx car Google
            // peut facturer une requête même rejected). On compte tôt pour ne jamais
            // sous-estimer notre usage réel.
            $this->incrementMonthlyUsage();

            if (! $response->successful()) {
                if (class_exists(\Sentry\State\Hub::class)) {
                    \Sentry\captureMessage(
                        "GooglePlaces HTTP {$response->status()} for query: {$query}"
                    );
                }
                Log::warning('GooglePlaces HTTP error', [
                    'status' => $response->status(),
                    'query'  => $query,
                ]);
                Cache::put($cacheKey, '__null__', now()->addDays(1));
                $reason = 'http_error';
                return null;
            }

            $places = $response->json('places', []);
            if (! is_array($places) || empty($places)) {
                Cache::put($cacheKey, '__null__', now()->addDays(self::CACHE_TTL_DAYS));
                $reason = 'not_found';
                return null;
            }

            $place = $places[0];
            Cache::put($cacheKey, $place, now()->addDays(self::CACHE_TTL_DAYS));

            return $place;
        } catch (\Throwable $e) {
            if (class_exists(\Sentry\State\Hub::class)) {
                \Sentry\captureException($e);
            }
            Log::warning('GooglePlaces exception', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            $reason = 'exception';
            return null;
        }
    }

    /**
     * Sprint H12 — Quota mensuel free tier ($200/mois crédit Maps Platform).
     * Default 11500 (laisse une marge de 500 sur les 12K free).
     */
    public function monthlyQuotaLimit(): int
    {
        return (int) config('services.google.places.monthly_quota_limit', 11500);
    }

    /**
     * Sprint H12 — Compteur d'appels Google Places effectués ce mois-ci.
     * Stocké en Redis cache (TTL 35 jours pour couvrir le reset mensuel).
     */
    public function currentMonthUsage(): int
    {
        $key = $this->monthlyQuotaCacheKey();
        return (int) (Cache::get($key) ?? 0);
    }

    public function isQuotaExceeded(): bool
    {
        return $this->currentMonthUsage() >= $this->monthlyQuotaLimit();
    }

    private function incrementMonthlyUsage(): void
    {
        $key = $this->monthlyQuotaCacheKey();
        try {
            $current = (int) (Cache::get($key) ?? 0);
            Cache::put($key, $current + 1, now()->addDays(35));
        } catch (\Throwable $e) {
            Log::debug('GooglePlaces quota counter update failed', ['error' => $e->getMessage()]);
        }
    }

    private function monthlyQuotaCacheKey(): string
    {
        return 'gplaces:quota:' . now()->format('Y-m');
    }

    /**
     * Helper : extrait les infos utiles dans un format adapté à la fiche company.
     *
     * @param  array<string,mixed>|null  $place  payload retourné par searchText()
     * @return array{
     *   phone: ?string,
     *   website: ?string,
     *   address: ?string,
     *   lat: ?float,
     *   lon: ?float,
     *   rating: ?float,
     *   user_rating_count: ?int,
     *   business_status: ?string,
     *   primary_type: ?string,
     *   types: array<int,string>,
     *   opening_hours: array<int,string>,
     *   google_place_id: ?string,
     *   display_name: ?string
     * }
     */
    public function flatten(?array $place): array
    {
        if ($place === null) {
            return [
                'phone' => null, 'website' => null, 'address' => null,
                'lat' => null, 'lon' => null,
                'rating' => null, 'user_rating_count' => null,
                'business_status' => null, 'primary_type' => null,
                'types' => [], 'opening_hours' => [],
                'google_place_id' => null, 'display_name' => null,
            ];
        }

        $phone = $place['internationalPhoneNumber'] ?? $place['nationalPhoneNumber'] ?? null;
        $location = $place['location'] ?? [];

        return [
            'phone'             => is_string($phone) ? $phone : null,
            'website'           => isset($place['websiteUri']) && is_string($place['websiteUri'])
                ? $place['websiteUri']
                : null,
            'address'           => isset($place['formattedAddress']) && is_string($place['formattedAddress'])
                ? $place['formattedAddress']
                : null,
            'lat'               => isset($location['latitude']) ? (float) $location['latitude'] : null,
            'lon'               => isset($location['longitude']) ? (float) $location['longitude'] : null,
            'rating'            => isset($place['rating']) ? (float) $place['rating'] : null,
            'user_rating_count' => isset($place['userRatingCount']) ? (int) $place['userRatingCount'] : null,
            'business_status'   => isset($place['businessStatus']) && is_string($place['businessStatus'])
                ? $place['businessStatus']
                : null,
            'primary_type'      => isset($place['primaryType']) && is_string($place['primaryType'])
                ? $place['primaryType']
                : null,
            'types'             => isset($place['types']) && is_array($place['types'])
                ? array_values(array_filter($place['types'], 'is_string'))
                : [],
            'opening_hours'     => isset($place['regularOpeningHours']['weekdayDescriptions'])
                && is_array($place['regularOpeningHours']['weekdayDescriptions'])
                ? array_values(array_filter($place['regularOpeningHours']['weekdayDescriptions'], 'is_string'))
                : [],
            'google_place_id'   => isset($place['id']) && is_string($place['id']) ? $place['id'] : null,
            'display_name'      => isset($place['displayName']['text']) && is_string($place['displayName']['text'])
                ? $place['displayName']['text']
                : null,
        ];
    }
}
