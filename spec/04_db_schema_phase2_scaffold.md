# 04 — DB SCHEMA PHASE 2 (SCAFFOLD)

> **Statut :** **SCAFFOLD UNIQUEMENT**. Aucune logique métier active en V1. Les tables sont créées, indexées, RLS-isolées et documentées, pour qu'une activation Phase 2 ne nécessite ni migration de données ni refactor d'API.
>
> **Doctrine de scaffold :**
> - Chaque table porte `COMMENT ON TABLE: 'Phase 2 scaffold — créée pour structure future, pas de logique métier active'`.
> - Aucun worker n'écrit dans ces tables en V1. Aucune route métier active.
> - Les routes API correspondantes renvoient `501 Not Implemented` (cf. fichier 14).
> - Les pages UI correspondantes affichent un placeholder "Module en développement — sera activé en Phase 2" (cf. fichier 13).
> - Les events Laravel correspondants sont **définis** mais **sans listener actif**.
>
> **Convention multi-tenant :** toutes les tables Phase 2 portent `workspace_id` + RLS, identique au schéma Phase 1.

---

## 0. Bootstrap supplémentaire

Aucune extension supplémentaire requise pour les scaffolds. Les extensions déclarées en Phase 1 (`pg_trgm`, `postgis`, `pgvector`, `pg_partman`, `uuid-ossp`, `pgcrypto`) couvrent tous les besoins futurs (pgvector sera utilisé en Phase 2 pour recherche sémantique CRM).

---

## 1. Campagnes (orchestrateur global multi-canal)

### `campaigns`

```sql
CREATE TABLE campaigns (
  id              BIGSERIAL PRIMARY KEY,
  uuid            UUID NOT NULL DEFAULT uuid_generate_v4() UNIQUE,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  name            VARCHAR(180) NOT NULL,
  description     TEXT,
  status          VARCHAR(20) NOT NULL DEFAULT 'draft'
                  CHECK (status IN ('draft','scheduled','running','paused','completed','archived')),
  channels        TEXT[] NOT NULL DEFAULT '{}',           -- ['email','linkedin','call','manual']
  audience_filter JSONB NOT NULL DEFAULT '{}'::jsonb,    -- {"naf_subclass":[...],"tier":["PME"],"region":["75"]}
  audience_target_size INTEGER,
  start_at        TIMESTAMPTZ,
  end_at          TIMESTAMPTZ,
  total_targets   INTEGER NOT NULL DEFAULT 0,
  total_sent      INTEGER NOT NULL DEFAULT 0,
  total_replied   INTEGER NOT NULL DEFAULT 0,
  total_meetings  INTEGER NOT NULL DEFAULT 0,
  total_deals_eur BIGINT NOT NULL DEFAULT 0,
  created_by      BIGINT REFERENCES users(id),
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  deleted_at      TIMESTAMPTZ
);
COMMENT ON TABLE campaigns IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active. Orchestrateur central multi-canal (Email + LinkedIn + call + manual).';

ALTER TABLE campaigns ENABLE ROW LEVEL SECURITY;
CREATE POLICY campaigns_tenant_isolation ON campaigns
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());

CREATE INDEX campaigns_ws_status_idx ON campaigns (workspace_id, status) WHERE deleted_at IS NULL;
```

### `campaign_targets`

```sql
CREATE TABLE campaign_targets (
  id           BIGSERIAL PRIMARY KEY,
  workspace_id BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  campaign_id  BIGINT NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
  company_id   BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
  contact_id   BIGINT REFERENCES contacts(id) ON DELETE CASCADE,
  email_id     BIGINT REFERENCES company_emails(id) ON DELETE SET NULL,
  status       VARCHAR(20) NOT NULL DEFAULT 'pending'
               CHECK (status IN ('pending','running','completed','skipped','failed','opted_out')),
  next_step_at TIMESTAMPTZ,
  current_step SMALLINT NOT NULL DEFAULT 0,
  attempts     SMALLINT NOT NULL DEFAULT 0,
  meta         JSONB,
  created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (campaign_id, company_id, COALESCE(contact_id, 0))
);
COMMENT ON TABLE campaign_targets IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE campaign_targets ENABLE ROW LEVEL SECURITY;
CREATE POLICY campaign_targets_tenant_isolation ON campaign_targets
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());

CREATE INDEX campaign_targets_camp_status_idx ON campaign_targets (campaign_id, status, next_step_at);
```

