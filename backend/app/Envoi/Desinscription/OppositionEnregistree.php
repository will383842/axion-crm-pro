<?php

namespace App\Envoi\Desinscription;

/**
 * Une personne s'est opposée par le lien de désinscription. Aucune adresse en
 * clair : l'empreinte suffit à retrouver ses envois. Le lot L1 (arrêt par
 * entreprise) s'abonne à cet événement ou implémente `ArretParEntreprise`.
 */
final class OppositionEnregistree
{
    public function __construct(
        public readonly string $workspaceId,
        public readonly string $empreinteAdresse,
        public readonly string $portee,
        public readonly string $canal,
    ) {}
}
