# 14 — API ROUTES LARAVEL

> **Convention :** toutes les routes sont préfixées `/api`. Format `application/json` exclusivement.
>
> **Auth :** Sanctum cookie SPA (CSRF requis sur mutations). Les routes `/api/auth/login` + `/api/auth/csrf` + `/api/auth/magic-link/request` + `/api/auth/magic-link/consume` sont publiques. Le reste exige `auth:sanctum` + workspace_id injecté.
>
> **Format réponse standard :**
> ```json
> { "data": { ... }, "meta": { "page": 1, "perPage": 25, "total": 42 } }
> ```
>
> **Format erreur standard :**
> ```json
> { "error": "validation_failed", "message": "Le champ siren est requis.", "details": { "siren": ["required"] } }
> ```
>
> **Payloads typés :** Spatie Laravel Data. Chaque route définit un `*Request` DTO et renvoie un `*Resource` DTO sérialisé.
>
> **Rate limiting :** 60 req/min/IP sur routes publiques + 600 req/min/user sur routes auth (configurable runtime via `cache:throttle:axion-crm.api`).

---

## 1. AUTH (`/api/auth/*`)

| Method | Path | Body / Query | Reply | Status |
|---|---|---|---|---|
| `GET` | `/api/auth/csrf` | — | `{ "ok": true }` (set XSRF-TOKEN cookie) | 200 |
| `POST` | `/api/auth/login` | `LoginRequest { email, password, totp_code? }` | `{ "user": UserResource, "requires_2fa": bool, "workspaces": Workspace[] }` | 200, 401, 422 |
| `POST` | `/api/auth/logout` | — | `{ "ok": true }` | 200 |
| `POST` | `/api/auth/2fa/setup` | — | `{ "qr_code_url": str, "secret": str, "backup_codes": str[] }` | 200 |
| `POST` | `/api/auth/2fa/verify` | `{ "totp_code": str }` | `{ "ok": true }` | 200, 422 |
| `DELETE` | `/api/auth/2fa` | `{ "password": str }` | `{ "ok": true }` | 200 |
| `POST` | `/api/auth/magic-link/request` | `{ "email": str }` | `{ "ok": true }` (envoi email même si email inconnu) | 200 |
| `POST` | `/api/auth/magic-link/consume` | `{ "token": str }` | `{ "user": UserResource }` | 200, 422 |
| `POST` | `/api/auth/refresh` | — | `{ "user": UserResource }` (refresh session) | 200 |
| `GET` | `/api/auth/me` | — | `{ "user": UserResource, "workspace": WorkspaceResource, "permissions": str[] }` | 200 |

---

## 2. WORKSPACES (`/api/workspaces/*`)

| Method | Path | Auth | Description |
|---|---|---|---|
| `GET` | `/api/workspaces` | sanctum + super_admin | Liste tous workspaces (V1 = 1 seul) |
| `GET` | `/api/workspaces/{slug}` | sanctum | Détails workspace courant (cookies workspace_id) |
| `PUT` | `/api/workspaces/{slug}` | owner | Update name/plan/status |
| `POST` | `/api/workspaces/switch` | sanctum | Switch workspace courant : `{ "workspace_id": int }` |

---

## 3. USERS + INVITATIONS (`/api/users/*`)

| Method | Path | Body | Auth |
|---|---|---|---|
| `GET` | `/api/users` | (filtres : role, status) | admin |
| `GET` | `/api/users/{uuid}` | — | admin |
| `PUT` | `/api/users/{uuid}` | `UpdateUserRequest { first_name, last_name, locale, timezone, role? }` | admin |
| `DELETE` | `/api/users/{uuid}` | — | owner (soft delete) |
| `POST` | `/api/users/{uuid}/reset-2fa` | `{ "owner_password": str }` | owner |
| `POST` | `/api/users/{uuid}/disable` | — | admin |
| `POST` | `/api/users/{uuid}/enable` | — | admin |
| `GET` | `/api/users/invitations` | — | admin |
| `POST` | `/api/users/invitations` | `InviteUserRequest { email, role, message? }` | admin |
| `DELETE` | `/api/users/invitations/{id}` | — | admin (revoke) |
| `POST` | `/api/users/invitations/accept` | `{ "token": str, "first_name": str, "last_name": str, "password": str }` (public) | — |

