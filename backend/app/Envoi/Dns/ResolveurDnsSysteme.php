<?php

namespace App\Envoi\Dns;

use Illuminate\Support\Facades\Http;
use Throwable;

/** Résolveur réel (lecture seule). Jamais utilisé par les tests. */
final class ResolveurDnsSysteme implements ResolveurDns
{
    /** @var (\Closure(string, int): mixed)|null */
    private readonly ?\Closure $interrogeur;

    /** @param (\Closure(string, int): mixed)|null $interroger Source DNS injectable (tests) ; défaut : dns_get_record. */
    public function __construct(?\Closure $interroger = null)
    {
        $this->interrogeur = $interroger;
    }

    public function txt(string $nom): array
    {
        $sortie = [];
        foreach ($this->interroger($nom, DNS_TXT) as $e) {
            $sortie[] = (string) ($e['txt'] ?? '');
        }

        return $sortie;
    }

    public function a(string $nom): array
    {
        $sortie = [];
        foreach ($this->interroger($nom, DNS_A) as $e) {
            $sortie[] = (string) ($e['ip'] ?? '');
        }

        return array_values(array_filter($sortie));
    }

    public function aaaa(string $nom): array
    {
        $sortie = [];
        foreach ($this->interroger($nom, DNS_AAAA) as $e) {
            $sortie[] = (string) ($e['ipv6'] ?? '');
        }

        return array_values(array_filter($sortie));
    }

    public function ptr(string $ip): array
    {
        $inverse = self::nomInverse($ip);
        if ($inverse === null) {
            return [];
        }
        $sortie = [];
        foreach ($this->interroger($inverse, DNS_PTR) as $e) {
            $sortie[] = rtrim(strtolower((string) ($e['target'] ?? '')), '.');
        }

        return array_values(array_filter($sortie));
    }

    /** Nom de zone inverse (in-addr.arpa / ip6.arpa) d'une IP, ou null si ce n'est pas une IP. */
    public static function nomInverse(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return implode('.', array_reverse(explode('.', $ip))) . '.in-addr.arpa';
        }
        $bin = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? inet_pton($ip) : false;
        if ($bin === false) {
            return null;
        }

        return implode('.', array_reverse(str_split(bin2hex($bin)))) . '.ip6.arpa';
    }

    /**
     * Politique MTA-STS (RFC 8461). Anti-SSRF : l'hôte est d'abord résolu ; si la liste est vide ou si UNE adresse
     * est privée, réservée ou partagée (100.64.0.0/10), aucune requête HTTP n'est émise. La connexion est ensuite
     * épinglée sur l'adresse contrôlée (pas de rebond DNS), sans redirection, corps limité à 64 Ko.
     */
    public function politiqueMtaSts(string $domaine): ?string
    {
        try {
            $hote = 'mta-sts.' . $domaine;
            $adresses = array_merge($this->a($hote), $this->aaaa($hote));
            if ($adresses === []) {
                return null;
            }
            foreach ($adresses as $ip) {
                if (! self::adressePublique($ip)) {
                    return null;
                }
            }
            $ip = $adresses[0];
            $epingle = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
            $r = Http::timeout(5)->withoutRedirecting()->withOptions([
                'curl' => [
                    CURLOPT_RESOLVE => [$hote . ':443:' . $epingle],
                    CURLOPT_MAXFILESIZE => 65536,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                ],
            ])->get('https://' . $hote . '/.well-known/mta-sts.txt');

            return $r->successful() ? $r->body() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Liste d'AUTORISATION : vrai seulement pour une adresse unicast publique (IPv4 ou IPv6). */
    public static function adressePublique(string $ip): bool
    {
        $bin = filter_var($ip, FILTER_VALIDATE_IP) !== false ? inet_pton($ip) : false;
        if ($bin === false) {
            return false;
        }
        if (strlen($bin) === 16) {
            // IPv4 mappée (::ffff:a.b.c.d) ou compatible (::a.b.c.d) : contrôler l'IPv4 embarquée.
            if (self::dansPlage($bin, str_repeat("\0", 10) . "\xff\xff", 96) || self::dansPlage($bin, str_repeat("\0", 12), 96)) {
                return self::adressePublique(inet_ntop(substr($bin, 12)) ?: '');
            }
            if ((ord($bin[0]) & 0xE0) !== 0x20) { // hors 2000::/3
                return false;
            }
            $refusees = [
                ["\x00\x64\xff\x9b" . str_repeat("\0", 12), 96], // 64:ff9b::/96 (NAT64)
                ["\x00\x64\xff\x9b\x00\x01" . str_repeat("\0", 10), 48], // 64:ff9b:1::/48
                ["\x20\x02" . str_repeat("\0", 14), 16], // 2002::/16 (6to4)
                ["\x20\x01" . str_repeat("\0", 14), 32], // 2001::/32 (Teredo)
                ["\x20\x01\x0d\xb8" . str_repeat("\0", 12), 32], // 2001:db8::/32
                ["\x20\x01\x00\x10" . str_repeat("\0", 12), 28], // 2001:10::/28
            ];
            foreach ($refusees as [$prefixe, $bits]) {
                if (self::dansPlage($bin, $prefixe, $bits)) {
                    return false;
                }
            }

            return true;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        foreach ([['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15], ['224.0.0.0', 3]] as [$reseau, $bits]) {
            if (self::dansPlage($bin, (string) inet_pton($reseau), $bits)) {
                return false;
            }
        }

        return true;
    }

    /** Vrai si les $bits premiers bits de $bin et $prefixeBin (même longueur) sont égaux. */
    private static function dansPlage(string $bin, string $prefixeBin, int $bits): bool
    {
        if (strlen($bin) !== strlen($prefixeBin)) {
            return false;
        }
        $octets = intdiv($bits, 8);
        if (substr($bin, 0, $octets) !== substr($prefixeBin, 0, $octets)) {
            return false;
        }
        $reste = $bits % 8;
        if ($reste === 0) {
            return true;
        }
        $masque = (0xFF << (8 - $reste)) & 0xFF;

        return (ord($bin[$octets]) & $masque) === (ord($prefixeBin[$octets]) & $masque);
    }

    /** @return list<array<string, mixed>> */
    private function interroger(string $nom, int $type): array
    {
        $r = $this->interrogeur !== null ? ($this->interrogeur)($nom, $type) : @dns_get_record($nom, $type);

        return is_array($r) ? array_values($r) : [];
    }
}
