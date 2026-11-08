<?php

namespace App\Envoi\Imap;

/**
 * Lecture PURE (sans réseau) des en-têtes et d'un rapport de remise (DSN,
 * RFC 3464) à partir du texte brut d'un message. Testable sans serveur.
 * Un échec PCRE lève `EchecAnalyse` (jamais « aucun rapport » en silence).
 */
final class AnalyseDsn
{
    /**
     * @return array{statut: ?string, messageId: ?string, destinataire: ?string}
     */
    public static function depuisBrut(string $brut): array
    {
        $statut = null;
        if (($g = Pcre::groupes('/^Status:\s*([2-5]\.\d{1,3}\.\d{1,3})\b/mi', $brut)) !== null) {
            $statut = $g[0];
        }
        $destinataire = null;
        if (($g = Pcre::groupes('/^Final-Recipient:\s*rfc822;\s*<?([^<>\s;]+@[^<>\s;]+)>?/mi', $brut)) !== null) {
            $destinataire = $g[0];
        }
        // $brut est le CORPS du rapport : son premier Message-ID est celui du
        // message d'ORIGINE cité (les en-têtes du rapport lui-même n'y figurent pas).
        $messageId = null;
        if (($g = Pcre::groupes('/^Message-ID:\s*<?([^<>\s]+)>?/mi', $brut)) !== null) {
            $messageId = $g[0];
        }
        if (($g = Pcre::groupes('/^X-Original-Message-ID:\s*<?([^<>\s]+)>?/mi', $brut)) !== null) {
            $messageId = $g[0];
        }

        return ['statut' => $statut, 'messageId' => $messageId, 'destinataire' => $destinataire];
    }

    /** Vrai rapport de remise : `report-type=delivery-status`, avec ou sans guillemets. */
    public static function estRapport(string $contentType): bool
    {
        return Pcre::correspond('/report-type\s*=\s*"?\s*delivery-status\s*"?/i', $contentType);
    }

    /**
     * N'extrait statut, Message-ID et destinataire QUE d'un vrai rapport : le
     * Message-ID cité dans une réponse humaine ne doit pas servir au rattachement.
     *
     * @return array{statut: ?string, messageId: ?string, destinataire: ?string}
     */
    public static function pourContenu(string $contentType, string $brut): array
    {
        return self::estRapport($contentType)
            ? self::depuisBrut($brut)
            : ['statut' => null, 'messageId' => null, 'destinataire' => null];
    }

    /**
     * Quelques en-têtes utiles, clés en minuscules (dépliés).
     *
     * @return array<string, string>
     */
    public static function enTetes(string $entete): array
    {
        $entete = Pcre::remplacer('/\r?\n[ \t]+/', ' ', $entete);
        $utiles = ['auto-submitted', 'precedence', 'x-autoreply', 'x-autorespond', 'content-type', 'delivered-to', 'x-original-to', 'envelope-to', 'to'];
        $sortie = [];
        foreach (Pcre::decouper('/\r?\n/', $entete) as $ligne) {
            if (($g = Pcre::groupes('/^([A-Za-z0-9-]+):\s*(.*)$/', $ligne)) !== null && in_array(strtolower($g[0]), $utiles, true)) {
                $sortie[strtolower($g[0])] = trim($g[1]);
            }
        }

        return $sortie;
    }
}
