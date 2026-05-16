# 04 — DB schema Phase 2 (scaffold)

> **Scope :** Tables Phase 2, créées vides au démarrage. Pas de logique métier active en Phase 1. Permet de construire l'UI Phase 2 (pages affichant placeholder) et de figer le schéma DB pour éviter migrations douloureuses plus tard.
> **Règle dure :** Tous les CREATE TABLE ici sont exécutables Phase 1 (la DB schema est entièrement créée). Mais aucun INSERT n'a lieu tant que la logique métier Phase 2 n'est pas codée.

---

## §1 — Campagnes (orchestrateur multi-canal)

### `campaigns`

```sql
CREATE TABLE campaigns (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    name            TEXT NOT NULL,
    description     TEXT,
    channel         TEXT NOT NULL,                  -- 'email'|'linkedin'|'multi'
    status          TEXT NOT NULL DEFAULT 'draft',  -- draft|scheduled|running|paused|completed|archived
    starts_at       TIMESTAMPTZ,
    ends_at         TIMESTAMPTZ,
    timezone        TEXT NOT NULL DEFAULT 'Europe/Paris',
    audience_rule   JSONB NOT NULL DEFAULT '{}'::jsonb,    -- SQL-like rule serialized (NAF, taille, signal, etc.)
    sender_profile  JSONB NOT NULL DEFAULT '{}'::jsonb,    -- sending_domain, smtp_ip_pool, signature, etc.
    schedule_config JSONB NOT NULL DEFAULT '{}'::jsonb,    -- daily caps, business hours, etc.
    safety_caps     JSONB NOT NULL DEFAULT '{}'::jsonb,    -- max_sends_per_day, opt_out_check, etc.
    created_by      UUID REFERENCES users(id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    archived_at     TIMESTAMPTZ
);
CREATE INDEX idx_campaigns_workspace ON campaigns (workspace_id, status);
COMMENT ON TABLE campaigns IS 'Phase 2 scaffold — orchestrateur multi-canal. Vide en Phase 1.';
```

### `campaign_targets` (liste audience matérialisée)

```sql
CREATE TABLE campaign_targets (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    campaign_id     UUID NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
    contact_id      UUID NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
    company_id      UUID NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    status          TEXT NOT NULL DEFAULT 'pending',   -- pending|in_sequence|completed|opted_out|bounced|replied|disqualified
    current_step    INT NOT NULL DEFAULT 0,
    enqueued_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    next_action_at  TIMESTAMPTZ,
    completed_at    TIMESTAMPTZ,
    metadata        JSONB NOT NULL DEFAULT '{}'::jsonb,
    UNIQUE (campaign_id, contact_id)
);
CREATE INDEX idx_camp_targets_due ON campaign_targets (workspace_id, status, next_action_at)
  WHERE status IN ('pending','in_sequence');
COMMENT ON TABLE campaign_targets IS 'Phase 2 scaffold.';
```

### `campaign_sequence_steps`

```sql
CREATE TABLE campaign_sequence_steps (
    id              BIGSERIAL PRIMARY KEY,
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    campaign_id     UUID NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
    step_number     INT NOT NULL,                       -- 1, 2, 3, ...
    step_type       TEXT NOT NULL,                      -- 'email'|'linkedin_connect'|'linkedin_message'|'wait'|'condition'
    delay_after_previous_hours INT NOT NULL DEFAULT 72,
    template_id     UUID,                               -- → email_templates OR linkedin_message_templates
    condition_dsl   JSONB,                              -- pour step_type='condition'
    is_active       BOOLEAN NOT NULL DEFAULT true,
    UNIQUE (campaign_id, step_number)
);
COMMENT ON TABLE campaign_sequence_steps IS 'Phase 2 scaffold.';
```

### `campaign_executions`

```sql
CREATE TABLE campaign_executions (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    campaign_id     UUID NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
    target_id       UUID NOT NULL REFERENCES campaign_targets(id) ON DELETE CASCADE,
    step_id         BIGINT NOT NULL REFERENCES campaign_sequence_steps(id) ON DELETE CASCADE,
    executed_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    channel         TEXT NOT NULL,
    status          TEXT NOT NULL,                      -- 'sent'|'failed'|'skipped'|'queued'
    external_ref    TEXT,                               -- ex: SES messageId
    error           TEXT,
    metadata        JSONB NOT NULL DEFAULT '{}'::jsonb
);
CREATE INDEX idx_camp_exec_campaign ON campaign_executions (campaign_id, executed_at DESC);
COMMENT ON TABLE campaign_executions IS 'Phase 2 scaffold.';
```

