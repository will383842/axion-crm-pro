# 01 — Thinking architecte, executive summary, naming

> **Préambule.** Ce fichier est le seul à inclure du raisonnement non-prescriptif. Les 22 fichiers suivants sont 100 % prescriptifs. Lis ceci en premier pour comprendre *pourquoi* la spec est faite ainsi.

---

## Vision long terme

Axion CRM Pro est conçue comme une **plateforme globale de prospection B2B de bout en bout, 100 % automatisée et pilotable depuis une console centralisée unique**.

Fonctionnalités présentes et futures, toutes accessibles depuis la même console :

- ✅ **Phase 1 (présente spec, implémentée intégralement)**
  - Scraping multi-sources 14 sources gratuites
  - Enrichissement waterfall 10 étapes
  - Email finder + validation SMTP cascade
  - Récupération URLs LinkedIn via Google Search Wrapper
  - Direction Finder (ETI/Grandes)
  - Classification automatique IA (maturité IA + offres Axion-IA + tags + scoring qualité fiche)
  - Coverage Matrix + carte interactive France
  - Anti-doublon strict 6 niveaux
  - Multi-tenant + RBAC + audit hash chain
  - Monitoring complet

- 🟡 **Phase 2 (scaffold DB + UI prêtes, logique vide)**
  - Cold Email de masse personnalisé IA — *finalité business du projet*
  - LinkedIn Outreach automatisé
  - CRM pipeline complet (deals, activités, tâches)
  - Analyses avancées + ROI
  - Orchestrateur de campagnes multi-canal

**Règle fondatrice non négociable :** aucune action utilisateur ne doit jamais nécessiter SSH, modification de code, outil tiers ou intervention manuelle technique. Toute la plateforme est pilotée depuis la console admin.

---

## Chain-of-thought architecte — 8 risques majeurs