### `campaign_sequence_steps`

```sql
CREATE TABLE campaign_sequence_steps (
  id           BIGSERIAL PRIMARY KEY,
  workspace_id BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  campaign_id  BIGINT NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
  step_order   SMALLINT NOT NULL,
  channel      VARCHAR(20) NOT NULL CHECK (channel IN ('email','linkedin','wait','call_reminder','manual_task')),
  template_ref VARCHAR(120),                                -- ref vers email_templates.key ou linkedin_message_templates.key
  wait_days    SMALLINT,                                    -- pour channel = 'wait'
  condition    JSONB,                                       -- {"if_no_reply_in":3,"if_opened":false,...}
  active       BOOLEAN NOT NULL DEFAULT TRUE,
  created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
COMMENT ON TABLE campaign_sequence_steps IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE campaign_sequence_steps ENABLE ROW LEVEL SECURITY;
CREATE POLICY campaign_sequence_steps_tenant_isolation ON campaign_sequence_steps
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());
```

### `campaign_executions`

```sql
CREATE TABLE campaign_executions (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  campaign_id     BIGINT NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
  target_id       BIGINT NOT NULL REFERENCES campaign_targets(id) ON DELETE CASCADE,
  step_id         BIGINT NOT NULL REFERENCES campaign_sequence_steps(id) ON DELETE CASCADE,
  executed_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  channel         VARCHAR(20) NOT NULL,
  status          VARCHAR(20) NOT NULL,
  external_ref    VARCHAR(120),                             -- ref vers email_sends.id ou linkedin_messages_sent.id
  meta            JSONB
);
COMMENT ON TABLE campaign_executions IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE campaign_executions ENABLE ROW LEVEL SECURITY;
CREATE POLICY campaign_executions_tenant_isolation ON campaign_executions
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());

CREATE INDEX campaign_executions_target_idx ON campaign_executions (target_id, executed_at DESC);
```

### `campaign_kpis_snapshots`

```sql
CREATE TABLE campaign_kpis_snapshots (
  id            BIGSERIAL PRIMARY KEY,
  workspace_id  BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  campaign_id   BIGINT NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
  snapshot_at   DATE NOT NULL,
  kpis          JSONB NOT NULL,                              -- {"sent":120,"opens":85,"clicks":12,"replies":4,...}
  created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (campaign_id, snapshot_at)
);
COMMENT ON TABLE campaign_kpis_snapshots IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE campaign_kpis_snapshots ENABLE ROW LEVEL SECURITY;
CREATE POLICY campaign_kpis_snapshots_tenant_isolation ON campaign_kpis_snapshots
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());
```

---

## 2. Cold Email

### `sending_domains`

```sql
CREATE TABLE sending_domains (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  domain          VARCHAR(190) NOT NULL,
  status          VARCHAR(20) NOT NULL DEFAULT 'pending'
                  CHECK (status IN ('pending','warming','active','paused','blacklisted')),
  spf_ok          BOOLEAN NOT NULL DEFAULT FALSE,
  dkim_ok         BOOLEAN NOT NULL DEFAULT FALSE,
  dmarc_ok        BOOLEAN NOT NULL DEFAULT FALSE,
  reputation_score SMALLINT,                                 -- 0..100
  primary_smtp_ip_id BIGINT,
  meta            JSONB,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (workspace_id, domain)
);
COMMENT ON TABLE sending_domains IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE sending_domains ENABLE ROW LEVEL SECURITY;
CREATE POLICY sending_domains_tenant_isolation ON sending_domains
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());
```

### `smtp_ips`