### `campaign_kpis_snapshots` (rollup quotidien)

```sql
CREATE TABLE campaign_kpis_snapshots (
    id              BIGSERIAL PRIMARY KEY,
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    campaign_id     UUID NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
    snapshot_date   DATE NOT NULL,
    contacts_total  INT NOT NULL DEFAULT 0,
    sends           INT NOT NULL DEFAULT 0,
    opens           INT NOT NULL DEFAULT 0,
    clicks          INT NOT NULL DEFAULT 0,
    replies         INT NOT NULL DEFAULT 0,
    positive_replies INT NOT NULL DEFAULT 0,
    bounces         INT NOT NULL DEFAULT 0,
    unsubscribes    INT NOT NULL DEFAULT 0,
    meetings_booked INT NOT NULL DEFAULT 0,
    deals_created   INT NOT NULL DEFAULT 0,
    UNIQUE (campaign_id, snapshot_date)
);
COMMENT ON TABLE campaign_kpis_snapshots IS 'Phase 2 scaffold.';
```

---

## §2 — Cold Email (envoi de masse — finalité business)

### `sending_domains` (domaines secondaires dédiés cold email)

```sql
CREATE TABLE sending_domains (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id        UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    domain              TEXT NOT NULL,                      -- ex: 'axion-prospect.com'
    is_primary          BOOLEAN NOT NULL DEFAULT false,
    spf_status          TEXT,                               -- 'configured'|'missing'|'misconfigured'
    dkim_status         TEXT,
    dmarc_status        TEXT,
    dmarc_policy        TEXT,                               -- 'none'|'quarantine'|'reject'
    warmup_started_at   TIMESTAMPTZ,
    warmup_completed_at TIMESTAMPTZ,
    health_score        SMALLINT NOT NULL DEFAULT 100,      -- 0-100
    is_enabled          BOOLEAN NOT NULL DEFAULT true,
    notes               TEXT,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (workspace_id, domain)
);
COMMENT ON TABLE sending_domains IS 'Phase 2 scaffold.';
```

### `smtp_ips`

```sql
CREATE TABLE smtp_ips (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id        UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    ip_address          INET NOT NULL,
    provider            TEXT NOT NULL,                      -- 'aws_ses'|'sendgrid'|'mailgun'|'postmark'|'own'
    reverse_dns         TEXT,
    reputation_score    SMALLINT,                           -- 0-100 (Sender Score, Postmark Mail-Tester, etc.)
    sending_domain_id   UUID REFERENCES sending_domains(id),
    daily_cap           INT NOT NULL DEFAULT 50,            -- monte progressivement avec warmup
    daily_sent          INT NOT NULL DEFAULT 0,
    daily_reset_at      DATE NOT NULL DEFAULT current_date,
    warmup_status       TEXT NOT NULL DEFAULT 'pending',    -- 'pending'|'in_progress'|'completed'|'paused'
    is_enabled          BOOLEAN NOT NULL DEFAULT true,
    notes               TEXT,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE smtp_ips IS 'Phase 2 scaffold.';
```

### `smtp_ip_warmup_log`

```sql
CREATE TABLE smtp_ip_warmup_log (
    id              BIGSERIAL PRIMARY KEY,
    smtp_ip_id      UUID NOT NULL REFERENCES smtp_ips(id) ON DELETE CASCADE,
    warmup_day      INT NOT NULL,                           -- 1..30
    target_volume   INT NOT NULL,
    actual_volume   INT NOT NULL DEFAULT 0,
    bounce_rate     NUMERIC(5,4),
    complaint_rate  NUMERIC(5,4),
    date            DATE NOT NULL DEFAULT current_date,
    notes           TEXT,
    UNIQUE (smtp_ip_id, warmup_day)
);
COMMENT ON TABLE smtp_ip_warmup_log IS 'Phase 2 scaffold — suit progression warmup 30 jours.';
```

### `warmup_states` (état warmup par boîte / IP / domaine)

```sql
CREATE TABLE warmup_states (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    entity_type     TEXT NOT NULL,                          -- 'inbox'|'smtp_ip'|'domain'
    entity_id       TEXT NOT NULL,
    started_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    target_volume_per_day INT NOT NULL DEFAULT 200,
    current_step    INT NOT NULL DEFAULT 1,                 -- 1..N
    next_step_at    TIMESTAMPTZ,
    status          TEXT NOT NULL DEFAULT 'active',         -- 'active'|'paused'|'completed'|'failed'
    notes           TEXT,
    UNIQUE (workspace_id, entity_type, entity_id)
);
COMMENT ON TABLE warmup_states IS 'Phase 2 scaffold.';
```

