<?php

namespace App\Crm\Opposition;

/**
 * X7 / M5 — les FORMES sous lesquelles une même adresse peut se présenter, pour que l'opposition la retrouve.
 *
 * Une opposition posée sur `nom@exemple.test` doit aussi arrêter `Nom@Exemple.test.` (point final), `nom+x@exemple.test`
 * (alias), `nom@exemple.test` suivi d'un espace insécable, ou le même domaine accentué (IDN) face à sa forme `xn--`.
 *
 * LECTURE SEULE, sans base ni réseau. On ne touche PAS à `ListeSuppression::empreinte` (source unique, partagée avec le site) :
 * l'appelant calcule l'empreinte de CHAQUE variante avec elle.
 *
 * Limite assumée : on ne peut pas énumérer des empreintes. Une opposition enregistrée sur `nom+x@…` ne couvre donc PAS
 * `nom@…`. On accepte de trop exclure, jamais de trop envoyer.
 */
final class FormesAdresse
{
    /** Espaces Unicode : blancs, insécable, fine insécable, chiffre, largeur nulle, joint, mot-lieur, BOM. */
    private const ESPACES = '/[\s\x{00A0}\x{2007}\x{202F}\x{200B}-\x{200D}\x{2060}\x{FEFF}]+/u';

    /** Plafond de formes : borne le nombre de paramètres SQL par lot (7 × 1000 lignes ≪ 65 535). */
    public const MAX = 7;

    /**
     * @return list<string> formes dédoublonnées, la forme d'origine (minuscule, rognée) en tête ; jamais vide
     */
    public static function variantes(string $brute): array
    {
        $origine = mb_strtolower(trim($brute));
        $formes = [$origine];

        $sans = preg_replace(self::ESPACES, '', $origine);
        $sans = is_string($sans) ? $sans : $origine;
        $formes[] = $sans;

        $arobase = strrpos($sans, '@');
        if ($arobase !== false && $arobase > 0) {
            $locale = substr($sans, 0, $arobase);
            $domaine = rtrim(substr($sans, $arobase + 1), '.');

            $domaines = [$domaine];
            if ($domaine !== '') {
                foreach ([IDNA_NONTRANSITIONAL_TO_ASCII => 'idn_to_ascii', IDNA_NONTRANSITIONAL_TO_UNICODE => 'idn_to_utf8'] as $option => $fonction) {
                    $converti = $fonction($domaine, $option, INTL_IDNA_VARIANT_UTS46);
                    if (is_string($converti) && $converti !== '') {
                        $domaines[] = $converti;
                    }
                }
            }

            $plus = strpos($locale, '+');
            $locales = $plus === false || $plus === 0 ? [$locale] : [$locale, substr($locale, 0, $plus)];

            foreach ($locales as $l) {
                foreach ($domaines as $dom) {
                    $formes[] = $l . '@' . $dom;
                }
            }
        }

        return array_slice(array_values(array_unique(array_filter($formes, static fn (string $f): bool => $f !== ''))), 0, self::MAX) ?: [$origine];
    }

    /**
     * Le domaine d'une adresse sous toutes ses formes (sans point final), pour le registre de domaines.
     *
     * @return list<string>
     */
    public static function domaines(string $brute): array
    {
        $liste = [];
        foreach (self::variantes($brute) as $forme) {
            $pos = strrpos($forme, '@');
            if ($pos !== false) {
                $liste[rtrim(substr($forme, $pos + 1), '.')] = true;
            }
        }
        unset($liste['']);

        return array_map('strval', array_keys($liste));
    }
}