---

## 4. COMPANIES (`/api/companies/*`)

| Method | Path | Body / Query | Auth | Reply |
|---|---|---|---|---|
| `GET` | `/api/companies` | `?page=1&per_page=50&filters[axion_offer]=mission_pme&filters[priority_score]=prioritaire&q=dupont&sort=-priority_score` | viewer | Paginated companies |
| `GET` | `/api/companies/{uuid}` | — | viewer | Full company + relations |
| `POST` | `/api/companies` | `CreateCompanyRequest` | operator | 201 (rare en V1, on importe via INSEE) |
| `PUT` | `/api/companies/{uuid}` | `UpdateCompanyRequest` | operator | 200 |
| `DELETE` | `/api/companies/{uuid}` | — | admin | 204 (soft delete) |
| `POST` | `/api/companies/{uuid}/relaunch-enrichment` | `{ "skip_steps": str[]? }` | operator | 202 (queue) |
| `POST` | `/api/companies/{uuid}/override-priority` | `OverrideScoresRequest { axion_offer?, priority_score?, contact_priority?, ia_maturity?, reason: str }` | operator | 200 |
| `POST` | `/api/companies/{uuid}/tags` | `{ "tag_keys": str[] }` | operator | 200 |
| `DELETE` | `/api/companies/{uuid}/tags/{tag_key}` | — | operator | 204 |
| `POST` | `/api/companies/bulk-export` | `BulkExportRequest { uuids: str[], format: 'csv'|'xlsx' }` | viewer | 202 (async, download URL by email) |
| `POST` | `/api/companies/bulk-disqualify` | `{ "uuids": str[], "reason": str }` | operator | 200 |
| `POST` | `/api/companies/bulk-tag` | `{ "uuids": str[], "tag_keys": str[] }` | operator | 200 |
| `GET` | `/api/companies/{uuid}/scraper-runs` | (filtres date, source) | viewer | Paginated runs |
| `GET` | `/api/companies/{uuid}/business-signals` | — | viewer | Liste signaux |
| `GET` | `/api/companies/{uuid}/audit-log` | — | admin | Liste audit_logs liées |

---

## 5. CONTACTS (`/api/contacts/*`)

| Method | Path | Notes |
|---|---|---|
| `GET` | `/api/contacts` | Filtres : company_uuid, position_function, is_executive, is_legal_representative, has_validated_email |
| `GET` | `/api/contacts/{uuid}` | Détail + emails + history |
| `POST` | `/api/contacts` | Manual create (rare) |
| `PUT` | `/api/contacts/{uuid}` | Update |
| `DELETE` | `/api/contacts/{uuid}` | Soft delete |
| `POST` | `/api/contacts/{uuid}/find-email` | Déclenche email finder + validation cascade |
| `GET` | `/api/contacts/{uuid}/emails` | Liste tous emails du contact + statuts |

---

## 6. SCRAPER (`/api/scraper/*`)

