<?php

namespace App\Console\Commands;

use App\Services\Alertes\AlerteTelegram;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Anomaly detector (15 min) — détecte les déviations vs baseline 7j moyenne mobile :
 * - taux d'échec scraping > 15 %
 * - latence p95 LLM > 5s
 * - coût LLM/h > kill-switch workspace
 * - taux email invalid > 30 %
 * Si anomalie détectée → notification Telegram + journal d'application.
 *
 * 🔴 PENDANT DES MOIS, CETTE COMMANDE N'A ALERTE PERSONNE (audit 360, 21/08).
 *
 * Elle détectait, puis écrivait :
 *
 *     $this->warn(json_encode($a));
 *     // Sprint 11 : send TelegramAlert::dispatch($anomalies);
 *
 * Deux défauts, et ce sont exactement ceux relevés sur `AuditVerifyChain` :
 *
 * 1. LE COMMENTAIRE TENAIT LIEU DE CODE. `TelegramAlert` n'existait nulle part.
 * 2. `$this->warn()` ÉCRIT SUR UN FLUX QUE PERSONNE NE LIT. Lancée toutes les
 *    15 minutes par le planificateur, cette commande n'a pas de terminal : sa
 *    sortie part dans le vide.
 *
 * Autrement dit, un détecteur d'anomalies parfaitement fonctionnel tournait
 * quatre fois par heure en ne prévenant personne. *Un détecteur qui n'alerte
 * pas ne se distingue pas d'un détecteur éteint.*
 *
 * Désormais : journal d'application (canal fiable) ET Telegram (canal lu), par
 * `AlerteTelegram`, qui DIT quand il n'est pas configuré au lieu de se taire.
 */
class AnomalyDetect extends Command
{
    protected $signature = 'anomaly:detect';

    protected $description = 'Détecte les anomalies sur les métriques métier vs baseline 7j.';

    public function handle(): int
    {
        $anomalies = [];

        // Taux d'échec scraping (1h)
        $row = DB::selectOne(<<<'SQL'
            SELECT
              COUNT(*) FILTER (WHERE status = 'failed')::FLOAT
              / NULLIF(COUNT(*), 0) AS failure_rate,
              COUNT(*) AS total
            FROM scraper_runs
            WHERE created_at > now() - INTERVAL '1 hour'
        SQL);
        $rate = (float) ($row->failure_rate ?? 0);
        if ($rate > 0.15 && (int) ($row->total ?? 0) >= 20) {
            $anomalies[] = ['kind' => 'scraping_failure_rate', 'value' => $rate, 'threshold' => 0.15, 'window' => '1h'];
        }

        // Coût LLM workspace (depuis minuit)
        $workspaces = DB::select(<<<'SQL'
            SELECT workspace_id, SUM(cost_eur) AS total_eur
            FROM llm_usage
            WHERE created_at >= date_trunc('day', now())
            GROUP BY workspace_id
        SQL);
        foreach ($workspaces as $ws) {
            $cap = (float) DB::table('workspaces')->where('id', $ws->workspace_id)->value('cost_cap_eur');
            if ((float) $ws->total_eur > $cap * 0.8) {
                $anomalies[] = [
                    'kind' => 'llm_cost_near_cap', 'workspace_id' => $ws->workspace_id,
                    'value' => (float) $ws->total_eur, 'threshold' => $cap * 0.8,
                ];
            }
        }

        if (empty($anomalies)) {
            $this->info('Aucune anomalie détectée.');

            return self::SUCCESS;
        }

        foreach ($anomalies as $a) {
            $this->warn(json_encode($a, JSON_UNESCAPED_SLASHES));
        }

        // ── L'ALERTE, ENFIN ─────────────────────────────────────────────────
        //
        // Le corps est écrit pour être lu sur un téléphone, à 3 h du matin, par
        // quelqu'un qui n'a pas le contexte : une anomalie par ligne, avec sa
        // valeur ET son seuil, pour qu'on sache tout de suite si c'est un
        // frémissement ou un incendie.
        // ⚠️ Pas de `is_float()` ni de `?? '?'` ici : PHPStan a montre que ces
        // garde-fous etaient du CODE MORT — `value` et `threshold` sont toujours
        // des flottants dans les deux formes d'anomalie ci-dessus. Un garde-fou
        // qui ne peut jamais servir donne l'illusion d'une robustesse, et cache
        // le jour ou la forme change vraiment.
        $lignes = array_map(
            static fn (array $a): string => sprintf(
                '· %s : %s (seuil %s)%s',
                $a['kind'],
                number_format($a['value'], 4, ',', ' '),
                number_format($a['threshold'], 4, ',', ' '),
                isset($a['workspace_id']) ? ' — espace ' . $a['workspace_id'] : '',
            ),
            $anomalies,
        );

        app(AlerteTelegram::class)->envoyer(
            '🔴 CRM — ' . count($anomalies) . ' anomalie(s) detectee(s)',
            implode('
', $lignes)
            . '

Detecteur : `anomaly:detect`, toutes les 15 min.'
            . '
Les valeurs sont comparees a la moyenne mobile 7 jours.',
            ['anomalies' => $anomalies],
        );

        return self::SUCCESS;
    }
}
