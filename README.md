# Axion CRM Pro

Plateforme B2B de prospection automatisée pour Axion-IA.

## Description

Outil de scraping + enrichissement + future Phase 2 cold email/LinkedIn outreach. Console admin unique pour piloter toute la prospection sans SSH, sans code, sans outil tiers.

## Cible

TPE/PME françaises (1-50 salariés) en priorité, mais le système couvre **toutes les tailles** :

- Artisans + Commerçants (1-9 salariés) — ~1,5M entités
- TPE (1-19 salariés) — ~4M entreprises
- PME (20-249) — ~150 k entreprises
- ETI (250-4999) — ~6 000 entreprises (activation **Direction Finder** automatique)
- Grandes (5000+) — ~280 entreprises (Direction Finder + best-effort)
- Écoles + universités + CFA (en complément formations IA)

Décideur cible variable selon la taille (dirigeant légal pour TPE/PME, C-level pour ETI/Grandes via Direction Finder).

## Stack

- **Backend** — Laravel 12 + PHP 8.3 + PostgreSQL 16 (pg_trgm, postgis, pgvector, pg_partman) + Redis 7 + Horizon + Sanctum
- **Frontend** — React 19 + TypeScript 5.6 + Vite 6 + Tailwind 4 + shadcn/ui + MapLibre GL JS 4 + TanStack Query/Virtual
- **Workers** — Node.js 22 LTS + Playwright 1.49+ (Chromium) + playwright-extra stealth — pour les scrapers headless
- **Hosting** — Hetzner Cloud Frankfurt (UE/RGPD), compte Hetzner **dédié** (isolation totale d'axion-ia.com)
- **Monitoring** — Grafana + Prometheus + Loki + Tempo + GlitchTip + Uptime Kuma (auto-hébergés)

## Sources de données (14, 100 % gratuites)

INSEE Sirene · annuaire-entreprises.data.gouv.fr (remplace Pappers) · Infogreffe · Societe.com · BODACC · Google Maps · Pages Jaunes · Sites web entreprises · **Google Search Wrapper** (remplace PhantomBuster pour URLs LinkedIn) · France Travail · MESRI/ONISEP · Crunchbase · BAN (api-adresse) · Social light.

**Aucun abonnement payant** : pas de Pappers, pas de PhantomBuster, pas de Sales Navigator, pas d'Apollo/Lusha/Kaspr.

## Anti-doublon strict (6 niveaux)

1. Entreprise par SIREN (unique composite `workspace_id, siren`)
2. Contact par hash normalisé `prenom + nom + company_id`
3. Scraping jobs par TTL configurable par source (`scraper_runs`)
4. Coverage cells avec cooldown 24 h
5. Validation email avec TTL 30 j
6. Opt-out cross-workspace (RGPD)

## Scoring qualité des fiches

Chaque entreprise scrapée reçoit un score :

- 🟢 **Complète** — email validé ≥70 + nom décideur + téléphone + LinkedIn → prête cold email
- 🟡 **Partielle** — email OU LinkedIn + nom décideur + 1 autre donnée → utilisable manuellement
- 🔴 **Basique** — INSEE + téléphone → fiche catalogue

## Statut actuel

Phase 1 — **spec en cours** dans `./spec/` (24 fichiers Markdown denses).

- ✅ Spec Phase 1 (modules implémentés)
- 🟡 Spec Phase 2 scaffold (cold email, LinkedIn outreach, CRM, analytics — DB + UI prêtes, logique vide)
- ⏳ Code (vide pour l'instant — Phase 1 sera implémentée après validation spec)

## Phases

**Phase 1 — IMPLÉMENTÉE dans la spec** :

- Scraping 14 sources gratuites
- Enrichissement waterfall 10 étapes
- Email finder + validation SMTP cascade
- Récupération URLs LinkedIn (Google Search Wrapper)
- Direction Finder (ETI/Grandes : pages corporate + presse + rapport annuel + Google Search étendu C-level)
- Coverage Matrix + carte France interactive (MapLibre + IGN + BAN)
- LLM Router multi-providers configurable runtime
- Auth + multi-tenant (workspace_id + RLS) + RBAC (Spatie Permission)
- Anti-doublon strict 6 niveaux
- Monitoring + observabilité

**Phase 2 — SCAFFOLDÉE (DB + UI prêtes, logique vide)** :

- Cold Email (envoi de masse — finalité business)
- LinkedIn Outreach (messagerie automatisée)
- CRM (pipeline, deals, activités)
- Analyses avancées + ROI

## Volume cible

- Mois 1 — 200 000 entreprises traitées (~7 000/jour)
- Année 1 — vers 1 M entreprises/mois

## Coût mensuel cible Phase 1

~275-345 €/mois (vs ~635 € avec PhantomBuster + Sales Navigator).

## Documentation

- Spec complète — `./spec/00_INDEX.md`
- Architecture infra — `./spec/02_architecture_infra.md`
- DB schema Phase 1 — `./spec/03_db_schema_phase1.md`
- Roadmap & coûts — `./spec/21_couts_roadmap.md`
- Execution pack (12 prompts CC) — `./spec/23_interfaces_phase2_execution_pack.md`

## Doctrine technique (héritée d'Axion-IA)

- Hébergement UE par défaut (RGPD)
- Mix LLM open-source + propriétaires, **Claude pivot**
- OWASP top 10 appliqué, journalisation immuable (hash chain), minimisation PII
- Code custom — pas de no-code en production (Filament/Forest exclus)
- Aucun lock-in technologique (LLM Router pluggable, ProxyProvider pluggable, ScraperPlugin pluggable)

## Isolation d'axion-ia.com

Compte Hetzner séparé, IPs distinctes, domaine séparé, secrets séparés, base de données séparée. La proximité locale dans `C:\Users\willi\Documents\Projets\` est purement organisationnelle.

## Licence

Propriétaire — Axion-IA OÜ.
