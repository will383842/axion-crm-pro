<?php

namespace App\Envoi\Gardes;

use DateTimeInterface;

/**
 * Ce que la porte `EnvoiAutorise` doit savoir d'UN envoi envisagé.
 * Aucune donnée n'est lue ailleurs que dans les tables d'envoi : l'appelant
 * fournit les faits qu'il a déjà (fiche, occurrences de l'adresse).
 */
final class DemandeEnvoi
{
    /**
     * @param  list<array<string, mixed>>  $occurrences  voir `EligibiliteAdresse::motif`
     */
    public function __construct(
        public readonly string $workspaceId,
        public readonly string $email,
        public readonly array $occurrences,
        public readonly ?string $paysOrganisation,
        public readonly ?string $siren,
        public readonly string $domaineExpediteur,
        public readonly ?string $entiteLegaleExpeditrice,
        public readonly ?DateTimeInterface $donneesCollecteesLe,
        public readonly ?DateTimeInterface $premiereInfoLe,
        public readonly ?string $segment = null,
        public readonly bool $premierContact = true,
        public readonly bool $nonInformes = false,
    ) {}
}
