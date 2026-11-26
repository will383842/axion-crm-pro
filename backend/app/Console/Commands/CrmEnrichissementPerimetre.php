<?php

namespace App\Console\Commands;

use App\Crm\Sites\PerimetreEnrichissement;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Compteurs du périmètre de la recherche de site, par lot (1 à 7).
 * LECTURE SEULE : un `SELECT count(*)` par lot, aucune écriture.
 */
class CrmEnrichissementPerimetre extends Command
{
    protected $signature = 'crm:enrichissement:perimetre';

    protected $description = 'Compte les fiches du périmètre de la recherche de site, lot par lot. Lecture seule.';

    public function handle(): int
    {
        $slug = (string) config('crm.ingest.business_workspace', 'axion-ia');
        $espace = DB::table('workspaces')->where('slug', $slug)->whereNull('deleted_at')->value('id');
        if ($espace === null) {
            $this->error('Espace business introuvable.');

            return self::FAILURE;
        }

        $lignes = WorkspaceContext::run((string) $espace, function () use ($espace): array {
            $lignes = [];
            $total = 0;
            foreach (PerimetreEnrichissement::LOTS as $lot) {
                [$sql, $liaisons] = PerimetreEnrichissement::compteSql((string) $espace, $lot);
                $n = (int) (DB::selectOne($sql, $liaisons)->n ?? 0);
                $total += $n;
                $lignes[] = [$lot, $n];
            }
            $lignes[] = ['total', $total];

            return $lignes;
        });

        $this->table(['lot', 'fiches'], $lignes);

        return self::SUCCESS;
    }
}
