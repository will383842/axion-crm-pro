<?php

namespace App\Services\Auth;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Sprint 18.1 — HaveIBeenPwned password check.
 *
 * Utilise l'API k-Anonymity gratuite (https://haveibeenpwned.com/API/v3#PwnedPasswords) :
 *   1) Hash SHA-1 du password en clair
 *   2) Envoie les 5 premiers caractères du hash
 *   3) API renvoie une liste de tous les hashes commençant par ce préfixe + leur compteur
 *   4) On cherche le suffixe (35 chars restants) dans la réponse
 *
 * Avantage : le password en clair ne quitte JAMAIS le serveur.
 *
 * Cache Redis 24h pour réduire les appels (key = sha1 prefix).
 */
class HibpChecker
{
    public const API_BASE_URL = 'https://api.pwnedpasswords.com/range/';
    public const CACHE_TTL_SECONDS = 86400; // 24h
    public const DEFAULT_THRESHOLD = 5;     // breaches > 5 → refusé

    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client([
            'timeout'         => 5,
            'connect_timeout' => 3,
            'headers'         => [
                'User-Agent' => 'Axion-CRM-Pro/1.0 (security@axion-crm-pro.com)',
                'Accept'     => 'text/plain',
                // HIBP demande l'opt-in padding response pour anonymity++
                'Add-Padding' => 'true',
            ],
        ]);
    }

    /**
     * Retourne le nombre de fois où ce password apparait dans les breaches connus.
     * 0 = jamais vu, > 0 = breached.
     *
     * En cas d'erreur réseau, retourne 0 (fail-open) pour ne pas bloquer un user
     * légitime sur indisponibilité de l'API externe. La sécurité reste assurée
     * par les autres règles (password min length, rate limit, 2FA).
     */
    public function getBreachCount(string $plainPassword): int
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
            Log::warning('HibpChecker: connection error', ['error' => $e->getMessage()]);
            return 0;
        } catch (GuzzleException $e) {
            Log::warning('HibpChecker: guzzle error', ['error' => $e->getMessage()]);
            return 0;
        } catch (\Throwable $e) {
            Log::warning('HibpChecker: unexpected', ['error' => $e->getMessage()]);
            return 0;
        }

        // Body format : "<SUFFIX>:<COUNT>\r\n<SUFFIX>:<COUNT>\r\n..."
        // Avec Add-Padding: true, des lignes "<SUFFIX>:0" sont mélangées (anonymity).
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
     * Helper : retourne true si le password est considéré breached (count > threshold).
     */
    public function isBreached(string $plainPassword, int $threshold = self::DEFAULT_THRESHOLD): bool
    {
        return $this->getBreachCount($plainPassword) > $threshold;
    }
}
