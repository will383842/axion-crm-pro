<?php

namespace App\Services\FranceTravail;

use App\Contracts\FranceTravailClient;
use App\Data\Sources\JobOfferData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * France Travail (ex Pôle Emploi) Offres d'Emploi API v2.
 * OAuth2 client_credentials. Endpoint `https://api.francetravail.io/partenaire/offresdemploi/v2`.
 */
class HttpFranceTravailClient implements FranceTravailClient
{
    private const BASE_URL = 'https://api.francetravail.io/partenaire/offresdemploi/v2';

    public function fetchOffersBySiren(string $siren): array
    {
        $token = $this->getToken();
        $resp = Http::withToken($token)
            ->timeout(15)
            ->get(self::BASE_URL . '/offres/search', [
                'entreprise.siren' => $siren,
                'range'            => '0-99',
            ]);

        if ($resp->status() === 204 || $resp->failed()) {
            return [];
        }

        $out = [];
        foreach ($resp->json('resultats', []) as $offer) {
            $out[] = new JobOfferData(
                siren: $siren,
                title: (string) ($offer['intitule'] ?? ''),
                publishedAt: $offer['dateCreation'] ?? null,
                city: $offer['lieuTravail']['libelle'] ?? null,
                contract: $offer['typeContrat'] ?? null,
                sourceUrl: $offer['origineOffre']['urlOrigine'] ?? null,
            );
        }
        return $out;
    }

    private function getToken(): string
    {
        return Cache::remember('francetravail:token', 1500, function () {
            $id = (string) env('FRANCE_TRAVAIL_CLIENT_ID', '');
            $secret = (string) env('FRANCE_TRAVAIL_CLIENT_SECRET', '');
            if ($id === '' || $secret === '') {
                throw new \LogicException('FRANCE_TRAVAIL_CLIENT_ID + SECRET required');
            }
            $resp = Http::asForm()->post(
                'https://entreprise.francetravail.fr/connexion/oauth2/access_token?realm=%2Fpartenaire',
                [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $id,
                    'client_secret' => $secret,
                    'scope'         => 'api_offresdemploiv2 o2dsoffre',
                ],
            );
            if ($resp->failed()) {
                throw new \RuntimeException('FranceTravail auth error');
            }
            return (string) $resp->json('access_token');
        });
    }
}
