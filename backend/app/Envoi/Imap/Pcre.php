<?php

namespace App\Envoi\Imap;

/**
 * Enveloppes de preg_* : un échec (`null`/`false` : UTF-8 invalide, limite PCRE) lève
 * `EchecAnalyse` au lieu de devenir silencieusement une chaîne vide ou « aucune correspondance ».
 */
final class Pcre
{
    public static function remplacer(string $motif, string $remplacement, string $sujet): string
    {
        $r = preg_replace($motif, $remplacement, $sujet);
        if (! is_string($r)) {
            throw new EchecAnalyse('preg_replace : ' . preg_last_error_msg());
        }

        return $r;
    }

    /** @return list<string> */
    public static function decouper(string $motif, string $sujet): array
    {
        $r = preg_split($motif, $sujet);
        if ($r === false) {
            throw new EchecAnalyse('preg_split : ' . preg_last_error_msg());
        }

        return $r;
    }

    public static function correspond(string $motif, string $sujet): bool
    {
        $r = preg_match($motif, $sujet);
        if ($r === false) {
            throw new EchecAnalyse('preg_match : ' . preg_last_error_msg());
        }

        return $r === 1;
    }

    /**
     * Premier résultat : les groupes capturés (indice 0 = groupe 1), ou null s'il n'y a aucune correspondance.
     *
     * @return ?list<string>
     */
    public static function groupes(string $motif, string $sujet): ?array
    {
        $m = [];
        $r = preg_match($motif, $sujet, $m);
        if ($r === false) {
            throw new EchecAnalyse('preg_match : ' . preg_last_error_msg());
        }
        if ($r !== 1) {
            return null;
        }
        array_shift($m);

        return array_map('strval', array_values($m));
    }

    /**
     * Toutes les occurrences du groupe 1.
     *
     * @return list<string>
     */
    public static function tousLesGroupes1(string $motif, string $sujet): array
    {
        $m = [];
        $r = preg_match_all($motif, $sujet, $m);
        if ($r === false) {
            throw new EchecAnalyse('preg_match_all : ' . preg_last_error_msg());
        }

        return array_map('strval', $m[1] ?? []);
    }
}
