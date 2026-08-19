-- Agent 11 — épreuve d'étanchéité table par table, jouée sous le rôle NON-PROPRIÉTAIRE.
CREATE TABLE IF NOT EXISTS a11_result (
    table_name text PRIMARY KEY,
    rls boolean, force_rls boolean, npol int,
    n_sans_contexte int,     -- attendu 0
    n_contexte_a int,        -- attendu 1
    n_contexte_a_hors_a int, -- attendu 0  (lignes visibles n'appartenant PAS à A)
    n_contexte_b int,
    verdict text
);
TRUNCATE a11_result;

GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO axion_app;
GRANT USAGE ON SCHEMA public TO axion_app;

DO $$
DECLARE
    t record; a uuid := '11111111-1111-4111-8111-111111111111';
    b uuid := '22222222-2222-4222-8222-222222222222';
    n0 int; na int; nax int; nb int; v text;
BEGIN
    FOR t IN
        SELECT c.oid, c.relname, c.relrowsecurity, c.relforcerowsecurity,
               (SELECT count(*) FROM pg_policy p WHERE p.polrelid = c.oid) AS npol
          FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
          JOIN a11_seed_report r ON r.table_name = c.relname
         WHERE n.nspname = 'public' ORDER BY c.relname
    LOOP
        SET LOCAL ROLE axion_app;
        PERFORM set_config('app.current_workspace_id', '', false);
        EXECUTE format('SELECT count(*) FROM public.%I WHERE workspace_id::text IN (%L,%L)',
                       t.relname, a::text, b::text) INTO n0;
        PERFORM set_config('app.current_workspace_id', a::text, false);
        EXECUTE format('SELECT count(*) FROM public.%I WHERE workspace_id::text IN (%L,%L)',
                       t.relname, a::text, b::text) INTO na;
        EXECUTE format('SELECT count(*) FROM public.%I WHERE workspace_id::text = %L',
                       t.relname, b::text) INTO nax;
        PERFORM set_config('app.current_workspace_id', b::text, false);
        EXECUTE format('SELECT count(*) FROM public.%I WHERE workspace_id::text IN (%L,%L)',
                       t.relname, a::text, b::text) INTO nb;
        RESET ROLE;
        PERFORM set_config('app.current_workspace_id', '', false);

        v := CASE
               WHEN n0 = 0 AND na = 1 AND nax = 0 AND nb = 1 THEN 'ETANCHE'
               WHEN n0 > 0 AND na = 1 AND nax = 0 THEN 'FUITE-SANS-CONTEXTE'
               WHEN nax > 0 THEN 'FUITE-CROSS-ESPACE'
               ELSE 'ANOMALIE'
             END;
        INSERT INTO a11_result VALUES (t.relname, t.relrowsecurity, t.relforcerowsecurity,
                                       t.npol, n0, na, nax, nb, v);
    END LOOP;
END $$;

SELECT verdict, count(*) FROM a11_result GROUP BY 1 ORDER BY 2 DESC;