### `email_campaigns`, `email_sequences`, `email_steps`, `email_templates`

```sql
CREATE TABLE email_campaigns (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id        UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    campaign_id         UUID REFERENCES campaigns(id) ON DELETE CASCADE,
    name                TEXT NOT NULL,
    sending_domain_id   UUID REFERENCES sending_domains(id),
    smtp_ip_pool        UUID[] NOT NULL DEFAULT '{}',
    daily_cap_per_inbox INT NOT NULL DEFAULT 50,
    business_hours_only BOOLEAN NOT NULL DEFAULT true,
    tz                  TEXT NOT NULL DEFAULT 'Europe/Paris',
    bcc                 CITEXT,
    reply_to            CITEXT,
    track_opens         BOOLEAN NOT NULL DEFAULT true,
    track_clicks        BOOLEAN NOT NULL DEFAULT true,
    add_unsubscribe_link BOOLEAN NOT NULL DEFAULT true,
    add_list_unsubscribe_header BOOLEAN NOT NULL DEFAULT true,
    status              TEXT NOT NULL DEFAULT 'draft',
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE email_campaigns IS 'Phase 2 scaffold.';

CREATE TABLE email_sequences (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    email_campaign_id UUID NOT NULL REFERENCES email_campaigns(id) ON DELETE CASCADE,
    name            TEXT NOT NULL,
    is_active       BOOLEAN NOT NULL DEFAULT true,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE email_sequences IS 'Phase 2 scaffold.';

CREATE TABLE email_templates (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    slug            TEXT NOT NULL,
    label           TEXT NOT NULL,
    subject_template TEXT NOT NULL,
    body_template_html TEXT NOT NULL,
    body_template_text TEXT,
    variables       TEXT[] NOT NULL DEFAULT '{}',
    personalization_strategy TEXT NOT NULL DEFAULT 'standard',  -- 'standard'|'vip'|'aida'|'pas'
    llm_personalization_enabled BOOLEAN NOT NULL DEFAULT false,
    llm_use_case_slug TEXT,                                     -- → llm_use_cases
    is_active       BOOLEAN NOT NULL DEFAULT true,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (workspace_id, slug)
);
COMMENT ON TABLE email_templates IS 'Phase 2 scaffold.';

CREATE TABLE email_steps (
    id                  BIGSERIAL PRIMARY KEY,
    workspace_id        UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    email_sequence_id   UUID NOT NULL REFERENCES email_sequences(id) ON DELETE CASCADE,
    step_number         INT NOT NULL,
    delay_days          INT NOT NULL DEFAULT 3,
    template_id         UUID NOT NULL REFERENCES email_templates(id),
    skip_if_replied     BOOLEAN NOT NULL DEFAULT true,
    skip_if_opened      BOOLEAN NOT NULL DEFAULT false,
    UNIQUE (email_sequence_id, step_number)
);
COMMENT ON TABLE email_steps IS 'Phase 2 scaffold.';
```

### `email_sends` (PARTITIONNÉE par mois, Phase 2 actif → high volume)

```sql
CREATE TABLE email_sends (
    id                  UUID DEFAULT gen_random_uuid(),
    workspace_id        UUID NOT NULL,
    email_campaign_id   UUID,
    email_sequence_id   UUID,
    step_id             BIGINT,
    contact_id          UUID NOT NULL,
    company_id          UUID NOT NULL,
    template_id         UUID,
    sending_domain_id   UUID,
    smtp_ip_id          UUID,
    from_email          CITEXT NOT NULL,
    to_email            CITEXT NOT NULL,
    subject             TEXT NOT NULL,
    body_html           TEXT,
    body_text           TEXT,
    external_message_id TEXT,
    status              TEXT NOT NULL DEFAULT 'queued',  -- 'queued'|'sent'|'delivered'|'bounced'|'failed'|'cancelled'
    sent_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    delivered_at        TIMESTAMPTZ,
    opened_first_at     TIMESTAMPTZ,
    opened_count        INT NOT NULL DEFAULT 0,
    clicked_first_at    TIMESTAMPTZ,
    clicked_count       INT NOT NULL DEFAULT 0,
    bounced_at          TIMESTAMPTZ,
    bounce_type         TEXT,
    error               TEXT,
    headers             JSONB NOT NULL DEFAULT '{}'::jsonb,
    PRIMARY KEY (id, sent_at)
) PARTITION BY RANGE (sent_at);

-- Partman setup (créé en Phase 1, premake 6 mois)
SELECT partman.create_parent(
    p_parent_table => 'public.email_sends',
    p_control => 'sent_at',
    p_type => 'native',
    p_interval => '1 month',
    p_premake => 6
);
UPDATE partman.part_config SET retention = '24 months' WHERE parent_table = 'public.email_sends';

CREATE INDEX idx_sends_workspace_sent ON email_sends (workspace_id, sent_at DESC);
CREATE INDEX idx_sends_contact ON email_sends (contact_id, sent_at DESC);
CREATE INDEX idx_sends_status ON email_sends (workspace_id, status, sent_at DESC);
CREATE INDEX idx_sends_external_id ON email_sends (external_message_id);

COMMENT ON TABLE email_sends IS 'Phase 2 scaffold. PARTITIONNÉE — high volume attendu Phase 2.';
```

