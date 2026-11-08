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
     * IPv4 forcée, AUCUNE redirection, vérification TLS toujours active.
     * ÉPINGLAGE STRICT : AUCUNE autorité publique du système n'est acceptée (CURLOPT_CAPATH = dossier vide ; l'AC
     * privée est la seule dans « verify »). Si l'empreinte de clé publique est configurée, elle est imposée
     * (CURLOPT_PINNEDPUBLICKEY). Dès que `reel_actif` est vrai, l'AC privée ET le TLS mutuel (certificat + clé
     * client, existants et lisibles) sont OBLIGATOIRES : sinon REFUS, jamais de repli.
     *
     * @param  array{hote: string, port: int, ip: string}  $cible
     * @return array<string, mixed>
     */
    public static function optionsHttp(array $cible): array
    {
        $curl = [
            CURLOPT_RESOLVE => [$cible['hote'] . ':' . $cible['port'] . ':' . $cible['ip']],
            CURLOPT_CAPATH => self::dossierAcVide(),
        ];
        $options = ['allow_redirects' => false, 'force_ip_resolve' => 'v4', 'verify' => true];

        $empreinte = trim((string) config('envoi.serveur.empreinte_cle', ''));
        if ($empreinte !== '') {
            if (preg_match('#^sha256//[A-Za-z0-9+/]{43}=(;sha256//[A-Za-z0-9+/]{43}=)*$#', $empreinte) !== 1) {
                throw ServeurEnvoiRefuse::motif('empreinte de clé publique invalide.');
            }
            $curl[CURLOPT_PINNEDPUBLICKEY] = $empreinte;
        }

        $ac = (string) config('envoi.serveur.ca_path', '');
        $cert = (string) config('envoi.serveur.client_cert_path', '');
        $cle = (string) config('envoi.serveur.client_key_path', '');
        if (config('envoi.reel_actif') === true && ($ac === '' || $cert === '' || $cle === '')) {
            throw ServeurEnvoiRefuse::motif('TLS mutuel et AC privée obligatoires (chemins non configurés).');
        }
        if ($ac !== '') {
            if (! is_file($ac) || ! is_readable($ac)) {
                throw ServeurEnvoiRefuse::motif('autorité de certification introuvable.');
            }
            $options['verify'] = $ac;
        }
        if ($cert !== '' || $cle !== '') {
            if (! is_file($cert) || ! is_readable($cert) || ! is_file($cle) || ! is_readable($cle)) {
                throw ServeurEnvoiRefuse::motif('certificat client incomplet ou introuvable.');
            }
            $options['cert'] = $cert;
            $options['ssl_key'] = $cle;
        }
        $options['curl'] = $curl;

        return $options;
    }

    /** Dossier d'AC VIDE, propre à l'application (jamais le /tmp partagé) ; non vide = refus (anti-dépôt d'une AC). */
    private static function dossierAcVide(): string
    {
        $dossier = storage_path('framework/envoi-ca-vide');
        if (! is_dir($dossier) && ! @mkdir($dossier, 0700, true) && ! is_dir($dossier)) {
            throw ServeurEnvoiRefuse::motif('dossier d’autorités vide impossible à créer.');
        }
        if (array_diff(scandir($dossier) ?: ['x'], ['.', '..']) !== []) {
            throw ServeurEnvoiRefuse::motif('dossier d’autorités vide non vide.');
        }

        return $dossier;
    }
}
