<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec/03 § §1 — multi-tenant + auth + audit (9 tables).
 *
 * - workspaces (tenant logique)
 * - users (citext email + TOTP + verrouillage)
 * - user_workspaces (m2m + role_slug)
 * - personal_access_tokens (Sanctum)
 * - password_reset_tokens (Laravel default)
 * - sessions (Laravel default)
 * - magic_links (passwordless)
 * - audit_logs (PARTITIONED + hash chain)
 * - Spatie Permission tables : roles + permissions + role_has_permissions + model_has_roles
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- ====================================================================
            -- §1.1 workspaces (tenant logique)
            -- ====================================================================
            CREATE TABLE workspaces (
                id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                slug            CITEXT NOT NULL UNIQUE,
                name            TEXT NOT NULL,
                settings        JSONB NOT NULL DEFAULT '{}'::jsonb,
                cost_cap_eur    NUMERIC(10,2) NOT NULL DEFAULT 500.00,
                is_active       BOOLEAN NOT NULL DEFAULT true,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at      TIMESTAMPTZ
            );
            CREATE INDEX idx_workspaces_slug_active ON workspaces (slug) WHERE deleted_at IS NULL;

            -- ====================================================================
            -- §1.2 users
            -- ====================================================================
            CREATE TABLE users (
                id                       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                email                    CITEXT NOT NULL UNIQUE,
                password_hash            TEXT,
                name                     TEXT NOT NULL,
                avatar_url               TEXT,
                locale                   TEXT NOT NULL DEFAULT 'fr',
                timezone                 TEXT NOT NULL DEFAULT 'Europe/Paris',
                current_workspace_id     UUID REFERENCES workspaces(id) ON DELETE SET NULL,
                totp_secret              TEXT,
                totp_enabled_at          TIMESTAMPTZ,
                totp_recovery_codes      TEXT[],
                first_login_completed_at TIMESTAMPTZ,
                last_login_at            TIMESTAMPTZ,
                last_login_ip            INET,
                last_login_user_agent    TEXT,
                failed_login_count       INT NOT NULL DEFAULT 0,
                locked_until             TIMESTAMPTZ,
                email_verified_at        TIMESTAMPTZ,
                remember_token           VARCHAR(100),
                created_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at               TIMESTAMPTZ
            );
            CREATE INDEX idx_users_email_active ON users (email) WHERE deleted_at IS NULL;
            CREATE INDEX idx_users_current_workspace ON users (current_workspace_id);

            -- ====================================================================
            -- §1.3 user_workspaces (m2m)
            -- ====================================================================
            CREATE TABLE user_workspaces (
                user_id       UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                role_slug     TEXT NOT NULL CHECK (role_slug IN ('owner','admin','operator','viewer')),
                invited_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                joined_at     TIMESTAMPTZ,
                revoked_at    TIMESTAMPTZ,
                PRIMARY KEY (user_id, workspace_id)
            );
            CREATE INDEX idx_user_workspaces_workspace ON user_workspaces (workspace_id) WHERE revoked_at IS NULL;

            -- ====================================================================
            -- §1.4 Spatie Permission tables
            -- ====================================================================
            CREATE TABLE roles (
                id           BIGSERIAL PRIMARY KEY,
                team_id      UUID REFERENCES workspaces(id) ON DELETE CASCADE,
                name         TEXT NOT NULL,
                guard_name   TEXT NOT NULL DEFAULT 'web',
                description  TEXT,
                created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (team_id, name, guard_name)
            );

            CREATE TABLE permissions (
                id           BIGSERIAL PRIMARY KEY,
                name         TEXT NOT NULL UNIQUE,
                guard_name   TEXT NOT NULL DEFAULT 'web',
                description  TEXT,
                created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at   TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            CREATE TABLE model_has_permissions (
                permission_id BIGINT NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
                model_type    TEXT NOT NULL,
                model_id      UUID NOT NULL,
                team_id       UUID REFERENCES workspaces(id) ON DELETE CASCADE,
                PRIMARY KEY (team_id, permission_id, model_type, model_id)
            );

            CREATE TABLE model_has_roles (
                role_id    BIGINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
                model_type TEXT NOT NULL,
                model_id   UUID NOT NULL,
                team_id    UUID REFERENCES workspaces(id) ON DELETE CASCADE,
                PRIMARY KEY (team_id, role_id, model_type, model_id)
            );

            CREATE TABLE role_has_permissions (
                permission_id BIGINT NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
                role_id       BIGINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
                PRIMARY KEY (permission_id, role_id)
            );

            -- ====================================================================
            -- §1.5 invitations + magic_links + password_reset_tokens + sessions + personal_access_tokens
            -- ====================================================================
            CREATE TABLE invitations (
                id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                workspace_id   UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                email          CITEXT NOT NULL,
                role_slug      TEXT NOT NULL,
                invited_by     UUID NOT NULL REFERENCES users(id),
                token_hash     TEXT NOT NULL UNIQUE,
                expires_at     TIMESTAMPTZ NOT NULL,
                accepted_at    TIMESTAMPTZ,
                accepted_by    UUID REFERENCES users(id),
                revoked_at     TIMESTAMPTZ,
                created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            CREATE TABLE magic_links (
                id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                user_id      UUID REFERENCES users(id) ON DELETE CASCADE,
                email        CITEXT NOT NULL,
                token_hash   TEXT NOT NULL UNIQUE,
                ip           INET,
                expires_at   TIMESTAMPTZ NOT NULL,
                consumed_at  TIMESTAMPTZ,
                created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX idx_magic_links_pending ON magic_links (email) WHERE consumed_at IS NULL;

            CREATE TABLE password_reset_tokens (
                email      CITEXT PRIMARY KEY,
                token      TEXT NOT NULL,
                created_at TIMESTAMPTZ
            );

            CREATE TABLE sessions (
                id            TEXT PRIMARY KEY,
                user_id       UUID REFERENCES users(id) ON DELETE CASCADE,
                workspace_id  UUID REFERENCES workspaces(id) ON DELETE SET NULL,
                ip_address    INET,
                user_agent    TEXT,
                payload       TEXT NOT NULL,
                last_activity INT NOT NULL
            );
            CREATE INDEX idx_sessions_user ON sessions (user_id);
            CREATE INDEX idx_sessions_last_activity ON sessions (last_activity);

            CREATE TABLE personal_access_tokens (
                id              BIGSERIAL PRIMARY KEY,
                tokenable_type  TEXT NOT NULL,
                tokenable_id    UUID NOT NULL,
                name            TEXT NOT NULL,
                token           VARCHAR(64) NOT NULL UNIQUE,
                abilities       TEXT,
                last_used_at    TIMESTAMPTZ,
                expires_at      TIMESTAMPTZ,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX idx_pat_tokenable ON personal_access_tokens (tokenable_type, tokenable_id);

            -- ====================================================================
            -- §1.6 audit_logs — chaîne crypto, partitionnement mensuel
            -- (pg_partman activation reportée à un script DBA séparé spec/02)
            -- ====================================================================
            CREATE TABLE audit_logs (
                id              BIGSERIAL PRIMARY KEY,
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
                created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX idx_audit_workspace_created ON audit_logs (workspace_id, created_at DESC);
            CREATE INDEX idx_audit_user_created ON audit_logs (user_id, created_at DESC);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS audit_logs CASCADE;
            DROP TABLE IF EXISTS personal_access_tokens CASCADE;
            DROP TABLE IF EXISTS sessions CASCADE;
            DROP TABLE IF EXISTS password_reset_tokens CASCADE;
            DROP TABLE IF EXISTS magic_links CASCADE;
            DROP TABLE IF EXISTS invitations CASCADE;
            DROP TABLE IF EXISTS role_has_permissions CASCADE;
            DROP TABLE IF EXISTS model_has_roles CASCADE;
            DROP TABLE IF EXISTS model_has_permissions CASCADE;
            DROP TABLE IF EXISTS permissions CASCADE;
            DROP TABLE IF EXISTS roles CASCADE;
            DROP TABLE IF EXISTS user_workspaces CASCADE;
            DROP TABLE IF EXISTS users CASCADE;
            DROP TABLE IF EXISTS workspaces CASCADE;
        SQL);
    }
};
