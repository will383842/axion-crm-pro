<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * PONT-LINKEDIN PL-M0A — colonne envoi_campagnes.filtre_origine_contenu : {"mots": [...], "guides": [...]} restreint la cible
 * aux unités rattachées à une personne venue de ces mots / guides. NULL = aucun filtre. ADDITIVE : aucune ligne touchée, rien
 * n'est supprimé. La contrainte de `filtre_origine` (réseaux) n'est PAS élargie. Figée une fois la campagne reliée
 * (déclencheur à part : envoi_campagnes_garde() n'est PAS réécrite).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('ALTER TABLE envoi_campagnes ADD COLUMN IF NOT EXISTS filtre_origine_contenu JSONB NULL');
        // Remise à plat de la contrainte rejouable (up() rejouable) : ajout seul de données, aucune ligne touchée.
        DB::statement('ALTER TABLE envoi_campagnes DROP CONSTRAINT IF EXISTS envoi_campagnes_filtre_origine_contenu_forme');
        DB::statement(<<<'SQL'
            ALTER TABLE envoi_campagnes ADD CONSTRAINT envoi_campagnes_filtre_origine_contenu_forme CHECK (
              filtre_origine_contenu IS NULL OR (
                    jsonb_typeof(filtre_origine_contenu) = 'object'
                AND (filtre_origine_contenu - 'mots' - 'guides') = '{}'::jsonb
                AND (jsonb_exists(filtre_origine_contenu, 'mots') OR jsonb_exists(filtre_origine_contenu, 'guides'))
                AND (NOT jsonb_exists(filtre_origine_contenu, 'mots') OR (
                      jsonb_typeof(filtre_origine_contenu -> 'mots') = 'array'
                  AND jsonb_array_length(filtre_origine_contenu -> 'mots') BETWEEN 1 AND 10
                  AND NOT jsonb_path_exists(filtre_origine_contenu, '$.mots[*] ? (@.type() != "string" || !(@ like_regex "^[a-z0-9][a-z0-9._-]{0,63}$"))')))
                AND (NOT jsonb_exists(filtre_origine_contenu, 'guides') OR (
                      jsonb_typeof(filtre_origine_contenu -> 'guides') = 'array'
                  AND jsonb_array_length(filtre_origine_contenu -> 'guides') BETWEEN 1 AND 10
                  AND NOT jsonb_path_exists(filtre_origine_contenu, '$.guides[*] ? (@.type() != "string" || !(@ like_regex "^[a-z0-9][a-z0-9._-]{0,63}$"))')))
                AND octet_length(filtre_origine_contenu::text) <= 2048)
            ) NOT VALID
            SQL);
        DB::statement('ALTER TABLE envoi_campagnes VALIDATE CONSTRAINT envoi_campagnes_filtre_origine_contenu_forme');
        DB::statement(<<<'SQL'
            COMMENT ON COLUMN envoi_campagnes.filtre_origine_contenu IS 'PONT-LINKEDIN : {"mots": [...], "guides": [...]} restreint la cible aux unités rattachées à une personne venue de ces mots / guides. NULL = aucun filtre. Figé une fois la campagne reliée.'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION envoi_campagnes_filtre_origine_contenu_fige() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $fn$
            BEGIN
              IF NEW.filtre_origine_contenu IS DISTINCT FROM OLD.filtre_origine_contenu AND (OLD.sequence_id IS NOT NULL OR NEW.sequence_id IS NOT NULL) THEN
                RAISE EXCEPTION 'envoi_campagnes : le filtre d''origine (mots, guides) est figé une fois la campagne reliée';
              END IF;
              RETURN NEW;
            END
            $fn$
            SQL);
        DB::statement('DROP TRIGGER IF EXISTS envoi_campagnes_filtre_origine_contenu_fige ON envoi_campagnes');
        DB::statement('CREATE TRIGGER envoi_campagnes_filtre_origine_contenu_fige BEFORE UPDATE OF filtre_origine_contenu ON envoi_campagnes FOR EACH ROW EXECUTE FUNCTION envoi_campagnes_filtre_origine_contenu_fige()');

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            DB::statement('GRANT INSERT (filtre_origine_contenu), UPDATE (filtre_origine_contenu) ON public.envoi_campagnes TO "' . str_replace('"', '""', $roleApplicatif) . '"');
        }
    }

    /** Retour INERTE : on ne supprime jamais la colonne ni son contenu. */
    public function down(): void
    {
        // Aucune donnée de campagne ne se perd : volontairement vide.
    }
};
