<?php

namespace App\Http\Controllers\Api;

use App\Crm\Taxonomy;
use App\Support\DelaiRequeteSql;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * L'ÉCRAN D'ACCUEIL DE LA CONSOLE — constat P6-UI-001 (S0).
 *
 * 🔴 CE QU'IL Y AVAIT AVANT, ET CE QUE ÇA FAISAIT.
 *
 * `GET /dashboard/stats` n'était pas un contrôleur : c'était une **closure dans
 * `routes/api.php` qui renvoyait des zéros écrits en dur**. Aucun
 * `DashboardController` n'existait dans ce dépôt.
 *
 * Or `DashboardPage.tsx` teste `companies_total === 0` pour décider d'afficher
 * son état vide. **L'écran d'accueil du CRM annonçait donc en permanence
 * « Lance ton premier scrape — aucune entreprise collectée »**, sur une base qui
 * en porte 4 295 349. Les quatre vignettes, les trois graphiques et les deux
 * cartes latérales étaient du code injoignable.
 *
 * 🔑 POURQUOI PERSONNE NE L'AVAIT VU. Le mandat de l'audit 360° exige que chaque
 * écran soit ouvert à la main dans un vrai navigateur (§12, point 3). *La console
 * ne tourne pas.* Un défaut qui saute aux yeux en trois secondes d'usage a donc
 * survécu à un audit de 46 agents, et n'a été trouvé qu'à la passe P6, par un
 * regard neuf, en lisant le code.
 *
 * ── DEUX PRINCIPES QUI VIENNENT DES CONSTATS DU JOUR ────────────────────────
 *
 * 1. **Cloisonné, et fail-closed.** Un compteur est plus discret qu'une liste :
 *    il ne montre aucune fiche, mais il en **révèle le nombre**. Sans contexte
 *    d'espace, on rend donc des zéros — jamais le total de tous les clients.
 *
 * 2. **Chaque compteur se défend seul.** Une table absente ou une requête en
 *    erreur ne doit pas emporter l'écran entier : c'est ce qui a produit
 *    `A-015`, où l'accueil s'effaçait dès qu'`audit_logs` portait une ligne. On
 *    renvoie zéro pour CE compteur-là, et on le journalise.
 */
class DashboardController extends ApiController
{
    /**
     * ── 2026-10-02 : « le tableau de bord met ~10 s » ──────────────────────
     *
     * Mesuré en production (EXPLAIN) : `companies_total` parcourt un index de
     * 4,3 M d'entrées, `contacts_qualified` balaie `contacts` (1,3 M) en
     * séquentiel, `size_distribution` balaie le tas de `companies` (4 Go).
     * Recalculés à CHAQUE ouverture de l'accueil, par chaque session.
     *
     * Ce sont des ordres de grandeur, pas une comptabilité : on les sert
     * depuis un cache court, par espace — même mécanique que
     * `App\Crm\Console\CompteursHub` (`Cache::flexible` : la valeur périmée
     * est servie tout de suite, UN seul recalcul part après la réponse, sous
     * verrou borné).
     */
    public const FRAIS_SECONDES = 120;

    public const PERIME_SECONDES = 1800;

    public static function cle(string $espace): string
    {
        return 'crm:dashboard:stats:v2:' . $espace;
    }

    public function stats(Request $r): JsonResponse
    {
        $espace = $this->espaceCourantOuNull();

        // Sans contexte d'espace : des zéros, jamais le total de tout le monde.
        if ($espace === null) {
            return response()->json($this->gabaritVide());
        }

        /** @var mixed $charge */
        $charge = Cache::flexible(
            self::cle($espace),
            [self::FRAIS_SECONDES, self::PERIME_SECONDES],
            fn (): array => $this->calculer($espace),
            lock: ['seconds' => 60],
        );

        if (! is_array($charge)) {
            $charge = $this->calculer($espace);
        }

        return response()->json(array_merge($this->gabaritVide(), $charge, [
            'period_label' => $this->libellePeriode($r->query('period')),
        ]));
    }

