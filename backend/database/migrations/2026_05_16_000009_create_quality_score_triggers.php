<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Triggers SQL : recompute automatique de `companies.quality_score` quand
 * une donnée pertinente change (contact email_status, website, phone, linkedin_url, signals).
 *
 * Cf. spec/03 § Score qualité de fiche + fonction recompute_company_quality_score (migration 000001).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Trigger sur contacts.email_status / email_score
            CREATE OR REPLACE FUNCTION trg_contact_recompute_company_score() RETURNS TRIGGER AS $$
            BEGIN
              IF NEW.company_id IS NOT NULL THEN
                PERFORM recompute_company_quality_score(NEW.company_id);
              END IF;
              RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER contacts_recompute_score
              AFTER INSERT OR UPDATE OF email_status, email_score ON contacts
              FOR EACH ROW EXECUTE FUNCTION trg_contact_recompute_company_score();

            -- Trigger sur companies.website / phone / linkedin_url / signals
            CREATE OR REPLACE FUNCTION trg_company_recompute_score() RETURNS TRIGGER AS $$
            BEGIN
              -- Évite la récursion infinie : ne recompute pas si seul quality_score change
              IF NEW.website IS DISTINCT FROM OLD.website
                 OR NEW.phone IS DISTINCT FROM OLD.phone
                 OR NEW.linkedin_url IS DISTINCT FROM OLD.linkedin_url
                 OR NEW.signals IS DISTINCT FROM OLD.signals THEN
                PERFORM recompute_company_quality_score(NEW.id);
              END IF;
              RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER companies_recompute_score
              AFTER UPDATE OF website, phone, linkedin_url, signals ON companies
              FOR EACH ROW EXECUTE FUNCTION trg_company_recompute_score();

            -- Trigger updated_at automatique sur les tables qui en ont besoin
            CREATE OR REPLACE FUNCTION trg_set_updated_at() RETURNS TRIGGER AS $$
            BEGIN
              NEW.updated_at = now();
              RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER workspaces_updated_at      BEFORE UPDATE ON workspaces      FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at();
            CREATE TRIGGER users_updated_at           BEFORE UPDATE ON users           FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at();
            CREATE TRIGGER companies_updated_at       BEFORE UPDATE ON companies       FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at();
            CREATE TRIGGER contacts_updated_at        BEFORE UPDATE ON contacts        FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at();
            CREATE TRIGGER tags_updated_at            BEFORE UPDATE ON tags            FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at();
            CREATE TRIGGER llm_use_cases_updated_at   BEFORE UPDATE ON llm_use_cases   FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at();
            CREATE TRIGGER proxy_providers_updated_at BEFORE UPDATE ON proxy_providers_config FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at();
            CREATE TRIGGER rotations_updated_at       BEFORE UPDATE ON rotations       FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at();
            CREATE TRIGGER rgpd_requests_updated_at   BEFORE UPDATE ON rgpd_requests   FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at();
            CREATE TRIGGER ai_act_register_updated_at BEFORE UPDATE ON ai_act_register FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at();
            CREATE TRIGGER saved_views_updated_at     BEFORE UPDATE ON saved_views     FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at();
            CREATE TRIGGER campaigns_updated_at       BEFORE UPDATE ON campaigns       FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at();
            CREATE TRIGGER deals_updated_at           BEFORE UPDATE ON deals           FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS deals_updated_at ON deals;
            DROP TRIGGER IF EXISTS campaigns_updated_at ON campaigns;
            DROP TRIGGER IF EXISTS saved_views_updated_at ON saved_views;
            DROP TRIGGER IF EXISTS ai_act_register_updated_at ON ai_act_register;
            DROP TRIGGER IF EXISTS rgpd_requests_updated_at ON rgpd_requests;
            DROP TRIGGER IF EXISTS rotations_updated_at ON rotations;
            DROP TRIGGER IF EXISTS proxy_providers_updated_at ON proxy_providers_config;
            DROP TRIGGER IF EXISTS llm_use_cases_updated_at ON llm_use_cases;
            DROP TRIGGER IF EXISTS tags_updated_at ON tags;
            DROP TRIGGER IF EXISTS contacts_updated_at ON contacts;
            DROP TRIGGER IF EXISTS companies_updated_at ON companies;
            DROP TRIGGER IF EXISTS users_updated_at ON users;
            DROP TRIGGER IF EXISTS workspaces_updated_at ON workspaces;
            DROP FUNCTION IF EXISTS trg_set_updated_at();
            DROP TRIGGER IF EXISTS companies_recompute_score ON companies;
            DROP FUNCTION IF EXISTS trg_company_recompute_score();
            DROP TRIGGER IF EXISTS contacts_recompute_score ON contacts;
            DROP FUNCTION IF EXISTS trg_contact_recompute_company_score();
        SQL);
    }
};
