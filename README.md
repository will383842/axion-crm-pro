# Axion CRM Pro

Plateforme B2B de prospection automatisée pour Axion-IA. Interne, multi-tenant ready, RGPD/AI Act compliant, Phase 1 complète + Phase 2 scaffold.

## Démarrage local 5 minutes

```bash
# 1. Clone + .env
git clone https://github.com/will383842/axion-crm-pro.git
cd axion-crm-pro
cp .env.example .env

# 2. Démarrer la stack — les 8 services RÉELS de docker-compose.yml
#    (postgres, redis, api, horizon, reverb, scheduler, app, caddy).
#    Cette ligne annonçait « + workers » jusqu'au 2026-08-22 : aucun service
#    worker n'a jamais été déclaré ici (constat A09-010). Les workers Node se
#    lancent hors conteneur, depuis `workers/` — voir « Tests » plus bas.
docker compose up -d

# 3. Migrations + seeders (mode mocks + démo)
docker exec axion-crm-api php artisan key:generate
docker exec axion-crm-api php artisan migrate:fresh --seed

# 4. Accéder
# - API : https://api.localhost/up           (200 OK)
# - App : https://app.localhost              (login form)
# - Docs: https://api.localhost/docs         (Swagger UI)
# - Horizon : https://api.localhost/horizon  (queues)
```

**Mocks** : `MOCK_MODE=true` par défaut → aucun appel réseau réel, aucun coût provider.

## Stack

- **Backend** — Laravel 12 + PHP 8.3 + PostgreSQL 16 (pg_trgm, postgis, pgvector, pg_partman, citext) + Redis 7 + Horizon + Sanctum + Spatie Permission v6 (teams)
- **Frontend** — React 19 + TypeScript 5.6 + Vite 6 + Tailwind 4 + TanStack Router/Query + MapLibre GL JS 4 + shadcn/ui
- **Workers** — Node 22 LTS + Playwright 1.49 + BullMQ + cheerio + pino
- **Hosting** — Hetzner Cloud Frankfurt (UE/RGPD), Coolify v4 PaaS, Caddy 2 reverse proxy
- **Observabilité** — Prometheus + Grafana + Loki + Tempo + GlitchTip + Uptime Kuma

## 14 sources de données (100 % gratuites)

INSEE Sirene · annuaire-entreprises.data.gouv.fr · Infogreffe · Societe.com · BODACC · Google Maps · Pages Jaunes · sites web · Google Search Wrapper · France Travail · MESRI/ONISEP · Crunchbase · BAN · social light.

## Anti-doublon strict (6 niveaux)

1. Entreprise par SIREN (UNIQUE workspace_id, siren)
2. Contact par hash normalisé (`normalized_hash` GENERATED Postgres)
3. Scraping jobs par TTL configurable par source (7d → 365d)
4. Coverage cells cooldown 24h (`coverage_zones`)
5. Validation email TTL 30j (`email_validations`)
6. Opt-out cross-workspace (RGPD global)

## Sprints livrés (12/12)

| Sprint | Livré | Statut |
|--------|-------|--------|
| S1 Bootstrap | infra + backend + frontend + workers + CI | ✅ |
| S2 DB migrations + RLS + seeders | 9 migrations Phase 1+2 + 13 seeders + triggers | ✅ |
| S3 Auth + RBAC + multi-tenant + audit | login + 2FA + magic-link + password reset + AuditHashChain | ✅ |
| S4 LLM Router + Proxies + Dedup + Rotations | 5 providers HTTP + Webshare/IPRoyal + dedup 6 niveaux + WRR | ✅ |
| S5 Sources officielles + Waterfall | INSEE/Annuaire/BODACC/BAN/FT + WaterfallOrchestrator 10 étapes | ✅ |
| S6 Workers Playwright | Google Maps + PJ + Web stealth + bridge HMAC | ✅ |
| S7 Search Wrapper + Direction Finder | 3 moteurs + 16 C-level titles + 13 paths corporate | ✅ |
| S8 Email finder + SMTP cascade | 18 patterns + N1-N5 + catch-all detection | ✅ |
| S9 Carte France interactive | MapLibre + IGN + 3 modes Visu/Search/Action | ✅ |
| S10 Classification + UI 17 pages | 4 LLM use cases + AutoTagApplier DSL + composants UI | ✅ |
| S11 Phase 2 scaffold + RGPD + Monitoring | tables Phase 2 + Erasure/Portability + 7-services obs stack | ✅ |
| S12 Tests E2E + Doc + Polish + Tag | Playwright × 17 + runbooks × 5 + DR drill + pentest + OpenAPI | ✅ |

