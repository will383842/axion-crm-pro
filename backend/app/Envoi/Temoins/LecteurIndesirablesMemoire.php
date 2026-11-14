<?php

namespace App\Envoi\Temoins;

use DateTimeImmutable;
use RuntimeException;

/** Lecteur de test : aucune connexion réseau. Compte ses appels (preuve « interrupteur éteint = aucune connexion »). */
final class LecteurIndesirablesMemoire implements LecteurIndesirables
{
    public int $appels = 0;

    /** @var array<string, true> */
    private array $enPanne = [];

    /** @param array<string, list<string>> $parFournisseur Message-ID présents en indésirables, par fournisseur */
    public function __construct(private array $parFournisseur = []) {}

    public function mettreEnPanne(string $fournisseur): void
    {
        $this->enPanne[$fournisseur] = true;
    }

    public function messageIdsEnIndesirables(AccesTemoin $acces, DateTimeImmutable $depuis): array
    {
        $this->appels++;
        if (isset($this->enPanne[$acces->fournisseur])) {
            throw new RuntimeException('Connexion impossible.');
        }

        return $this->parFournisseur[$acces->fournisseur] ?? [];
    }
}
