<?php

namespace App\Envoi\ListesNoires;

/**
 * Spamhaus refuse les résolveurs publics : leur réponse n'est pas un « vert ».
 * Fiable = adresse locale (boucle, privée, lien local) et absente de la liste
 * des résolveurs publics connus. Tout le reste (y compris vide/illisible) = public.
 * 127.0.0.11 (DNS embarqué de Docker) relaie souvent vers un résolveur public : non fiable.
 */
final class DetecteurResolveurPublic
{
    private const PUBLICS = ['8.8.8.8', '8.8.4.4', '1.1.1.1', '1.0.0.1', '9.9.9.9', '149.112.112.112', '208.67.222.222', '208.67.220.220', '94.140.14.14', '2001:4860:4860::8888', '2606:4700:4700::1111'];

    private const RELAIS_DOCKER = '127.0.0.11';

    /** Fiable = au moins un serveur de noms et AUCUN public/relayé (un seul suffit à fausser la réponse). */
    /** @param  list<string>  $adresses */
    public static function estFiable(array $adresses): bool
    {
        if ($adresses === []) {
            return false;
        }
        foreach ($adresses as $a) {
            if (self::estPublic((string) $a)) {
                return false;
            }
        }

        return true;
    }

    public static function estPublic(string $adresse): bool
    {
        $a = trim($adresse);
        if (filter_var($a, FILTER_VALIDATE_IP) === false || in_array($a, self::PUBLICS, true) || $a === self::RELAIS_DOCKER) {
            return true;
        }
        if ($a === '::1' || str_starts_with(strtolower($a), 'fe80:') || str_starts_with(strtolower($a), 'fc') || str_starts_with(strtolower($a), 'fd')) {
            return false;
        }
        if (str_contains($a, ':')) {
            return true;
        }
        $o = array_map('intval', explode('.', $a));

        $local = $o[0] === 127 || $o[0] === 10 || ($o[0] === 172 && $o[1] >= 16 && $o[1] <= 31)
            || ($o[0] === 192 && $o[1] === 168) || ($o[0] === 169 && $o[1] === 254);

        return ! $local;
    }
}
