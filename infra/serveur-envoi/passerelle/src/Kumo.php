<?php

declare(strict_types=1);

namespace Passerelle;

/** Appel local de KumoMTA. Les tests utilisent FauxKumo : aucun envoi réel. */
interface Kumo
{
    /**
     * Durée maximale (secondes) d'un appel au moteur. Condition BLOQUANTE K4c : strictement et nettement inférieure à
     * `Stockage::DELAI_RESERVATION`, sinon une réservation « en_cours » est reprise pendant que le premier appel court
     * encore et la commande s'exécute deux fois. Tout client DOIT appliquer cette limite (connexion + lecture).
     */
    public const DELAI_MAX_SECONDES = 20;

    /** Délai maximal RÉEL du client (≤ DELAI_MAX_SECONDES) ; `Passerelle` refuse un client plus lent. */
    public function delaiMaximal(): int;

    /**
     * Opérations : `pause`, `reprise`, `retrait` (params vides) et `injecter` (C2-2, params `mail_from`, `rcpt_to`,
     * `message` = octets RFC 5322 décodés ; jamais journalisés). `injecter` n'est PAS une commande K1.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     *
     * @throws \RuntimeException si KumoMTA refuse ou est injoignable
     */
    public function executer(string $workspaceId, string $operation, array $params): array;
}
