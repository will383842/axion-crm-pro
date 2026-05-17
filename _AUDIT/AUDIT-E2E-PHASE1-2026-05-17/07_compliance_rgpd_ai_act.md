# Phase 7 — Conformité RGPD + AI Act

## RGPD (sur 40 pts)

| Item | Statut | Détail |
|------|--------|--------|
| Base légale documentée art. 6.1.f | 🟡 | Spec/17 traite, code rien (pas de table `data_processing_log` seedée) |
| Opt-out cross-workspace | ✅ | Table `opt_out` globale + DeduplicationService Niveau 6 |
| Droit suppression (art. 17) | ✅ | `GdprErasureService::erase()` transaction multi-tables atomique |
| Portabilité (art. 20) | ✅ | `GdprPortabilityService::export()` JSON chiffré AES-256 |
| Token export TTL | ✅ | 7 jours, hash sha256 stocké |
| DPO email configuré | ✅ | `contact@axion-ia.com` (cf. CONTRIBUTING + ai_act_register) |
| Conservation 90j scraping | 🟡 | `RetentionPurge` artisan command créée mais `scraper_runs.payload >90j` seul (pas anonymisation contacts) |
| Anonymisation IPs > 30j | ❌ | Pas de job dédié |
| Sous-processeurs LLM register | 🟡 | `ai_act_register.metadata.providers` mention Anthropic/Mistral, pas tableau dédié |
| DPIA | ❌ | Section spec/17 mentionnée, **pas de fichier DPIA livré** |

**Score RGPD : 26 / 40**

## AI Act UE 2024/1689 (sur 30 pts)

| Item | Statut | Détail |
|------|--------|--------|
| Table `ai_act_register` créée | ✅ | Migration 000006 |
| `AiActRegisterSeeder` | ✅ | 1 entrée "LLM Router — Classification Axion-IA" |
| risk_class | ✅ | `limited` (approprié scoring B2B intérêt légitime) |
| `purpose` documenté | ✅ | "Classification entreprise → matching offre + score IA maturité" |
| `provider` + `model` | ✅ | "Anthropic + Mistral (fallback)" / "claude-sonnet-4-6 / mistral-large-latest" |
| `impact_assessment` JSON | ✅ | data_categories, no_pii, human_oversight, opt_out_route, mitigations, review_date |
| Transparency notice UI | ❌ | Pas de widget dans CompanyDetailPage (page stub) |
| Profilage automatisé | ✅ | Documenté via `human_oversight: systematic` |

**Score AI Act : 24 / 30**

## OWASP cross-référence (sur 30 pts)

Voir `06_security_forensic.md` — applications mesures vérifiées.

**Score OWASP : 22 / 30**

## Score Phase 7 global : **72 / 100**