### Risque #1 — Bannissement IP massif sur les sources de scraping
**Probabilité :** Élevée. **Impact :** Critique (la plateforme s'arrête).
**Analyse :** Toute source non-API (Google Maps, Pages Jaunes, sites web, Google Search) bannit les IPs qui dépassent ses seuils non-publiés. Un seul ban Google sur l'IP de sortie peut tuer le Google Search Wrapper.
**Décision :** Architecture proxies pluggable runtime, rotation User-Agents pool 50+, fingerprinting playwright-extra stealth, weighted round-robin avec auto-disable proxies qui se dégradent.

### Risque #2 — annuaire-entreprises.data.gouv.fr change structure HTML
**Probabilité :** Moyenne (refonte DGFiP/INSEE possible chaque année). **Impact :** Sévère (source légale principale).
**Analyse :** Pas de garantie de stabilité HTML. Si la source change demain, on perd les dirigeants légaux + bilans.
**Décision :** Fallbacks ordonnés (1. annuaire-entreprises → 2. Infogreffe → 3. Societe.com → 4. fallback API si dispo). Tests E2E quotidiens des sélecteurs CSS. Alerte Slack si taux extraction < 80 % sur 1h.

### Risque #3 — Coût LLM explose au volume cible 200k entreprises/mois
**Probabilité :** Moyenne. **Impact :** Économique.
**Analyse :** À 200 k entreprises × 5 use cases LLM × $0.001/use case = $1000/mois si on n'optimise pas. Cible : ≤ 60 €/mois.
**Décision :** LLM Router avec routing intelligent par use case → Mistral Small (économique) en priorité, Claude Haiku 4.5 pour parsing complexe, fallback Ollama local sur GPU Hetzner si volume > 500 k/mois. Cost cap par workspace avec kill-switch. Cache LLM agressif (clé : hash du prompt + version).

### Risque #4 — Faux positifs LinkedIn (Google Search ramène un homonyme)
**Probabilité :** Élevée. **Impact :** Sévère (cold email envoyé à mauvaise personne = signal RGPD négatif + churn).
**Analyse :** « Jean Dupont DG » → des dizaines d'homonymes possibles. Le moteur ne sait pas lequel est le bon.
**Décision :** Scoring de matching obligatoire (use case `linkedin_url_matching_scoring`) : nom dans URL + nom dans snippet + raison sociale dans snippet + ville si dispo. Score < 70 → flag « non confirmé », pas exposé en filtre « Prêt cold email ».

### Risque #5 — Plainte CNIL pour scraping massif
**Probabilité :** Faible. **Impact :** Critique (interdiction, amende, ferme la boîte).
**Analyse :** Le RGPD autorise l'intérêt légitime B2B sur emails pro nominatifs (art. 6.1.f). Mais la CNIL surveille les zones grises (scraping volumes élevés, opt-out non respecté, conservation > nécessaire).
**Décision :** Base légale documentée (intérêt légitime B2B) + table opt-out cross-workspace consultée AVANT chaque scraping/enrichissement + emails pro uniquement (refus gmail/hotmail/yahoo sauf publication pro vérifiée) + purge logs > 90 j + DPO `contact@axion-ia.com` + droit accès/suppression atomique multi-tables.

### Risque #6 — Direction Finder peu fiable sur ETI/Grandes
**Probabilité :** Moyenne. **Impact :** Modéré (taux succès attendu 25-40 % vs 5-15 % sans).
**Analyse :** Les ETI ont des pages /direction propres dans ~60 % des cas. Les Grandes les cachent souvent dans des rapports annuels PDF de 200 pages. Le PDF parsing reste imparfait.
**Décision :** Multi-sources combinées (pages corporate + presse + rapport annuel AMF + Google Search étendu) + LLM Haiku 4.5 pour extraction structurée + cache `corporate_pages_crawled` TTL 30 j + activation conditionnelle (skip si effectif < 100). Si succès global < 25 % à 90j, déclencher Phase 2 premium (PhantomBuster ou équivalent).

### Risque #7 — Bottleneck PostgreSQL à 1M+ rows
**Probabilité :** Élevée à terme. **Impact :** Sévère (lenteur UI, scraping ralenti).
**Analyse :** Coverage Matrix sur 32 colonnes croisées peut exploser. Tables `scraper_runs`, `llm_usage`, `email_verifications` grossissent vite.
**Décision :** Partitionnement pg_partman par mois sur les tables hot (`scraper_runs`, `llm_usage`, `audit_logs`, `email_sends`, `proxy_usage_log`). Indexes composites obligatoires (`workspace_id, ...`). Materialized view `coverage_matrix_cells` refresh hourly. Read replica Postgres dès 500 k entreprises.

### Risque #8 — Dev solo absent longue durée
**Probabilité :** Moyenne (vacances, maladie). **Impact :** Sévère (plateforme = pilier business Axion-IA).
**Analyse :** Will = dev unique. Pas de roulement. Si KO 2 semaines, scraping s'arrête, signaux ratés.
**Décision :** Documentation exhaustive (cette spec + runbooks dans `18_deploiement_hetzner.md`) + monitoring + alertes Slack/Telegram + 24 prompts Claude Code prêts à l'emploi (`23_interfaces_phase2_execution_pack.md` B.4) → un freelance peut prendre le relais en < 2 jours sur n'importe quel module.

---

## Chain-of-thought architecte — 8 décisions structurantes

### Décision #1 — Isolation totale d'axion-ia.com
**Choix :** Compte Hetzner DÉDIÉ Axion CRM Pro, IPs distinctes, vSwitch séparé, domaine séparé, secrets séparés, DB séparée.
**Pourquoi :** (1) Axion-IA est public-facing, Axion CRM Pro est interne — surfaces d'attaque différentes. (2) Un éventuel ban IP pour scraping ne doit JAMAIS impacter axion-ia.com. (3) Cession future possible (CRM peut être vendu sans céder le cabinet).
**Trade-off :** Coût infra +30 € HT/mois (compte séparé) vs risque cross-impact.

### Décision #2 — Stack Laravel/PHP backend + Node/Playwright workers
**Choix :** Hybride. Laravel 12 pour API + orchestration + admin. Node 22 + Playwright pour scraping headless.
**Pourquoi :** (1) Laravel = écosystème mature (Sanctum, Horizon, Spatie), DX excellente, queues Redis natives. (2) Playwright = standard headless, anti-bot, navigation fluide, JS rendu correctement. (3) PHP médiocre pour scraping JS, Node médiocre pour API métier complexe.
**Trade-off :** 2 langages = 2 stacks de tests. Mitigé par bridge Redis simple + DTOs partagés JSON.

### Décision #3 — PostgreSQL 16 unique (pas de Mongo/Elastic)
**Choix :** PostgreSQL 16 avec extensions pg_trgm + postgis + pgvector + pg_partman.
**Pourquoi :** (1) Une seule source de vérité, pas de sync. (2) pg_trgm = fuzzy matching natif (vs Elasticsearch). (3) postgis = géocodage natif pour la carte. (4) pgvector = futur embedding LLM. (5) pg_partman = partitionnement automatique. (6) Coût ops ÷ 3 vs polyglotte.
**Trade-off :** Plafond ~5M rows avant scaling read replica. Acceptable Phase 1.

### Décision #4 — Frontend React 19 + TanStack (vs Inertia.js)
**Choix :** SPA React + TanStack Query + Sanctum cookie auth.
**Pourquoi :** (1) Console = dashboard temps réel avec graphes/cartes/listes virtualisées. SPA pure = meilleur DX. (2) Inertia ajoute couche Laravel-flavored mais pas adaptée carte interactive temps réel. (3) Possibilité future de wrapper mobile (React Native) si besoin.
**Trade-off :** Setup auth SPA un peu plus complexe que Inertia. Mitigé par doc Sanctum SPA officielle.

### Décision #5 — Multi-tenant prêt mais mono-tenant au lancement
**Choix :** `workspace_id` partout (DB + API + UI) + RLS policies PostgreSQL. Démarrage avec 1 workspace `axion-ia`.
**Pourquoi :** (1) Refactor multi-tenant après coup = coûteux. (2) RLS = double sécurité en cas de bug filtre applicatif. (3) Permet future commercialisation (SaaS) ou consulting (workspaces clients).
**Trade-off :** Index composite obligatoire partout = légère perte perf vs single-tenant. Négligeable.

### Décision #6 — Anti-doublon strict 6 niveaux dès le démarrage
**Choix :** SIREN + contact hash + scraping jobs TTL + coverage cells cooldown + validation email TTL + opt-out cross-workspace. Implémenté **avant** premier scraping en production.
**Pourquoi :** Sans dedup, 30 % du budget proxies + LLM est gaspillé en re-scraping inutile (~50-100 €/mois). Plus douloureux à ajouter après coup quand la DB est sale.
**Trade-off :** +1 semaine de dev en S2. Largement remboursé en 1 mois.

### Décision #7 — LLM Router pluggable runtime (jamais hardcodé)
**Choix :** Table `llm_use_cases` éditable depuis admin. Aucun nom de modèle en dur dans le code.
**Pourquoi :** (1) Le marché LLM bouge vite (Haiku 4.5 → 5 dans 6 mois). (2) Permet A/B testing sans redéploiement. (3) Permet fallback Ollama local si Claude rate-limite. (4) Doctrine Axion-IA : « aucun lock-in technologique ».
**Trade-off :** Setup légèrement plus complexe (cf. `07_llm_router.md`). Largement justifié.

### Décision #8 — Sources gratuites uniquement (zéro abonnement payant)
**Choix :** 14 sources gratuites. Variables : proxies (~30 €/mois) + captcha solving (~20 €/mois optionnel).
**Pourquoi :** (1) Pappers : 99 € HT/mois × workspace = explose au scale. (2) PhantomBuster : 70-200 $/mois + risque ban LinkedIn. (3) Sales Navigator : 79 € HT/mois × 3 = 237 €/mois + risque ban. (4) Apollo/Lusha/Kaspr : 100-500 $/mois + qualité émail médiocre. (5) Nos alternatives : annuaire-entreprises remplace Pappers, Google Search Wrapper remplace PhantomBuster, Direction Finder remplace partiellement Sales Navigator.
**Trade-off :** Taux succès LinkedIn finders légèrement inférieur (50-70 % vs 80-90 %). Acceptable pour démarrage. Phase 2 premium possible.

---

## Executive summary

### Pour l'opérationnel (Will, futur dev freelance)

> **Axion CRM Pro est une plateforme B2B qui scrappe automatiquement les entreprises françaises (TPE + PME + ETI + Grandes), trouve les emails pros et URLs LinkedIn des décideurs, et organise tout dans une console admin. La Phase 1 livre la collecte ; la Phase 2 (scaffold) livrera l'envoi cold email + LinkedIn outreach + CRM.**

**Volume cible :** 200 k entreprises/mois en mois 1, 1 M/mois en année 1.

**KPI succès Phase 1 :** Nombre de fiches 🟢 (email validé ≥70 + nom décideur + téléphone + LinkedIn). Cible : 50 000 fiches 🟢 en fin de S12.

**Stack courte :** Laravel 12 + PostgreSQL 16 + Redis 7 + React 19 + Node 22 + Playwright sur Hetzner Frankfurt.

**Coût mensuel cible :** ~275-345 €/mois Phase 1 (vs ~635 € avec PhantomBuster + Sales Navigator).

**Durée :** 12 semaines dev (cf. `21_couts_roadmap.md`).

### Pour le métier (Axion-IA prospects)

> **Outil interne Axion-IA pour identifier et qualifier automatiquement les prospects (TPE/PME/ETI/Grandes) avec besoin IA documenté, pour optimiser le démarchage commercial du cabinet.**

Pas commercialisé. Pas SaaS. 100 % interne. RGPD + AI Act-friendly (intérêt légitime B2B documenté + opt-out cross-workspace + audit log immuable + AI Act register).

### Pour la conformité (DPO + AMF/CNIL si questions)

- **Base légale RGPD :** Intérêt légitime (art. 6.1.f) pour prospection B2B sur emails pros nominatifs.
- **Emails personnels exclus** (gmail/hotmail/yahoo sauf publication pro vérifiée).
- **Opt-out cross-workspace** consulté avant tout scraping/enrichissement.
- **Conservation données scraping** : 90 jours max après dernière utilisation, auto-purge.
- **Droit accès/suppression** : transaction multi-tables atomique, < 30 j (cf. `17_rgpd_aiact_owasp.md`).
- **DPO** : `contact@axion-ia.com`.
- **AI Act** : tous les use cases de profilage automatisé (`classify_company_axion` v1.1 mergé, anciennement `ia_maturity_scoring` + `axion_offer_match` v1.0) documentés en table `ai_act_register`. Le scoring qualité de fiche (🟢/🟡/🔴) est **déterministe SQL**, pas LLM, donc pas concerné par AI Act profilage automatisé.

### Pour le futur Phase 2

DB + UI prêtes pour : Cold Email (envoi de masse), LinkedIn Outreach (messagerie auto), CRM pipeline (deals/activités), Analytics. Activation = implémenter la logique métier dans les workers + workflows existants. Voir `04_db_schema_phase2_scaffold.md` + `23_interfaces_phase2_execution_pack.md`.

---

## Flux global ASCII

```
                                    AXION CRM PRO — FLUX BOUT-EN-BOUT
                                    ════════════════════════════════════

  ┌─────────────────────────────────────────────────────────────────────────────────────┐
  │                              CONSOLE ADMIN UNIQUE                                    │
  │  ┌─────────┬─────────┬──────────┬───────────┬──────────┬────────────┬─────────────┐ │
  │  │Dashboard│Coverage │Liste     │Détail     │LLM       │RGPD        │Workspaces   │ │
  │  │KPIs     │Map+Mtx  │entr.+ctc │entr.+ctc  │Router    │requests    │+ users +RBAC│ │
  │  └─────────┴─────────┴──────────┴───────────┴──────────┴────────────┴─────────────┘ │
  └────────────────────────────────────┬─────────────────────────────────────────────────┘
                                       │  API REST Laravel 12 + Sanctum SPA
                                       ▼
  ┌─────────────────────────────────────────────────────────────────────────────────────┐
  │                    BACKEND LARAVEL 12 (8 modules)                                    │
  │                                                                                       │
  │  Auth+RBAC ─── Scraping orchestrator ─── Email Finder ─── LLM Router ─── RGPD       │
  │   │              │                       │                  │              │         │
  │   │              ▼                       ▼                  ▼              ▼         │
  │   │   ┌──────────────────────┐  ┌──────────────┐  ┌──────────────┐  ┌────────────┐  │
  │   │   │ Dedup Service (6 niv)│  │SMTP cascade  │  │5 providers   │  │Audit hash  │  │
  │   │   └──────────────────────┘  │N1→N5 + score │  │+ fallback    │  │chain       │  │
  │   │                             └──────────────┘  └──────────────┘  └────────────┘  │
  │   ▼                                                                                   │
  │  Spatie Permission + RLS PostgreSQL + audit_logs append-only                         │
  └──────────────────────────────┬──────────────────────────────────────────────────────┘
                                 │  Redis queues (Horizon + BullMQ)
                                 ▼
  ┌─────────────────────────────────────────────────────────────────────────────────────┐
  │                    WORKERS PLAYWRIGHT (Node.js 22)                                   │
  │                                                                                       │
  │  Google Maps ─ Pages Jaunes ─ Sites web ─ Google Search ─ Direction Finder          │
  │   (Playwright + playwright-extra stealth + proxies rotation + UA pool)              │
  │                                                                                       │
  └──────────────────────────────┬──────────────────────────────────────────────────────┘
                                 │
                                 ▼
  ┌─────────────────────────────────────────────────────────────────────────────────────┐
  │                    14 SOURCES DE DONNÉES (100 % GRATUITES)                           │
  │                                                                                       │
  │  APIs officielles : INSEE Sirene · BODACC · France Travail · MESRI/ONISEP · BAN     │
  │  Sites scrappés   : annuaire-entreprises · Infogreffe · Societe.com ·               │
  │                     Google Maps · Pages Jaunes · sites web · Google Search ·         │
  │                     Crunchbase · réseaux sociaux (URLs)                              │
  └──────────────────────────────┬──────────────────────────────────────────────────────┘
                                 │
                                 ▼
  ┌─────────────────────────────────────────────────────────────────────────────────────┐
  │                    POSTGRES 16 (32 tables Phase 1 + 30 tables Phase 2 scaffold)     │
  │                                                                                       │
  │  Entités : workspaces · users · companies · contacts · email_verifications · ...    │
  │  Scraping: scraper_runs (partitionnée) · proxies · llm_usage (partitionnée) · ...   │
  │  RGPD    : opt_out (global) · data_processing_log · gdpr_requests · audit_logs      │
  │  Coverage: coverage_matrix_cells (materialized view) · duplicate_flags              │
  └──────────────────────────────┬──────────────────────────────────────────────────────┘
                                 │
                                 ▼
  ┌─────────────────────────────────────────────────────────────────────────────────────┐
  │                    MONITORING (auto-hébergé)                                          │
  │                                                                                       │
  │  Prometheus + Grafana (10 dashboards) + Loki + Tempo + GlitchTip + Uptime Kuma      │
  │  Alertmanager → Slack + Telegram + email pour CRITICAL                              │
  └─────────────────────────────────────────────────────────────────────────────────────┘
```

### Flux waterfall détaillé (par entreprise scrapée)

```
  [SIREN cible] (depuis INSEE batch OU clic carte « Lancer zone »)
        │
        ▼
   Étape 1  ┌──────────────────────┐
            │ INSEE Sirene API     │  → companies (raison sociale, NAF, effectif, adresse)
            └──────────────────────┘
        │
        ▼
   Étape 2  ┌──────────────────────┐
            │ annuaire-entreprises │  → dirigeant légal, CA, bilans, bénéficiaires
            │ + Infogreffe fallback│
            │ + Societe.com fallbk │
            └──────────────────────┘
        │
        ▼
   Étape 3  ┌──────────────────────┐
            │ Google Maps          │  → téléphone, site web, horaires, avis
            │ + Pages Jaunes backup│
            └──────────────────────┘
        │
        ▼
   Étape 4  ┌──────────────────────┐
            │ Scraping site web    │  → emails (tous, classifiés), pattern, équipe, sociaux
            └──────────────────────┘
        │
        ▼
   Étape 5  ┌──────────────────────┐
            │ Google Search Wrapper│  → linkedin_url (entreprise + dirigeant légal)
            │ Google→Bing→DDG     │
            └──────────────────────┘
        │
        ▼
   ┌────[ effectif > 100 ? ]────┐
   │ OUI                        │ NON → skip
   ▼                            │
   Étape 6 (CONDITIONNELLE)    │
   ┌──────────────────────┐    │
   │ Direction Finder     │    │  → C-level (DRH, DAF, DSI, Mkt, Comm)
   │ pages corporate +    │    │     + leurs emails (via pattern entreprise)
   │ presse + rapp. ann.+ │    │     + leurs LinkedIn URLs
   │ Google Search étendu │    │
   └──────────────────────┘    │
   │                            │
   └────────────┬──────────────┘
                ▼
   Étape 7  ┌──────────────────────┐
            │ Email Finder         │  → variantes générées (15+ patterns)
            │ + Validation SMTP    │     + cascade N1 syntaxe → N5 score
            └──────────────────────┘
        │
        ▼
   Étape 8  ┌──────────────────────┐
            │ Géocodage BAN        │  → lat/lon
            └──────────────────────┘
        │
        ▼
   Étape 9  ┌──────────────────────┐
            │ Signaux business     │  → flags (recrutement, levée, redressement, etc.)
            │ BODACC+FT+Crunchbase │
            └──────────────────────┘
        │
        ▼
   Étape 10 ┌──────────────────────┐
            │ Classification LLM   │  → maturité IA + offre match Axion-IA + tags
            │                      │     + score qualité fiche 🟢/🟡/🔴
            └──────────────────────┘
        │
        ▼
   [Entreprise enrichie, prête pour Coverage Matrix + console]
```

**Latence cible par entreprise (waterfall complet) :**
- TPE/PME : < 30 secondes
- ETI/Grandes (avec Direction Finder) : < 90 secondes

---

## 3 propositions de nom de domaine

> **Contrainte :** le domaine de la console admin doit être **distinct** d'`axion-ia.com`. Pas de sous-domaine.

### Option A — `crm.axion-pro.com` *(recommandée)*

- **Pourquoi :** Court, mémorable, suffixe `pro` indique outil interne. Disponible probablement (à vérifier).
- **Coût :** 10-15 €/an (Namecheap ou OVH).
- **DNS :** Cloudflare Free (comme axion-ia.com mais compte CF distinct).

### Option B — `console.axionprospect.io`

- **Pourquoi :** Plus explicite (« prospection »). TLD `.io` = signal tech.
- **Coût :** 30-40 €/an (`.io` plus cher).
- **DNS :** Cloudflare Free, compte distinct.

### Option C — `app.axion-crm.fr`

- **Pourquoi :** Brand-aligned, TLD `.fr` localement légitime. Sous-domaine `app.` = convention SaaS.
- **Coût :** 7-12 €/an.
- **DNS :** Cloudflare Free, compte distinct.

**Décision provisoire** : **Option A** (`crm.axion-pro.com`). À valider Will. Si pris, tomber sur Option C.

> **STOP & ASK Will :** valider une des 3 options ou en proposer une 4ᵉ. **Décision faute de réponse : Option A.**

---

## Phase 1 vs Phase 2 — clarification critique

### Ce qui est dans Phase 1 (implémenté complètement)

| Module | Statut spec | Code |
|--------|-------------|------|
| Multi-tenant + Auth + 2FA + RBAC | ✅ Spécifié | À coder S1 |
| Scraping 14 sources | ✅ Spécifié | À coder S3-S7 |
| Google Search Wrapper (URLs LinkedIn) | ✅ Spécifié | À coder S6 |
| Direction Finder (ETI/Grandes) | ✅ Spécifié | À coder S6 |
| Email Finder + validation SMTP cascade | ✅ Spécifié | À coder S8 |
| LLM Router 5 providers | ✅ Spécifié | À coder S2 |
| Carte France interactive (3 modes) | ✅ Spécifié | À coder S9 |
| Anti-doublon strict 6 niveaux | ✅ Spécifié | À coder S2 |
| Classification LLM (maturité + offres + tags + qualité fiche) | ✅ Spécifié | À coder S10 |
| Monitoring + audit + RGPD | ✅ Spécifié | À coder S12 |
| UI admin 17 pages | ✅ Spécifié | À coder S1-S12 |

### Ce qui est dans Phase 2 (scaffold uniquement)

| Module | Statut spec | Code |
|--------|-------------|------|
| Cold Email envoi de masse | 🟡 DB + UI scaffold | Phase 2 (post-S12) |
| LinkedIn Outreach automatisé | 🟡 DB + UI scaffold | Phase 2 |
| CRM pipeline (deals, activités) | 🟡 DB + UI scaffold | Phase 2 |
| Analytics avancées | 🟡 DB + UI scaffold | Phase 2 |
| Orchestrateur campagnes multi-canal | 🟡 DB + UI scaffold | Phase 2 |

> **Règle dure :** Aucune logique métier Phase 2 dans le code Phase 1. Tables DB créées vides + pages UI affichant « Phase 2 — bientôt disponible » + routes API retournant `501 Not Implemented` avec types définis. Cela permet d'enclencher Phase 2 en mois 4 sans refactor.

### Pourquoi cette séparation stricte

1. **MVP focus** — Avoir 50 000 fiches 🟢 prêtes à contacter dans 3 mois > tout faire en 6 mois.
2. **Validation business** — Tester ROI cold email manuel avant d'investir dans l'automation.
3. **Cohérence DB** — Schéma Phase 2 connu dès le départ = pas de migration douloureuse plus tard.
4. **Cohérence UI** — Console unique dès le départ = pas de retour utilisateur sur navigation Phase 2 plus tard.

---

## Anti-objectifs explicites (à ne PAS faire)

- ❌ **Pas de Pappers API** — annuaire-entreprises.data.gouv.fr couvre 95 % du besoin gratuitement.
- ❌ **Pas de PhantomBuster** — Google Search Wrapper + Direction Finder couvrent le besoin URLs LinkedIn.
- ❌ **Pas de Sales Navigator** — Direction Finder + scraping pages corporate suffisent au démarrage.
- ❌ **Pas d'Apollo/Lusha/Kaspr** — qualité variable, lock-in, coût.
- ❌ **Pas de scraping direct des profils LinkedIn** — risque ban + RGPD discutable. Uniquement les URLs publiques retournées par moteurs de recherche.
- ❌ **Pas d'envoi d'emails/messages LinkedIn en Phase 1** — uniquement scaffold DB + UI.
- ❌ **Pas de no-code en production** — Filament/Forest/Retool exclus. React custom obligatoire.
- ❌ **Pas de référence à AppFactory / Mission Control / SOS-Expat** — projets distincts, ne pas mélanger.
- ❌ **Pas de partage infra/IPs/domaine avec axion-ia.com** — isolation totale.
- ❌ **Pas de LLM hardcodé** — toujours via LLM Router avec table `llm_use_cases` éditable.
- ❌ **Pas de secrets en clair dans Git** — Infisical self-hosted ou Doppler.
- ❌ **Pas de limite arbitraire 20 pages** sur les scrapers paginés — pagination jusqu'à fin réelle.
- ❌ **Pas de multi-tenant à moitié** — `workspace_id` partout dès jour 1, RLS dès jour 1.
- ❌ **Pas de logique métier Phase 2 dans Phase 1** — uniquement scaffold.

---

## Lecture suivante

→ `02_architecture_infra.md` (diagramme infra, dimensionnement Hetzner détaillé, isolation d'axion-ia.com).
