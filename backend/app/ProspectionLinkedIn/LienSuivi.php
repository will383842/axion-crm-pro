<?php

namespace App\ProspectionLinkedIn;

use InvalidArgumentException;

/**
 * Lien suivi unique du parcours. La variante n'est PAS dans l'URL (mesurée
 * dans `pl_journal`). Échec fermé : domaine non configuré ⇒ exception.
 */
final class LienSuivi
{
    public static function pour(string $sequence, string $page): string
    {
        $site = rtrim((string) config('prospection_linkedin.site_url', ''), '/');
        if (! preg_match('#^https://[a-z0-9.-]+$#i', $site)) {
            throw new InvalidArgumentException('Domaine du site non configuré.');
        }
        if (! preg_match('/^[a-z0-9-]{1,40}$/', $sequence)) {
            throw new InvalidArgumentException('Code de séquence invalide.');
        }
        if (! preg_match('#^/?[a-z0-9/_-]{0,100}$#i', $page)) {
            throw new InvalidArgumentException('Page invalide.');
        }

        return $site . '/' . ltrim($page, '/') . '?' . http_build_query([
            'utm_source' => 'linkedin',
            'utm_medium' => 'message',
            'utm_campaign' => 'seq-' . $sequence,
            'depuis' => 'message-linkedin',
        ]);
    }
}
