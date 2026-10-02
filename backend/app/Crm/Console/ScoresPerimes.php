<?php

namespace App\Crm\Console;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LA PART ESTIMÉE DES SCORES QUALITÉ PÉRIMÉS — partagée par l'accueil et la
 * carte de France.
 *
 * Sur un échantillon de 0,1 % des fiches, la part dont le `quality_score`
 * stocké diffère du barème (`company_quality_score_calcul`). Tant que cette
 * part est forte, les écrans DISENT « calcul en attente » au lieu d'une
 * moyenne fausse.
 *
 * 🔴 2026-10-02 22 h — la carte affichait « calcul en attente » alors que la
 * reprise `crm:recalculer-quality-score` était TERMINÉE (21 h 46). Mesuré en
 * production : l'échantillon donnait 0 écart sur 4 042 fiches. La carte ne
 * calculait rien elle-même : elle relisait le cache de l'ACCUEIL
 * (`DashboardController::cle`), qui expire 30 minutes après le dernier
 * affichage de l'accueil. Accueil pas ouvert depuis 30 min → clé absente →
 * `null` → « calcul en attente », sans fin ; accueil ouvert PENDANT la
 * reprise → l'ancien pourcentage (≈ 41 %) reste servi jusqu'à 30 min.
 * Désormais la carte obtient sa valeur ici, avec son propre cache court.
 *
 * Coût mesuré en production : ≈ 0,9 s pour l'échantillon (≈ 4 000 fiches).
 */
final class ScoresPerimes
{
    /** Fraîche 10 min ; servie au-delà (recalcul en différé) jusqu'à 1 h. */
    public const FRAIS_SECONDES = 600;

    public const PERIME_SECONDES = 3600;

    public static function cle(string $espace): string
    {
        return 'crm:qualite:perimes:v1:' . $espace;
    }

    /**
     * Depuis le cache quand c'est possible. `null` = estimation impossible.
     */
    public static function enCache(string $espace): ?float
    {
        /** @var mixed $charge */
        $charge = Cache::flexible(
            self::cle($espace),
            [self::FRAIS_SECONDES, self::PERIME_SECONDES],
            // Enveloppé dans un tableau : un `null` (barème absent) se met
            // aussi en cache, au lieu d'être recalculé à chaque affichage.
            // `WorkspaceContext::run` : le recalcul différé tourne APRÈS la
            // réponse, quand la RLS n'a plus de contexte (cf. CompteursHub).
            static fn (): array => ['pct' => WorkspaceContext::run($espace, static fn (): ?float => self::estimer($espace))],
            lock: ['seconds' => 30],
        );

        if (! is_array($charge) || ! array_key_exists('pct', $charge)) {
            return null;
        }

        return is_numeric($charge['pct']) ? (float) $charge['pct'] : null;
    }

    /**
     * Le calcul, sans cache. Suppose le contexte d'espace posé (RLS).
     */
    public static function estimer(string $espace): ?float
    {
        // Sous PostgreSQL, une requête en échec AVORTE la transaction en cours
        // (25P02) : on vérifie que le barème existe avant de l'appeler.
        try {
            $bareme = DB::selectOne("SELECT count(*) AS n FROM pg_proc WHERE proname = 'company_quality_score_calcul'");
            if ((int) ($bareme->n ?? 0) === 0) {
                return null;
            }

            $echantillon = DB::selectOne(
                'SELECT count(*) AS n,
                        count(*) FILTER (WHERE c.quality_score IS DISTINCT FROM company_quality_score_calcul(c)) AS ecarts
                   FROM companies c TABLESAMPLE SYSTEM (0.1)
                  WHERE c.workspace_id = ? AND c.deleted_at IS NULL',
                [$espace],
            );
            // Trop petit échantillon (petite base) : on compare TOUT.
            if ((int) $echantillon->n < 200) {
                $echantillon = DB::selectOne(
                    'SELECT count(*) AS n,
                            count(*) FILTER (WHERE c.quality_score IS DISTINCT FROM company_quality_score_calcul(c)) AS ecarts
                       FROM (SELECT * FROM companies WHERE workspace_id = ? AND deleted_at IS NULL LIMIT 5000) c',
                    [$espace],
                );
            }
            $n = (int) $echantillon->n;

            return $n === 0 ? 0.0 : round(100 * (int) $echantillon->ecarts / $n, 1);
        } catch (\Throwable $e) {
            Log::warning('qualite: estimation des scores perimes indisponible', ['exception' => $e->getMessage()]);

            return null;
        }
    }
}
