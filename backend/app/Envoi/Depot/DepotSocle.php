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

    /**
     * État EFFECTIF de la clé : `reservee` | `acceptee` | `a_verifier`, ou null (jamais réservée, ou réservation libérée).
     * Tout état non null COMPTE dans les plafonds.
     */
    public function etatInjection(string $workspaceId, string $cleIdempotence): ?string;

    /** À appeler SOUS le verrou : réserve la place (statut `reservee`, compte dans les plafonds). Vrai si la réservation est nouvelle. */
    public function reserverInjection(string $workspaceId, DateTimeImmutable $jour, string $cleIdempotence, DateTimeImmutable $instant): bool;

    /**
     * Conclut la réservation en cours : `acceptee` | `liberee` (la place est rendue) | `a_verifier` (elle compte toujours).
     * Une seule conclusion par réservation : vrai la première fois, faux ensuite (rien ne change).
     * Seule transition permise ensuite : `a_verifier` -> `acceptee` (renvoi confirmé), une seule fois.
     */
    public function concluireInjection(string $workspaceId, DateTimeImmutable $jour, string $cleIdempotence, string $statut): bool;

    /**
     * À appeler SOUS le verrou : toute réservation restée `reservee` depuis avant $avant passe en `a_verifier`.
     *
     * @return list<string> clés passées en `a_verifier`
     */
    public function perimerReservations(string $workspaceId, DateTimeImmutable $avant): array;

    /**
     * Balaie TOUS les espaces : comme `perimerReservations`, pour chacun.
     *
     * @return list<array{0: string, 1: string}> couples [espace, clé] passés en `a_verifier`
     */
    public function perimerToutesLesReservations(DateTimeImmutable $avant): array;

    /** À appeler SOUS le verrou. Une même clé ne compte qu'une fois. */
    public function noterInjection(string $workspaceId, DateTimeImmutable $jour, string $cleIdempotence): void;

    /** Commande locale uniquement (artisan). */
    public function eteindreTemoins(string $workspaceId, string $auteur): void;

    /**
     * Commande locale : ajoute un témoin (empreinte HMAC seulement, jamais l'adresse), journalisé dans la MÊME
     * transaction. Idempotent : un doublon n'est pas une erreur. Vrai si le témoin est nouveau.
     */
    public function ajouterTemoin(string $workspaceId, string $adresse, string $fournisseur, string $libelle, string $auteur): bool;

    /** @return list<array{empreinte: string, libelle: string, fournisseur: string|null, cree_le: string}> */
    public function listerTemoins(string $workspaceId): array;

    /** Commande locale : arme « Tout arrêter » (journalisé). */
    public function armerToutArreter(string $workspaceId, string $auteur): void;

    /** Commande locale : désarme « Tout arrêter » (journalisé, dans la même transaction). Faux si rien n'était armé. */
    public function leverToutArreter(string $workspaceId, string $auteur): bool;

    /** @param array<string, mixed> $details */
    public function journaliser(string $workspaceId, string $commande, string $auteur, array $details = [], ?DateTimeImmutable $jour = null): void;
}
