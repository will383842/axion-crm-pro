<?php

namespace App\Envoi\Serveur\Kumo;

/** Registre en mémoire : banc et tests. Ne survit pas au processus (voir la dette K9-registre-durable). */
final class RegistreMemoire implements RegistreInjections
{
    /** @var array<string, array{id: string, etat: string, reporte_au: ?int, instant: int, jeton: string}> */
    private array $lignes = [];

    private bool $pause = false;

    /** @var list<int> */
    private array $cinqCents = [];

    public function lire(string $cle): ?array
    {
        return $this->lignes[$cle] ?? null;
    }

    public function ecrire(string $cle, array $ligne): void
    {
        $this->lignes[$cle] = $ligne;
    }

    public function ecrireSi(string $cle, ?string $etatAttendu, array $ligne): bool
    {
        if (($this->lignes[$cle]['etat'] ?? null) !== $etatAttendu) {
            return false;
        }
        $this->lignes[$cle] = $ligne;

        return true;
    }

    public function tout(): array
    {
        return $this->lignes;
    }

    public function enPause(): bool
    {
        return $this->pause;
    }

    public function fixerPause(bool $pause): void
    {
        $this->pause = $pause;
    }

    public function noter5xx(int $instant): void
    {
        $this->cinqCents[] = $instant;
    }

    public function compter5xx(int $depuis): int
    {
        return count(array_filter($this->cinqCents, fn (int $i): bool => $i >= $depuis));
    }
}
