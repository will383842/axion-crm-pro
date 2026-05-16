# 03 — DB schema Phase 1 (PostgreSQL 16)

> **Scope :** Toutes les tables Phase 1, exécutables telles quelles sur PostgreSQL 16 avec extensions activées (cf. `02_architecture_infra.md` § Data).
> **Conventions :** snake_case, UUID v7 (`gen_random_uuid()` puis migration `uuidv7()` quand pg_uuidv7 dispo), timestamps `TIMESTAMPTZ`, montants `NUMERIC(12,2)` en €.
> **Multi-tenant :** `workspace_id` partout sauf 3 tables globales (`opt_out`, `countries`, `naf_*`). RLS policies en fin de fichier.
> **Partitionnement :** pg_partman pour `scraper_runs`, `llm_usage`, `audit_logs`, `proxy_usage_log`, `email_sends` (Phase 2).

---

## Pré-requis

```sql
-- À exécuter une fois en tant que superuser
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS pgvector;
CREATE EXTENSION IF NOT EXISTS pg_partman SCHEMA partman;
CREATE EXTENSION IF NOT EXISTS pg_cron;
CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE EXTENSION IF NOT EXISTS btree_gin;
CREATE EXTENSION IF NOT EXISTS citext;     -- emails case-insensitive

-- Fonction helper de normalisation (utilisée par hash dédup contacts)
CREATE OR REPLACE FUNCTION normalize_name(input TEXT) RETURNS TEXT AS $$
  SELECT lower(unaccent(regexp_replace(
    regexp_replace(coalesce(input, ''), '\s+', ' ', 'g'),
    '\m(de|du|la|le|les|d|l)\M\s+', '', 'gi'
  )))
$$ LANGUAGE SQL IMMUTABLE;
```

---

## §1 — Multi-tenant, auth, audit (9 tables)

### `workspaces`

```sql
CREATE TABLE workspaces (
    id              UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    slug            CITEXT      NOT NULL UNIQUE,
    name            TEXT        NOT NULL,
    settings        JSONB       NOT NULL DEFAULT '{}'::jsonb,
    cost_cap_eur    NUMERIC(10,2) NOT NULL DEFAULT 500.00,    -- kill-switch LLM mensuel
    is_active       BOOLEAN     NOT NULL DEFAULT true,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at      TIMESTAMPTZ
);
CREATE INDEX idx_workspaces_slug_active ON workspaces (slug) WHERE deleted_at IS NULL;
COMMENT ON TABLE workspaces IS 'Tenant logique. Démarrage mono-tenant "axion-ia".';
```

### `users`

```sql
CREATE TABLE users (
    id                       UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    email                    CITEXT      NOT NULL UNIQUE,
    password_hash            TEXT,                                    -- nullable si magic-link only
    name                     TEXT        NOT NULL,
    avatar_url               TEXT,
    locale                   TEXT        NOT NULL DEFAULT 'fr',
    timezone                 TEXT        NOT NULL DEFAULT 'Europe/Paris',
    totp_secret              TEXT,                                    -- chiffré application-side avant insert
    totp_enabled_at          TIMESTAMPTZ,
    totp_recovery_codes      TEXT[],                                  -- hashes bcrypt
    last_login_at            TIMESTAMPTZ,
    last_login_ip            INET,
    last_login_user_agent    TEXT,
    failed_login_count       INT         NOT NULL DEFAULT 0,
    locked_until             TIMESTAMPTZ,
    email_verified_at        TIMESTAMPTZ,
    created_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at               TIMESTAMPTZ
);
CREATE INDEX idx_users_email_active ON users (email) WHERE deleted_at IS NULL;
COMMENT ON COLUMN users.totp_secret IS 'AES-256-GCM chiffré côté application (APP_KEY). NE JAMAIS exposer.';
```

### `user_workspaces` (table de jointure many-to-many)

```sql
CREATE TABLE user_workspaces (
    user_id       UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    role_slug     TEXT NOT NULL,                          -- 'owner'|'admin'|'operator'|'viewer'
    invited_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    joined_at     TIMESTAMPTZ,
    revoked_at    TIMESTAMPTZ,
    PRIMARY KEY (user_id, workspace_id)
);
CREATE INDEX idx_user_workspaces_workspace ON user_workspaces (workspace_id) WHERE revoked_at IS NULL;
```

### `roles`, `permissions`, `role_permissions` (Spatie Permission)

```sql
CREATE TABLE roles (
    id           BIGSERIAL PRIMARY KEY,
    workspace_id UUID REFERENCES workspaces(id) ON DELETE CASCADE,  -- NULL = global
    name         TEXT NOT NULL,
    slug         TEXT NOT NULL,
    guard_name   TEXT NOT NULL DEFAULT 'web',
    description  TEXT,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (workspace_id, slug, guard_name)
);

CREATE TABLE permissions (
    id           BIGSERIAL PRIMARY KEY,
    name         TEXT NOT NULL,
    slug         TEXT NOT NULL UNIQUE,
    guard_name   TEXT NOT NULL DEFAULT 'web',
    description  TEXT,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE role_permissions (
    role_id        BIGINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    permission_id  BIGINT NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
    PRIMARY KEY (role_id, permission_id)
);

-- Seed permissions Phase 1 (extrait)
INSERT INTO permissions (name, slug) VALUES
  ('Voir entreprises',       'companies.view'),
  ('Créer entreprises',      'companies.create'),
  ('Éditer entreprises',     'companies.update'),
  ('Supprimer entreprises',  'companies.delete'),
  ('Lancer scraping',        'scraping.run'),
  ('Config sources scraping','scraping.config'),
  ('Config LLM router',      'llm.config'),
  ('Voir RGPD requests',     'rgpd.view'),
  ('Traiter RGPD requests',  'rgpd.handle'),
  ('Gérer workspaces',       'workspaces.manage'),
  ('Voir audit log',         'audit.view'),
  ('Export données',         'data.export')
ON CONFLICT DO NOTHING;
```

### `invitations`

```sql
CREATE TABLE invitations (
    id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id   UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    email          CITEXT NOT NULL,
    role_slug      TEXT NOT NULL,
    invited_by     UUID NOT NULL REFERENCES users(id),
    token_hash     TEXT NOT NULL UNIQUE,             -- SHA-256 du token (token plain envoyé email)
    expires_at     TIMESTAMPTZ NOT NULL,
    accepted_at    TIMESTAMPTZ,
    accepted_by    UUID REFERENCES users(id),
    revoked_at     TIMESTAMPTZ,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_invitations_pending ON invitations (workspace_id, email)
  WHERE accepted_at IS NULL AND revoked_at IS NULL AND expires_at > now();
```

### `sessions` (Laravel default)

```sql
CREATE TABLE sessions (
    id            TEXT PRIMARY KEY,
    user_id       UUID REFERENCES users(id) ON DELETE CASCADE,
    workspace_id  UUID REFERENCES workspaces(id) ON DELETE SET NULL,
    ip_address    INET,
    user_agent    TEXT,
    payload       TEXT NOT NULL,
    last_activity INT NOT NULL                  -- unix timestamp
);
CREATE INDEX idx_sessions_user ON sessions (user_id);
CREATE INDEX idx_sessions_last_activity ON sessions (last_activity);
```

### `audit_logs` (PARTITIONNÉE par mois + hash chain)

```sql
CREATE TABLE audit_logs (
    id              BIGSERIAL,
    workspace_id    UUID,
    user_id         UUID REFERENCES users(id) ON DELETE SET NULL,
    action          TEXT NOT NULL,                                      -- ex: 'company.update', 'scraping.run.start'
    resource_type   TEXT,
    resource_id     TEXT,
    changes         JSONB,                                              -- diff before/after
    ip_address      INET,
    user_agent      TEXT,
    metadata        JSONB NOT NULL DEFAULT '{}'::jsonb,
    previous_hash   TEXT NOT NULL DEFAULT 'GENESIS',
    record_hash     TEXT NOT NULL,                                      -- sha256(previous_hash || action || resource_type || resource_id || changes || ts)
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at);

-- Partman setup
SELECT partman.create_parent(
    p_parent_table => 'public.audit_logs',
    p_control => 'created_at',
    p_type => 'native',
    p_interval => '1 month',
    p_premake => 6,
    p_start_partition => to_char(date_trunc('month', now()), 'YYYY-MM-DD')
);
UPDATE partman.part_config SET retention = '24 months', retention_keep_table = true WHERE parent_table = 'public.audit_logs';

CREATE INDEX idx_audit_workspace_created ON audit_logs (workspace_id, created_at DESC);
CREATE INDEX idx_audit_user_created ON audit_logs (user_id, created_at DESC);
CREATE INDEX idx_audit_resource ON audit_logs (resource_type, resource_id);
```

---

## §2 — Référentiels géo (4 tables — globales sauf city overrides)

### `countries`, `regions`, `departments`, `cities`

