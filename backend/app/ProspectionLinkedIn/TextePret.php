<?php

namespace App\ProspectionLinkedIn;

use InvalidArgumentException;

/**
 * Composition d'un texte « prêt à copier ». Refus (exception) plutôt que
 * texte dégradé : le fondateur ne reçoit jamais un texte non conforme.
 * Type `invitation` : ≤ 300 caractères. Type `message` (premier message) :
 * phrase d'opposition « répondez STOP » ET lien de politique de
 * confidentialité (information art. 14) obligatoires.
 */
final class TextePret
{
    public const VARIABLES = ['prenom', 'entreprise', 'accroche', 'lien'];

    public const MOTS_REFUSES = ['certifié', 'certification', 'qualiopi'];

    public const MAX_INVITATION = 300;

    public const MAX_MESSAGE = 1900;

    /** @param array<string,string> $variables */
    public static function composer(string $gabarit, array $variables, bool $essai, string $type = 'message'): string
    {
        if (! in_array($type, ['invitation', 'message'], true)) {
            throw new InvalidArgumentException('Type de texte inconnu.');
        }
        foreach (array_keys($variables) as $cle) {
            if (! in_array($cle, self::VARIABLES, true)) {
                throw new InvalidArgumentException("Variable non autorisée : {$cle}");
            }
        }

        $texte = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function (array $m) use ($variables): string {
            if (! in_array($m[1], self::VARIABLES, true) || ! isset($variables[$m[1]])) {
                throw new InvalidArgumentException("Variable manquante ou inconnue : {$m[1]}");
            }

            return (string) $variables[$m[1]];
        }, $gabarit);

        $plat = self::plat($texte);
        foreach (self::MOTS_REFUSES as $mot) {
            if (str_contains($plat, self::plat($mot))) {
                throw new InvalidArgumentException("Mot refusé : {$mot}");
            }
        }

        if ($type === 'invitation') {
            if (mb_strlen($texte) > self::MAX_INVITATION) {
                throw new InvalidArgumentException('Invitation trop longue.');
            }
        } else {
            if (mb_strlen($texte) > self::MAX_MESSAGE) {
                throw new InvalidArgumentException('Message trop long.');
            }
            if (! str_contains($plat, 'repondez stop')) {
                throw new InvalidArgumentException("Phrase d'opposition manquante.");
            }
            $politique = (string) config('prospection_linkedin.politique_confidentialite_url', '');
            if ($politique === '' || ! str_contains($texte, $politique)) {
                throw new InvalidArgumentException('Lien de la politique de confidentialité manquant.');
            }
        }

        return $essai ? '[ESSAI] ' . $texte : $texte;
    }

    /** Minuscules, sans accents. */
    private static function plat(string $s): string
    {
        $s = mb_strtolower($s);

        return strtr($s, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
    }
}
