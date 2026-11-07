<?php

namespace App\Envoi\Smtp;

/**
 * Garde SSRF des boîtes SMTP : ports en liste blanche, hôtes publics seulement,
 * adresse résolue CONTRÔLÉE puis ÉPINGLÉE au moment de la connexion (anti rebinding).
 *
 * Dérogation de TEST : `envoi.smtp_boucle_tests` autorise 127.0.0.1 (faux serveur SMTP
 * local), et elle n'est honorée QUE sous APP_ENV=testing — une valeur posée par erreur
 * en production reste sans effet.
 */
final class GardeHoteSmtp
{
    /** @var list<int> */
    public const PORTS_AUTORISES = [25, 465, 587, 2525];

    public const HOTE_BOUCLE_TESTS = '127.0.0.1';

    /** Plages non publiques (v4 puis v6). */
    private const PLAGES_INTERDITES = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
        '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', 'fc00::/7', 'fe80::/10', 'ff00::/8', '2001:db8::/32', '100::/64',
    ];

    public static function boucleTolereeEnTest(): bool
    {
        return (bool) config('envoi.smtp_boucle_tests', false) && app()->environment('testing');
    }

    private static function estBoucleDeTest(string $hote): bool
    {
        return self::boucleTolereeEnTest() && $hote === self::HOTE_BOUCLE_TESTS;
    }

    /**
     * Contrôle de création ET de connexion : mode de chiffrement, port, hôte (et adresses résolues).
     * Rend l'adresse IP contrôlée, à contacter à la place du nom.
     */
    public static function controlerBoite(string $hote, int $port, string $chiffrement, ResolveurHote $resolveur): string
    {
        if (! in_array($chiffrement, ['tls', 'starttls', 'aucun'], true)) {
            throw EnvoiSmtpRefuse::motif('mode de chiffrement inconnu.');
        }
        if ($chiffrement === 'aucun' && ! self::estBoucleDeTest($hote)) {
            throw EnvoiSmtpRefuse::motif('le mode sans chiffrement n\'existe pas : jamais d\'identifiants en clair.');
        }
        if (! self::estBoucleDeTest($hote) && ! in_array($port, self::PORTS_AUTORISES, true)) {
            throw EnvoiSmtpRefuse::motif('port hors liste blanche (' . implode(', ', self::PORTS_AUTORISES) . ').');
        }

        return self::adresseSure($hote, $resolveur);
    }

    /**
     * Résout l'hôte, exige que TOUTES ses adresses soient publiques et rend celle à utiliser.
     * À rappeler à chaque connexion : c'est cette adresse, et non le nom, qui est contactée.
     */
    public static function adresseSure(string $hote, ResolveurHote $resolveur): string
    {
        if (self::estBoucleDeTest($hote)) {
            return $hote;
        }
        $nom = strtolower(trim($hote, "[] \t\n\r\0\x0B."));
        if ($nom === '' || preg_match('/[^a-z0-9.:_-]/', $nom) === 1) {
            throw EnvoiSmtpRefuse::motif('nom d\'hôte invalide.');
        }
        if ($nom === 'localhost' || $nom === 'metadata.google.internal'
            || str_ends_with($nom, '.localhost') || str_ends_with($nom, '.internal') || str_ends_with($nom, '.local')) {
            throw EnvoiSmtpRefuse::motif('hôte interne refusé.');
        }
        try {
            $adresses = filter_var($nom, FILTER_VALIDATE_IP) !== false ? [$nom] : $resolveur->resoudre($nom);
        } catch (\Throwable) {
            $adresses = [];
        }
        if ($adresses === []) {
            throw EnvoiSmtpRefuse::motif('hôte non résolu.');
        }
        foreach ($adresses as $ip) {
            if (! self::estPublique($ip)) {
                throw EnvoiSmtpRefuse::motif('l\'hôte pointe vers une adresse non publique.');
            }
        }

        return $adresses[0];
    }

    public static function estPublique(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        if (strlen($bin) === 16) {
            // IPv4 encapsulée (::ffff:a.b.c.d ; 64:ff9b::a.b.c.d) : on contrôle l'adresse v4 portée.
            if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff") || str_starts_with($bin, "\x00\x64\xff\x9b" . str_repeat("\0", 8))) {
                return self::estPublique((string) inet_ntop(substr($bin, 12)));
            }
        }
        foreach (self::PLAGES_INTERDITES as $plage) {
            if (self::dansPlage($bin, $plage)) {
                return false;
            }
        }

        return true;
    }

    private static function dansPlage(string $bin, string $plage): bool
    {
        [$base, $bits] = explode('/', $plage);
        $baseBin = (string) inet_pton($base);
        if (strlen($baseBin) !== strlen($bin)) {
            return false;
        }
        $octets = intdiv((int) $bits, 8);
        $reste = (int) $bits % 8;
        if (substr($bin, 0, $octets) !== substr($baseBin, 0, $octets)) {
            return false;
        }
        if ($reste === 0) {
            return true;
        }
        $masque = (0xFF << (8 - $reste)) & 0xFF;

        return (ord($bin[$octets]) & $masque) === (ord($baseBin[$octets]) & $masque);
    }
}
