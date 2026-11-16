<?php

namespace App\Envoi\Contrat;

/** Message à injecter. Aucun suivi d'ouverture : ni pixel, ni lien traqué. */
final class MessageAEnvoyer
{
    public function __construct(
        public readonly string $workspaceId,
        public readonly string $destinataire,
        public readonly string $expediteur,
        public readonly string $sujet,
        public readonly string $corps,
        public readonly string $cleIdempotence,
        /** UUID choisi par le CRM (registre C1) ; null pour un adaptateur qui garde le sien (smtp). */
        public readonly ?string $idInjection = null,
        /** Adresse de rebond VERP `rb-<32 hex><10 hex>@bounce.<domaine>` ; null sans clé d'empreinte (aucun VERP posé). */
        public readonly ?string $cheminRetour = null,
    ) {
        if ($idInjection !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $idInjection) !== 1) {
            throw new \InvalidArgumentException('Identifiant d\'injection : un UUID est exigé.');
        }
        if ($cheminRetour !== null && preg_match('/^rb-[0-9a-f]{32}[0-9a-f]{10}@bounce\.[a-z0-9.-]+$/', $cheminRetour) !== 1) {
            throw new \InvalidArgumentException('Chemin de retour : adresse VERP rb-…@bounce.<domaine> exigée.');
        }
    }
}
