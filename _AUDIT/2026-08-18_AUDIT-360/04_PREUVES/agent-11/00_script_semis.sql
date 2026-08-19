-- Agent 11 — semis générique « deux espaces » sur toute table portant workspace_id.
-- Base JETABLE axion_crm_a11 uniquement.

CREATE TABLE IF NOT EXISTS a11_seed_report (
    table_name text PRIMARY KEY,
    rows_inserted int NOT NULL DEFAULT 0,
    error text
);
TRUNCATE a11_seed_report;

CREATE OR REPLACE FUNCTION a11_dummy(ftype text, seed int) RETURNS text AS $f$
DECLARE lbl text;
BEGIN
    IF ftype LIKE '%[]' THEN
        RETURN format('%L::%s', '{}', ftype);
    END IF;
    IF ftype IN ('uuid') THEN RETURN 'gen_random_uuid()'; END IF;
    IF ftype IN ('smallint','integer','bigint','numeric','real','double precision')
       OR ftype LIKE 'numeric(%' THEN RETURN seed::text; END IF;
    IF ftype = 'boolean' THEN RETURN 'false'; END IF;
    IF ftype LIKE 'timestamp%' OR ftype = 'date' THEN RETURN 'now()'; END IF;
    IF ftype LIKE 'time%' THEN RETURN format('%L::%s', '00:00:00', ftype); END IF;
    IF ftype = 'interval' THEN RETURN '''0''::interval'; END IF;
    IF ftype IN ('json','jsonb') THEN RETURN format('%L::%s', '{}', ftype); END IF;
    IF ftype = 'inet' THEN RETURN '''127.0.0.1''::inet'; END IF;
    IF ftype = 'bytea' THEN RETURN '''\x''::bytea'; END IF;
    IF ftype IN ('text','citext') OR ftype LIKE 'character varying%' OR ftype LIKE 'character%'
       OR ftype LIKE 'varchar%' THEN
        RETURN format('%L', 'a' || seed::text);
    END IF;
    -- enum ou type utilisateur : première étiquette
    SELECT e.enumlabel INTO lbl
      FROM pg_enum e JOIN pg_type ty ON ty.oid = e.enumtypid
     WHERE ty.typname = split_part(ftype, '.', greatest(1, array_length(string_to_array(ftype,'.'),1)))
     ORDER BY e.enumsortorder LIMIT 1;
    IF lbl IS NOT NULL THEN RETURN format('%L::%s', lbl, ftype); END IF;
    RETURN format('%L::%s', 'a' || seed::text, ftype);
END;
$f$ LANGUAGE plpgsql;

DO $$
DECLARE
    t record; col record;
    cols text; vals text;
    wsids uuid[] := ARRAY['11111111-1111-4111-8111-111111111111'::uuid,
                          '22222222-2222-4222-8222-222222222222'::uuid];
    ws uuid; k int; n int; err text;
BEGIN
    PERFORM set_config('session_replication_role','replica',false);
    FOR t IN
        SELECT c.oid, c.relname
          FROM pg_class c JOIN pg_namespace n2 ON n2.oid = c.relnamespace
         WHERE n2.nspname = 'public' AND c.relkind = 'r' AND NOT c.relispartition
           AND EXISTS (SELECT 1 FROM pg_attribute a
                        WHERE a.attrelid = c.oid AND a.attname = 'workspace_id'
                          AND a.attnum > 0 AND NOT a.attisdropped)
           AND c.relname <> 'a11_seed_report'
         ORDER BY c.relname
    LOOP
        n := 0; err := NULL;
        FOR k IN 1..2 LOOP
            ws := wsids[k];
            cols := ''; vals := '';
            FOR col IN
                SELECT a.attname,
                       format_type(a.atttypid, a.atttypmod) AS ftype,
                       a.attnotnull,
                       (d.adbin IS NOT NULL) AS hasdef,
                       a.attidentity
                  FROM pg_attribute a
                  LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
                 WHERE a.attrelid = t.oid AND a.attnum > 0 AND NOT a.attisdropped
                 ORDER BY a.attnum
            LOOP
                IF col.attname = 'workspace_id' THEN
                    cols := cols || quote_ident(col.attname) || ',';
                    vals := vals || format('%L::%s', ws::text, col.ftype) || ',';
                ELSIF col.attnotnull AND NOT col.hasdef AND col.attidentity = '' THEN
                    cols := cols || quote_ident(col.attname) || ',';
                    vals := vals || a11_dummy(col.ftype, k) || ',';
                END IF;
            END LOOP;
            BEGIN
                EXECUTE format('INSERT INTO public.%I (%s) VALUES (%s)',
                               t.relname, rtrim(cols, ','), rtrim(vals, ','));
                n := n + 1;
            EXCEPTION WHEN OTHERS THEN
                err := coalesce(err || ' | ', '') || SQLERRM;
            END;
        END LOOP;
        INSERT INTO a11_seed_report(table_name, rows_inserted, error) VALUES (t.relname, n, err);
    END LOOP;
END $$;

SELECT count(*) FILTER (WHERE rows_inserted = 2) AS ok_2_lignes,
       count(*) FILTER (WHERE rows_inserted < 2) AS echecs,
       count(*) AS total
  FROM a11_seed_report;
