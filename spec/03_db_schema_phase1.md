# 03 — DB SCHEMA PHASE 1 (COMPLET)

> **Cible :** PostgreSQL 16.x
> **Extensions requises :** `pg_trgm`, `postgis`, `pgvector`, `pg_partman`, `uuid-ossp`, `pgcrypto`, `unaccent`
> **Convention :** noms de tables en `snake_case`, pluriel. Colonnes : `snake_case`. PK : `id BIGSERIAL` ou `id UUID`. FK : `<table_singulier>_id`. Timestamps : `created_at`, `updated_at`. Soft-delete : `deleted_at` quand nécessaire.
> **Multi-tenant :** toutes les tables métier portent `workspace_id` + RLS policy.
> **Convention RLS :** chaque table tenant-scoped active RLS et applique une policy `tenant_isolation` filtrant par `workspace_id = current_setting('app.workspace_id')::bigint`.

---

## 0. Bootstrap (extensions + schemas + roles)

```sql
-- Création des extensions (à exécuter une seule fois lors de l'init)
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";
CREATE EXTENSION IF NOT EXISTS "unaccent";
CREATE EXTENSION IF NOT EXISTS "postgis";
CREATE EXTENSION IF NOT EXISTS "vector";       -- pgvector
CREATE EXTENSION IF NOT EXISTS "pg_partman";

-- Role d'application (utilisé par Laravel)
CREATE ROLE axion_crm_app LOGIN PASSWORD '<from-vault>';
GRANT CONNECT ON DATABASE axion_crm_pro TO axion_crm_app;
GRANT USAGE ON SCHEMA public TO axion_crm_app;

-- Schema dédié partition (pg_partman)
CREATE SCHEMA IF NOT EXISTS partman;
GRANT USAGE ON SCHEMA partman TO axion_crm_app;

-- Helper function pour le setting tenant
CREATE OR REPLACE FUNCTION app_workspace_id() RETURNS BIGINT AS $$
  SELECT NULLIF(current_setting('app.workspace_id', TRUE), '')::BIGINT;
$$ LANGUAGE SQL STABLE;
```

---

## 1. Multi-tenant & Auth

### `workspaces`

```sql
CREATE TABLE workspaces (
  id          BIGSERIAL PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  slug        VARCHAR(60)  UNIQUE NOT NULL,
  status      VARCHAR(20)  NOT NULL DEFAULT 'active'
              CHECK (status IN ('active','suspended','archived')),
  plan        VARCHAR(20)  NOT NULL DEFAULT 'internal',
  created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);
COMMENT ON TABLE workspaces IS 'Unités de tenant. V1 : un seul workspace "Axion-IA".';
```

### `users`

```sql
CREATE TABLE users (
  id                BIGSERIAL PRIMARY KEY,
  uuid              UUID NOT NULL DEFAULT uuid_generate_v4() UNIQUE,
  email             VARCHAR(190) UNIQUE NOT NULL,
  email_verified_at TIMESTAMPTZ,
  password          VARCHAR(255),                        -- bcrypt
  first_name        VARCHAR(80),
  last_name         VARCHAR(80),
  totp_secret       VARCHAR(64),                         -- chiffré app-side
  totp_enabled_at   TIMESTAMPTZ,
  backup_codes_hash JSONB,                               -- array de bcrypt
  locale            VARCHAR(8) NOT NULL DEFAULT 'fr',
  timezone          VARCHAR(64) NOT NULL DEFAULT 'Europe/Paris',
  last_login_at     TIMESTAMPTZ,
  last_login_ip     INET,
  is_super_admin    BOOLEAN NOT NULL DEFAULT FALSE,
  status            VARCHAR(20) NOT NULL DEFAULT 'active'
                    CHECK (status IN ('active','disabled','locked')),
  created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  deleted_at        TIMESTAMPTZ
);
CREATE INDEX users_email_lower_idx ON users (LOWER(email));
COMMENT ON COLUMN users.totp_secret IS 'Chiffré via APP_KEY Laravel (Crypt::encryptString). Jamais en clair.';
```

### `user_workspaces`

```sql
CREATE TABLE user_workspaces (
  user_id      BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  workspace_id BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  joined_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  invited_by   BIGINT REFERENCES users(id) ON DELETE SET NULL,
  PRIMARY KEY (user_id, workspace_id)
);
CREATE INDEX user_workspaces_ws_idx ON user_workspaces (workspace_id);
```

### `roles`, `permissions`, `role_permissions`, `model_has_roles`, `model_has_permissions`

> Schéma standard `spatie/laravel-permission`. Adapté avec `workspace_id` pour scope multi-tenant.

```sql
CREATE TABLE roles (
  id           BIGSERIAL PRIMARY KEY,
  workspace_id BIGINT REFERENCES workspaces(id) ON DELETE CASCADE,   -- NULL = global
  name         VARCHAR(60) NOT NULL,
  guard_name   VARCHAR(60) NOT NULL DEFAULT 'web',
  created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (workspace_id, name, guard_name)
);

CREATE TABLE permissions (
  id           BIGSERIAL PRIMARY KEY,
  workspace_id BIGINT REFERENCES workspaces(id) ON DELETE CASCADE,
  name         VARCHAR(120) NOT NULL,
  guard_name   VARCHAR(60) NOT NULL DEFAULT 'web',
  created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (workspace_id, name, guard_name)
);

CREATE TABLE role_has_permissions (
  permission_id BIGINT NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
  role_id       BIGINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
  PRIMARY KEY (permission_id, role_id)
);

CREATE TABLE model_has_roles (
  role_id     BIGINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
  model_type  VARCHAR(255) NOT NULL,
  model_id    BIGINT NOT NULL,
  workspace_id BIGINT REFERENCES workspaces(id),
  PRIMARY KEY (role_id, model_id, model_type)
);
CREATE INDEX model_has_roles_model_idx ON model_has_roles (model_id, model_type);

CREATE TABLE model_has_permissions (
  permission_id BIGINT NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
  model_type    VARCHAR(255) NOT NULL,
  model_id      BIGINT NOT NULL,
  workspace_id  BIGINT REFERENCES workspaces(id),
  PRIMARY KEY (permission_id, model_id, model_type)
);
CREATE INDEX model_has_permissions_model_idx ON model_has_permissions (model_id, model_type);
```

**Rôles seed Phase 1 :**
- `owner` (full access, peut supprimer le workspace)
- `admin` (config sources, LLM, users, RGPD)
- `operator` (lecture + déclenchement scraping + override scores)
- `viewer` (lecture seule)

### `invitations`

```sql
CREATE TABLE invitations (
  id           BIGSERIAL PRIMARY KEY,
  workspace_id BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  email        VARCHAR(190) NOT NULL,
  token_hash   VARCHAR(64) NOT NULL,                    -- sha256(plain_token)
  role         VARCHAR(60) NOT NULL,
  invited_by   BIGINT NOT NULL REFERENCES users(id),
  expires_at   TIMESTAMPTZ NOT NULL,
  accepted_at  TIMESTAMPTZ,
  created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (workspace_id, email, token_hash)
);
```

### `sessions` (Sanctum SPA = cookie + table sessions Laravel)

```sql
CREATE TABLE sessions (
  id            VARCHAR(40) PRIMARY KEY,
  user_id       BIGINT REFERENCES users(id) ON DELETE CASCADE,
  ip_address    VARCHAR(45),
  user_agent    TEXT,
  payload       TEXT NOT NULL,
  last_activity INTEGER NOT NULL
);
CREATE INDEX sessions_user_idx ON sessions (user_id);
CREATE INDEX sessions_last_activity_idx ON sessions (last_activity);
```

