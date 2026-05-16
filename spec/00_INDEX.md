# 00 — INDEX / Sommaire / Glossaire

> **Spec :** Axion CRM Pro v6 — plateforme B2B de prospection automatisée pour Axion-IA.
> **Date :** 2026-05-16
> **Auteur de la spec :** Williams Jullin (Axion-IA OÜ)
> **Format :** 24 fichiers Markdown denses dans `./spec/`, ordonnés.
> **Statut :** Spec exhaustive — implémentation à venir.

---

## Comment lire cette spec

1. **Lecture séquentielle recommandée** — les fichiers s'enchaînent logiquement (fondations → scraping → orchestration → UI → production → infra → exécution).
2. **Chaque fichier est autonome** — il contient le contexte nécessaire pour être lu seul, sauf renvois explicites (« cf. fichier XX »).
3. **Code = exécutable** — tous les blocs SQL/PHP/TypeScript sont écrits pour être copiés tels quels et compiler. Pas de pseudo-code abstrait, sauf indication contraire.
4. **Numéros de fichiers stables** — les noms ne changeront jamais (`03_db_schema_phase1.md` reste `03` même si on insère plus tard). Toute insertion = suffixe `_bis`.
5. **Liens internes** — utiliser `./XX_nom.md#ancre` pour pointer une section précise.
6. **Conventions d'écriture** — voir `Conventions` plus bas.

## Sommaire des 24 fichiers

### Bloc 1 — Fondations (5 fichiers)

| # | Fichier | Rôle | Lignes |
|---|---------|------|--------|
| 01 | `00_INDEX.md` | Sommaire + glossaire + conventions (ce fichier) | ~400 |
| 02 | `01_thinking_executive_naming.md` | Chain-of-thought architecte (8 risques + 8 décisions) + executive summary + flux ASCII + 3 propositions nom domaine | ~600 |
| 03 | `02_architecture_infra.md` | Diagramme infra ASCII + modules + stack précise + dimensionnement Hetzner + isolation totale d'axion-ia.com | ~700 |
| 04 | `03_db_schema_phase1.md` | ~32 tables PostgreSQL 16 exécutables (FK, indexes, RLS, partitionnement pg_partman) | ~1500 |
| 05 | `04_db_schema_phase2_scaffold.md` | ~30 tables Phase 2 scaffoldées (campagnes, cold email, LinkedIn outreach, CRM, analytics) | ~1000 |

### Bloc 2 — Couche scraping (3 fichiers)

| # | Fichier | Rôle | Lignes |
|---|---------|------|--------|
| 06 | `05_scrapers_14_sources.md` | 14 sources détaillées + spec Google Search Wrapper + spec Direction Finder (ETI/Grandes) | ~1800 |
| 07 | `06_email_finder_validation.md` | Patterns 15+ variantes + extraction exhaustive + détection pattern entreprise + cascade SMTP N1→N5 + scoring 0-100 | ~900 |
| 08 | `07_llm_router.md` | Interface `LLMClient` PHP + 5 providers + fallback chain + prompt templates versionnés + A/B testing + cost tracking | ~900 |

### Bloc 3 — Orchestration (3 fichiers)

| # | Fichier | Rôle | Lignes |
|---|---------|------|--------|
| 09 | `08_waterfall_enrichissement_classification.md` | State machine 10 étapes Spatie + parallélisation + classification LLM + tags | ~800 |
| 10 | `09_proxy_pluggable_system.md` | Interface `ProxyProvider` + 4 implémentations (Webshare, IPRoyal, Smartproxy, BrightData) + routeur intelligent | ~700 |
| 11 | `10_rotations_universelles.md` | 5 dimensions de rotation (proxies, UA, cibles, moteurs recherche, LLM) + weighted round-robin + health checks | ~700 |

### Bloc 4 — Interface (3 fichiers)

| # | Fichier | Rôle | Lignes |
|---|---------|------|--------|
| 12 | `11_carte_france_interactive.md` | MapLibre + OpenFreeMap + IGN AdminExpress + BAN + composant React 3 modes (visu/recherche/action) | ~700 |
| 13 | `12_coverage_matrix_deduplication.md` | Materialized view rollup + anti-doublon 6 niveaux + fuzzy matching pg_trgm + algo « prochaine zone » | ~800 |
| 14 | `13_ui_admin_phase1.md` | 17 pages Phase 1 + 5 pages Phase 2 scaffold + wireframes textuels détaillés | ~1500 |