```sql
CREATE TABLE countries (
    code_iso2   TEXT PRIMARY KEY,                  -- 'FR', 'BE', 'CH'
    code_iso3   TEXT NOT NULL UNIQUE,              -- 'FRA'
    name_fr     TEXT NOT NULL,
    name_en     TEXT NOT NULL,
    eu_member   BOOLEAN NOT NULL DEFAULT false,
    currency    TEXT NOT NULL DEFAULT 'EUR',
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE regions (
    code         TEXT PRIMARY KEY,                  -- INSEE region code, ex: '11' (Île-de-France)
    country_code TEXT NOT NULL REFERENCES countries(code_iso2),
    name         TEXT NOT NULL,
    geometry     geometry(MultiPolygon, 4326),      -- IGN AdminExpress simplified
    population   INT,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_regions_geometry ON regions USING gist (geometry);

CREATE TABLE departments (
    code         TEXT PRIMARY KEY,                  -- INSEE dept code, ex: '75', '2A', '971'
    region_code  TEXT NOT NULL REFERENCES regions(code),
    name         TEXT NOT NULL,
    geometry     geometry(MultiPolygon, 4326),
    population   INT,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_departments_geometry ON departments USING gist (geometry);
CREATE INDEX idx_departments_region ON departments (region_code);

CREATE TABLE cities (
    code_insee    TEXT PRIMARY KEY,                 -- 5 chars, ex: '75056'
    department    TEXT NOT NULL REFERENCES departments(code),
    name          TEXT NOT NULL,
    slug          TEXT NOT NULL,
    postal_codes  TEXT[] NOT NULL,
    population    INT NOT NULL DEFAULT 0,
    geometry      geometry(MultiPolygon, 4326),
    centroid      geometry(Point, 4326),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_cities_dept ON cities (department);
CREATE INDEX idx_cities_population ON cities (population DESC);
CREATE INDEX idx_cities_slug ON cities (slug);
CREATE INDEX idx_cities_geometry ON cities USING gist (geometry);
CREATE INDEX idx_cities_centroid ON cities USING gist (centroid);
CREATE INDEX idx_cities_name_trgm ON cities USING gin (name gin_trgm_ops);
```

**Source d'import :** IGN AdminExpress COG 2026 (cf. `23_interfaces_phase2_execution_pack.md` § B.3 pour commande artisan).

---

## §3 — Référentiels secteurs & business (8 tables — globales)

### NAF (5 niveaux)

```sql
CREATE TABLE naf_sections (
    code        CHAR(1) PRIMARY KEY,        -- 'A'..'U'
    label       TEXT NOT NULL
);

CREATE TABLE naf_divisions (
    code         CHAR(2) PRIMARY KEY,        -- '01'..'99'
    section_code CHAR(1) NOT NULL REFERENCES naf_sections(code),
    label        TEXT NOT NULL
);
CREATE INDEX idx_naf_div_section ON naf_divisions (section_code);

CREATE TABLE naf_groups (
    code          CHAR(3) PRIMARY KEY,
    division_code CHAR(2) NOT NULL REFERENCES naf_divisions(code),
    label         TEXT NOT NULL
);

CREATE TABLE naf_classes (
    code        CHAR(4) PRIMARY KEY,
    group_code  CHAR(3) NOT NULL REFERENCES naf_groups(code),
    label       TEXT NOT NULL
);

CREATE TABLE naf_subclasses (
    code        TEXT PRIMARY KEY,            -- ex: '6201Z', '8559A'
    class_code  CHAR(4) NOT NULL REFERENCES naf_classes(code),
    label       TEXT NOT NULL,
    label_long  TEXT
);
CREATE INDEX idx_naf_subclass_class ON naf_subclasses (class_code);
CREATE INDEX idx_naf_subclass_label_trgm ON naf_subclasses USING gin (label gin_trgm_ops);
```

### `legal_forms`

```sql
CREATE TABLE legal_forms (
    code         TEXT PRIMARY KEY,            -- INSEE code, ex: '5710' (SAS), '5499' (SCI)
    label        TEXT NOT NULL,
    category     TEXT,                        -- 'commerciale', 'civile', 'association', etc.
    is_corporate BOOLEAN NOT NULL DEFAULT true
);
```

### `effectif_ranges`

```sql
CREATE TABLE effectif_ranges (
    code           TEXT PRIMARY KEY,          -- INSEE: '00', '01', '02', '03', '11', '12', ...
    label          TEXT NOT NULL,             -- '0 salarié', '1-2 salariés', etc.
    min_employees  INT NOT NULL,
    max_employees  INT,                       -- NULL = no upper bound
    size_category  TEXT NOT NULL              -- 'tpe', 'pme', 'eti', 'ge'
);
INSERT INTO effectif_ranges VALUES
  ('00','0 salarié',0,0,'tpe'),
  ('01','1-2 salariés',1,2,'tpe'),
  ('02','3-5 salariés',3,5,'tpe'),
  ('03','6-9 salariés',6,9,'tpe'),
  ('11','10-19 salariés',10,19,'tpe'),
  ('12','20-49 salariés',20,49,'pme'),
  ('21','50-99 salariés',50,99,'pme'),
  ('22','100-199 salariés',100,199,'pme'),
  ('31','200-249 salariés',200,249,'pme'),
  ('32','250-499 salariés',250,499,'eti'),
  ('41','500-999 salariés',500,999,'eti'),
  ('42','1000-1999 salariés',1000,1999,'eti'),
  ('51','2000-4999 salariés',2000,4999,'eti'),
  ('52','5000-9999 salariés',5000,9999,'ge'),
  ('53','10000+ salariés',10000,NULL,'ge');
```

### `axion_offer_targets`

```sql
CREATE TABLE axion_offer_targets (
    id                   BIGSERIAL PRIMARY KEY,
    workspace_id         UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    offer_code           TEXT NOT NULL,        -- 'audit_flash', 'audit_essentielle', 'mission_pme', 'mission_eti', 'grand_programme'
    label                TEXT NOT NULL,
    target_size_min      INT,                  -- effectif min
    target_size_max      INT,
    target_revenue_min   NUMERIC(14,2),
    target_revenue_max   NUMERIC(14,2),
    naf_sections_in      CHAR(1)[],
    naf_subclasses_in    TEXT[],
    naf_subclasses_out   TEXT[],
    keywords_must        TEXT[],               -- "transformation", "IA", "data"
    keywords_should      TEXT[],
    score_weight         NUMERIC(4,2) NOT NULL DEFAULT 1.0,
    is_active            BOOLEAN NOT NULL DEFAULT true,
    created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (workspace_id, offer_code)
);
```

### `strategic_keywords`

```sql
CREATE TABLE strategic_keywords (
    id            BIGSERIAL PRIMARY KEY,
    workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    keyword       TEXT NOT NULL,
    aliases       TEXT[],
    category      TEXT NOT NULL,                -- 'digital', 'ia', 'cloud', 'data', 'cyber', 'transformation', 'autre'
    weight        NUMERIC(4,2) NOT NULL DEFAULT 1.0,
    is_active     BOOLEAN NOT NULL DEFAULT true,
    UNIQUE (workspace_id, keyword)
);
CREATE INDEX idx_strategic_kw_workspace ON strategic_keywords (workspace_id) WHERE is_active = true;
```

### `auto_tag_definitions`

```sql
CREATE TABLE auto_tag_definitions (
    id            BIGSERIAL PRIMARY KEY,
    workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    tag_slug      TEXT NOT NULL,
    tag_label     TEXT NOT NULL,
    color         TEXT NOT NULL DEFAULT '#888',
    rule_dsl      JSONB NOT NULL,               -- ex: { "all": [ { "naf_section": "J" }, { "size_category_in": ["pme","eti"] } ] }
    is_active     BOOLEAN NOT NULL DEFAULT true,
    UNIQUE (workspace_id, tag_slug)
);
```

---

## §4 — Entités scrapées (10 tables)

### `companies` (la table maîtresse)

