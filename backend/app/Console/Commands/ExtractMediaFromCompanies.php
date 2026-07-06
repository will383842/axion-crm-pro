<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Extrait les MÉDIAS déjà présents dans la base des ~4,3M entreprises (par code
 * NAF) vers la table `media`. Un média = une company éditrice (source
 * 'naf-extract'), rattachée par company_id.
 *
 * Scalable & idempotent : un unique INSERT ... SELECT côté Postgres (aucune
 * boucle PHP), le LEFT JOIN exclut ce qui est déjà extrait → REPRENABLE, et
 * ré-exécutable pour capter les nouveaux sites trouvés par l'enrichissement.
 *
 * Mapping NAF → media_type (la périodicité fine viendra de la CPPAP en L2) :
 *   5813 journaux · 5814 revues · 5819 autres édition · 6010 radio · 6020 TV
 *   6391 agences de presse · 6312 portails web · 5911/5912 prod. audiovisuelle
 */
class ExtractMediaFromCompanies extends Command
{
    protected $signature = 'media:extract-from-companies';

    protected $description = 'Extrait les médias (par NAF) depuis la table companies vers la table media (idempotent).';

    public function handle(): int
    {
        $sql = <<<'SQL'
            INSERT INTO media (
                workspace_id, company_id, siren, name, media_type,
                department_code, region_code, city, postcode,
                website, website_status, email, phone,
                enrich_status, source, created_at, updated_at
            )
            SELECT
                c.workspace_id, c.id, c.siren, c.denomination,
                CASE
                    WHEN c.n LIKE '5813%' THEN 'presse_journal'
                    WHEN c.n LIKE '5814%' THEN 'presse_revue'
                    WHEN c.n LIKE '5819%' THEN 'presse_autre'
                    WHEN c.n LIKE '6010%' THEN 'radio'
                    WHEN c.n LIKE '6020%' THEN 'tv'
                    WHEN c.n LIKE '6391%' THEN 'agence_presse'
                    WHEN c.n LIKE '6312%' THEN 'portail_web'
                    WHEN c.n LIKE '5911%' OR c.n LIKE '5912%' THEN 'production_audiovisuelle'
                END,
                c.department_code, c.region_code, c.city_name, c.postcode,
                c.website,
                CASE WHEN c.website IS NOT NULL THEN 'found' ELSE 'pending' END,
                c.email_generic, c.phone,
                'pending', 'naf-extract', now(), now()
            FROM (
                SELECT *, regexp_replace(upper(coalesce(naf,'')), '[^0-9A-Z]', '', 'g') AS n
                FROM companies
                WHERE deleted_at IS NULL AND denomination IS NOT NULL
            ) c
            LEFT JOIN media m ON m.company_id = c.id AND m.source = 'naf-extract'
            WHERE m.id IS NULL
              AND c.n ~ '^(5813|5814|5819|6010|6020|6391|6312|5911|5912)'
        SQL;

        $this->info('Extraction des médias depuis companies (par NAF)…');
        $inserted = DB::affectingStatement($sql);
        $this->info("✓ {$inserted} médias extraits/ajoutés.");

        $total = DB::table('media')->count();
        $withSite = DB::table('media')->whereNotNull('website')->count();
        $this->info("Total médias en base : {$total} (dont {$withSite} avec un site web).");

        return self::SUCCESS;
    }
}
