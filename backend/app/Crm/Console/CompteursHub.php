<?php

namespace App\Crm\Console;

use App\Crm\Taxonomy;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * COMPTEURS DU HUB DE CONTACTS — les pastilles de la navigation (conception
 * §2.2), rendues à CHAQUE affichage de l'écran.
 *
 * Pourquoi cette classe existe (mesure, pas supposition) :
 * `_REPORTS/2026-08-18_MESURE-PERFORMANCE-REFERENCE.md` §4 n°1 — l'agrégat
 * `GROUP BY relation_type, lifecycle_stage` était un **balayage séquentiel
 * complet** de `companies`. 300 000 fiches → 337 à 476 ms mesurés ; la
 * production en porte **4,29 M dans le même workspace**, donc de l'ordre de
 * **3 s à chaque rendu de navigation**, et ça grandit avec la collecte.
 *
 * Deux correctifs, qui ne se remplacent pas :
 *
 *   1. **Un index couvrant** (`idx_companies_ws_counts`, migration
 *      `2026_08_19_000001`) : le calcul devient un `Index Only Scan` — c'est le
 *      plancher, ce qu'on paye quand le cache est froid.
 *   2. **Un cache court, par workspace** (ci-dessous) : le calcul sort du
 *      chemin d'affichage. Le cahier des charges demande des compteurs qui
 *      « appellent une action », pas un total à la seconde près — le rapport de
 *      mesure conclut que « les rafraîchir toutes les cinq minutes suffit ».
 *
 * `Cache::flexible` plutôt que `Cache::remember`, à dessein : au-delà de la
 * fenêtre fraîche, la valeur périmée est servie IMMÉDIATEMENT et le recalcul
 * part en différé, sous verrou. Avec `remember`, l'expiration provoque une
 * ruée : le critère §29 n°17 exige dix sessions simultanées sans dégradation,
 * et dix balayages lancés à la même seconde sont exactement ce qu'il ne faut
 * pas. Le verrou de `flexible` fait qu'un seul recalcule.
 *
 * ⚠️ Le cache est **par workspace**, jamais global : deux univers ne partagent
 * pas un compteur. La clé porte l'identifiant de workspace, et un test le garde
 * (`CompteursHubTest`, « le cache ne fuit pas d'un workspace à l'autre »).
 */
final class CompteursHub
{
    /**
     * Fenêtre FRAÎCHE : en deçà, la valeur en cache est servie telle quelle.
     * Cinq minutes — la valeur du rapport de mesure §4 n°1.
     */
    public const FRAIS_SECONDES = 300;

    /**
     * Fenêtre PÉRIMÉE : entre `FRAIS_SECONDES` et celle-ci, la valeur est encore
     * servie (immédiatement) mais un recalcul part en différé. Au-delà, plus
     * rien en cache : le calcul redevient synchrone.
     */
    public const PERIME_SECONDES = 3600;

    /**
     * `v1` dans la clé : le jour où la forme de la charge utile change, la
     * version change avec elle. Sans cela, un déploiement sert pendant une heure
     * des charges utiles de l'ancienne forme à du code neuf.
     */
    public static function cle(string $workspaceId): string
    {
        return 'crm:hub:counts:v1:' . $workspaceId;
    }

    /**
     * Les compteurs du workspace, depuis le cache quand c'est possible.
     *
     * @return array{total: int, by_relation_type: array<string, int>, by_lifecycle_stage: array<string, int>, computed_at: string}
     */
    public static function pour(string $workspaceId): array
    {
        /** @var mixed $charge */
        $charge = Cache::flexible(
            self::cle($workspaceId),
            [self::FRAIS_SECONDES, self::PERIME_SECONDES],
            static fn (): array => self::calculer($workspaceId),
            // Verrou BORNÉ. Par défaut `flexible` prend un verrou SANS expiration :
            // un processus tué entre la prise et le relâchement le laisserait posé
            // pour toujours, et les compteurs de ce workspace ne se
            // rafraîchiraient plus JAMAIS — une panne muette, celle qui coûte le
            // plus cher à diagnostiquer. Trente secondes : très au-dessus du
            // calcul mesuré, très en dessous de la fenêtre de fraîcheur.
            lock: ['seconds' => 30],
        );

        // Défense contre une charge utile d'une forme antérieure restée en
        // cache (déploiement, clé recyclée) : on recalcule plutôt que de servir
        // un objet incomplet à l'écran.
        if (! is_array($charge) || ! isset($charge['total'], $charge['by_relation_type'], $charge['by_lifecycle_stage'], $charge['computed_at'])) {
            return self::calculer($workspaceId);
        }

        /** @var array{total: int, by_relation_type: array<string, int>, by_lifecycle_stage: array<string, int>, computed_at: string} $charge */
        return $charge;
    }