### Bloc 5 — Production-ready (4 fichiers)

| # | Fichier | Rôle | Lignes |
|---|---------|------|--------|
| 15 | `14_api_routes_laravel.md` | 60-80 endpoints REST + DTOs Spatie Data + rate limiting + Phase 2 stubs 501 | ~1100 |
| 16 | `15_auth_multitenant_rbac.md` | Sanctum SPA cookie + 2FA TOTP + magic link + RLS policies + Spatie Permission + audit hash chain | ~800 |
| 17 | `16_monitoring_observabilite.md` | 40+ métriques Prometheus + 10 dashboards Grafana + Alertmanager rules + logs structurés Loki | ~800 |
| 18 | `17_rgpd_aiact_owasp.md` | Registre RGPD + droit accès/suppression SQL transaction + audit hash chain PHP + AI Act register + checklist OWASP top 10 | ~700 |

### Bloc 6 — Infrastructure + déploiement (3 fichiers)

| # | Fichier | Rôle | Lignes |
|---|---------|------|--------|
| 19 | `18_deploiement_hetzner.md` | Schéma IPs + vSwitch + docker-compose maître + Dockerfiles multi-stage + GH Actions + backups + DR RPO 1h/RTO 4h | ~1100 |
| 20 | `19_queues_workers_playwright.md` | Queues Horizon exhaustives + workers Laravel/PHP + workers Node/Playwright + bridge Redis | ~900 |
| 21 | `20_detection_nouveaux_prospects_signaux.md` | Jobs nightly INSEE/BODACC/France Travail + scraping news FR hebdo + notifications Slack/Telegram | ~600 |

### Bloc 7 — Exécution (3 fichiers finaux)

| # | Fichier | Rôle | Lignes |
|---|---------|------|--------|
| 22 | `21_couts_roadmap.md` | Tableau coûts mensuels détaillé + roadmap 12 semaines avec critères « done » | ~600 |
| 23 | `22_risques_mitigations.md` | Top 15 risques techniques + légaux + opérationnels avec mitigation chacun | ~700 |
| 24 | `23_interfaces_phase2_execution_pack.md` | Interfaces Phase 2 + Code Gen Roadmap 12 étapes + Tests AC + Seeders + 12 prompts Claude Code prêts à l'emploi | ~2000 |
| 25 | `24_frontend_design_system.md` (**v1.2**) | Design tokens + empty/loading states + error boundaries + toast + form patterns + responsive mobile/tablette/desktop + onboarding + saved views + notifications + ⌘K + print PDF | ~1500 |

**Volume total estimé v1.2 :** ~22 000 lignes / ~67 000 mots de spec dense.

---

## Glossaire (54 termes)

### Métier

- **Axion-IA** — Cabinet IA opérationnel B2B (OÜ estonienne, fondateur Williams Jullin, site `axion-ia.com`). Client unique de Axion CRM Pro au démarrage.
- **Axion CRM Pro** — Le projet courant. Plateforme INTERNE de prospection multi-canal. Distincte d'axion-ia.com (compte Hetzner, domaine, secrets séparés).
- **Cabinet IA opérationnel** — Naming canonique d'Axion-IA (FR) / *operational AI consultancy* (EN). Jamais « agence/studio/atelier ».
- **Offre Axion-IA** — Catalogue de prestations : *Audit Flash*, *Audit Ciblé* (Essentielle 490/790/1190 €, Approfondie 890/1390/1990 €), *Mission PME*, *Mission ETI*, *Grand programme*. Voir `pricing.ts` SSOT côté Axion-IA.
- **Prospect chaud / tiède / froid / gelé** — Catégorisation contact pour priorisation outreach.

### Cibles

- **TPE** — Très Petite Entreprise (INSEE : 1-19 salariés). ~4 M entités en France.
- **PME** — Petite et Moyenne Entreprise (INSEE : 20-249 salariés). ~150 k entités.
- **ETI** — Entreprise de Taille Intermédiaire (250-4 999 salariés). ~6 000 entités. *Direction Finder activé automatiquement.*
- **Grande entreprise** — 5 000+ salariés. ~280 entités. *Direction Finder + best-effort.*
- **Artisans / Commerçants** — Sous-segment TPE (1-9 salariés). ~1,5 M entités. Dirigeant légal = décideur.
- **Dirigeant légal** — Personne déclarée comme représentant légal au RNCS (gérant, président, PDG selon forme juridique).
- **C-level** — Cadre dirigeant (DRH/CHRO, DAF/CFO, DSI/CIO, Directeur Marketing/CMO, Directeur Commercial/CCO). Cible Direction Finder.
- **Décideur cible** — Personne qui peut signer un devis Axion-IA. Variable selon la taille (dirigeant légal pour TPE/PME, C-level pour ETI/Grandes).

