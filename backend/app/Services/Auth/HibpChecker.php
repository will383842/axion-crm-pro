<?php

namespace App\Services\Auth;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Sprint 18.1 — vérification HaveIBeenPwned d'un mot de passe.
 *
 * API k-Anonymity (https://haveibeenpwned.com/API/v3#PwnedPasswords) :
 *   1) SHA-1 du mot de passe en clair
 *   2) on envoie les 5 premiers caractères du hachage
 *   3) l'API renvoie tous les hachages de ce préfixe, avec leur compteur
 *   4) on cherche le suffixe (35 caractères) dans la réponse
 * Le mot de passe en clair ne quitte JAMAIS le serveur. Cache 24 h par préfixe.
 *
 * 🔴 CE SERVICE NE FAIT PLUS DE « FAIL-OPEN ». Il en faisait un, assumé en
 * commentaire : toute erreur réseau renvoyait `0`, c'est-à-dire « ce mot de passe
 * n'apparaît dans aucune fuite ». Une panne DNS, un pare-feu sortant ou une
 * indisponibilité de l'API suffisaient donc à désactiver le contrôle **en
 * silence**, et `NotPwnedPassword` acceptait alors le mot de passe `password`.
 * Mesuré le 2026-08-19 (audit 360, F35-004).
 *
 * Le contrat est désormais à trois états, et l'appelant DOIT les distinguer :
 *   - `int >= 0` : réponse obtenue, voici le nombre de fuites connues
 *   - `null`     : service indisponible — on ne sait pas, et on ne prétend pas savoir
 */
class HibpChecker
{
    public const API_BASE_URL = 'https://api.pwnedpasswords.com/range/';

    public const CACHE_TTL_SECONDS = 86400; // 24h

    public const DEFAULT_THRESHOLD = 5;     // au-delà de 5 apparitions → refusé

    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client([
            'timeout' => 5,
            'connect_timeout' => 3,
            'headers' => [
                'User-Agent' => 'Axion-CRM-Pro/1.0 (security@axion-crm-pro.com)',
                'Accept' => 'text/plain',
                // HIBP recommande le remplissage de la réponse (anonymat renforcé).
                'Add-Padding' => 'true',
            ],
        ]);
    }

    /**
     * Nombre de fuites connues contenant ce mot de passe.
     *
     * @return int|null `null` si le service est injoignable — surtout PAS `0`,
     *                  qui signifie « vérifié, et sain ».
     */
    public function getBreachCount(string $plainPassword): ?int
    {
        if ($plainPassword === '') {
            return 0;
        }

        $sha1 = strtoupper(sha1($plainPassword));
        $prefix = substr($sha1, 0, 5);
        $suffix = substr($sha1, 5);

        $cacheKey = 'hibp:range:' . $prefix;

        try {
            $body = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($prefix) {
                $response = $this->http->get(self::API_BASE_URL . $prefix);
                $status = $response->getStatusCode();
                if ($status !== 200) {
                    throw new \RuntimeException("HIBP API status {$status}");
                }

                return (string) $response->getBody();
            });
        } catch (ConnectException $e) {
            Log::warning('HibpChecker : service injoignable', ['error' => $e->getMessage()]);

            return null;
        } catch (GuzzleException $e) {
            Log::warning('HibpChecker : erreur Guzzle', ['error' => $e->getMessage()]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('HibpChecker : erreur inattendue', ['error' => $e->getMessage()]);

            return null;
        }

        // Corps : "<SUFFIXE>:<COMPTEUR>\r\n..." ; avec Add-Padding, des lignes
        // "<SUFFIXE>:0" sont mêlées pour l'anonymat.
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            if (strtoupper($parts[0]) === $suffix) {
                return (int) $parts[1];
            }
        }

        return 0;
    }

    /**
     * @return bool|null `true` compromis, `false` sain, `null` service indisponible.
     */
    public function isBreached(string $plainPassword, int $threshold = self::DEFAULT_THRESHOLD): ?bool
    {
        $count = $this->getBreachCount($plainPassword);

        return $count === null ? null : $count > $threshold;
    }
}