### `audit_logs` (append-only, hash chain)

```sql
CREATE TABLE audit_logs (
  id           BIGSERIAL PRIMARY KEY,
  workspace_id BIGINT REFERENCES workspaces(id) ON DELETE SET NULL,
  actor_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
  actor_ip     INET,
  actor_agent  TEXT,
  action       VARCHAR(100) NOT NULL,
  entity_type  VARCHAR(100),
  entity_id    BIGINT,
  payload      JSONB,
  prev_hash    VARCHAR(64),                            -- hash de la ligne précédente
  row_hash     VARCHAR(64) NOT NULL,                   -- sha256(prev_hash || action || ts || payload)
  occurred_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX audit_logs_ws_occurred_idx ON audit_logs (workspace_id, occurred_at DESC);
CREATE INDEX audit_logs_action_idx ON audit_logs (action, occurred_at DESC);

-- Empêcher UPDATE et DELETE (append-only)
CREATE OR REPLACE FUNCTION block_audit_mutation() RETURNS TRIGGER AS $$
BEGIN
  RAISE EXCEPTION 'audit_logs is append-only';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs
  FOR EACH ROW EXECUTE FUNCTION block_audit_mutation();
CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs
  FOR EACH ROW EXECUTE FUNCTION block_audit_mutation();
COMMENT ON TABLE audit_logs IS 'Append-only avec hash chain. Toute mutation est interdite. La vérification d''intégrité chaîne row_hash via prev_hash.';
```

---

## 2. LLM Router

### `llm_providers`

```sql
CREATE TABLE llm_providers (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT REFERENCES workspaces(id) ON DELETE CASCADE,    -- NULL = global
  provider_key    VARCHAR(40) NOT NULL,                  -- anthropic / openai / mistral / openrouter / ollama
  display_name    VARCHAR(80) NOT NULL,
  base_url        VARCHAR(255) NOT NULL,
  api_key_vault_path VARCHAR(255),                        -- ex: 'kv/llm/anthropic/api_key'
  enabled         BOOLEAN NOT NULL DEFAULT TRUE,
  config          JSONB NOT NULL DEFAULT '{}'::jsonb,    -- timeouts, retry, etc.
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (workspace_id, provider_key)
);
```

### `llm_use_cases`

```sql
CREATE TABLE llm_use_cases (
  id                BIGSERIAL PRIMARY KEY,
  workspace_id      BIGINT REFERENCES workspaces(id) ON DELETE CASCADE,
  use_case_key      VARCHAR(80) NOT NULL,                -- 'sector_classification', 'ia_maturity_scoring', ...
  display_name      VARCHAR(120) NOT NULL,
  phase             SMALLINT NOT NULL CHECK (phase IN (1,2)),
  primary_provider  VARCHAR(40) NOT NULL,
  primary_model     VARCHAR(80) NOT NULL,
  fallback_chain    JSONB NOT NULL DEFAULT '[]'::jsonb,  -- [{"provider":"openai","model":"gpt-4o-mini"}]
  max_tokens        INTEGER NOT NULL DEFAULT 1024,
  temperature       NUMERIC(3,2) NOT NULL DEFAULT 0.20,
  active_template_id BIGINT,                              -- FK prompt_template_versions, set after seed
  enabled           BOOLEAN NOT NULL DEFAULT TRUE,
  ab_test_config    JSONB,                                -- { "enabled":true, "variant_b": {...}, "split":0.10 }
  created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (workspace_id, use_case_key)
);
COMMENT ON COLUMN llm_use_cases.fallback_chain IS 'Ordre de fallback en JSON. Ex: [{"provider":"anthropic","model":"claude-haiku-4-5"},{"provider":"openai","model":"gpt-4o-mini"}]';
```

### `prompt_templates` + `prompt_template_versions`

```sql
CREATE TABLE prompt_templates (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT REFERENCES workspaces(id) ON DELETE CASCADE,
  use_case_id     BIGINT NOT NULL REFERENCES llm_use_cases(id) ON DELETE CASCADE,
  name            VARCHAR(120) NOT NULL,
  current_version_id BIGINT,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE prompt_template_versions (
  id              BIGSERIAL PRIMARY KEY,
  template_id     BIGINT NOT NULL REFERENCES prompt_templates(id) ON DELETE CASCADE,
  version         INTEGER NOT NULL,
  system_prompt   TEXT NOT NULL,
  user_prompt     TEXT NOT NULL,
  variables_spec  JSONB,                                  -- liste variables attendues + types
  created_by      BIGINT REFERENCES users(id) ON DELETE SET NULL,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (template_id, version)
);
```

### `llm_usage` (partitionnée mois)

```sql
CREATE TABLE llm_usage (
  id                BIGSERIAL,
  workspace_id      BIGINT NOT NULL,
  use_case_key      VARCHAR(80) NOT NULL,
  provider_key      VARCHAR(40) NOT NULL,
  model             VARCHAR(80) NOT NULL,
  template_id       BIGINT,
  template_version  INTEGER,
  input_tokens      INTEGER NOT NULL,
  output_tokens     INTEGER NOT NULL,
  cost_eur_micro    BIGINT NOT NULL,                     -- en micro-euros (1€ = 1_000_000)
  latency_ms        INTEGER NOT NULL,
  status            VARCHAR(20) NOT NULL,                -- 'ok','retry','fallback','error'
  request_hash      VARCHAR(64),                          -- pour idempotence + dedup analytics
  ab_variant        VARCHAR(8),                            -- 'A' | 'B' | NULL
  related_entity    VARCHAR(60),                          -- ex: 'company:12345'
  occurred_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  PRIMARY KEY (id, occurred_at)
) PARTITION BY RANGE (occurred_at);

-- pg_partman configuration (créée 12 mois avance, drop après 24 mois)
SELECT partman.create_parent(
  p_parent_table => 'public.llm_usage',
  p_control => 'occurred_at',
  p_type => 'native',
  p_interval => 'monthly',
  p_premake => 12
);

CREATE INDEX llm_usage_ws_use_case_idx ON llm_usage (workspace_id, use_case_key, occurred_at DESC);
```

---

## 3. Rotations & Proxies

### `proxy_providers`

```sql
CREATE TABLE proxy_providers (
  id             BIGSERIAL PRIMARY KEY,
  workspace_id   BIGINT REFERENCES workspaces(id) ON DELETE CASCADE,
  provider_key   VARCHAR(40) NOT NULL,                   -- webshare / iproyal / smartproxy / brightdata
  display_name   VARCHAR(80) NOT NULL,
  type           VARCHAR(20) NOT NULL CHECK (type IN ('datacenter','residential','mobile','isp')),
  enabled        BOOLEAN NOT NULL DEFAULT TRUE,
  monthly_budget_eur INTEGER NOT NULL DEFAULT 0,
  api_endpoint   VARCHAR(255),
  api_key_vault_path VARCHAR(255),
  config         JSONB NOT NULL DEFAULT '{}'::jsonb,
  created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (workspace_id, provider_key)
);
```

### `proxies`

