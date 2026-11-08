<?php

namespace App\Envoi\Configuration;

final class VerdictConfiguration
{
    public function __construct(public readonly bool $accepte, public readonly ?string $motif = null) {}
}
