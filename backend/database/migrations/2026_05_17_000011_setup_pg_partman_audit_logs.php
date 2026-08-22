<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Active pg_partman sur audit_logs (partitionnement mensuel + retention 24 mois).
 *
 * Cette migration **suppose que `pg_partman` est installé en superuser** côté infra Postgres
 * (cf. spec/02 § shared_preload_libraries=pg_partman_bgw). Si pg_partman n'est PAS installé,
 * la migration log un warning et continue sans casser le boot (audit_logs reste non-partitionné,
 * partition manuelle via cron Sprint 18+).
 *
 * 🔴 ORDRE DES OPÉRATIONS — NE PAS REMETTRE L'`INSERT` AVANT LES PARTITIONS.
 * Jusqu'au 2026-08-18, la réinjection des lignes sauvegardées (`INSERT INTO
 * audit_logs SELECT * FROM audit_logs_old`) précédait la création de la moindre
 * partition. Toute base portant au moins une ligne d'audit — c'est-à-dire toute
 * base ayant servi — tombait sur :
 *
 *     SQLSTATE[23514]: Check violation: 7 ERROR:
 *       no partition of relation "audit_logs" found for row
 *     DETAIL:  Partition key of the failing row contains (created_at) = (…)
 *     CONTEXT: SQL statement "INSERT INTO audit_logs SELECT * FROM audit_logs_old"
 *
 * Reproduit le 2026-08-18 (cf. `_REPORTS/2026-08-18_RECONSTRUCTION-BASE.md`) ;
 * la garde `tests/Feature/Database/AuditLogsPartitionnementTest.php` rougit si
 * l'ordre est ré-inversé.
 *
 * 🔴 SCHÉMA `partman` — le code appelle `partman.create_parent()` depuis
 * l'origine, mais l'extension était créée sans clause `SCHEMA`, donc dans
 * `public`. L'appel échouait donc TOUJOURS et retombait sur la partition
 * DEFAULT du bloc `EXCEPTION` (commit de5e684 de juillet) : pg_partman n'a
 * jamais géré cette table. Le schéma est désormais résolu dynamiquement, et
 * `2026_08_18_100001_partman_dans_son_propre_schema` déplace l'extension.
 *
 * 🔴 SIGNATURE `create_parent` — pg_partman v5 n'accepte plus `p_type =>
 * 'native'` (v4) mais `'range'` / `'list'`. La version installée est lue dans
 * `pg_extension` et l'argument adapté ; le repli DEFAULT reste en place pour
 * qu'aucune combinaison image/migration ne puisse faire rougir un déploiement.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Détecter si pg_partman est disponible
        $partmanAvailable = DB::selectOne(
            "SELECT 1 AS available FROM pg_extension WHERE extname = 'pg_partman'",
        );

        if (! $partmanAvailable) {
            Log::warning('pg_partman not installed — audit_logs reste non-partitionné. '
                . 'Installer côté infra (shared_preload_libraries) puis re-run cette migration.');

            return;
        }

        DB::unprepared(<<<'SQL'
            -- Convertir audit_logs en table partitionnée (si pas déjà fait)
            DO $$
            DECLARE
                is_partitioned  BOOLEAN;
                v_ns            TEXT;
                v_majeure       INT;
                v_type          TEXT;
                v_defaut        BOOLEAN;
                v_max_id        BIGINT;
            BEGIN
                SELECT EXISTS (
                    SELECT 1 FROM pg_partitioned_table p
                    JOIN pg_class c ON c.oid = p.partrelid
                    WHERE c.relname = 'audit_logs'
                ) INTO is_partitioned;

                IF is_partitioned THEN
                    RETURN;
                END IF;

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
                    -- B16-013 : plus AUCUN défaut ici. Ce gabarit est celui dont
                    -- héritent toutes les partitions créées par pg_partman ; le
                    -- laisser porter un défaut réarmerait, sur chaque base
                    -- reconstruite, le piège que 2026_08_22_150000 désarme.
                    prev_hash       TEXT NOT NULL,
                    current_hash    TEXT NOT NULL,
                    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                    PRIMARY KEY (id, created_at)
                ) PARTITION BY RANGE (created_at);

                CREATE INDEX idx_audit_workspace_created ON audit_logs (workspace_id, created_at DESC);
                CREATE INDEX idx_audit_user_created ON audit_logs (user_id, created_at DESC);

                -- Schéma réel de l'extension (`partman` attendu ; `public` sur les
                -- bases antérieures à 2026_08_18_100001).
                SELECT n.nspname
                INTO   v_ns
                FROM   pg_extension e
                JOIN   pg_namespace n ON n.oid = e.extnamespace
                WHERE  e.extname = 'pg_partman';

                SELECT split_part(extversion, '.', 1)::INT
                INTO   v_majeure
                FROM   pg_extension
                WHERE  extname = 'pg_partman';

                -- v5 : 'range' / 'list'. v4 : 'native' / 'partman'.
                v_type := CASE WHEN v_majeure >= 5 THEN 'range' ELSE 'native' END;

                -- Un `migrate:fresh` a pu laisser une configuration orpheline :
                -- les tables de `public` ont disparu, pas le schéma `partman`.
                BEGIN
                    EXECUTE format(
                        'DELETE FROM %I.part_config_sub WHERE sub_parent = %L',
                        v_ns, 'public.audit_logs'
                    );
                    EXECUTE format(
                        'DELETE FROM %I.part_config WHERE parent_table = %L',
                        v_ns, 'public.audit_logs'
                    );
                    EXECUTE format('DROP TABLE IF EXISTS %I.template_public_audit_logs', v_ns);
                EXCEPTION WHEN OTHERS THEN
                    RAISE NOTICE 'Nettoyage part_config ignoré (%).', SQLERRM;
                END;

                -- pg_partman setup : 1 partition/mois, premake 6 mois d'avance.
                -- Si l'appel échoue (extension hors de son schéma, signature d'une
                -- autre version majeure, droits insuffisants), on retombe sur la
                -- seule partition DEFAULT : la table reste fonctionnelle et le
                -- déploiement reste vert. La gestion automatique se configure
                -- ensuite côté infra.
                BEGIN
                    EXECUTE format(
                        'SELECT %I.create_parent('
                        || 'p_parent_table := %L, p_control := %L, p_type := %L, '
                        || 'p_interval := %L, p_premake := 6)',
                        v_ns, 'public.audit_logs', 'created_at', v_type, '1 month'
                    );
                    EXECUTE format(
                        'UPDATE %I.part_config SET retention = %L, '
                        || 'retention_keep_table = true, retention_keep_index = false '
                        || 'WHERE parent_table = %L',
                        v_ns, '24 months', 'public.audit_logs'
                    );
                EXCEPTION WHEN OTHERS THEN
                    RAISE WARNING 'pg_partman create_parent indisponible (%) — audit_logs en partition DEFAULT (gestion pg_partman à configurer côté infra selon la version).', SQLERRM;
                END;

                -- 🔴 FILET OBLIGATOIRE, ET AVANT L'`INSERT`. `create_parent` ne crée
                -- que les partitions du mois courant et des mois à venir : les lignes
                -- d'audit plus anciennes n'auraient nulle part où aller. Une partition
                -- DEFAULT garantit qu'aucune date ne peut être refusée — ni à la
                -- restauration, ni plus tard en production.
                SELECT EXISTS (
                    SELECT 1
                    FROM   pg_class c
                    JOIN   pg_inherits i ON i.inhrelid = c.oid
                    WHERE  i.inhparent = 'public.audit_logs'::regclass
                      AND  pg_get_expr(c.relpartbound, c.oid) = 'DEFAULT'
                ) INTO v_defaut;

                IF NOT v_defaut THEN
                    EXECUTE 'CREATE TABLE IF NOT EXISTS audit_logs_default PARTITION OF audit_logs DEFAULT';
                END IF;

                -- Restore data (les partitions existent désormais).
                INSERT INTO audit_logs SELECT * FROM audit_logs_old;

                -- La table ayant été recréée, `audit_logs_id_seq` est repartie à 1
                -- alors que les lignes réinjectées portent leurs anciens `id` :
                -- sans ce recalage, la prochaine écriture d'audit violerait la clé
                -- primaire.
                SELECT COALESCE(MAX(id), 0) INTO v_max_id FROM audit_logs;
                PERFORM setval(pg_get_serial_sequence('audit_logs', 'id'), GREATEST(v_max_id, 1), v_max_id > 0);

                DROP TABLE audit_logs_old;
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
            DO $$
            DECLARE
                v_ns TEXT;
            BEGIN
                SELECT n.nspname
                INTO   v_ns
                FROM   pg_extension e
                JOIN   pg_namespace n ON n.oid = e.extnamespace
                WHERE  e.extname = 'pg_partman';

                EXECUTE format('DELETE FROM %I.part_config WHERE parent_table = %L', v_ns, 'public.audit_logs');
            EXCEPTION WHEN OTHERS THEN
                RAISE NOTICE 'down() pg_partman ignoré (%).', SQLERRM;
            END$$;
            -- Pas de DROP de la table partitionnée ici, pour éviter de perdre data.
        SQL);
    }
};
