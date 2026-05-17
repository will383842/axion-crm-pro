<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint Prospection Pipeline 360° — Ajout colonnes prospection à companies.
 *
 * Colonnes existantes (NE PAS recréer) : website TEXT, phone TEXT, postcode TEXT,
 * naf TEXT, effectif_range TEXT, size_category TEXT, city TEXT, insee TEXT.
 *
 * Colonnes nouvelles :
 *  - email_generic      VARCHAR(255)  — email contact générique (contact@site.fr)
 *  - prospection_status enum-like     — pending|ready_for_outreach|partial_email|archived_no_email
 *  - region_code        VARCHAR(3)    — code région INSEE
 *  - department_code    VARCHAR(3)    — code département INSEE (75, 2A, 971...)
 *  - commune_code       VARCHAR(5)    — code commune INSEE 5 chars
 *  - city_name          VARCHAR(120)  — nom ville canonique (BAN normalize)
 *  - sector_main        VARCHAR(64)   — secteur principal dérivé NAF
 *  - archive_reason     VARCHAR(64)   — entreprise_radiee|no_email|low_quality_score|duplicate|manual
 *
 * Idempotent : utilise ADD COLUMN IF NOT EXISTS et CREATE INDEX IF NOT EXISTS.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE companies
                ADD COLUMN IF NOT EXISTS email_generic   VARCHAR(255),
                ADD COLUMN IF NOT EXISTS prospection_status TEXT NOT NULL DEFAULT 'pending',
                ADD COLUMN IF NOT EXISTS region_code     VARCHAR(3),
                ADD COLUMN IF NOT EXISTS department_code VARCHAR(3),
                ADD COLUMN IF NOT EXISTS commune_code    VARCHAR(5),
                ADD COLUMN IF NOT EXISTS city_name       VARCHAR(120),
                ADD COLUMN IF NOT EXISTS sector_main     VARCHAR(64),
                ADD COLUMN IF NOT EXISTS archive_reason  VARCHAR(64);
        SQL);

        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'companies_prospection_status_check'
                ) THEN
                    ALTER TABLE companies
                        ADD CONSTRAINT companies_prospection_status_check
                        CHECK (prospection_status IN (
                            'pending','ready_for_outreach','partial_email','archived_no_email'
                        ));
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'companies_archive_reason_check'
                ) THEN
                    ALTER TABLE companies
                        ADD CONSTRAINT companies_archive_reason_check
                        CHECK (archive_reason IS NULL OR archive_reason IN (
                            'entreprise_radiee','no_email','low_quality_score','duplicate','manual'
                        ));
                END IF;
            END
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_companies_prospection_status
                ON companies (workspace_id, prospection_status);
            CREATE INDEX IF NOT EXISTS idx_companies_dept
                ON companies (workspace_id, department_code);
            CREATE INDEX IF NOT EXISTS idx_companies_region
                ON companies (workspace_id, region_code);
            CREATE INDEX IF NOT EXISTS idx_companies_sector
                ON companies (workspace_id, sector_main);
            CREATE INDEX IF NOT EXISTS idx_companies_archive_reason
                ON companies (workspace_id, archive_reason)
                WHERE archive_reason IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS idx_companies_archive_reason;
            DROP INDEX IF EXISTS idx_companies_sector;
            DROP INDEX IF EXISTS idx_companies_region;
            DROP INDEX IF EXISTS idx_companies_dept;
            DROP INDEX IF EXISTS idx_companies_prospection_status;

            ALTER TABLE companies
                DROP CONSTRAINT IF EXISTS companies_archive_reason_check,
                DROP CONSTRAINT IF EXISTS companies_prospection_status_check;

            ALTER TABLE companies
                DROP COLUMN IF EXISTS archive_reason,
                DROP COLUMN IF EXISTS sector_main,
                DROP COLUMN IF EXISTS city_name,
                DROP COLUMN IF EXISTS commune_code,
                DROP COLUMN IF EXISTS department_code,
                DROP COLUMN IF EXISTS region_code,
                DROP COLUMN IF EXISTS prospection_status,
                DROP COLUMN IF EXISTS email_generic;
        SQL);
    }
};