```sql
CREATE TABLE companies (
    id                       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id             UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,

    -- Identification
    siren                    CHAR(9),
    siret_siege              CHAR(14),
    legal_name               TEXT NOT NULL,
    legal_name_normalized    TEXT GENERATED ALWAYS AS (normalize_name(legal_name)) STORED,
    brand_name               TEXT,
    legal_form_code          TEXT REFERENCES legal_forms(code),
    naf_subclass_code        TEXT REFERENCES naf_subclasses(code),
    creation_date            DATE,

    -- Effectif & taille
    effectif_range_code      TEXT REFERENCES effectif_ranges(code),
    effectif_min             INT,
    effectif_max             INT,
    size_category            TEXT,                 -- 'tpe'|'pme'|'eti'|'ge'
    revenue_eur              NUMERIC(14,2),
    revenue_year             INT,
    is_public_company        BOOLEAN NOT NULL DEFAULT false,
    is_listed                BOOLEAN NOT NULL DEFAULT false,        -- coté Euronext etc.

    -- Localisation principale
    city_insee               TEXT REFERENCES cities(code_insee),
    department_code          TEXT REFERENCES departments(code),
    region_code              TEXT REFERENCES regions(code),
    country_code             TEXT NOT NULL DEFAULT 'FR' REFERENCES countries(code_iso2),
    headquarter_geom         geometry(Point, 4326),

    -- Contact entreprise
    website_url              TEXT,
    main_phone               TEXT,
    main_email               CITEXT,
    linkedin_url             TEXT,                 -- ex: linkedin.com/company/axion-ia
    twitter_handle           TEXT,

    -- Classification IA
    ia_maturity_score        SMALLINT,             -- 0-100, NULL si pas classifié
    ia_maturity_label        TEXT,                 -- 'decouverte'|'en_cours'|'avancee'
    axion_offer_match_code   TEXT,                 -- offer_code recommandé
    axion_offer_match_score  SMALLINT,             -- 0-100
    priority_label           TEXT,                 -- 'prioritaire'|'moyenne'|'faible'|'non_cible'
    priority_override        TEXT,                 -- override manuel humain

    -- Qualité fiche (computed)
    quality_score            TEXT NOT NULL DEFAULT 'basic',         -- 'complete'|'partial'|'basic'
    quality_recomputed_at    TIMESTAMPTZ,

    -- Statut prospection
    prospection_status       TEXT NOT NULL DEFAULT 'discovered',    -- discovered|enriched|qualified|contacted|replied|customer|disqualified
    contact_priority         TEXT,                                  -- 'hot'|'warm'|'cold'|'frozen'

    -- Tags
    tags                     TEXT[] NOT NULL DEFAULT '{}',
    auto_tags                TEXT[] NOT NULL DEFAULT '{}',

    -- Métadonnées scraping
    first_seen_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_enriched_at         TIMESTAMPTZ,
    enrichment_attempts      INT NOT NULL DEFAULT 0,
    discovery_source         TEXT,                 -- 'insee_batch'|'manual'|'import'|'signal_business'
    notes                    TEXT,

    -- Timestamps
    created_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at               TIMESTAMPTZ,

    -- Anti-doublon HARD
    CONSTRAINT companies_workspace_siren_unique UNIQUE (workspace_id, siren) DEFERRABLE INITIALLY DEFERRED
);

CREATE INDEX idx_companies_workspace ON companies (workspace_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_companies_siren ON companies (siren) WHERE siren IS NOT NULL AND deleted_at IS NULL;
CREATE INDEX idx_companies_size_priority ON companies (workspace_id, size_category, priority_label) WHERE deleted_at IS NULL;
CREATE INDEX idx_companies_quality ON companies (workspace_id, quality_score, prospection_status) WHERE deleted_at IS NULL;
CREATE INDEX idx_companies_naf ON companies (workspace_id, naf_subclass_code) WHERE deleted_at IS NULL;
CREATE INDEX idx_companies_city ON companies (workspace_id, city_insee) WHERE deleted_at IS NULL;
CREATE INDEX idx_companies_geom ON companies USING gist (headquarter_geom);
CREATE INDEX idx_companies_legal_name_trgm ON companies USING gin (legal_name gin_trgm_ops);
CREATE INDEX idx_companies_tags ON companies USING gin (tags);
CREATE INDEX idx_companies_auto_tags ON companies USING gin (auto_tags);
CREATE INDEX idx_companies_last_enriched ON companies (workspace_id, last_enriched_at NULLS FIRST);

COMMENT ON COLUMN companies.quality_score IS '"complete" (🟢) = email validé ≥70 + nom décideur + tel + linkedin; "partial" (🟡) = email OR linkedin + nom + 1 autre; "basic" (🔴) = INSEE + éventuel tel';
```

### `contacts` (personnes physiques)

```sql
CREATE TABLE contacts (
    id                    UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id          UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    company_id            UUID NOT NULL REFERENCES companies(id) ON DELETE CASCADE,

    -- Identité
    first_name            TEXT,
    last_name             TEXT NOT NULL,
    full_name_normalized  TEXT GENERATED ALWAYS AS (
        normalize_name(coalesce(first_name,'') || ' ' || last_name)
    ) STORED,
    gender                TEXT,                              -- 'm'|'f'|NULL
    title                 TEXT,                              -- 'M.', 'Mme', 'Dr', ...

    -- Position
    position_label        TEXT NOT NULL,                     -- ex: 'Directrice des Ressources Humaines'
    position_normalized   TEXT,                              -- 'drh'|'daf'|'dsi'|'cmo'|'cco'|...
    seniority_level       TEXT NOT NULL DEFAULT 'other',     -- 'c_level'|'director'|'manager'|'other'
    is_legal_director     BOOLEAN NOT NULL DEFAULT false,

    -- Découverte
    discovery_source      TEXT NOT NULL DEFAULT 'manual',    -- 'legal_director'|'direction_finder'|'linkedin_finder'|'manual'|'import'
    discovery_url         TEXT,                              -- URL exacte de la page où trouvé
    discovery_confidence  SMALLINT NOT NULL DEFAULT 50,      -- 0-100

    -- Contact
    primary_email         CITEXT,
    primary_email_status  TEXT,                              -- 'unverified'|'valid'|'invalid'|'catch_all'|'unknown'
    primary_email_score   SMALLINT,                          -- 0-100
    primary_phone         TEXT,
    linkedin_url          TEXT,
    twitter_handle        TEXT,

    -- Notes
    notes                 TEXT,

    -- Timestamps
    first_seen_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_enriched_at      TIMESTAMPTZ,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at            TIMESTAMPTZ,

    -- Anti-doublon HARD
    CONSTRAINT contacts_unique_per_company UNIQUE (company_id, full_name_normalized)
        DEFERRABLE INITIALLY DEFERRED
);

CREATE INDEX idx_contacts_workspace ON contacts (workspace_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_contacts_company ON contacts (company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_contacts_seniority ON contacts (workspace_id, seniority_level, discovery_source) WHERE deleted_at IS NULL;
CREATE INDEX idx_contacts_email ON contacts (primary_email) WHERE primary_email IS NOT NULL AND deleted_at IS NULL;
CREATE INDEX idx_contacts_legal_director ON contacts (company_id) WHERE is_legal_director = true AND deleted_at IS NULL;
CREATE INDEX idx_contacts_name_trgm ON contacts USING gin (full_name_normalized gin_trgm_ops);
```

### `company_addresses` (établissements multiples)

```sql
CREATE TABLE company_addresses (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    company_id      UUID NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    siret           CHAR(14),
    is_headquarter  BOOLEAN NOT NULL DEFAULT false,
    is_active       BOOLEAN NOT NULL DEFAULT true,
    street          TEXT,
    complement      TEXT,
    postal_code     TEXT,
    city_insee      TEXT REFERENCES cities(code_insee),
    city_name       TEXT,
    geom            geometry(Point, 4326),
    geocoding_score NUMERIC(3,2),
    source          TEXT,                             -- 'insee'|'google_maps'|'ban'|'manual'
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, siret)
);
CREATE INDEX idx_addr_company ON company_addresses (company_id);
CREATE INDEX idx_addr_city ON company_addresses (city_insee);
CREATE INDEX idx_addr_geom ON company_addresses USING gist (geom);
```

### `company_phones`, `company_emails`, `company_social_handles`

```sql
CREATE TABLE company_phones (
    id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    company_id    UUID NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    phone         TEXT NOT NULL,
    phone_e164    TEXT,                                -- normalisé +33...
    label         TEXT,                                -- 'principal'|'standard'|'service_x'
    source        TEXT NOT NULL,                       -- 'google_maps'|'pages_jaunes'|'site_web'|'annuaire_entr'
    is_active     BOOLEAN NOT NULL DEFAULT true,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, phone_e164)
);
CREATE INDEX idx_phones_company ON company_phones (company_id);

CREATE TABLE company_emails (
    id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    company_id    UUID NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    email         CITEXT NOT NULL,
    email_type    TEXT NOT NULL,                       -- 'nominative'|'role_based'|'generic'|'no_reply'
    source        TEXT NOT NULL,                       -- 'site_web'|'pages_jaunes'|'pattern_inferred'
    found_url     TEXT,
    is_active     BOOLEAN NOT NULL DEFAULT true,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, email)
);
CREATE INDEX idx_emails_company ON company_emails (company_id);
CREATE INDEX idx_emails_type ON company_emails (workspace_id, email_type);

CREATE TABLE company_social_handles (
    id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    company_id    UUID NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    platform      TEXT NOT NULL,                       -- 'linkedin'|'twitter'|'facebook'|'instagram'|'tiktok'|'youtube'
    handle        TEXT NOT NULL,
    url           TEXT NOT NULL,
    followers     INT,
    source        TEXT NOT NULL,
    found_url     TEXT,
    is_active     BOOLEAN NOT NULL DEFAULT true,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, platform, handle)
);
CREATE INDEX idx_social_company ON company_social_handles (company_id);
```

### `schools` (écoles + universités + CFA)

```sql
CREATE TABLE schools (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id        UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    company_id          UUID REFERENCES companies(id) ON DELETE SET NULL,  -- lié si école = entité commerciale aussi
    uai                 TEXT,                       -- code Éducation Nationale
    name                TEXT NOT NULL,
    type                TEXT NOT NULL,              -- 'university'|'business_school'|'engineering_school'|'cfa'|'cnam'|'iut'|'lycee_pro'|'other'
    ministry            TEXT,                       -- 'enseignement_superieur'|'agriculture'|...
    city_insee          TEXT REFERENCES cities(code_insee),
    department_code     TEXT REFERENCES departments(code),
    region_code         TEXT REFERENCES regions(code),
    website_url         TEXT,
    main_email          CITEXT,
    main_phone          TEXT,
    student_count       INT,
    notes               TEXT,
    source              TEXT,                       -- 'mesri'|'onisep'|'manual'
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_schools_workspace ON schools (workspace_id);
CREATE INDEX idx_schools_dept ON schools (department_code);
CREATE INDEX idx_schools_type ON schools (workspace_id, type);
```

