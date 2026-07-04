<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill : classe la taille (tpe/pme/eti/grande_entreprise) des entreprises
 * DÉJÀ récupérées, à partir de la tranche d'effectif INSEE stockée
 * (`effectif_range`). Instantané (requête SQL) — évite de re-collecter.
 */
class ProspectionReclassifySize extends Command
{
    protected $signature = 'prospection:reclassify-size {--all : reclasse aussi celles déjà classées}';

    protected $description = 'Classe la taille (TPE/PME/ETI/GE) des entreprises depuis la tranche d\'effectif INSEE.';

    public function handle(): int
    {
        $where = $this->option('all')
            ? '1=1'
            : "(size_category IS NULL OR size_category = '')";

        $affected = DB::update(<<<SQL
            UPDATE companies SET size_category = CASE
                -- Catégorie OFFICIELLE INSEE (calculée sur tout le groupe : effectif
                -- + CA + bilan) — prioritaire, bien plus juste que le seul effectif du siège.
                WHEN metadata->>'categorie_entreprise' = 'GE'  THEN 'grande_entreprise'
                WHEN metadata->>'categorie_entreprise' = 'ETI' THEN 'eti'
                WHEN metadata->>'categorie_entreprise' = 'PME'
                     AND effectif_range IN ('11','12','21','22','31') THEN 'pme'
                WHEN metadata->>'categorie_entreprise' = 'PME' THEN 'tpe'
                -- Repli sur l'effectif du siège (catégorie absente, fréquent pour les micro).
                WHEN effectif_range IN ('11','12','21','22','31') THEN 'pme'
                WHEN effectif_range IN ('32','41','42','51')      THEN 'eti'
                WHEN effectif_range IN ('52','53')                THEN 'grande_entreprise'
                ELSE 'tpe'
            END
            WHERE {$where}
        SQL);

        $this->info("✅ {$affected} entreprises classées par taille (TPE/PME/ETI/GE).");
        return self::SUCCESS;
    }
}
