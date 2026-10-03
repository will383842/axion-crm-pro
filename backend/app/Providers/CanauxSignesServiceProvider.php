<?php

namespace App\Providers;

use App\Support\FenetreHorodatage;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Validation au démarrage de la configuration des canaux internes signés.
 *
 * La fenêtre d'horodatage est relue et validée à CHAQUE démarrage (y compris
 * sous `config:cache`, où la valeur brute est figée telle que lue dans
 * l'environnement). Une valeur invalide lève une exception : l'application ne
 * démarre pas, plutôt que de servir un canal mal configuré.
 *
 * La valeur validée est réécrite dans la configuration sous forme d'entier :
 * les contrôleurs lisent ainsi une valeur déjà vérifiée.
 */
class CanauxSignesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $fenetre = FenetreHorodatage::valider(config('crm.ingest.max_clock_skew_seconds'));

        config(['crm.ingest.max_clock_skew_seconds' => $fenetre]);

        $this->exigerMagasinRedis();
    }

    /**
     * La mémoire des requêtes déjà vues doit être PARTAGÉE entre tous les
     * processus PHP : seul un magasin Redis l'est. Un magasin `array` (propre
     * à chaque requête) ou `file` (propre à chaque conteneur) la rendrait
     * inopérante sans aucune alerte. Hors environnement de test, tout autre
     * magasin fait refuser le démarrage.
     */
    private function exigerMagasinRedis(): void
    {
        if ($this->app->environment('testing')) {
            return;
        }

        $magasin = config('crm.ingest.replay_store');
        $pilote = is_string($magasin) && $magasin !== '' ? config("cache.stores.{$magasin}.driver") : null;

        if ($pilote !== 'redis') {
            throw new RuntimeException(sprintf(
                'Configuration invalide : CRM_INGEST_REPLAY_STORE (crm.ingest.replay_store) vaut %s, '
                . 'dont le pilote est %s. Attendu : un magasin de cache au pilote redis (défaut : redis). '
                . "L'application refuse de démarrer tant que la valeur n'est pas corrigée.",
                is_scalar($magasin) ? var_export($magasin, true) : get_debug_type($magasin),
                is_scalar($pilote) ? var_export($pilote, true) : 'inconnu',
            ));
        }
    }
}