### `company_business_signals`

```sql
CREATE TABLE company_business_signals (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    company_id      UUID NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    signal_type     TEXT NOT NULL,            -- 'fundraising'|'hiring_surge'|'leadership_change'|'redressement'|'acquisition'|'cse_creation'|'office_move'|'product_launch'
    signal_score    SMALLINT NOT NULL,        -- 0-100 (intensité)
    source          TEXT NOT NULL,            -- 'bodacc'|'france_travail'|'crunchbase'|'press'|'news'
    source_url      TEXT,
    detected_at     DATE NOT NULL,            -- date du signal (pas découverte)
    discovered_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at      TIMESTAMPTZ,              -- pour signaux périssables
    metadata        JSONB NOT NULL DEFAULT '{}'::jsonb,
    is_active       BOOLEAN NOT NULL DEFAULT true
);
CREATE INDEX idx_signals_company_active ON company_business_signals (company_id, is_active);
CREATE INDEX idx_signals_workspace_type ON company_business_signals (workspace_id, signal_type, detected_at DESC);
```

### `company_strategic_keywords`, `company_tags`

```sql
CREATE TABLE company_strategic_keywords (
    id            BIGSERIAL PRIMARY KEY,
    workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    company_id    UUID NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    keyword_id    BIGINT NOT NULL REFERENCES strategic_keywords(id) ON DELETE CASCADE,
    score         NUMERIC(4,2) NOT NULL DEFAULT 1.0,
    found_in_url  TEXT,
    detected_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, keyword_id)
);

CREATE TABLE company_tags (
    id            BIGSERIAL PRIMARY KEY,
    workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    company_id    UUID NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    tag_slug      TEXT NOT NULL,
    is_auto       BOOLEAN NOT NULL DEFAULT false,
    set_by        UUID REFERENCES users(id),
    set_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, tag_slug)
);
CREATE INDEX idx_company_tags_workspace_slug ON company_tags (workspace_id, tag_slug);
```

---

## §5 — Email finder & validation (3 tables)

### `email_patterns`

```sql
CREATE TABLE email_patterns (
    id              BIGSERIAL PRIMARY KEY,
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    company_id      UUID NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    pattern         TEXT NOT NULL,             -- ex: '{first}.{last}@{domain}', '{f}{last}@{domain}'
    domain          TEXT NOT NULL,
    confidence      SMALLINT NOT NULL,         -- 0-100
    evidence_emails TEXT[],                    -- emails ayant aidé à inférer ce pattern
    detected_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, pattern, domain)
);
CREATE INDEX idx_patterns_company ON email_patterns (company_id);
CREATE INDEX idx_patterns_domain ON email_patterns (domain);
```

### `email_verifications`

```sql
CREATE TABLE email_verifications (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id        UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    contact_id          UUID REFERENCES contacts(id) ON DELETE CASCADE,
    company_id          UUID REFERENCES companies(id) ON DELETE CASCADE,
    email               CITEXT NOT NULL,
    pattern_used        TEXT,                                 -- pattern email_patterns.pattern utilisé pour générer
    validation_status   TEXT NOT NULL,                        -- 'valid'|'invalid'|'catch_all'|'unknown'|'role_based'|'disposable'
    score               SMALLINT NOT NULL,                    -- 0-100
    smtp_response       JSONB NOT NULL DEFAULT '{}'::jsonb,   -- full handshake détails
    mx_records          TEXT[],
    is_catch_all        BOOLEAN NOT NULL DEFAULT false,
    is_disposable       BOOLEAN NOT NULL DEFAULT false,
    is_role_based       BOOLEAN NOT NULL DEFAULT false,
    smtp_provider       TEXT,                                 -- 'google'|'microsoft'|'ovh'|'mailgun'|'unknown'
    verified_via        TEXT NOT NULL DEFAULT 'inhouse',      -- 'inhouse'|'million_verifier'|'kickbox'
    validated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at          TIMESTAMPTZ NOT NULL DEFAULT now() + INTERVAL '30 days',
    UNIQUE (workspace_id, email)
);
CREATE INDEX idx_verif_status ON email_verifications (workspace_id, validation_status);
CREATE INDEX idx_verif_expires ON email_verifications (expires_at) WHERE validation_status != 'invalid';
CREATE INDEX idx_verif_score ON email_verifications (workspace_id, score DESC) WHERE validation_status IN ('valid','catch_all');
```

### `opt_out` (GLOBAL, cross-workspace — RGPD)

```sql
CREATE TABLE opt_out (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    email           CITEXT,                                  -- email plein
    email_hash      TEXT,                                    -- SHA-256 (pour scope sans stocker email)
    domain          TEXT,                                    -- opt-out tout le domaine
    person_name_norm TEXT,                                   -- normalize_name() — opt-out personne quelle entreprise
    reason          TEXT NOT NULL,                           -- 'user_request'|'bounce_hard'|'spam_complaint'|'manual'|'cnil_request'
    source          TEXT NOT NULL,                           -- 'unsubscribe_link'|'bounce'|'spam_report'|'manual'|'cnil'
    requested_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at      TIMESTAMPTZ,                             -- NULL = définitif
    notes           TEXT,
    CONSTRAINT opt_out_has_target CHECK (email IS NOT NULL OR email_hash IS NOT NULL OR domain IS NOT NULL OR person_name_norm IS NOT NULL)
);
CREATE INDEX idx_optout_email ON opt_out (email) WHERE email IS NOT NULL;
CREATE INDEX idx_optout_email_hash ON opt_out (email_hash) WHERE email_hash IS NOT NULL;
CREATE INDEX idx_optout_domain ON opt_out (domain) WHERE domain IS NOT NULL;
CREATE INDEX idx_optout_person ON opt_out (person_name_norm) WHERE person_name_norm IS NOT NULL;

COMMENT ON TABLE opt_out IS 'TABLE GLOBALE — pas de workspace_id. Consultée AVANT tout scraping et enrichissement.';
```

---

## §6 — Scraping operations (8 tables)

### `scraping_sources` (RUNTIME-CONFIG)

```sql
CREATE TABLE scraping_sources (
    id                    BIGSERIAL PRIMARY KEY,
    workspace_id          UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    source_slug           TEXT NOT NULL,            -- 'insee'|'annuaire_entreprises'|'infogreffe'|...
    label                 TEXT NOT NULL,
    is_enabled            BOOLEAN NOT NULL DEFAULT true,
    rate_limit_per_minute INT NOT NULL DEFAULT 60,
    rate_limit_per_hour   INT NOT NULL DEFAULT 1000,
    ttl_revalidation_days INT NOT NULL DEFAULT 90,
    requires_proxy        BOOLEAN NOT NULL DEFAULT false,
    requires_captcha      BOOLEAN NOT NULL DEFAULT false,
    proxy_pool            TEXT[] NOT NULL DEFAULT '{}',          -- liste proxy_providers.slug
    settings              JSONB NOT NULL DEFAULT '{}'::jsonb,
    UNIQUE (workspace_id, source_slug)
);
```

### `scraper_runs` (PARTITIONNÉE par mois)

```sql
CREATE TABLE scraper_runs (
    id                  BIGSERIAL,
    workspace_id        UUID NOT NULL,
    source              TEXT NOT NULL,                            -- scraping_sources.source_slug
    scraper_name        TEXT NOT NULL,                            -- nom worker, ex 'google-maps-search'
    target_id           UUID,                                     -- ex: companies.id
    target_type         TEXT,                                     -- 'company'|'zone'|'contact'|'url'
    triggered_by        UUID,                                     -- users.id ou NULL si auto

    started_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    completed_at        TIMESTAMPTZ,
    duration_ms         INT,
    status              TEXT NOT NULL DEFAULT 'running',          -- 'running'|'ok'|'failed'|'skipped_already_fresh'|'skipped_opt_out'|'skipped_quota'

    proxy_used          BIGINT,                                   -- proxies.id
    user_agent_used     BIGINT,                                   -- user_agents.id
    llm_used            TEXT,                                     -- 'haiku-4.5'|'mistral-small'|...
    tokens_consumed     INT NOT NULL DEFAULT 0,
    cost_eur            NUMERIC(8,4) NOT NULL DEFAULT 0,

    contacts_found      INT NOT NULL DEFAULT 0,
    contacts_new        INT NOT NULL DEFAULT 0,
    emails_found        INT NOT NULL DEFAULT 0,
    emails_validated    INT NOT NULL DEFAULT 0,

    error_message       TEXT,
    error_stacktrace    TEXT,
    error_code          TEXT,                                     -- 'rate_limit'|'captcha'|'parse_error'|'network'|...
    retry_count         INT NOT NULL DEFAULT 0,

    metadata            JSONB NOT NULL DEFAULT '{}'::jsonb,

    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (id, started_at)
) PARTITION BY RANGE (started_at);

SELECT partman.create_parent(
    p_parent_table => 'public.scraper_runs',
    p_control => 'started_at',
    p_type => 'native',
    p_interval => '1 month',
    p_premake => 6
);
UPDATE partman.part_config SET retention = '90 days', retention_keep_table = false WHERE parent_table = 'public.scraper_runs';

CREATE INDEX idx_runs_workspace_started ON scraper_runs (workspace_id, started_at DESC);
CREATE INDEX idx_runs_source_status ON scraper_runs (source, status, started_at DESC);
CREATE INDEX idx_runs_target ON scraper_runs (target_id, target_type) WHERE target_id IS NOT NULL;
```

