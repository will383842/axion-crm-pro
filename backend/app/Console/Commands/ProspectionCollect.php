<?php

namespace App\Console\Commands;

use App\Contracts\InseeClient;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Récupère (DÉCOUVERTE SEULE, sans enrichissement) toutes les entreprises d'un
 * département via l'API INSEE Sirene, en sauvegardant au fil de l'eau (résilient
 * aux timeouts). L'enrichissement se lance séparément ensuite.
 *
 * Ex : `php artisan prospection:collect 38`            → tout l'Isère
 *      `php artisan prospection:collect 38 --limit=500`→ 500 premières
 *      `php artisan prospection:collect 38 --req-delay=150` → plan authentifié 500/min
 */
class ProspectionCollect extends Command
{
    protected $signature = 'prospection:collect '
        . '{department : code département (ex 38, 2A, 971)} '
        . '{--limit=0 : nombre max (0 = tout le département)} '
        . '{--workspace= : UUID du workspace cible (défaut = 1er)} '
        . '{--req-delay=2100 : ms entre requêtes INSEE (2100≈30/min plan public ; 150≈500/min plan authentifié)}';

    protected $description = 'Récupère toutes les entreprises d\'un département (découverte INSEE seule, sans enrichissement).';

    public function handle(InseeClient $insee): int
    {
        $dept = trim((string) $this->argument('department'));
        if ($dept === '') {
            $this->error('Département requis (ex : 38).');
            return self::FAILURE;
        }
        $limit = (int) $this->option('limit');
        $delay = (int) $this->option('req-delay');

        $workspaceId = $this->option('workspace')
            ?: DB::table('workspaces')->orderBy('created_at')->value('id');
        if (! $workspaceId) {
            $this->error('Aucun workspace cible (--workspace=UUID).');
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Récupération INSEE — département %s (limite %s) → workspace %s…',
            $dept,
            $limit > 0 ? $limit : 'tout',
            substr((string) $workspaceId, 0, 8),
        ));

        $count = 0;
        $new = 0;
        $start = microtime(true);

        foreach ($insee->iterateByCriteria(['department' => $dept, 'req_delay_ms' => $delay]) as $data) {
            if ($data->siren === '') {
                continue;
            }

            $company = Company::query()->updateOrCreate(
                ['workspace_id' => $workspaceId, 'siren' => $data->siren],
                [
                    'denomination'     => $data->denomination,
                    'naf'              => $data->naf,
                    'legal_form'       => $data->legalForm,
                    'effectif_range'   => $data->effectifRange,
                    'discovery_source' => 'insee',
                    'department_code'  => $dept,
                ],
            );
            if ($company->wasRecentlyCreated) {
                $new++;
            }
            $count++;

            if ($count % 1000 === 0) {
                $elapsed = round(microtime(true) - $start);
                $this->info("  … {$count} traitées ({$new} nouvelles) — {$elapsed}s");
            }
            if ($limit > 0 && $count >= $limit) {
                break;
            }
        }

        $elapsed = round(microtime(true) - $start);
        $this->info("✅ Terminé : {$count} entreprises ({$new} nouvelles) pour le dépt {$dept} en {$elapsed}s.");
        $this->line("Enrichissement (emails/tél/dirigeants) à lancer séparément.");
        return self::SUCCESS;
    }
}
