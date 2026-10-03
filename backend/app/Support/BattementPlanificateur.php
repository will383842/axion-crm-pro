<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BATTEMENT DU PLANIFICATEUR — « `schedule:work` tourne-t-il encore ? »
 *
 * Avis #308, réserve 5 : un `crm:flush-outbound` arrêté avec une file vide est
 * invisible, et ne se voit que 2 à 3 h après le premier événement non émis.
 * Plus largement, un conteneur `scheduler` mort fait taire TOUTES les tâches
 * planifiées (purges RGPD comprises) sans qu'aucune ne le dise.
 *
 * Une tâche planifiée CHAQUE MINUTE (`routes/console.php`, en processus, sans
 * verrou ni requête SQL) pose ici l'horodatage de son passage ; la sonde
 * `crm:canaux:etat` le lit et lève `planificateur_arrete` au-delà de 15 min.
 *
 * Magasin : le cache par défaut (`redis` en production), partagé entre le
 * conteneur `scheduler` qui écrit et le conteneur `api` qui lit. Aucune donnée
 * personnelle : un entier.
 */
final class BattementPlanificateur
{
    /** Nom de la tâche planifiée (visible dans `schedule:list`). */
    public const NOM_TACHE = 'crm:planificateur:battement';

    private const CLE = 'crm:planificateur:battement';

    /** Un battement absent depuis un jour a expiré : la sonde dit « jamais ». */
    private const TTL_SECONDES = 86400;

    public static function battre(): void
    {
        try {
            Cache::store()->put(self::CLE, now()->getTimestamp(), self::TTL_SECONDES);
        } catch (Throwable $e) {
            // Le battement manqué SERA vu par la sonde : rien de plus à faire.
            Log::warning('battement du planificateur non écrit', ['exception' => $e::class]);
        }
    }

    /**
     * Horodatage Unix du dernier battement, `null` s'il n'y en a aucun.
     *
     * @throws Throwable si le magasin est illisible (la sonde en fait une alerte)
     */
    public static function dernier(): ?int
    {
        $valeur = Cache::store()->get(self::CLE);

        return is_numeric($valeur) ? (int) $valeur : null;
    }
}