### `scraper_targets` (file d'attente persistée)

```sql
CREATE TABLE scraper_targets (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    target_type     TEXT NOT NULL,                  -- 'company'|'siren'|'zone_naf_dept'|'url'|'school'
    target_ref      TEXT NOT NULL,                  -- ex: SIREN '123456789', ou JSON zone
    source          TEXT NOT NULL,                  -- source à scraper
    priority        SMALLINT NOT NULL DEFAULT 50,   -- 0-100
    status          TEXT NOT NULL DEFAULT 'pending',-- 'pending'|'running'|'done'|'failed'|'skipped'
    enqueued_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    scheduled_for   TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_run_id     BIGINT,
    attempts        INT NOT NULL DEFAULT 0,
    last_error      TEXT,
    metadata        JSONB NOT NULL DEFAULT '{}'::jsonb
);
CREATE INDEX idx_targets_pending ON scraper_targets (workspace_id, source, priority DESC, scheduled_for)
  WHERE status = 'pending';
CREATE INDEX idx_targets_workspace_status ON scraper_targets (workspace_id, status);
```

### `enrichment_runs` (cycle waterfall complet, agrège plusieurs scraper_runs)

```sql
CREATE TABLE enrichment_runs (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    company_id      UUID NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    triggered_by    UUID REFERENCES users(id),
    started_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    completed_at    TIMESTAMPTZ,
    duration_ms     INT,
    waterfall_steps JSONB NOT NULL DEFAULT '[]'::jsonb,   -- [{step:'insee', status:'ok', run_id:..}, ...]
    final_status    TEXT NOT NULL DEFAULT 'running',      -- 'running'|'success'|'partial'|'failed'
    quality_before  TEXT,
    quality_after   TEXT,
    cost_total_eur  NUMERIC(8,4) NOT NULL DEFAULT 0,
    metadata        JSONB NOT NULL DEFAULT '{}'::jsonb
);
CREATE INDEX idx_enrich_company ON enrichment_runs (company_id, started_at DESC);
CREATE INDEX idx_enrich_workspace_status ON enrichment_runs (workspace_id, final_status, started_at DESC);
```

### `duplicate_flags`

```sql
CREATE TABLE duplicate_flags (
    id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    entity_type   TEXT NOT NULL,                       -- 'company'|'contact'
    entity_a_id   UUID NOT NULL,
    entity_b_id   UUID NOT NULL,
    fuzzy_score   NUMERIC(4,3) NOT NULL,               -- pg_trgm similarity
    match_fields  TEXT[] NOT NULL,                     -- ['legal_name','city']
    status        TEXT NOT NULL DEFAULT 'pending',     -- 'pending'|'confirmed_merge'|'rejected'|'auto_merged'
    reviewed_by   UUID REFERENCES users(id),
    reviewed_at   TIMESTAMPTZ,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (entity_a_id != entity_b_id)
);
CREATE INDEX idx_dupflag_pending ON duplicate_flags (workspace_id, entity_type) WHERE status = 'pending';
```

### `linkedin_url_searches` (Google Search Wrapper traçabilité)

```sql
CREATE TABLE linkedin_url_searches (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id        UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    company_id          UUID REFERENCES companies(id) ON DELETE CASCADE,
    contact_id          UUID REFERENCES contacts(id) ON DELETE CASCADE,
    search_query        TEXT NOT NULL,
    search_target_type  TEXT NOT NULL,                  -- 'company_linkedin'|'person_linkedin'|'clevel_drh'|'clevel_daf'|...
    engine_used         TEXT NOT NULL,                  -- 'google'|'bing'|'duckduckgo'
    results_raw         JSONB NOT NULL DEFAULT '[]'::jsonb,
    best_url            TEXT,
    best_url_confidence SMALLINT,                       -- 0-100
    captcha_encountered BOOLEAN NOT NULL DEFAULT false,
    duration_ms         INT,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_li_search_company ON linkedin_url_searches (company_id);
CREATE INDEX idx_li_search_engine ON linkedin_url_searches (engine_used, created_at DESC);
```

---

## §7 — Direction Finder (4 tables — ETI/Grandes)

### `direction_finder_runs`

```sql
CREATE TABLE direction_finder_runs (
    id                    UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id          UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    company_id            UUID NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    started_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    completed_at          TIMESTAMPTZ,
    duration_ms           INT,
    sources_attempted     TEXT[] NOT NULL,             -- ['corporate_pages','press','annual_report','google_search']
    sources_successful    TEXT[] NOT NULL DEFAULT '{}',
    c_level_found_count   INT NOT NULL DEFAULT 0,
    c_level_with_email    INT NOT NULL DEFAULT 0,
    c_level_with_linkedin INT NOT NULL DEFAULT 0,
    pages_crawled         INT NOT NULL DEFAULT 0,
    llm_tokens_used       INT NOT NULL DEFAULT 0,
    llm_cost_eur          NUMERIC(8,4) NOT NULL DEFAULT 0,
    status                TEXT NOT NULL DEFAULT 'running',  -- running|ok|failed|skipped
    error                 TEXT,
    metadata              JSONB NOT NULL DEFAULT '{}'::jsonb
);
CREATE INDEX idx_df_company ON direction_finder_runs (company_id);
CREATE INDEX idx_df_workspace_status ON direction_finder_runs (workspace_id, status, started_at DESC);
```

### `corporate_pages_crawled` (cache TTL 30j)

```sql
CREATE TABLE corporate_pages_crawled (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    company_id      UUID NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    url             TEXT NOT NULL,
    page_type       TEXT NOT NULL,             -- 'direction'|'leadership'|'team'|'newsroom'|'about'|'governance'|'board'|'comex'
    http_status     INT,
    raw_html_sha256 TEXT,                      -- pour detection changement
    parsed_data     JSONB NOT NULL DEFAULT '{}'::jsonb,   -- {team_members: [...]}
    c_level_found   INT NOT NULL DEFAULT 0,
    crawled_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at      TIMESTAMPTZ NOT NULL DEFAULT now() + INTERVAL '30 days',
    UNIQUE (company_id, url)
);
CREATE INDEX idx_corp_pages_company ON corporate_pages_crawled (company_id);
CREATE INDEX idx_corp_pages_expires ON corporate_pages_crawled (expires_at);
```

### `press_releases_indexed`

```sql
CREATE TABLE press_releases_indexed (
    id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id      UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    company_id        UUID NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    url               TEXT NOT NULL,
    title             TEXT,
    published_at      DATE,
    contains_nomination BOOLEAN NOT NULL DEFAULT false,
    nominations_data  JSONB NOT NULL DEFAULT '[]'::jsonb,   -- [{name, position, effective_date}]
    indexed_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, url)
);
CREATE INDEX idx_press_company ON press_releases_indexed (company_id, published_at DESC);
CREATE INDEX idx_press_nominations ON press_releases_indexed (workspace_id) WHERE contains_nomination = true;
```

### `annual_reports_indexed`

```sql
CREATE TABLE annual_reports_indexed (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id        UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    company_id          UUID NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    report_year         INT NOT NULL,
    report_type         TEXT NOT NULL,                  -- 'urd'|'annual_report'|'rsa'|'reference_doc'
    source              TEXT NOT NULL,                  -- 'amf'|'company_website'|'google'
    url                 TEXT NOT NULL,
    pdf_sha256          TEXT,
    pages_total         INT,
    leadership_pages    INT[],                          -- numéros de pages contenant direction
    leadership_data     JSONB NOT NULL DEFAULT '[]'::jsonb,
    indexed_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, report_year, report_type)
);
CREATE INDEX idx_annual_company ON annual_reports_indexed (company_id, report_year DESC);
```

---

## §8 — Rotations & proxies (9 tables)

### `proxy_providers`

```sql
CREATE TABLE proxy_providers (
    id                  BIGSERIAL PRIMARY KEY,
    workspace_id        UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    slug                TEXT NOT NULL,                  -- 'webshare'|'iproyal'|'smartproxy'|'brightdata'
    label               TEXT NOT NULL,
    provider_type       TEXT NOT NULL,                  -- 'datacenter'|'residential'|'mobile'|'isp'
    auth_method         TEXT NOT NULL,                  -- 'user_pass'|'ip_whitelist'|'token'
    endpoint            TEXT NOT NULL,                  -- ex: 'p.webshare.io:80'
    credentials_ref     TEXT NOT NULL,                  -- pointer vers secret manager (Infisical/Doppler), pas la valeur
    monthly_budget_eur  NUMERIC(8,2) NOT NULL DEFAULT 0,
    monthly_spent_eur   NUMERIC(8,2) NOT NULL DEFAULT 0,
    quota_gb            NUMERIC(10,2),
    quota_used_gb       NUMERIC(10,2) NOT NULL DEFAULT 0,
    priority            SMALLINT NOT NULL DEFAULT 50,
    is_enabled          BOOLEAN NOT NULL DEFAULT true,
    success_rate_30d    NUMERIC(4,3),                   -- computed
    avg_latency_ms      INT,
    settings            JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (workspace_id, slug)
);
```

