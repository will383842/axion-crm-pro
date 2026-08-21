<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * LE COMPTEUR DE DEPENSE LLM — constat B12-007 (S1).
 *
 * 🔴 CE QU'IL Y AVAIT AVANT, ET CE QUE CA AFFIRMAIT.
 *
 * Ce controleur tenait en deux lignes :
 *
 *     public function index(Request $r): JsonResponse   { return $this->ok(['data' => []]); }
 *     public function summary(Request $r): JsonResponse { return $this->ok(['summary' => ['total_eur' => 0]]); }
 *
 * Deux corps ECRITS DANS LE CODE, rendus avec un `200`. L'onglet « Usage 30j »
 * de `LlmRouterPage.tsx` lit `summary.total_eur` : la console annoncait donc en
 * permanence **0,00 EUR depense en appels LLM**, quel que soit le contenu de la
 * table `llm_usage`.
 *
 * 🔑 Ce n'est pas une fonctionnalite manquante, c'est une AFFIRMATION. Un `501`
 * dit « je ne sais pas » ; un `200 {"total_eur":0}` dit « j'ai regarde, et il
 * n'y a rien ». Sur une ligne de budget, la difference n'est pas de nuance :
 * c'est la difference entre un tableau de bord et un faux temoin. Et le
 * plafond de cout par cas d'usage (`llm_use_cases.cost_cap_eur`) ne peut pas
 * etre surveille par un compteur qui rend zero.
 *
 * ✅ CE QUE C'EST MAINTENANT. La table `llm_usage` EXISTE depuis la migration
 * `2026_05_16_000004` — une ligne par appel, avec `cost_eur NUMERIC(10,6)`,
 * `tokens_input`, `tokens_output`, `provider`, `use_case_slug` — et elle porte
 * meme son index `idx_llm_usage_workspace_created (workspace_id, created_at DESC)`,
 * exactement l'index que ces deux requetes empruntent. La piece etait posee ;
 * seule la route ne la regardait pas.
 *
 * Garde : `tests/Feature/Api/CorpsCodeEnDurTest.php` — elle seme 1,50 + 2,25 EUR
 * en base et exige 3,75 EUR en sortie. Un corps en dur ne peut pas inventer ce
 * chiffre.
 */
class LlmUsageController extends ApiController
{
    /**
     * Plafond de lignes rendues par `index()`.
     *
     * `llm_usage` grossit d'une ligne PAR APPEL LLM : c'est la table la plus
     * bavarde du sous-systeme. `G41-007` a etabli qu'une lecture sans plafond
     * gele l'application au volume de production ; on ne refait pas la meme
     * erreur en refermant celle-ci.
     */
    private const PLAFOND = 100;

    /** Fenetre par defaut du resume, en jours — l'onglet s'intitule « Usage 30j ». */
    private const FENETRE_PAR_DEFAUT = 30;

    /**
     * @OA\Get(path="/llm/usage", tags={"LLM"}, summary="Historique d'usage LLM (tokens + coût €)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="limit", in="query", @OA\Schema(type="integer", maximum=100)),
     *
     *     @OA\Response(response=200, description="Liste paginée"))
     */
    public function index(Request $r): JsonResponse
    {
        if (! Schema::hasTable('llm_usage')) {
            return $this->ok(['data' => []]);
        }

        // Sans contexte d'espace, on ne rend RIEN — jamais la depense de tous
        // les clients. Meme regle fail-closed que `P6-API-001` : le doute se
        // tranche en faveur du silence.
        $espace = $this->espaceCourantOuNull();
        if ($espace === null) {
            return $this->ok(['data' => []]);
        }

        $plafond = max(1, min(self::PLAFOND, (int) $r->query('limit', (string) self::PLAFOND)));

        try {
            $lignes = DB::table('llm_usage')
                ->where('workspace_id', $espace)
                ->orderByDesc('created_at')
                ->limit($plafond)
                ->get([
                    'id', 'use_case_slug', 'provider', 'model',
                    'tokens_input', 'tokens_output', 'cost_eur',
                    'latency_ms', 'cache_hit', 'created_at',
                ])
                ->map(function (object $ligne): array {
                    $t = (array) $ligne;
                    // `NUMERIC` ressort en CHAINE du pilote PostgreSQL. Le
                    // rendre tel quel obligerait l'ecran a le reconvertir, et un
                    // « 1.500000 » additionne comme une chaine donne « 01.5 ».
                    $t['cost_eur'] = (float) $t['cost_eur'];

                    return $t;
                })
                ->all();

            return $this->ok(['data' => $lignes, 'limit' => $plafond]);
        } catch (\Throwable $e) {
            Log::error('llm.usage.index failed', ['exception' => $e->getMessage()]);
            report($e);

            // `degraded` : on distingue explicitement « rien a montrer » de
            // « je n'ai pas pu regarder ». C'est precisement la distinction que
            // l'ancienne version effacait.
            return $this->ok(['data' => [], 'degraded' => true]);
        }
    }

