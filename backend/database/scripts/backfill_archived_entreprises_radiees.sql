-- =============================================================================
-- One-shot backfill — Archive entreprises déjà importées qui sont radiées INSEE
-- Sprint Pipeline 360° Hardening — H3 commit 2 (2026-05-17)
-- =============================================================================
--
-- Contexte : avant le sprint H3, le waterfall n'archivait pas les entreprises
-- radiées (etatAdministratifUniteLegale !== 'A'). Les ~1000 entreprises Isère
-- importées 2026-05-17 peuvent contenir quelques radiées qu'on ne veut plus
-- enrichir / inclure dans les audiences.
--
-- AVANT EXÉCUTION :
--   1. Vérifier le périmètre : SELECT COUNT(*) FROM companies WHERE …
--   2. Backup PG : pg_dump axion_crm > backup_pre_backfill_$(date +%s).sql
--   3. Lancer EXPLAIN ANALYZE sur le UPDATE pour estimer durée
--   4. Demander confirmation Will (mass-update production)
--
-- USAGE :
--   docker compose exec -T postgres psql -U axion -d axion_crm \
--     -v workspace_id="'1db106f5-c8a4-47b0-bf86-930f1ccc9f4a'" \
--     -f /backups/backfill_archived_entreprises_radiees.sql
--
-- =============================================================================

BEGIN;

-- 1. PERIMETER CHECK (à exécuter SEUL d'abord, sans le UPDATE en dessous)
--    Compte combien d'entreprises sont concernées par workspace
SELECT
    workspace_id,
    COUNT(*) FILTER (WHERE prospection_status != 'archived_no_email') AS to_archive_count,
    COUNT(*) AS total
FROM companies
WHERE
    -- On suppose que step1_insee a stocké etatAdministratif quelque part.
    -- Si pas encore le cas, ce script est ineffectif tant que les companies
    -- n'ont pas été re-traversées par le waterfall (cf. companies:rescrape).
    -- En attendant, on peut filtrer sur signals JSON :
    signals->'legal'->>'etat_administratif' IS NOT NULL
    AND signals->'legal'->>'etat_administratif' != 'A'
GROUP BY workspace_id;

-- 2. UPDATE (commenté par défaut — décommenter APRÈS validation Will)
--
-- UPDATE companies
-- SET
--     prospection_status = 'archived_no_email',
--     archive_reason     = 'entreprise_radiee',
--     updated_at         = NOW()
-- WHERE
--     prospection_status != 'archived_no_email'
--     AND signals->'legal'->>'etat_administratif' IS NOT NULL
--     AND signals->'legal'->>'etat_administratif' != 'A'
-- RETURNING id, siren, denomination, archive_reason;

-- 3. POST-CHECK (vérifier que la contrainte CHECK companies_archive_reason_check
--    n'a pas été violée — devrait être impossible si valeur 'entreprise_radiee')
SELECT archive_reason, COUNT(*)
FROM companies
WHERE archive_reason IS NOT NULL
GROUP BY archive_reason
ORDER BY 2 DESC;

-- Si tout est OK, COMMIT, sinon ROLLBACK.
ROLLBACK;  -- ⚠️ Changer en COMMIT après revue Will + check counts
