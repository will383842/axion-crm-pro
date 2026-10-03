<?php

namespace App\Providers;

use App\Support\Partners\ConfigurationCanalPartners;
use Illuminate\Support\ServiceProvider;

/**
 * Lot N11 — refus de démarrage du futur canal Axion Partners mal configuré.
 *
 * Relue à CHAQUE démarrage (y compris sous `config:cache`). Mode inconnu, ou
 * mode `essai` / `actif` avec un secret entrant absent, trop court ou au
 * préfixe de développement : l'application ne démarre pas. Une erreur de
 * configuration sur un canal signé se corrige avant de servir.
 *
 * En mode `off` (défaut), seul le mode est contrôlé : le canal est fermé et
 * n'a besoin d'aucun secret. Le reste des règles vit dans
 * `App\Support\Partners\ConfigurationCanalPartners::depuisConfig()`, que le
 * vérificateur de requêtes rappelle à l'identique.
 *
 * Fournisseur DÉDIÉ plutôt qu'une ligne de plus dans
 * `CanauxSignesServiceProvider` : le canal du site et celui de Partners ont
 * des secrets, des modes et des calendriers distincts ; un refus de l'un se
 * lit sans ambiguïté dans le nom du fournisseur qui l'a levé.
 */
class CanalPartnersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        ConfigurationCanalPartners::depuisConfig();
    }
}
