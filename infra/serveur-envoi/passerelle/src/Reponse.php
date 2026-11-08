<?php

declare(strict_types=1);

namespace Passerelle;

final class Reponse
{
    /** @param array<string,string> $entetes */
    public function __construct(public readonly int $statut, public readonly string $corps, public readonly array $entetes = []) {}

    /** @param array<string,mixed> $donnees */
    public static function json(int $statut, array $donnees): self
    {
        return new self($statut, json_encode($donnees, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), ['Content-Type' => 'application/json']);
    }

    public static function erreur(int $statut, string $code): self
    {
        return self::json($statut, ['erreur' => $code]);
    }
}
