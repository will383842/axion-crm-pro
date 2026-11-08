<?php

namespace App\Envoi\Imap;

/**
 * Adresse de rebond VERP : `rb-<jeton>@bounce.<domaine>` (fourre-tout de la réception D2).
 * Le jeton = identifiant d'injection (32 hex) + 10 hex de signature HMAC : un jeton falsifié ou signé
 * avec une autre clé ne rattache RIEN. Sans clé (`envoi.cle_empreinte` vide) : jamais de rattachement.
 *
 * ROTATION : changer `envoi.cle_empreinte` invalide TOUS les VERP déjà émis (leurs rebonds et plaintes tomberaient
 * en « à rattacher », sans effet automatique). Conserver l'ancienne clé le temps que les derniers messages
 * envoyés cessent de rebondir (quelques jours), ou accepter la revue humaine de ces retours.
 */
final class Verp
{
    private const MOTIF = '/^rb-([0-9a-f]{32})([0-9a-f]{10})@bounce\.[a-z0-9.-]+$/';

    public static function adressePour(string $idInjection, string $domaine): string
    {
        // Seul un UUID est un identifiant d'injection : tout autre texte ferait un jeton qui ne se relit jamais.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', strtolower($idInjection)) !== 1) {
            throw new \InvalidArgumentException('Identifiant d\'injection VERP : un UUID est exigé.');
        }
        $hex = str_replace('-', '', strtolower($idInjection));

        return 'rb-' . $hex . self::signature($hex) . '@bounce.' . strtolower($domaine);
    }

    /** UUID de l'injection si l'adresse est un VERP valide et signé, sinon null. */
    public static function idInjectionDepuis(string $adresse): ?string
    {
        $cle = (string) config('envoi.cle_empreinte', '');
        if ($cle === '') {
            return null;
        }
        try {
            $g = Pcre::groupes(self::MOTIF, strtolower(trim($adresse, " \t\r\n<>")));
        } catch (EchecAnalyse) {
            return null;
        }
        if ($g === null || ! hash_equals(self::signature($g[0]), $g[1])) {
            return null;
        }

        return preg_replace('/^(.{8})(.{4})(.{4})(.{4})(.{12})$/', '$1-$2-$3-$4-$5', $g[0]);
    }

    private static function signature(string $hex): string
    {
        return substr(hash_hmac('sha256', 'verp:' . $hex, (string) config('envoi.cle_empreinte', '')), 0, 10);
    }
}
