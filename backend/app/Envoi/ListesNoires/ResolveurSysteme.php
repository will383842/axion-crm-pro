<?php

namespace App\Envoi\ListesNoires;

/**
 * Implémentation réelle (dns_get_record). Non utilisée en test.
 * `dns_get_record()` passe par le résolveur du SYSTÈME : on lit donc ses `nameserver` dans
 * resolv.conf (jamais une valeur de configuration qui ne dirait pas ce qui est réellement interrogé).
 */
final class ResolveurSysteme implements ResolveurDns
{
    public function __construct(private readonly string $fichierResolv = '/etc/resolv.conf') {}

    public function adresses(): array
    {
        $lignes = @file($this->fichierResolv, FILE_IGNORE_NEW_LINES);
        if ($lignes === false) {
            return [];
        }
        $out = [];
        foreach ($lignes as $ligne) {
            if (preg_match('/^\s*nameserver\s+(\S+)/i', $ligne, $m) === 1 && filter_var($m[1], FILTER_VALIDATE_IP) !== false) {
                $out[] = $m[1];
            }
        }

        return $out;
    }

    public function interroger(string $nom): array
    {
        $r = @dns_get_record($nom . '.', DNS_A);
        if ($r === false) {
            throw new \RuntimeException('Échec DNS pour ' . $nom);
        }

        return array_map(static fn (array $e): string => (string) $e['ip'], $r);
    }
}