### `proxies`

```sql
CREATE TABLE proxies (
    id              BIGSERIAL PRIMARY KEY,
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    provider_id     BIGINT NOT NULL REFERENCES proxy_providers(id) ON DELETE CASCADE,
    proxy_url       TEXT NOT NULL,                  -- 'http://user:pass@ip:port'
    external_ip     INET,
    country         TEXT,
    region          TEXT,
    city            TEXT,
    is_active       BOOLEAN NOT NULL DEFAULT true,
    success_count   BIGINT NOT NULL DEFAULT 0,
    failure_count   BIGINT NOT NULL DEFAULT 0,
    captcha_count   BIGINT NOT NULL DEFAULT 0,
    last_used_at    TIMESTAMPTZ,
    last_success_at TIMESTAMPTZ,
    last_failure_at TIMESTAMPTZ,
    cooldown_until  TIMESTAMPTZ,                     -- backoff après échec
    metadata        JSONB NOT NULL DEFAULT '{}'::jsonb
);
CREATE INDEX idx_proxies_provider ON proxies (provider_id);
CREATE INDEX idx_proxies_active ON proxies (workspace_id, is_active, cooldown_until);
```

### `proxy_health_checks`

```sql
CREATE TABLE proxy_health_checks (
    id          BIGSERIAL PRIMARY KEY,
    proxy_id    BIGINT NOT NULL REFERENCES proxies(id) ON DELETE CASCADE,
    checked_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    is_alive    BOOLEAN NOT NULL,
    latency_ms  INT,
    ip_observed INET,
    error       TEXT
);
CREATE INDEX idx_health_proxy_checked ON proxy_health_checks (proxy_id, checked_at DESC);
```

### `proxy_usage_log` (PARTITIONNÉE par mois)

```sql
CREATE TABLE proxy_usage_log (
    id              BIGSERIAL,
    workspace_id    UUID NOT NULL,
    proxy_id        BIGINT NOT NULL,
    target_domain   TEXT NOT NULL,
    bytes_in        BIGINT NOT NULL DEFAULT 0,
    bytes_out       BIGINT NOT NULL DEFAULT 0,
    status_code     INT,
    duration_ms     INT,
    is_success      BOOLEAN NOT NULL,
    used_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (id, used_at)
) PARTITION BY RANGE (used_at);

SELECT partman.create_parent(
    p_parent_table => 'public.proxy_usage_log',
    p_control => 'used_at',
    p_type => 'native',
    p_interval => '1 month',
    p_premake => 3
);
UPDATE partman.part_config SET retention = '60 days', retention_keep_table = false WHERE parent_table = 'public.proxy_usage_log';

CREATE INDEX idx_proxy_usage_proxy ON proxy_usage_log (proxy_id, used_at DESC);
```

### `user_agents` (pool 50+)

```sql
CREATE TABLE user_agents (
    id              BIGSERIAL PRIMARY KEY,
    user_agent      TEXT NOT NULL UNIQUE,
    browser_family  TEXT,                           -- 'chrome'|'firefox'|'safari'|'edge'
    browser_version TEXT,
    os              TEXT,
    device_category TEXT,                           -- 'desktop'|'mobile'|'tablet'
    is_active       BOOLEAN NOT NULL DEFAULT true,
    last_used_at    TIMESTAMPTZ,
    usage_count     BIGINT NOT NULL DEFAULT 0,
    success_count   BIGINT NOT NULL DEFAULT 0
);
CREATE INDEX idx_ua_active ON user_agents (is_active, device_category);
```

### `scraper_rotation_state` (round-robin état)

```sql
CREATE TABLE scraper_rotation_state (
    workspace_id     UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    dimension        TEXT NOT NULL,         -- 'zone_naf_dept'|'department'|'naf_section'|'effectif_range'
    last_value       TEXT,
    cursor           JSONB,                 -- état complexe (FIFO queue)
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (workspace_id, dimension)
);
COMMENT ON TABLE scraper_rotation_state IS 'Pour parallel safety, UPDATE avec SELECT ... FOR UPDATE ou advisory lock.';
```

### `search_engines`, `search_engine_health_checks`

```sql
CREATE TABLE search_engines (
    id              BIGSERIAL PRIMARY KEY,
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    slug            TEXT NOT NULL,                  -- 'google'|'bing'|'duckduckgo'|'startpage'|'brave'
    label           TEXT NOT NULL,
    base_url        TEXT NOT NULL,                  -- 'https://www.google.com/search'
    priority        SMALLINT NOT NULL DEFAULT 50,
    state           TEXT NOT NULL DEFAULT 'active', -- 'active'|'rate_limited'|'cooldown'|'captcha_challenge'|'disabled'
    cooldown_until  TIMESTAMPTZ,
    is_enabled      BOOLEAN NOT NULL DEFAULT true,
    success_rate_24h NUMERIC(4,3),
    settings        JSONB NOT NULL DEFAULT '{}'::jsonb,
    UNIQUE (workspace_id, slug)
);

CREATE TABLE search_engine_health_checks (
    id          BIGSERIAL PRIMARY KEY,
    engine_id   BIGINT NOT NULL REFERENCES search_engines(id) ON DELETE CASCADE,
    checked_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    state       TEXT NOT NULL,
    latency_ms  INT,
    error       TEXT
);
CREATE INDEX idx_engine_health ON search_engine_health_checks (engine_id, checked_at DESC);
```

### `rotation_events` (journal des changements d'état)

```sql
CREATE TABLE rotation_events (
    id              BIGSERIAL PRIMARY KEY,
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    dimension       TEXT NOT NULL,                  -- 'proxy'|'search_engine'|'user_agent'|'zone'|'llm'
    entity_id       TEXT NOT NULL,
    from_state      TEXT,
    to_state        TEXT NOT NULL,
    reason          TEXT,
    metadata        JSONB NOT NULL DEFAULT '{}'::jsonb,
    occurred_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_rotevt_dim ON rotation_events (workspace_id, dimension, occurred_at DESC);
```

---

## §9 — LLM Router (5 tables)

### `llm_providers`

```sql
CREATE TABLE llm_providers (
    id              BIGSERIAL PRIMARY KEY,
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    slug            TEXT NOT NULL,                  -- 'anthropic'|'openai'|'mistral'|'openrouter'|'ollama_local'
    label           TEXT NOT NULL,
    api_endpoint    TEXT NOT NULL,
    credentials_ref TEXT NOT NULL,                  -- pointer secrets manager
    monthly_budget_eur NUMERIC(8,2) NOT NULL DEFAULT 50.00,
    monthly_spent_eur  NUMERIC(8,2) NOT NULL DEFAULT 0,
    is_enabled      BOOLEAN NOT NULL DEFAULT true,
    priority        SMALLINT NOT NULL DEFAULT 50,
    settings        JSONB NOT NULL DEFAULT '{}'::jsonb,
    UNIQUE (workspace_id, slug)
);
```

### `llm_use_cases` (RUNTIME-CONFIG)

```sql
CREATE TABLE llm_use_cases (
    id                  BIGSERIAL PRIMARY KEY,
    workspace_id        UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    use_case_slug       TEXT NOT NULL,              -- 'sector_classification'|'extract_team_from_page'|...
    label               TEXT NOT NULL,
    primary_provider    TEXT NOT NULL,              -- llm_providers.slug
    primary_model       TEXT NOT NULL,              -- ex: 'claude-haiku-4-5'
    fallback_chain      JSONB NOT NULL DEFAULT '[]'::jsonb,  -- [{provider, model}, ...]
    prompt_template_id  BIGINT,                     -- → prompt_templates
    max_tokens          INT NOT NULL DEFAULT 1024,
    temperature         NUMERIC(3,2) NOT NULL DEFAULT 0.20,
    timeout_ms          INT NOT NULL DEFAULT 30000,
    cache_ttl_seconds   INT NOT NULL DEFAULT 86400, -- 1 jour par défaut
    is_enabled          BOOLEAN NOT NULL DEFAULT true,
    ab_test_config      JSONB,                      -- {variant_a:{...}, variant_b:{...}, split:0.5}
    UNIQUE (workspace_id, use_case_slug)
);
```

### `llm_usage` (PARTITIONNÉE par mois)

