<?php

declare(strict_types=1);

namespace Passerelle;

/**
 * Garde par défaut du TLS mutuel (AC privée, ADR K0) : tant que l'envoi réel est actif (ou que la variable est absente, vide ou douteuse),
 * une requête sans certificat client vérifié par nginx (`SSL_CLIENT_VERIFY` = « SUCCESS » exactement) est refusée.
 * Défense en profondeur : la configuration nginx (K2-nginx / K3) reste la première barrière.
 */
final class GardeTls
{
    /** @param array<string,mixed> $serveur $_SERVER */
    public static function autorise(array $serveur, ?string $envoiReel): bool
    {
        // Seul « false » EXPLICITE (casse et espaces tolérés) autorise l'absence de certificat client.
        // Variable absente (php-fpm vide l'environnement par défaut), vide, « 0 » ou douteuse : envoi supposé actif.
        $inactif = strtolower(trim((string) $envoiReel)) === 'false';

        return $inactif || ($serveur['SSL_CLIENT_VERIFY'] ?? null) === 'SUCCESS';
    }
}
