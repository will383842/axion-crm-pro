<?php

declare(strict_types=1);

namespace Passerelle\Sante;

/** Mesureur de test : rend les valeurs données, ou lève l'exception donnée. */
final class MesureurFixe implements Mesureur
{
    /** @param array<string, int|float|bool> $valeurs */
    public function __construct(private readonly array $valeurs = [], private readonly ?\Throwable $exception = null) {}

    public function mesurer(): array
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->valeurs;
    }
}
