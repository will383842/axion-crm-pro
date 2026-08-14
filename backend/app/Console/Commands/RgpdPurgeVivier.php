<?php

namespace App\Console\Commands;

use App\Crm\Taxonomy;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PURGE AUTOMATIQUE DU VIVIER (lot L4, plan §2.8.3 — doctrine CNIL CVthèque).
 *
 * Deux horloges, ni plus ni moins :
 *   - 2 ANS après la dernière interaction du candidat
 *     (`derniere_interaction_at + 2 ans`) : la fiche est SUPPRIMÉE — le
 *     consentement v2 promet « pendant 2 ans », le tenir est non négociable ;
 *   - `refuse` : 90 JOURS après le refus (la finalité d'étude est éteinte).
 *
 * La purge est une ÉCHÉANCE, pas une opposition : elle n'inscrit RIEN en
 * liste d'opposition — la personne peut recandidater librement demain.
 * La timeline de la personne part avec la fiche (les payloads portent des
 * PII). Chaque purge écrit son bilan dans la chaîne d'audit.
 *
 * GATE : `CRM_PURGE_ENABLED` (via config crm.purges_enabled). Construit
 * inerte, activé à la bascule finale comme le reste.
 */
class RgpdPurgeVivier extends Command
{
    protected $signature = 'rgpd:purge-vivier {--dry-run : Compte sans supprimer}';

    protected $description = 'Purge le vivier candidats : 2 ans après la dernière interaction, refusés à J+90 (CNIL CVthèque)';

    public function handle(AuditHashChain $audit): int
    {
        if (! filter_var(config('crm.purges_enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->error('CRM_PURGE_ENABLED n\'est pas à true — purge construite mais inerte (activation à la bascule finale).');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $vivierId = DB::table('workspaces')->where('slug', Taxonomy::VIVIER_WORKSPACE_SLUG)->value('id');
        if ($vivierId === null) {
            $this->error('Workspace vivier introuvable.');

            return self::FAILURE;
        }
        $vivierId = (string) $vivierId;

        $counts = WorkspaceContext::run($vivierId, function () use ($vivierId, $dryRun): array {
            // Fenêtre 2 ans : `derniere_interaction_at`, repli `created_at`
            // (une fiche sans interaction n'échappe pas à l'horloge).
            $expired = DB::table('candidates')
                ->where('workspace_id', $vivierId)
                ->whereRaw('COALESCE(derniere_interaction_at, created_at) < ?', [now()->subYears(2)]);

            $refused = DB::table('candidates')
                ->where('workspace_id', $vivierId)
                ->where('lifecycle_stage', 'refuse')
                ->where('updated_at', '<', now()->subDays(90));

            if ($dryRun) {
                return [
                    'expired_2y' => (clone $expired)->count(),
                    'refused_90d' => (clone $refused)->count(),
                    'activities' => 0,
                ];
            }

            return DB::transaction(function () use ($vivierId, $expired, $refused): array {
                $keys = (clone $expired)->pluck('person_key')
                    ->merge((clone $refused)->pluck('person_key'))
                    ->filter()
                    ->unique()
                    ->values();

                $expiredCount = $expired->delete();
                $refusedCount = $refused->delete();

                $activities = 0;
                if ($keys->isNotEmpty()) {
                    $activities = DB::table('activities')
                        ->where('workspace_id', $vivierId)
                        ->whereIn('person_key', $keys->all())
                        ->delete();
                }

                return [
                    'expired_2y' => $expiredCount,
                    'refused_90d' => $refusedCount,
                    'activities' => $activities,
                ];
            });
        });

        if (! $dryRun) {
            $audit->record([
                'workspace_id' => $vivierId,
                'user_id' => null,
                'method' => 'GDPR_PURGE_VIVIER',
                'path' => 'artisan rgpd:purge-vivier',
                'status' => 200,
                'ip' => null,
                'user_agent' => null,
                'payload_hash' => hash('sha256', json_encode($counts, JSON_THROW_ON_ERROR)),
            ]);
        }

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . sprintf(
            'Vivier : %d fiches à 2 ans, %d refusées à J+90, %d activités.',
            $counts['expired_2y'],
            $counts['refused_90d'],
            $counts['activities'],
        ));

        return self::SUCCESS;
    }
}