```sql
CREATE TABLE llm_usage (
    id                  BIGSERIAL,
    workspace_id        UUID NOT NULL,
    use_case_slug       TEXT NOT NULL,
    provider            TEXT NOT NULL,
    model               TEXT NOT NULL,
    prompt_template_id  BIGINT,
    prompt_version      INT,
    tokens_input        INT NOT NULL DEFAULT 0,
    tokens_output       INT NOT NULL DEFAULT 0,
    cost_eur            NUMERIC(10,6) NOT NULL DEFAULT 0,
    latency_ms          INT,
    status              TEXT NOT NULL,              -- 'ok'|'error'|'rate_limited'|'timeout'|'cache_hit'
    error               TEXT,
    cache_hit           BOOLEAN NOT NULL DEFAULT false,
    request_hash        TEXT,                       -- pour cache LLM
    scraper_run_id      BIGINT,
    metadata            JSONB NOT NULL DEFAULT '{}'::jsonb,
    used_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (id, used_at)
) PARTITION BY RANGE (used_at);

SELECT partman.create_parent(
    p_parent_table => 'public.llm_usage',
    p_control => 'used_at',
    p_type => 'native',
    p_interval => '1 month',
    p_premake => 6
);
UPDATE partman.part_config SET retention = '12 months' WHERE parent_table = 'public.llm_usage';

CREATE INDEX idx_llm_usage_workspace ON llm_usage (workspace_id, used_at DESC);
CREATE INDEX idx_llm_usage_use_case ON llm_usage (use_case_slug, used_at DESC);
CREATE INDEX idx_llm_usage_cache ON llm_usage (request_hash) WHERE cache_hit = false;
```

### `prompt_templates` + `prompt_template_versions`

```sql
CREATE TABLE prompt_templates (
    id              BIGSERIAL PRIMARY KEY,
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    slug            TEXT NOT NULL,
    label           TEXT NOT NULL,
    use_case_slug   TEXT NOT NULL,
    current_version INT NOT NULL DEFAULT 1,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (workspace_id, slug)
);

CREATE TABLE prompt_template_versions (
    id              BIGSERIAL PRIMARY KEY,
    template_id     BIGINT NOT NULL REFERENCES prompt_templates(id) ON DELETE CASCADE,
    version_number  INT NOT NULL,
    system_prompt   TEXT,
    user_prompt     TEXT NOT NULL,
    variables       TEXT[] NOT NULL DEFAULT '{}',
    notes           TEXT,
    created_by      UUID REFERENCES users(id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (template_id, version_number)
);
```

---

## §10 — Coverage tracking (2 objets)

### `target_zones` (zones à scraper)

```sql
CREATE TABLE target_zones (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    label           TEXT NOT NULL,
    department_code TEXT REFERENCES departments(code),
    naf_section     CHAR(1) REFERENCES naf_sections(code),
    naf_subclass    TEXT REFERENCES naf_subclasses(code),
    effectif_range  TEXT REFERENCES effectif_ranges(code),
    size_category   TEXT,
    priority        SMALLINT NOT NULL DEFAULT 50,
    is_active       BOOLEAN NOT NULL DEFAULT true,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_target_zones_workspace ON target_zones (workspace_id) WHERE is_active = true;
```

### `coverage_matrix_cells` (MATERIALIZED VIEW)

```sql
CREATE MATERIALIZED VIEW coverage_matrix_cells AS
SELECT
    c.workspace_id,
    c.department_code,
    c.naf_subclass_code,
    c.size_category,
    COUNT(*)                                                    AS companies_count,
    COUNT(*) FILTER (WHERE c.quality_score = 'complete')        AS quality_complete,
    COUNT(*) FILTER (WHERE c.quality_score = 'partial')         AS quality_partial,
    COUNT(*) FILTER (WHERE c.quality_score = 'basic')           AS quality_basic,
    COUNT(*) FILTER (WHERE c.last_enriched_at IS NOT NULL)      AS enriched_count,
    COUNT(*) FILTER (WHERE c.prospection_status = 'qualified')  AS qualified_count,
    MAX(c.last_enriched_at)                                     AS last_enriched_at,
    MAX(c.updated_at)                                           AS last_updated_at,
    now()                                                       AS refreshed_at
FROM companies c
WHERE c.deleted_at IS NULL
GROUP BY 1, 2, 3, 4
WITH NO DATA;

CREATE UNIQUE INDEX idx_coverage_unique ON coverage_matrix_cells (workspace_id, department_code, naf_subclass_code, size_category);
CREATE INDEX idx_coverage_workspace ON coverage_matrix_cells (workspace_id);

-- Refresh hourly via pg_cron
SELECT cron.schedule(
    'coverage_matrix_refresh',
    '5 * * * *',
    $$REFRESH MATERIALIZED VIEW CONCURRENTLY coverage_matrix_cells$$
);
```

---

## §11 — RGPD (3 tables)

### `data_processing_log` (registre traitements)

```sql
CREATE TABLE data_processing_log (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id        UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    processing_purpose  TEXT NOT NULL,            -- ex: 'prospection_b2b_email'
    legal_basis         TEXT NOT NULL,            -- 'legitimate_interest_b2b'|'consent'|'contract'
    data_categories     TEXT[] NOT NULL,          -- ['professional_email','full_name','position','company_data']
    data_subjects       TEXT[] NOT NULL,          -- ['legal_director','c_level','employee']
    recipients          TEXT[] NOT NULL DEFAULT '{}',  -- internal teams accessing
    retention_period_days INT NOT NULL DEFAULT 90,
    transferred_outside_eu BOOLEAN NOT NULL DEFAULT false,
    transfer_safeguards TEXT,
    security_measures   TEXT[] NOT NULL,          -- ['encryption_at_rest','encryption_in_transit','access_control','audit_log','2fa']
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    reviewed_at         TIMESTAMPTZ
);
```

### `gdpr_requests`

```sql
CREATE TABLE gdpr_requests (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id        UUID REFERENCES workspaces(id) ON DELETE SET NULL,
    request_type        TEXT NOT NULL,             -- 'access'|'rectification'|'erasure'|'portability'|'opposition'|'limitation'
    requester_email     CITEXT NOT NULL,
    requester_name      TEXT,
    identity_verified   BOOLEAN NOT NULL DEFAULT false,
    identity_verified_at TIMESTAMPTZ,
    received_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    deadline_at         TIMESTAMPTZ NOT NULL DEFAULT now() + INTERVAL '30 days',
    handled_by          UUID REFERENCES users(id),
    handled_at          TIMESTAMPTZ,
    status              TEXT NOT NULL DEFAULT 'received',  -- 'received'|'identity_check'|'in_progress'|'completed'|'rejected'|'extended'
    affected_records    JSONB,                     -- {companies: [...], contacts: [...], scraper_runs: [...]}
    response_sent_at    TIMESTAMPTZ,
    response_method     TEXT,                      -- 'email'|'postal'|'admin_action'
    notes               TEXT
);
CREATE INDEX idx_gdpr_status ON gdpr_requests (status, deadline_at);
CREATE INDEX idx_gdpr_email ON gdpr_requests (requester_email);
```

### `ai_act_register`

```sql
CREATE TABLE ai_act_register (
    id                  BIGSERIAL PRIMARY KEY,
    workspace_id        UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    ai_system_name      TEXT NOT NULL,            -- ex: 'fiche_quality_scoring'
    use_case_slug       TEXT,                     -- → llm_use_cases.use_case_slug
    purpose             TEXT NOT NULL,
    risk_category       TEXT NOT NULL,            -- 'minimal'|'limited'|'high'|'unacceptable'
    is_profiling        BOOLEAN NOT NULL DEFAULT false,
    human_oversight     TEXT NOT NULL,            -- description du contrôle humain
    accuracy_metrics    JSONB,
    bias_mitigations    TEXT,
    data_governance     TEXT,
    transparency_notice TEXT,                     -- ce qu'on dit à la personne profilée
    deployed_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    reviewed_at         TIMESTAMPTZ,
    is_active           BOOLEAN NOT NULL DEFAULT true
);
```

---

## §12 — RLS policies

Activation RLS sur les tables sensibles + politiques. Activées via session variable `app.current_workspace_id`.

