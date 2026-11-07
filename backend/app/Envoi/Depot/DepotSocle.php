<?php

namespace App\Envoi\Depot;

use Closure;
use DateTimeImmutable;

/** État local du socle d'envoi, par workspace. Ajout seulement : rien ne s'efface. */
interface DepotSocle
{
    public function toutArreter(string $workspaceId): bool;

    public function modeTemoins(string $workspaceId): bool;

    public function estTemoin(string $workspaceId, string $adresse): bool;

    public function injectesLe(string $workspaceId, DateTimeImmutable $jour): int;

    /** Injections du jour, tous espaces confondus (plafond global). */
    public function injectesLeGlobal(DateTimeImmutable $jour): int;

    /** Cette clé d'idempotence a-t-elle déjà été comptée pour cet espace ? */
    public function dejaInjecte(string $workspaceId, string $cleIdempotence): bool;

    /**
     * Exécute $f en exclusion mutuelle pour ce jour (transaction + verrou
     * consultatif) : comptage, appel du serveur et note de l'injection y sont
     * atomiques, donc le plafond dur ne peut jamais être dépassé en concurrence.
     *
     * @template T
     *
     * @param  Closure(): T  $f
     * @return T
     */
    public function sousVerrouInjection(DateTimeImmutable $jour, Closure $f): mixed;

    /** À appeler SOUS le verrou. Une même clé ne compte qu'une fois. */
    public function noterInjection(string $workspaceId, DateTimeImmutable $jour, string $cleIdempotence): void;

    /** Commande locale uniquement (artisan). */
    public function eteindreTemoins(string $workspaceId, string $auteur): void;

    /** Commande locale : arme « Tout arrêter » (journalisé). */
    public function armerToutArreter(string $workspaceId, string $auteur): void;

    /** @param array<string, mixed> $details */
    public function journaliser(string $workspaceId, string $commande, string $auteur, array $details = [], ?DateTimeImmutable $jour = null): void;
}