| Method | Path | Auth |
|---|---|---|
| `GET` | `/api/scraper/runs` | `?page=1&filters[source_key]=gmaps&filters[status]=error&filters[date_from]=2026-05-01` | viewer |
| `GET` | `/api/scraper/runs/{id}` | — | viewer (drill-down) |
| `GET` | `/api/scraper/sources` | — | viewer |
| `GET` | `/api/scraper/sources/{source_key}` | — | viewer |
| `PUT` | `/api/scraper/sources/{source_key}` | `UpdateSourceConfigRequest { enabled, rate_limit_per_min, ttl_days, config }` | admin |
| `POST` | `/api/scraper/sources/{source_key}/test` | `{ "test_payload": object }` | admin |
| `GET` | `/api/scraper/targets` | (filtres state, source) | viewer |
| `POST` | `/api/scraper/targets/launch-zone` | `LaunchZoneRequest { zone_type, zone_code, filters?, priority? }` | operator |
| `POST` | `/api/scraper/targets/launch-siren-list` | `{ "sirens": str[], "sources": str[] }` | operator |
| `POST` | `/api/scraper/targets/{id}/cancel` | — | operator |
| `POST` | `/api/scraper/targets/{id}/retry` | — | operator |
| `GET` | `/api/scraper/queue-depth` | — | viewer (admin dashboard widget) |

---

## 7. COVERAGE (`/api/coverage/*`)

| Method | Path | Notes |
|---|---|---|
| `GET` | `/api/coverage/matrix` | `?group_by=region_id&filters[axion_offer]=...` |
| `GET` | `/api/coverage/zones/{type}/{code}` | type: region/department/city, code: INSEE |
| `GET` | `/api/coverage/recommend-next-zones` | `?limit=10` (algo "prochaine zone à attaquer") |
| `GET` | `/api/coverage/summary` | KPIs globaux (total/enriched/coverage_pct/by_tier) |
| `POST` | `/api/coverage/refresh-matrix` | Trigger manuel refresh CONCURRENTLY (admin) |
| `GET` | `/api/cities/suggest` | `?q=par&limit=10` (auto-suggest carte) |

---

## 8. GEO DATA (`/api/geo/*`)

| Method | Path | Notes |
|---|---|---|
| `GET` | `/api/geo/regions.geojson` | Servi via cache navigateur 7j |
| `GET` | `/api/geo/departments.geojson` | idem |
| `GET` | `/api/geo/cities.geojson` | idem (~10 Mo) |
| `GET` | `/api/geo/regions/{insee_code}` | Détail région |
| `GET` | `/api/geo/departments/{insee_code}` | Détail département |
| `GET` | `/api/geo/cities/{insee_code}` | Détail ville |

---

## 9. LLM ROUTER (`/api/llm/*`)

| Method | Path | Body | Auth |
|---|---|---|---|
| `GET` | `/api/llm/providers` | — | admin |
| `PUT` | `/api/llm/providers/{provider_key}` | `UpdateProviderRequest { enabled, base_url, config }` | admin |
| `GET` | `/api/llm/use-cases` | — | admin |
| `GET` | `/api/llm/use-cases/{use_case_key}` | — | admin |
| `PUT` | `/api/llm/use-cases/{use_case_key}` | `UpdateUseCaseRequest { primary_provider, primary_model, fallback_chain, max_tokens, temperature, enabled, ab_test_config }` | admin |
| `GET` | `/api/llm/templates` | — | admin |
| `GET` | `/api/llm/templates/{id}` | — | admin |
| `POST` | `/api/llm/templates` | `CreateTemplateRequest { use_case_id, name, system_prompt, user_prompt, variables_spec }` | admin |
| `POST` | `/api/llm/templates/{id}/versions` | `{ system_prompt, user_prompt, variables_spec }` (crée v+1) | admin |
| `POST` | `/api/llm/templates/{id}/activate-version` | `{ "version": int }` | admin |
| `POST` | `/api/llm/test` | `TestPromptRequest { use_case_key, variables, provider_override?, model_override? }` | admin |
| `GET` | `/api/llm/usage` | `?group_by=day&use_case_key=...&from=...&to=...` | admin |
| `GET` | `/api/llm/usage/by-use-case` | top use cases consommateurs | admin |
| `GET` | `/api/llm/usage/by-provider` | total par provider | admin |
| `GET` | `/api/llm/usage/cost-per-enrichment` | calcul coût LLM par entreprise enrichie | admin |
| `GET` | `/api/llm/ab-results/{use_case_key}` | comparaison A vs B | admin |

---