### Sources & données

- **INSEE Sirene** — API officielle (rate-limited) — identification + filtrage de masse.
- **annuaire-entreprises.data.gouv.fr** — API + scraping — remplace Pappers (gratuit, données légales fraîches : dirigeants, CA, bilans, bénéficiaires effectifs).
- **BODACC** — Bulletin Officiel des Annonces Civiles et Commerciales — signaux business (changements, levées, redressements).
- **NAF** — Nomenclature d'Activités Française (732 codes sur 5 niveaux : sections, divisions, groupes, classes, sous-classes).
- **SIREN** — Identifiant entreprise français unique (9 chiffres). Clé primaire métier.
- **SIRET** — Identifiant établissement (14 chiffres = SIREN + 5 chiffres). Plusieurs SIRET par SIREN.
- **BAN** — Base Adresse Nationale (`api-adresse.data.gouv.fr`) — géocodage officiel, gratuit, illimité.
- **IGN AdminExpress COG 2026** — Référentiel polygones administratifs (régions + départements + communes), licence ouverte Etalab.
- **OpenFreeMap** — Service de tuiles vectorielles OSM gratuit illimité (`tiles.openfreemap.org`).

### Scraping & enrichissement

- **Waterfall** — Pipeline 10 étapes d'enrichissement séquentiel (identification → légal → contact → site web → LinkedIn → Direction Finder → email finder → géocodage → signaux → classification).
- **Google Search Wrapper** — Module maison de récupération d'URLs LinkedIn via dorking Google/Bing/DuckDuckGo (remplace PhantomBuster).
- **Direction Finder** — Module activé automatiquement si effectif > 100 — récupère les C-level via pages corporate + presse + rapport annuel + Google Search étendu.
- **Email finder** — Algorithme de génération de variantes email + validation SMTP cascade.
- **Validation SMTP cascade** — 5 niveaux : N1 syntaxe → N2 DNS MX → N3 SMTP handshake → N4 catch-all detection → N5 scoring 0-100.
- **Pattern email** — Format détecté pour une entreprise : `{first}.{last}@domain.tld`, `{f}{last}@`, etc. 15+ patterns supportés.
- **Catch-all** — Serveur SMTP qui accepte tous les emails de son domaine sans vérifier l'existence. Détection obligatoire pour éviter faux positifs.
- **Signal d'achat** — Indicateur business (levée de fonds, recrutement, nomination, déménagement) suggérant un budget disponible.

### Architecture technique

- **Workspace** — Tenant logique. Toute donnée scoped par `workspace_id`. Démarrage mono-tenant (Axion-IA), architecture prête multi-tenant.
- **RLS** — Row-Level Security (PostgreSQL) — isolation des lignes par workspace au niveau DB, en complément des filtres applicatifs.
- **RBAC** — Role-Based Access Control. Via Spatie Laravel Permission. 4 rôles : owner, admin, operator, viewer.
- **Sanctum SPA** — Mode d'authentification Laravel Sanctum cookie-based pour SPA (vs token-based pour API mobile).
- **TOTP 2FA** — Time-based One-Time Password (Google Authenticator, Authy). Obligatoire pour tous les utilisateurs.
- **Magic link** — Lien email à usage unique pour login passwordless.
- **Audit log hash chain** — Journal d'audit append-only avec chaînage cryptographique (chaque ligne contient le hash de la précédente). Détection de tampering.
- **Horizon** — Dashboard Laravel pour monitorer Redis queues.
- **BullMQ** — Bibliothèque Node.js de queues sur Redis. Côté workers Playwright.
- **Bridge Redis** — Convention de noms de queues partagés permettant à Laravel (Horizon) et Node (BullMQ) de communiquer via Redis.

### LLM & IA

