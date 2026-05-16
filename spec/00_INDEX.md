# 00 — INDEX & GLOSSAIRE

> **Spec version :** v1.0
> **Date :** 2026-05-16
> **Auteur :** Architecte logiciel principal — projet Axion CRM Pro
> **Cible :** Williams Jullin (Fondateur Axion-IA) + futurs développeurs + Claude Code (génération du code)
> **Statut :** Spec Phase 1 + Scaffold Phase 2 — VERROUILLÉE pour démarrage implémentation V1

---

## Le projet en un paragraphe

**Axion CRM Pro** est la plateforme B2B interne de prospection bout en bout du cabinet IA opérationnel **Axion-IA**. Elle scrape 14 sources gratuites (INSEE, annuaire-entreprises.data.gouv.fr, BODACC, France Travail, Google Maps, Pages Jaunes, sites web, LinkedIn via PhantomBuster, etc.), enrichit chaque entreprise française cible (TPE/PME/ETI/GE + écoles + universités) via un waterfall de 9 étapes incluant un email finder + validation SMTP, classifie automatiquement par 10 dimensions (taille, secteur NAF, géographie, maturité IA, offre Axion-IA recommandée, priorité contact, signaux business…) et affiche tout dans une console admin React unique avec carte de France interactive 3 modes (visualisation, recherche, action). Volume cible mois 1 : **200 000 entreprises enrichies** (~7 000/jour), croissance vers 1M/mois en année 1. La Phase 2 (Cold Email, LinkedIn Outreach, CRM, Analytics avancées, Orchestrateur de campagnes) est **scaffoldée dès V1** (DB + UI prêtes, logique métier vide) pour activation sans refactor. Stack : Laravel 12 + PostgreSQL 16 + Redis 7 + workers Node.js 22 + Playwright + React 19 + Vite 6 + MapLibre GL JS, déployée sur Hetzner Frankfurt (compte dédié, indépendance totale d'axion-ia.com), pilotée à 100 % depuis la console admin (zéro SSH, zéro CLI, zéro intervention manuelle technique pour les opérateurs).

---

## Les 24 fichiers de cette spec

### Bloc 1 — Fondations (5 fichiers)

| # | Fichier | Description en 1 ligne |
|---|---|---|
| 00 | [`00_INDEX.md`](./00_INDEX.md) | Sommaire complet, glossaire 60+ termes, convention de nommage, modes de lecture |
| 01 | [`01_thinking_executive_naming.md`](./01_thinking_executive_naming.md) | Chain-of-thought visible (risques + décisions clés) + executive summary métier/technique + ASCII flux + 3 propositions de nom de domaine console |
| 02 | [`02_architecture_infra.md`](./02_architecture_infra.md) | Diagramme ASCII détaillé + découpage modules + dimensionnement Hetzner (Compte 2 dédié) + réseau privé vSwitch + isolation totale d'axion-ia.com |
| 03 | [`03_db_schema_phase1.md`](./03_db_schema_phase1.md) | DB schema Phase 1 COMPLET — ~30 tables PostgreSQL 16 avec FK, indexes, RLS, partitionnement pg_partman, commentaires SQL |
| 04 | [`04_db_schema_phase2_scaffold.md`](./04_db_schema_phase2_scaffold.md) | DB schema Phase 2 SCAFFOLDÉ — ~30 tables (Campagnes / Cold Email / LinkedIn / CRM / Analytics) avec FK Phase 1, RLS, commentaires |

### Bloc 2 — Couche scraping (3 fichiers)

| # | Fichier | Description en 1 ligne |
|---|---|---|
| 05 | [`05_scrapers_14_sources.md`](./05_scrapers_14_sources.md) | Spec exhaustive des 14 sources : objectif, champs, méthode, proxies, code PHP/TS, mapping DB, pagination sans limite, classification emails exhaustive |
| 06 | [`06_email_finder_validation.md`](./06_email_finder_validation.md) | Génération patterns (15+ variantes), extraction TOUS emails classifiés, validation SMTP cascade 5 niveaux, TTL 30j, code PHP réel |
| 07 | [`07_llm_router.md`](./07_llm_router.md) | LLM Router unifié 5 providers (Anthropic / OpenAI / Mistral / OpenRouter / Ollama local), fallback chain, prompt templates versionnés, cost tracking |

### Bloc 3 — Orchestration (3 fichiers)

| # | Fichier | Description en 1 ligne |
|---|---|---|
| 08 | [`08_waterfall_enrichissement_classification.md`](./08_waterfall_enrichissement_classification.md) | Waterfall 9 étapes + state machine Spatie + parallélisation + classification LLM (maturité IA + offres Axion-IA + tags + mots-clés stratégiques) |
| 09 | [`09_proxy_pluggable_system.md`](./09_proxy_pluggable_system.md) | Interface PHP `ProxyProvider` + 4 implémentations (Webshare, IPRoyal, Smartproxy, BrightData) + routeur intelligent + UI admin + stratégie évolutive |
| 10 | [`10_rotations_universelles.md`](./10_rotations_universelles.md) | 5 dimensions de rotation : proxies, user-agents, cibles géo/sectorielles, comptes LinkedIn, LLM providers, avec weighted round-robin + health checks |

### Bloc 4 — Interface (3 fichiers)

| # | Fichier | Description en 1 ligne |
|---|---|---|
| 11 | [`11_carte_france_interactive.md`](./11_carte_france_interactive.md) | Carte France interactive 100 % gratuite (MapLibre + OpenFreeMap + IGN + BAN), 3 modes (visualization/search/action), composant React complet |
| 12 | [`12_coverage_matrix_deduplication.md`](./12_coverage_matrix_deduplication.md) | Coverage matrix 10 dimensions (mat. view) + déduplication 6 niveaux + algo "prochaine zone à attaquer" avec priority_score |
| 13 | [`13_ui_admin_phase1.md`](./13_ui_admin_phase1.md) | Console admin React — 17 pages Phase 1 implémentées + 5 pages Phase 2 scaffoldées + 9 vues d'organisation données, wireframes textuels détaillés |

### Bloc 5 — Production-ready (4 fichiers)

| # | Fichier | Description en 1 ligne |
|---|---|---|
| 14 | [`14_api_routes_laravel.md`](./14_api_routes_laravel.md) | 70+ endpoints REST Laravel — auth, workspaces, companies, contacts, scraper runs, coverage, LLM, rotations, GDPR, Phase 2 (501 NI) |
| 15 | [`15_auth_multitenant_rbac.md`](./15_auth_multitenant_rbac.md) | Sanctum cookie SPA + TOTP 2FA + magic link + middleware workspace_id + RLS PostgreSQL + Spatie Permission (4 rôles) + audit log hash chain |
| 16 | [`16_monitoring_observabilite.md`](./16_monitoring_observabilite.md) | 40+ métriques Prometheus + 10 dashboards Grafana + Alertmanager rules + Slack/Telegram + anomaly detection nightly |
| 17 | [`17_rgpd_aiact_owasp.md`](./17_rgpd_aiact_owasp.md) | Registre RGPD rempli + procédures droits CNIL + audit log hash chain + AI Act register + OWASP top 10 appliqué |

### Bloc 6 — Infrastructure + déploiement (3 fichiers)

| # | Fichier | Description en 1 ligne |
|---|---|---|
| 18 | [`18_deploiement_hetzner.md`](./18_deploiement_hetzner.md) | Schéma Hetzner Compte 2 + docker-compose.yml + Dockerfiles multi-stage + GitHub Actions + backups + DR runbooks |
| 19 | [`19_queues_workers_playwright.md`](./19_queues_workers_playwright.md) | 16 queues Horizon + workers Laravel + workers Node Playwright stealth + BullMQ + graceful shutdown |
| 20 | [`20_detection_nouveaux_prospects_signaux.md`](./20_detection_nouveaux_prospects_signaux.md) | Jobs nightly INSEE + BODACC + France Travail + scraping news + stockage `company_business_signals` + notifications Slack/Telegram |

### Bloc 7 — Exécution (3 fichiers FINAUX)

| # | Fichier | Description en 1 ligne |
|---|---|---|
| 21 | [`21_couts_roadmap.md`](./21_couts_roadmap.md) | Coûts mensuels détaillés (~600-700€/mois, ~0,003€/entreprise enrichie) + roadmap 12 semaines avec critères "done" |
| 22 | [`22_risques_mitigations.md`](./22_risques_mitigations.md) | Top 15 risques (techniques + légaux + opérationnels) avec mitigation pour chacun |
| 23 | [`23_interfaces_phase2_execution_pack.md`](./23_interfaces_phase2_execution_pack.md) | Interfaces Phase 1↔Phase 2 + Code Generation Roadmap 12 étapes + Tests Acceptance + Data Seeders + 12 Prompts Claude Code prêts à l'emploi |

**Total : 24 fichiers** (de `00_INDEX.md` à `23_interfaces_phase2_execution_pack.md`).

---

## Glossaire (60+ termes)

> Tous les termes techniques, business, juridiques et abréviations utilisés dans la spec, dans l'ordre alphabétique.

| Terme | Définition |
|---|---|
| **AI Act** | Règlement UE 2024/1689 encadrant l'IA. Pour Axion CRM Pro, principalement les obligations de transparence et de documentation des modèles utilisés pour le profilage/scoring (table `ai_act_register`). |
| **Anti-doublon** | Politique stricte : aucune entreprise n'est scrapée 2× pour la même source dans son TTL ; aucun contact créé 2× ; aucun email validé SMTP 2× en < 30j ; aucune zone géo/sectorielle scrapée 2× dans son cooldown 24h. |
| **API Sirene** | API officielle gratuite INSEE (sirene.fr/static-resources/htm/v_sommaire.html) exposant les données SIREN/SIRET de toutes les entreprises FR. Quota 30 req/min. |
| **Append-only** | Mode d'écriture d'une table garantissant qu'aucune ligne ne peut être modifiée ni supprimée (uniquement insérée). Utilisé pour `audit_logs`. |
| **AUDIT_FLASH / AUDIT_CIBLE / MISSION_PME / MISSION_ETI / GRAND_PROGRAMME** | Les 5 offres commerciales Axion-IA. La classification automatique LLM attribue à chaque entreprise scrapée la plus pertinente (ou `NON_CIBLE`). |
| **BAN** | Base Adresse Nationale, géocodage officiel gratuit illimité (`api-adresse.data.gouv.fr`). |
| **BODACC** | Bulletin Officiel des Annonces Civiles et Commerciales. API officielle gratuite exposant les changements de dirigeants, levées de fonds, redressements, créations, radiations. Signal d'achat majeur. |
| **BullMQ** | Bibliothèque de queues Node.js basée Redis (npmjs.com/package/bullmq), utilisée côté workers Playwright. Compatible Laravel Horizon via convention de payload. |
| **CCX / CPX / CAX / CCX-Dedicated** | Familles de serveurs Hetzner Cloud. `CCX` = dedicated vCPU (recommandé prod), `CPX` = shared AMD (recommandé staging/scraping), `CAX` = ARM Ampere (économique mais incompatible Playwright stealth). |
| **Choropleth** | Carte thématique où chaque zone est colorée selon une valeur (ici : % de couverture scrapée). |
| **Circuit-broken** | Statut d'un scraper temporairement désactivé suite à un trop grand nombre d'erreurs successives (pattern Circuit Breaker). |
| **Coverage Matrix** | Vue matérialisée PostgreSQL agrégeant le % de couverture scrapée par croisement de 10 dimensions (région × NAF × taille × maturité IA × …). Refresh hourly. |
| **DBeaver / TablePlus** | Clients SQL recommandés pour explorer la DB en dev. Optionnel — toute manipulation prod doit passer par la console admin. |
| **DPO** | Délégué à la Protection des Données. Pour Axion CRM Pro : `contact@axion-ia.com` (boîte unique, pas de `dpo@` séparée — confirmé Will 2026-05-16). |
| **Email finder** | Module générant les variantes d'email plausibles d'un contact (15+ patterns) puis les validant en cascade SMTP. |
| **Email type** | Classification automatique de chaque email trouvé : `nominative` / `role_based` / `generic` / `no_reply`. Les `no_reply` sont systématiquement exclus de toute prospection. |
| **Enrichment waterfall** | Pipeline séquentiel + parallélisable de 9 étapes d'enrichissement par entreprise (identification → légal → contact → site web → C-level → email → géocodage → signaux → classification). |
| **France Travail** | Ex Pôle emploi. API officielle gratuite exposant les offres d'emploi publiées. Recrutements C-level = signal d'achat. |
| **GDPR / RGPD** | Règlement (UE) 2016/679. Base légale Axion CRM Pro : intérêt légitime prospection B2B sur emails pro nominatifs uniquement. |
| **Horizon (Laravel Horizon)** | Dashboard et superviseur officiel des queues Laravel basées Redis. UI temps réel + métriques + retries. |
| **IGN AdminExpress COG 2026** | Référentiel géographique officiel de l'IGN (Open License Etalab), millésime COG 2026 : régions, départements, communes France entière en GeoJSON simplifié via mapshaper. |
| **INSEE Sirene** | Institut National de la Statistique. Sirene = registre des entreprises. API officielle gratuite (30 req/min). Source de vérité pour SIREN/SIRET/NAF/effectif. |
| **JSONB** | Type PostgreSQL pour stocker du JSON binaire indexable (GIN). Utilisé partout où la structure varie (`meta`, `raw_event`, `payload`). |
| **LinkedIn Sales Nav** | Compte LinkedIn Sales Navigator (~75€/mois). Axion CRM Pro utilise 3 comptes minimum avec rotation et état (active / rate_limited / cooldown / suspicious / banned). |
| **LLM Router** | Service Laravel qui route chaque appel LLM vers le bon provider/modèle selon `use_case`, avec fallback chain, cost tracking, A/B testing, configuration runtime depuis admin (table `llm_use_cases`). |
| **LLM use case** | Cas d'usage métier d'un LLM dans Axion CRM Pro (10 en Phase 1, 5 en Phase 2 scaffold). Ex : `ia_maturity_scoring`, `axion_offer_match`, `extract_team_from_page`. |
| **MapLibre GL JS** | Bibliothèque WebGL open-source pour rendre des cartes vectorielles. Fork libre de Mapbox GL JS v1. Version 4.x utilisée. |
| **Materialized View** | Vue SQL matérialisée (résultat persistant rafraîchi périodiquement). Utilisée pour `coverage_matrix_cells` (refresh hourly). |
| **Maturité IA estimée** | Classification automatique LLM en 3 niveaux (`découverte` / `en cours` / `avancée`) pour qualifier le degré d'adoption IA d'une entreprise scrapée. Use case `ia_maturity_scoring`. |
| **NAF** | Nomenclature d'Activités Française (732 sous-classes, 5 niveaux : section → division → groupe → classe → sous-classe). Source officielle INSEE. |
| **NextActionScore** | Score calculé `priority_score = (target_match × 0.4) + (business_value × 0.3) + (low_coverage × 0.2) + (freshness_decay × 0.1)` qui détermine la prochaine zone à attaquer. |
| **OpenFreeMap** | Service gratuit de tiles vectorielles basé OSM (`https://tiles.openfreemap.org/styles/positron`). Pas de limite, pas d'inscription, pas de clé API. |
| **Ollama** | Serveur LLM local open-source. Axion CRM Pro héberge Llama 3.3 70B sur un serveur Hetzner GPU dédié pour les cas d'usage internes sensibles ou volumineux. |
| **OWASP top 10** | Référentiel sécurité applicative OWASP. Axion CRM Pro applique les 10 contrôles : CSP strict, HSTS, JWT short-TTL, rate limiting, secrets vaultés, input validation, etc. |
| **Pages Jaunes** | `pagesjaunes.fr`. Backup Google Maps pour téléphone + adresse + site web. Scraping Playwright + proxies résidentiels. |
| **pg_partman** | Extension PostgreSQL de gestion automatique du partitionnement par range (date). Utilisée pour `scraper_runs`, `llm_usage`, `proxy_usage_log`. |
| **pgvector** | Extension PostgreSQL ajoutant un type `vector` + index HNSW. Pas utilisée Phase 1, prévue Phase 2 pour recherche sémantique CRM. |
| **PhantomBuster** | Outil tiers SaaS d'automatisation LinkedIn (~370$/mois pour usage Axion CRM Pro). Seule source LinkedIn autorisée (scraping direct interdit par doctrine). |
| **Playwright** | Framework de test/scraping headless Microsoft (Chromium / Firefox / WebKit). Version 1.49+. Combiné à `playwright-extra` + plugins stealth pour bypass anti-bot. |
| **PostGIS** | Extension PostgreSQL ajoutant types et index spatiaux (point, polygon, ST_*). Utilisée pour géocodage entreprises et zones de couverture. |
| **PostgreSQL RLS** | Row-Level Security PostgreSQL. Chaque requête est automatiquement filtrée par `workspace_id` via policies. Garantie multi-tenant au niveau DB (pas seulement applicatif). |
| **Priorité de contact** | Score `hot` / `warm` / `cold` / `frozen` basé sur signaux business actifs (différent du score de priorité Axion-IA). |
| **Priorité Axion-IA / Score de priorité** | Score global `prioritaire` / `moyenne` / `faible` / `non-cible` calculé depuis fit produit × signaux business × maturité IA × taille pertinente. Override manuel possible. |
| **Proxy provider** | Fournisseur de proxies HTTP/HTTPS. Implémente l'interface `ProxyProvider`. Liste démarrage : Webshare (datacenter) → IPRoyal (résidentiel) → Smartproxy (premium) → BrightData (massif). |
| **RLS** | Voir PostgreSQL RLS. |
| **Sanctum (Laravel Sanctum)** | Package officiel Laravel pour auth API SPA via cookie HttpOnly + CSRF token. Pas de JWT, pas de Bearer token, pas de session-id PHP fragile. |
| **Scraper plugin** | Implémentation de l'interface `ScraperPlugin`. Chaque source des 14 est un plugin. Ajouter une 15e source = créer 1 fichier ~300 lignes. |
| **Scraper run** | Une exécution d'un scraper sur une cible. Stocké dans `scraper_runs` partitionnée par mois avec ~20 colonnes de traçabilité (status, proxy_used, llm_used, tokens, cost, contacts_found, error, raw meta). |
| **SIREN / SIRET / NIC** | Identifiants INSEE. SIREN = 9 chiffres entreprise. NIC = 5 chiffres établissement. SIRET = SIREN+NIC (14 chiffres). |
| **Signal d'achat** | Événement business indiquant qu'une entreprise est susceptible d'acheter du conseil IA dans les 0-6 mois : recrutement C-level, levée de fonds, changement dirigeant, redressement, transformation digitale annoncée. |
| **Société.com** | `societe.com`. Fallback dirigeants si annuaire-entreprises.data.gouv.fr ne suffit pas. Scraping Playwright + proxies. |
| **Spatie Data** | Package PHP de DTOs typés `spatie/laravel-data`. Utilisé pour tous les payloads API. |
| **Spatie Laravel Permission** | RBAC officiel Laravel `spatie/laravel-permission`. 4 rôles Axion CRM Pro : `owner`, `admin`, `operator`, `viewer`. |
| **Spatie State Machines** | Machine à états PHP `spatie/laravel-model-states`. Utilisée pour le waterfall d'enrichissement. |
| **Stealth plugin** | Plugin `playwright-extra-plugin-stealth` masquant les marqueurs WebDriver détectés par les anti-bots (Cloudflare, DataDome, Akamai). |
| **TanStack Query 5** | ex React Query. Cache + sync client-server React. Utilisé pour TOUTES les requêtes API frontend. |
| **TanStack Virtual** | Virtualisation listes longues React (rend uniquement les lignes visibles). Indispensable pour afficher 200k entreprises. |
| **Tier (TPE / PME / ETI / GE)** | Tailles INSEE : TPE < 10 employés, PME 10-249, ETI 250-4999, GE ≥ 5000. |
| **TLS / mTLS** | Transport Layer Security. mTLS = mutuel (cert client + cert serveur). Caddy gère TLS auto Let's Encrypt côté Axion CRM Pro. |
| **TTL** | Time-To-Live. Délai au-delà duquel une donnée est considérée périmée. Ex : TTL Google Maps = 90j (re-scrapable), TTL annuaire-entreprises = 365j, TTL site web = 30j, TTL LinkedIn = 60j. |
| **User-agent rotation** | Pool de 50+ user-agents réalistes (Chrome desktop/mobile, Firefox, Safari, Edge) tournés à chaque requête avec fingerprints cohérents (Accept-Language, Viewport, etc.). |
| **Use case (LLM)** | Voir LLM use case. |
| **Vue choropleth** | Voir Choropleth. |
| **vSwitch (Hetzner)** | Réseau privé virtuel Hetzner sans surcoût permettant communication inter-serveurs sans transit Internet public. |
| **Waterfall** | Voir Enrichment waterfall. |
| **Workspace** | Unité de multi-tenant Axion CRM Pro. Au démarrage, un seul workspace `Axion-IA` est créé. Architecture prête pour héberger d'autres workspaces à l'avenir (option commerciale). |

---

## Convention de nommage des fichiers

- Préfixe à 2 chiffres (`00_` → `23_`) qui définit l'ordre de lecture canonique de la spec
- `snake_case` pour le nom du fichier
- Suffixe `.md` (CommonMark — GitHub Flavored Markdown)
- Encodage UTF-8 sans BOM, fin de ligne LF (cf. `.gitattributes`)
- Aucun fichier de spec ne doit dépasser 4 000 mots (granularité = lecture aisée + commit Git ciblé)

---

## Comment lire cette spec

Trois parcours sont prévus selon ta cible.

### Parcours développeur / implémenteur (lecture complète obligatoire)

Lis dans l'ordre, du 00 au 23. Le fichier 23 contient les 12 prompts Claude Code prêts à l'emploi qui te permettront de générer le code Phase 1 en autonomie totale.

### Parcours exécutif / décideur (15 min)

1. [`01_thinking_executive_naming.md`](./01_thinking_executive_naming.md) — vision + risques + executive summary
2. [`21_couts_roadmap.md`](./21_couts_roadmap.md) — coûts mensuels + roadmap 12 semaines
3. [`22_risques_mitigations.md`](./22_risques_mitigations.md) — top 15 risques
4. Section "Vision" du présent `00_INDEX.md`

### Parcours Claude Code (génération code)

1. Lis le fichier 23, partie B.1 (Code Generation Roadmap)
2. Pour chaque étape, lis les sections de spec listées en "Inputs requis"
3. Utilise le prompt Claude Code prêt à l'emploi correspondant (partie B.4)
4. Vérifie les critères "done" et les tests d'acceptance avant commit

---

## Statut spec & gouvernance

- **Verrouillée pour démarrage implémentation V1.** Toute modification de la spec ≥ rev minor doit passer par un commit Git documenté `spec(XX): description`.
- **Source de vérité** : ce dossier `./spec/`. Si une divergence apparaît entre le code et la spec, c'est la spec qui prime SAUF si Williams Jullin tranche explicitement pour durcir le code (doctrine SSOT pragmatique).
- **Pas de spec orpheline** : tout fichier de code Phase 1 doit pouvoir être tracé à au moins une section de cette spec.

---

## Contacts & responsabilités

| Rôle | Personne | Contact |
|---|---|---|
| Product owner | Williams Jullin | `contact@axion-ia.com` |
| DPO | Williams Jullin (interim) | `contact@axion-ia.com` |
| Architecte logiciel | Claude Code (Opus 4.7) | n/a |
| Hosting compte | Hetzner Cloud (compte Axion CRM Pro dédié) | à créer |

---

## Lien avec le site axion-ia.com

Aucun. Indépendance technique stricte :
- Compte Hetzner différent
- Domaine différent (à arbitrer, cf. fichier 01)
- IPs différentes
- Pas de code partagé
- Pas de monorepo

Le partage du dossier parent local `C:\Users\willi\Documents\Projets\Axion-IA\` est purement organisationnel pour Williams.
