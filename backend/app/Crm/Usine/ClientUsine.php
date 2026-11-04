<?php

namespace App\Crm\Usine;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client sortant vers l'Usine (L7). UNIQUE destination : l'URL de configuration
 * (jamais une URL venue d'un utilisateur ni d'une donnée). https obligatoire,
 * aucune redirection suivie. Ni le corps ni la signature ne sont journalisés.
 */
final class ClientUsine
{
    public function __construct(
        private readonly string $url,
        private readonly string $secret,
        private readonly int $delai = 10,
    ) {
        $h = parse_url($url);
        if (($h['scheme'] ?? '') !== 'https' || empty($h['host'])) {
            throw new RuntimeException('URL de l’Usine absente ou non https.');
        }
    }

    public static function depuisConfig(): self
    {
        return new self(
            (string) config('usine.agregats.url', ''),
            (string) config('usine.agregats.secret', ''),
            (int) config('usine.agregats.timeout', 10),
        );
    }

    /**
     * Dates déjà reçues par l'Usine (≤ 7), ou null si l'Usine n'a pas répondu proprement.
     *
     * @return list<string>|null
     */
    public function joursRecus(): ?array
    {
        $reponse = $this->envoyer('GET', '');
        if (! $reponse->successful()) {
            return null;
        }
        $jours = $reponse->json('jours');

        return is_array($jours)
            ? array_values(array_filter($jours, static fn ($j): bool => is_string($j) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $j) === 1))
            : null;
    }

    /** @param  list<array<string, mixed>>  $lignes */
    public function pousser(string $jour, string $genereLe, array $lignes): Response
    {
        $corps = json_encode(['jour' => $jour, 'genere_le' => $genereLe, 'lignes' => $lignes], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return $this->envoyer('POST', $corps);
    }

    private function envoyer(string $methode, string $corps): Response
    {
        $chemin = (string) (parse_url($this->url, PHP_URL_PATH) ?: '/');
        $horodatage = (string) time();
        $nonce = SignatureAgregats::nonce();
        $signature = SignatureAgregats::signer($this->secret, $methode, $chemin, $horodatage, $nonce, $corps);

        $requete = Http::withOptions(['allow_redirects' => false])
            ->timeout($this->delai)->connectTimeout($this->delai)
            ->withHeaders([
                'X-Axion-Horodatage' => $horodatage,
                'X-Axion-Nonce' => $nonce,
                'X-Axion-Signature' => $signature,
                'Accept' => 'application/json',
            ]);

        return $methode === 'POST'
            ? $requete->withBody($corps, 'application/json')->post($this->url)
            : $requete->get($this->url);
    }
}