```sql
CREATE TABLE smtp_ips (
  id                BIGSERIAL PRIMARY KEY,
  workspace_id      BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  ip                INET NOT NULL,
  hostname          VARCHAR(255),
  provider          VARCHAR(40) NOT NULL,                  -- 'mailgun','sendgrid','amazon_ses','postmark','self_hosted_postfix','smtp2go'
  daily_limit       INTEGER NOT NULL DEFAULT 50,
  daily_sent        INTEGER NOT NULL DEFAULT 0,
  reset_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  warmup_state      VARCHAR(20) NOT NULL DEFAULT 'cold'
                    CHECK (warmup_state IN ('cold','warming_d1','warming_d3','warming_d7','warming_d14','warming_d30','active','paused','blacklisted')),
  reputation_score  SMALLINT,
  bounce_rate_24h   NUMERIC(5,2),
  complaint_rate_24h NUMERIC(5,2),
  enabled           BOOLEAN NOT NULL DEFAULT TRUE,
  meta              JSONB,
  created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (workspace_id, ip)
);
COMMENT ON TABLE smtp_ips IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE smtp_ips ENABLE ROW LEVEL SECURITY;
CREATE POLICY smtp_ips_tenant_isolation ON smtp_ips
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());

ALTER TABLE sending_domains ADD CONSTRAINT sending_domains_smtp_ip_fk
  FOREIGN KEY (primary_smtp_ip_id) REFERENCES smtp_ips(id) ON DELETE SET NULL;
```

### `smtp_ip_warmup_log`

```sql
CREATE TABLE smtp_ip_warmup_log (
  id           BIGSERIAL PRIMARY KEY,
  workspace_id BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  smtp_ip_id   BIGINT NOT NULL REFERENCES smtp_ips(id) ON DELETE CASCADE,
  day          INTEGER NOT NULL,                            -- 1..30
  emails_sent  INTEGER NOT NULL DEFAULT 0,
  bounces      INTEGER NOT NULL DEFAULT 0,
  complaints   INTEGER NOT NULL DEFAULT 0,
  occurred_at  DATE NOT NULL DEFAULT CURRENT_DATE,
  UNIQUE (smtp_ip_id, occurred_at)
);
COMMENT ON TABLE smtp_ip_warmup_log IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE smtp_ip_warmup_log ENABLE ROW LEVEL SECURITY;
CREATE POLICY smtp_ip_warmup_log_tenant_isolation ON smtp_ip_warmup_log
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());
```

### `warmup_states`

```sql
CREATE TABLE warmup_states (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  smtp_ip_id      BIGINT NOT NULL REFERENCES smtp_ips(id) ON DELETE CASCADE,
  sending_domain_id BIGINT NOT NULL REFERENCES sending_domains(id) ON DELETE CASCADE,
  state           VARCHAR(20) NOT NULL,
  scheduled_emails_today INTEGER NOT NULL DEFAULT 0,
  sent_emails_today INTEGER NOT NULL DEFAULT 0,
  notes           TEXT,
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (smtp_ip_id, sending_domain_id)
);
COMMENT ON TABLE warmup_states IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE warmup_states ENABLE ROW LEVEL SECURITY;
CREATE POLICY warmup_states_tenant_isolation ON warmup_states
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());
```

### `email_templates`

```sql
CREATE TABLE email_templates (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  key             VARCHAR(80) NOT NULL,
  display_name    VARCHAR(160) NOT NULL,
  subject_template TEXT NOT NULL,
  body_template_html TEXT NOT NULL,
  body_template_text TEXT NOT NULL,
  variables       JSONB,                                   -- liste variables attendues
  language        VARCHAR(8) NOT NULL DEFAULT 'fr',
  ab_variant      VARCHAR(8),                              -- pour A/B tests
  llm_use_case_key VARCHAR(80),                            -- ex 'cold_email_personalization_vip'
  active          BOOLEAN NOT NULL DEFAULT FALSE,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (workspace_id, key)
);
COMMENT ON TABLE email_templates IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE email_templates ENABLE ROW LEVEL SECURITY;
CREATE POLICY email_templates_tenant_isolation ON email_templates
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());
```

### `email_campaigns`, `email_sequences`, `email_steps`

