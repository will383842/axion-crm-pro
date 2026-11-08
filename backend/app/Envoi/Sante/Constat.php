<?php

namespace App\Envoi\Sante;

final class Constat
{
    public const ATTENTION = 'attention';

    public const CRITIQUE = 'critique';

    public function __construct(
        public readonly string $code,
        public readonly string $gravite,
        public readonly string $detail,
    ) {}
}
