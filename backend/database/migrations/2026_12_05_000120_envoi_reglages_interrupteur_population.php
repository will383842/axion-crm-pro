<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P2-A — l'interrupteur `envoi.population_personnes` ne s'allume pas par l'application : seul le propriétaire de la table (commande
 * du fondateur) peut poser ou modifier cette clé, et sa valeur vaut « true » ou « false ». Déclencheur seul, sans SECURITY DEFINER.
 * Aucune ligne créée : l'absence de ligne = éteint. `down()` inerte ; `up()` rejouable.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            SET LOCAL lock_timeout = '5s';
            CREATE OR REPLACE FUNCTION envoi_reglages_interrupteur_population() RETURNS trigger AS $f$
            BEGIN
              IF (NEW.cle = 'envoi.population_personnes' OR (TG_OP = 'UPDATE' AND OLD.cle = 'envoi.population_personnes'))
                 AND NOT pg_has_role(current_user, (SELECT c.relowner FROM pg_catalog.pg_class c WHERE c.oid = 'public.envoi_reglages'::regclass), 'MEMBER') THEN
                RAISE EXCEPTION 'envoi_reglages : envoi.population_personnes ne se pose que par le propriétaire (commande du fondateur)';
              END IF;
              IF NEW.cle = 'envoi.population_personnes' AND NEW.valeur NOT IN ('true', 'false') THEN
                RAISE EXCEPTION 'envoi_reglages : envoi.population_personnes vaut true ou false';
              END IF;
              RETURN NEW;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp;

            DROP TRIGGER IF EXISTS envoi_reglages_interrupteur_population ON envoi_reglages;
            CREATE TRIGGER envoi_reglages_interrupteur_population BEFORE INSERT OR UPDATE
                ON envoi_reglages FOR EACH ROW EXECUTE FUNCTION envoi_reglages_interrupteur_population();
        SQL);
    }

    /** Retour arrière INERTE : la garde est conservée. */
    public function down(): void {}
};