    /**
     * Oublie les compteurs d'un workspace — à appeler depuis les écritures de la
     * console qui déplacent une fiche d'une case à l'autre (changement d'étape
     * en masse). Volontairement PAS branché sur les écritures de la collecte :
     * celle-ci insère par centaines de milliers, et invalider à chaque insertion
     * ferait recalculer en permanence ce que le cache est là pour éviter.
     */
    public static function oublier(string $workspaceId): void
    {
        $cle = self::cle($workspaceId);

        Cache::forget($cle);
        // `flexible` tient un second marqueur : sans lui, la date de calcul
        // survivrait à l'oubli et fausserait la fenêtre de fraîcheur.
        Cache::forget('illuminate:cache:flexible:created:' . $cle);
    }

    /**
     * Le calcul, sans cache. Une seule requête agrégée : un compteur par entrée
     * de menu aurait fait huit requêtes à chaque rendu de page.
     *
     * Les tableaux sont pré-remplis à zéro sur la taxonomie FERMÉE (§Taxonomy) :
     * une case sans fiche doit s'afficher « 0 », pas disparaître de l'écran.
     *
     * @return array{total: int, by_relation_type: array<string, int>, by_lifecycle_stage: array<string, int>, computed_at: string}
     */
    public static function calculer(string $workspaceId): array
    {
        /** @var array<string, int> $byType */
        $byType = array_fill_keys(Taxonomy::BUSINESS_RELATION_TYPES, 0);
        /** @var array<string, int> $byStage */
        $byStage = array_fill_keys(Taxonomy::BUSINESS_LIFECYCLE_STAGES, 0);

        // Les trois colonnes, et rien d'autre : c'est ce qui rend l'index
        // `idx_companies_ws_counts` COUVRANT. Ajouter une colonne ici
        // (`created_at`, `quality_score`…) suffirait à faire retomber le plan
        // sur un accès au tas — la garde « les compteurs sont servis par un
        // index couvrant » (`CompteursHubTest`) rougit dans ce cas.
        //
        // `WorkspaceContext::run` et pas seulement le `where` : `companies` porte
        // une policy RLS en FORCE, alimentée par la variable de session
        // `app.current_workspace_id`. En HTTP c'est le middleware
        // `SetCurrentWorkspace` qui la pose — mais il la RETIRE au `terminate()`,
        // et le rafraîchissement différé de `Cache::flexible` s'exécute
        // précisément après la réponse. Sans ce contexte posé par le calcul
        // lui-même, la requête verrait ZÉRO ligne sous le rôle applicatif et
        // mettrait en cache « total : 0 » pour une heure — un écran vide, sans
        // erreur nulle part. Le contexte est restauré ensuite, y compris sur
        // exception.
        $rows = WorkspaceContext::run($workspaceId, static fn () => DB::table('companies')
            ->selectRaw('relation_type, lifecycle_stage, count(*) AS total')
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')
            ->groupBy('relation_type', 'lifecycle_stage')
            ->get());

        $total = 0;
        foreach ($rows as $row) {
            $count = (int) $row->total;
            $total += $count;

            $type = is_string($row->relation_type) ? $row->relation_type : '';
            if (array_key_exists($type, $byType)) {
                $byType[$type] += $count;
            }

            $stage = is_string($row->lifecycle_stage) ? $row->lifecycle_stage : '';
            if (array_key_exists($stage, $byStage)) {
                $byStage[$stage] += $count;
            }
        }

        return [
            'total' => $total,
            'by_relation_type' => $byType,
            'by_lifecycle_stage' => $byStage,
            // Temps universel (§29 n°16) : l'écran affiche dans le fuseau de
            // l'utilisateur, la donnée se stocke et circule en UTC.
            'computed_at' => now()->utc()->toIso8601ZuluString(),
        ];
    }
}
