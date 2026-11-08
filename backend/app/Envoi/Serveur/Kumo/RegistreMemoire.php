<?php

namespace App\Envoi\Serveur\Kumo;

/** Registre en mémoire : banc et tests. Ne survit pas au processus (voir la dette du registre durable). */
final class RegistreMemoire implements RegistreInjections
{
    /** @var array<string, array{id: string, etat: string, reporte_au: ?int, instant: int}> */
    private array $lignes = [];

    public function lire(string $cle): ?array
    {
        return $this->lignes[$cle] ?? null;
    }

    public function ecrire(string $cle, array $ligne): void
    {
        $this->lignes[$cle] = $ligne;
    }

    public function tout(): array
    {
        return $this->lignes;
    }
}
