# 21 — Coûts mensuels + roadmap 12 semaines

> **Budget Phase 1 cible :** ~265 €/mois (cible prompt v6) — réalité 220-340 €/mois selon proxies + LLM volume.
> **Économie vs version PhantomBuster :** ~360 €/mois.
> **Roadmap :** 12 semaines dev, critères "done" mesurables par semaine.

---

## §1 — Tableau coûts mensuels détaillé Phase 1

### Compte Hetzner CRM-Pro dédié

| Serveur | Type | vCPU/RAM/Disk | € HT/mois | Activé S |
|---------|------|---------------|------------|----------|
| edge | CAX21 ARM | 2/4/40 | 5,59 | S1 |
| app | CPX31 | 4/8/160 | 15,69 | S1 |
| data | CCX13 dédié | 2/8/80 NVMe | 15,79 | S1 |
| worker-1 | CPX31 | 4/8/160 | 15,69 | S1 |
| worker-2 | CPX31 | 4/8/160 | 15,69 | S6 |
| observability | CPX21 | 3/4/80 | 9,99 | S12 |
| staging | CCX13 | 2/8/80 | 15,79 | S2 |
| volume bloc DB | — | +100 GB | 4,76 | S3 |
| IPv4 add (×3) | — | — | 1,80 | S1 |
| **Sous-total Hetzner core** | | | **~100 €** | |

### GPU optionnel (S10+)

| Serveur | Type | Note | € HT/mois |
|---------|------|------|------------|
| gpu-ollama | GEX44 RTX4000 SFF | Si LLM API > 60€/mois | 184,90 |

### Services tiers (P0 audit v1.1 — budget révisé honnête)

> **v1.0 sous-estimait dramatiquement** les coûts proxies résidentiels et LLM. Réalité audit :
> - Proxies résidentiels à 200 k entreprises/mois : 1 200 GB × 5-8 $/GB = **5 000-8 500 $/mois** sans mitigation.
> - LLM 1.8 M appels/mois : ~770 €/mois en v1.0 (vs 60 € annoncés).
>
> **Stratégies de mitigation appliquées v1.1** (cf. `09_proxy_pluggable_system.md` + `07_llm_router.md`) :
> 1. Datacenter Webshare dominant pour 60-70 % du volume (Direction Finder, sites web, Pages Jaunes, BAN, etc.)
> 2. Résidentiel IPRoyal ciblé uniquement Google Search + Google Maps + Crunchbase
> 3. Sampling 80/20 : top 50 k entreprises max au démarrage (vs 200 k discoverables)
> 4. LinkedIn matching : règles déterministes avant LLM (économie 320 €/mo)
> 5. Use cases mergés (`classify_company_axion` économie 80 €/mo)

| Service | Démarrage | S12 stable | Note v1.1 |
|---------|-----------|------------|-----------|
| Domaines (axion-pro.com + 3-5 secondaires Phase 2) | 0,83 €/mo | 5 €/mo | Namecheap/OVH |
| Cloudflare Free | 0 | 0 | DNS, WAF base, SSL |
| Backblaze B2 (~50 GB backups réplication) | 0,21 €/mo | 0,50 €/mo | |
| Hetzner OBS (~50 GB) | 0,24 €/mo | 0,40 €/mo | |
| Proxies Webshare (datacenter, ~50 IPs, ~400 GB/mo) | 10 $/mo | 25 $/mo | Volume dominant |
| Proxies IPRoyal (résidentiel, ~150-300 GB/mo selon sampling) | 50 $/mo | **300-500 $/mo** | **Réalité honnête** |
| **2captcha (OBLIGATOIRE P0 audit, pas optionnel)** | 20 $/mo | **50 $/mo** | Indispensable Google Search |
| LLM APIs (Claude Haiku + Mistral, post-optimisations P0 audit) | 80 €/mo | **250 €/mo** | Volume + use cases mergés |
| GlitchTip + Loki + Tempo (self-hosted) | 0 | 0 | inclus observability server |
| **Sous-total services tiers révisé v1.1** | **~165 €** | **~635 €** | |

### Total Phase 1 sans GPU — révisé v1.1

| Mois | Configuration | Coût € HT/mois |
|------|---------------|----------------|
| Mois 1 (S1-S4) | core Hetzner + Webshare DC + LLM léger | ~250 € |
| Mois 2 (S5-S8) | + worker-2 + IPRoyal résidentiel + LLM en montée | ~500 € |
| Mois 3 (S9-S12) | + observability + LLM full optimisé + 2captcha + sampling | **~750 €** |

