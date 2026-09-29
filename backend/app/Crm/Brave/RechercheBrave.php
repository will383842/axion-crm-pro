<?php

namespace App\Crm\Brave;

use App\Services\Http\SsrfGuard;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * UNE requête à l'API Brave Search — comptée AVANT d'être envoyée.
 *
 * C'est le SEUL émetteur de requêtes Brave du dépôt — le passage des
 * fédérations (`crm:federations:trouver-sites`) comme l'enrichissement
 * (`DomainFinderService::find()`) passent par ici, chacun sous son USAGE.
 * `BraveUnSeulEmetteurTest` garde qu'aucun autre fichier n'appelle l'API.
 *
 * Aucune requête ne part sans une réservation réussie dans `QuotaBrave`
 * (sous-quota de l'usage ET plafond global). Sans clé, rien n'est réservé.
 *
 * Pas de `->retry()` : chaque nouvel essai serait une requête facturée que le
 * compteur ne verrait pas.
 *
 * 🔴 LE DÉPÔT EST PUBLIC. La clé part dans l'en-tête `X-Subscription-Token`,
 * jamais dans l'URL ; aucun message d'exception n'est rendu ni journalisé
 * (celui d'une `ConnectionException` porte l'URL, donc la requête, donc le
 * nom de l'organisme) — seulement une catégorie et un code HTTP.
 */
class RechercheBrave
{
    public const URL = 'https://api.search.brave.com/res/v1/web/search';

    private const DELAI_SECONDES = 10;

    /** Codes après lesquels continuer ne sert à rien : clé refusée, crédit ou cadence épuisés. */
    private const CODES_BLOQUANTS = [401, 402, 403, 429];

    public function __construct(private readonly QuotaBrave $quota) {}

    public function cleConfiguree(): bool
    {
        $cle = config('services.brave.api_key');

        return is_string($cle) && trim($cle) !== '';
    }

    /**
     * `sans_cle` et `plafond` : rien n'a été envoyé. `bloque` : arrêter le
     * passage. `$usage` : l'un de `QuotaBrave::USAGES`.
     *
     * @return array{etat: 'sans_cle'|'plafond'|'ok'|'erreur'|'bloque', urls: list<string>, code: int|null}
     */
    public function chercher(string $requete, string $usage, int $nombre = 10): array
    {
        if (! $this->cleConfiguree()) {
            return ['etat' => 'sans_cle', 'urls' => [], 'code' => null];
        }
        if (! $this->quota->reserver($usage)) {
            return ['etat' => 'plafond', 'urls' => [], 'code' => null];
        }

        try {
            $reponse = Http::timeout(self::DELAI_SECONDES)
                ->withHeaders([
                    'X-Subscription-Token' => (string) config('services.brave.api_key'),
                    'Accept' => 'application/json',
                ])
                ->withOptions(SsrfGuard::redirectOptions())
                ->get(self::URL, [
                    'q' => $requete,
                    'count' => max(1, min(20, $nombre)),
                    'country' => 'fr',
                    'search_lang' => 'fr',
                    'safesearch' => 'moderate',
                ]);
        } catch (Throwable) {
            return ['etat' => 'erreur', 'urls' => [], 'code' => null];
        }

        $code = $reponse->status();
        if (in_array($code, self::CODES_BLOQUANTS, true)) {
            return ['etat' => 'bloque', 'urls' => [], 'code' => $code];
        }
        if (! $reponse->successful()) {
            return ['etat' => 'erreur', 'urls' => [], 'code' => $code];
        }

        $resultats = $reponse->json('web.results', []);
        $urls = [];
        foreach (is_array($resultats) ? $resultats : [] as $r) {
            $url = is_array($r) ? ($r['url'] ?? null) : null;
            if (is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }

        return ['etat' => 'ok', 'urls' => $urls, 'code' => $code];
    }
}
