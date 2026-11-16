<?php

namespace App\Envoi\Message;

use Normalizer;

/**
 * Nettoyage DÉTERMINISTE des valeurs lues en base (cahier F2a §2.3). Une valeur douteuse est ÉCARTÉE (le repli
 * s'applique), jamais corrigée ni tronquée : une valeur de la base ne peut plus faire refuser le message entier.
 * Aucune valeur n'est jamais journalisée.
 */
final class ValeursVariables
{
    /** @var array<string, int> longueur maximale par champ */
    private const MAX = ['prenom' => 40, 'nom' => 40, 'fonction' => 60, 'organisation' => 80, 'ville' => 60];

    /** Champs dont une valeur TOUT EN MAJUSCULES ou tout en minuscules passe en casse de titre. */
    private const CASSE_TITRE = ['prenom', 'nom'];

    /**
     * @param  array<string, ?string>  $brutes
     * @return array{valeurs: array<string, string>, ecartees: list<string>}
     */
    public static function nettoyer(array $brutes, ControleurContenu $controleur = new ControleurContenu): array
    {
        $valeurs = [];
        $ecartees = [];
        foreach (VariablesMessage::NOMS as $nom) {
            if (! array_key_exists($nom, $brutes)) {
                continue;
            }
            $v = self::propre((string) $brutes[$nom]);
            if ($v === null || mb_strlen($v) > self::MAX[$nom] || self::suspecte($v) || $controleur->analyser([$v]) !== []) {
                $ecartees[] = $nom;

                continue;
            }
            if (in_array($nom, self::CASSE_TITRE, true) && ($v === mb_strtoupper($v) || $v === mb_strtolower($v))) {
                $v = mb_convert_case($v, MB_CASE_TITLE, 'UTF-8');
            }
            $valeurs[$nom] = $v;
        }

        return ['valeurs' => $valeurs, 'ecartees' => $ecartees];
    }

    /** NFC ; blancs et sauts de ligne => une espace ; autres caractères de contrôle ou invisibles retirés ; espaces fusionnés. */
    private static function propre(string $v): ?string
    {
        if (class_exists(Normalizer::class)) {
            $v = Normalizer::normalize($v, Normalizer::FORM_C);
            if ($v === false) {
                return null;
            }
        }
        $v = preg_replace('/[\s\p{Z}]+/u', ' ', $v);
        $v = $v === null ? null : preg_replace('/[\p{C}]+/u', '', $v);
        if ($v === null) {
            return null;
        }
        $v = trim((string) preg_replace('/ {2,}/', ' ', $v));

        return $v === '' ? null : $v;
    }

    private static function suspecte(string $v): bool
    {
        foreach (['{{', '}}', '@', '<', '>', '://'] as $interdit) {
            if (str_contains($v, $interdit)) {
                return true;
            }
        }

        // Écrit à la façon d'un domaine (« exemple.test », « exemple.test/x ») : jamais une valeur de personne ou d'organisation.
        return stripos($v, 'www.') !== false
            || preg_match('/(?<![\p{L}\d])[\p{L}\d-]+(?:\.[\p{L}\d-]+)*\.\p{L}{2,}(?![\p{L}\d])/u', $v) !== 0;
    }
}
