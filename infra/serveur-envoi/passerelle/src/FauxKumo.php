<?php

declare(strict_types=1);

namespace Passerelle;

final class FauxKumo implements Kumo
{
    /** @var list<array{workspace_id:string,operation:string,params:array<string,mixed>}> */
    public array $appels = [];

    public bool $echec = false;

    /** Code SMTP (400 à 599) du refus que le moteur rend à `injecter` ; null : le moteur accepte. */
    public ?int $refus = null;

    /** Crochet de test, appelé PENDANT l'appel (simule une requête concurrente ou une panne). */
    public ?\Closure $pendant = null;

    public function delaiMaximal(): int
    {
        return self::DELAI_MAX_SECONDES;
    }

    public function executer(string $workspaceId, string $operation, array $params): array
    {
        $this->appels[] = ['workspace_id' => $workspaceId, 'operation' => $operation, 'params' => $params];
        if ($this->pendant !== null) {
            ($this->pendant)();
        }
        if ($this->refus !== null && $operation === 'injecter') {
            throw new Injection\RefusKumo($this->refus);
        }
        if ($this->echec) {
            array_pop($this->appels);
            throw new \RuntimeException('faux_echec');
        }

        return ['accepte' => true, 'appel' => count($this->appels)];
    }
}
