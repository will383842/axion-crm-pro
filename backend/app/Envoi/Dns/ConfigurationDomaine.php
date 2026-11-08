<?php

namespace App\Envoi\Dns;

use InvalidArgumentException;

/** Paramètres DNS d'un domaine d'envoi. Viennent de l'environnement, jamais du dépôt. */
final class ConfigurationDomaine
{
    public readonly string $domaine;

    public readonly string $helo;

    public function __construct(
        string $domaine,
        public readonly string $selecteur,
        string $helo,
        public readonly string $ip,
    ) {
        $this->domaine = self::enAscii($domaine);
        $this->helo = self::enAscii($helo);
        foreach ([$this->domaine, $this->helo] as $nom) {
            if (filter_var($nom, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false || ! str_contains($nom, '.')) {
                throw new InvalidArgumentException('nom de domaine invalide');
            }
        }
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/i', $selecteur) !== 1) {
            throw new InvalidArgumentException('sélecteur DKIM invalide');
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('adresse IP invalide');
        }
    }

    /** Minuscules + punycode (IDN) : le DNS ne connaît que l'ASCII. */
    private static function enAscii(string $nom): string
    {
        $nom = mb_strtolower(trim($nom));
        if (preg_match('/[^\x00-\x7F]/', $nom) === 1 && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($nom, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            return $ascii === false ? $nom : $ascii;
        }

        return $nom;
    }
}
