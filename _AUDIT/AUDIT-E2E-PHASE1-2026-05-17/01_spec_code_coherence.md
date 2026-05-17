# Phase 1 — Cohérence Spec ↔ Code

## Synthèse

| Élément spec | Cible | Réalité | Statut |
|--------------|-------|---------|--------|
| Tables DB Phase 1 (spec 03) | 66 | ~60 (9 migrations) | 🟡 91 % |
| Tables Phase 2 scaffold (spec 04) | 35 | 11 (1 migration) | 🔴 31 % |
| Scrapers 14 sources (spec 05) | 14 | 5 Playwright + 5 mocks + 9 sources API côté Laravel | 🟡 70 % |
| LLM use cases (spec 07) | 9 | 9 seedés (`LlmUseCasesSeeder.php`) | 🟢 100 % |
| Waterfall étapes (spec 08) | 10 | 7/10 (1+2+3+8+9+10 implémentées, 4-6 dispatch async, 7 absent) | 🔴 70 % |
| Pages Phase 1 UI (spec 13) | 17 | 19 fichiers, **~10 stubs PageShell seuls** | 🔴 47 % réel |
| Endpoints API (spec 14) | ~121 | 27 controllers → ~80 routes définies | 🟡 66 % |
| Rôles RBAC (spec 15) | 4 | owner/admin/operator/viewer ✅ | 🟢 100 % |
| Métriques Prometheus (spec 16) | 48 | ~10 métriques business documentées | 🔴 21 % |
| SSRF guard (spec 17) | présent | `SsrfGuard.php` + appliqué 5 clients | 🟢 100 % côté PHP |
| Terraform module Hetzner (spec 18) | présent | **absent** | 🔴 0 % |

## Détail Phase 2 scaffold

Migration `000007_create_phase2_scaffold_schema.php` crée 11 tables (campaigns, email_templates,
email_sequences, email_sends, linkedin_accounts, linkedin_messages, pipeline_stages, deals,
activities, analytics_daily_rollups). **Spec 04 attend ≈35 tables.** Manquent : email_events,
unsubscribes, dnc_lists, linkedin_invitations, linkedin_sequences, crm_pipelines (multi),
crm_lost_reasons, analytics_funnels, analytics_cohorts, etc.

## Score Phase 1 : **65 / 100**

Bonnes correspondances sur la couche transversale (LLM use cases, RBAC, SSRF, contrats). Lacune
sur le scaffold Phase 2 (31 %), pages UI réellement implémentées (47 %), et infra Terraform (0 %).
