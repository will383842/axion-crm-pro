# 01 — THINKING + EXECUTIVE SUMMARY + NAMING

## Chain-of-thought architecte (visible, non caché)

> Cette section expose le raisonnement de l'architecte avant la spec proprement dite. Les 8 risques majeurs identifiés et les 8 décisions d'architecture clés sont posés explicitement pour que toute lecture future de la spec puisse les contester ou les valider.

### 8 risques techniques majeurs identifiés en amont

1. **Ban IP massif simultané sur plusieurs sources.** Si Google Maps + Pages Jaunes + Société.com + LinkedIn bloquent tous nos IPs le même jour, la chaîne d'enrichissement s'effondre et l'objectif 7000 entreprises/jour devient inatteignable. Mitigation : `ProxyProvider` pluggable + 4 providers en parallèle dès le démarrage + circuit breaker par source.
2. **Coût LLM qui explose.** À 200k entreprises × 4 classifications LLM × 1500 tokens moyens = 1,2 milliard de tokens/mois. Au prix Claude Sonnet, c'est ~3600€/mois — inacceptable. Mitigation : routing intelligent vers Haiku 4.5 par défaut + Mistral Small pour les classifications déterministes + Ollama local pour les cas volumineux. Budget cible : 150-250€/mois.
3. **PostgreSQL bottleneck à 1M+ rows sur `companies` et 5M+ sur `scraper_runs`.** Mitigation : partitionnement pg_partman par mois pour `scraper_runs`, `llm_usage`, `proxy_usage_log` + indexes GIN sur JSONB + materialized view `coverage_matrix_cells` rafraîchie hourly.
4. **annuaire-entreprises.data.gouv.fr change la structure HTML.** Source critique remplaçant Pappers. Si elle casse, on perd dirigeants légaux + CA + bilans. Mitigation : double scraping API + HTML + fallback Infogreffe + societe.com + monitoring alerting sur drop > 30% taux de succès.
5. **LinkedIn change ses défenses anti-bot et PhantomBuster tombe.** Source unique pour C-level non-dirigeants. Mitigation : isolation du module dans un plugin (interface `ScraperPlugin`), accepter de basculer en mode dégradé pendant 2-4 semaines le temps de patcher.
6. **Multi-tenant bugué = leak de données entre workspaces.** Risque catastrophe RGPD. Mitigation : RLS PostgreSQL au niveau DB (pas seulement applicatif) + middleware Laravel d'injection `workspace_id` automatique + tests E2E qui vérifient l'isolation + audit logs hash chain.
7. **Plainte CNIL sur scraping massif.** Cabinet IA = visibilité importante. Mitigation : base légale documentée (intérêt légitime B2B), aucun email perso, `opt_out` cross-workspace, registre RGPD intégré, DPO `contact@axion-ia.com`, droit accès/effacement transactionnel.
8. **Dev solo qui s'absente longue durée.** Williams = founder seul technique. Mitigation : zéro lock-in (Hetzner, OSS), spec exhaustive (cette spec), docker-compose dev/prod identiques, runbooks détaillés, monitoring auto-alertant sur Telegram + Slack.

### 8 décisions d'architecture clés (avec alternatives écartées)

