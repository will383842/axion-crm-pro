<?php

namespace App\Envoi\Imap;

use App\Envoi\Reponses\StockerReponse;

/**
 * Maillon de la chaîne (R12) : garde le texte chiffré des réponses (humaine, automatique, désinscription, hostile).
 * Ne décide rien et n'applique aucun arrêt. Rejeu sans effet (une ligne par événement).
 */
final class RecepteurRetoursReponses implements RecepteurRetours
{
    public function __construct(private readonly StockerReponse $stockage) {}

    public function recevoir(string $workspaceId, RetourDetecte $retour): void
    {
        $this->stockage->enregistrer(
            $workspaceId,
            $retour->cle,
            (string) $retour->objet,
            (string) $retour->extrait,
            $retour->adresseExpediteur ?? $retour->adresse,
            $retour->classe->value,
        );
    }
}