## 10. ROTATIONS (`/api/rotations/*`)

| Method | Path | Notes |
|---|---|---|
| `GET` | `/api/rotations/proxies` | État pool proxies par provider |
| `GET` | `/api/rotations/user-agents` | Distribution + last refresh |
| `POST` | `/api/rotations/user-agents/refresh` | Force refresh pool depuis source externe |
| `GET` | `/api/rotations/linkedin-accounts` | Liste comptes + status + daily_used |
| `POST` | `/api/rotations/linkedin-accounts/{id}/reset-status` | Reset state à `active` après re-login manuel |
| `GET` | `/api/rotations/state` | État par scraper × workspace (cooldowns en cours) |
| `GET` | `/api/rotations/events` | Journal des événements rotation (cooldown, ban_detected, recovered) |

---

## 11. PROXY PROVIDERS (`/api/proxies/*`)

| Method | Path | Notes |
|---|---|---|
| `GET` | `/api/proxies/providers` | Liste providers (Webshare, IPRoyal, Smartproxy, BrightData) |
| `POST` | `/api/proxies/providers` | Ajouter nouveau provider |
| `PUT` | `/api/proxies/providers/{provider_key}` | Update budget, config |
| `DELETE` | `/api/proxies/providers/{provider_key}` | Désactiver (soft) |
| `GET` | `/api/proxies/providers/{provider_key}/proxies` | Liste IPs du provider |
| `POST` | `/api/proxies/providers/{provider_key}/sync` | Sync depuis API provider |
| `POST` | `/api/proxies/providers/{provider_key}/health-check` | Force health check |
| `GET` | `/api/proxies/{id}` | Détail proxy individuel + stats |
| `PUT` | `/api/proxies/{id}` | Update status manuellement (cooldown / disabled / active) |

---

## 12. GDPR (`/api/gdpr/*`)

| Method | Path | Body | Auth |
|---|---|---|---|
| `GET` | `/api/gdpr/requests` | (filtres status, type, deadline) | admin |
| `POST` | `/api/gdpr/requests` | `CreateGdprRequest { request_type, subject_email?, subject_name?, subject_phone?, evidence_url? }` | admin |
| `GET` | `/api/gdpr/requests/{id}` | + affected_entities preview | admin |
| `POST` | `/api/gdpr/requests/{id}/process` | `{ "action": "erase" | "export", "confirmation": str }` | owner |
| `GET` | `/api/gdpr/requests/{id}/export.json` | Export portabilité utilisateur | owner |
| `GET` | `/api/gdpr/processing-log` | Registre RGPD (data_processing_log) | admin |
| `GET` | `/api/gdpr/opt-out` | Liste opt-outs | admin |
| `POST` | `/api/gdpr/opt-out` | `{ "email"?: str, "domain"?: str, "phone_e164"?: str, "reason": str }` | admin |

---

## 13. AUDIT LOGS (`/api/audit-logs/*`)

| Method | Path | Notes |
|---|---|---|
| `GET` | `/api/audit-logs` | (filtres action, entity_type, actor, date) |
| `GET` | `/api/audit-logs/{id}` | Détail action + payload |
| `POST` | `/api/audit-logs/verify-integrity` | Vérifie hash chain depuis t0 |

---

## 14. MONITORING (`/api/monitoring/*`)

| Method | Path | Notes |
|---|---|---|
| `GET` | `/api/monitoring/dashboard-stats` | Dashboard global stats |
| `GET` | `/api/monitoring/anomalies` | Anomalies détectées |
| `POST` | `/api/monitoring/anomalies/{id}/acknowledge` | Acknowledge anomalie |
| `POST` | `/api/monitoring/anomalies/{id}/resolve` | Résoudre anomalie |
| `GET` | `/api/monitoring/metrics/prometheus` | Endpoint Prometheus scrape (auth basique) |
| `GET` | `/api/monitoring/health` | Healthcheck simple (public, no auth) |
| `GET` | `/api/monitoring/health-deep` | Healthcheck profond (DB, Redis, Horizon) |

