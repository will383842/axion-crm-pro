<?php

namespace App\Envoi\ListesNoires;

/** Implémentation réelle (dns_get_record). Non utilisée en test. */
final class ResolveurSysteme implements ResolveurDns
{
    public function __construct(private readonly string $adresse) {}

    public function adresse(): string
    {
        return $this->adresse;
    }

    public function interroger(string $nom): array
    {
        $r = @dns_get_record($nom . '.', DNS_A);
        if ($r === false) {
            throw new \RuntimeException('Échec DNS pour ' . $nom);
        }

        return array_values(array_map(static fn (array $e): string => (string) $e['ip'], $r));
    }
}
