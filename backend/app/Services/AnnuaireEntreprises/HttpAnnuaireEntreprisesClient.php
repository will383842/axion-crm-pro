<?php

namespace App\Services\AnnuaireEntreprises;

use App\Contracts\AnnuaireEntreprisesClient;
use App\Data\Sources\AnnuaireEntrepriseData;
use Illuminate\Support\Facades\Http;

/**
 * Recherche Entreprises API gouv — `https://recherche-entreprises.api.gouv.fr`.
 * Public, gratuit, sans clé. Remplace Pappers (payant). Données légales fraîches.
 */
class HttpAnnuaireEntreprisesClient implements AnnuaireEntreprisesClient
{
    private const BASE_URL = 'https://recherche-entreprises.api.gouv.fr';

    public function fetchBySiren(string $siren): ?AnnuaireEntrepriseData
    {
        $resp = Http::timeout(15)
            ->retry(2, 1000)
            ->get(self::BASE_URL . '/search', ['q' => $siren, 'page' => 1, 'per_page' => 1]);

        if ($resp->failed()) {
            throw new \RuntimeException('Annuaire Entreprises API error: ' . $resp->status());
        }
        $r = $resp->json('results', []);
        if (empty($r)) {
            return null;
        }
        $entry = $r[0];

        $representatives = [];
        foreach ($entry['dirigeants'] ?? [] as $dir) {
            $representatives[] = [
                'role'       => (string) ($dir['qualite'] ?? 'dirigeant'),
                'first_name' => $dir['prenoms'] ?? null,
                'last_name'  => (string) ($dir['nom'] ?? ''),
                'birth_date' => $dir['date_naissance'] ?? null,
            ];
        }

        $finances = $entry['finances'] ?? [];
        $lastYear = ! empty($finances) ? max(array_keys($finances)) : null;

        return new AnnuaireEntrepriseData(
            siren: $siren,
            denomination: $entry['nom_complet'] ?? $entry['nom_raison_sociale'] ?? null,
            naf: $entry['activite_principale'] ?? null,
            representatives: $representatives,
            chiffreAffaires: $lastYear ? (float) ($finances[$lastYear]['ca'] ?? 0) : null,
            resultatNet: $lastYear ? (int) ($finances[$lastYear]['resultat_net'] ?? 0) : null,
            bilansLastYear: $lastYear ? (string) $lastYear : null,
            raw: $entry,
        );
    }
}
