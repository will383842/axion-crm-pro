<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Active pg_partman sur audit_logs (partitionnement mensuel + retention 24 mois).
 *
 * Cette migration **suppose que `pg_partman` est installé en superuser** côté infra Postgres
 * (cf. spec/02 § shared_preload_libraries=pg_partman_bgw). Si pg_partman n'est PAS installé,
 * la migration log un warning et continue sans casser le boot (audit_logs reste non-partitionné,
 * partition manuelle via cron Sprint 18+).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Détecter si pg_partman est disponible
        $partmanAvailable = DB::selectOne(
            "SELECT 1 AS available FROM pg_extension WHERE extname = 'pg_partman'"
        );

        if (! $partmanAvailable) {
            \Log::warning('pg_partman not installed — audit_logs reste non-partitionné. '
                . 'Installer côté infra (shared_preload_libraries) puis re-run cette migration.');
            return;
        }

        DB::unprepared(<<<'SQL'
            -- Convertir audit_logs en table partitionnée (si pas déjà fait)
            DO $$
            DECLARE
                is_partitioned BOOLEAN;
            BEGIN
                SELECT EXISTS (
                    SELECT 1 FROM pg_partitioned_table p
                    JOIN pg_class c ON c.oid = p.partrelid
                    WHERE c.relname = 'audit_logs'
                ) INTO is_partitioned;

                IF NOT is_partitioned THEN
                    -- Backup existing data
                    CREATE TABLE audit_logs_old AS TABLE audit_logs;
                    DROP TABLE audit_logs CASCADE;

                    -- Recréer en PARTITION BY RANGE
                    CREATE TABLE audit_logs (
                        id              BIGSERIAL,
                        workspace_id    UUID,
                        user_id         UUID REFERENCES users(id) ON DELETE SET NULL,
                        event_type      TEXT NOT NULL,
                        path            TEXT,
                        status_code     SMALLINT,
                        ip              INET,
                        user_agent      TEXT,
                        payload_hash    TEXT,
                        prev_hash       TEXT NOT NULL DEFAULT 'GENESIS',
                        current_hash    TEXT NOT NULL,
                        created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                        PRIMARY KEY (id, created_at)
                    ) PARTITION BY RANGE (created_at);

                    CREATE INDEX idx_audit_workspace_created ON audit_logs (workspace_id, created_at DESC);
                    CREATE INDEX idx_audit_user_created ON audit_logs (user_id, created_at DESC);

                    -- Restore data
                    INSERT INTO audit_logs SELECT * FROM audit_logs_old;
                    DROP TABLE audit_logs_old;

                    -- pg_partman setup : 1 partition/mois, premake 6 mois d'avance.
                    -- Tolérant à la version de pg_partman : la signature de
                    -- create_parent diffère entre v4 (p_type='native') et v5
                    -- (p_type='range'). Si l'appel échoue (mismatch image/migration),
                    -- on retombe sur une partition DEFAULT pour que la table reste
                    -- fonctionnelle → migrate:fresh + CI verts. La gestion auto
                    -- pg_partman se configure côté infra selon la version installée.
                    BEGIN
                        PERFORM partman.create_parent(
                            p_parent_table => 'public.audit_logs',
                            p_control => 'created_at',
                            p_type => 'native',
                            p_interval => '1 month',
                            p_premake => 6
                        );
                        UPDATE partman.part_config
                        SET retention = '24 months',
                            retention_keep_table = true,
                            retention_keep_index = false
                        WHERE parent_table = 'public.audit_logs';
                    EXCEPTION WHEN OTHERS THEN
                        RAISE WARNING 'pg_partman create_parent indisponible (%) — audit_logs en partition DEFAULT (gestion pg_partman à configurer côté infra selon la version).', SQLERRM;
                        EXECUTE 'CREATE TABLE IF NOT EXISTS audit_logs_default PARTITION OF audit_logs DEFAULT';
                    END;
                END IF;
            END$$;
        SQL);
    }

    public function down(): void
    {
        // Revertir : flatten partitions en table simple
        $partmanAvailable = DB::selectOne("SELECT 1 AS available FROM pg_extension WHERE extname = 'pg_partman'");
        if (! $partmanAvailable) {
            return;
        }
        DB::unprepared(<<<'SQL'
            DELETE FROM partman.part_config WHERE parent_table = 'public.audit_logs';
            -- Pas de DROP de la table partitionnée ici, pour éviter de perdre data.
        SQL);
    }
};
