<?php

namespace App\Envoi\Temoins;

use App\Envoi\Chauffe\Fournisseur;
use InvalidArgumentException;

/**
 * LISTE FERMÉE des fournisseurs de témoins relevables (R16). L'hôte IMAP est CODÉ ici (et répété dans le CHECK de la
 * table `envoi_temoins_acces`) : ni commande, ni route, ni base ne peut en nommer un autre, donc aucune connexion
 * sortante vers un hôte choisi par quelqu'un (pas de SSRF).
 *
 * Limite écrite (rapport de la commande) : la pause automatique s'appuie sur {@see Fournisseur} (gmail, microsoft, autre).
 * Yahoo et Orange sont donc rattachés à « autre » pour la pause : un message en indésirables chez eux freine « autre ».
 */
final class HotesTemoins
{
    /** @var array<string, array{hote: string, dossiers: list<string>, pause: string}> */
    public const LISTE = [
        'gmail' => ['hote' => 'imap.gmail.com', 'dossiers' => ['[Gmail]/Spam', '[Gmail]/Courrier indésirable'], 'pause' => Fournisseur::GMAIL],
        'google_workspace' => ['hote' => 'imap.gmail.com', 'dossiers' => ['[Gmail]/Spam', '[Gmail]/Courrier indésirable'], 'pause' => Fournisseur::GMAIL],
        'outlook_com' => ['hote' => 'outlook.office365.com', 'dossiers' => ['Junk', 'Junk Email', 'Courrier indésirable'], 'pause' => Fournisseur::MICROSOFT],
        'microsoft_365' => ['hote' => 'outlook.office365.com', 'dossiers' => ['Junk', 'Junk Email', 'Courrier indésirable'], 'pause' => Fournisseur::MICROSOFT],
        'yahoo' => ['hote' => 'imap.mail.yahoo.com', 'dossiers' => ['Bulk', 'Bulk Mail'], 'pause' => Fournisseur::AUTRE],
        'orange' => ['hote' => 'imap.orange.fr', 'dossiers' => ['Spam', 'Junk', 'Courrier indésirable'], 'pause' => Fournisseur::AUTRE],
    ];

    /** @return array{hote: string, dossiers: list<string>, pause: string} */
    public static function pour(string $fournisseur): array
    {
        return self::LISTE[$fournisseur] ?? throw new InvalidArgumentException('Fournisseur de témoin hors liste.');
    }

    public static function hoteAutorise(string $hote): bool
    {
        foreach (self::LISTE as $fiche) {
            if ($fiche['hote'] === $hote) {
                return true;
            }
        }

        return false;
    }

    /** Famille de pause (gmail, microsoft, autre) d'un fournisseur de témoin. */
    public static function pourPause(string $fournisseur): string
    {
        return self::pour($fournisseur)['pause'];
    }
}
