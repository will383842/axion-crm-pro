<?php

namespace App\ProspectionLinkedIn;

use InvalidArgumentException;

/**
 * Machine à états monotone de la file LinkedIn (voie S). Pure : aucune base.
 * `ecarte` est absorbant et l'emporte toujours ; un geste qui ferait reculer
 * l'état est rendu `sans_effet` (jamais d'exception).
 */
final class EtatsProspect
{
    /** Chaîne principale, dans l'ordre. */
    public const CHAINE = [
        'candidat', 'qualifie', 'controle', 'en_file', 'invite', 'connecte',
        'message_envoye', 'repondu', 'humain', 'rdv', 'clos',
    ];

    public const BRANCHES = ['ecarte', 'en_pause', 'sans_suite', 'invitation_retiree', 'plus_tard'];

    /** Geste → état cible. */
    public const GESTES = [
        'invitation_faite' => 'invite',
        'invitation_acceptee' => 'connecte',
        'message_fait' => 'message_envoye',
        'a_repondu' => 'repondu',
        'invitation_retiree' => 'invitation_retiree',
        'plus_tard' => 'plus_tard',
        'interesse' => 'humain',
        'rdv_pris' => 'rdv',
        'non' => 'clos',
        'hors_cible' => 'ecarte',
        'opposition' => 'ecarte',
    ];

    /** Rang des branches dans l'ordre de la chaîne (en_pause : figé, géré à part). */
    private const RANG_BRANCHE = ['sans_suite' => 6, 'invitation_retiree' => 3, 'plus_tard' => 7];

    /** États depuis lesquels une branche sortante est possible. */
    private const ORIGINES_BRANCHE = [
        'invitation_retiree' => ['invite'],
        'plus_tard' => ['message_envoye', 'repondu', 'humain'],
    ];

    /** @return array{etat: string, sans_effet: bool} */
    public static function appliquer(string $etat, string $geste): array
    {
        if (! array_key_exists($geste, self::GESTES)) {
            throw new InvalidArgumentException("Geste inconnu : {$geste}");
        }
        if (! in_array($etat, self::CHAINE, true) && ! in_array($etat, self::BRANCHES, true)) {
            throw new InvalidArgumentException("État inconnu : {$etat}");
        }

        $cible = self::GESTES[$geste];
        $rien = ['etat' => $etat, 'sans_effet' => true];

        if ($cible === 'ecarte') {
            return ['etat' => 'ecarte', 'sans_effet' => $etat === 'ecarte'];
        }
        if ($etat === 'ecarte' || $etat === 'en_pause') {
            return $rien;
        }
        if (isset(self::ORIGINES_BRANCHE[$cible])) {
            return in_array($etat, self::ORIGINES_BRANCHE[$cible], true)
                ? ['etat' => $cible, 'sans_effet' => false]
                : $rien;
        }

        return self::rang($cible) > self::rang($etat)
            ? ['etat' => $cible, 'sans_effet' => false]
            : $rien;
    }

    private static function rang(string $etat): int
    {
        $i = array_search($etat, self::CHAINE, true);

        return $i !== false ? $i : self::RANG_BRANCHE[$etat];
    }
}
