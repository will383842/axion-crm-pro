<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi FINI-M0B — boîte d'expédition d'une inscription. AJOUT SEUL : colonne `boite_id` NULL (« pas encore choisie » : toutes
 * les inscriptions existantes), clé composite (workspace_id, boite_id) vers `envoi_boites` (une inscription ne peut pas viser
 * la boîte d'un autre espace). Une fois posée, la boîte ne change plus (un fil garde son expéditeur) : déclencheur.
 * `envoi_inscriptions` porte déjà UNIQUE (workspace_id, id) (000033). Migration seule : rien ne la lit. `down()` inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE envoi_inscriptions ADD COLUMN IF NOT EXISTS boite_id UUID NULL;
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'envoi_inscriptions_boite_workspace_fk' AND conrelid = 'envoi_inscriptions'::regclass) THEN
                    ALTER TABLE envoi_inscriptions ADD CONSTRAINT envoi_inscriptions_boite_workspace_fk
                        FOREIGN KEY (workspace_id, boite_id) REFERENCES envoi_boites (workspace_id, id);
                END IF;
            END $$;
            CREATE OR REPLACE FUNCTION envoi_inscriptions_boite_figee() RETURNS trigger AS $f$
            BEGIN
                IF OLD.boite_id IS NOT NULL AND NEW.boite_id IS DISTINCT FROM OLD.boite_id THEN
                    RAISE EXCEPTION 'envoi_inscriptions.boite_id est figee une fois posee';
                END IF;
                RETURN NEW;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_inscriptions_boite_figee ON envoi_inscriptions;
            CREATE TRIGGER envoi_inscriptions_boite_figee BEFORE UPDATE ON envoi_inscriptions
                FOR EACH ROW EXECUTE FUNCTION envoi_inscriptions_boite_figee();
        SQL);
    }

    /** Retour arrière INERTE : la colonne et la clé restent. */
    public function down(): void {}
};
