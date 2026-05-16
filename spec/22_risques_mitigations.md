# 22 — Top 15 risques + mitigations

> **Format :** Risque | Probabilité | Impact | Mitigation primaire | Mitigation backup | Détection.

---

## R1 — Ban IP massif simultané plusieurs sources

- **Probabilité :** Élevée (au moins 1×/mois)
- **Impact :** Sévère (plusieurs sources tombent en même temps)
- **Mitigation primaire :** Architecture proxies pluggable runtime (`09_proxy_pluggable_system.md`) + rotation User-Agents 50+ + stealth Playwright + cool-down auto par proxy après échec
- **Mitigation backup :** Activation Smartproxy/BrightData via UI admin sans redéploiement
- **Détection :** Métrique `axion_crm_proxy_failures_total` + alerte `ProxyProviderDegraded`

## R2 — Google/Bing changent défenses anti-scraping

- **Probabilité :** Élevée (1-2×/an Google fait évoluer ses captchas)
- **Impact :** Critique (Google Search Wrapper down → URLs LinkedIn manquantes)
- **Mitigation primaire :** Rotation 3 moteurs (`10_rotations_universelles.md` §4). Si Google captcha-bloqué → Bing → DuckDuckGo
- **Mitigation backup :** Activation 2captcha solving (~20 $/mois) + augmentation cooldown
- **Détection :** Alerte `AllSearchEnginesBlocked` Prometheus

## R3 — Taux succès Google Search Wrapper insuffisant (< 50 %)

- **Probabilité :** Moyenne
- **Impact :** Modéré (URLs LinkedIn manquantes pour 50 %+ contacts)
- **Mitigation primaire :** Scoring LLM `linkedin_url_matching_scoring` éviter faux positifs + 3 moteurs
- **Mitigation backup :** Phase 2 premium (PhantomBuster, Unipile API ~70 $/mo)
- **Détection :** KPI `quality_score = 'complete'` taux < cible 50 %

## R4 — annuaire-entreprises.data.gouv.fr change structure

- **Probabilité :** Moyenne (refonte DGFiP/INSEE possible chaque année)
- **Impact :** Sévère (perte dirigeants légaux + bilans)
- **Mitigation primaire :** API JSON `recherche-entreprises.api.gouv.fr` (officielle, plus stable que scraping HTML)
- **Mitigation backup :** Fallbacks ordonnés Infogreffe → Societe.com. Sélecteurs CSS en RUNTIME-CONFIG (modifiables sans déploiement)
- **Détection :** Test E2E quotidien sur 10 SIRENs connus. Alerte si extraction < 80 %.

## R5 — INSEE rate limits insuffisants pour volume

- **Probabilité :** Moyenne (à 200k/mois volume)
- **Impact :** Modéré (lenteur découverte nouvelles entreprises)
- **Mitigation primaire :** Token authentifié = 500 req/min (vs 30 anonyme). Backoff intelligent + cache TTL 180j
- **Mitigation backup :** Demande de quota augmenté (formulaire INSEE), reachable à 1000 req/min en justifiant usage
- **Détection :** Métrique `axion_crm_scraper_runs_total{source="insee",status="rate_limited"}`

## R6 — Coût LLM explose au scale

- **Probabilité :** Moyenne (sans optimization)
- **Impact :** Économique sévère (peut tripler le coût mensuel)
- **Mitigation primaire :** LLM Router routing intelligent (Mistral Small > Haiku 4.5 > Sonnet 4.6) + cache Redis TTL agressif + cost cap workspace (`workspaces.cost_cap_eur` 500 €/mo) + kill-switch automatique
- **Mitigation backup :** Bascule Ollama local (GPU GEX44 +185 €/mo mais coût LLM → 0). Disable use cases non critiques.
- **Détection :** Alerte `LLMCostSpike` Prometheus (cost > 2× baseline 7j)

## R7 — PostgreSQL bottleneck à 1M+ rows