1. **Laravel 12 + PHP 8.3 (rejeté : Symfony, Django, Rails, Adonis, Node Full Stack).** Justification : DX excellente pour SaaS B2B + écosystème Spatie ultra-mature + Horizon pour les queues + Sanctum pour SPA cookie + maturité communautaire FR.
2. **PostgreSQL 16 + extensions (rejeté : MySQL, SQLite, MariaDB).** Justification : JSONB GIN, pg_trgm fuzzy match, partitionnement natif, RLS, postgis, pgvector. Aucun équivalent.
3. **React 19 + Vite 6 + Tailwind 4 (rejeté : Next.js admin, Vue, Svelte, Filament, Inertia).** Justification : on veut une SPA pure côté admin (pas de SSR utile), zéro magie, contrôle total. Pas de Filament/Nova/Backpack : "pas de no-code en production" (doctrine Axion-IA).
4. **Node.js 22 + Playwright en workers séparés (rejeté : Symfony Panther, headless Chrome PHP wrappers).** Justification : Playwright écosystème mature, stealth plugins éprouvés, BullMQ stable. PHP n'est pas optimal pour scraping concurrent headless.
5. **Hetzner Cloud Frankfurt dédié (rejeté : OVH, Scaleway, AWS, GCP, Azure).** Justification : RGPD UE, prix imbattable (CCX23 ~30€/mois pour 4 vCPU dédiés), DC fsn1 hyper-stable, déjà familier (axion-ia.com tourne dessus). Compte SÉPARÉ pour isolation totale.
6. **MapLibre + OpenFreeMap + IGN AdminExpress + BAN (rejeté : Mapbox, Google Maps, MapTiler payant, HERE).** Justification : 100% gratuit, illimité, OSS, données officielles France. Aucun risque vendor lock-in ni coût qui dérape avec le volume.
7. **PhantomBuster + 3× Sales Nav (rejeté : Apollo, Lusha, Kaspr, Cognism, scraping LinkedIn direct).** Justification : Apollo/Lusha = dépendance lourde + coût 5-15k€/an + qualité moyenne en FR. Scraping direct LinkedIn = ban garanti. PhantomBuster est le moindre mal.
8. **LLM Router avec fallback chain configurable runtime (rejeté : hardcoded Claude partout, OpenAI partout, Mistral only).** Justification : besoin de mixer providers selon le cas d'usage (coût × qualité × latence) + résilience si un provider tombe + flexibilité business sans redéploiement.

### Sequencing de la spec

L'ordre des 24 fichiers suit une logique de dépendance technique :

1. **Bloc 1 (00-04)** : fondations communes. On définit le quoi (00, 01), où ça tourne (02), et comment les données sont stockées (03, 04).
2. **Bloc 2 (05-07)** : la couche scraping. Sources, email finder, LLM Router — la "matière première".
3. **Bloc 3 (08-10)** : orchestration. Waterfall, proxies, rotations — le "moteur".
4. **Bloc 4 (11-13)** : l'interface. Carte, coverage matrix, console admin — le "pilotage".
5. **Bloc 5 (14-17)** : prod-ready. API, auth/RBAC, monitoring, conformité.
6. **Bloc 6 (18-20)** : déploiement effectif. Hetzner, queues, détection prospects.
7. **Bloc 7 (21-23)** : exécution. Coûts, risques, et le tout-en-un "Execution Pack" pour Claude Code.

Cette progression évite les forward references : à aucun moment un fichier ne dépend d'un fichier qui le suit.

---

## Executive Summary métier

**Positionnement Axion-IA.** Axion-IA est un cabinet IA opérationnel B2B fondé par Williams Jullin (Axion-IA OÜ, structure estonienne). Son site `axion-ia.com` (Next.js 16) vend 5 prestations : **Audit Flash**, **Audit Ciblé**, **Mission PME**, **Mission ETI** et **Grand Programme**, ainsi que des **Interventions** plus courtes (formation, audit ponctuel, accompagnement implémentation). La cible : entreprises françaises 10-5000 employés, écoles et universités, secteur public marginalement.

