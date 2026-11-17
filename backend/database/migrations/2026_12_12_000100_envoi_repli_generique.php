<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi L0a (PLAN ADRESSES GÉNÉRALES, #680) — migration SEULE et INERTE : colonne `repli_generique` (défaut constant false, donc sans
 * réécriture), CHECK « un repli = population entreprises et UNE seule étape » posé NOT VALID puis VALIDATE, déclencheur de garde sur les
 * inscriptions (colonne figée ; une insertion `true` n'entre que si `envoi.repli_generique` vaut « true » dans l'espace : absent, faux,
 * illisible ou invisible par la RLS = refus) et déclencheur sur les réglages (`envoi.repli_generique` ne se pose que par le propriétaire,
 * « true » ou « false » ; `envoi.repli_generique_plafond_jour` : propriétaire, entier de 0 à 5).
 * Aucune ligne créée, aucune donnée touchée ni supprimée. Verrou court (lock_timeout) : échec = déploiement rouge, on relance.
 * `down()` inerte ; `up()` rejouable.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            SET LOCAL lock_timeout = '5s';
            ALTER TABLE envoi_inscriptions ADD COLUMN IF NOT EXISTS repli_generique BOOLEAN NOT NULL DEFAULT false;

            ALTER TABLE envoi_inscriptions DROP CONSTRAINT IF EXISTS envoi_inscriptions_repli_une_etape;
            ALTER TABLE envoi_inscriptions ADD CONSTRAINT envoi_inscriptions_repli_une_etape CHECK (
                NOT repli_generique OR (
                    population = 'entreprises'
                    AND CASE WHEN jsonb_typeof(version_figee -> 'etapes') = 'array'
                             THEN jsonb_array_length(version_figee -> 'etapes') = 1 ELSE false END
                )
            ) NOT VALID;
            ALTER TABLE envoi_inscriptions VALIDATE CONSTRAINT envoi_inscriptions_repli_une_etape;

            CREATE OR REPLACE FUNCTION envoi_inscriptions_repli_garde() RETURNS trigger AS $f$
            DECLARE ouvert BOOLEAN;
            BEGIN
              IF TG_OP = 'UPDATE' AND NEW.repli_generique IS DISTINCT FROM OLD.repli_generique THEN
                RAISE EXCEPTION 'envoi_inscriptions : repli_generique est figé';
              END IF;
              IF TG_OP = 'INSERT' AND NEW.repli_generique THEN
                SELECT (r.valeur = 'true') INTO ouvert FROM public.envoi_reglages r
                 WHERE r.workspace_id = NEW.workspace_id AND r.cle = 'envoi.repli_generique';
                IF ouvert IS NOT TRUE THEN
                  RAISE EXCEPTION 'envoi_inscriptions : repli sur adresse générale fermé dans cet espace (envoi.repli_generique)';
                END IF;
              END IF;
              RETURN NEW;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp;

            DROP TRIGGER IF EXISTS envoi_inscriptions_repli_garde ON envoi_inscriptions;
            CREATE TRIGGER envoi_inscriptions_repli_garde BEFORE INSERT OR UPDATE OF repli_generique
                ON envoi_inscriptions FOR EACH ROW EXECUTE FUNCTION envoi_inscriptions_repli_garde();

            CREATE OR REPLACE FUNCTION envoi_reglages_repli_generique() RETURNS trigger AS $f$
            DECLARE proprio BOOLEAN;
            BEGIN
              IF NEW.cle IN ('envoi.repli_generique', 'envoi.repli_generique_plafond_jour')
                 OR (TG_OP = 'UPDATE' AND OLD.cle IN ('envoi.repli_generique', 'envoi.repli_generique_plafond_jour')) THEN
                proprio := pg_has_role(current_user, (SELECT c.relowner FROM pg_catalog.pg_class c WHERE c.oid = 'public.envoi_reglages'::regclass), 'MEMBER');
                IF NOT proprio THEN
                  RAISE EXCEPTION 'envoi_reglages : envoi.repli_generique et son plafond ne se posent que par le propriétaire (commande du fondateur)';
                END IF;
              END IF;
              IF NEW.cle = 'envoi.repli_generique' AND NEW.valeur NOT IN ('true', 'false') THEN
                RAISE EXCEPTION 'envoi_reglages : envoi.repli_generique vaut true ou false';
              END IF;
              IF NEW.cle = 'envoi.repli_generique_plafond_jour' AND NEW.valeur !~ '^[0-5]$' THEN
                RAISE EXCEPTION 'envoi_reglages : envoi.repli_generique_plafond_jour est un entier de 0 à 5';
              END IF;
              RETURN NEW;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp;

            DROP TRIGGER IF EXISTS envoi_reglages_repli_generique ON envoi_reglages;
            CREATE TRIGGER envoi_reglages_repli_generique BEFORE INSERT OR UPDATE
                ON envoi_reglages FOR EACH ROW EXECUTE FUNCTION envoi_reglages_repli_generique();
        SQL);
    }

    /** Retour arrière INERTE : colonne, contrainte et gardes sont conservées. */
    public function down(): void {}
};