```sql
CREATE TABLE email_campaigns (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  campaign_id     BIGINT REFERENCES campaigns(id) ON DELETE SET NULL,
  name            VARCHAR(180) NOT NULL,
  status          VARCHAR(20) NOT NULL DEFAULT 'draft',
  sending_domain_id BIGINT REFERENCES sending_domains(id),
  smtp_ip_id      BIGINT REFERENCES smtp_ips(id),
  daily_send_limit INTEGER NOT NULL DEFAULT 50,
  config          JSONB,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
COMMENT ON TABLE email_campaigns IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE email_campaigns ENABLE ROW LEVEL SECURITY;
CREATE POLICY email_campaigns_tenant_isolation ON email_campaigns
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());

CREATE TABLE email_sequences (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  email_campaign_id BIGINT NOT NULL REFERENCES email_campaigns(id) ON DELETE CASCADE,
  name            VARCHAR(160) NOT NULL,
  total_steps     SMALLINT NOT NULL DEFAULT 0,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
COMMENT ON TABLE email_sequences IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE email_sequences ENABLE ROW LEVEL SECURITY;
CREATE POLICY email_sequences_tenant_isolation ON email_sequences
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());

CREATE TABLE email_steps (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  sequence_id     BIGINT NOT NULL REFERENCES email_sequences(id) ON DELETE CASCADE,
  step_order      SMALLINT NOT NULL,
  template_id     BIGINT REFERENCES email_templates(id),
  wait_days       SMALLINT NOT NULL DEFAULT 0,
  condition       JSONB,                                    -- {"if_no_reply": true, "if_not_opened": true}
  active          BOOLEAN NOT NULL DEFAULT TRUE,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (sequence_id, step_order)
);
COMMENT ON TABLE email_steps IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE email_steps ENABLE ROW LEVEL SECURITY;
CREATE POLICY email_steps_tenant_isolation ON email_steps
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());
```

### `email_sends`, `email_bounces`, `email_replies`, `email_unsubscribes`

```sql
CREATE TABLE email_sends (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  email_campaign_id BIGINT REFERENCES email_campaigns(id),
  step_id         BIGINT REFERENCES email_steps(id),
  company_id      BIGINT REFERENCES companies(id),
  contact_id      BIGINT REFERENCES contacts(id),
  target_email    VARCHAR(254) NOT NULL,
  subject         TEXT NOT NULL,
  body_html_snapshot TEXT,
  body_text_snapshot TEXT,
  smtp_ip_id      BIGINT REFERENCES smtp_ips(id),
  smtp_message_id VARCHAR(255),                             -- ref SMTP server
  status          VARCHAR(20) NOT NULL DEFAULT 'queued'
                  CHECK (status IN ('queued','sending','sent','delivered','deferred','bounced','complained','blocked','failed')),
  scheduled_at    TIMESTAMPTZ,
  sent_at         TIMESTAMPTZ,
  delivered_at    TIMESTAMPTZ,
  bounced_at      TIMESTAMPTZ,
  llm_provider    VARCHAR(40),
  llm_model       VARCHAR(80),
  cost_eur_micro  BIGINT NOT NULL DEFAULT 0,
  ab_variant      VARCHAR(8),
  raw_response    JSONB,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
COMMENT ON TABLE email_sends IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE email_sends ENABLE ROW LEVEL SECURITY;
CREATE POLICY email_sends_tenant_isolation ON email_sends
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());
CREATE INDEX email_sends_campaign_idx ON email_sends (email_campaign_id, status, sent_at DESC);

CREATE TABLE email_bounces (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  email_send_id   BIGINT NOT NULL REFERENCES email_sends(id) ON DELETE CASCADE,
  bounce_type     VARCHAR(20) NOT NULL CHECK (bounce_type IN ('hard','soft','spam_complaint','blocked','technical')),
  smtp_code       VARCHAR(10),
  smtp_response   TEXT,
  occurred_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  raw_event       JSONB
);
COMMENT ON TABLE email_bounces IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE email_bounces ENABLE ROW LEVEL SECURITY;
CREATE POLICY email_bounces_tenant_isolation ON email_bounces
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());

CREATE TABLE email_replies (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  email_send_id   BIGINT NOT NULL REFERENCES email_sends(id) ON DELETE CASCADE,
  reply_subject   VARCHAR(500),
  reply_body_text TEXT,
  reply_intent    VARCHAR(40),                              -- LLM-classified : 'positive','neutral','negative','out_of_office','unsubscribe','bounce'
  intent_score    SMALLINT,
  occurred_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  raw_event       JSONB
);
COMMENT ON TABLE email_replies IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE email_replies ENABLE ROW LEVEL SECURITY;
CREATE POLICY email_replies_tenant_isolation ON email_replies
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());

CREATE TABLE email_unsubscribes (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  email           VARCHAR(254) NOT NULL,
  source          VARCHAR(40) NOT NULL,                    -- 'email_link','reply_intent','manual'
  email_send_id   BIGINT REFERENCES email_sends(id),
  occurred_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (workspace_id, LOWER(email))
);
COMMENT ON TABLE email_unsubscribes IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE email_unsubscribes ENABLE ROW LEVEL SECURITY;
CREATE POLICY email_unsubscribes_tenant_isolation ON email_unsubscribes
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());
```

