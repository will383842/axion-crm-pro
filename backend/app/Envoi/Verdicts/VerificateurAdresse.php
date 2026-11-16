<?php

namespace App\Envoi\Verdicts;

use DateTimeImmutable;

/** Emplacement d'un vérificateur d'adresses. Seul `VerificateurInterne` existe (sans coût, sans sonder personne). */
interface VerificateurAdresse
{
    /** Nom écrit dans `source` (32 caractères au plus, [a-z0-9-]). */
    public function source(): string;

    /**
     * @param  list<string>  $adresses  adresses en clair, de l'espace courant
     * @return array<string, VerdictAdresse|null> clé = ListeSuppression::empreinte($adresse) ; null = on ne sait pas
     */
    public function verifier(string $ws, array $adresses, DateTimeImmutable $maintenant): array;
}
