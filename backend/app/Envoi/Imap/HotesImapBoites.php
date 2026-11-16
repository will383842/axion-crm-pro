<?php

namespace App\Envoi\Imap;

/**
 * LISTE FERMÉE des hôtes IMAP admis pour les boîtes d'envoi (D1). Elle est répétée à l'identique dans le CHECK de la table
 * `envoi_boites_acces_imap` : ni commande ni base ne peut nommer un autre hôte (pas de SSRF). Port 993 (TLS implicite) seul.
 */
final class HotesImapBoites
{
    /** @var list<string> */
    public const LISTE = ['imap.gmail.com', 'imap.mail.yahoo.com', 'imap.orange.fr', 'outlook.office365.com'];

    public static function hoteAutorise(string $hote): bool
    {
        return in_array($hote, self::LISTE, true);
    }
}