- **LLM Router** — Couche d'abstraction unifiée pour appeler n'importe quel LLM. Configurable runtime via table `llm_use_cases`.
- **Use case LLM** — Tâche métier (ex : `sector_classification`, `extract_team_from_page`). Mappée à un provider/model + prompt template + paramètres.
- **Prompt template versionné** — Template stocké en DB (table `prompt_templates` + `prompt_template_versions`). Permet A/B testing et rollback.
- **Fallback chain** — Suite ordonnée de providers à essayer si le premier échoue (rate limit, panne, timeout).
- **Cost tracking** — Comptabilisation tokens consommés + coût € par requête LLM. Stockée dans `llm_usage`.
- **AI Act** — Règlement européen sur l'IA (2024). Profilage automatisé documenté en table `ai_act_register`.

### Anti-doublon & qualité

- **Anti-doublon strict (6 niveaux)** — Garantie qu'aucune donnée n'est scrapée ou payée deux fois. Niveaux : SIREN, contact, scraping jobs TTL, coverage cells cooldown, validation email, opt-out cross-workspace.
- **Coverage Matrix** — Materialized view rollup hourly croisant département × NAF × tranche effectif. Sert à : (1) calcul du taux de couverture, (2) sélection automatique de la « prochaine zone à attaquer ».
- **Cooldown** — Délai minimum entre deux tentatives sur la même cellule géo+secteur+taille. 24h par défaut.
- **TTL revalidation** — Durée pendant laquelle un scraping est considéré « frais » et n'est pas redéclenché. Configurable par source (7j → 365j).
- **Fuzzy matching** — Détection de quasi-doublons via pg_trgm. Seuil 0.85+. Stockage en `duplicate_flags` pour validation humaine.
- **Score qualité de fiche** — 🟢 complète / 🟡 partielle / 🔴 basique. Calculé selon données récupérées (email validé, nom décideur, téléphone, LinkedIn).
- **Opt-out cross-workspace** — Table globale (pas scopée workspace). Si une personne demande à ne plus être contactée, elle l'est sur TOUS les workspaces (conformité RGPD).

### Infra & déploiement

