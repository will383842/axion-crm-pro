<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi D1b (sécurité) — l'horloge des triggers L7 n'est plus réglable par une session. AJOUTS SEULS : on remplace
 * (CREATE OR REPLACE) des FONCTIONS ; aucune table, aucune donnée, aucun DROP / DELETE de données.
 *
 * Faille (2026_11_10_000076) : `envoi_l7_horloge()` suivait `app.maintenant` de N'IMPORTE QUELLE session. Le rôle
 * applicatif pouvait donc faire `SELECT set_config('app.maintenant', '2099-01-01', true)` puis lever par « expiration »
 * une pause non expirée, ou dater un battement de santé dans le futur et masquer l'arrêt sur silence (INF-025).
 *
 * Correctif : `app.maintenant` n'est suivi que si la BASE porte `axion.test_horloge = on` (ALTER DATABASE, réservé au
 * propriétaire : une session ne peut pas le poser). Le paramètre est lu dans le catalogue `pg_db_role_setting` et NON par
 * `current_setting()` : un `set_config('axion.test_horloge', 'on', true)` de session ne l'active donc pas. Seul le banc de
 * test (bases `axion_crm_test*`, y compris les bases parallèles) le reçoit, ici même ; ailleurs l'horloge est celle de la base.
 * Les fonctions fixent leur `search_path`. down() rétablit les fonctions de 000076 et retire le paramètre de base.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION envoi_l7_horloge_injectee() RETURNS timestamptz AS $f$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_catalog.pg_db_role_setting s
                    WHERE s.setrole = 0
                      AND s.setdatabase = (SELECT d.oid FROM pg_catalog.pg_database d WHERE d.datname = pg_catalog.current_database())
                      AND 'axion.test_horloge=on' = ANY (s.setconfig)
                ) THEN
                    RETURN NULL;   -- hors banc de test : aucun réglage de session n'est suivi
                END IF;
                RETURN NULLIF(pg_catalog.current_setting('app.maintenant', true), '')::timestamptz;
            END;
            $f$ LANGUAGE plpgsql STABLE SET search_path = pg_catalog, public, pg_temp;

            CREATE OR REPLACE FUNCTION envoi_l7_horloge() RETURNS timestamptz AS $f$
            BEGIN
                RETURN COALESCE(envoi_l7_horloge_injectee(), clock_timestamp());
            END;
            $f$ LANGUAGE plpgsql STABLE SET search_path = pg_catalog, public, pg_temp;

            CREATE OR REPLACE FUNCTION envoi_sante_battement_garde() RETURNS trigger AS $f$
            DECLARE
                ref timestamptz := envoi_l7_horloge();
            BEGIN
                -- Borné à l'horloge (jamais dans le futur) et monotone. Sans horloge d'appli EFFECTIVE (hors banc de test,
                -- ou UPDATE à la main) l'ancien battement est ramené à l'horloge ; avec elle, un instant plus ancien est ignoré.
                IF TG_OP = 'UPDATE' THEN
                    IF envoi_l7_horloge_injectee() IS NULL THEN
                        NEW.dernier_le := GREATEST(LEAST(NEW.dernier_le, ref), LEAST(OLD.dernier_le, ref));
                    ELSE
                        NEW.dernier_le := GREATEST(LEAST(NEW.dernier_le, ref), OLD.dernier_le);
                    END IF;
                ELSE
                    NEW.dernier_le := LEAST(NEW.dernier_le, ref);
                END IF;
                RETURN NEW;
            END;
            $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp;

            DO $do$
            BEGIN
                IF current_database() LIKE 'axion\_crm\_test%' THEN
                    EXECUTE format('ALTER DATABASE %I SET axion.test_horloge = %L', current_database(), 'on');
                END IF;
            END
            $do$;
        SQL);
    }

    /** Rétablit les fonctions de 000076 et retire le paramètre de base (aucune donnée touchée). */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $do$
            BEGIN
                EXECUTE format('ALTER DATABASE %I RESET axion.test_horloge', current_database());
            END
            $do$;

            CREATE OR REPLACE FUNCTION envoi_l7_horloge() RETURNS timestamptz AS $f$
            BEGIN
                RETURN COALESCE(NULLIF(current_setting('app.maintenant', true), '')::timestamptz, clock_timestamp());
            END;
            $f$ LANGUAGE plpgsql STABLE SET search_path = pg_catalog, public, pg_temp;

            CREATE OR REPLACE FUNCTION envoi_sante_battement_garde() RETURNS trigger AS $f$
            DECLARE
                ref timestamptz := envoi_l7_horloge();
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF COALESCE(current_setting('app.maintenant', true), '') = '' THEN
                        NEW.dernier_le := GREATEST(LEAST(NEW.dernier_le, ref), LEAST(OLD.dernier_le, ref));
                    ELSE
                        NEW.dernier_le := GREATEST(LEAST(NEW.dernier_le, ref), OLD.dernier_le);
                    END IF;
                ELSE
                    NEW.dernier_le := LEAST(NEW.dernier_le, ref);
                END IF;
                RETURN NEW;
            END;
            $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp;

            DROP FUNCTION IF EXISTS envoi_l7_horloge_injectee();
        SQL);
    }
};