    /**
     * Le calcul, sans cache.
     *
     * - `WorkspaceContext::run` : le recalcul différé de `Cache::flexible`
     *   tourne APRÈS la réponse, quand `SetCurrentWorkspace` a retiré la
     *   variable de session de la RLS — sans contexte, il compterait zéro et
     *   le mettrait en cache (cf. `CompteursHub::calculer`).
     * - `DelaiRequeteSql::etendu` : ces balayages dépassent les 15 s accordés
     *   aux écrans ; ils ne bloquent plus l'écran, ils ont droit à plus.
     *
     * @return array<string, mixed>
     */
    private function calculer(string $espace): array
    {
        return DelaiRequeteSql::etendu(120, fn (): array => WorkspaceContext::run($espace, fn (): array => [
            'companies_total' => $this->compter('companies', $espace),
            'companies_enriched_24h' => $this->compter('companies', $espace, function ($q) {
                $q->where('updated_at', '>=', now()->subDay());
            }),
            'contacts_qualified' => $this->compter('contacts', $espace, function ($q) {
                // « Qualifiée » = joignable. C'est la définition que le hub
                // emploie déjà ; on ne réinvente pas un second sens ici.
                $q->where(function ($sq) {
                    $sq->whereNotNull('email')->orWhereNotNull('phone');
                });
            }),
            'scraper_runs_24h' => $this->compter('scraper_runs', $espace, function ($q) {
                $q->where('created_at', '>=', now()->subDay());
            }),
            // 🔴 2026-10-02 : « Qualité moyenne 0/100 » sur 4,3 M de fiches.
            // La répartition lisait la colonne `quality_tier`, qui N'EXISTE
            // PAS (la colonne générée s'appelle `quality_badge`) : le garde
            // `hasColumn` rendait le gabarit à zéro, et l'écran en tirait une
            // moyenne de 0. On lit désormais `quality_score` lui-même.
            ...$this->qualite($espace),
            // Les tailles du référentiel unique (`Taxonomy::TAILLES`), plus
            // aucune liste recopiée ici.
            'size_distribution' => $this->repartition('companies', $espace, 'size_category', self::taillesAZero()),
            'computed_at' => now()->utc()->toIso8601ZuluString(),
        ]));
    }

    /**
     * La qualité des fiches : répartition, moyenne RÉELLE, et part estimée
     * des scores PÉRIMÉS.
     *
     * Les seuils sont ceux de la colonne générée `quality_badge` (≥ 90
     * complète, ≥ 50 partielle, sinon basique). Un seul passage, servi par
     * l'index `idx_companies_workspace_score` (≈ 4 s sur la production,
     * dans le calcul différé de `stats()`).
     *
     * `quality_a_recalculer_pct` : sur un échantillon de 0,1 % des fiches,
     * la part dont le score stocké diffère du barème
     * (`company_quality_score_calcul`). Mesure du 2026-10-02 : ≈ 79 % — la
     * reprise `crm:recalculer-quality-score` n'a jamais été jouée. Tant que
     * cette part est forte, l'écran DIT « calcul en attente » au lieu d'une
     * moyenne fausse. `null` = estimation impossible (fonction absente).
     *
     * @return array<string, mixed>
     */
    private function qualite(string $espace): array
    {
        $resultat = [
            'quality_distribution' => ['complete' => 0, 'partielle' => 0, 'basique' => 0],
            'quality_avg' => null,
            'quality_scored' => null,
            'quality_a_recalculer_pct' => null,
        ];

        try {
            $l = DB::selectOne(
                'SELECT count(*) FILTER (WHERE quality_score >= 90) AS complete,
                        count(*) FILTER (WHERE quality_score >= 50 AND quality_score < 90) AS partielle,
                        count(*) FILTER (WHERE quality_score < 50) AS basique,
                        count(*) FILTER (WHERE quality_score > 0) AS notees,
                        round(avg(quality_score)) AS moyenne
                   FROM companies
                  WHERE workspace_id = ? AND deleted_at IS NULL',
                [$espace],
            );
            $resultat['quality_distribution'] = [
                'complete' => (int) $l->complete, 'partielle' => (int) $l->partielle, 'basique' => (int) $l->basique,
            ];
            $resultat['quality_avg'] = $l->moyenne === null ? null : (int) $l->moyenne;
            $resultat['quality_scored'] = (int) $l->notees;
        } catch (\Throwable $e) {
            Log::warning('dashboard: qualite indisponible', ['exception' => $e->getMessage()]);

            return $resultat;
        }

        // Sous PostgreSQL, une requête en échec AVORTE la transaction en cours
        // (25P02) : on vérifie que le barème existe avant de l'appeler.
        $bareme = DB::selectOne("SELECT count(*) AS n FROM pg_proc WHERE proname = 'company_quality_score_calcul'");
        if ((int) ($bareme->n ?? 0) === 0) {
            return $resultat;
        }

        try {
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
            $resultat['quality_a_recalculer_pct'] = $n === 0 ? 0.0 : round(100 * (int) $echantillon->ecarts / $n, 1);
        } catch (\Throwable $e) {
            Log::warning('dashboard: estimation des scores perimes indisponible', ['exception' => $e->getMessage()]);
        }

        return $resultat;
    }

