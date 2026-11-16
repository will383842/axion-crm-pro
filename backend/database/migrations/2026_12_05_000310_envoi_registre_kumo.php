<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi C3-0 — registre durable de l'adaptateur Kumo (T4) : injections par (espace, clé d'idempotence), état par espace,
 * erreurs 5xx en ajout seul. DELETE/TRUNCATE refusés partout ; `seq` figé une fois posé ; `envoi_kumo_5xx` sans UPDATE
 * (la remise à zéro écrit `remise_5xx_le`, elle n'efface rien). RLS FORCE par espace. Aucune adresse stockée.
 * AJOUT SEUL, INERTE : aucune lecture ni écriture applicative dans ce lot.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isolation = static fn (string $t): string => "
            ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY;
            ALTER TABLE {$t} FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS {$t}_workspace_isolation ON {$t};
            CREATE POLICY {$t}_workspace_isolation ON {$t} FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            DROP TRIGGER IF EXISTS {$t}_pas_de_truncate ON {$t};
            CREATE TRIGGER {$t}_pas_de_truncate BEFORE TRUNCATE ON {$t}
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_kumo_registre_garde();
        ";

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION envoi_kumo_registre_garde() RETURNS trigger AS $f$
            BEGIN
                IF TG_OP = 'UPDATE' AND TG_TABLE_NAME = 'envoi_kumo_injections' THEN
                    IF NEW.workspace_id IS DISTINCT FROM OLD.workspace_id OR NEW.cle_idempotence IS DISTINCT FROM OLD.cle_idempotence
                       OR NEW.id_injection IS DISTINCT FROM OLD.id_injection THEN
                        RAISE EXCEPTION 'envoi_kumo_injections : identité figée';
                    END IF;
                    IF OLD.seq IS NOT NULL AND NEW.seq IS DISTINCT FROM OLD.seq THEN
                        RAISE EXCEPTION 'envoi_kumo_injections : seq figé une fois posé';
                    END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'UPDATE' AND TG_TABLE_NAME = 'envoi_kumo_etat' THEN
                    IF NEW.workspace_id IS DISTINCT FROM OLD.workspace_id THEN
                        RAISE EXCEPTION 'envoi_kumo_etat : identité figée';
                    END IF;
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION '% : % refusé (ajout seul)', TG_TABLE_NAME, TG_OP;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;

            CREATE TABLE IF NOT EXISTS envoi_kumo_injections (
                workspace_id    UUID     NOT NULL REFERENCES workspaces(id),
                cle_idempotence TEXT     NOT NULL CHECK (char_length(cle_idempotence) BETWEEN 1 AND 200),
                id_injection    UUID     NOT NULL,
                etat            TEXT     NOT NULL CHECK (etat IN ('a_injecter', 'injection_en_cours', 'accepte', 'a_verifier', 'reporte', 'echec')),
                reporte_au      BIGINT,
                instant         BIGINT   NOT NULL,
                jeton           CHAR(32) NOT NULL CHECK (jeton ~ '^[0-9a-f]{32}$'),
                code            SMALLINT CHECK (code IS NULL OR code BETWEEN 200 AND 599),
                seq             BIGINT,
                PRIMARY KEY (workspace_id, cle_idempotence)
            );
            CREATE TABLE IF NOT EXISTS envoi_kumo_etat (
                workspace_id  UUID    PRIMARY KEY REFERENCES workspaces(id),
                en_pause      BOOLEAN NOT NULL DEFAULT false,
                derniere_seq  BIGINT  NOT NULL DEFAULT 0,
                remise_5xx_le BIGINT  NOT NULL DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS envoi_kumo_5xx (
                id           BIGSERIAL PRIMARY KEY,
                workspace_id UUID      NOT NULL REFERENCES workspaces(id),
                instant      BIGINT    NOT NULL
            );
            CREATE INDEX IF NOT EXISTS envoi_kumo_5xx_ws_instant ON envoi_kumo_5xx (workspace_id, instant);

            DROP TRIGGER IF EXISTS envoi_kumo_injections_garde ON envoi_kumo_injections;
            CREATE TRIGGER envoi_kumo_injections_garde BEFORE UPDATE OR DELETE ON envoi_kumo_injections
                FOR EACH ROW EXECUTE FUNCTION envoi_kumo_registre_garde();
            DROP TRIGGER IF EXISTS envoi_kumo_etat_garde ON envoi_kumo_etat;
            CREATE TRIGGER envoi_kumo_etat_garde BEFORE UPDATE OR DELETE ON envoi_kumo_etat
                FOR EACH ROW EXECUTE FUNCTION envoi_kumo_registre_garde();
            DROP TRIGGER IF EXISTS envoi_kumo_5xx_garde ON envoi_kumo_5xx;
            CREATE TRIGGER envoi_kumo_5xx_garde BEFORE UPDATE OR DELETE ON envoi_kumo_5xx
                FOR EACH ROW EXECUTE FUNCTION envoi_kumo_registre_garde();
            COMMENT ON TABLE envoi_kumo_injections IS 'Envoi C3-0 : registre durable des injections Kumo. Ni DELETE ni TRUNCATE ; seq fige.';
            COMMENT ON TABLE envoi_kumo_etat IS 'Envoi C3-0 : etat Kumo par espace (pause, derniere seq, remise 5xx). Ni DELETE ni TRUNCATE.';
            COMMENT ON TABLE envoi_kumo_5xx IS 'Envoi C3-0 : erreurs 5xx Kumo, ajout seul.';
        SQL);
        foreach (['envoi_kumo_injections', 'envoi_kumo_etat', 'envoi_kumo_5xx'] as $t) {
            DB::unprepared($isolation($t));
        }

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE ALL ON public.envoi_kumo_injections, public.envoi_kumo_etat, public.envoi_kumo_5xx FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT, UPDATE ON public.envoi_kumo_injections, public.envoi_kumo_etat TO ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_kumo_5xx TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_kumo_5xx_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : le registre durable est conservé (le retirer effacerait des injections). */
    public function down(): void {}
};
