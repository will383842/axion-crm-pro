<?php

declare(strict_types=1);

namespace Passerelle;

/**
 * Garde par défaut du TLS mutuel (AC privée, ADR K0) : tant que l'envoi réel est actif (ou que sa valeur est douteuse),
 * une requête sans certificat client vérifié par nginx (`SSL_CLIENT_VERIFY` = « SUCCESS » exactement) est refusée.
 * Défense en profondeur : la configuration nginx (K2-nginx / K3) reste la première barrière.
 */
final class GardeTls
{
    /** @param array<string,mixed> $serveur $_SERVER */
    public static function autorise(array $serveur, ?string $envoiReel): bool
    {
        $inactif = in_array(strtolower(trim((string) $envoiReel)), ['', 'false', '0'], true);

        return $inactif || ($serveur['SSL_CLIENT_VERIFY'] ?? null) === 'SUCCESS';
    }
}
