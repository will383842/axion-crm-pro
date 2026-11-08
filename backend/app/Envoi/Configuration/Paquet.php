<?php

namespace App\Envoi\Configuration;

/** Paquet de configuration : contenu canonique (trié) et empreinte SHA-256 déterministe. */
final class Paquet
{
    /** @param array<string, mixed> $contenu */
    private function __construct(public readonly array $contenu) {}

    public static function depuis(SourceConfiguration $source): self
    {
        $domaines = array_map(fn (DomaineConfig $d): array => $d->enTableau(), $source->domaines());
        usort($domaines, fn (array $a, array $b): int => strcmp((string) $a['domaine'], (string) $b['domaine']));

        return new self(['version' => 1, 'domaines' => $domaines]);
    }

    /** @param array<string, mixed> $brut */
    public static function deBrut(array $brut): self
    {
        return new self($brut);
    }

    public function json(): string
    {
        return json_encode(self::trier($this->contenu), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function empreinte(): string
    {
        return hash('sha256', $this->json());
    }

    private static function trier(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        $v = array_map(self::trier(...), $v);
        if (! array_is_list($v)) {
            ksort($v);
        }

        return $v;
    }
}