### `email_bounces`, `email_replies`, `email_unsubscribes`

```sql
CREATE TABLE email_bounces (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    email_send_id   UUID NOT NULL,
    email           CITEXT NOT NULL,
    bounce_type     TEXT NOT NULL,                          -- 'hard'|'soft'|'block'|'spam'
    bounce_subtype  TEXT,                                   -- 'mailbox_full'|'invalid_address'|...
    bounced_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    raw_message     TEXT,
    smtp_response   JSONB
);
COMMENT ON TABLE email_bounces IS 'Phase 2 scaffold.';

CREATE TABLE email_replies (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    email_send_id   UUID,
    contact_id      UUID NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
    reply_email     CITEXT NOT NULL,
    subject         TEXT,
    body_text       TEXT,
    body_html       TEXT,
    received_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    intent_label    TEXT,                                   -- 'positive'|'negative'|'oof'|'unsubscribe'|'meeting'|'question'|'other'
    intent_score    SMALLINT,
    handled_at      TIMESTAMPTZ,
    handled_by      UUID REFERENCES users(id)
);
CREATE INDEX idx_replies_contact ON email_replies (contact_id, received_at DESC);
COMMENT ON TABLE email_replies IS 'Phase 2 scaffold.';

CREATE TABLE email_unsubscribes (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    email           CITEXT NOT NULL,
    contact_id      UUID REFERENCES contacts(id) ON DELETE SET NULL,
    unsubscribed_via TEXT NOT NULL,                         -- 'link'|'reply'|'list_unsubscribe'|'manual'
    reason          TEXT,
    unsubscribed_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_unsub_email ON email_unsubscribes (email);
COMMENT ON TABLE email_unsubscribes IS 'Phase 2 scaffold. AUTO-INSERT en opt_out global lors d''enregistrement.';
```

### `email_deliverability_tracking`

```sql
CREATE TABLE email_deliverability_tracking (
    id              BIGSERIAL PRIMARY KEY,
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    sending_domain_id UUID REFERENCES sending_domains(id) ON DELETE CASCADE,
    smtp_ip_id      UUID REFERENCES smtp_ips(id) ON DELETE CASCADE,
    snapshot_date   DATE NOT NULL,
    inbox_rate      NUMERIC(5,4),
    spam_rate       NUMERIC(5,4),
    sender_score    SMALLINT,
    google_postmaster_score TEXT,                           -- 'good'|'normal'|'bad'|'unknown'
    blacklist_hits  TEXT[],                                 -- ['spamhaus','barracuda',...]
    metadata        JSONB NOT NULL DEFAULT '{}'::jsonb,
    UNIQUE (sending_domain_id, smtp_ip_id, snapshot_date)
);
COMMENT ON TABLE email_deliverability_tracking IS 'Phase 2 scaffold.';
```

---

## §3 — LinkedIn Outreach Phase 2

> **Important :** En Phase 1, AUCUNE action sur LinkedIn (juste récupération URLs publiques via Google Search Wrapper). Phase 2 ajoute des comptes LinkedIn opérés (via Unipile/LiCM/Phantombuster premium au choix futur).

### `linkedin_accounts` (comptes opérés)

```sql
CREATE TABLE linkedin_accounts (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id        UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    operator_user_id    UUID REFERENCES users(id),
    profile_url         TEXT NOT NULL,
    full_name           TEXT NOT NULL,
    headline            TEXT,
    connections_count   INT,
    daily_invite_cap    INT NOT NULL DEFAULT 20,
    daily_message_cap   INT NOT NULL DEFAULT 80,
    timezone            TEXT NOT NULL DEFAULT 'Europe/Paris',
    business_hours_only BOOLEAN NOT NULL DEFAULT true,
    status              TEXT NOT NULL DEFAULT 'active',     -- 'active'|'paused'|'restricted'|'banned'
    last_activity_at    TIMESTAMPTZ,
    integration_provider TEXT,                              -- 'unipile'|'licm'|'phantombuster'|'custom'
    integration_ref     TEXT,                               -- session ID externe
    notes               TEXT
);
COMMENT ON TABLE linkedin_accounts IS 'Phase 2 scaffold.';
```