> **Cible S12 stable honnête v1.1 : ~750 €/mo** (vs 265 € v1.0 irréaliste).
> Si budget contraint à 500 €/mo strict → activer sampling 80/20 plus agressif (top 30 k entreprises) + cap proxies résidentiels manuel.

### Total Phase 1 + GPU Ollama

~450 €/mois (si activé S10+ pour réduire LLM API).

### Coût par entreprise enrichie (révisé v1.1)

Avec sampling 80/20 (top 50 k entreprises max vs 200 k discoverables) :

```
750 €/mois ÷ 50 000 enrichissements ciblés/mois = 0.015 € / entreprise
```

→ **~0,015 € / entreprise enrichie complète** (réalité v1.1, vs 0,0015 € v1.0 sous-estimé d'un facteur 10).

### Économie vs version PhantomBuster + Sales Navigator

| Poste | Avec PhantomBuster | Version actuelle | Économie |
|-------|---------------------|------------------|----------|
| PhantomBuster | 200 €/mo | 0 (Google Search Wrapper) | -200 |
| Sales Navigator × 3 | 237 €/mo | 0 (Direction Finder) | -237 |
| Pappers | 99 €/mo | 0 (annuaire-entreprises) | -99 |
| Apollo/Lusha | 100 €/mo | 0 | -100 |
| **Économie totale** | | | **~636 €/mo** |

(Le prompt mentionne ~360 € d'économie ; chiffre conservateur si on compte uniquement PhantomBuster + Sales Navigator + Pappers basics. Réalité plus proche de 600 € avec stack complète "premium".)

---

## §2 — Roadmap 12 semaines

### Vue d'ensemble

```
S1   ── Setup infra Hetzner + Laravel/React skeleton + DB COMPLETE + auth + multi-tenant
S2   ── Patterns techniques (anti-ban, rotation, dedup STRICT 6 niveaux, email finder, validation) + LLM Router
S3   ── INSEE + annuaire-entreprises + BODACC + Coverage Matrix
S4   ── Google Maps + Pages Jaunes
S5   ── Sites web (crawl + extraction emails + équipe + sociaux + mots-clés)
S6   ── Google Search Wrapper + Direction Finder ETI/Grandes + France Travail + écoles MESRI
S7   ── Crunchbase + Infogreffe + Societe.com + BAN + social light
S8   ── Email finder + validation SMTP cascade complète
S9   ── Carte France interactive (3 modes)
S10  ── Classification LLM (maturité + offres + tags + mots-clés), proxy providers UI
S11  ── Scaffold complet UI Phase 2
S12  ── Monitoring complet, anomaly detection, polish UI, doc, tests E2E
```

### S1 — Setup foundation

**Inputs requis :** compte Hetzner CRM-Pro créé, clé SSH générée, accès Cloudflare nouveau compte, domaine `axion-pro.com` acheté.

**Done quand :**
- ✅ 5 serveurs Hetzner provisionnés (edge, app, data, worker-1, staging)
- ✅ vSwitch 4011 actif
- ✅ Coolify v4 installé sur app
- ✅ DNS `crm.axion-pro.com` + `staging.axion-pro.com` pointent vers IPs Hetzner
- ✅ Postgres 16 + extensions + Redis 7 fonctionnels sur data
- ✅ Repo `axion-crm-pro` créé GitHub
- ✅ Premier `docker-compose up` Laravel hello world OK
- ✅ Migrations toutes les 63 tables Phase 1 exécutent (CREATE TABLE + RLS + partman)
- ✅ Migrations 35 tables Phase 2 scaffold exécutent
- ✅ Seed user owner + workspace `axion-ia` + 4 rôles RBAC
- ✅ Auth Sanctum SPA + 2FA TOTP + magic link fonctionnels
- ✅ Page login + dashboard skeleton React déployée
- ✅ CI GitHub Actions verte
- ✅ Smoke test : `curl https://crm.axion-pro.com/up` → 200

**Effort estimé :** 5 jours dev senior + 8 jours Claude Code.

### S2 — Patterns techniques

**Done quand :**
- ✅ `DeduplicationService` 6 niveaux codé + tests
- ✅ `ProxyProvider` interface + Webshare + IPRoyal implémentations
- ✅ `ProxyRouter` intelligent + health checks
- ✅ Pool 50+ User-Agents seedé + `UserAgentSelector`
- ✅ `LLMClient` (router) + 5 providers (Anthropic, OpenAI, Mistral, OpenRouter, Ollama stub)
- ✅ `PromptRenderer` Twig + versions
- ✅ 11 use cases LLM seedés + prompts v1
- ✅ Cache LLM Redis fonctionnel
- ✅ Cost tracking actif (`llm_usage` insertions)
- ✅ Anti-ban patterns Playwright (stealth + cookies + viewport coherent)
- ✅ Tests unitaires patterns email (15+ variantes)
- ✅ Cascade SMTP validation N1→N5 fonctionnelle (sans IP dédiée encore)
- ✅ IPs dédiées validation Hetzner allouées + rDNS

**Effort estimé :** 7 jours dev + 12 jours Claude Code.

### S3 — Sources officielles + Coverage

**Done quand :**
- ✅ Scraper INSEE Sirene v3 fonctionnel (OAuth token mgmt)
- ✅ Scraper annuaire-entreprises (API JSON principal) + tests
- ✅ Scraper BODACC API + signal detection
- ✅ `EnrichmentRun` waterfall state machine (étapes 1 + 2 + 9 actives)
- ✅ Materialized view `coverage_matrix_cells` + refresh hourly pg_cron
- ✅ Algo "prochaine zone" + `ZoneRotator`
- ✅ Cooldown 24h cellule appliqué
- ✅ Smoke : enrichir 100 SIRENs IDF → 100 fiches en `companies` avec quality_score = 'basic'
- ✅ Dashboard Phase 1 KPIs basiques (entreprises totales, distribution taille)

**Effort estimé :** 7 jours.

### S4 — Google Maps + Pages Jaunes

**Done quand :**
- ✅ Worker Node Playwright `worker-google-maps` (concurrence 4)
- ✅ Worker Node Playwright `worker-pages-jaunes` (concurrence 3)
- ✅ Stealth + UA rotation + proxy IPRoyal résidentiel fonctionnels
- ✅ Bridge Redis Laravel ↔ Node opérationnel (BullMQ queue)
- ✅ Endpoint interne `/internal/scraper-result` actif
- ✅ Captcha detection + bascule auto
- ✅ Pagination sans limite + checkpoints
- ✅ Smoke : 50 entreprises → tél + site web récupérés (>= 70% succès)
- ✅ Téléphones normalisés E.164 + dedup
- ✅ UI page "Scraper Runs" + drill-down

**Effort estimé :** 8 jours.

### S5 — Sites web (extraction emails)

**Done quand :**
- ✅ Worker `worker-sites-web` (concurrence 6)
- ✅ Crawl 2-3 niveaux profondeur sur URLs cibles 11 paths
- ✅ Extraction emails exhaustive (HTML + mailto + obfusqués `[at]` `(at)` `&#64;` + JS-rendered)
- ✅ Classification 4 catégories (nominative/role_based/generic/no_reply)
- ✅ Détection pattern email (15+ patterns) + INSERT `email_patterns`
- ✅ Extraction équipe (structured CSS + fallback LLM use case)
- ✅ Extraction social handles
- ✅ Extraction mots-clés stratégiques
- ✅ Smoke : 30 sites → patterns détectés pour 80% + ≥3 emails/site moyenne
- ✅ Étape 4 waterfall active

**Effort estimé :** 8 jours.

### S6 — Google Search Wrapper + Direction Finder + France Travail + MESRI (v1.1 durci)

**Done quand :**
- ✅ Worker `worker-google-search` 3 moteurs (Google/Bing/DuckDuckGo) rotation
- ✅ **Détection captcha v2 + v3 invisible + "unusual traffic" + Cloudflare challenge (P0 audit v1.1)**
- ✅ **2captcha intégré OBLIGATOIRE pour captcha v2 (P0 audit v1.1)**
- ✅ **Fingerprinting Canvas/WebGL/Audio randomization (P0 audit v1.1)**
- ✅ **Cookie warehouse persistance par sticky session 30 min (P0 audit v1.1)**
- ✅ Cool-down 60 min sur captcha v3 / unusual_traffic
- ✅ Scoring matching LLM **règles déterministes d'abord, LLM zone grise uniquement (P0 audit v1.1)**
- ✅ Worker `worker-direction-finder` (concurrence 2)
- ✅ **Datacenter Webshare DOMINANT pour pages corporate (P0 audit v1.1, économie 700 €/mo proxies)**
- ✅ 4 sources DF (corporate pages 25 URLs + presse + rapport annuel PDF + Google Search étendu)
- ✅ **Cap download PDF 10 MB (P0 audit v1.1)**
- ✅ **Fallback "no_directory_page" + needs_manual_review (P1 audit v1.1)**
- ✅ Cache `corporate_pages_crawled` TTL 30j
- ✅ pdf-parse fonctionnel pour rapports annuels
- ✅ Worker `worker-france-travail` API (signal hiring_surge)
- ✅ Scraper MESRI/ONISEP écoles (table `schools` seedée ~3500 entités)
- ✅ Smoke : 10 ETI testées → ≥ 3 C-level trouvés moyenne (post-POC #3 validation)

**Effort estimé :** 12 jours (le plus chargé).

### S7 — Crunchbase + Infogreffe + Societe.com + BAN + Social

**Done quand :**
- ✅ Scraper Crunchbase (Google → site Crunchbase, fundraising)
- ✅ Scrapers fallback Infogreffe + Societe.com
- ✅ Géocodage BAN sur toutes entreprises (étape 8 waterfall)
- ✅ Scraper social light (handles)
- ✅ Coverage Matrix se peuple correctement
- ✅ Carte France interactive premier déploiement (S9 sera version perfectionnée)

**Effort estimé :** 8 jours.

### S8 — Email Finder + Validation SMTP

**Done quand :**
- ✅ `EmailFinderService` complet (candidats + cache + SMTP cascade)
- ✅ Catch-all detection + cache 7j
- ✅ Disposable list mensuelle
- ✅ Scoring 0-100 final
- ✅ TTL 30j fonctionnel
- ✅ IPs dédiées validation (rDNS configurés)
- ✅ Job hourly check blacklists (Spamhaus, Barracuda, SORBS)
- ✅ Smoke : 100 contacts → 60%+ ont email validé score ≥ 70
- ✅ Étape 7 waterfall active

**Effort estimé :** 8 jours.

### S9 — Carte France perfectionnée

**Done quand :**
- ✅ MapLibre GL JS v4 intégré
- ✅ Tiles OpenFreeMap + IGN AdminExpress simplification mapshaper
- ✅ MVT generated par tippecanoe + servies par Caddy
- ✅ 3 modes : Visualisation choropleth + Recherche auto-suggest + Action clic zone
- ✅ Endpoint `/api/coverage` + cache 60s
- ✅ Lazy-load MapLibre
- ✅ Performance : <50 KB transfer initial, <2s TTI sur 4G
- ✅ Composant React `<FranceCoverageMap />` testé (Vitest + Playwright E2E)

**Effort estimé :** 7 jours.

### S10 — Classification LLM + Proxy UI

**Done quand :**
- ✅ Étape 10 waterfall active (classification automatique)
- ✅ Use cases LLM `ia_maturity_scoring`, `axion_offer_match`, `auto_tag_generation`, `extract_strategic_keywords` opérationnels
- ✅ Auto-tag rules DSL appliqué post-classification
- ✅ UI admin "LLM Router" complète (Providers/UseCases/Prompts/Usage tabs)
- ✅ A/B testing fonctionnel
- ✅ UI admin "Proxy Providers" complète (add/edit/test/disable)
- ✅ Dashboard "coût par enrichissement" (p50/p95/p99)

**Effort estimé :** 8 jours.

### S11 — Scaffold UI Phase 2

**Done quand :**
- ✅ 5 pages Phase 2 stub (Campaigns, Cold Email, LinkedIn, CRM, Analytics) avec wireframes "bientôt disponible"
- ✅ Tables DB Phase 2 (35) toutes créées + RLS
- ✅ Triggers Phase 2 (unsubscribe→opt_out, bounce→opt_out) créés (firent jamais Phase 1)
- ✅ Routes API Phase 2 → 501 Not Implemented avec types Spatie Data
- ✅ Migration order doc complète

**Effort estimé :** 5 jours.

### S12 — Monitoring + Polish + E2E + Pentest (P0 audit v1.1)

**Done quand :**
- ✅ Observability stack déployé (Prometheus + Grafana + Loki + Tempo + GlitchTip + Uptime Kuma)
- ✅ 10 dashboards Grafana opérationnels
- ✅ Alertmanager rules + Slack/Telegram routing
- ✅ Anomaly detection statistique active
- ✅ Tests E2E Playwright 50+ scénarios (auth, CRUD, scraping, RGPD)
- ✅ Tests load (k6) : 100 req/s API tient sans dégradation
- ✅ Documentation finale (OpenAPI auto-doc, runbooks, prompts CC pour future maintenance)
- ✅ **Pentest interne OBLIGATOIRE avant promotion prod (P0 audit v1.1)** :
  - Burp Suite Community (passive scan + manual session testing)
  - OWASP ZAP (active scan against staging)
  - Nmap (network exposure check)
  - Manual : tests SSRF (cf. `17_rgpd_aiact_owasp.md` § A10), prompt injection (cf. `07_llm_router.md` § 4)
  - **Conclusion attendue : aucune vulnérabilité High/Critical**
- ✅ **DPIA produite et signée DPO (P0 audit v1.1)** — cf. `17_rgpd_aiact_owasp.md` § DPIA
- ✅ **DPA sous-processeurs LLM signés** (Anthropic, Mistral minimum) — cf. `17_rgpd_aiact_owasp.md` § Sous-processeurs
- ✅ Promotion staging → prod
- ✅ Cloudflare orange clouds + HSTS preload 12 mois
- ✅ DNSSEC actif
- ✅ Smoke prod : tous les scénarios métier critiques OK

**Effort estimé révisé v1.1 :** **10 jours** (vs 8 v1.0 ; +1j pentest interne, +1j DPIA + DPA).

### Récap effort total — révisé v1.1

| Semaine | Jours dev | Jours Claude Code | Note |
|---------|-----------|--------------------|------|
| S1 | 5 | 8 | Setup |
| S2 | 7 | 12 | Patterns techniques |
| S3 | 7 | 10 | Sources officielles |
| S4 | 8 | 10 | Google Maps + PJ |
| S5 | 8 | 12 | Sites web |
| S6 | 12 | 16 | Google Search + Direction Finder |
| S7 | 8 | 10 | Fallbacks + BAN |
| S8 | 8 | 10 | Email finder + SMTP |
| S9 | 7 | 9 | Carte |
| S10 | 8 | 10 | Classification + Proxy UI |
| S11 | 5 | 7 | Scaffold Phase 2 |
| S12 | **10** | 12 | Monitoring + Polish + **Pentest + DPIA + DPA** |
| **+5 POCs avant code** | **16 j** | — | parallélisables 2 sem (cf. AUDIT_v1 § 13) |
| **Total honnête v1.1** | **109 jours dev** | **126 jours CC** | |

Avec Claude Code en autopilote + dev senior (Will) en review/orchestration : **~14-16 semaines réalistes** (vs 12 v1.0).

---

## §3 — Critères "GO/NO-GO" fin S12

✅ **GO** si (critères révisés v1.1 honnêtes) :

- ≥ 50 000 fiches 🟢 (quality_complete) dans le workspace `axion-ia` (cible inchangée)
- Throughput soutenu ≥ 7 000 entreprises enrichies/jour avec sampling 80/20
- Latence enrichissement P95 < 30s (TPE/PME), < 90s (ETI/Grandes)
- Cost LLM mensuel ≤ **300 €** (vs 60 v1.0 — honnête)
- Cost total mensuel ≤ **800 €** (vs 280 v1.0 — honnête)
- 0 incident sécu P0/P1
- 0 plainte RGPD non traitée
- Audit hash chain valide
- DR drill effectué avec RTO < 4h
- **Pentest interne sans High/Critical (P0 audit v1.1)**
- **DPIA signée + DPA Anthropic/Mistral signés (P0 audit v1.1)**
- **5 POCs validés en pré-S1 (P0 audit v1.1)**

❌ **NO-GO** déclenche correctif Sprint 13 (1-2 semaines additionnelles).

---

## §4 — Phase 2 (post-S12, à planifier ultérieurement)

Phase 2 = activation cold email + LinkedIn outreach + CRM. À planifier en S13-S24 (3 mois supplémentaires) une fois Phase 1 validée.

Pré-requis Phase 2 :
- Achats domaines secondaires (3-5) pour cold email
- Setup SMTP IPs dédiées + warmup 30 jours
- Décision : self-hosted SMTP (Postfix/Postmark) vs API (AWS SES/Mailgun/Postmark)
- Compliance avocat (RGPD opt-in vs opt-out B2B documenté)

---

## Lecture suivante

→ `22_risques_mitigations.md` (15 risques tech/légal/ops avec mitigation).