### `email_deliverability_tracking`

```sql
CREATE TABLE email_deliverability_tracking (
  id                BIGSERIAL PRIMARY KEY,
  workspace_id      BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  email_send_id     BIGINT NOT NULL REFERENCES email_sends(id) ON DELETE CASCADE,
  status            VARCHAR(20) NOT NULL
                    CHECK (status IN ('delivered','opened','clicked','bounced','complained','blocked','unknown','timeout')),
  tracking_pixel_id VARCHAR(64),                            -- unique id (uuid) du pixel
  click_tracking_id VARCHAR(64),                            -- unique id (uuid) pour chaque lien tracké
  click_url         VARCHAR(2000),
  click_position    SMALLINT,
  smtp_response     TEXT,
  user_agent        TEXT,
  user_ip_hash      VARCHAR(64),                             -- IP hashée RGPD-safe
  occurred_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  raw_event         JSONB
);
COMMENT ON TABLE email_deliverability_tracking IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active. Permet de savoir précisément si un email envoyé a été reçu, ouvert, cliqué, bouncé, marqué spam, ou si la cible n''a jamais reçu (timeout, blocage anti-spam).';

ALTER TABLE email_deliverability_tracking ENABLE ROW LEVEL SECURITY;
CREATE POLICY email_deliverability_tracking_tenant_isolation ON email_deliverability_tracking
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());
CREATE INDEX email_deliverability_tracking_send_idx ON email_deliverability_tracking (email_send_id, status, occurred_at DESC);
CREATE INDEX email_deliverability_tracking_status_idx ON email_deliverability_tracking (workspace_id, status, occurred_at DESC);
```

---

## 3. LinkedIn Outreach

### `linkedin_campaigns`, `linkedin_sequences`, `linkedin_message_templates`, `linkedin_connection_requests`, `linkedin_messages_sent`

```sql
CREATE TABLE linkedin_campaigns (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  campaign_id     BIGINT REFERENCES campaigns(id) ON DELETE SET NULL,
  name            VARCHAR(180) NOT NULL,
  status          VARCHAR(20) NOT NULL DEFAULT 'draft',
  linkedin_account_id BIGINT REFERENCES linkedin_accounts(id),
  daily_send_limit INTEGER NOT NULL DEFAULT 25,
  config          JSONB,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
COMMENT ON TABLE linkedin_campaigns IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE linkedin_campaigns ENABLE ROW LEVEL SECURITY;
CREATE POLICY linkedin_campaigns_tenant_isolation ON linkedin_campaigns
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());

CREATE TABLE linkedin_sequences (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  linkedin_campaign_id BIGINT NOT NULL REFERENCES linkedin_campaigns(id) ON DELETE CASCADE,
  name            VARCHAR(160) NOT NULL,
  total_steps     SMALLINT NOT NULL DEFAULT 0,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
COMMENT ON TABLE linkedin_sequences IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE linkedin_sequences ENABLE ROW LEVEL SECURITY;
CREATE POLICY linkedin_sequences_tenant_isolation ON linkedin_sequences
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());

CREATE TABLE linkedin_message_templates (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  key             VARCHAR(80) NOT NULL,
  display_name    VARCHAR(160) NOT NULL,
  message_template TEXT NOT NULL,
  variables       JSONB,
  message_type    VARCHAR(20) NOT NULL CHECK (message_type IN ('invite_note','direct_message','inmail','follow_up')),
  llm_use_case_key VARCHAR(80),
  active          BOOLEAN NOT NULL DEFAULT FALSE,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (workspace_id, key)
);
COMMENT ON TABLE linkedin_message_templates IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE linkedin_message_templates ENABLE ROW LEVEL SECURITY;
CREATE POLICY linkedin_message_templates_tenant_isolation ON linkedin_message_templates
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());

CREATE TABLE linkedin_connection_requests (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  linkedin_campaign_id BIGINT REFERENCES linkedin_campaigns(id),
  contact_id      BIGINT REFERENCES contacts(id),
  linkedin_account_id BIGINT REFERENCES linkedin_accounts(id),
  target_linkedin_url VARCHAR(255) NOT NULL,
  status          VARCHAR(20) NOT NULL DEFAULT 'pending'
                  CHECK (status IN ('pending','sent','accepted','rejected','withdrawn','expired','blocked')),
  message_sent    TEXT,
  sent_at         TIMESTAMPTZ,
  accepted_at     TIMESTAMPTZ,
  meta            JSONB
);
COMMENT ON TABLE linkedin_connection_requests IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE linkedin_connection_requests ENABLE ROW LEVEL SECURITY;
CREATE POLICY linkedin_connection_requests_tenant_isolation ON linkedin_connection_requests
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());

CREATE TABLE linkedin_messages_sent (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  linkedin_campaign_id BIGINT REFERENCES linkedin_campaigns(id),
  template_id     BIGINT REFERENCES linkedin_message_templates(id),
  contact_id      BIGINT REFERENCES contacts(id),
  linkedin_account_id BIGINT REFERENCES linkedin_accounts(id),
  message_type    VARCHAR(20) NOT NULL,
  body_snapshot   TEXT NOT NULL,
  sent_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  delivered       BOOLEAN,
  read_at         TIMESTAMPTZ,
  replied_at      TIMESTAMPTZ,
  reply_intent    VARCHAR(40),
  raw_event       JSONB
);
COMMENT ON TABLE linkedin_messages_sent IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE linkedin_messages_sent ENABLE ROW LEVEL SECURITY;
CREATE POLICY linkedin_messages_sent_tenant_isolation ON linkedin_messages_sent
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());
```