## Où en est l'envoi (18/11)

- Le code de l'envoi est écrit et testé, mais **rien ne part** : `ENVOI_REEL_ACTIF=false`. Aucun agent ne le touche.
- Fusionné le 18/11 : repli sur l'adresse générale (avec opposition sur l'adresse et le SIREN), LinkedIn (file, alertes, relevé), assistant et campagnes (écrans grisés tant qu'un refus s'applique), plafond d'IP et inventaire des IP, feu vert, santé par espace. Détail dans `CHANGELOG.md`.
- Connexion : codes de secours et écrans du code courriel fusionnés ; un changement de mot de passe ferme les appareils de confiance. La double authentification par courriel reste éteinte ; la commande locale `crm:compte-double-auth` (PR #792, en relecture) refuse de l'activer tant que le canal est faux.
- Bilan de couverture du contrat d'envoi : `docs/envoi/lots/R19.md` (aucun trou réel).

## Ce qui reste au fondateur

Ces gestes ne peuvent pas être faits par un agent. La procédure complète, dans l'ordre, est dans **`docs/envoi/JOUR-J.md`** :

- **Achats** : serveur d'envoi, domaines d'envoi et adresses IP (étape « J-30 »).
- **DNS** : publier SPF, DKIM, DMARC, PTR et HELO, puis contrôler (étape « J-21 »).
- **Port 25** : le faire ouvrir en sortie chez l'hébergeur (étape « J-30 »).
- **Feu vert** : relancer la batterie des preuves, accorder le feu vert à l'écran, puis poser soi-même `ENVOI_REEL_ACTIF=true` (étape « J0 »).
- Allumer les autres interrupteurs de production (double authentification par courriel, garde du rôle applicatif) : décision du fondateur, après le jour J ou à part.

Aucun prix ni secret n'est écrit dans le dépôt : les références d'achat et les vraies valeurs restent hors dépôt.

## Runbooks

- `infra/runbooks/01-restart-workers.md` — workers Playwright
- `infra/runbooks/02-disk-full.md` — disque plein
- `infra/runbooks/03-site-down.md` — 5xx persistant
- `infra/runbooks/04-restore-dr.md` — restauration disaster recovery
- `infra/runbooks/05-rotate-secrets.md` — rotation secrets

## Tests

```bash
# Backend Pest
docker exec axion-crm-api composer test

# Frontend Vitest
docker exec axion-crm-app pnpm test

# Workers Vitest (depuis host) — il n'existe AUCUN conteneur `axion-crm-worker-*` :
# docker-compose.yml n'en déclare pas (mesure du 2026-08-22, constat A09-010).
# Sous-shell : la ligne suivante repart de la racine du dépôt.
(cd workers && pnpm test)

# E2E Playwright (depuis host)
cd frontend && pnpm e2e

# Load k6 (depuis host avec k6 installé)
k6 run --vus 50 --duration 2m infra/loadtest/k6-api.js

# Pentest self-check OWASP
docker exec axion-crm-api php artisan app:pentest-self-check

# Audit hash chain
docker exec axion-crm-api php artisan audit:verify-chain
```

## Bascule mocks → réel

1. Souscrire credentials (Anthropic, Webshare, IPRoyal, 2captcha) → mettre à jour `.env`
2. `MOCK_MODE=false` et flags granulaires (`MOCK_LLM=false`, etc.)
3. `docker compose restart api horizon worker-*`
4. Vérification : `php artisan llm:smoke-test` (à créer Sprint 13)

## Conformité

- ✅ **RGPD** art. 6 (intérêt légitime B2B), 15-22 (droits personnes), 30 (registre)
- ✅ **AI Act** UE 2024/1689 — registre `ai_act_register` populé
- ✅ **OWASP Top 10** via `php artisan app:pentest-self-check`
- ✅ **DPO** : `contact@axion-ia.com`
- ✅ **Hébergement UE** : Hetzner Frankfurt
- ⚠️ **DPA papier providers** : action humaine du fondateur (cf. spec/17)

## Licence

Propriétaire — AXION IA SAS.

## Documentation complète

- Spec exhaustive : `./spec/00_INDEX.md`
- Stratégie mocks : `./MOCKS-STRATEGY.md`
- Roadmap & coûts : `./spec/21_couts_roadmap.md`
- Progress autopilot : `./_REPORTS/PROGRESS.md`
- Audit qualité : `./_AUDIT/`
- Changelog : `./CHANGELOG.md`