```sql
-- Activation RLS (PostgreSQL 16)
ALTER TABLE workspaces ENABLE ROW LEVEL SECURITY;
ALTER TABLE companies ENABLE ROW LEVEL SECURITY;
ALTER TABLE contacts  ENABLE ROW LEVEL SECURITY;
ALTER TABLE company_addresses ENABLE ROW LEVEL SECURITY;
ALTER TABLE company_phones ENABLE ROW LEVEL SECURITY;
ALTER TABLE company_emails ENABLE ROW LEVEL SECURITY;
ALTER TABLE company_social_handles ENABLE ROW LEVEL SECURITY;
ALTER TABLE company_business_signals ENABLE ROW LEVEL SECURITY;
ALTER TABLE company_strategic_keywords ENABLE ROW LEVEL SECURITY;
ALTER TABLE company_tags ENABLE ROW LEVEL SECURITY;
ALTER TABLE schools ENABLE ROW LEVEL SECURITY;
ALTER TABLE email_patterns ENABLE ROW LEVEL SECURITY;
ALTER TABLE email_verifications ENABLE ROW LEVEL SECURITY;
ALTER TABLE scraping_sources ENABLE ROW LEVEL SECURITY;
ALTER TABLE scraper_runs ENABLE ROW LEVEL SECURITY;
ALTER TABLE scraper_targets ENABLE ROW LEVEL SECURITY;
ALTER TABLE enrichment_runs ENABLE ROW LEVEL SECURITY;
ALTER TABLE duplicate_flags ENABLE ROW LEVEL SECURITY;
ALTER TABLE linkedin_url_searches ENABLE ROW LEVEL SECURITY;
ALTER TABLE direction_finder_runs ENABLE ROW LEVEL SECURITY;
ALTER TABLE corporate_pages_crawled ENABLE ROW LEVEL SECURITY;
ALTER TABLE press_releases_indexed ENABLE ROW LEVEL SECURITY;
ALTER TABLE annual_reports_indexed ENABLE ROW LEVEL SECURITY;
ALTER TABLE proxy_providers ENABLE ROW LEVEL SECURITY;
ALTER TABLE proxies ENABLE ROW LEVEL SECURITY;
ALTER TABLE proxy_usage_log ENABLE ROW LEVEL SECURITY;
ALTER TABLE search_engines ENABLE ROW LEVEL SECURITY;
ALTER TABLE rotation_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE llm_providers ENABLE ROW LEVEL SECURITY;
ALTER TABLE llm_use_cases ENABLE ROW LEVEL SECURITY;
ALTER TABLE llm_usage ENABLE ROW LEVEL SECURITY;
ALTER TABLE prompt_templates ENABLE ROW LEVEL SECURITY;
ALTER TABLE prompt_template_versions ENABLE ROW LEVEL SECURITY;
ALTER TABLE target_zones ENABLE ROW LEVEL SECURITY;
ALTER TABLE data_processing_log ENABLE ROW LEVEL SECURITY;
ALTER TABLE gdpr_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE ai_act_register ENABLE ROW LEVEL SECURITY;
ALTER TABLE audit_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE strategic_keywords ENABLE ROW LEVEL SECURITY;
ALTER TABLE auto_tag_definitions ENABLE ROW LEVEL SECURITY;
ALTER TABLE axion_offer_targets ENABLE ROW LEVEL SECURITY;
ALTER TABLE invitations ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_workspaces ENABLE ROW LEVEL SECURITY;

-- Macro pour générer les policies (à exécuter pour chaque table avec workspace_id)
DO $$
DECLARE
    t TEXT;
    tables TEXT[] := ARRAY[
        'companies','contacts','company_addresses','company_phones','company_emails',
        'company_social_handles','company_business_signals','company_strategic_keywords',
        'company_tags','schools','email_patterns','email_verifications',
        'scraping_sources','scraper_targets','enrichment_runs','duplicate_flags',
        'linkedin_url_searches','direction_finder_runs','corporate_pages_crawled',
        'press_releases_indexed','annual_reports_indexed',
        'proxy_providers','proxies','search_engines','rotation_events',
        'llm_providers','llm_use_cases','prompt_templates',
        'target_zones','data_processing_log','gdpr_requests','ai_act_register',
        'strategic_keywords','auto_tag_definitions','axion_offer_targets','invitations'
    ];
BEGIN
    FOREACH t IN ARRAY tables LOOP
        EXECUTE format($p$
            CREATE POLICY workspace_isolation ON %I
                USING (workspace_id = current_setting('app.current_workspace_id', true)::uuid)
                WITH CHECK (workspace_id = current_setting('app.current_workspace_id', true)::uuid);
        $p$, t);
    END LOOP;
END$$;

-- Tables partitionnées : appliquer sur la table parent (Postgres 16 propage aux partitions)
CREATE POLICY workspace_isolation ON scraper_runs
    USING (workspace_id = current_setting('app.current_workspace_id', true)::uuid)
    WITH CHECK (workspace_id = current_setting('app.current_workspace_id', true)::uuid);

CREATE POLICY workspace_isolation ON llm_usage
    USING (workspace_id = current_setting('app.current_workspace_id', true)::uuid)
    WITH CHECK (workspace_id = current_setting('app.current_workspace_id', true)::uuid);

CREATE POLICY workspace_isolation ON proxy_usage_log
    USING (workspace_id = current_setting('app.current_workspace_id', true)::uuid)
    WITH CHECK (workspace_id = current_setting('app.current_workspace_id', true)::uuid);

CREATE POLICY workspace_isolation ON audit_logs
    USING (workspace_id IS NULL OR workspace_id = current_setting('app.current_workspace_id', true)::uuid);

-- prompt_template_versions : isolation via parent
CREATE POLICY workspace_isolation ON prompt_template_versions
    USING (template_id IN (SELECT id FROM prompt_templates WHERE workspace_id = current_setting('app.current_workspace_id', true)::uuid));
```

**Bypass RLS pour jobs système :** Le user Postgres `axion_worker` a `BYPASSRLS`. Utilisé uniquement par les workers de scraping qui doivent voir cross-workspace (rare, pour `opt_out` notamment qui est déjà non-RLS).

---

## §13 — Vue récap inventaire tables

| § | Catégorie | Tables | Partitionnées |
|---|-----------|--------|---------------|
| 1 | Multi-tenant/auth/audit | 9 | `audit_logs` |
| 2 | Référentiels géo | 4 | — |
| 3 | Référentiels secteurs & business | 8 | — |
| 4 | Entités scrapées | 10 | — |
| 5 | Email finder & validation | 3 | — |
| 6 | Scraping operations | 6 | `scraper_runs` |
| 7 | Direction Finder | 4 | — |
| 8 | Rotations & proxies | 9 | `proxy_usage_log` |
| 9 | LLM Router | 5 | `llm_usage` |
| 10 | Coverage tracking | 2 (dont 1 MV) | — |
| 11 | RGPD | 3 | — |
| **Total** | | **63 tables Phase 1** | **5 partitionnées** |

> Le prompt parlait de « ~32 tables ». Le compte réel (63) reflète les sous-tables nécessaires (NAF 5 niveaux, social handles séparé, signals séparé, etc.). Cohérent avec la complexité métier.

---

## §14 — Migrations Laravel (séquence)

Ordre d'exécution recommandé (sera matérialisé en `database/migrations/` Phase 1) :

```
2026_05_16_000001_create_extensions.php             -- CREATE EXTENSION...
2026_05_16_000010_create_workspaces.php
2026_05_16_000020_create_users.php
2026_05_16_000030_create_user_workspaces.php
2026_05_16_000040_create_roles_permissions.php
2026_05_16_000050_create_invitations.php
2026_05_16_000060_create_sessions.php
2026_05_16_000070_create_audit_logs.php             -- + partman setup
2026_05_16_000100_create_countries.php
2026_05_16_000110_create_regions.php                -- + postgis index
2026_05_16_000120_create_departments.php
2026_05_16_000130_create_cities.php
2026_05_16_000200_create_naf_sections.php
2026_05_16_000210_create_naf_divisions.php
2026_05_16_000220_create_naf_groups.php
2026_05_16_000230_create_naf_classes.php
2026_05_16_000240_create_naf_subclasses.php
2026_05_16_000250_create_legal_forms.php
2026_05_16_000260_create_effectif_ranges.php        -- + seed
2026_05_16_000270_create_axion_offer_targets.php
2026_05_16_000280_create_strategic_keywords.php
2026_05_16_000290_create_auto_tag_definitions.php
2026_05_16_000300_create_companies.php
2026_05_16_000310_create_contacts.php
2026_05_16_000320_create_company_addresses.php
2026_05_16_000330_create_company_phones_emails_social.php
2026_05_16_000340_create_schools.php
2026_05_16_000350_create_company_business_signals.php
2026_05_16_000360_create_company_strategic_keywords.php
2026_05_16_000370_create_company_tags.php
2026_05_16_000400_create_email_patterns.php
2026_05_16_000410_create_email_verifications.php
2026_05_16_000420_create_opt_out_global.php
2026_05_16_000500_create_scraping_sources.php
2026_05_16_000510_create_scraper_runs_partitioned.php
2026_05_16_000520_create_scraper_targets.php
2026_05_16_000530_create_enrichment_runs.php
2026_05_16_000540_create_duplicate_flags.php
2026_05_16_000550_create_linkedin_url_searches.php
2026_05_16_000600_create_direction_finder_runs.php
2026_05_16_000610_create_corporate_pages_crawled.php
2026_05_16_000620_create_press_releases_indexed.php
2026_05_16_000630_create_annual_reports_indexed.php
2026_05_16_000700_create_proxy_providers.php
2026_05_16_000710_create_proxies.php
2026_05_16_000720_create_proxy_health_checks.php
2026_05_16_000730_create_proxy_usage_log_partitioned.php
2026_05_16_000740_create_user_agents.php
2026_05_16_000750_create_scraper_rotation_state.php
2026_05_16_000760_create_search_engines.php
2026_05_16_000770_create_search_engine_health_checks.php
2026_05_16_000780_create_rotation_events.php
2026_05_16_000800_create_llm_providers.php
2026_05_16_000810_create_llm_use_cases.php
2026_05_16_000820_create_llm_usage_partitioned.php
2026_05_16_000830_create_prompt_templates.php
2026_05_16_000840_create_prompt_template_versions.php
2026_05_16_000900_create_target_zones.php
2026_05_16_000910_create_coverage_matrix_view.php   -- materialized view + pg_cron schedule
2026_05_16_001000_create_data_processing_log.php
2026_05_16_001010_create_gdpr_requests.php
2026_05_16_001020_create_ai_act_register.php
2026_05_16_001900_enable_rls_policies.php           -- toutes les ENABLE RLS + CREATE POLICY
```

---

## Lecture suivante

→ `04_db_schema_phase2_scaffold.md` (~30 tables Phase 2 scaffold : campagnes, cold email, LinkedIn, CRM, analytics).
