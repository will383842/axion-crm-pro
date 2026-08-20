<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    public function stats(Request $r): JsonResponse
    {
        $espace = $this->espaceCourantOuNull();

        // Sans contexte d'espace : des zéros, jamais le total de tout le monde.
        if ($espace === null) {
            return response()->json($this->gabaritVide());
        }

        return response()->json(array_merge($this->gabaritVide(), [
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
            'quality_distribution' => $this->repartition('companies', $espace, 'quality_tier', [
                'complete' => 0, 'partielle' => 0, 'basique' => 0,
            ]),
            'size_distribution' => $this->repartition('companies', $espace, 'size_category', [
                'artisan' => 0, 'tpe' => 0, 'pme' => 0, 'eti' => 0, 'grande_entreprise' => 0,
            ]),
            'period_label' => $this->libellePeriode($r->query('period')),
        ]));
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
            'size_distribution' => [
                'artisan' => 0, 'tpe' => 0, 'pme' => 0, 'eti' => 0, 'grande_entreprise' => 0,
            ],
        ];
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
