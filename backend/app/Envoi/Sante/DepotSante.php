<?php

namespace App\Envoi\Sante;

use DateTimeImmutable;

/** Ajout seulement : un relevé = une ligne, une ouverture ou un rétablissement = une ligne. Rien ne s'efface. */
interface DepotSante
{
    /** @param array<string, mixed> $mesures */
    public function noterReleve(string $ws, string $source, DateTimeImmutable $quand, bool $ok, array $mesures): void;

    /** @return ?array{mesure_le: string, ok: bool, mesures: array<string, mixed>} */
    public function dernierReleve(string $ws, string $source): ?array;

    public function dernierBattementOk(string $ws, string $source): ?DateTimeImmutable;

    public function premierReleve(string $ws, string $source): ?DateTimeImmutable;

    /** @return array<string, array{gravite: string, detail: string, depuis: string}> constats ouverts, par code */
    public function constatsOuverts(string $ws, string $source): array;

    public function ouvrir(string $ws, string $source, string $code, string $gravite, string $detail, DateTimeImmutable $quand): void;

    public function retablir(string $ws, string $source, string $code, DateTimeImmutable $quand): void;

    /**
     * Dernier contrôle DNS (K12) de chaque domaine.
     *
     * @return list<array{domaine: string, pret: bool, pause: bool, controle_le: string}>
     */
    public function controlesDns(string $ws): array;

    /**
     * Dernier statut de liste noire (K13) de chaque ressource.
     *
     * @return list<array{type: string, ressource: string, statut: string}>
     */
    public function controlesListes(string $ws): array;

    /**
     * Serveurs d'envoi actifs (tous espaces) : [workspace_id, source].
     *
     * @return list<array{0: string, 1: string}>
     */
    public function serveursActifs(): array;
}