### `linkedin_campaigns`, `linkedin_sequences`, `linkedin_message_templates`

```sql
CREATE TABLE linkedin_campaigns (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id        UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    campaign_id         UUID REFERENCES campaigns(id) ON DELETE CASCADE,
    name                TEXT NOT NULL,
    linkedin_account_id UUID NOT NULL REFERENCES linkedin_accounts(id),
    status              TEXT NOT NULL DEFAULT 'draft',
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE linkedin_campaigns IS 'Phase 2 scaffold.';

CREATE TABLE linkedin_sequences (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    linkedin_campaign_id UUID NOT NULL REFERENCES linkedin_campaigns(id) ON DELETE CASCADE,
    name            TEXT NOT NULL,
    is_active       BOOLEAN NOT NULL DEFAULT true
);
COMMENT ON TABLE linkedin_sequences IS 'Phase 2 scaffold.';

CREATE TABLE linkedin_message_templates (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    slug            TEXT NOT NULL,
    label           TEXT NOT NULL,
    message_type    TEXT NOT NULL,                          -- 'connection_request'|'first_message'|'follow_up'|'inmail'
    body_template   TEXT NOT NULL,
    max_chars       INT NOT NULL,                           -- 300 pour connection, 2000 pour message
    variables       TEXT[] NOT NULL DEFAULT '{}',
    llm_personalization_enabled BOOLEAN NOT NULL DEFAULT false,
    UNIQUE (workspace_id, slug)
);
COMMENT ON TABLE linkedin_message_templates IS 'Phase 2 scaffold.';
```

### `linkedin_connection_requests`, `linkedin_messages_sent`

```sql
CREATE TABLE linkedin_connection_requests (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id        UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    linkedin_account_id UUID NOT NULL REFERENCES linkedin_accounts(id),
    contact_id          UUID NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
    company_id          UUID NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    sent_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    note_text           TEXT,
    status              TEXT NOT NULL DEFAULT 'sent',       -- 'sent'|'accepted'|'declined'|'expired'|'withdrawn'
    accepted_at         TIMESTAMPTZ,
    declined_at         TIMESTAMPTZ
);
CREATE INDEX idx_li_invite_contact ON linkedin_connection_requests (contact_id);
COMMENT ON TABLE linkedin_connection_requests IS 'Phase 2 scaffold.';

CREATE TABLE linkedin_messages_sent (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id        UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    linkedin_account_id UUID NOT NULL REFERENCES linkedin_accounts(id),
    contact_id          UUID NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
    template_id         UUID REFERENCES linkedin_message_templates(id),
    body                TEXT NOT NULL,
    sent_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    read_at             TIMESTAMPTZ,
    replied_at          TIMESTAMPTZ,
    reply_text          TEXT,
    intent_label        TEXT,
    intent_score        SMALLINT
);
CREATE INDEX idx_li_msg_contact ON linkedin_messages_sent (contact_id, sent_at DESC);
COMMENT ON TABLE linkedin_messages_sent IS 'Phase 2 scaffold.';
```

---

## §4 — CRM (pipeline, deals, activités)

### `crm_pipelines`, `crm_stages`

```sql
CREATE TABLE crm_pipelines (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    name            TEXT NOT NULL,                          -- ex: 'B2B France', 'Académique'
    slug            TEXT NOT NULL,
    description     TEXT,
    is_default      BOOLEAN NOT NULL DEFAULT false,
    is_active       BOOLEAN NOT NULL DEFAULT true,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (workspace_id, slug)
);
COMMENT ON TABLE crm_pipelines IS 'Phase 2 scaffold.';

CREATE TABLE crm_stages (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    pipeline_id     UUID NOT NULL REFERENCES crm_pipelines(id) ON DELETE CASCADE,
    name            TEXT NOT NULL,                          -- 'Lead', 'Qualified', 'Demo', 'Proposal', 'Won', 'Lost'
    slug            TEXT NOT NULL,
    color           TEXT,
    position        INT NOT NULL,
    win_probability NUMERIC(4,3) NOT NULL DEFAULT 0.50,
    is_won_stage    BOOLEAN NOT NULL DEFAULT false,
    is_lost_stage   BOOLEAN NOT NULL DEFAULT false,
    UNIQUE (pipeline_id, slug)
);
COMMENT ON TABLE crm_stages IS 'Phase 2 scaffold.';
```