```sql
CREATE TABLE proxies (
  id             BIGSERIAL PRIMARY KEY,
  workspace_id   BIGINT REFERENCES workspaces(id) ON DELETE CASCADE,
  provider_id    BIGINT NOT NULL REFERENCES proxy_providers(id) ON DELETE CASCADE,
  ip             INET NOT NULL,
  port           INTEGER NOT NULL,
  protocol       VARCHAR(10) NOT NULL DEFAULT 'http',
  username       VARCHAR(120),
  password_enc   TEXT,                                    -- chiffré
  country_code   CHAR(2),
  city           VARCHAR(80),
  asn            INTEGER,
  status         VARCHAR(20) NOT NULL DEFAULT 'active'
                 CHECK (status IN ('active','cooldown','disabled','banned')),
  cooldown_until TIMESTAMPTZ,
  success_rate_24h NUMERIC(5,2),                          -- 0..100
  total_requests INTEGER NOT NULL DEFAULT 0,
  total_failures INTEGER NOT NULL DEFAULT 0,
  last_used_at   TIMESTAMPTZ,
  weight         INTEGER NOT NULL DEFAULT 100,            -- pour weighted round-robin
  meta           JSONB,
  created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX proxies_status_weight_idx ON proxies (status, weight DESC) WHERE status = 'active';
CREATE INDEX proxies_provider_idx ON proxies (provider_id, status);
```

### `proxy_health_checks`

```sql
CREATE TABLE proxy_health_checks (
  id          BIGSERIAL PRIMARY KEY,
  proxy_id    BIGINT NOT NULL REFERENCES proxies(id) ON DELETE CASCADE,
  target      VARCHAR(120) NOT NULL,                    -- ex: 'https://httpbin.org/ip'
  status_code INTEGER,
  latency_ms  INTEGER,
  ok          BOOLEAN NOT NULL,
  error_message TEXT,
  occurred_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX proxy_health_checks_proxy_idx ON proxy_health_checks (proxy_id, occurred_at DESC);
```

### `proxy_usage_log` (partitionnée jour)

```sql
CREATE TABLE proxy_usage_log (
  id           BIGSERIAL,
  workspace_id BIGINT NOT NULL,
  proxy_id     BIGINT NOT NULL,
  scraper_run_id BIGINT,
  target_domain VARCHAR(180),
  http_status  INTEGER,
  latency_ms   INTEGER,
  bytes_in     INTEGER,
  bytes_out    INTEGER,
  occurred_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  PRIMARY KEY (id, occurred_at)
) PARTITION BY RANGE (occurred_at);

SELECT partman.create_parent(
  p_parent_table => 'public.proxy_usage_log',
  p_control => 'occurred_at',
  p_type => 'native',
  p_interval => 'daily',
  p_premake => 30
);

CREATE INDEX proxy_usage_log_proxy_idx ON proxy_usage_log (proxy_id, occurred_at DESC);
```

### `user_agents`

```sql
CREATE TABLE user_agents (
  id          BIGSERIAL PRIMARY KEY,
  ua_string   TEXT NOT NULL,
  browser     VARCHAR(40),                                -- chrome / firefox / safari / edge
  device      VARCHAR(20),                                -- desktop / mobile / tablet
  os          VARCHAR(40),
  fingerprint JSONB,                                      -- viewport, accept-lang, etc.
  weight      INTEGER NOT NULL DEFAULT 100,
  enabled     BOOLEAN NOT NULL DEFAULT TRUE,
  last_used_at TIMESTAMPTZ,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX user_agents_enabled_weight_idx ON user_agents (enabled, weight DESC);
```

### `scraper_rotation_state`

```sql
CREATE TABLE scraper_rotation_state (
  id                 BIGSERIAL PRIMARY KEY,
  workspace_id       BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  scraper_key        VARCHAR(40) NOT NULL,                -- gmaps / pj / linkedin_pb / website
  current_zone       JSONB,                                -- {"type":"city","code":"75056"}
  cooldown_until     TIMESTAMPTZ,
  last_target_id     BIGINT,
  last_run_at        TIMESTAMPTZ,
  state              VARCHAR(20) NOT NULL DEFAULT 'idle'
                     CHECK (state IN ('idle','running','rate_limited','circuit_broken','cooldown')),
  meta               JSONB,
  updated_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (workspace_id, scraper_key)
);
```

### `linkedin_accounts`

