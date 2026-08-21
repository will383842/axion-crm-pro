<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Refresh la materialized view `coverage_matrix_cells`.
 * Schedulé hourly (cf. routes/console.php).
 *
 * 🔴 A08-001 (S1), mesuré le 2026-08-20 — « échoue 71 fois sur 71 en production
 * depuis l'armement du rôle applicatif ».
 *
 * Cette commande émettait `REFRESH MATERIALIZED VIEW coverage_matrix_cells`
 * directement sur la connexion par défaut. Or PostgreSQL réserve ce verbe au
 * PROPRIÉTAIRE de la vue — ce n'est pas un privilège, aucun `GRANT` ne
 * l'accorde. Reproduction :
 *
 *     psql -U axion_app -c "REFRESH MATERIALIZED VIEW coverage_matrix_cells;"
 *     ERROR:  must be owner of materialized view coverage_matrix_cells
 *
 * Depuis `CRM_DB_APP_ROLE_ENABLED=true`, la connexion par défaut porte
 * `axion_app`, qui n'est pas propriétaire : chaque passage horaire mourait.
 *
 * Le passage se fait désormais par `public.rafraichir_matrice_couverture()`,
 * fonction `SECURITY DEFINER` appartenant au propriétaire, sur laquelle le rôle
 * applicatif a EXECUTE et rien d'autre
 * (`2026_08_20_140000_rafraichir_matrice_couverture_par_fonction_definer`).
 *
 * ⚠️ Et la commande AVOUE désormais son échec. Le planificateur de Laravel
 * n'interprète pas le code de sortie d'une commande planifiée : les 71 échecs
 * sont partis sur un flux sans lecteur. Un `Log::critical` part, lui, dans le
 * canal de l'application — et `routes/console.php` double l'alerte par un
 * `onFailure()`, seul crochet du planificateur qui lise le code de sortie.
 */
class CoverageRefreshMatrix extends Command
{
    /** Préfixe cherché par la garde et par l'exploitation dans les journaux. */
    public const PREFIXE_ALERTE = '[COUVERTURE] rafraichissement de coverage_matrix_cells EN ECHEC';

    protected $signature = 'coverage:refresh-matrix {--concurrent : utilise REFRESH CONCURRENTLY}';

    protected $description = 'Rafraîchit la materialized view coverage_matrix_cells (rollup hourly).';

    public function handle(): int
    {
        $concurrent = (bool) $this->option('concurrent');
        $start = microtime(true);

        try {
            // `SELECT fonction(?)` et non `DB::statement` : c'est un appel de
            // fonction, pas une commande utilitaire. Le `CONCURRENTLY` est
            // décidé DANS la fonction, qui sait si la vue est déjà peuplée —
            // PostgreSQL refuse CONCURRENTLY sur une vue jamais peuplée, et la
            // vue naît `WITH NO DATA`.
            DB::select('SELECT public.rafraichir_matrice_couverture(?)', [$concurrent]);
        } catch (Throwable $e) {
            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            $message = self::PREFIXE_ALERTE . " apres {$latencyMs} ms (concurrent="
                . ($concurrent ? 'true' : 'false') . ') : ' . $e->getMessage();

            // Deux sorties, parce qu'elles n'ont pas le même lecteur : `error()`
            // pour l'humain qui lance la commande à la main, `Log::critical`
            // pour la tâche planifiée, dont la sortie console ne va nulle part.
            $this->error($message);
            Log::critical($message);

            return self::FAILURE;
        }

        $latencyMs = (int) ((microtime(true) - $start) * 1000);
        $this->info("coverage_matrix_cells refresh OK ({$latencyMs} ms, concurrent=" . ($concurrent ? 'true' : 'false') . ')');

        return self::SUCCESS;
    }
}
