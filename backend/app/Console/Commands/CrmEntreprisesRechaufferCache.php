<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\CompaniesController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * RÉCHAUFFE LE CACHE DE L'ÉCRAN « ENTREPRISES » APRÈS UN DÉPLOIEMENT.
 *
 * Chaque déploiement lance `cache:clear` : les indicateurs
 * (`crm:companies:stats:v2:…`) et le total de la liste par défaut
 * (`crm:liste:total:v1:…`) repartent à froid, et les premiers affichages
 * payaient des comptages de plusieurs dizaines de secondes sur ≈ 4,4 M de
 * fiches (page figée sur ses squelettes, cf.
 * `docs/DIAG-LISTE-ENTREPRISES-2026-10-26.md`). Cette commande les recalcule,
 * pour chaque espace actif, avant que le premier opérateur n'ouvre la page.
 *
 * Elle ne touche à aucune donnée : elle lit et remplit le cache. Un échec sur
 * un espace n'empêche pas les suivants ; le code de sortie le signale.
 */
class CrmEntreprisesRechaufferCache extends Command
{
    protected $signature = 'crm:entreprises:rechauffer-cache
                            {--workspace= : Identifiant de l espace (par défaut : tous les espaces actifs)}';

    protected $description = 'Recalcule les indicateurs et le total de la liste par défaut de l écran Entreprises (à lancer après cache:clear).';

    public function handle(CompaniesController $controleur): int
    {
        $designation = $this->option('workspace');
        $espaces = is_string($designation) && $designation !== ''
            ? [$designation]
            : DB::table('workspaces')->where('is_active', true)->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        $echecs = 0;
        foreach ($espaces as $espace) {
            try {
                $stats = $controleur->rechaufferStats($espace);
                $total = $controleur::rechaufferTotalDefaut($espace);
                $this->info("Espace {$espace} : indicateurs " . ($stats ? 'recalculés' : 'déjà en cours ou en cache') . ", total par défaut {$total}.");
            } catch (\Throwable $e) {
                $echecs++;
                $this->error("Espace {$espace} : échec du réchauffage ({$e->getMessage()}).");
            }
        }

        return $echecs === 0 ? self::SUCCESS : self::FAILURE;
    }
}
