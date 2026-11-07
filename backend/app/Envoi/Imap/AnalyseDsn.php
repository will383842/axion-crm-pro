<?php

namespace App\Envoi\Imap;

/**
 * Lecture PURE (sans réseau) des en-têtes et d'un rapport de remise (DSN,
 * RFC 3464) à partir du texte brut d'un message. Testable sans serveur.
 */
final class AnalyseDsn
{
    /**
     * @return array{statut: ?string, messageId: ?string, destinataire: ?string}
     */
    public static function depuisBrut(string $brut): array
    {
        $statut = null;
        if (preg_match('/^Status:\s*([2-5]\.\d{1,3}\.\d{1,3})\b/mi', $brut, $m) === 1) {
            $statut = $m[1];
        }
        $destinataire = null;
        if (preg_match('/^Final-Recipient:\s*rfc822;\s*<?([^<>\s;]+@[^<>\s;]+)>?/mi', $brut, $m) === 1) {
            $destinataire = $m[1];
        }
        // $brut est le CORPS du rapport : son premier Message-ID est celui du
        // message d'ORIGINE cité (les en-têtes du rapport lui-même n'y figurent pas).
        $messageId = null;
        if (preg_match('/^Message-ID:\s*<?([^<>\s]+)>?/mi', $brut, $m) === 1) {
            $messageId = $m[1];
        }
        if (preg_match('/^X-Original-Message-ID:\s*<?([^<>\s]+)>?/mi', $brut, $m) === 1) {
            $messageId = $m[1];
        }

        return ['statut' => $statut, 'messageId' => $messageId, 'destinataire' => $destinataire];
    }

    /**
     * Quelques en-têtes utiles, clés en minuscules (dépliés).
     *
     * @return array<string, string>
     */
    public static function enTetes(string $entete): array
    {
        $entete = (string) preg_replace('/\r?\n[ \t]+/', ' ', $entete);
        $utiles = ['auto-submitted', 'precedence', 'x-autoreply', 'x-autorespond', 'content-type'];
        $sortie = [];
        foreach (preg_split('/\r?\n/', $entete) ?: [] as $ligne) {
            if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $ligne, $m) === 1 && in_array(strtolower($m[1]), $utiles, true)) {
                $sortie[strtolower($m[1])] = trim($m[2]);
            }
        }

        return $sortie;
    }
}
