<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi S1-C (P1-LN-B) — imports de listes de refus : une ligne par import (auteur, empreinte du fichier, jeton de simulation,
 * compteurs entiers, horodatage). AJOUT SEUL. RLS forcée par espace, UPDATE/DELETE/TRUNCATE refusés, droits SELECT + INSERT.
 * Aucune adresse dans la base d'envoi : un CHECK refuse tout `@` dans les textes. Aucune colonne JSON.
 * Ajoute `envoi_oppositions_registre.import_id` (nullable) avec une clé étrangère COMPOSITE (workspace_id, import_id) :
 * un import d'un autre espace est refusé ; les lignes existantes du registre ne sont pas touchées. down() inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_oppositions_imports (
                id                UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                workspace_id      UUID        NOT NULL REFERENCES workspaces(id),
                auteur            TEXT        NOT NULL CHECK (char_length(auteur) BETWEEN 1 AND 100 AND position('@' IN auteur) = 0),
                empreinte_fichier TEXT        NOT NULL CHECK (empreinte_fichier ~ '^[0-9a-f]{64}$'),
                jeton_simulation  UUID        NOT NULL,
                nb_lignes         INTEGER     NOT NULL DEFAULT 0 CHECK (nb_lignes >= 0),
                nb_adresses       INTEGER     NOT NULL DEFAULT 0 CHECK (nb_adresses >= 0),
                nb_domaines       INTEGER     NOT NULL DEFAULT 0 CHECK (nb_domaines >= 0),
                nb_organisations  INTEGER     NOT NULL DEFAULT 0 CHECK (nb_organisations >= 0),
                nb_ignorees       INTEGER     NOT NULL DEFAULT 0 CHECK (nb_ignorees >= 0),
                cree_le           TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT envoi_oppositions_imports_jeton_unique UNIQUE (workspace_id, jeton_simulation),
                CONSTRAINT envoi_oppositions_imports_ws_id_unique UNIQUE (workspace_id, id)
            );
            ALTER TABLE envoi_oppositions_imports ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_oppositions_imports FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_oppositions_imports_workspace_isolation ON envoi_oppositions_imports;
            CREATE POLICY envoi_oppositions_imports_workspace_isolation ON envoi_oppositions_imports FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            CREATE OR REPLACE FUNCTION envoi_oppositions_imports_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_oppositions_imports : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_oppositions_imports_ajout_seul ON envoi_oppositions_imports;
            CREATE TRIGGER envoi_oppositions_imports_ajout_seul BEFORE UPDATE OR DELETE ON envoi_oppositions_imports
                FOR EACH ROW EXECUTE FUNCTION envoi_oppositions_imports_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_oppositions_imports_pas_de_truncate ON envoi_oppositions_imports;
            CREATE TRIGGER envoi_oppositions_imports_pas_de_truncate BEFORE TRUNCATE ON envoi_oppositions_imports
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_oppositions_imports_ajout_seul();

            ALTER TABLE envoi_oppositions_registre ADD COLUMN IF NOT EXISTS import_id UUID NULL;
            DO $c$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'envoi_oppositions_registre_import_fk') THEN
                    ALTER TABLE envoi_oppositions_registre ADD CONSTRAINT envoi_oppositions_registre_import_fk
                        FOREIGN KEY (workspace_id, import_id) REFERENCES envoi_oppositions_imports (workspace_id, id);
                END IF;
            END $c$;
        SQL);

        $nom = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($nom !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$nom]) !== null) {
            $role = '"' . str_replace('"', '""', $nom) . '"';
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.envoi_oppositions_imports FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_oppositions_imports TO ' . $role);
        }
    }

    /** Retour arrière INERTE : l'historique des imports et la colonne du registre sont conservés. */
    public function down(): void {}
};