### `crm_deals`

```sql
CREATE TABLE crm_deals (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    pipeline_id     UUID NOT NULL REFERENCES crm_pipelines(id) ON DELETE CASCADE,
    stage_id        UUID NOT NULL REFERENCES crm_stages(id),
    title           TEXT NOT NULL,
    company_id      UUID NOT NULL REFERENCES companies(id),
    primary_contact_id UUID REFERENCES contacts(id),
    amount_eur      NUMERIC(12,2),
    currency        TEXT NOT NULL DEFAULT 'EUR',
    expected_close_date DATE,
    closed_at       TIMESTAMPTZ,
    won_lost_reason TEXT,
    owner_id        UUID REFERENCES users(id),
    source          TEXT,                                   -- 'cold_email'|'linkedin'|'referral'|'inbound'|...
    score           SMALLINT,                               -- 0-100 (LLM-scored)
    notes           TEXT,
    metadata        JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_deals_workspace_pipeline ON crm_deals (workspace_id, pipeline_id, stage_id);
CREATE INDEX idx_deals_company ON crm_deals (company_id);
CREATE INDEX idx_deals_owner_open ON crm_deals (owner_id) WHERE closed_at IS NULL;
COMMENT ON TABLE crm_deals IS 'Phase 2 scaffold.';
```

### `crm_activities`, `crm_notes`, `crm_tasks`

```sql
CREATE TABLE crm_activities (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    deal_id         UUID REFERENCES crm_deals(id) ON DELETE CASCADE,
    contact_id      UUID REFERENCES contacts(id) ON DELETE CASCADE,
    company_id      UUID REFERENCES companies(id) ON DELETE CASCADE,
    activity_type   TEXT NOT NULL,                          -- 'call'|'meeting'|'email_sent'|'email_received'|'linkedin_message'|'note'|'stage_change'|'demo'
    subject         TEXT,
    body            TEXT,
    occurred_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    duration_min    INT,
    outcome         TEXT,
    created_by      UUID REFERENCES users(id),
    metadata        JSONB NOT NULL DEFAULT '{}'::jsonb
);
CREATE INDEX idx_activities_deal ON crm_activities (deal_id, occurred_at DESC);
CREATE INDEX idx_activities_company ON crm_activities (company_id, occurred_at DESC);
COMMENT ON TABLE crm_activities IS 'Phase 2 scaffold.';

CREATE TABLE crm_notes (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    deal_id         UUID REFERENCES crm_deals(id) ON DELETE CASCADE,
    contact_id      UUID REFERENCES contacts(id) ON DELETE CASCADE,
    company_id      UUID REFERENCES companies(id) ON DELETE CASCADE,
    content         TEXT NOT NULL,
    is_pinned       BOOLEAN NOT NULL DEFAULT false,
    created_by      UUID NOT NULL REFERENCES users(id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE crm_notes IS 'Phase 2 scaffold.';

CREATE TABLE crm_tasks (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    deal_id         UUID REFERENCES crm_deals(id) ON DELETE CASCADE,
    contact_id      UUID REFERENCES contacts(id) ON DELETE CASCADE,
    title           TEXT NOT NULL,
    description     TEXT,
    due_at          TIMESTAMPTZ,
    completed_at    TIMESTAMPTZ,
    assignee_id     UUID REFERENCES users(id),
    priority        TEXT NOT NULL DEFAULT 'medium',         -- 'low'|'medium'|'high'|'urgent'
    created_by      UUID NOT NULL REFERENCES users(id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_tasks_assignee_open ON crm_tasks (assignee_id, due_at) WHERE completed_at IS NULL;
COMMENT ON TABLE crm_tasks IS 'Phase 2 scaffold.';
```

---

## §5 — Analytics avancées

### `analytics_snapshots`

```sql
CREATE TABLE analytics_snapshots (
    id              BIGSERIAL PRIMARY KEY,
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    metric_slug     TEXT NOT NULL,                          -- 'fiches_complete_total'|'email_sent_today'|'reply_rate_30d'|...
    snapshot_date   DATE NOT NULL,
    dimensions      JSONB NOT NULL DEFAULT '{}'::jsonb,     -- {department:'75', size:'pme'}
    value_numeric   NUMERIC(18,6),
    value_text      TEXT,
    UNIQUE (workspace_id, metric_slug, snapshot_date, dimensions)
);
CREATE INDEX idx_analytics_metric ON analytics_snapshots (metric_slug, snapshot_date DESC);
COMMENT ON TABLE analytics_snapshots IS 'Phase 2 scaffold. Rollup quotidien KPIs.';
```

