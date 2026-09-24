<?php

namespace App\Support;

/**
 * NEUTRALISATION D'UNE CELLULE CSV (injection de formule, CWE-1236).
 *
 * Un tableur (Excel, LibreOffice) EXÉCUTE une cellule qui commence par `=`,
 * `+`, `-` ou `@` — et, selon la version, par une tabulation ou un retour
 * chariot suivis d'une formule. Un nom saisi dans un formulaire PUBLIC
 * (`=HYPERLINK("…","x")`) deviendrait alors un lien piégé, voire une commande,
 * à l'ouverture de l'export. On préfixe ces cellules d'une apostrophe : le
 * tableur l'affiche comme du texte, rien n'est perdu.
 *
 * Premier usage : l'export « Personnes (lettre et guide) » (lot L4-C), le
 * premier export du dépôt dont les valeurs viennent d'un formulaire anonyme.
 */
final class CelluleCsv
{
    private const DECLENCHEURS = ['=', '+', '-', '@', "\t", "\r"];

    public static function neutraliser(mixed $valeur): mixed
    {
        if (! is_string($valeur) || $valeur === '') {
            return $valeur;
        }

        return in_array($valeur[0], self::DECLENCHEURS, true) ? "'" . $valeur : $valeur;
    }

    /**
     * @param  array<int, mixed>  $ligne
     * @return array<int, mixed>
     */
    public static function ligne(array $ligne): array
    {
        return array_map(self::neutraliser(...), $ligne);
    }
}
