<?php

namespace App\Envoi\Dns;

/** Résultat d'un point : `ok`, `avert` (non bloquant) ou `ko` (bloquant). */
final class PointControle
{
    public function __construct(
        public readonly string $code,
        public readonly string $statut,
    ) {}
}
