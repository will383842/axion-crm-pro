<?php

namespace App\Socle\Produits;

use App\Models\Workspace;
use App\Support\WorkspaceContext;

/**
 * Droit d'un espace à un produit (contrat `EtatProduit` : actif, suspendu, resilie, absent).
 *
 * SOURCE UNIQUE côté serveur : `workspaces.settings` → `produits.<produit>`. Elle suit le schéma `Features.produits`
 * du contrat OpenAPI (backend/openapi/envoi/commun.yaml) ; `FeaturesController` n'exposait pas encore ce champ
 * (le frontend retombe sur « absent » pour envoi). Quand il l'exposera, il devra lire CETTE classe, pas une autre.
 *
 * FERMÉ PAR DÉFAUT : tout ce qui n'est pas exactement la chaîne « actif » (absent, inconnu, mal typé, espace introuvable)
 * vaut « absent ». Aucun réglage ne peut donc ouvrir Envoi par accident.
 */
final class ProduitsEspace
{
    public const ETATS = ['actif', 'suspendu', 'resilie', 'absent'];

    /** Produits que la console sait activer (`php artisan produits:activer`). */
    public const PRODUITS = ['envoi'];

    /**
     * Clés de `workspaces.settings` RÉSERVÉES À LA CONSOLE : aucune route cliente ne les écrit.
     * `PUT /api/v1/workspace` les refuse (422) et les conserve telles qu'en base. Les poser ne passe
     * que par `produits:activer`. Toute nouvelle clé de ce genre s'ajoute ICI, et nulle part ailleurs.
     */
    public const CLES_RESERVEES = ['produits'];

    public static function etat(?string $workspaceId, string $produit): string
    {
        $id = WorkspaceContext::validIdOrNull($workspaceId);
        if ($id === null) {
            return 'absent';
        }
        $settings = Workspace::query()->whereKey($id)->value('settings');
        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }
        $etat = is_array($settings) && is_array($settings['produits'] ?? null) ? ($settings['produits'][$produit] ?? null) : null;

        return is_string($etat) && in_array($etat, self::ETATS, true) ? $etat : 'absent';
    }

    public static function actif(?string $workspaceId, string $produit): bool
    {
        return self::etat($workspaceId, $produit) === 'actif';
    }
}