---

## 4. CRM

### `crm_pipelines`, `crm_stages`, `crm_deals`, `crm_activities`, `crm_notes`, `crm_tasks`

```sql
CREATE TABLE crm_pipelines (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  name            VARCHAR(180) NOT NULL,
  is_default      BOOLEAN NOT NULL DEFAULT FALSE,
  config          JSONB,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
COMMENT ON TABLE crm_pipelines IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE crm_pipelines ENABLE ROW LEVEL SECURITY;
CREATE POLICY crm_pipelines_tenant_isolation ON crm_pipelines
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());

CREATE TABLE crm_stages (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  pipeline_id     BIGINT NOT NULL REFERENCES crm_pipelines(id) ON DELETE CASCADE,
  name            VARCHAR(120) NOT NULL,
  stage_order     SMALLINT NOT NULL,
  probability_pct SMALLINT NOT NULL DEFAULT 0,
  is_won          BOOLEAN NOT NULL DEFAULT FALSE,
  is_lost         BOOLEAN NOT NULL DEFAULT FALSE,
  UNIQUE (pipeline_id, stage_order)
);
COMMENT ON TABLE crm_stages IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE crm_stages ENABLE ROW LEVEL SECURITY;
CREATE POLICY crm_stages_tenant_isolation ON crm_stages
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());

CREATE TABLE crm_deals (
  id              BIGSERIAL PRIMARY KEY,
  uuid            UUID NOT NULL DEFAULT uuid_generate_v4() UNIQUE,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  pipeline_id     BIGINT NOT NULL REFERENCES crm_pipelines(id),
  stage_id        BIGINT NOT NULL REFERENCES crm_stages(id),
  company_id      BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
  primary_contact_id BIGINT REFERENCES contacts(id),
  name            VARCHAR(255) NOT NULL,
  axion_offer     VARCHAR(40),
  amount_eur      BIGINT NOT NULL DEFAULT 0,
  currency        CHAR(3) NOT NULL DEFAULT 'EUR',
  probability_pct SMALLINT,
  expected_close_at DATE,
  closed_at       TIMESTAMPTZ,
  status          VARCHAR(20) NOT NULL DEFAULT 'open'
                  CHECK (status IN ('open','won','lost')),
  loss_reason     VARCHAR(120),
  owner_user_id   BIGINT REFERENCES users(id),
  source_campaign_id BIGINT REFERENCES campaigns(id),
  meta            JSONB,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  deleted_at      TIMESTAMPTZ
);
COMMENT ON TABLE crm_deals IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE crm_deals ENABLE ROW LEVEL SECURITY;
CREATE POLICY crm_deals_tenant_isolation ON crm_deals
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());
CREATE INDEX crm_deals_ws_stage_idx ON crm_deals (workspace_id, stage_id, status) WHERE deleted_at IS NULL;
CREATE INDEX crm_deals_owner_idx ON crm_deals (workspace_id, owner_user_id, status);

CREATE TABLE crm_activities (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  deal_id         BIGINT REFERENCES crm_deals(id) ON DELETE CASCADE,
  company_id      BIGINT REFERENCES companies(id) ON DELETE CASCADE,
  contact_id      BIGINT REFERENCES contacts(id) ON DELETE CASCADE,
  activity_type   VARCHAR(40) NOT NULL,                    -- 'email_sent','email_received','call_log','meeting','note','linkedin_msg'
  subject         VARCHAR(255),
  body            TEXT,
  occurred_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  user_id         BIGINT REFERENCES users(id),
  meta            JSONB,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
COMMENT ON TABLE crm_activities IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE crm_activities ENABLE ROW LEVEL SECURITY;
CREATE POLICY crm_activities_tenant_isolation ON crm_activities
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());
CREATE INDEX crm_activities_deal_idx ON crm_activities (deal_id, occurred_at DESC);

CREATE TABLE crm_notes (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  deal_id         BIGINT REFERENCES crm_deals(id) ON DELETE CASCADE,
  company_id      BIGINT REFERENCES companies(id) ON DELETE CASCADE,
  contact_id      BIGINT REFERENCES contacts(id) ON DELETE CASCADE,
  body            TEXT NOT NULL,
  user_id         BIGINT REFERENCES users(id),
  is_pinned       BOOLEAN NOT NULL DEFAULT FALSE,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
COMMENT ON TABLE crm_notes IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE crm_notes ENABLE ROW LEVEL SECURITY;
CREATE POLICY crm_notes_tenant_isolation ON crm_notes
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());

CREATE TABLE crm_tasks (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  deal_id         BIGINT REFERENCES crm_deals(id) ON DELETE CASCADE,
  company_id      BIGINT REFERENCES companies(id) ON DELETE CASCADE,
  contact_id      BIGINT REFERENCES contacts(id) ON DELETE CASCADE,
  assigned_to     BIGINT REFERENCES users(id),
  title           VARCHAR(255) NOT NULL,
  description     TEXT,
  due_at          TIMESTAMPTZ,
  priority        VARCHAR(10) CHECK (priority IN ('low','normal','high','urgent')),
  status          VARCHAR(20) NOT NULL DEFAULT 'open'
                  CHECK (status IN ('open','in_progress','done','cancelled')),
  completed_at    TIMESTAMPTZ,
  created_by      BIGINT REFERENCES users(id),
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
COMMENT ON TABLE crm_tasks IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE crm_tasks ENABLE ROW LEVEL SECURITY;
CREATE POLICY crm_tasks_tenant_isolation ON crm_tasks
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());
CREATE INDEX crm_tasks_assigned_due_idx ON crm_tasks (workspace_id, assigned_to, due_at) WHERE status IN ('open','in_progress');
```