Le bottleneck commercial actuel : **trouver et qualifier les bons prospects** au bon moment (signal d'achat actif), avec les bons interlocuteurs (dirigeants légaux ET C-level : DRH, DAF, DSI, Marketing, Commercial). Sans automatisation, ce travail est intenable pour un cabinet solo qui vise 1M€ de CA en année 1.

**Axion CRM Pro** est la machine industrielle qui adresse exactement ce bottleneck. Elle scrape massivement les sources gratuites françaises (INSEE, BODACC, annuaire-entreprises, sites web, etc.), enrichit chaque entreprise via un waterfall 9 étapes, classifie automatiquement via LLM (maturité IA estimée, offre Axion-IA recommandée, priorité contact, signaux business actifs) et présente le tout dans une console admin React avec carte de France interactive 3 modes.

**Lien avec les prestations.** Pour chaque entreprise enrichie, le LLM `axion_offer_match` propose automatiquement la prestation la plus pertinente :
- TPE 5-15 employés en phase "découverte IA" → **Audit Flash**
- PME 30-150 employés avec signal d'achat actif → **Mission PME**
- ETI 250-2000 avec maturité IA "en cours" → **Mission ETI**
- Groupe / GE > 5000 → **Grand Programme**
- Pas pertinent → **NON_CIBLE** (filtré par défaut)

L'opérateur (Williams) voit dans la console les "leads chauds" filtrés par offre × score priorité × signaux business du moment, et peut décider quoi attaquer en cold email/LinkedIn (Phase 2) ou directement par appel/email manuel (V1).

**Cible volumétrique année 1.** Couverture à 80%+ des ~300k entreprises FR éligibles (TPE 5-249 employés, secteurs marketing/digital/industrie 4.0/santé/finance/RH/conseil/éducation), soit ~240k entreprises enrichies + ~600k contacts décideurs identifiés + ~150k emails validés SMTP.

---

## Executive Summary technique

Axion CRM Pro est une plateforme Laravel 12 + React 19 + PostgreSQL 16 + Redis 7 + workers Node.js 22 + Playwright, hébergée sur un compte Hetzner Cloud Frankfurt dédié (isolation totale d'axion-ia.com). Le backend expose ~70 endpoints REST sécurisés par Laravel Sanctum cookie SPA + TOTP 2FA + magic link, avec RLS PostgreSQL et Spatie Permission pour l'auth/RBAC multi-tenant. Le frontend admin SPA (React + Vite + Tailwind + TanStack Query + MapLibre GL JS) propose 17 pages Phase 1 (Dashboard, Carte coverage 3 modes, Liste entreprises 9 vues, Détail entreprise, Scraper Runs, LLM Router config, Rotations dashboard, Proxy providers, RGPD requests, Audit log, Anomalies, etc.) + 5 pages Phase 2 scaffoldées (Campagnes, Cold Email, LinkedIn, CRM, Analytics). Les workers scraping Node.js + Playwright + playwright-extra stealth communiquent avec Laravel via Redis (BullMQ ↔ Horizon). Le LLM Router unifie 5 providers (Anthropic, OpenAI, Mistral, OpenRouter, Ollama local) avec fallback chain, cost tracking par requête, prompt templates versionnés en DB, A/B testing — configuration runtime depuis l'admin sans redéploiement. Le système de proxies est pluggable (interface `ProxyProvider`), 4 providers démarrent : Webshare (datacenter), IPRoyal (résidentiel), Smartproxy (premium), BrightData (massif), routés intelligemment selon domaine cible × budget × santé × historique. Monitoring : Grafana + Prometheus + Loki + Tempo + GlitchTip + Uptime Kuma auto-hébergés. Conformité : registre RGPD intégré, AI Act register, OWASP top 10, audit logs append-only hash chain. Déploiement : Docker Compose en dev, Coolify v4 ou k3s en prod, GitHub Actions pour CI/CD, Caddy pour HTTPS auto Let's Encrypt. Budget mensuel cible Phase 1 : **600-700€/mois tout compris**, soit ~0,003€ par entreprise enrichie.

---

## Schéma ASCII flux global

```
                          ┌─────────────────────────────────────────────────────────────────┐
                          │                  CONSOLE ADMIN UNIQUE (React 19)                │
                          │  Carte FR │ Companies │ Contacts │ LLM cfg │ Rotations │ RGPD   │
                          └────────────────────────────┬────────────────────────────────────┘
                                                       │ HTTPS + Sanctum cookie SPA
                                                       ▼
              ┌────────────────────────────────────────────────────────────────────────────┐
              │                       LARAVEL 12 API  (PHP 8.3, Horizon)                   │
              │  Controllers  │  Spatie Permission  │  RLS injection  │  Audit log hash    │
              └─┬─────┬──────────────────┬───────────────────┬──────────────────┬─────┬─────┘
                │     │                  │                   │                  │     │
        ┌───────┘     │                  │                   │                  │     └────────────┐
        ▼             ▼                  ▼                   ▼                  ▼                  ▼
   ┌─────────┐  ┌──────────┐    ┌────────────────┐   ┌──────────────┐   ┌──────────────┐   ┌──────────────┐
   │PostgreSQL│  │  Redis 7 │    │   LLM Router  │   │   Workers     │   │   Workers    │   │  Workers    │
   │   16     │  │  cache + │    │ Anthropic     │   │  Laravel      │   │  Node.js 22  │   │  Node.js    │
   │ +pg_trgm │  │  queues  │    │ OpenAI        │   │  Horizon      │   │  Playwright  │   │  Playwright │
   │ +postgis │  │ +BullMQ  │    │ Mistral       │   │ insee-fetch   │   │ gmaps-scrape │   │ pj-scrape   │
   │ +pgvector│  │  bridge  │    │ OpenRouter    │   │ annu-enrich   │   │ website-craw │   │ linkedin-pb │
   │+pg_partman│ │          │    │ Ollama local  │   │ bodacc-check  │   │ social-light │   │ crunchbase  │
   │  RLS ON  │  │          │    │ Fallback chain│   │ email-validate│   │ ...          │   │ ...          │
   └─────────┘  └──────────┘    └────────────────┘   └──────────────┘   └──────────────┘   └──────────────┘
                                                                          │       │
                                                                          ▼       ▼
                                          ┌─────────────────────────────────────────────────┐
                                          │   Proxy Router  (Webshare, IPRoyal, Smartproxy, │
                                          │   BrightData) + User-agents + Stealth plugins   │
                                          └─────────────────────────────────────────────────┘
                                                              │
                                                              ▼
                              ┌─────────────────────────────────────────────────────────────┐
                              │            14 SOURCES (toutes gratuites sauf LinkedIn)       │
                              │  INSEE • annuaire-entreprises • BODACC • France Travail •  │
                              │  Google Maps • Pages Jaunes • Sites web • LinkedIn (PB) •  │
                              │  MESRI/ONISEP • Crunchbase • Infogreffe • Societe.com •    │
                              │  BAN api-adresse • Social light (X/IG/TikTok/YT/FB)        │
                              └─────────────────────────────────────────────────────────────┘

  Observabilité transverse : Prometheus → Grafana, Loki (logs JSON), Tempo (traces), GlitchTip (errors), Uptime Kuma (synthetic).
  Conformité transverse    : audit_logs hash chain, data_processing_log, ai_act_register, opt_out cross-workspace.
```

---

## Clarification stricte Phase 1 vs Phase 2

> **Cette clarification est CRITIQUE pour Claude Code lors de la génération.**

### Phase 1 — IMPLÉMENTÉE complètement dans la spec ET dans le code V1

- ✅ Scraping multi-sources (14 sources)
- ✅ Enrichissement waterfall 9 étapes
- ✅ Classification automatique LLM (10 use cases actifs)
- ✅ Coverage Matrix + Carte France interactive (3 modes)
- ✅ Email finder + validation SMTP cascade
- ✅ LLM Router multi-providers configurable runtime
- ✅ Auth Sanctum + 2FA + magic link
- ✅ Multi-tenant + RLS PostgreSQL + RBAC Spatie
- ✅ Monitoring complet (Grafana + Prometheus + Loki + Tempo + GlitchTip + Uptime Kuma)
- ✅ Conformité RGPD + AI Act + OWASP
- ✅ Console admin React — 17 pages fonctionnelles
- ✅ Détection prospects + signaux business (jobs nightly)

### Phase 2 — SCAFFOLDÉE en V1 (DB + UI prêtes, logique métier vide)

- 🟡 Tables DB créées avec `COMMENT ON TABLE: 'Phase 2 scaffold — créée pour structure future, pas de logique métier active'`
- 🟡 Routes API exposées avec réponse 501 Not Implemented + types Spatie Data définis
- 🟡 Pages React placeholders affichant "Module en développement — sera activé en Phase 2"
- 🟡 Events Laravel définis (`ContactReadyForColdEmail`, `LeadScored`, etc.) mais pas de listener actif
- 🟡 Queues Horizon nommées (`cold-email-send`, `linkedin-outreach-message`, etc.) mais workers vides

**Modules Phase 2 scaffoldés :**
- Campagnes (orchestrateur global multi-canal)
- Cold Email Hub (séquences, templates, sending domains, SMTP IPs, warmup, deliverability tracking)
- LinkedIn Outreach Hub (campagnes, templates, comptes Sales Nav, analytics)
- CRM Hub (pipeline kanban, deals, activités, tâches, reports)
- Analytics avancées (funnels, cohorts, ROI)

**Doctrine de scaffold.** Aucun code métier Phase 2 ne doit être écrit en V1. L'objectif est uniquement de figer la structure (DB + interfaces + UI placeholders) pour qu'une activation Phase 2 future ne nécessite ni migration de données, ni refactor de routes, ni redessin de l'UI. Le scaffold doit "passer le test du lecteur naïf" : un nouveau dev arrivant sur le code doit voir où Phase 2 ira sans qu'on lui explique.

---

## 3 propositions de nom de domaine pour la console admin

> **Contraintes :** sobre, interne, non-consumer (les opérateurs et Williams sont les seuls utilisateurs), pas confusable avec axion-ia.com, hébergeable sur compte Hetzner différent.

### Proposition 1 — `crm.axion-ia.com` (sous-domaine du domaine principal)

- **Avantages :** lien de marque évident, gratuit (déjà possédé), SSL Let's Encrypt simple, DNS sous contrôle Will.
- **Inconvénients :** lie subtilement les deux infras (un visiteur curieux du site marketing peut résoudre le sous-domaine et tomber sur le login admin). Risque mineur OWASP (information disclosure) — mitigation : Cloudflare devant + auth obligatoire + pas d'index public.
- **Compte Hetzner :** différent (Compte 2), IP différente. Le sous-domaine pointe vers IP Compte 2.
- **Verdict :** ✅ pragmatique, économique, faible risque si bien configuré.

### Proposition 2 — `console.axion-crm.com` (domaine dédié court)

- **Avantages :** nom de marque séparé "Axion CRM" plus tard exploitable si Williams ouvre la plateforme à d'autres workspaces (option commerciale "Axion CRM Pro pour cabinets de conseil IA"). Pas de lien DNS avec axion-ia.com. Console explicite.
- **Inconvénients :** coût ~12€/an, communication interne moins fluide ("c'est où déjà ?").
- **Compte Hetzner :** différent (Compte 2), IP différente.
- **Verdict :** ✅ propre, future-proof, faible coût.

### Proposition 3 — `ops.axion-ia.fr` (.fr pour interne FR uniquement)

- **Avantages :** TLD `.fr` signe "interne FR" sans ambiguïté, nom court, peu coûteux.
- **Inconvénients :** crée un 3e domaine à gérer pour Will (axion-ia.com + axion-ia.eu déjà rejeté + .fr nouveau), risque de confusion DNS.
- **Compte Hetzner :** différent.
- **Verdict :** 🟡 acceptable mais introduit une nouvelle convention de domaine sans gain majeur sur P1.

### Recommandation finale

**Proposition 1 — `crm.axion-ia.com`**, pour les 4 raisons :
1. **Coût zéro** sur le domaine.
2. **Mise en route plus rapide** (Will modifie 1 enregistrement DNS A chez Namecheap, c'est tout).
3. **Cohérence de marque** pour usage interne (lui-même).
4. **Future option commerciale ouverte** : si Axion CRM Pro est plus tard offert à d'autres workspaces, on rachètera `axion-crm.com` ou `axioncrm.com` à ce moment-là — décision business reportée sans surcoût technique.

**Configuration cible :**
- DNS Namecheap : `crm.axion-ia.com → A → <IP_Hetzner_Compte_2>` + Cloudflare proxy ON
- TLS : Caddy auto Let's Encrypt
- Cloudflare : règle "Bot Fight Mode ON", "AI Scrapers OFF" (on AI-scrape soi-même), HSTS 12 mois preload, SSL Full strict
- Auth : page de login NON indexable + IP allowlist (futur) + 2FA obligatoire

> **Décision Will confirmée avant implémentation S1.** Si Will souhaite tester la proposition 2 (`console.axion-crm.com`), le coût d'un changement reste limité à 1 ligne DNS + 1 var d'env `APP_URL`.

---

## Décisions adjacentes à acter en S1 (rappel pour Williams)

| Sujet | Choix par défaut | Réversibilité |
|---|---|---|
| Domaine console | `crm.axion-ia.com` | ✅ Très facile (DNS + var env) |
| Compte Hetzner | Nouveau compte dédié `axion-crm@axion-ia.com` | ⚠️ Modéré (migration serveurs) |
| TLS | Caddy auto Let's Encrypt | ✅ Facile |
| Cloudflare | ON proxy + bot fight + AI scrapers OFF | ✅ Très facile |
| Logo console | Reprend logo Axion-IA en variante "interne" (fond foncé, badge "CRM Pro") | ✅ Facile |
| Pas de logo investissement S1 | Confirmé | n/a |
| Couleurs admin | Dark theme par défaut (Tailwind `zinc-950` base, `terracotta` accent Axion-IA) | ✅ Facile |
| Police | Inter (Google Fonts self-hosted) | ✅ Facile |
| Langue UI | FR canonique. EN miroir en Phase 2. | ✅ Facile |

---

## Prochaine étape

→ Lire `02_architecture_infra.md` pour le découpage modules détaillé et le dimensionnement Hetzner du compte 2 dédié.