    /**
     * Le gabarit complet, à zéro.
     *
     * `DashboardPage.tsx` lit toutes ces clés. Une clé absente rend `undefined`,
     * que l'écran affiche en `NaN` ou qui le fait planter au calcul de moyenne.
     * On part donc TOUJOURS du gabarit complet, et on l'enrichit.
     *
     * @return array<string, mixed>
     */
    private function gabaritVide(): array
    {
        return [
            'companies_total' => 0,
            'companies_enriched_24h' => 0,
            'contacts_qualified' => 0,
            'scraper_runs_24h' => 0,
            'llm_cost_eur_month' => 0,
            'quality_distribution' => ['complete' => 0, 'partielle' => 0, 'basique' => 0],
            // `null` = pas de moyenne connue : l'écran écrit « — », jamais 0.
            'quality_avg' => null,
            'quality_scored' => null,
            'quality_a_recalculer_pct' => null,
            'size_distribution' => self::taillesAZero(),
        ];
    }

    /** @return array<string, int> */
    private static function taillesAZero(): array
    {
        return array_fill_keys(array_keys(Taxonomy::TAILLES), 0);
    }

    /** Un compteur qui ne peut pas emporter l'écran avec lui. */
    private function compter(string $table, string $espace, ?callable $affiner = null): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        try {
            $q = DB::table($table)->where('workspace_id', $espace);

            if (Schema::hasColumn($table, 'deleted_at')) {
                $q->whereNull('deleted_at');
            }
            if ($affiner !== null) {
                $affiner($q);
            }

            return (int) $q->count();
        } catch (\Throwable $e) {
            // Constat A-015 : l'accueil s'effaçait entièrement dès qu'une seule
            // requête échouait. Un compteur en panne vaut mieux qu'un écran
            // blanc — mais il ne doit pas se taire.
            Log::warning('dashboard: compteur indisponible', [
                'table' => $table, 'exception' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * @param  array<string, int>  $gabarit
     * @return array<string, int>
     */
    private function repartition(string $table, string $espace, string $colonne, array $gabarit): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $colonne)) {
            return $gabarit;
        }

        try {
            $lignes = DB::table($table)
                ->where('workspace_id', $espace)
                ->whereNotNull($colonne)
                ->select($colonne, DB::raw('count(*) as n'))
                ->groupBy($colonne)
                ->get();

            foreach ($lignes as $ligne) {
                $cle = (string) $ligne->{$colonne};
                // On n'invente pas de catégorie : une valeur hors gabarit est
                // ajoutée telle quelle, l'écran la rendra sous son nom brut
                // plutôt que de la perdre en silence.
                $gabarit[$cle] = (int) $ligne->n;
            }
        } catch (\Throwable $e) {
            Log::warning('dashboard: repartition indisponible', [
                'table' => $table, 'colonne' => $colonne, 'exception' => $e->getMessage(),
            ]);
        }

        return $gabarit;
    }

    /**
     * ⚠️ Le sélecteur 7j / 30j / 90j de l'écran n'est PAS dans sa `queryKey`
     * React Query : changer de période ne relance aucune requête, seul le
     * sous-titre change. C'est un défaut du frontend, relevé par la passe P6, et
     * il n'est pas corrigé ici — mais l'API accepte et renvoie le libellé, pour
     * que le jour où le frontend sera réparé, le contrat existe déjà.
     */
    private function libellePeriode(mixed $periode): string
    {
        return match ((string) $periode) {
            '7d' => '7 derniers jours',
            '90d' => '90 derniers jours',
            default => '30 derniers jours',
        };
    }
}
