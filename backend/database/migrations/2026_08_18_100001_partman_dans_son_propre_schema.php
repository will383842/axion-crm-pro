<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RECONSTRUCTIBILITÉ — déplace `pg_partman` hors du `search_path` applicatif.
 *
 * 🔴 Le défaut, mesuré le 2026-08-18 sur une base neuve (`axion_crm_fresh`) :
 *
 *     $ php artisan migrate:fresh    # 1re fois : verte
 *     $ php artisan migrate:fresh    # 2e fois :
 *       Dropping all tables ............................... 1 s FAIL
 *       SQLSTATE[2BP01]: Dependent objects still exist: 7 ERROR:
 *         cannot drop table part_config because extension pg_partman requires it
 *       HINT:  You can drop extension pg_partman instead.
 *
 * Mécanique : `Illuminate\Database\Schema\PostgresBuilder::dropAllTables()`
 * énumère TOUTES les tables des schémas du `search_path` (`public`), n'exclut
 * que la clé de connexion `dont_drop` (par défaut `['spatial_ref_sys']`), et
 * émet UN SEUL `DROP TABLE … CASCADE`. `infra/postgres/init/01-extensions.sql`
 * créait pg_partman sans clause `SCHEMA`, donc dans `public` : ses tables
 * internes `part_config` / `part_config_sub` entraient dans le lot, PostgreSQL
 * refusait la commande ENTIÈRE, et AUCUNE migration ne s'exécutait ensuite.
 * `RefreshDatabase` (toute la suite Pest) passe par le même chemin.
 *
 * Effet de bord bienvenu : `2026_05_17_000011_setup_pg_partman_audit_logs`
 * appelle depuis toujours `partman.create_parent()` et `partman.part_config`,
 * un schéma qui n'existait pas. L'appel échouait donc systématiquement et
 * retombait sur la partition DEFAULT du bloc `EXCEPTION` ajouté en juillet
 * (commit de5e684) : pg_partman n'a JAMAIS géré `audit_logs`. Mesuré le
 * 2026-08-18 sur `axion_crm` : `part_config` contenait 0 ligne, et le schéma
 * `partman` n'existait pas. Une fois l'extension à sa place, l'appel aboutit.
 *
 * ── Sûreté production ──────────────────────────────────────────────────────
 * pg_partman est `relocatable = false` : `ALTER EXTENSION … SET SCHEMA` est
 * refusé. Il faut donc DROP puis CREATE, ce qui détruit le contenu de
 * `part_config`. La relocalisation est par conséquent ABANDONNÉE (avec un
 * `RAISE WARNING`, sans faire rougir le déploiement) dès que `part_config`
 * porte la moindre ligne : dans ce cas pg_partman gère réellement des
 * partitions et on ne touche à rien. Le cas mesuré partout (dev + prod, même
 * code, même échec silencieux de `create_parent`) est `part_config` vide.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            DECLARE
                v_schema  TEXT;
                v_lignes  BIGINT;
            BEGIN
                SELECT n.nspname
                INTO   v_schema
                FROM   pg_extension e
                JOIN   pg_namespace n ON n.oid = e.extnamespace
                WHERE  e.extname = 'pg_partman';

                IF v_schema IS NULL THEN
                    RAISE NOTICE 'pg_partman absent de cette base — rien à relocaliser.';
                    RETURN;
                END IF;

                IF v_schema = 'partman' THEN
                    RAISE NOTICE 'pg_partman est déjà dans le schéma partman — rien à faire.';
                    RETURN;
                END IF;

                EXECUTE format('SELECT count(*) FROM %I.part_config', v_schema) INTO v_lignes;

                IF v_lignes > 0 THEN
                    RAISE WARNING 'pg_partman vit dans le schéma % et gère % ensemble(s) de partitions : '
                        'relocalisation ABANDONNÉE (elle détruirait part_config). '
                        'La base restera NON RECONSTRUCTIBLE par migrate:fresh tant que ce ne sera pas '
                        'traité à la main — cf. _REPORTS/2026-08-18_RECONSTRUCTION-BASE.md.',
                        v_schema, v_lignes;
                    RETURN;
                END IF;

                -- part_config est vide : l'extension ne gère rien, la recréer ailleurs
                -- ne perd aucune configuration.
                EXECUTE 'DROP EXTENSION pg_partman';
                EXECUTE 'CREATE SCHEMA IF NOT EXISTS partman';
                EXECUTE 'CREATE EXTENSION pg_partman SCHEMA partman';

                RAISE NOTICE 'pg_partman relocalisé de % vers partman.', v_schema;
            EXCEPTION WHEN OTHERS THEN
                -- Un déploiement ne doit JAMAIS rougir sur cette réparation de confort.
                RAISE WARNING 'Relocalisation de pg_partman impossible (%) — la base reste '
                    'non reconstructible par migrate:fresh, mais rien n''est cassé.', SQLERRM;
            END $$;
        SQL);
    }

    public function down(): void
    {
        // Pas de retour en arrière : remettre pg_partman dans `public` recréerait
        // délibérément le défaut que cette migration corrige.
    }
};
