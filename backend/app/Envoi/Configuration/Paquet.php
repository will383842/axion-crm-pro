<?php

namespace App\Envoi\Configuration;

/**
 * Paquet de configuration d'UN espace : contenu canonique (trié) et empreinte SHA-256 déterministe.
 * Le paquet remis au serveur ne sort que de Paquet::valide().
 */
final class Paquet
{
    /** @param array<string, mixed> $contenu */
    private function __construct(public readonly array $contenu) {}

    /** Paquet généré depuis la base : à faire passer par valide() avant toute remise au serveur. */
    public static function pourEspace(string $workspaceId, SourceConfiguration $source): self
    {
        $domaines = array_map(fn (DomaineConfig $d): array => $d->enTableau(), $source->domaines());
        usort($domaines, fn (array $a, array $b): int => strcmp((string) $a['domaine'], (string) $b['domaine']));

        return new self(['version' => 1, 'espace' => $workspaceId, 'domaines' => $domaines]);
    }

    /**
     * Seule porte de sortie d'un paquet transmissible.
     *
     * @param  array<string, mixed>  $brut
     *
     * @throws PaquetInvalide
     */
    public static function valide(string $workspaceId, array $brut): self
    {
        ValidateurPaquet::verifier($workspaceId, $brut);

        return new self($brut);
    }

    /**
     * Empreinte d'un contenu brut, même invalide : ne lève jamais (UTF-8 invalide substitué,
     * structure trop profonde ramenée à une empreinte fixe, sans parcours récursif).
     *
     * @param  array<mixed>  $brut
     */
    public static function empreinteBrute(array $brut): string
    {
        if (! ValidateurPaquet::dansLesBornes($brut)) {
            return hash('sha256', 'structure-hors-bornes');
        }
        $json = json_encode(self::trier($brut), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return hash('sha256', $json === false ? 'illisible' : $json);
    }

    public function json(): string
    {
        return json_encode(self::trier($this->contenu), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function empreinte(): string
    {
        return self::empreinteBrute($this->contenu);
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
