<?php

use App\Crm\Taxonomy;
use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LES FICHES GOFAB 2026 DEVIENNENT PROTÉGÉES (2026-09-29).
 *
 * Will, après l'import des participants du salon GOFAB : « il ne faut surtout
 * pas perdre ces contacts ». Même régime que les organisateurs d'événements et
 * les fédérations : `FichesProtegees::TAGS` gagne `src:scraping-gofab-2026`,
 * et le déclencheur de la base — dont la liste est FIGÉE — est réinstallé avec
 * les trois tags (`FichesProtegeesTest` lit la fonction installée).
 *
 * Le seeder est rejoué pour mettre à jour la `legal_note` de la source.
 */
return new class extends Migration
{
    private const SLUGS_AVANT = ['src:scraping-evenements-pro', 'src:scraping-federations-2026'];

    private const SLUGS = ['src:scraping-evenements-pro', 'src:scraping-federations-2026', 'src:scraping-gofab-2026'];

    public function up(): void
    {
        $this->installerProtection(self::SLUGS);

        (new ScrapingSourcesSeeder)->run();
    }

    public function down(): void
    {
        $this->installerProtection(self::SLUGS_AVANT);
    }

    /** @param  list<string>  $slugs */
    private function installerProtection(array $slugs): void
    {
        $liste = Taxonomy::sqlList($slugs);

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION public.refuser_suppression_fiche_protegee()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_catalog
            AS \$fn\$
            BEGIN
                IF COALESCE(current_setting('app.autoriser_suppression_protegee', true), '') = 'on' THEN
                    RETURN OLD;
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM   public.company_tag ct
                    JOIN   public.tags t ON t.id = ct.tag_id
                    WHERE  ct.company_id = OLD.id
                    AND    t.slug IN ({$liste})
                ) THEN
                    RAISE EXCEPTION 'fiche_protegee : suppression refusee (company_id=%)', OLD.id
                        USING HINT = 'Organisateurs d''evenements, federations ou participants GOFAB. Levee volontaire : SET LOCAL app.autoriser_suppression_protegee = ''on''.';
                END IF;

                RETURN OLD;
            END
            \$fn\$;
        SQL);
    }
};