### `analytics_funnels`, `analytics_cohorts`

```sql
CREATE TABLE analytics_funnels (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    name            TEXT NOT NULL,
    steps           JSONB NOT NULL,                         -- [{label,query_dsl}, ...]
    is_active       BOOLEAN NOT NULL DEFAULT true,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE analytics_funnels IS 'Phase 2 scaffold.';

CREATE TABLE analytics_cohorts (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    name            TEXT NOT NULL,
    cohort_by       TEXT NOT NULL,                          -- 'first_contact_month'|'first_reply_month'|...
    metric          TEXT NOT NULL,                          -- 'retention'|'response_rate'|...
    period          TEXT NOT NULL,                          -- 'daily'|'weekly'|'monthly'
    data            JSONB NOT NULL DEFAULT '{}'::jsonb,
    refreshed_at    TIMESTAMPTZ
);
COMMENT ON TABLE analytics_cohorts IS 'Phase 2 scaffold.';
```

---

## §6 — RLS Phase 2

```sql
DO $$
DECLARE
    t TEXT;
    tables TEXT[] := ARRAY[
        'campaigns','campaign_targets','campaign_sequence_steps','campaign_executions','campaign_kpis_snapshots',
        'sending_domains','smtp_ips','smtp_ip_warmup_log','warmup_states',
        'email_campaigns','email_sequences','email_templates','email_steps',
        'email_bounces','email_replies','email_unsubscribes','email_deliverability_tracking',
        'linkedin_accounts','linkedin_campaigns','linkedin_sequences','linkedin_message_templates',
        'linkedin_connection_requests','linkedin_messages_sent',
        'crm_pipelines','crm_stages','crm_deals','crm_activities','crm_notes','crm_tasks',
        'analytics_snapshots','analytics_funnels','analytics_cohorts'
    ];
BEGIN
    FOREACH t IN ARRAY tables LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
        EXECUTE format($p$
            CREATE POLICY workspace_isolation ON %I
                USING (workspace_id = current_setting('app.current_workspace_id', true)::uuid)
                WITH CHECK (workspace_id = current_setting('app.current_workspace_id', true)::uuid)
        $p$, t);
    END LOOP;
END$$;

-- email_sends partitionnée
ALTER TABLE email_sends ENABLE ROW LEVEL SECURITY;
CREATE POLICY workspace_isolation ON email_sends
    USING (workspace_id = current_setting('app.current_workspace_id', true)::uuid)
    WITH CHECK (workspace_id = current_setting('app.current_workspace_id', true)::uuid);
```

---

## §7 — Inventaire & migrations

### Inventaire tables Phase 2

| § | Catégorie | Tables | Partitionnées |
|---|-----------|--------|---------------|
| 1 | Campagnes orchestrateur | 5 | — |
| 2 | Cold Email | 13 | `email_sends` |
| 3 | LinkedIn Outreach | 7 | — |
| 4 | CRM | 7 | — |
| 5 | Analytics | 3 | — |
| **Total Phase 2** | | **35 tables** | **1 partitionnée** |

### Migrations Laravel Phase 2 (ordre)

```
2026_05_16_002000_create_campaigns_orchestrator.php
2026_05_16_002010_create_campaign_targets.php
2026_05_16_002020_create_campaign_sequence_steps.php
2026_05_16_002030_create_campaign_executions.php
2026_05_16_002040_create_campaign_kpis_snapshots.php
2026_05_16_002100_create_sending_domains.php
2026_05_16_002110_create_smtp_ips.php
2026_05_16_002120_create_smtp_ip_warmup_log.php
2026_05_16_002130_create_warmup_states.php
2026_05_16_002200_create_email_campaigns.php
2026_05_16_002210_create_email_sequences.php
2026_05_16_002220_create_email_templates.php
2026_05_16_002230_create_email_steps.php
2026_05_16_002240_create_email_sends_partitioned.php
2026_05_16_002250_create_email_bounces.php
2026_05_16_002260_create_email_replies.php
2026_05_16_002270_create_email_unsubscribes.php
2026_05_16_002280_create_email_deliverability_tracking.php
2026_05_16_002300_create_linkedin_accounts.php
2026_05_16_002310_create_linkedin_campaigns.php
2026_05_16_002320_create_linkedin_sequences.php
2026_05_16_002330_create_linkedin_message_templates.php
2026_05_16_002340_create_linkedin_connection_requests.php
2026_05_16_002350_create_linkedin_messages_sent.php
2026_05_16_002400_create_crm_pipelines.php
2026_05_16_002410_create_crm_stages.php
2026_05_16_002420_create_crm_deals.php
2026_05_16_002430_create_crm_activities.php
2026_05_16_002440_create_crm_notes.php
2026_05_16_002450_create_crm_tasks.php
2026_05_16_002500_create_analytics_snapshots.php
2026_05_16_002510_create_analytics_funnels.php
2026_05_16_002520_create_analytics_cohorts.php
2026_05_16_002990_enable_rls_phase2.php
```

