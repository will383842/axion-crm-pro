-- Jeu de données au VOLUME RÉEL DE LA PRODUCTION — étape 1a, pièce n°1 (compteurs du hub).
--
-- Pourquoi ce second jeu, en plus de `seed_reference_50k.sql` :
-- le jeu de référence (300 000 fiches) est celui du cahier des charges §0.9 et sert
-- à comparer les étapes entre elles. Il ne dit RIEN du vrai risque des compteurs,
-- qui est un balayage LINÉAIRE : la production porte **4,29 M de `companies` dans
-- un seul workspace business**. Extrapoler 300 000 → 4,29 M est une supposition ;
-- le §28.3 en interdit l'usage comme preuve (« Mesure, jamais supposition »).
--
-- Ce script porte la table à ~4,3 M de lignes, PAR LOTS COMMITÉS de 400 000 :
--   - un seul INSERT de 4 M dans une transaction unique fait exploser le WAL et
--     a fait tomber le serveur local en cours de session (19/08, `server process
--     exited with exit code 2`, redo de plusieurs minutes) ;
--   - des lots commités laissent l'autovacuum et les points de reprise respirer,
--     et un lot perdu ne coûte que lui-même.
--
-- Les lignes ajoutées sont FROIDES (`nouveau` + provenance collecte), fidèles à ce
-- que la production contient réellement en masse. Elles portent une `denomination`
-- pour que la largeur de ligne du tas — donc le coût du balayage — soit réaliste :
-- une table remplie de lignes étroites mentirait dans le sens qui arrange.
--
-- Usage :
--   docker exec axion-crm-postgres psql -U axion -d postgres \
--     -c "CREATE DATABASE axion_crm_perf4m TEMPLATE axion_crm"
--   docker cp seed_volume_production_4m.sql axion-crm-postgres:/tmp/
--   docker exec axion-crm-postgres psql -U axion -d axion_crm_perf4m -f /tmp/seed_volume_production_4m.sql
--
-- ⚠️ Compter ~1 GB de tas + WAL, et plusieurs minutes. Base JETABLE uniquement.

\set ws '20cd81e4-de5d-4875-a759-07d64fe1f168'

-- Le workspace doit exister (contrainte de clé étrangère). `axion_crm` le porte
-- déjà ; on ne le crée donc pas, on échoue franchement s'il manque.
DO $verif$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM workspaces WHERE id = '20cd81e4-de5d-4875-a759-07d64fe1f168'::uuid) THEN
        RAISE EXCEPTION 'Workspace business absent : créer la base depuis TEMPLATE axion_crm.';
    END IF;
END
$verif$;

DO $remplir$
DECLARE
    lot        int;
    lots       constant int := 10;
    par_lot    constant int := 400000;
    -- Décalage de SIREN : au-delà des plages du jeu de référence
    -- (100 000 001 → 100 250 000 et 500 000 001 → 500 050 000).
    base_siren constant bigint := 600000000;
BEGIN
    PERFORM setseed(0.42);

    FOR lot IN 0..(lots - 1) LOOP
        INSERT INTO companies (workspace_id, siren, denomination, postcode, city,
                               department_code, region_code, relation_type,
                               lifecycle_stage, prospection_status, created_at, updated_at)
        SELECT '20cd81e4-de5d-4875-a759-07d64fe1f168'::uuid,
               lpad((base_siren + (lot::bigint * par_lot) + g)::text, 9, '0'),
               'Société volume ' || ((lot * par_lot) + g),
               lpad(((g % 95) + 1)::text, 2, '0') || lpad((g % 900)::text, 3, '0'),
               'Ville ' || (g % 5000),
               lpad(((g % 95) + 1)::text, 2, '0'),
               lpad(((g % 13) + 1)::text, 2, '0'),
               'prospect',
               'nouveau',
               'pending',
               now() - (random() * interval '1825 days'),
               now() - (random() * interval '365 days')
        FROM generate_series(1, par_lot) g;

        COMMIT;
        RAISE NOTICE 'lot % / % commité (% lignes)', lot + 1, lots, par_lot;
    END LOOP;
END
$remplir$;

ANALYZE companies;

SELECT count(*) AS companies_total FROM companies;