- **Probabilité :** Élevée à terme (an 1)
- **Impact :** Sévère (lenteur UI, scraping ralenti)
- **Mitigation primaire :** Partitionnement pg_partman par mois sur tables hot (`scraper_runs`, `llm_usage`, `audit_logs`, `proxy_usage_log`, `email_sends`). Indexes composites obligatoires `(workspace_id, ...)`. Materialized view `coverage_matrix_cells` refresh hourly.
- **Mitigation backup :** Scale-up CCX13 → CCX23 (4 vCPU dédié) + read replica + pgbouncer transaction mode
- **Détection :** Métrique `axion_crm_db_query_duration_ms` p95 > 200 ms

## R8 — IGN change format AdminExpress

- **Probabilité :** Faible (annuel, mais format stable depuis 5 ans)
- **Impact :** Sévère (carte cassée jusqu'à update)
- **Mitigation primaire :** Versions IGN stockées localement (pas téléchargées runtime). Mise à jour annuelle planifiée février-mars.
- **Mitigation backup :** Source alternative OpenStreetMap (Geofabrik). Données légèrement moins précises mais OK.
- **Détection :** Job annuel `app:check-ign-version` + rappel calendar Will

## R9 — OpenFreeMap ferme ou rate-limite

- **Probabilité :** Faible (modèle service public)
- **Impact :** Modéré (carte sans tuiles fond, mais polygones OK)
- **Mitigation primaire :** Self-hosting tile server (open-source tileserver-gl) sur observability server (+1 GB disk only)
- **Mitigation backup :** Fallback Mapbox free tier (50 000 map loads/mois gratuits)
- **Détection :** Probe `https://tiles.openfreemap.org` uptime monitor

## R10 — Plainte CNIL pour scraping massif

- **Probabilité :** Faible (B2B intérêt légitime documenté)
- **Impact :** Critique (amende potentielle, interdiction)
- **Mitigation primaire :** Base légale documentée (article 6.1.f) + opt-out cross-workspace + emails pros uniquement (refus gmail/hotmail sauf publication pro) + AI Act register + DPO actif (`contact@axion-ia.com`)
- **Mitigation backup :** Avocat RGPD identifié + procédure incident < 72h CNIL
- **Détection :** Veille presse + monitoring email DPO

## R11 — AI Act se durcit (post-2025)

- **Probabilité :** Élevée (régulateur très actif)
- **Impact :** Modéré (mise à jour conformité requise)
- **Mitigation primaire :** Table `ai_act_register` documentée pour tous les use cases LLM. Transparency notice sur UI fiche entreprise. Override humain documenté.
- **Mitigation backup :** Audit annuel par avocat IA-spécialisé
- **Détection :** Veille réglementaire trimestrielle

## R12 — Hetzner suspend pour abuse

- **Probabilité :** Très faible (compte CRM-Pro dédié, scraping documenté légal)
- **Impact :** Critique (downtime + perte data si pas backup)
- **Mitigation primaire :** Conformité TOS Hetzner stricte. Pas de scraping de Hetzner lui-même. Volume bandwidth sortant raisonnable.
- **Mitigation backup :** DR Backblaze B2 + provisioning Helsinki ou GCP Frankfurt
- **Détection :** Email Hetzner avant suspension habituellement 7-14j

## R13 — Bug data corruption multi-tenant

- **Probabilité :** Faible (RLS + tests E2E)
- **Impact :** Critique (fuite cross-workspace = breach)
- **Mitigation primaire :** RLS PostgreSQL double-sécurité (vs filtre applicatif). Tests E2E "user A cannot access workspace B" obligatoires.
- **Mitigation backup :** Audit log hash chain permet de tracer cross-workspace access
- **Détection :** Test E2E CI bloque merge si fail. Alerte si SELECT cross-workspace détecté.

## R14 — Dev solo (Will) s'absente longue durée

- **Probabilité :** Moyenne (vacances, maladie)
- **Impact :** Sévère (plateforme = pilier business)
- **Mitigation primaire :** Documentation exhaustive (cette spec + runbooks `18_deploiement_hetzner.md` § 8) + 24 prompts Claude Code prêts (`23_interfaces_phase2_execution_pack.md` § B.4). Freelance peut reprendre < 2 jours sur n'importe quel module.
- **Mitigation backup :** Mode dégradé : scraping continu auto + alertes au DPO/dev backup. Pas de feature dev pendant absence.
- **Détection :** Calendar Will + procédure de "going AFK" (notification équipe + freeze deploys)

## R15 — Pic volume non anticipé (DDoS, viralité Axion-IA, etc.)

- **Probabilité :** Faible
- **Impact :** Sévère (saturation app/workers)
- **Mitigation primaire :** Cloudflare DDoS protection (gratuit). Rate limiting Caddy + Laravel.
- **Mitigation backup :** Auto-scale Coolify (+50% Horizon processes via `horizon:scale`). Mode dégradé : disable nouveaux scraping discoveries, focus enrichment queue.
- **Détection :** Alerte `RedisQueueBacklog` > 10k. Métrique http_requests_total spike.

---

## Risques additionnels (R16-R20, secondaires)

### R16 — Réputation IPs cold email (Phase 2)

- **Probabilité :** Élevée Phase 2 (cold email à risque)
- **Impact :** Sévère Phase 2 (deliverability)
- **Mitigation :** 3-5 domaines secondaires + IPs dédiées Phase 2 + warmup 30j progressive + monitoring Postmark/Sender Score
- **Détection :** Dashboard 5 (Email validation) + métrique `axion_crm_email_send_inbox_rate`

### R17 — Surcharge DB pendant refresh materialized view

- **Probabilité :** Moyenne
- **Impact :** Modéré (UI lente pendant 1-2 min/heure)
- **Mitigation :** `REFRESH MATERIALIZED VIEW CONCURRENTLY` (lock minimal) + heures creuses (`5 * * * *` = minute 5 chaque heure, pas pic d'activité utilisateur)

### R18 — Cookies/session compromised (XSS)

- **Probabilité :** Faible
- **Impact :** Critique (vol session)
- **Mitigation :** Cookies HttpOnly + Secure + SameSite=lax + CSP strict + sanitization toute output user-controlled

### R19 — Captcha solving service (2captcha) down

- **Probabilité :** Faible
- **Impact :** Modéré
- **Mitigation :** Service optionnel (skip si pas dispo). Bascule sur autre service (Anti-Captcha, Capsolver) via interface pluggable.

### R20 — Conflit dépendances majeur (ex: PHP 8.3 → 8.5)

- **Probabilité :** Moyenne (tous les 2 ans environ)
- **Impact :** Modéré (downtime upgrade)
- **Mitigation :** Staging d'abord, rolling update. Backups pgbackrest avant.

---

## Plan de réponse aux incidents

### Severity matrix

| Severity | Description | Response time | Comms |
|----------|-------------|---------------|-------|
| **P0** | Site down / breach confirmé | < 15 min | Slack #prod + Telegram + email DPO |
| **P1** | Feature majeure cassée / dégradation visible | < 1 h | Slack #prod + Telegram |
| **P2** | Bug non-critique / dégradation invisible utilisateur | < 24 h | Slack #alerts |
| **P3** | Cosmétique / suggestion amélioration | < 7 j | Issue GitHub |

### Communication template P0

```
🚨 INCIDENT P0 — Axion CRM Pro

Heure début : 2026-05-16 14:32 UTC
Heure fin   : (en cours)
Impact      : Site indisponible 100%
Cause hypothèse : ...
Action en cours : ...
Prochaine update : dans 15 min

Status page : https://status.axion-pro.com (Uptime Kuma public)
```

---

## Lecture suivante

→ `23_interfaces_phase2_execution_pack.md` (interfaces Phase 2 + Code Gen Roadmap + Tests AC + Seeders + 12 prompts CC).
