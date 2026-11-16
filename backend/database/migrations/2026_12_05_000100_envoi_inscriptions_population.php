<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P2-A — inscriptions typées (migration SEULE et INERTE) : colonnes `population` (défaut constant « entreprises », donc sans
 * réécriture) et `personne_id`, deux CHECK (valeur, cohérence) posés NOT VALID puis VALIDATE, PUIS contact_id et entreprise_id deviennent
 * nullables. Déclencheur de garde : colonnes figées ; une inscription « personnes » n'entre que si le réglage `envoi.population_personnes`
 * de l'espace vaut « true » (absent, faux, illisible ou invisible par la RLS = refus). Aucune ligne créée, aucune donnée touchée.
 * Verrou court (lock_timeout) : échec = déploiement rouge, on relance. `down()` inerte ; `up()` rejouable.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            SET LOCAL lock_timeout = '5s';
            ALTER TABLE envoi_inscriptions ADD COLUMN IF NOT EXISTS population TEXT NOT NULL DEFAULT 'entreprises';
            ALTER TABLE envoi_inscriptions ADD COLUMN IF NOT EXISTS personne_id BIGINT NULL;

            ALTER TABLE envoi_inscriptions DROP CONSTRAINT IF EXISTS envoi_inscriptions_population_valeur;
            ALTER TABLE envoi_inscriptions ADD CONSTRAINT envoi_inscriptions_population_valeur
                CHECK (population IN ('entreprises', 'personnes')) NOT VALID;
            ALTER TABLE envoi_inscriptions VALIDATE CONSTRAINT envoi_inscriptions_population_valeur;

            ALTER TABLE envoi_inscriptions DROP CONSTRAINT IF EXISTS envoi_inscriptions_population_coherence;
            ALTER TABLE envoi_inscriptions ADD CONSTRAINT envoi_inscriptions_population_coherence CHECK (
                   (population = 'entreprises' AND contact_id IS NOT NULL AND entreprise_id IS NOT NULL AND personne_id IS NULL)
                OR (population = 'personnes'   AND personne_id IS NOT NULL AND contact_id IS NULL AND entreprise_id IS NULL)
            ) NOT VALID;
            ALTER TABLE envoi_inscriptions VALIDATE CONSTRAINT envoi_inscriptions_population_coherence;

            ALTER TABLE envoi_inscriptions ALTER COLUMN contact_id DROP NOT NULL;
            ALTER TABLE envoi_inscriptions ALTER COLUMN entreprise_id DROP NOT NULL;

            CREATE OR REPLACE FUNCTION envoi_inscriptions_population_garde() RETURNS trigger AS $f$
            DECLARE ouvert BOOLEAN;
            BEGIN
              IF TG_OP = 'UPDATE' AND (NEW.population IS DISTINCT FROM OLD.population OR NEW.personne_id IS DISTINCT FROM OLD.personne_id
                  OR NEW.contact_id IS DISTINCT FROM OLD.contact_id OR NEW.entreprise_id IS DISTINCT FROM OLD.entreprise_id) THEN
                RAISE EXCEPTION 'envoi_inscriptions : population, personne, contact et entreprise sont figés';
              END IF;
              IF NEW.population = 'personnes' THEN
                SELECT (r.valeur = 'true') INTO ouvert FROM public.envoi_reglages r
                 WHERE r.workspace_id = NEW.workspace_id AND r.cle = 'envoi.population_personnes';
                IF ouvert IS NOT TRUE THEN
                  RAISE EXCEPTION 'envoi_inscriptions : population personnes fermée dans cet espace (envoi.population_personnes)';
                END IF;
              END IF;
              RETURN NEW;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp;

            DROP TRIGGER IF EXISTS envoi_inscriptions_population_garde ON envoi_inscriptions;
            CREATE TRIGGER envoi_inscriptions_population_garde BEFORE INSERT OR UPDATE OF population, personne_id, contact_id, entreprise_id
                ON envoi_inscriptions FOR EACH ROW EXECUTE FUNCTION envoi_inscriptions_population_garde();
        SQL);
    }

    /** Retour arrière INERTE : colonnes, contraintes et garde sont conservées. */
    public function down(): void {}
};
