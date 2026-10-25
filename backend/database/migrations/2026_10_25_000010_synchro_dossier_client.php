<?php

use App\Crm\Taxonomy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SYNCHRO AUTOMATIQUE DU DOSSIER CLIENT console du site → CRM (25/10/2026).
 *
 * Décision de Will : la synchro part toute seule, sans clic. Cette migration
 * pose ce dont `DossierClientIngest` a besoin, et RIEN d'autre :
 *
 *  1. `companies.field_origins_at` et `contacts.field_origins_at` (JSONB,
 *     `{}` par défaut) : la DATE de chaque valeur de `field_origins`, pour que
 *     « le plus récent gagne » se tranche sans deviner ;
 *  2. `companies.console_client_ref` (`site:client:<uuid>`) et
 *     `companies.console_dossier_version` (dernière version appliquée — un
 *     message plus ancien est ignoré). `external_ref` ne suffisait pas : une
 *     fiche venue d'un formulaire y porte déjà `site:submission:<uuid>`.
 *     L'index unique partiel est posé par `2026_10_25_000020` (CONCURRENTLY) ;
 *  3. `activities_kind_check` reconstruit depuis `Taxonomy::ACTIVITY_KINDS`
 *     (gagne `dossier_client_synchro`), `NOT VALID` ; le `VALIDATE` est fait
 *     HORS transaction par `2026_10_25_000020` ;
 *  4. le déclencheur `refuser_suppression_fiche_protegee` réinstallé avec le
 *     cinquième tag protégé `src:console-client` (sa liste est FIGÉE,
 *     `FichesProtegeesTest` lit la fonction installée).
 *
 * PUREMENT ADDITIVE : aucune ligne n'est réécrite ni supprimée. Les colonnes
 * ont une valeur par défaut constante ou nulle : catalogue seulement, aucun
 * réécriture de table. `lock_timeout` borne l'attente des verrous.
 */
return new class extends Migration
{
    private const SLUGS_AVANT = ['src:scraping-evenements-pro', 'src:scraping-federations-2026', 'src:scraping-gofab-2026', 'src:scraping-presse-2026'];

    private const SLUGS = ['src:scraping-evenements-pro', 'src:scraping-federations-2026', 'src:scraping-gofab-2026', 'src:scraping-presse-2026', 'src:console-client'];

    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '30s'");

        DB::statement("ALTER TABLE companies ADD COLUMN IF NOT EXISTS field_origins_at JSONB NOT NULL DEFAULT '{}'::jsonb");
        DB::statement("ALTER TABLE contacts ADD COLUMN IF NOT EXISTS field_origins_at JSONB NOT NULL DEFAULT '{}'::jsonb");
        DB::statement('ALTER TABLE companies ADD COLUMN IF NOT EXISTS console_client_ref TEXT');
        DB::statement('ALTER TABLE companies ADD COLUMN IF NOT EXISTS console_dossier_version BIGINT');

        DB::statement("COMMENT ON COLUMN companies.field_origins_at IS 'Date ISO 8601 de chaque valeur de field_origins (meme cles). Sert a la regle le plus recent gagne de la synchro console.'");
        DB::statement("COMMENT ON COLUMN contacts.field_origins_at IS 'Date ISO 8601 de chaque valeur de field_origins (meme cles). Sert a la regle le plus recent gagne de la synchro console.'");
        DB::statement("COMMENT ON COLUMN companies.console_client_ref IS 'Client de la console du site (site:client:<uuid>), pose par la synchro du dossier client ou par l''arbitrage.'");
        DB::statement("COMMENT ON COLUMN companies.console_dossier_version IS 'Derniere version du dossier client appliquee : un message de version inferieure ou egale est ignore.'");

        DB::statement('ALTER TABLE activities DROP CONSTRAINT IF EXISTS activities_kind_check');
        DB::statement(
            'ALTER TABLE activities ADD CONSTRAINT activities_kind_check
             CHECK (kind IS NULL OR kind IN (' . Taxonomy::sqlList(Taxonomy::ACTIVITY_KINDS) . ')) NOT VALID',
        );
        // `VALIDATE` hors de cette transaction (`2026_10_25_000020`) : ici il
        // garderait le verrou EXCLUSIF de l'`ADD CONSTRAINT` pendant toute la
        // relecture d'`activities` (relecture #341, patron `2026_09_29_000002`).

        $this->installerProtection(self::SLUGS);
    }

    /**
     * Retour arrière PARTIEL et sans perte : le déclencheur retrouve sa liste
     * d'avant et le CHECK sa liste d'avant (les lignes `dossier_client_synchro`
     * gardent leur trace, `kind` seul est neutralisé — même geste que
     * `2026_09_24_000001`). Les colonnes RESTENT : elles portent des données
     * de clients, et rien n'est jamais supprimé.
     */
    public function down(): void
    {
        $avant = array_values(array_diff(Taxonomy::ACTIVITY_KINDS, ['dossier_client_synchro']));

        DB::statement("UPDATE activities SET kind = NULL WHERE kind = 'dossier_client_synchro'");
        DB::statement('ALTER TABLE activities DROP CONSTRAINT IF EXISTS activities_kind_check');
        DB::statement(
            'ALTER TABLE activities ADD CONSTRAINT activities_kind_check
             CHECK (kind IS NULL OR kind IN (' . Taxonomy::sqlList($avant) . '))',
        );

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
            SET search_path = pg_catalog, public
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
                        USING HINT = 'Organisateurs d''evenements, federations, participants GOFAB, presse ou clients de la console. Levee volontaire : SET LOCAL app.autoriser_suppression_protegee = ''on''.';
                END IF;

                RETURN OLD;
            END
            \$fn\$;
        SQL);
    }
};
