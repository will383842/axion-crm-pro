<?php

namespace App\Support;

/**
 * Vérification de signature HMAC-SHA256 pour les canaux machine-à-machine.
 *
 * Reprend le patron déjà en place sur `POST /internal/scraper-result`
 * (`X-Worker-Signature`, `hash_equals`) — le seul canal machine authentifié du
 * CRM, l'API v1 étant en Sanctum cookie SPA — en y ajoutant l'horodatage signé.
 *
 * Ce que l'horodatage apporte : sans lui, une requête légitime interceptée peut
 * être REJOUÉE indéfiniment (la signature reste valide pour toujours). Ici, le
 * corps signé est « <timestamp>.<corps> » : hors fenêtre, le message est
 * refusé. L'idempotence par `event_id` protège de la duplication de données ;
 * l'horodatage protège du rejeu tardif, ce n'est pas la même chose.
 */
final class HmacSignature
{
    /**
     * Comparaison à temps constant (`hash_equals`) : une comparaison naïve
     * fuit, par le temps qu'elle met à échouer, le nombre de caractères
     * corrects — de quoi reconstruire une signature valide octet par octet.
     */
    public static function verify(string $secret, string $payload, ?string $received): bool
    {
        if ($secret === '' || $received === null || $received === '') {
            return false;
        }

        $received = str_starts_with($received, 'sha256=') ? substr($received, 7) : $received;

        return hash_equals(hash_hmac('sha256', $payload, $secret), $received);
    }

    public static function sign(string $secret, string $payload): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    /** Corps effectivement signé : l'horodatage EST dans la signature. */
    public static function signedPayload(string $timestamp, string $body): string
    {
        return $timestamp . '.' . $body;
    }

    public static function timestampWithinWindow(?string $timestamp, int $maxSkewSeconds): bool
    {
        if ($maxSkewSeconds <= 0) {
            return true;
        }
        if ($timestamp === null || preg_match('/^\d{1,12}$/', $timestamp) !== 1) {
            return false;
        }

        return abs(time() - (int) $timestamp) <= $maxSkewSeconds;
    }
}