---

## 15. AI ACT REGISTER (`/api/ai-act/*`)

| Method | Path | Notes |
|---|---|---|
| `GET` | `/api/ai-act/entries` | Liste registre AI Act |
| `POST` | `/api/ai-act/entries` | Créer entry |
| `PUT` | `/api/ai-act/entries/{id}` | Update |
| `DELETE` | `/api/ai-act/entries/{id}` | Soft remove (logged) |

---

## 16. PHASE 2 — 501 NOT IMPLEMENTED (avec types réponse définis)

> Toutes ces routes sont **scaffoldées en V1** : la route existe, le controller renvoie systématiquement HTTP 501 avec un body documentant le shape attendu en Phase 2.

### `/api/campaigns/*`

| Method | Path | Phase 2 expected |
|---|---|---|
| `GET` | `/api/campaigns` | List campaigns (filters status, channel) |
| `POST` | `/api/campaigns` | Create campaign |
| `GET` | `/api/campaigns/{uuid}` | Detail + targets |
| `PUT` | `/api/campaigns/{uuid}` | Update |
| `POST` | `/api/campaigns/{uuid}/launch` | Start scheduling |
| `POST` | `/api/campaigns/{uuid}/pause` | Pause |
| `POST` | `/api/campaigns/{uuid}/duplicate` | Duplicate |
| `GET` | `/api/campaigns/{uuid}/targets` | List targets |
| `POST` | `/api/campaigns/{uuid}/targets` | Add targets bulk |
| `GET` | `/api/campaigns/{uuid}/kpis` | KPI snapshots |

### `/api/cold-email/*`

| Method | Path | Phase 2 expected |
|---|---|---|
| `GET` | `/api/cold-email/campaigns` | List |
| `POST` | `/api/cold-email/campaigns` | Create |
| `GET` | `/api/cold-email/templates` | List templates |
| `POST` | `/api/cold-email/templates` | Create template |
| `GET` | `/api/cold-email/sending-domains` | List domains |
| `POST` | `/api/cold-email/sending-domains` | Add domain |
| `POST` | `/api/cold-email/sending-domains/{id}/verify-dns` | Check SPF/DKIM/DMARC |
| `GET` | `/api/cold-email/smtp-ips` | List IPs |
| `POST` | `/api/cold-email/smtp-ips/{id}/warmup-start` | Start warmup |
| `GET` | `/api/cold-email/sends` | Paginated email_sends |
| `GET` | `/api/cold-email/deliverability` | Stats deliverability |

### `/api/linkedin-outreach/*`

| Method | Path | Phase 2 expected |
|---|---|---|
| `GET` | `/api/linkedin-outreach/campaigns` | List |
| `POST` | `/api/linkedin-outreach/campaigns` | Create |
| `GET` | `/api/linkedin-outreach/templates` | List |
| `POST` | `/api/linkedin-outreach/templates` | Create |
| `GET` | `/api/linkedin-outreach/connection-requests` | List |
| `POST` | `/api/linkedin-outreach/connection-requests` | Send connect |

### `/api/crm/*`

| Method | Path | Phase 2 expected |
|---|---|---|
| `GET` | `/api/crm/pipelines` | List |
| `POST` | `/api/crm/pipelines` | Create |
| `GET` | `/api/crm/pipelines/{id}/board` | Kanban view (stages + deals) |
| `GET` | `/api/crm/deals` | List deals (filters) |
| `POST` | `/api/crm/deals` | Create |
| `PUT` | `/api/crm/deals/{uuid}` | Update |
| `POST` | `/api/crm/deals/{uuid}/move-stage` | Move stage |
| `GET` | `/api/crm/deals/{uuid}/activities` | List activities |
| `POST` | `/api/crm/deals/{uuid}/activities` | Log activity |
| `GET` | `/api/crm/tasks` | My tasks |
| `POST` | `/api/crm/tasks` | Create task |

