<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ABANDONS DÉJÀ SIGNALÉS — l'état de dédoublonnage de l'alerte `gave_up`,
 * tenu CÔTÉ SERVEUR.
 *
 * Veto sécurité #310 : cet état vivait dans des marqueurs
 * `<!-- gave_up_ids: … -->` des issues d'un dépôt PUBLIC. N'importe quel
 * compte GitHub pouvait y poster un marqueur et faire taire durablement
 * l'alerte RGPD la plus grave ; et les numéros de ligne publiés dévoilaient
 * le volume et le rythme des oppositions et effacements. Désormais aucun
 * numéro ne sort du serveur : la sonde ne rend qu'un COMPTE de lignes
 * nouvelles.
 *
 * Seule la surveillance planifiée MÉMORISE (`crm:canaux:etat
 * --memoriser-signales`) : une mesure rejouée à la main ne consomme rien.
 *
 * Magasin : le cache par défaut (`redis` en production). Une mémoire perdue
 * (`cache:clear` au déploiement, Redis redémarré) ou illisible fait
 * re-signaler les lignes encore dans la fenêtre : un doublon, jamais un
 * silence.
 */
final class MemoireAbandonsSignales
{
    private const CLE = 'crm:surveillance:gave_up_signales';

    /** Plus long que la fenêtre de 26 h, avec de la marge pour des passages sautés. */
    private const TTL_SECONDES = 3 * 86400;

    /**
     * Combien de lignes `$ids` (les abandons de la fenêtre) n'ont pas encore
     * été signalées. Avec `$memoriser`, elles le sont désormais.
     *
     * @param  list<int>  $ids
     */
    public static function nouveaux(array $ids, bool $memoriser): int
    {
        try {
            $deja = Cache::store()->get(self::CLE);
            $deja = is_array($deja) ? array_map('intval', $deja) : [];
        } catch (Throwable $e) {
            Log::warning('mémoire des abandons signalés illisible', ['exception' => $e::class]);

            return count($ids);
        }

        $nouveaux = count(array_diff($ids, $deja));

        if ($memoriser) {
            try {
                // On ne garde que les lignes encore dans la fenêtre : la
                // mémoire reste bornée par la sonde elle-même.
                Cache::store()->put(self::CLE, $ids, self::TTL_SECONDES);
            } catch (Throwable $e) {
                Log::warning('mémoire des abandons signalés non écrite', ['exception' => $e::class]);
            }
        }

        return $nouveaux;
    }
}
