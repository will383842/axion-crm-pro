<?php

namespace App\Envoi\Infrastructure;

use InvalidArgumentException;

/**
 * Nom de domaine d'envoi saisi par l'utilisateur : normalisé (minuscules, punycode) puis borné.
 * Refus : adresse IP, domaine interne ou réservé, étiquette ou nom trop longs. Les messages ne citent jamais la saisie.
 */
final class NomDomaineEnvoi
{
    public const LONGUEUR_MAX = 253;

    /** Suffixes qui ne désignent jamais un domaine public (RFC 2606, 6761, 6762, usages internes courants). */
    private const RESERVES = ['localhost', 'local', 'internal', 'invalid', 'test', 'example', 'lan', 'intranet', 'corp', 'home', 'home.arpa', 'arpa', 'example.com', 'example.net', 'example.org', 'onion', 'alt', 'localdomain'];

    /** @throws InvalidArgumentException */
    public static function normaliser(string $saisie): string
    {
        $nom = rtrim(mb_strtolower(trim($saisie)), '.');
        if ($nom === '' || mb_strlen($nom) > self::LONGUEUR_MAX * 2) {
            throw new InvalidArgumentException('nom de domaine invalide');
        }
        if (preg_match('/[^\x00-\x7F]/', $nom) === 1) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($nom, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;
            if ($ascii === false) {
                throw new InvalidArgumentException('nom de domaine invalide');
            }
            $nom = $ascii;
        }
        if (strlen($nom) > self::LONGUEUR_MAX) {
            throw new InvalidArgumentException('nom de domaine trop long');
        }
        if (filter_var($nom, FILTER_VALIDATE_IP) !== false || filter_var(trim($nom, '[]'), FILTER_VALIDATE_IP) !== false || preg_match('/^[0-9.]+$/', $nom) === 1) {
            throw new InvalidArgumentException('une adresse IP n\'est pas un domaine');
        }
        if (! str_contains($nom, '.') || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/D', $nom) !== 1) {
            throw new InvalidArgumentException('nom de domaine invalide');
        }
        foreach (self::RESERVES as $suffixe) {
            if ($nom === $suffixe || str_ends_with($nom, '.' . $suffixe)) {
                throw new InvalidArgumentException('domaine interne ou réservé');
            }
        }

        return $nom;
    }
}