---

## 5. Analytics avancées

### `analytics_snapshots`, `analytics_funnels`, `analytics_cohorts`

```sql
CREATE TABLE analytics_snapshots (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  snapshot_date   DATE NOT NULL,
  scope           VARCHAR(40) NOT NULL,                    -- 'campaign','sequence','channel','global'
  scope_ref       VARCHAR(120),
  kpis            JSONB NOT NULL,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (workspace_id, snapshot_date, scope, scope_ref)
);
COMMENT ON TABLE analytics_snapshots IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE analytics_snapshots ENABLE ROW LEVEL SECURITY;
CREATE POLICY analytics_snapshots_tenant_isolation ON analytics_snapshots
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());

CREATE TABLE analytics_funnels (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  name            VARCHAR(180) NOT NULL,
  steps_definition JSONB NOT NULL,                          -- liste ordonnée des étapes
  date_range      JSONB NOT NULL,
  total_entered   INTEGER NOT NULL DEFAULT 0,
  total_converted INTEGER NOT NULL DEFAULT 0,
  steps_breakdown JSONB,
  computed_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
COMMENT ON TABLE analytics_funnels IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE analytics_funnels ENABLE ROW LEVEL SECURITY;
CREATE POLICY analytics_funnels_tenant_isolation ON analytics_funnels
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());

CREATE TABLE analytics_cohorts (
  id              BIGSERIAL PRIMARY KEY,
  workspace_id    BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  name            VARCHAR(180) NOT NULL,
  cohort_date     DATE NOT NULL,
  cohort_size     INTEGER NOT NULL DEFAULT 0,
  retention_data  JSONB,                                    -- {"week_1": 80, "week_2": 65, ...}
  computed_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
COMMENT ON TABLE analytics_cohorts IS 'Phase 2 scaffold — créée pour structure future, pas de logique métier active.';

ALTER TABLE analytics_cohorts ENABLE ROW LEVEL SECURITY;
CREATE POLICY analytics_cohorts_tenant_isolation ON analytics_cohorts
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());
```

