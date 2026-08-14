<?php

namespace App\Console\Commands;

use App\Crm\Taxonomy;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PURGE BUSINESS (lot L4, plan §2.8.3) — standard CNIL prospection : les
 * données d'un prospect qui n'a JAMAIS interagi se périment 3 ANS après le
 * dernier contact émanant de lui (ou, à défaut de toute interaction, après
 * leur collecte).
 *
 * Ce qui est purgé : les FICHES PERSONNES (`contacts`) froides — base légale
 * `legitimate_interest_b2b`, aucune activité entrante rattachée à leur
 * personne, plus vieilles que 3 ans. Les `companies` restent : une personne
 * morale n'est pas une donnée personnelle (ses signaux non plus) ; seuls ses
 * humains le sont.
 *
 * Un contact qui a interagi (person_key présent dans la timeline, ou base
 * légale devenue precontractual/consent via l'ingestion L2) N'EST PAS touché :
 * son horloge est contractuelle/relationnelle, pas celle de la prospection.
 *
 * GATE : `CRM_PURGE_ENABLED` — construit inerte.
 */
class RgpdPurgeBusinessProspects extends Command
{
    protected $signature = 'rgpd:purge-business-prospects {--dry-run : Compte sans supprimer}';

    protected $description = 'Purge les fiches personnes froides (intérêt légitime, sans interaction) au-delà de 3 ans (CNIL prospection)';

    public function handle(AuditHashChain $audit): int
    {
        if (! filter_var(config('crm.purges_enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->error('CRM_PURGE_ENABLED n\'est pas à true — purge construite mais inerte (activation à la bascule finale).');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $businessIds = DB::table('workspaces')
            ->where('slug', '!=', Taxonomy::VIVIER_WORKSPACE_SLUG)
            ->whereNull('deleted_at')
            ->pluck('id');

        $total = 0;
        foreach ($businessIds as $workspaceId) {
            $workspaceId = (string) $workspaceId;

            $count = WorkspaceContext::run($workspaceId, function () use ($workspaceId, $dryRun): int {
                $stale = DB::table('contacts')
                    ->where('workspace_id', $workspaceId)
                    // Seule la prospection froide se périme par cette horloge.
                    ->where(function ($q): void {
                        $q->where('legal_basis', 'legitimate_interest_b2b')
                            ->orWhereNull('legal_basis');
                    })
                    ->where('created_at', '<', now()->subYears(3))
                    // Jamais une personne qui a interagi : sa timeline en fait foi.
                    ->whereNotExists(function ($q) use ($workspaceId): void {
                        $q->selectRaw('1')
                            ->from('activities')
                            ->whereColumn('activities.person_key', 'contacts.person_key')
                            ->where('activities.workspace_id', $workspaceId)
                            ->whereNotNull('contacts.person_key');
                    });

                return $dryRun ? $stale->count() : $stale->delete();
            });

            $this->line(($dryRun ? '[DRY-RUN] ' : '') . "workspace {$workspaceId} : {$count} fiches personnes purgées");
            $total += $count;

            if (! $dryRun && $count > 0) {
                $audit->record([
                    'workspace_id' => $workspaceId,
                    'user_id' => null,
                    'method' => 'GDPR_PURGE_BUSINESS',
                    'path' => 'artisan rgpd:purge-business-prospects',
                    'status' => 200,
                    'ip' => null,
                    'user_agent' => null,
                    'payload_hash' => hash('sha256', $workspaceId . '|' . $count),
                ]);
            }
        }

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . "Total : {$total}");

        return self::SUCCESS;
    }
}