    /**
     * @OA\Get(path="/llm/usage/summary", tags={"LLM"}, summary="Résumé coûts LLM (par use-case, par provider, par jour)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="days", in="query", @OA\Schema(type="integer", maximum=365)),
     *
     *     @OA\Response(response=200, description="Agrégations"))
     */
    public function summary(Request $r): JsonResponse
    {
        $vide = $this->gabaritVide(self::FENETRE_PAR_DEFAUT);

        if (! Schema::hasTable('llm_usage')) {
            return $this->ok(['summary' => $vide]);
        }

        $espace = $this->espaceCourantOuNull();
        if ($espace === null) {
            return $this->ok(['summary' => $vide]);
        }

        $jours = max(1, min(365, (int) $r->query('days', (string) self::FENETRE_PAR_DEFAUT)));
        $vide = $this->gabaritVide($jours);

        try {
            // 🔑 DEUX AGREGATIONS EN BASE, PAS UN PARCOURS EN PHP. Ramener les
            // lignes pour les additionner ici coûterait un aller-retour par
            // appel LLM enregistre — c'est-a-dire tout ce que la table contient.
            // Postgres additionne sur l'index ; on lui laisse le travail.
            $parCasDUsage = DB::table('llm_usage')
                ->where('workspace_id', $espace)
                ->where('created_at', '>=', now()->subDays($jours))
                ->groupBy('use_case_slug')
                ->get([
                    'use_case_slug',
                    DB::raw('SUM(cost_eur) AS cout'),
                    DB::raw('SUM(tokens_input) AS entree'),
                    DB::raw('SUM(tokens_output) AS sortie'),
                    DB::raw('COUNT(*) AS appels'),
                ]);

            $parFournisseur = DB::table('llm_usage')
                ->where('workspace_id', $espace)
                ->where('created_at', '>=', now()->subDays($jours))
                ->groupBy('provider')
                ->get(['provider', DB::raw('SUM(cost_eur) AS cout')]);

            $resume = $vide;

            foreach ($parCasDUsage as $ligne) {
                $cout = (float) $ligne->cout;

                $resume['by_use_case'][(string) $ligne->use_case_slug] = [
                    'cost_eur' => round($cout, 6),
                    'tokens_in' => (int) $ligne->entree,
                    'tokens_out' => (int) $ligne->sortie,
                    'calls' => (int) $ligne->appels,
                ];

                $resume['total_eur'] += $cout;
                $resume['tokens_in'] += (int) $ligne->entree;
                $resume['tokens_out'] += (int) $ligne->sortie;
                $resume['calls'] += (int) $ligne->appels;
            }

            foreach ($parFournisseur as $ligne) {
                $resume['by_provider'][(string) $ligne->provider] = round((float) $ligne->cout, 6);
            }

            // Arrondi au centieme de centime : `cost_eur` est un NUMERIC(10,6),
            // et une somme de flottants rend 3.7500000000000004 si on ne la
            // borne pas — un total de depense affiche avec quinze decimales
            // fait douter de tout le tableau.
            $resume['total_eur'] = round($resume['total_eur'], 6);

            return $this->ok(['summary' => $resume]);
        } catch (\Throwable $e) {
            Log::error('llm.usage.summary failed', ['exception' => $e->getMessage()]);
            report($e);

            return $this->ok(['summary' => array_merge($vide, ['degraded' => true])]);
        }
    }

    /**
     * Le gabarit complet, a zero.
     *
     * ⚠️ IL EST INDISPENSABLE, ET IL RESSEMBLE POURTANT AU DEFAUT QU'ON REPARE.
     * `LlmRouterPage.tsx` lit `by_provider` et `by_use_case` sans garde : une
     * cle absente y devient `undefined`, et `Object.entries(undefined)` jette.
     * On part donc TOUJOURS du gabarit complet, et on l'enrichit — comme
     * `DashboardController::gabaritVide()`, meme raison, meme forme.
     *
     * La difference avec l'ancien corps en dur tient en un mot : ce gabarit est
     * un POINT DE DEPART, pas une reponse. Il n'est rendu tel quel que dans les
     * trois cas ou l'on n'a rien pu mesurer — table absente, espace inconnu,
     * requete en erreur — et le dernier porte alors `degraded: true`.
     *
     * @return array<string, mixed>
     */
    private function gabaritVide(int $jours): array
    {
        return [
            'total_eur' => 0.0,
            'tokens_in' => 0,
            'tokens_out' => 0,
            'calls' => 0,
            'window_days' => $jours,
            'by_provider' => [],
            'by_use_case' => [],
        ];
    }
}