---

## §8 — Triggers et automation cross-table (Phase 2 ready)

### Auto-insertion en `opt_out` global lors d'unsubscribe

```sql
CREATE OR REPLACE FUNCTION trg_unsubscribe_to_optout()
RETURNS TRIGGER AS $$
BEGIN
    INSERT INTO opt_out (email, reason, source)
    VALUES (NEW.email, 'user_request', NEW.unsubscribed_via)
    ON CONFLICT DO NOTHING;
    RETURN NEW;
END$$ LANGUAGE plpgsql;

CREATE TRIGGER email_unsubscribe_to_optout
    AFTER INSERT ON email_unsubscribes
    FOR EACH ROW EXECUTE FUNCTION trg_unsubscribe_to_optout();

-- Note : trigger CRÉÉ Phase 1, mais ne firera jamais tant que email_unsubscribes vide.
```

### Auto-insertion en `opt_out` lors de hard bounce

```sql
CREATE OR REPLACE FUNCTION trg_bounce_hard_to_optout()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.bounce_type = 'hard' THEN
        INSERT INTO opt_out (email, reason, source)
        VALUES (NEW.email, 'bounce_hard', 'bounce')
        ON CONFLICT DO NOTHING;
    END IF;
    RETURN NEW;
END$$ LANGUAGE plpgsql;

CREATE TRIGGER email_bounce_hard_to_optout
    AFTER INSERT ON email_bounces
    FOR EACH ROW EXECUTE FUNCTION trg_bounce_hard_to_optout();
```

### Auto-update `contacts.primary_email_status` lors de validation

(Trigger fonctionnel Phase 1 — déjà actif puisque `email_verifications` est utilisée par l'Email Finder.)

```sql
CREATE OR REPLACE FUNCTION trg_sync_contact_email_status()
RETURNS TRIGGER AS $$
BEGIN
    UPDATE contacts
       SET primary_email_status = NEW.validation_status,
           primary_email_score  = NEW.score,
           updated_at = now()
     WHERE id = NEW.contact_id
       AND primary_email = NEW.email;
    RETURN NEW;
END$$ LANGUAGE plpgsql;

CREATE TRIGGER email_verif_sync_contact
    AFTER INSERT OR UPDATE ON email_verifications
    FOR EACH ROW EXECUTE FUNCTION trg_sync_contact_email_status();
```

### Auto-recompute `companies.quality_score` — déplacée en Phase 1

> **P0 audit corrigé v1.1** : la fonction `recompute_company_quality_score()` + ses triggers + la fonction `compute_size_category()` sont maintenant définies dans `03_db_schema_phase1.md` § 11ter (Phase 1).
> Raison : ces fonctions sont utilisées dès le waterfall enrichissement Phase 1, leur placement initial en Phase 2 scaffold créait une dépendance d'ordre de migration cassée.

---

## §9 — Volumétrie attendue (capacity planning)

| Table | Phase 1 (12 mois) | Phase 2 (24 mois) | Index size estimé |
|-------|-------------------|-------------------|--------------------|
| `companies` | 200 k → 1 M | 1 M → 3 M | ~500 MB |
| `contacts` | 400 k → 2 M | 2 M → 6 M | ~700 MB |
| `email_verifications` | 800 k → 4 M | 4 M → 15 M | ~1.5 GB |
| `scraper_runs` (12 mois) | ~50 M rows | ~150 M rows | partitionnée ~5 GB |
| `llm_usage` (12 mois) | ~30 M rows | ~80 M rows | partitionnée ~3 GB |
| `email_sends` (Phase 2 actif) | 0 | ~50 M rows | partitionnée ~10 GB |
| `audit_logs` (12 mois) | ~5 M rows | ~30 M rows | partitionnée ~1 GB |
| `coverage_matrix_cells` MV | ~15 k rows | ~80 k rows | <50 MB |

**Stockage total estimé** : ~15 GB Phase 1 fin année 1, ~50 GB Phase 2 fin année 2.

CCX13 80 GB suffit largement Phase 1. Migration vers volume bloc 200 GB en Phase 2.

---

## Lecture suivante

→ `05_scrapers_14_sources.md` (14 sources détaillées + Google Search Wrapper + Direction Finder).
