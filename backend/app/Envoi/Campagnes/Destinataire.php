<?php

namespace App\Envoi\Campagnes;

/**
 * X1-L2 — un destinataire retenu par la sélection. AUCUNE adresse e-mail en sortie : seule l'empreinte
 * (`ListeSuppression::empreinte` de l'adresse brute du contact) permet de recouper sans exposer l'adresse.
 */
final class Destinataire
{
    public function __construct(
        public readonly string $population,   // toujours 'entreprises' dans ce lot
        public readonly int $contactId,
        public readonly int $entrepriseId,
        public readonly ?int $personneId,     // toujours null dans ce lot
        public readonly string $empreinte,    // 64 caractères hexadécimaux
        public readonly bool $repliGenerique = false, // L3 : adresse générale choisie en dernier recours (un seul message, aucune relance)
    ) {}
}
