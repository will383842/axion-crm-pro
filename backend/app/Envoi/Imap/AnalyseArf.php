<?php

namespace App\Envoi\Imap;

/**
 * Lecture PURE d'un rapport d'abus ARF (RFC 5965 : JMRP, Yahoo CFL…). Un échec PCRE lève `EchecAnalyse`.
 * `adresses` : Original-Mail-From (porte le VERP). `messageId` : premier Message-ID du message d'origine cité.
 */
final class AnalyseArf
{
    /** Types ARF qui valent une plainte (RFC 5965). */
    public const TYPES_PLAINTE = ['abuse', 'fraud', 'virus'];

    /** Types connus SANS effet : ni plainte, ni transmission (ce n'est pas une auto-réponse non plus). */
    public const TYPES_SANS_EFFET = ['not-spam', 'auth-failure'];

    /** Tout autre type (« other », inconnu, « illisible ») : « à rattacher », revue humaine, jamais compté. */
    public static function typeConnu(?string $type): bool
    {
        return in_array($type, [...self::TYPES_PLAINTE, ...self::TYPES_SANS_EFFET], true);
    }

    public static function estRapport(string $contentType): bool
    {
        return Pcre::correspond('/report-type\s*=\s*"?\s*feedback-report\s*"?/i', $contentType);
    }

    /** @return array{type: ?string, destinataire: ?string, messageId: ?string, adresses: list<string>} */
    public static function depuisBrut(string $brut): array
    {
        $courriel = '<?([^<>\s;]+@[^<>\s;]+)>?';
        $type = Pcre::groupes('/^Feedback-Type:\s*([A-Za-z-]+)/mi', $brut);
        $dest = Pcre::groupes('/^Original-Rcpt-To:\s*' . $courriel . '/mi', $brut);
        $de = Pcre::groupes('/^Original-Mail-From:\s*' . $courriel . '/mi', $brut);
        $mid = Pcre::groupes('/^Message-ID:\s*<?([^<>\s]+)>?/mi', $brut);

        return [
            'type' => $type !== null ? strtolower($type[0]) : null,
            'destinataire' => $dest[0] ?? null,
            'messageId' => $mid[0] ?? null,
            'adresses' => $de !== null ? [$de[0]] : [],
        ];
    }

    /**
     * Adresses locales du message reçu (Delivered-To, X-Original-To, Envelope-To, To) : où chercher un VERP ou abuse@.
     *
     * @param  array<string, string>  $enTetes
     * @return list<string>
     */
    public static function adressesDesEnTetes(array $enTetes): array
    {
        $sortie = [];
        foreach (['delivered-to', 'x-original-to', 'envelope-to', 'to'] as $nom) {
            foreach (Pcre::tousLesGroupes1('/([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+)/', $enTetes[$nom] ?? '') as $a) {
                $sortie[$a] = true;
            }
        }

        return array_map('strval', array_keys($sortie));
    }
}
