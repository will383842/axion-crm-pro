<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * X1-L2-bis, lot L2b-A — colonne envoi_campagnes.filtre_origine : {"reseaux": [...]} restreint la cible aux unités rattachées
 * à une personne venue de ces réseaux. NULL = aucun filtre. ADDITIVE : aucune ligne touchée, rien n'est supprimé.
 * Figée une fois la campagne reliée (déclencheur à part : envoi_campagnes_garde() n'est PAS réécrite).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('ALTER TABLE envoi_campagnes ADD COLUMN IF NOT EXISTS filtre_origine JSONB NULL');
        DB::statement('ALTER TABLE envoi_campagnes DROP CONSTRAINT IF EXISTS envoi_campagnes_filtre_origine_forme');
        DB::statement(<<<'SQL'
            ALTER TABLE envoi_campagnes ADD CONSTRAINT envoi_campagnes_filtre_origine_forme CHECK (
              filtre_origine IS NULL OR (
                    jsonb_typeof(filtre_origine) = 'object'
                AND jsonb_exists(filtre_origine, 'reseaux')
                AND (filtre_origine - 'reseaux') = '{}'::jsonb
                AND jsonb_typeof(filtre_origine -> 'reseaux') = 'array'
                AND jsonb_array_length(filtre_origine -> 'reseaux') BETWEEN 1 AND 10
                AND NOT jsonb_path_exists(filtre_origine, '$.reseaux[*] ? (@.type() != "string" || !(@ like_regex "^[a-z0-9][a-z0-9._-]{0,63}$"))')
                AND octet_length(filtre_origine::text) <= 1024)
            ) NOT VALID
            SQL);
        DB::statement('ALTER TABLE envoi_campagnes VALIDATE CONSTRAINT envoi_campagnes_filtre_origine_forme');
        DB::statement(<<<'SQL'
            COMMENT ON COLUMN envoi_campagnes.filtre_origine IS 'X1-L2-bis : {"reseaux": [...]} restreint la cible aux unités rattachées à une personne venue de ces réseaux. NULL = aucun filtre. Figé une fois la campagne reliée.'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION envoi_campagnes_filtre_origine_fige() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $fn$
            BEGIN
              IF NEW.filtre_origine IS DISTINCT FROM OLD.filtre_origine AND (OLD.sequence_id IS NOT NULL OR NEW.sequence_id IS NOT NULL) THEN
                RAISE EXCEPTION 'envoi_campagnes : le filtre d''origine est figé une fois la campagne reliée';
              END IF;
              RETURN NEW;
            END
            $fn$
            SQL);
        DB::statement('DROP TRIGGER IF EXISTS envoi_campagnes_filtre_origine_fige ON envoi_campagnes');
        DB::statement('CREATE TRIGGER envoi_campagnes_filtre_origine_fige BEFORE UPDATE OF filtre_origine ON envoi_campagnes FOR EACH ROW EXECUTE FUNCTION envoi_campagnes_filtre_origine_fige()');

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            DB::statement('GRANT INSERT (filtre_origine), UPDATE (filtre_origine) ON public.envoi_campagnes TO "' . str_replace('"', '""', $roleApplicatif) . '"');
        }
    }

    /** Retour INERTE : on ne supprime jamais la colonne ni son contenu. */
    public function down(): void
    {
        // Aucune donnée de campagne ne se perd : volontairement vide.
    }
};
