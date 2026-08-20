<?php

namespace App\Support;

/**
 * Vérification de signature HMAC-SHA256 pour les canaux machine-à-machine.
 *
 * 🔑 CETTE CLASSE EST LE PATRON DE RÉFÉRENCE DU DÉPÔT. Tout nouveau canal
 * machine-à-machine passe par elle. Ne recopiez pas la vérification à la main
 * dans un contrôleur : c'est exactement ce qui a produit F37-001 (S0).
 *
 * ⚠️ RECTIFICATION — constat P5-HMAC-001. Ce docbloc affirmait « reprend le
 * patron déjà en place sur `POST /internal/scraper-result` … le seul canal
 * machine authentifié du CRM ». Les deux moitiés étaient trompeuses :
 *
 *   - `/internal/scraper-result` n'était pas un patron, c'était la version
 *     FAIBLE — secret vide accepté (fail-open), aucun horodatage, donc
 *     rejouable indéfiniment. C'est le défaut F37-001, corrigé depuis en le
 *     faisant passer par cette classe-ci ;
 *   - l'affirmation est devenue circulaire : la classe écrite pour corriger le
 *     défaut se déclarait dérivée du code défectueux, lequel dérive maintenant
 *     d'elle.
 *
 * Ce que la classe apporte par rapport à une vérification écrite à la main :
 * secret vide = `return false` (porte fermée, jamais ouverte), tolérance du
 * préfixe `sha256=`, comparaison à temps constant, et l'horodatage signé.
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
