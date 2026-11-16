<?php

namespace App\Envoi\Message;

/**
 * Liste FERMÉE des champs de fusion d'un message (cahier F2a §2.2). Aucune lecture en base ici : noms seulement.
 * Un nom inconnu est refusé à l'écriture et à la validation d'un message type ; à l'envoi il prend son repli.
 */
final class VariablesMessage
{
    public const NOMS = ['prenom', 'nom', 'organisation', 'ville', 'fonction'];

    /** @return list<string> noms trouvés dans les gabarits (triés, sans doublon), connus ou non */
    public static function utilisees(string ...$gabarits): array
    {
        preg_match_all('/\{\{(.*?)\}\}/s', implode("\n", $gabarits), $m);
        $noms = [];
        foreach ($m[1] as $interieur) {
            $nom = trim(explode('|', $interieur, 2)[0]);
            if ($nom !== '') {
                $noms[$nom] = true;
            }
        }
        $noms = array_map('strval', array_keys($noms));
        sort($noms);

        return $noms;
    }

    /** @return list<string> motifs « Variable {{x}} inconnue : champs permis : prenom, nom, organisation, ville, fonction. » */
    public static function inconnues(string ...$gabarits): array
    {
        $motifs = [];
        foreach (self::utilisees(...$gabarits) as $nom) {
            if (! in_array($nom, self::NOMS, true)) {
                $motifs[] = 'Variable {{' . $nom . '}} inconnue : champs permis : ' . implode(', ', self::NOMS) . '.';
            }
        }

        return $motifs;
    }
}