- **Hetzner Cloud** — Hébergeur allemand. Datacenters Frankfurt/Nuremberg/Helsinki. UE/RGPD natif.
- **Coolify v4** — PaaS auto-hébergé open-source. Alternative Vercel/Render. Utilisé par Axion-IA mais Axion CRM Pro pourra basculer k3s si volume justifie.
- **Caddy** — Reverse proxy HTTPS auto (Let's Encrypt). Alternative Traefik.
- **vSwitch** — Réseau privé Hetzner entre serveurs du même compte. Pas de coût bande passante intra-vSwitch.
- **Object Storage** — Stockage S3-compatible Hetzner. Utilisé pour backups + assets.
- **Backblaze B2** — Backup off-site secondaire (3-2-1 rule).
- **GlitchTip** — Alternative open-source à Sentry. Auto-hébergé.
- **Uptime Kuma** — Monitoring uptime open-source. Auto-hébergé.

### Conventions de spec

- **🟢 / 🟡 / 🔴** — Statut implémentation (vert = fait, jaune = scaffold, rouge = non fait).
- **« STOP & ASK »** — Décision attendue côté humain. Ne pas inventer.
- **« RUNTIME-CONFIG »** — Paramètre modifiable depuis l'admin sans redéploiement.
- **« WORKER-NODE »** — Composant Node.js (Playwright). Distinct des **« WORKER-LARAVEL »** (PHP).

---

## Conventions d'écriture

### Versions précises obligatoires

- Laravel : **12.x** (dernière stable)
- PHP : **8.3+** (8.4 supporté)
- PostgreSQL : **16.x**
- Redis : **7.2+**
- Node.js : **22 LTS**
- Playwright : **1.49+**
- React : **19.x**
- TypeScript : **5.6+**
- Vite : **6.x**
- Tailwind : **4.x**
- MapLibre GL JS : **4.x**

### Chiffres concrets obligatoires

Toute affirmation chiffrée doit être étayée : € (HT, mensuel sauf indication), ms (P50/P95/P99), req/s, MB/GB, % succès. Pas de « rapide », « gros », « bcp ».

### Justification décisions

Pour chaque choix non évident, ajouter 1-2 lignes max après le titre : « *Pourquoi X : raison. Trade-off : Y.* »

### Code

- **PHP** — PSR-12 + Laravel coding standards.
- **TypeScript** — `strict: true`, ESLint Airbnb + Prettier.
- **SQL** — UPPERCASE keywords, snake_case identifiers, lower-snake_case noms tables/colonnes.

### Tableaux

Markdown standard. Pas de colspan/rowspan (non rendu uniformément).

### Diagrammes

ASCII art uniquement (rendu universel terminal/GitHub). Pas de Mermaid (rendu inégal).

### Liens externes

URL exacte avec date d'accès si la doc est volatile (`(consulté 2026-05-16)`).

### Si information manquante

Prendre la meilleure décision en justifiant en 1 ligne dans le fichier (« *Décision faute de spec explicite : X. À valider Will.* »). Ne jamais bloquer.

---

## Statuts d'implémentation par module

| Module | Statut spec | Statut code |
|--------|-------------|-------------|
| Auth + RBAC + 2FA | 🟢 Spec complète | ⏳ À coder |
| Scraping INSEE | 🟢 Spec complète | ⏳ |
| Scraping annuaire-entreprises | 🟢 Spec complète | ⏳ |
| Scraping Google Maps / Pages Jaunes | 🟢 Spec complète | ⏳ |
| Scraping sites web | 🟢 Spec complète | ⏳ |
| Google Search Wrapper (LinkedIn URLs) | 🟢 Spec complète | ⏳ |
| Direction Finder (ETI/Grandes) | 🟢 Spec complète | ⏳ |
| Email finder + validation SMTP | 🟢 Spec complète | ⏳ |
| LLM Router multi-providers | 🟢 Spec complète | ⏳ |
| Carte France interactive | 🟢 Spec complète | ⏳ |
| Coverage Matrix + dedup 6 niveaux | 🟢 Spec complète | ⏳ |
| UI admin (17 pages Phase 1) | 🟢 Spec complète | ⏳ |
| Cold Email (Phase 2) | 🟡 Scaffold DB + UI | ❌ |
| LinkedIn Outreach (Phase 2) | 🟡 Scaffold DB + UI | ❌ |
| CRM pipeline (Phase 2) | 🟡 Scaffold DB + UI | ❌ |
| Analytics avancées (Phase 2) | 🟡 Scaffold DB + UI | ❌ |

---

## Documents externes liés

- **Doctrine technique Axion-IA** — voir `axion-ia.com` repo `_AUDIT/` (audits frontend V14, SEO/AEO/GEO, certification, etc.). Hérités mais adaptés à un produit interne (vs site marketing).
- **AI Act (UE)** — Règlement (UE) 2024/1689. Annexe III pour profilage automatisé.
- **RGPD** — Règlement (UE) 2016/679. Articles 6 (intérêt légitime B2B), 15-22 (droits personnes), 30 (registre traitements).
- **OWASP Top 10 (2021)** — `owasp.org/Top10/`. Checklist appliquée dans `17_rgpd_aiact_owasp.md`.

---

## FAQ — questions probables du dev qui implémente

> **Q : J'ai une question critique, je fais quoi ?**
> R : Prendre la meilleure décision en 1 ligne justifiée dans le fichier concerné. Si vraiment bloquant, marquer `// STOP & ASK Will: ...` dans le code + créer un commit isolé.

> **Q : Je peux changer de stack si j'estime que c'est mieux ?**
> R : Non sans validation. La stack est arrêtée. Tu peux remonter une proposition ADR dans `_AUDIT/`.

> **Q : Je peux utiliser un service payant non listé ?**
> R : Non. Tous les services payants sont listés dans `21_couts_roadmap.md`. Toute ajout = validation Will.

> **Q : Comment je gère les conflits multi-tenant ?**
> R : Toujours filtrer par `workspace_id` + RLS PostgreSQL en double sécurité. Voir `15_auth_multitenant_rbac.md`.

> **Q : Et si une source change son HTML ?**
> R : Spec un fallback dans `05_scrapers_14_sources.md`. Système de feature flags par source + alerte Slack si taux d'échec > 15% sur 1h.

---

## Lecture suivante recommandée

**Première lecture** — `01_thinking_executive_naming.md` (architecture mentale + executive summary).
**Implémentation** — `21_couts_roadmap.md` (roadmap 12 semaines) puis `23_interfaces_phase2_execution_pack.md` (12 prompts Claude Code prêts à l'emploi).
**Reference technique** — `03_db_schema_phase1.md` + `05_scrapers_14_sources.md` + `13_ui_admin_phase1.md`.

---

**Fin du fichier 00 — INDEX.**