```sql
CREATE TABLE linkedin_accounts (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  label           VARCHAR(80) NOT NULL,
  email           VARCHAR(190),
  phantombuster_session_id_vault VARCHAR(255),            -- session cookie chiffré dans vault
  proxy_id        BIGINT REFERENCES proxies(id) ON DELETE SET NULL,
  status          VARCHAR(20) NOT NULL DEFAULT 'active'
                  CHECK (status IN ('active','rate_limited','cooldown','suspicious','banned')),
  daily_limit     INTEGER NOT NULL DEFAULT 80,
  daily_used      INTEGER NOT NULL DEFAULT 0,
  cooldown_until  TIMESTAMPTZ,
  last_used_at    TIMESTAMPTZ,
  meta            JSONB,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

### `linkedin_account_health`

```sql
CREATE TABLE linkedin_account_health (
  id          BIGSERIAL PRIMARY KEY,
  account_id  BIGINT NOT NULL REFERENCES linkedin_accounts(id) ON DELETE CASCADE,
  status      VARCHAR(20) NOT NULL,
  reason      VARCHAR(120),
  evidence    JSONB,
  occurred_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX linkedin_account_health_account_idx ON linkedin_account_health (account_id, occurred_at DESC);
```

### `rotation_events`

```sql
CREATE TABLE rotation_events (
  id           BIGSERIAL PRIMARY KEY,
  workspace_id BIGINT REFERENCES workspaces(id) ON DELETE CASCADE,
  rotation_type VARCHAR(20) NOT NULL CHECK (rotation_type IN ('proxy','user_agent','target','linkedin','llm')),
  event        VARCHAR(40) NOT NULL,                     -- 'cooldown_triggered','ban_detected','recovered'
  entity_type  VARCHAR(40),
  entity_id    BIGINT,
  detail       JSONB,
  occurred_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX rotation_events_ws_type_idx ON rotation_events (workspace_id, rotation_type, occurred_at DESC);
```

---

## 4. Référentiel géographique + secteurs

### `countries`

```sql
CREATE TABLE countries (
  id           BIGSERIAL PRIMARY KEY,
  iso2         CHAR(2) UNIQUE NOT NULL,
  iso3         CHAR(3) UNIQUE NOT NULL,
  name_fr      VARCHAR(120) NOT NULL,
  name_en      VARCHAR(120) NOT NULL,
  geom         GEOMETRY(MultiPolygon, 4326)
);
```

### `regions`, `departments`, `cities`

```sql
CREATE TABLE regions (
  id           BIGSERIAL PRIMARY KEY,
  insee_code   CHAR(2) UNIQUE NOT NULL,                  -- ex: '11','24','27','28','32','44','52','53','75','76','84','93','94'
  name         VARCHAR(120) NOT NULL,
  slug         VARCHAR(120) UNIQUE NOT NULL,
  geom_simplified GEOMETRY(MultiPolygon, 4326)
);

CREATE TABLE departments (
  id           BIGSERIAL PRIMARY KEY,
  region_id    BIGINT NOT NULL REFERENCES regions(id) ON DELETE CASCADE,
  insee_code   VARCHAR(3) UNIQUE NOT NULL,               -- 01..95 + 2A/2B + 971..976
  name         VARCHAR(120) NOT NULL,
  slug         VARCHAR(120) UNIQUE NOT NULL,
  geom_simplified GEOMETRY(MultiPolygon, 4326)
);
CREATE INDEX departments_region_idx ON departments (region_id);

CREATE TABLE cities (
  id             BIGSERIAL PRIMARY KEY,
  department_id  BIGINT NOT NULL REFERENCES departments(id) ON DELETE CASCADE,
  insee_code     VARCHAR(5) UNIQUE NOT NULL,             -- COG INSEE
  name           VARCHAR(160) NOT NULL,
  name_unaccented VARCHAR(160),
  slug           VARCHAR(180) UNIQUE NOT NULL,
  postal_codes   TEXT[],                                  -- ex: ['75001','75002',...]
  population     INTEGER,
  latitude       NUMERIC(9,6),
  longitude      NUMERIC(9,6),
  geom_centroid  GEOMETRY(Point, 4326),
  geom_simplified GEOMETRY(MultiPolygon, 4326),          -- pour communes >5000 hab
  scrape_eligible BOOLEAN GENERATED ALWAYS AS (population >= 5000) STORED,
  created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX cities_department_idx ON cities (department_id);
CREATE INDEX cities_name_trgm_idx ON cities USING GIN (name_unaccented gin_trgm_ops);
CREATE INDEX cities_geom_centroid_idx ON cities USING GIST (geom_centroid);
CREATE INDEX cities_eligible_idx ON cities (scrape_eligible) WHERE scrape_eligible = TRUE;
COMMENT ON COLUMN cities.scrape_eligible IS 'Calculé auto à partir de population >= 5000. ~2157 villes éligibles en France métro.';
```

### `naf_sections`, `naf_divisions`, `naf_groups`, `naf_classes`, `naf_subclasses`

```sql
CREATE TABLE naf_sections (
  id           BIGSERIAL PRIMARY KEY,
  code         CHAR(1) UNIQUE NOT NULL,                  -- A..U
  label        VARCHAR(200) NOT NULL
);

CREATE TABLE naf_divisions (
  id           BIGSERIAL PRIMARY KEY,
  section_id   BIGINT NOT NULL REFERENCES naf_sections(id) ON DELETE CASCADE,
  code         CHAR(2) UNIQUE NOT NULL,
  label        VARCHAR(200) NOT NULL
);

CREATE TABLE naf_groups (
  id           BIGSERIAL PRIMARY KEY,
  division_id  BIGINT NOT NULL REFERENCES naf_divisions(id) ON DELETE CASCADE,
  code         VARCHAR(3) UNIQUE NOT NULL,
  label        VARCHAR(200) NOT NULL
);

CREATE TABLE naf_classes (
  id           BIGSERIAL PRIMARY KEY,
  group_id     BIGINT NOT NULL REFERENCES naf_groups(id) ON DELETE CASCADE,
  code         VARCHAR(5) UNIQUE NOT NULL,
  label        VARCHAR(200) NOT NULL
);

CREATE TABLE naf_subclasses (
  id           BIGSERIAL PRIMARY KEY,
  class_id     BIGINT NOT NULL REFERENCES naf_classes(id) ON DELETE CASCADE,
  code         VARCHAR(7) UNIQUE NOT NULL,               -- ex: '6201Z'
  label        VARCHAR(300) NOT NULL,
  is_axion_priority BOOLEAN NOT NULL DEFAULT FALSE
);
CREATE INDEX naf_subclasses_priority_idx ON naf_subclasses (is_axion_priority) WHERE is_axion_priority = TRUE;
```

### `legal_forms`, `effectif_ranges`

```sql
CREATE TABLE legal_forms (
  id          BIGSERIAL PRIMARY KEY,
  code        VARCHAR(6) UNIQUE NOT NULL,                -- code juridique INSEE
  label       VARCHAR(200) NOT NULL,
  is_business BOOLEAN NOT NULL DEFAULT TRUE,
  family      VARCHAR(40)                                -- 'SARL','SAS','SA','SCI','EI', etc.
);

CREATE TABLE effectif_ranges (
  id          BIGSERIAL PRIMARY KEY,
  code        VARCHAR(4) UNIQUE NOT NULL,                -- code INSEE : NN,00,01,02,03,...,53
  min         INTEGER,
  max         INTEGER,
  label       VARCHAR(80) NOT NULL,
  tier        VARCHAR(10) NOT NULL CHECK (tier IN ('TPE','PME','ETI','GE','UNKNOWN'))
);
```

### `axion_offer_targets`, `strategic_keywords`, `auto_tag_definitions`

```sql
CREATE TABLE axion_offer_targets (
  id              BIGSERIAL PRIMARY KEY,
  offer_key       VARCHAR(40) UNIQUE NOT NULL,           -- 'audit_flash','audit_cible','mission_pme','mission_eti','grand_programme'
  display_name    VARCHAR(120) NOT NULL,
  description     TEXT,
  min_effectif    INTEGER,
  max_effectif    INTEGER,
  preferred_naf_subclasses TEXT[],                       -- codes NAF priorisés
  preferred_signals JSONB,
  display_order   SMALLINT NOT NULL DEFAULT 100
);

CREATE TABLE strategic_keywords (
  id          BIGSERIAL PRIMARY KEY,
  keyword     VARCHAR(80) UNIQUE NOT NULL,
  family      VARCHAR(40),                                -- 'digital','ia','cloud','transformation','data','cybersecurite'
  weight      SMALLINT NOT NULL DEFAULT 10
);

CREATE TABLE auto_tag_definitions (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT REFERENCES workspaces(id) ON DELETE CASCADE,
  tag_key         VARCHAR(60) UNIQUE NOT NULL,
  display_name    VARCHAR(120) NOT NULL,
  color_hex       VARCHAR(7),
  source          VARCHAR(20) NOT NULL CHECK (source IN ('llm','rule','manual')),
  rule_json       JSONB,                                  -- pour 'rule' : condition matching
  active          BOOLEAN NOT NULL DEFAULT TRUE
);
```

---

## 5. Entités scrapées

### `companies`

```sql
CREATE TABLE companies (
  id                  BIGSERIAL PRIMARY KEY,
  uuid                UUID NOT NULL DEFAULT uuid_generate_v4() UNIQUE,
  workspace_id        BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  siren               CHAR(9) UNIQUE,
  siret_head          CHAR(14),
  vat_number          VARCHAR(20),
  legal_name          VARCHAR(255) NOT NULL,
  trade_name          VARCHAR(255),
  legal_form_id       BIGINT REFERENCES legal_forms(id),
  effectif_range_id   BIGINT REFERENCES effectif_ranges(id),
  effectif_estimated  INTEGER,
  revenue_eur         BIGINT,                            -- dernier CA connu
  revenue_year        SMALLINT,
  naf_subclass_id     BIGINT REFERENCES naf_subclasses(id),
  naf_code            VARCHAR(7),
  city_id             BIGINT REFERENCES cities(id),
  country_id          BIGINT NOT NULL REFERENCES countries(id) DEFAULT 1, -- FR
  registered_at       DATE,
  website             VARCHAR(255),
  description         TEXT,
  description_short   VARCHAR(500),                       -- LLM-resumed
  -- Scores et classifications
  axion_offer         VARCHAR(40),                        -- 'audit_flash' / ... / 'non_cible'
  axion_offer_score   SMALLINT,                           -- 0..100
  priority_score      VARCHAR(20) CHECK (priority_score IN ('prioritaire','moyenne','faible','non-cible')),
  priority_override   VARCHAR(20),                        -- override manuel
  priority_override_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
  contact_priority    VARCHAR(10) CHECK (contact_priority IN ('hot','warm','cold','frozen')),
  ia_maturity         VARCHAR(20) CHECK (ia_maturity IN ('decouverte','en_cours','avancee','inconnue')),
  prospection_status  VARCHAR(20) NOT NULL DEFAULT 'decouvert'
                      CHECK (prospection_status IN ('decouvert','enrichi','qualifie','contacte','repondu','client','disqualifie')),
  -- Géocodage
  address_line        VARCHAR(255),
  postal_code         VARCHAR(10),
  geom_point          GEOMETRY(Point, 4326),
  -- Métadonnées scraping
  last_enriched_at    TIMESTAMPTZ,
  last_enriched_sources JSONB,                            -- ['insee','annu_ent','gmaps','website']
  enrichment_score    SMALLINT,                            -- 0..100 (complétude)
  raw_data            JSONB,                                -- raw payload sources (pour audit)
  created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  deleted_at          TIMESTAMPTZ
);
CREATE INDEX companies_ws_idx ON companies (workspace_id) WHERE deleted_at IS NULL;
CREATE INDEX companies_siren_idx ON companies (siren) WHERE siren IS NOT NULL;
CREATE INDEX companies_city_idx ON companies (city_id);
CREATE INDEX companies_naf_idx ON companies (naf_subclass_id);
CREATE INDEX companies_offer_priority_idx ON companies (workspace_id, axion_offer, priority_score)
  WHERE deleted_at IS NULL;
CREATE INDEX companies_legal_name_trgm_idx ON companies USING GIN (legal_name gin_trgm_ops);
CREATE INDEX companies_geom_idx ON companies USING GIST (geom_point) WHERE geom_point IS NOT NULL;
CREATE INDEX companies_last_enriched_idx ON companies (workspace_id, last_enriched_at DESC NULLS LAST);
COMMENT ON COLUMN companies.raw_data IS 'JSONB regroupant les payloads bruts des sources (insee, annu_ent, gmaps...) pour audit + reprise sans re-scraping si besoin.';

ALTER TABLE companies ENABLE ROW LEVEL SECURITY;
CREATE POLICY companies_tenant_isolation ON companies
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());
```

### `contacts`

```sql
CREATE TABLE contacts (
  id                  BIGSERIAL PRIMARY KEY,
  uuid                UUID NOT NULL DEFAULT uuid_generate_v4() UNIQUE,
  workspace_id        BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  company_id          BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
  first_name          VARCHAR(120),
  last_name           VARCHAR(160),
  full_name           VARCHAR(280),
  gender              VARCHAR(8),                         -- m / f / unknown
  position_title      VARCHAR(160),
  position_function   VARCHAR(40),                        -- 'CEO','DRH','DAF','DSI','MARKETING','SALES','LEGAL','OTHER'
  is_legal_representative BOOLEAN NOT NULL DEFAULT FALSE,
  is_executive        BOOLEAN NOT NULL DEFAULT FALSE,
  linkedin_url        VARCHAR(255),
  source              VARCHAR(40) NOT NULL,               -- 'annu_ent','linkedin_pb','website','manual'
  source_url          VARCHAR(500),
  status              VARCHAR(20) NOT NULL DEFAULT 'enriched'
                      CHECK (status IN ('discovered','enriched','validated','disqualified','out_of_scope')),
  notes               TEXT,
  raw_data            JSONB,
  created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  deleted_at          TIMESTAMPTZ
);
CREATE UNIQUE INDEX contacts_company_fullname_idx
  ON contacts (company_id, LOWER(unaccent(COALESCE(full_name,''))))
  WHERE deleted_at IS NULL;
CREATE INDEX contacts_workspace_idx ON contacts (workspace_id) WHERE deleted_at IS NULL;
CREATE INDEX contacts_function_idx ON contacts (workspace_id, position_function);
CREATE INDEX contacts_name_trgm_idx ON contacts USING GIN (full_name gin_trgm_ops);

ALTER TABLE contacts ENABLE ROW LEVEL SECURITY;
CREATE POLICY contacts_tenant_isolation ON contacts
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());
```

### `company_addresses`, `company_phones`, `company_emails`, `company_social_handles`

```sql
CREATE TABLE company_addresses (
  id           BIGSERIAL PRIMARY KEY,
  workspace_id BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  company_id   BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
  is_head_office BOOLEAN NOT NULL DEFAULT FALSE,
  street       VARCHAR(255),
  postal_code  VARCHAR(10),
  city_label   VARCHAR(160),
  city_id      BIGINT REFERENCES cities(id),
  country_id   BIGINT REFERENCES countries(id),
  geom_point   GEOMETRY(Point, 4326),
  source       VARCHAR(40),
  raw_data     JSONB,
  created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX company_addresses_company_idx ON company_addresses (company_id);
CREATE INDEX company_addresses_geom_idx ON company_addresses USING GIST (geom_point);

CREATE TABLE company_phones (
  id           BIGSERIAL PRIMARY KEY,
  workspace_id BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  company_id   BIGINT REFERENCES companies(id) ON DELETE CASCADE,
  contact_id   BIGINT REFERENCES contacts(id) ON DELETE CASCADE,
  phone_e164   VARCHAR(20) NOT NULL,
  phone_kind   VARCHAR(20) CHECK (phone_kind IN ('main','direct','mobile','fax','other')),
  source       VARCHAR(40),
  source_url   VARCHAR(500),
  is_validated BOOLEAN NOT NULL DEFAULT FALSE,
  created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (workspace_id, COALESCE(company_id, 0), COALESCE(contact_id, 0), phone_e164)
);

CREATE TABLE company_emails (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  company_id      BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
  contact_id      BIGINT REFERENCES contacts(id) ON DELETE CASCADE,
  email           VARCHAR(254) NOT NULL,
  email_type      VARCHAR(20) NOT NULL
                  CHECK (email_type IN ('nominative','role_based','generic','no_reply','unknown')),
  is_validated    BOOLEAN NOT NULL DEFAULT FALSE,
  validation_score SMALLINT,                              -- 0..100
  validated_at    TIMESTAMPTZ,
  validation_method VARCHAR(20),                          -- 'syntax','mx','smtp','catchall'
  source          VARCHAR(40),                            -- 'website','pattern_inferred','linkedin','gmaps'
  source_url      VARCHAR(500),
  is_excluded     BOOLEAN NOT NULL DEFAULT FALSE,        -- true pour no_reply
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (workspace_id, company_id, LOWER(email))
);
CREATE INDEX company_emails_company_idx ON company_emails (company_id, email_type);
CREATE INDEX company_emails_validated_idx ON company_emails (workspace_id, is_validated, validation_score DESC);
COMMENT ON COLUMN company_emails.email_type IS 'Classification automatique : nominative (prenom.nom@), role_based (rh@,dsi@,daf@,...), generic (info@,contact@), no_reply (à exclure systématiquement).';

CREATE TABLE company_social_handles (
  id           BIGSERIAL PRIMARY KEY,
  workspace_id BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  company_id   BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
  platform     VARCHAR(20) NOT NULL CHECK (platform IN ('x','linkedin','instagram','tiktok','youtube','facebook','github')),
  handle       VARCHAR(120) NOT NULL,
  url          VARCHAR(500) NOT NULL,
  followers    INTEGER,
  source       VARCHAR(40),
  detected_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (workspace_id, company_id, platform)
);
```

### `schools` (entités spécifiques type établissement enseignement)

```sql
CREATE TABLE schools (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  company_id      BIGINT REFERENCES companies(id) ON DELETE SET NULL,    -- optionnel lien SIREN
  uai             VARCHAR(12),                            -- code UAI MENJ
  school_type     VARCHAR(40) NOT NULL,                   -- 'universite','ecole_ingenieur','ecole_commerce','cfa','college','lycee','primaire'
  name            VARCHAR(255) NOT NULL,
  city_id         BIGINT REFERENCES cities(id),
  website         VARCHAR(255),
  effectif_eleves INTEGER,
  source          VARCHAR(40),                            -- 'mesri','onisep','manual'
  raw_data        JSONB,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (workspace_id, uai)
);
```

### `company_business_signals`

```sql
CREATE TABLE company_business_signals (
  id           BIGSERIAL PRIMARY KEY,
  workspace_id BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  company_id   BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
  signal_type  VARCHAR(40) NOT NULL,                     -- 'leve_fonds','change_dirigeant','redressement','recrut_clevel','create','radiation','transfo_digitale_news'
  signal_severity VARCHAR(10) NOT NULL CHECK (signal_severity IN ('critical','high','medium','low')),
  source       VARCHAR(40),                              -- 'bodacc','france_travail','crunchbase','news_fr'
  source_ref   VARCHAR(500),                             -- URL ou ID source
  detected_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  occurred_at  TIMESTAMPTZ,                              -- date réelle si connue
  expires_at   TIMESTAMPTZ,                              -- signal "frais" combien de temps
  payload      JSONB,
  created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX company_business_signals_company_idx ON company_business_signals (company_id, detected_at DESC);
CREATE INDEX company_business_signals_type_idx ON company_business_signals (workspace_id, signal_type, signal_severity);
```

### `company_strategic_keywords`, `company_tags`

```sql
CREATE TABLE company_strategic_keywords (
  id           BIGSERIAL PRIMARY KEY,
  workspace_id BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  company_id   BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
  keyword_id   BIGINT NOT NULL REFERENCES strategic_keywords(id) ON DELETE CASCADE,
  occurrences  INTEGER NOT NULL DEFAULT 1,
  source_url   VARCHAR(500),
  detected_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (workspace_id, company_id, keyword_id)
);

CREATE TABLE company_tags (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  company_id      BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
  tag_definition_id BIGINT NOT NULL REFERENCES auto_tag_definitions(id) ON DELETE CASCADE,
  applied_by      VARCHAR(20) NOT NULL CHECK (applied_by IN ('llm','rule','manual')),
  applied_by_user BIGINT REFERENCES users(id) ON DELETE SET NULL,
  applied_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (workspace_id, company_id, tag_definition_id)
);
```

---

## 6. Email finder & validation + opt-out

### `email_patterns`

```sql
CREATE TABLE email_patterns (
  id            BIGSERIAL PRIMARY KEY,
  workspace_id  BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  domain        VARCHAR(190) NOT NULL,
  pattern       VARCHAR(60) NOT NULL,                     -- ex: '{first}.{last}' / '{f}{last}' / '{f}.{last}'
  confidence    SMALLINT NOT NULL,                         -- 0..100
  inferred_from JSONB,                                     -- échantillon ayant servi à l'inférence
  detected_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (workspace_id, domain, pattern)
);
```

### `email_verifications`

```sql
CREATE TABLE email_verifications (
  id           BIGSERIAL PRIMARY KEY,
  workspace_id BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  email        VARCHAR(254) NOT NULL,
  status       VARCHAR(20) NOT NULL CHECK (status IN ('valid','invalid','catchall','unknown','greylist','disposable')),
  method       VARCHAR(20) NOT NULL CHECK (method IN ('syntax','mx','smtp','catchall_probe')),
  score        SMALLINT NOT NULL,                         -- 0..100
  smtp_response TEXT,
  mx_records   JSONB,
  is_catchall  BOOLEAN NOT NULL DEFAULT FALSE,
  is_disposable BOOLEAN NOT NULL DEFAULT FALSE,
  ttl_expires_at TIMESTAMPTZ NOT NULL,                    -- now() + 30 jours
  occurred_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX email_verifications_email_idx ON email_verifications (LOWER(email), occurred_at DESC);
CREATE INDEX email_verifications_ttl_idx ON email_verifications (ttl_expires_at);
```

### `opt_out` (cross-workspace)

```sql
CREATE TABLE opt_out (
  id            BIGSERIAL PRIMARY KEY,
  email         VARCHAR(254),
  domain        VARCHAR(190),
  phone_e164    VARCHAR(20),
  reason        VARCHAR(120),
  requested_by  VARCHAR(190),                              -- email ayant fait la demande
  requested_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  expires_at    TIMESTAMPTZ,                               -- NULL = permanent
  source        VARCHAR(40) NOT NULL DEFAULT 'subject_request',
  CHECK (email IS NOT NULL OR domain IS NOT NULL OR phone_e164 IS NOT NULL)
);
CREATE INDEX opt_out_email_idx ON opt_out (LOWER(email)) WHERE email IS NOT NULL;
CREATE INDEX opt_out_domain_idx ON opt_out (LOWER(domain)) WHERE domain IS NOT NULL;
CREATE INDEX opt_out_phone_idx ON opt_out (phone_e164) WHERE phone_e164 IS NOT NULL;
COMMENT ON TABLE opt_out IS 'Cross-workspace. Consulté avant scraping/enrichissement/contact. Pas de RLS — déclarativement global.';
```

---

## 7. Scraping operations

### `scraping_sources`

```sql
CREATE TABLE scraping_sources (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT REFERENCES workspaces(id) ON DELETE CASCADE,
  source_key      VARCHAR(40) UNIQUE NOT NULL,
  display_name    VARCHAR(120) NOT NULL,
  category        VARCHAR(40) NOT NULL,                   -- 'official_api','public_scraping','third_party_paid'
  enabled         BOOLEAN NOT NULL DEFAULT TRUE,
  rate_limit_per_min INTEGER NOT NULL DEFAULT 30,
  ttl_days        INTEGER NOT NULL DEFAULT 30,            -- 30 site web, 90 gmaps, 365 annu_ent, 60 linkedin
  needs_proxy     BOOLEAN NOT NULL DEFAULT FALSE,
  needs_playwright BOOLEAN NOT NULL DEFAULT FALSE,
  config          JSONB NOT NULL DEFAULT '{}'::jsonb,
  last_run_at     TIMESTAMPTZ,
  health_score    SMALLINT,                                -- 0..100
  notes           TEXT,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

### `scraper_targets`

```sql
CREATE TABLE scraper_targets (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  source_key      VARCHAR(40) NOT NULL,
  target_type     VARCHAR(20) NOT NULL CHECK (target_type IN ('company','contact','zone','url','siren')),
  target_id       BIGINT,                                   -- ref companies.id / contacts.id / cities.id
  target_payload  JSONB NOT NULL,                            -- {"siren":"...","url":"..."} ou {"city_insee":"75056","naf":"6201Z"}
  state           VARCHAR(20) NOT NULL DEFAULT 'pending'
                  CHECK (state IN ('pending','running','done','skipped','failed','rate_limited','dead_letter')),
  priority        SMALLINT NOT NULL DEFAULT 100,
  attempts        SMALLINT NOT NULL DEFAULT 0,
  max_attempts    SMALLINT NOT NULL DEFAULT 5,
  last_page_scraped INTEGER NOT NULL DEFAULT 0,              -- pour pagination sans limite
  pagination_meta JSONB,                                     -- token, cursor, etc.
  scheduled_for   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  next_attempt_at TIMESTAMPTZ,
  last_run_id     BIGINT,
  cooldown_until  TIMESTAMPTZ,
  fingerprint     VARCHAR(80),                              -- hash(target_type+payload+ttl bucket) pour dedup
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (workspace_id, source_key, fingerprint)
);
CREATE INDEX scraper_targets_pending_idx ON scraper_targets (workspace_id, source_key, state, scheduled_for)
  WHERE state IN ('pending','rate_limited');
CREATE INDEX scraper_targets_priority_idx ON scraper_targets (workspace_id, priority DESC, scheduled_for)
  WHERE state = 'pending';
```

### `scraper_runs` (partitionnée mois)

```sql
CREATE TABLE scraper_runs (
  id                BIGSERIAL,
  workspace_id      BIGINT NOT NULL,
  scraper_name      VARCHAR(40) NOT NULL,
  source_key        VARCHAR(40) NOT NULL,
  target_id         BIGINT,
  target_payload    JSONB,
  triggered_by      VARCHAR(40) NOT NULL,                   -- 'user:<id>' / 'system' / 'cron:<job>'
  started_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  ended_at          TIMESTAMPTZ,
  duration_ms       INTEGER,
  status            VARCHAR(20) NOT NULL DEFAULT 'running'
                    CHECK (status IN ('running','ok','skipped','rate_limited','circuit_broken','error','banned','dead_letter')),
  http_status       INTEGER,
  proxy_id          BIGINT,
  user_agent_id     BIGINT,
  llm_provider      VARCHAR(40),
  llm_model         VARCHAR(80),
  tokens_consumed   INTEGER NOT NULL DEFAULT 0,
  cost_eur_micro    BIGINT NOT NULL DEFAULT 0,
  contacts_found    INTEGER NOT NULL DEFAULT 0,
  contacts_new      INTEGER NOT NULL DEFAULT 0,
  emails_found      INTEGER NOT NULL DEFAULT 0,
  emails_validated  INTEGER NOT NULL DEFAULT 0,
  error_message     TEXT,
  error_stacktrace  TEXT,
  meta              JSONB,
  PRIMARY KEY (id, started_at)
) PARTITION BY RANGE (started_at);

SELECT partman.create_parent(
  p_parent_table => 'public.scraper_runs',
  p_control => 'started_at',
  p_type => 'native',
  p_interval => 'monthly',
  p_premake => 12
);

CREATE INDEX scraper_runs_ws_source_idx ON scraper_runs (workspace_id, source_key, started_at DESC);
CREATE INDEX scraper_runs_status_idx ON scraper_runs (workspace_id, status, started_at DESC);
CREATE INDEX scraper_runs_target_idx ON scraper_runs (workspace_id, target_id, started_at DESC) WHERE target_id IS NOT NULL;
```

### `enrichment_runs`

```sql
CREATE TABLE enrichment_runs (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  company_id      BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
  state           VARCHAR(30) NOT NULL,                    -- géré par state machine Spatie
  current_step    VARCHAR(40),
  steps_completed JSONB NOT NULL DEFAULT '[]'::jsonb,
  started_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  ended_at        TIMESTAMPTZ,
  duration_ms     INTEGER,
  error_count     INTEGER NOT NULL DEFAULT 0,
  contacts_added  INTEGER NOT NULL DEFAULT 0,
  emails_added    INTEGER NOT NULL DEFAULT 0,
  cost_eur_micro  BIGINT NOT NULL DEFAULT 0,
  notes           TEXT
);
CREATE INDEX enrichment_runs_company_idx ON enrichment_runs (company_id, started_at DESC);
```

### `duplicate_flags`

```sql
CREATE TABLE duplicate_flags (
  id           BIGSERIAL PRIMARY KEY,
  workspace_id BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  entity_type  VARCHAR(20) NOT NULL,                       -- 'company' / 'contact'
  entity_id    BIGINT NOT NULL,
  duplicate_of_entity_id BIGINT NOT NULL,
  similarity_score NUMERIC(5,4),                            -- 0..1 pg_trgm
  rationale    TEXT,
  resolved     BOOLEAN NOT NULL DEFAULT FALSE,
  resolved_by  BIGINT REFERENCES users(id),
  resolved_at  TIMESTAMPTZ,
  detected_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX duplicate_flags_unresolved_idx ON duplicate_flags (workspace_id, entity_type) WHERE resolved = FALSE;
```

---

## 8. Coverage tracking

### `coverage_matrix_cells` (materialized view)

```sql
CREATE MATERIALIZED VIEW coverage_matrix_cells AS
SELECT
  c.workspace_id,
  r.id   AS region_id,
  d.id   AS department_id,
  ci.id  AS city_id,
  ns.id  AS naf_section_id,
  nd.id  AS naf_division_id,
  er.tier AS tier,
  c.ia_maturity,
  c.axion_offer,
  c.priority_score,
  c.prospection_status,
  COUNT(*)                       AS total_companies,
  COUNT(*) FILTER (WHERE c.last_enriched_at IS NOT NULL) AS enriched_companies,
  COUNT(*) FILTER (WHERE c.enrichment_score >= 80)        AS richly_enriched_companies,
  MIN(c.last_enriched_at)        AS earliest_enriched_at,
  MAX(c.last_enriched_at)        AS latest_enriched_at
FROM companies c
LEFT JOIN cities       ci ON ci.id = c.city_id
LEFT JOIN departments  d  ON d.id  = ci.department_id
LEFT JOIN regions      r  ON r.id  = d.region_id
LEFT JOIN naf_subclasses nsc ON nsc.id = c.naf_subclass_id
LEFT JOIN naf_classes  nc ON nc.id = nsc.class_id
LEFT JOIN naf_groups   ng ON ng.id = nc.group_id
LEFT JOIN naf_divisions nd ON nd.id = ng.division_id
LEFT JOIN naf_sections ns ON ns.id = nd.section_id
LEFT JOIN effectif_ranges er ON er.id = c.effectif_range_id
WHERE c.deleted_at IS NULL
GROUP BY 1,2,3,4,5,6,7,8,9,10,11;

CREATE UNIQUE INDEX coverage_matrix_cells_uk ON coverage_matrix_cells
  (workspace_id, region_id, department_id, city_id, naf_section_id, naf_division_id, tier, ia_maturity, axion_offer, priority_score, prospection_status);
CREATE INDEX coverage_matrix_cells_ws_idx ON coverage_matrix_cells (workspace_id);
COMMENT ON MATERIALIZED VIEW coverage_matrix_cells IS 'Refresh hourly via cron. Permet de répondre instantanément à toute question coverage par croisement de 10 dimensions.';
```

### `target_zones`

```sql
CREATE TABLE target_zones (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  name            VARCHAR(120) NOT NULL,
  zone_type       VARCHAR(20) NOT NULL CHECK (zone_type IN ('region','department','city','custom_polygon')),
  zone_ref        JSONB NOT NULL,                          -- {"insee_code":"75"} ou polygon
  filters         JSONB,                                    -- NAF, tier, etc.
  priority_score  NUMERIC(5,2),                              -- calculé par algo "prochaine zone à attaquer"
  status          VARCHAR(20) NOT NULL DEFAULT 'idle'
                  CHECK (status IN ('idle','queued','running','completed','paused')),
  total_targeted  INTEGER NOT NULL DEFAULT 0,
  total_enriched  INTEGER NOT NULL DEFAULT 0,
  scheduled_at    TIMESTAMPTZ,
  completed_at    TIMESTAMPTZ,
  created_by      BIGINT REFERENCES users(id),
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX target_zones_ws_status_idx ON target_zones (workspace_id, status, priority_score DESC);
```

---

## 9. RGPD + AI Act

### `data_processing_log`

```sql
CREATE TABLE data_processing_log (
  id            BIGSERIAL PRIMARY KEY,
  workspace_id  BIGINT NOT NULL REFERENCES workspaces(id),
  processing_key VARCHAR(60) NOT NULL,                    -- 'prospection_b2b','enrichment_legal','signal_detection'
  legal_basis   VARCHAR(40) NOT NULL,                     -- 'legitimate_interest_b2b','consent','contract'
  purpose       TEXT NOT NULL,
  data_categories TEXT[],                                  -- ['identity_pro','contact_pro','public_business']
  retention_days INTEGER NOT NULL,
  subprocessors JSONB,                                     -- ['anthropic','openai','mistral','phantombuster','webshare']
  documentation_url VARCHAR(500),
  created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

### `gdpr_requests`

```sql
CREATE TABLE gdpr_requests (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT REFERENCES workspaces(id),
  request_type    VARCHAR(20) NOT NULL CHECK (request_type IN ('access','rectification','erasure','restriction','portability','objection')),
  subject_name    VARCHAR(255),
  subject_email   VARCHAR(254),
  subject_phone   VARCHAR(20),
  evidence_url    VARCHAR(500),
  received_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  deadline_at     TIMESTAMPTZ NOT NULL,                    -- received + 30 jours
  status          VARCHAR(20) NOT NULL DEFAULT 'open'
                  CHECK (status IN ('open','in_progress','completed','rejected')),
  processed_by    BIGINT REFERENCES users(id),
  processed_at    TIMESTAMPTZ,
  notes           TEXT,
  affected_entities JSONB                                   -- {"companies":[1,2],"contacts":[3],"emails":[...]}
);
CREATE INDEX gdpr_requests_deadline_idx ON gdpr_requests (status, deadline_at);
```

### `ai_act_register`

```sql
CREATE TABLE ai_act_register (
  id                BIGSERIAL PRIMARY KEY,
  workspace_id      BIGINT REFERENCES workspaces(id),
  use_case_key      VARCHAR(80) NOT NULL,
  risk_class        VARCHAR(20) NOT NULL CHECK (risk_class IN ('minimal','limited','high','unacceptable')),
  provider          VARCHAR(40) NOT NULL,
  model             VARCHAR(80) NOT NULL,
  purpose           TEXT NOT NULL,
  decision_impact   TEXT NOT NULL,                        -- explication de l'usage
  human_review_required BOOLEAN NOT NULL DEFAULT TRUE,
  transparency_doc_url VARCHAR(500),
  registered_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

---

## 10. Application des RLS sur tables tenant-scoped

> Liste exhaustive des tables qui DOIVENT activer RLS :
> `companies`, `contacts`, `company_addresses`, `company_phones`, `company_emails`, `company_social_handles`, `company_business_signals`, `company_strategic_keywords`, `company_tags`, `schools`, `email_patterns`, `email_verifications`, `scraper_targets`, `enrichment_runs`, `duplicate_flags`, `target_zones`, `scraping_sources`, `proxy_providers`, `proxies`, `linkedin_accounts`, `linkedin_account_health`, `scraper_rotation_state`, `auto_tag_definitions`, `llm_use_cases`, `llm_providers`, `prompt_templates`, `prompt_template_versions`, `gdpr_requests`, `data_processing_log`, `ai_act_register`, `audit_logs`, `invitations`, `roles`, `permissions`.
>
> **Politique systématique :**
> ```sql
> ALTER TABLE <table> ENABLE ROW LEVEL SECURITY;
> CREATE POLICY <table>_tenant_isolation ON <table>
>   USING (workspace_id = app_workspace_id())
>   WITH CHECK (workspace_id = app_workspace_id());
> ```
> Les tables référentiels GLOBAUX (`countries`, `regions`, `departments`, `cities`, `naf_*`, `legal_forms`, `effectif_ranges`, `strategic_keywords`, `axion_offer_targets`, `user_agents`, `opt_out`) NE PAS activer RLS — elles sont communes à tous les tenants.
> `users` n'active pas RLS — l'accès est filtré applicativement par `user_workspaces`.
> Le middleware Laravel injecte `SET LOCAL app.workspace_id = ?` au début de chaque transaction.

---

## 11. Bilan tables Phase 1

| Catégorie | Tables |
|---|---|
| Multi-tenant & Auth | `workspaces`, `users`, `user_workspaces`, `roles`, `permissions`, `role_has_permissions`, `model_has_roles`, `model_has_permissions`, `invitations`, `sessions`, `audit_logs` |
| LLM Router | `llm_providers`, `llm_use_cases`, `prompt_templates`, `prompt_template_versions`, `llm_usage` (part.) |
| Rotations & Proxies | `proxy_providers`, `proxies`, `proxy_health_checks`, `proxy_usage_log` (part.), `user_agents`, `scraper_rotation_state`, `linkedin_accounts`, `linkedin_account_health`, `rotation_events` |
| Référentiel géo + NAF | `countries`, `regions`, `departments`, `cities`, `naf_sections`, `naf_divisions`, `naf_groups`, `naf_classes`, `naf_subclasses`, `legal_forms`, `effectif_ranges`, `axion_offer_targets`, `strategic_keywords`, `auto_tag_definitions` |
| Entités scrapées | `companies`, `contacts`, `company_addresses`, `company_phones`, `company_emails`, `company_social_handles`, `schools`, `company_business_signals`, `company_strategic_keywords`, `company_tags` |
| Email finder | `email_patterns`, `email_verifications`, `opt_out` |
| Scraping operations | `scraping_sources`, `scraper_targets`, `scraper_runs` (part.), `enrichment_runs`, `duplicate_flags` |
| Coverage tracking | `coverage_matrix_cells` (mat. view), `target_zones` |
| RGPD + AI Act | `data_processing_log`, `gdpr_requests`, `ai_act_register` |
| **TOTAL Phase 1** | **~52 tables + 1 mat. view** |

---

## 12. Refresh cycles à programmer

| Élément | Fréquence | Job |
|---|---|---|
| `coverage_matrix_cells` REFRESH MATERIALIZED VIEW CONCURRENTLY | Toutes les heures | `RefreshCoverageMatrixJob` |
| Vérification intégrité hash chain `audit_logs` | Quotidien 03:30 | `VerifyAuditLogIntegrityJob` |
| Purge `email_verifications` expirés | Quotidien 04:00 | `PurgeExpiredEmailVerificationsJob` |
| Pre-create partitions pg_partman (12 mois ahead) | Hebdomadaire dim 02:00 | `partman.run_maintenance()` |
| Recalcul `priority_score` companies | Quotidien 02:00 | `RecalculatePriorityScoresJob` |
| Health checks proxies (mass) | Toutes les 15 min | `BatchProxyHealthCheckJob` |

---

## Prochaine étape

→ Lire `04_db_schema_phase2_scaffold.md` pour les ~30 tables Phase 2 scaffoldées (logique vide).