---

## 6. Bilan tables Phase 2 scaffoldées

| Catégorie | Tables |
|---|---|
| Campagnes (orchestrateur) | `campaigns`, `campaign_targets`, `campaign_sequence_steps`, `campaign_executions`, `campaign_kpis_snapshots` |
| Cold Email | `sending_domains`, `smtp_ips`, `smtp_ip_warmup_log`, `warmup_states`, `email_templates`, `email_campaigns`, `email_sequences`, `email_steps`, `email_sends`, `email_bounces`, `email_replies`, `email_unsubscribes`, `email_deliverability_tracking` |
| LinkedIn Outreach | `linkedin_campaigns`, `linkedin_sequences`, `linkedin_message_templates`, `linkedin_connection_requests`, `linkedin_messages_sent` |
| CRM | `crm_pipelines`, `crm_stages`, `crm_deals`, `crm_activities`, `crm_notes`, `crm_tasks` |
| Analytics | `analytics_snapshots`, `analytics_funnels`, `analytics_cohorts` |
| **TOTAL Phase 2 scaffold** | **31 tables** |

---

## 7. Politique multi-tenant Phase 2

Toutes les tables Phase 2 sont tenant-scoped via `workspace_id` + RLS strict (`tenant_isolation` policy systématique). Aucune table Phase 2 n'est globale.

---

## 8. Conventions de scaffold à respecter strictement

1. **Aucun seeder Laravel** ne doit insérer de données métier dans ces tables en V1.
2. **Aucun worker Horizon ou BullMQ** ne doit lire/écrire dans ces tables en V1.
3. **Aucun service applicatif** (controller, action, job) ne doit instancier ces modèles en V1.
4. **Aucun endpoint REST métier** ne doit être actif. Routes Phase 2 répondent `501 Not Implemented` avec un body JSON `{"error":"phase_2_not_implemented","module":"<key>"}`.
5. **Aucun test E2E** ne doit dépendre du comportement métier Phase 2. Tests de schéma DB seulement (existence des tables, RLS active, FK propres).
6. **Pages UI** affichent un placeholder "Module en développement — sera activé en Phase 2" + lien vers `/docs/phase-2-roadmap.md`.

---

## 9. Préparation à l'activation Phase 2 (sans refactor)

L'activation Phase 2 consistera principalement à :

- Activer les listeners d'events Laravel qui sont déjà déclarés en V1 (`ContactReadyForColdEmail`, `LeadScored`, `DealCreatedFromContact`).
- Implémenter les workers BullMQ/Horizon Phase 2 dont les queues sont déjà nommées (`cold-email-send`, `linkedin-outreach-message`, etc.).
- Remplacer les `501 Not Implemented` des routes Phase 2 par des controllers réels.
- Remplacer les placeholders UI par les composants React fonctionnels.
- Aucune migration de données ne sera nécessaire — les tables sont déjà créées et bien indexées.

→ Voir `23_interfaces_phase2_execution_pack.md` partie A pour le détail des hooks d'intégration.

---

## Prochaine étape

→ Lire `05_scrapers_14_sources.md` pour la spec exhaustive des 14 sources de scraping.
