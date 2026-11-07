<?php

namespace App\Envoi\Serveur;

use App\Envoi\Smtp\GardeHoteSmtp;
use App\Envoi\Smtp\ResolveurHote;

/**
 * Garde SSRF du client du serveur d'envoi (modèle : GardeHoteSmtp). https obligatoire, hôte public
 * seulement, IPv4 forcée, adresse résolue ÉPINGLÉE (anti rebinding), aucune redirection suivie.
 */
final class GardeHoteServeur
{
    /** @var list<int> */
    public const PORTS_AUTORISES = [443, 8443];

    /** @return array{base: string, hote: string, port: int, ip: string} */
    public static function controler(string $url, ResolveurHote $resolveur): array
    {
        $p = parse_url(trim($url));
        if (! is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'https') {
            throw ServeurEnvoiRefuse::motif('https obligatoire.');
        }
        if (isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])
            || ! in_array($p['path'] ?? '', ['', '/'], true)) {
            throw ServeurEnvoiRefuse::motif('adresse de base invalide.');
        }
        $port = (int) ($p['port'] ?? 443);
        if (! in_array($port, self::PORTS_AUTORISES, true)) {
            throw ServeurEnvoiRefuse::motif('port hors liste blanche.');
        }
        $nom = strtolower(trim((string) ($p['host'] ?? ''), "[] \t\n\r\0\x0B."));
        if ($nom === '' || preg_match('/[^a-z0-9.-]/', $nom) === 1) {
            throw ServeurEnvoiRefuse::motif('nom d’hôte invalide.');
        }
        if ($nom === 'localhost' || str_ends_with($nom, '.localhost') || str_ends_with($nom, '.internal') || str_ends_with($nom, '.local')) {
            throw ServeurEnvoiRefuse::motif('hôte interne refusé.');
        }
        try {
            $adresses = filter_var($nom, FILTER_VALIDATE_IP) !== false ? [$nom] : $resolveur->resoudre($nom);
        } catch (\Throwable) {
            $adresses = [];
        }
        if ($adresses === []) {
            throw ServeurEnvoiRefuse::motif('hôte non résolu.');
        }
        foreach ($adresses as $ip) {
            if (! GardeHoteSmtp::estPublique($ip)) {
                throw ServeurEnvoiRefuse::motif('l’hôte pointe vers une adresse non publique.');
            }
        }
        $v4 = array_values(array_filter($adresses, fn (string $ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false));
        if ($v4 === []) {
            throw ServeurEnvoiRefuse::motif('aucune adresse IPv4.');
        }

        return ['base' => 'https://' . $nom . ($port === 443 ? '' : ':' . $port), 'hote' => $nom, 'port' => $port, 'ip' => $v4[0]];
    }

    /**
     * Options Guzzle : adresse épinglée (CURLOPT_RESOLVE, SNI et vérification du certificat restent sur le nom),
     * IPv4 forcée, AUCUNE redirection, vérification TLS toujours active, TLS mutuel et AC privée par chemins
     * d'environnement. Chemins configurés mais absents = refus (jamais de repli sans vérification).
     *
     * @param  array{hote: string, port: int, ip: string}  $cible
     * @return array<string, mixed>
     */
    public static function optionsHttp(array $cible): array
    {
        $options = [
            'allow_redirects' => false,
            'force_ip_resolve' => 'v4',
            'verify' => true,
            'curl' => [CURLOPT_RESOLVE => [$cible['hote'] . ':' . $cible['port'] . ':' . $cible['ip']]],
        ];
        $ac = (string) config('envoi.serveur.ca_path', '');
        if ($ac !== '') {
            if (! is_file($ac)) {
                throw ServeurEnvoiRefuse::motif('autorité de certification introuvable.');
            }
            $options['verify'] = $ac;
        }
        $cert = (string) config('envoi.serveur.client_cert_path', '');
        $cle = (string) config('envoi.serveur.client_key_path', '');
        if ($cert !== '' || $cle !== '') {
            if (! is_file($cert) || ! is_file($cle)) {
                throw ServeurEnvoiRefuse::motif('certificat client incomplet ou introuvable.');
            }
            $options['cert'] = $cert;
            $options['ssl_key'] = $cle;
        }

        return $options;
    }
}