### `/api/analytics/*`

| Method | Path | Phase 2 expected |
|---|---|---|
| `GET` | `/api/analytics/funnels` | List |
| `POST` | `/api/analytics/funnels` | Create |
| `GET` | `/api/analytics/funnels/{id}/compute` | Compute funnel |
| `GET` | `/api/analytics/cohorts` | List |
| `GET` | `/api/analytics/snapshots` | List daily snapshots |
| `GET` | `/api/analytics/roi` | ROI dashboard |

### Réponse 501 standard

```php
return response()->json([
    'error' => 'phase_2_not_implemented',
    'module' => 'cold_email',
    'message' => 'Ce module sera activé en Phase 2. Voir /docs/phase-2-roadmap.md',
    'expected_shape' => [/* shape attendu en Phase 2 */],
], 501);
```

---

## 17. WEBHOOKS PHASE 2 (scaffold)

| Method | Path | Notes |
|---|---|---|
| `POST` | `/api/webhooks/email-events` | Réceptionne événements (delivered/opened/clicked/bounced/complained) — 501 V1 |
| `POST` | `/api/webhooks/linkedin-events` | Réceptionne événements LinkedIn PB — 501 V1 |

---

## 18. Rate limiting

| Route family | Limit | Throttle key |
|---|---|---|
| `/api/auth/*` | 10 req / min / IP | `axion-crm.auth` |
| `/api/auth/login` | 5 essais / 15 min / IP+email | `axion-crm.login` |
| `/api/companies` GET | 600 req / min / user | `axion-crm.api` |
| `/api/companies` mutations | 60 req / min / user | `axion-crm.api-mutate` |
| `/api/scraper/targets/launch-*` | 10 launches / min / user | `axion-crm.scrape-launch` |
| `/api/llm/test` | 30 / min / user | `axion-crm.llm-test` |
| `/api/coverage/*` | 120 req / min / user | `axion-crm.coverage` |
| `/api/monitoring/health` | unlimited | n/a |

Tous configurables runtime via UI admin (table `rate_limits` Phase 2 scaffold ou `app_settings` config V1).

---

## 19. Versionning API

V1 ne préfixe pas par `/v1` (économie syntaxique). Si Phase 2 introduit breaking changes, on basculera vers `/api/v2/*` en gardant `/api/*` (alias V1) jusqu'au retrait planifié 6 mois plus tard.

---

## 20. Tests d'acceptance API (S1 + S12)

- [ ] 70+ endpoints REST implémentés ou stubbés
- [ ] Toutes les routes Phase 1 renvoient 200/201/204 ou 4xx documenté
- [ ] Toutes les routes Phase 2 renvoient 501 avec body `expected_shape`
- [ ] Pas de route protégée accessible sans cookie Sanctum + CSRF
- [ ] Pas de leak cross-workspace (test fuzzing avec 2 workspaces fictifs)
- [ ] Rate limiting effectif sur les routes critiques
- [ ] Format réponse uniforme (`data`/`meta`/`error`)
- [ ] Documentation OpenAPI 3.1 générée automatiquement (Laravel + `spatie/laravel-data` → `dedoc/scramble` ou équivalent)

---

## 21. Anti-patterns interdits

- ❌ Routes sans typed request/resource (Spatie Data)
- ❌ Routes qui ne logguent pas dans `audit_logs` les actions sensibles
- ❌ Réponses sans format standard `{ "data": ..., "meta": ... }`
- ❌ Suppression hard sans soft delete sur entités historiques (companies, contacts)
- ❌ Lecture de `request()->workspace_id` (toujours via middleware injecté `request()->user()->currentWorkspace`)
- ❌ Routes Phase 2 qui retournent 404 (doivent retourner 501 documenté)

---

## Prochaine étape

→ Lire `15_auth_multitenant_rbac.md` pour Sanctum + 2FA + RLS + Spatie Permission.
