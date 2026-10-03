<?php

namespace App\Providers;

use App\Crm\ProvenanceTiers\EmpreinteTelephone;
use Illuminate\Support\ServiceProvider;

/**
 * Validation au démarrage de la provenance tiers (N12, 03/10/2026).
 *
 * Fonctionnalité activée (`crm.provenance_tiers.actif`) sans clé d'empreinte
 * valable : l'application REFUSE DE DÉMARRER, plutôt que d'écrire un jour des
 * oppositions par téléphone impossibles à rapprocher. Désactivée (défaut) :
 * aucune clé n'est exigée — `EmpreinteTelephone` lève de toute façon à son
 * premier appel sans clé.
 */
class ProvenanceTiersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (filter_var(config('crm.provenance_tiers.actif'), FILTER_VALIDATE_BOOL)) {
            EmpreinteTelephone::exigerCle();
        }
    }
}
