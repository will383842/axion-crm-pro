<?php

namespace App\Envoi\Serveur\Kumo;

use RuntimeException;

/**
 * Registre en mémoire : banc et tests UNIQUEMENT. Ne survit pas au processus (voir la dette K9-registre-durable) :
 * en production, un `a_verifier` perdu ferait RENVOYER un message déjà parti. Il refuse donc de se construire (et de
 * servir) hors environnement `testing`, que le verrou d'envoi soit ouvert ou non.
 */
final class RegistreMemoire implements RegistreInjections
{
    /** @var array<string, array<string, array{id: string, etat: string, reporte_au: ?int, instant: int, jeton: string, seq?: int}>> */
    private array $lignes = [];

    /** @var array<string, bool> */
    private array $pause = [];

    /** @var array<string, list<int>> */
    private array $cinqCents = [];

    /** @var array<string, int> */
    private array $sequence = [];

    public function __construct()
    {
        self::garde();
    }

    private static function garde(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('RegistreMemoire : réservé à l’environnement de test (registre durable requis ailleurs).');
        }
    }

    public function lire(string $workspaceId, string $cle): ?array
    {
        self::garde();

        return $this->lignes[$workspaceId][$cle] ?? null;
    }

    public function ecrire(string $workspaceId, string $cle, array $ligne): void
    {
        self::garde();
        $this->lignes[$workspaceId][$cle] = $ligne;
    }

    public function ecrireSi(string $workspaceId, string $cle, ?string $etatAttendu, ?int $instantAttendu, array $ligne): bool
    {
        self::garde();
        $courante = $this->lignes[$workspaceId][$cle] ?? null;
        if (($courante['etat'] ?? null) !== $etatAttendu || ($courante['instant'] ?? null) !== $instantAttendu) {
            return false;
        }
        $this->lignes[$workspaceId][$cle] = $ligne;

        return true;
    }

    public function tout(string $workspaceId): array
    {
        self::garde();

        return $this->lignes[$workspaceId] ?? [];
    }

    public function enPause(string $workspaceId): bool
    {
        self::garde();

        return $this->pause[$workspaceId] ?? false;
    }

    public function fixerPause(string $workspaceId, bool $pause): void
    {
        self::garde();
        $this->pause[$workspaceId] = $pause;
    }

    public function noter5xx(string $workspaceId, int $instant): void
    {
        self::garde();
        $this->cinqCents[$workspaceId][] = $instant;
    }

    public function compter5xx(string $workspaceId, int $depuis): int
    {
        self::garde();

        return count(array_filter($this->cinqCents[$workspaceId] ?? [], fn (int $i): bool => $i >= $depuis));
    }

    public function reinitialiser5xx(string $workspaceId): void
    {
        self::garde();
        $this->cinqCents[$workspaceId] = [];
    }

    public function prochaineSequence(string $workspaceId): int
    {
        self::garde();

        return $this->sequence[$workspaceId] = ($this->sequence[$workspaceId] ?? 0) + 1;
    }
}
