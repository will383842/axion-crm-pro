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
            $r = Http::timeout(5)->withoutRedirecting()->withOptions([
                'curl' => [CURLOPT_RESOLVE => [$hote . ':443:' . $adresses[0]], CURLOPT_MAXFILESIZE => 65536],
            ])->get('https://' . $hote . '/.well-known/mta-sts.txt');

            return $r->successful() ? $r->body() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function adressePublique(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $n = ip2long($ip);

            return $n === false || ($n & 0xFFC00000) !== 0x64400000; // 100.64.0.0/10
        }

        return true;
    }

    /** @return list<array<string, mixed>> */
    private function interroger(string $nom, int $type): array
    {
        $r = $this->interrogeur !== null ? ($this->interrogeur)($nom, $type) : @dns_get_record($nom, $type);

        return is_array($r) ? array_values($r) : [];
    }
}
