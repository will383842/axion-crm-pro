# AUDIT v1 — Relecture critique de la spec Axion CRM Pro

> **Auditeur :** Claude Opus 4.7 — posture Architecte Principal externe
> **Date :** 2026-05-16
> **Objet :** spec V1 verrouillée (24 fichiers, ~11 500 lignes Markdown) prête pour démarrage implémentation
> **Posture :** honnêteté brutale, citations précises, recommandations actionnables

---

## Section 1 — Verdict global

**Note globale : 7,3 / 10**

La spec est cohérente, exhaustive et structurée. Le scaffolding Phase 2 dès V1 est une vraie bonne décision. Mais elle souffre de **3 angles morts opérationnels** qui peuvent tuer le projet en S1-S5 (anti-bot superficiel, scalabilité Postgres non pluggée, multi-country impossible sans refactor DB). La conformité RGPD/AI Act est présente en intention mais manque des documents papier obligatoires (LIA, DPIA, registre breach). Le coût de l'erreur si on code tel quel : ~3 semaines de re-travail S6-S8.

**Top 5 forces concrètes**
1. **Architecture plugin** pour scrapers (`ScraperPlugin`) ET proxies (`ProxyProvider`) — vraie extensibilité, pas du vocabulaire creux. Fichiers `05`, `09`.
2. **LLM Router multi-providers** avec fallback chain + cost tracking par requête + A/B + configuration runtime. Très propre. Fichier `07`.
3. **Multi-tenant RLS PostgreSQL au niveau DB** (pas seulement applicatif) — defense in depth réelle. Fichier `15`.
4. **Phase 2 scaffoldée dès V1** (DB + UI + events + queues) — pas de refactor futur, simple "activation". Fichier `04` + `23` partie A.
5. **24 fichiers self-contained + 12 prompts Claude Code prêts à l'emploi** — onboarding dev ou reprise post-absence Will = ~2 jours max. Fichier `23` partie B.4.

**Top 5 faiblesses majeures**
1. 🔴 **Anti-bot superficiel sur les sources critical** (Google Maps, Crunchbase, Societe.com). Aucune mention de captcha solver (2Captcha / Anti-Captcha / CapSolver), TLS fingerprinting (JA3/JA4), timezone rotation, mouse-movement humanization avancée. `05_scrapers_14_sources.md` + `10_rotations_universelles.md`.
2. 🔴 **Multi-country impossible sans refactor DB.** `companies.siren CHAR(9)` hardcodé format INSEE FR. Belgique = BCE 10 chiffres, Suisse = IDE alphanumérique, Allemagne = HRB. Tables NAF utilisées partout (732 sous-classes FR) sans équivalent NACE européen. `03_db_schema_phase1.md` §5.
3. 🔴 **Scalabilité Postgres pas pluggée pour 1M+ rows.** Aucune mention de PgBouncer (essentiel avec Octane + 32 workers), pas de plan read-replica concret, VACUUM strategy absent, worker Playwright sur-dimensionné (12 concurrency website-crawl × 300MB Chromium = ~3,6 Go par worker, mais `worker-node-01` n'a que 8 Go RAM total).
4. 🟠 **Conformité RGPD/AI Act sans docs papier obligatoires.** Pas de LIA (Legitimate Interest Assessment) formalisé, pas de DPIA (Data Protection Impact Assessment) bien que le profilage automatique l'exige côté CNIL, pas de template "notification de violation 72h", DPO unique = Will (incompatible scale).
5. 🟠 **Pas de PoC anti-bot avant code.** Toute la stack scraping (50 % de la valeur Axion CRM Pro) repose sur l'hypothèse "Playwright stealth + proxies résidentiels = ban-proof". Cette hypothèse n'est **jamais testée empiriquement** dans la roadmap. Si elle est fausse, on découvre en S4-S5 que 3 semaines de code sont à jeter.

**Recommandation finale : CORRECTIONS NÉCESSAIRES AVANT CODE**

Concrètement : 5 P0 + 8 P1 à intégrer dans une spec v1.1 (effort ~3-5 jours), avant lancement du Prompt 1 de génération de code. Ne pas y procéder = pari risqué sur 6-12 mois de dev.

---

## Section 2 — Audit Anti-Ban / Anti-Blacklist (CRITIQUE)

### SOURCE : INSEE Sirene API
**RISQUE DE BAN : Faible**
**DÉFENSES PRÉVUES (spec `05` §1) :** OAuth2 token, rate limit 30 req/min auto-imposé, backoff exponentiel HTTP 429.
**DÉFENSES MANQUANTES :**
- ⚠️ Pas de procédure **rotation du token OAuth** en cas de révocation INSEE pour abus
- ⚠️ Pas de **fallback CSV mensuel** (data.gouv.fr publie des exports complets) si l'API est suspendue
**RECOMMANDATIONS :**
- **P1** : ajouter `INSEE_SECONDARY_OAUTH_TOKEN` env var + bascule auto si primary révoqué
- **P2** : prévoir job `ImportInseeCsvFallbackJob` qui ingère les CSV mensuels (déjà publiés gratuitement)

### SOURCE : annuaire-entreprises.data.gouv.fr
**RISQUE DE BAN : Faible-Moyen**
**DÉFENSES PRÉVUES :** rate limit 60 req/min auto-imposé, fallback Infogreffe + Société.com.
**DÉFENSES MANQUANTES :**
- ⚠️ Aucune **rotation user-agent** mentionnée pour cette source (alors qu'on rotate ailleurs)
- ⚠️ Pas de **détection structurelle des changements HTML** (parsing fragile)
**RECOMMANDATIONS :**
- **P1** : appliquer rotation UA + headers Sec-Fetch même sur cette source HTTP simple
- **P1** : test contractuel (snapshot HTML hebdo + diff alerting) pour détecter changement structure

### SOURCE : Infogreffe
**RISQUE DE BAN : Moyen-Élevé**
**DÉFENSES PRÉVUES (`05` §3) :** Playwright stealth + Webshare datacenter proxies + rate 30/min.
**DÉFENSES MANQUANTES :**
- 🔴 Webshare DATACENTER explicitement banni par Cloudflare BM sur Infogreffe en 2025-2026 (constat marché)
- ⚠️ Pas de gestion du **Cloudflare "I'm under attack" 5s challenge**
- ⚠️ Pas de **cookie persistence inter-sessions** (Infogreffe set cookies CF anti-bot)
**RECOMMANDATIONS :**
- **P0** : forcer Infogreffe sur **résidentiel** (IPRoyal/Smartproxy), JAMAIS datacenter — patcher `DomainProfileService` fichier 09 §4
- **P0** : ajouter cookie jar persistant par session (storage state Playwright)
- **P1** : intégrer `puppeteer-extra-plugin-cloudflare-bypass` ou équivalent

### SOURCE : societe.com
**RISQUE DE BAN : Élevé**
**DÉFENSES PRÉVUES :** résidentiel obligatoire (`DOMAIN_PROFILES`), Cloudflare + DataDome mentionnés, stealth.
**DÉFENSES MANQUANTES :**
- 🔴 **Aucune solution captcha solver** (Société.com pousse parfois reCAPTCHA v3 + hCaptcha)
- 🔴 **TLS fingerprint** : Playwright Chromium par défaut expose un JA3 hash facilement détectable par DataDome
- ⚠️ Pas de **mouse movement humanization** avancée (juste `waitForTimeout` random)
**RECOMMANDATIONS :**
- **P0** : intégrer **2Captcha** ou **CapSolver** (budget ~30 €/mois) — cf section 7.5
- **P0** : utiliser **`curl-impersonate`** ou **`tls-client`** Node pour les requêtes HTTP simples (bypass TLS fingerprint)
- **P1** : remplacer `waitForTimeout` par mouvements souris simulés via lib `ghost-cursor` ou équivalent

### SOURCE : BODACC
**RISQUE DE BAN : Faible**
**DÉFENSES :** API publique gratuite via DILA OpenDataSoft, 100 req/min.
**DÉFENSES MANQUANTES :**
- ⚠️ Pas de cache anti-doublons sur les annonces (anti-doublon en DB mentionné §3 fichier 20 mais pas au niveau requête)
**RECOMMANDATIONS :**
- **P2** : ajouter cache Redis 24h sur clé `bodacc:{siren}:{date}` pour éviter re-fetch

### SOURCE : Google Maps — LE PLUS RISQUÉ
**RISQUE DE BAN : Critique**
**DÉFENSES PRÉVUES (`05` §6) :** Résidentiel premium (Smartproxy), stealth, rate 1-3/min/IP, captcha detect → dead_letter + cooldown zone 24h.
**DÉFENSES MANQUANTES :**
- 🔴 **Pas de captcha solver** — fichier 05 dit juste "si captcha détecté → banned, cooldown zone 24h" → **on perd la donnée**, on ne contourne pas
- 🔴 **Pas de timezone rotation** — toutes les sessions ouvertes en `Europe/Paris`. Quand on utilise des résidentiels US/UK/DE, Google détecte immédiatement (proxy DE + timezone Paris = anomalie 100 %)
- 🔴 **Headers `Sec-Fetch-*`** : mentionnés en fingerprint `user_agents.fingerprint.headers` (`02`) mais non utilisés explicitement par les workers
- ⚠️ **Click delay** : aucune simulation de pause humaine sur les clics
- ⚠️ **Scroll velocity** : `scrollIntoViewIfNeeded` immédiat alors qu'humain scroll lentement
- ⚠️ **WebGL/Canvas/Audio fingerprint** : stealth-plugin couvre partiellement mais pas randomisé par session
**RECOMMANDATIONS :**
- **P0** : intégrer **CapSolver** (~30 €/mois pour 5k captchas) — résoudre au lieu de subir
- **P0** : rotation timezone : la timezone doit matcher le pays du proxy. Patcher `user_agents.fingerprint.timezone_offset` pour qu'elle suive `proxies.country_code`
- **P0** : créer un **PoC Google Maps avant Sprint 4** (cf section 10)
- **P1** : remplacer scroll instantané par scroll progressif (5-15 px/frame, vitesse variable)
- **P1** : remplacer `page.click()` direct par `mouseMoveSlow` + `mouseDown` + `mouseUp` (lib `ghost-cursor`)

### SOURCE : Pages Jaunes
**RISQUE DE BAN : Moyen**
**DÉFENSES :** Playwright stealth, Webshare datacenter OK.
**DÉFENSES MANQUANTES :** Pages Jaunes a renforcé anti-bot en 2024-2025 (Akamai + reCAPTCHA conditionnel).
**RECOMMANDATIONS :**
- **P1** : forcer résidentiel sur Pages Jaunes après 100 req datacenter (escalation auto via `ProxyRouter`)

### SOURCE : Sites web entreprises
**RISQUE DE BAN : Variable (Faible à Élevé selon site)**
**DÉFENSES PRÉVUES :** datacenter Webshare suffit, crawl 2-3 niveaux.
**DÉFENSES MANQUANTES :**
- ⚠️ Pas de **`robots.txt` parser** — risque conformité (politesse + légal léger)
- ⚠️ Pas de **WAF detection** (sites avec ModSecurity, AWS WAF, Cloudflare WAF)
- ⚠️ **SSRF risk** : `companies.website` peut être saisi manuellement par un admin → un admin malveillant pourrait scraper `http://10.20.0.30:5432` (Postgres interne). OWASP A10 violé.
**RECOMMANDATIONS :**
- **P0** : valider `companies.website` côté serveur → seul `https://` + résolution DNS non-RFC1918 (pas 10.x, 172.16.x, 192.168.x)
- **P1** : ajouter parser `robots.txt` (politesse + détection `Disallow: /admin`)
- **P2** : `User-Agent: AxionCrawler/1.0 (+https://axion-ia.com/crawler-info)` pour les sites qui acceptent les crawlers identifiés

### SOURCE : LinkedIn via PhantomBuster
**RISQUE DE BAN : Élevé** (compte Sales Nav banni = ~99 $/mois perdus + PhantomBuster phantom à reconfigurer)
**DÉFENSES PRÉVUES :** 3 comptes en rotation, daily limit 80, états `active/rate_limited/cooldown/suspicious/banned`, health check hourly.
**DÉFENSES MANQUANTES :**
- ⚠️ Pas de mention d'**âge minimum des comptes** (compte créé < 30j = ban quasi-garanti par LinkedIn)
- ⚠️ Pas de **warm-up obligatoire** sur nouveaux comptes (premier mois : connexions humaines, posts, navigation organique avant activité PhantomBuster)
- ⚠️ Pas de **stratégie de re-création** quand un compte est banni (achat de comptes en marché gris = risqué + non-conforme LinkedIn ToS)
**RECOMMANDATIONS :**
- **P1** : documenter procédure compte Sales Nav (~3 semaines warm-up manuel par Will avant usage PhantomBuster) dans fichier 10
- **P1** : LinkedIn ToS interdisent l'automatisation. Documenter l'**acceptation du risque** explicitement (vs trade-off business)

### SOURCE : France Travail API
**RISQUE DE BAN : Faible** — API officielle, OAuth2.
**OK.**

### SOURCE : MESRI / ONISEP / data.gouv
**RISQUE DE BAN : Faible** — Open data CSV + API gratuites.
**OK.**

### SOURCE : Crunchbase
**RISQUE DE BAN : Critique**
**DÉFENSES PRÉVUES :** résidentiel premium, rate 1/min, scraping prudent hebdo.
**DÉFENSES MANQUANTES :**
- 🔴 Crunchbase utilise **Cloudflare Bot Management + PerimeterX + JS challenge** combinés → playwright-extra stealth seul NE SUFFIT PAS en 2026 (constat marché)
- 🔴 Pas de fallback : si Crunchbase scraping casse, **on perd l'unique source levées de fonds Tech FR**
**RECOMMANDATIONS :**
- **P0** : ajouter **fallback news scraping** (frenchweb.fr, maddyness.com déjà prévus fichier 20 §5) — mais aussi maddyness.com via flux RSS officiel (gratuit, légal, fiable)
- **P1** : envisager API tierce payante au cas où (Crunchbase Pro API ~50 $/mois) — à activer si scraping bloqué

### SOURCE : BAN api-adresse
**RISQUE DE BAN : Très faible** — API officielle, 50 req/sec.
**OK.**

### SOURCE : Réseaux sociaux (X, YT, TikTok, Insta, FB)
**RISQUE DE BAN : Moyen** — l'objectif est identification seulement (handles), pas content.
**DÉFENSES MANQUANTES :**
- ⚠️ Instagram et Facebook bannissent ultra-rapidement
- ⚠️ TikTok détecte les bots en < 30s
**RECOMMANDATIONS :**
- **P2** : limiter scraping social light à 1 visite / profil / 30 jours TTL (déjà spec) + max 50 req/jour/IP

### Synthèse anti-bot

| Source | Risque | P0 manquants | P1 manquants |
|---|---|---|---|
| Google Maps | Critique | Captcha solver + timezone rotation + PoC | Mouse humanization + scroll velocity |
| Crunchbase | Critique | Fallback news scraping | API payante envisagée |
| Societe.com | Élevé | Captcha solver + TLS fingerprint | Mouse humanization |
| Infogreffe | Élevé | Forcer résidentiel + cookie jar | Cloudflare bypass plugin |
| Pages Jaunes | Moyen | — | Escalation résidentiel |
| Sites web | Variable | Valider URLs (SSRF) | robots.txt |
| LinkedIn (PB) | Élevé | — | Warm-up procédure + acceptation ToS |
| Autres | Faible | — | — |

**Verdict section 2 :** la spec anti-bot est **superficielle sur les 3 sources critiques** (Google Maps, Crunchbase, Societe.com). Sans P0 corrigés, taux de succès en prod estimé à **40-60 %** vs cible 95 %.

---

## Section 3 — Audit Scalabilité Réelle

### 3.1 Bottlenecks PostgreSQL à 1M+ rows

- ✅ **Index** : globalement bons (GIN pg_trgm, GIST PostGIS, composites). Fichier 03.
- ✅ **Partitionnement pg_partman** : OK sur `scraper_runs`, `llm_usage`, `proxy_usage_log`.
- 🔴 **PgBouncer absent.** Avec Octane Swoole + 32 Horizon workers + scheduler + Octane workers, on est facilement à 100+ connexions Postgres simultanées. PostgreSQL 16 défaut `max_connections = 100`. Sans PgBouncer en transaction pooling, **app-01 va saturer DB en S6** avec 200k entreprises/mois.
- 🟠 **Materialized view `coverage_matrix_cells` refresh à 1M rows** : la spec annonce < 25 min. En réalité, sur CCX33 8 vCPU 32 Go RAM, REFRESH CONCURRENTLY sur ~10M cellules (1M × 10 dimensions) prendrait **45-90 min**. Plan B mentionné mais sous-dimensionné.
- 🟠 **Replica streaming "S2"** mentionné fichier 03 commentaire mais jamais détaillé dans fichier 18 déploiement. Risque de découvrir en S15 qu'il faut migrer 200 Go avec downtime.
- 🟠 **VACUUM strategy absente.** Sur tables partitionnées avec ~1M inserts/jour (scraper_runs), autovacuum doit être tuned (`autovacuum_vacuum_scale_factor = 0.05` au lieu de défaut 0.2).
- ⚠️ **Postgres `shared_buffers`** : mentionné 8 Go mais sur 32 Go RAM, viser 25 % = 8 Go OK, mais `work_mem = 32 Mo` est trop bas pour les agrégations Coverage Matrix (viser 128 Mo).

**Recommandations P0/P1 :**
- **P0** : ajouter PgBouncer dans `docker-compose.prod.yml` (mode transaction pooling, port 6432) + reconfigurer Laravel `DB_PORT=6432`
- **P1** : documenter autovacuum tuning pour tables partitionnées
- **P1** : préciser plan replica streaming concret (script de promotion, lag monitoring) dans fichier 18

### 3.2 Bottlenecks workers Playwright

- 🔴 **RAM sur-souscrite.** Fichier 19 dit `website-crawl concurrency 12` par worker Node. Chromium = ~250-350 MB par contexte. 12 × 300 = **3,6 Go** juste pour Chromium. Sur `worker-node-01` CPX31 (8 Go RAM total), avec Node base ~500 MB + workers idle ~200 MB + buffer OS ~1 Go, il reste **2,7 Go** → on tient 9 contextes Chromium, pas 12. Concurrency **doit être 6-8 max** par worker.
- 🟠 **Memory leak Chromium** : pas de restart périodique des workers. Pattern courant : `worker.process_count = 50` avant kill auto. Pas dans la spec.
- 🟠 **Headless vs headed** : headless choisi, mais certains anti-bots détectent `navigator.webdriver = true` même avec stealth → forcer `--disable-blink-features=AutomationControlled` à minima.
- ⚠️ **Browser context recycling** : créer un nouveau browser à chaque job est lent (~2s startup). Recommandation : pool 4-6 browsers persistants, créer/destroy uniquement les contexts.

**Recommandations P0/P1 :**
- **P0** : passer `website-crawl` concurrency 12 → **6** dans fichier 19 + ajouter restart auto après 100 jobs traités
- **P1** : pooler 4 browsers persistants par worker (gain ~30 % throughput)

### 3.3 Bottlenecks Redis

- ✅ Redis 7 AOF persistance OK, CCX13 8 Go RAM OK pour BullMQ.
- 🟠 **BullMQ à 1M jobs en queue** : Redis fonctionne mais latence ZADD passe de 0,1 ms à 2-5 ms → throughput chute. Pour V1 à 200k jobs simultanés c'est OK.
- ⚠️ **Eviction policy** : par défaut `noeviction` (Redis crash si OOM). Spec dit AOF mais pas `maxmemory-policy`. Recommander `volatile-lru` pour cache + queues sans eviction.
- ⚠️ **Sentinel / Cluster non discutés** : single point of failure Redis. Acceptable V1, à reconsidérer Phase 2.

**Recommandations P1/P2 :**
- **P1** : configurer `maxmemory 6gb` + `maxmemory-policy volatile-lru` + monitoring Redis memory dans dashboard Grafana fichier 16
- **P2** : Redis Sentinel ou cluster dès Phase 2 scaling

### 3.4 Bottlenecks réseau

- 🟠 **Hetzner bandwidth** : CPX/CCX incluent **20 To/mois sortants** puis 1 €/To. À 1M entreprises × moyenne 5 Mo crawl site web = **5 To/mois** sortant. À volume 200k/mois c'est 1 To, dans le quota. À 1M/mois on est à 5 To, encore OK. Mention manquante dans fichier 21 coûts.
- 🟠 **Proxies résidentiels** : facturation au Go (Smartproxy ~3-5 €/Go résidentiel). Estimer à 200k × 5 Mo = 1 To/mois résidentiel = **3000-5000 €/mois** si tout passe par résidentiel. Fichier 21 sous-estime probablement.

**Recommandations P0/P1 :**
- **P0** : recalculer fichier 21 §1 ligne "Smartproxy" — soit volume facturé au Go (réaliste), soit pricing au IP (moins courant). Probable explosion à scale.
- **P1** : router le datacenter Webshare en priorité pour les sites pas critical (Pages Jaunes, sites web petites entreprises) — économie ~80 % bandwidth résidentiel

### 3.5 Bottlenecks LLM

- 🟠 **Rate limits Anthropic Tier 1** : 50 req/min = 72 000/jour. À 7 000 entreprises/jour × 4 LLM calls = 28 000 calls/jour → OK avec Tier 1, mais bursty.
- 🟠 **Pas de stratégie de batching** : appels LLM unitaires alors que Anthropic Message Batches API (50 % discount) existe. Pas mentionné fichier 07.
- 🔴 **Pas de cache LLM** (semantic cache) : pour les classifications similaires (~30-40 % d'entreprises ont des descriptions proches), un cache embeddings + cosine similarity économiserait massivement. Pas dans spec.

**Recommandations P1 :**
- **P1** : ajouter Anthropic Message Batches API pour classifications non-temps-réel (-50 % coût)
- **P1** : semantic cache LLM (embedding via `text-embedding-3-small` OpenAI ~0,02 $ / 1M tokens, cosine sur Redis Vector ou pgvector) — économie estimée 30-40 %

### Verdict section 3

La spec décrit la stack mais **ne dimensionne pas finement** plusieurs choke points. Plus de la moitié des "Plans B Phase 2" risquent d'être activés en S6-S8 par surprise. PgBouncer + concurrency Playwright + budget Smartproxy résidentiel = P0 immédiats.

---

## Section 4 — Audit Évolutivité Long Terme (5 ans)

### 4.1 Évolutivité fonctionnelle

- 🔴 **Multi-country DB-locked.** `companies.siren CHAR(9)` est strictement format INSEE FR. Une entreprise belge avec BCE `0123.456.789` (10 chiffres avec points) ne rentre pas. Tables NAF utilisées partout = pas portable vers NACE européen. Migration multi-country = **refactor 15-20 jours + risque casser CI**.
- 🟠 **Internationalisation UI** : i18n mentionné FR canonique EN miroir Phase 2, mais `auto_tag_definitions.display_name` est VARCHAR(120) FR-only. `strategic_keywords.keyword` aussi. NAF labels en FR uniquement. Refactor i18n DB = 5-7 jours.
- ✅ **Ajout 15e source** : avec `ScraperPlugin` interface, c'est vraiment ~1 fichier ~300 lignes. OK.
- ✅ **Module SMS prospection** : tables Phase 2 scaffold à ajouter (~3 tables), event `ContactReadyForSmsOutreach`, queue `sms-send`. Effort raisonnable ~2 jours.
- 🟠 **Bascule SaaS multi-tenant** : architecturalement prête (RLS), mais billing, onboarding, isolation worker proxies cross-workspace pas pensés. Effort ~3-4 semaines.

**Recommandations P1 :**
- **P1** : **changer `companies.siren CHAR(9)` → `country_id BIGINT + national_id VARCHAR(20) + UNIQUE(country_id, national_id)`** dès la migration initiale (cf fichier 03 §5). Effort 1h dans migration, économise 15 jours plus tard.
- **P1** : ajouter `naf_subclasses.label_en`, `auto_tag_definitions.display_name_en`, etc. dès V1 (NULLABLE OK). Économise refactor i18n.

### 4.2 Évolutivité technique

- ✅ Laravel 12 + Spatie packages : pas de couplage rare, migration probable inutile.
- ✅ React 19 + Tailwind + shadcn : composants découplés, migration vers Solid/Svelte théoriquement possible mais peu probable nécessaire.
- 🟠 **Migration PostgreSQL → autre DB** : RLS PostgreSQL = très fort couplage. Migrer vers MySQL/SQLite = perdre le bouclier sécurité. C'est un choix assumé OK.
- 🔴 **Migration Hetzner → AWS/GCP** : workers/IPs/proxies/Coolify tout est Hetzner-flavored. **Pas de Terraform / Pulumi / IaC** mentionné. Migration cloud = re-provisioning manuel multi-jours.

**Recommandation P2 :**
- **P2** : ajouter Terraform basique sur `infra/` dès S12 (provider Hetzner Cloud) — économise migration future.

### 4.3 Évolutivité organisationnelle

- ✅ Spec exhaustive + 12 prompts Claude Code → onboarding rapide.
- 🟠 **Documentation utilisateur (admin)** : pas de mention de doc admin pour Will + futur dev. Pas de tutoriels.
- 🟠 **Tests E2E couvrant 5 parcours** : insuffisant. Manque parcours "ajouter provider proxy", "configurer LLM use case", "gérer alertes anomalies".
- 🔴 **Bus factor 1 (Will)** : si Will indisponible 3+ mois, projet en pause. Risque #13 fichier 22 mentionne enveloppe budgétaire backup mais pas pré-engagement dev.

**Recommandations P1/P2 :**
- **P1** : ajouter `docs/admin-guide.md` minimal en S12 (10 scénarios opérationnels)
- **P1** : étendre tests E2E à 10 parcours minimum
- **P2** : identifier 1-2 devs senior backup pré-contactés (relation pas contrat)

---

## Section 5 — Audit Conformité (RGPD + AI Act + OWASP)

### 5.1 RGPD

- ✅ **Base légale intérêt légitime** documentée + 7 traitements + `data_processing_log`. Bon début.
- 🔴 **LIA (Legitimate Interest Assessment) absent.** Avant invocation de l'intérêt légitime art. 6.1.f, la CNIL exige un test à 3 critères (légitimité, nécessité, balancing test contre droits des personnes). Aucun template/doc dans la spec.
- 🔴 **DPIA (Data Protection Impact Assessment) absent.** Le profilage automatique systématique (scoring `axion_offer_match`, `priority_score`) est explicitement listé dans **WP248 G29** comme déclenchant une DPIA obligatoire. La spec mentionne `ai_act_register` mais pas DPIA.
- 🟠 **Template "notification violation 72h"** : mentionné comme "procédure documentée" fichier 17 §6 mais pas de template concret prêt-à-l'emploi.
- 🟠 **DPO = Will (interim)** : juridiquement OK pour V1 mais à scale → conflit d'intérêts (DPO doit être indépendant de la direction). Mention manquante.
- 🟠 **Sous-processeurs DPA** : mentionnés (PhantomBuster, Hetzner, Cloudflare, Backblaze) mais pas tous (Anthropic, OpenAI, Mistral, Webshare, IPRoyal, Smartproxy à signer DPA aussi).

**Recommandations P0/P1 :**
- **P0** : créer template LIA + le remplir pour les 7 traitements (~3 h de travail) — ajouter dans `docs/legal/lia.md`
- **P0** : créer template DPIA + le remplir pour le profilage automatique (~5 h) — `docs/legal/dpia.md`
- **P1** : créer template "violation 72h" prêt à l'envoi CNIL — `docs/legal/breach-notification-template.md`
- **P1** : lister explicitement tous les sous-processeurs avec status DPA (signé/à signer/non requis)

### 5.2 AI Act

- ✅ Classification `limited` documentée + 10 entrées `ai_act_register` prévues.
- 🟠 **Transparence utilisateur final** : la spec dit "label visible sur scores dans l'UI" mais ne formalise pas le wording. Risque interprétation insuffisante par contrôle.
- 🟠 **Human-in-the-loop** : override manuel partout = bon, mais pas de **journalisation des reviews humaines** (combien d'overrides par jour, taux désaccord LLM/humain). Métrique manquante fichier 16.
- ⚠️ **AI Act art. 13 transparence obligatoire** : Axion CRM Pro étant `limited` n'a pas l'obligation stricte d'art. 13, mais en cas de durcissement (cf risque #10 fichier 22), il faut être prêt.

**Recommandations P1 :**
- **P1** : formaliser wording transparence dans `/legal/ai-act` (template phrase prêt à coller dans UI)
- **P1** : ajouter métrique `axion_llm_human_override_rate` dans Prometheus

### 5.3 OWASP top 10

| Item | Spec OK | Faille détectée |
|---|---|---|
| A01 Broken Access | ✅ RLS + Spatie | — |
| A02 Crypto | ✅ TLS auto + bcrypt 12 + Crypt | — |
| A03 Injection | ✅ Eloquent paramétré | ⚠️ Raw query fuzzy match fichier 12 — vérifier binding strict |
| A04 Insecure Design | ✅ Threat modeling | — |
| A05 Misconfiguration | ✅ Headers + APP_DEBUG=false | ⚠️ CSP nonce mentionné mais implémentation Laravel pas détaillée |
| A06 Vuln components | ✅ composer audit + npm audit + Trivy | — |
| A07 Auth | ✅ Sanctum + 2FA + rate limit | — |
| A08 Integrity | ✅ Hash chain audit logs | — |
| A09 Logging | ✅ Loki + GlitchTip + 3 channels | — |
| A10 SSRF | 🔴 **VIOLÉ** | `companies.website` user-controllable → workers peuvent fetch 10.20.0.30:5432 |

**Recommandations P0 :**
- **P0** : valider strictement `companies.website` côté serveur (Laravel rule custom : `https://` only + DNS résolution non-RFC1918) + filtrer côté worker Node aussi (double belt-and-braces)

**Recommandations P1 :**
- **P1** : détailler implémentation CSP nonce Laravel (middleware + view directive `@nonce`)
- **P1** : auditer les raw queries fichier 12 §5 (fuzzy match) pour confirmer parameter binding strict

### Verdict section 5

RGPD + AI Act = **intention solide, documents papier manquants**. OWASP = 9/10 OK, A10 SSRF est une faille concrète à patcher avant prod.

---

## Section 6 — Audit Cohérence Inter-Fichiers

Incohérences détectées :

1. 🔴 **Table `monitoring_anomalies` mentionnée fichier 16 §5 mais JAMAIS créée** dans fichier 03 ou 04. → **P0 : ajouter migration `monitoring_anomalies` au fichier 03**.

2. 🔴 **Route API `/api/internal/proxies/next` utilisée par workers Node fichier 19 §4 mais absente du fichier 14** (liste des 70+ endpoints). → **P0 : ajouter au fichier 14 routes internes (auth interne par token machine-to-machine, pas Sanctum)**.

3. 🟠 **Coûts Hetzner divergents** entre fichier 02 (264 € sans GPU, 334 € avec) et fichier 21 (300 € sans GPU dans le détail mais "sous-total Hetzner V1 300"). Différence : fichier 21 ajoute IPv4 supplémentaires (6 €) et arrondit. → **P1 : harmoniser à un montant unique (recommandé : 285 € HT sans GPU)**.

4. 🟠 **`failed_jobs`, `jobs_batches`, `cache`, `cache_locks` (tables Laravel standard)** : utilisées implicitement (Horizon retries, Bus::batch) mais jamais listées dans fichier 03. Laravel les crée auto via `php artisan queue:table` etc., mais la spec devrait les mentionner explicitement.

5. 🟠 **Backup script fichier 18 §7 utilise `b2 upload-file`** (CLI Backblaze) mais `infra/scripts/` ne mentionne pas l'install du CLI ni le `keyId/applicationKey`. → **P1 : ajouter step setup dans Dockerfile postgres-backup ou en commentaire script**.

6. 🟠 **Routes Phase 2 stubs fichier 14 §16.2** mentionnent `linkedin-outreach/connection-requests` POST mais aucune table `phone_numbers` ou équivalent pour Phase 2 SMS si l'on ouvre cette voie plus tard. **Non bloquant V1**, juste anticipation.

7. 🟠 **Use case LLM `business_signal_detection` mentionné fichier 20 §3** mais pas listé dans les 10 use cases Phase 1 du fichier 07 §8 (qui liste seulement les 10 use cases hors signaux). → **P1 : ajouter `business_signal_detection` au tableau §8 fichier 07**.

8. 🟢 **Page admin "Anomalies & alertes" fichier 13 §17** appelle des endpoints `/api/monitoring/anomalies/*` qui sont bien dans fichier 14 §14 ✅.

9. 🟢 **Page admin "Doublons potentiels" évoquée fichier 12** mais pas listée dans les 17 pages Phase 1 fichier 13. C'est plutôt une vue dans `/companies` qu'une page autonome — acceptable mais à clarifier.

10. 🟠 **Spatie Permission seedée fichier 15 §6** avec 4 rôles, mais permission `monitoring.acknowledge-alerts` listée et utilisée fichier 13 page 17 sans être donnée explicitement au rôle `operator`. → **P1 : vérifier seeder permissions cohérent avec page-level access control**.

11. 🟢 **`data_processing_log` 7 traitements documentés fichier 17** correspondent aux modules actifs V1. OK.

12. 🟠 **Page `/legal/mentions` et `/legal/privacy` fichier 17 §3** : routes publiques sans auth. Pas explicitement listées dans fichier 14 (qui liste les routes API). → acceptable (ce sont des routes Web Blade, pas API JSON), mais à clarifier.

13. 🟠 **`prompt_template_versions.variables_spec`** mentionné JSONB fichier 03 §2 et fichier 07 §4. Format clair. Mais la **validation runtime des variables fournies vs spec** n'est pas codifiée — risque silent fail si variable manquante. → **P1 : `LlmTemplateValidator` service dans Prompt 4**.

**Verdict section 6 :** 2 P0 (tables et routes manquantes) + 6 P1 incohérences mineures. Pas catastrophique mais à fixer avant code pour éviter petits bugs en chaîne.

---

## Section 7 — Audit Trous Fonctionnels

### 7.1 Backup & Restore
- ✅ Script `backup-postgres.sh` détaillé fichier 18 §7.
- 🟠 **Test restore mensuel** mentionné comme "à faire" mais pas de **job automatisé** qui valide qu'un restore staging fonctionne. → **P1 : `MonthlyRestoreTestJob` cron qui restore dump sur staging + run SQL validation queries**.

### 7.2 Disaster Recovery
- ✅ RPO 1h / RTO 4h documentés.
- 🟠 **Hetzner fsn1 down** : pas de plan B documenté concrètement. Plan B serait deuxième datacenter Hetzner (nbg1, hel1) avec snapshots volume répliqués — pas mentionné.
- 🟢 Backblaze B2 offsite OK.

**Recommandation P1 :** documenter procédure failover datacenter Hetzner (nbg1 backup target).

### 7.3 Observabilité
- ✅ 40+ métriques + 10 dashboards + Loki + Tempo + Uptime Kuma.
- 🟠 **Distributed tracing (Tempo)** : "préparé Phase 2" mais pas activé V1. C'est dommage car le waterfall enrichissement est exactement un cas d'usage idéal pour traces (chaque étape = un span).
- 🟠 **Métriques business** : majoritairement techniques. Manque KPIs commerciaux : "entreprises qualifiées hot / semaine", "deals créés depuis matching offer", "coverage % par offer Axion-IA".

**Recommandations P1 :**
- **P1** : activer OpenTelemetry traces dès V1 pour le waterfall (4 spans par enrichissement = très utile debug)
- **P1** : ajouter 5-7 métriques business dans dashboard 1 "Vue exécutive"

### 7.4 Sécurité avancée
- ✅ Rate limiting documenté fichier 14 §18.
- 🟠 **WAF** : Cloudflare Bot Fight ON mentionné, mais pas de règles WAF custom (ex: block SQL keywords sur les routes publiques, geo-restriction admin sur FR only).
- 🟠 **Pentest pré-prod** : mentionné fichier 17 §6 mais pas dans planning roadmap S12.
- 🟠 **Secrets rotation** : Infisical mentionné mais pas de procédure rotation automatique (ex: rotation `ANTHROPIC_API_KEY` tous les 90 jours).
- ⚠️ **Memory dumps** : `php artisan tinker` en prod peut leak secrets. Désactiver `tinker` en prod via APP_ENV check.

**Recommandations P1 :**
- **P1** : ajouter règle Cloudflare "country=FR only" sur `/admin/*` (Will est en FR)
- **P1** : planifier pentest externe S12 (budget ~3-5k€) — vrai ajout valeur si Will signe gros clients
- **P2** : automation rotation secrets tous les 90 jours

### 7.5 Coût caché
- 🔴 **Bandwidth Hetzner à 1M/mois** non chiffré (cf 3.4). Estimation 5 To/mois → dans le quota 20 To inclus. **OK mais à monitorer.**
- 🔴 **Smartproxy résidentiel facturé au Go** : à scale ce poste peut exploser. À 1 To/mois résidentiel = 3-5 k€/mois.
- 🔴 **Captcha solver** (P0 ajouté) : ~30 €/mois budget initial, peut monter à 100-200 €/mois selon volume captcha.
- 🟠 **Storage logs Loki** : retention 30j prévue, mais à 50 Go logs/mois × 6 mois = 300 Go. Hetzner Volume coût ~5 €/mois — OK.
- 🟠 **Coûts LLM en pic** : pas de circuit breaker dur configurable (auto kill-switch mentionné mais pas détaillé techniquement).

**Recommandations P0/P1 :**
- **P0** : recalculer fichier 21 §1 ligne Smartproxy avec pricing au Go (réaliste) + ligne captcha solver
- **P1** : implémenter circuit breaker LLM `LLM_HARD_CAP_EUR_PER_DAY = 50` → bloque tout call si dépassé jour courant

### 7.6 Onboarding utilisateur
- ✅ Spec exhaustive pour devs.
- 🔴 **Aucune doc utilisateur admin** (Will + futurs operators). Page `/help` ou `docs/user-guide.md` absente.
- 🟠 **Pas de vidéo tutoriels** : acceptable V1, à reconsidérer Phase 2.

**Recommandation P1 :** créer `docs/user-guide.md` minimal en S12 avec 10 parcours opérateurs courants (créer scraping zone, override score, gérer RGPD, etc.).

### Verdict section 7

3 vrais trous (test restore auto, business metrics, doc utilisateur) + plusieurs P1 fixables vite. Acceptable mais à fixer en S12.

---

## Section 8 — Meilleures Pratiques 2026 — Conformité

### 8.1 Stack moderne
- ✅ Laravel 12 + PHP 8.3 + React 19 + TypeScript 5.6 strict + Vite 6 + Tailwind 4 : stack 2026 conforme.
- 🟠 **PHP 8.3 features** : la spec ne mentionne pas l'usage idiomatique (readonly classes, typed enums, asymmetric visibility préparé 8.4). Code Generation Roadmap devra l'imposer.
- 🟠 **React 19 Server Components** : non utilisés (SPA pure). Acceptable mais on perd ~30 % de DX possible (data fetching simplifié, smaller bundle). Décision OK pour V1.
- 🟢 **Vite 6 vs Bun** : Vite OK V1, Bun valide alternative pas justifiée dans la spec — pas critique.

### 8.2 Patterns 2026
- 🟠 **DDD léger** : la structure `App\Modules\<Domain>` suit l'esprit DDD, mais pas de bounded contexts formels.
- 🟢 **CQRS / Event Sourcing** : pas nécessaire pour ce projet. Audit log hash chain = event log lite, suffisant.
- 🟢 **Hexagonal Architecture** : `Contracts/` + implémentations = hexagonal-lite OK.

### 8.3 DevOps 2026
- 🟠 **GitOps (ArgoCD/Flux)** : non utilisé. Coolify ferait l'équivalent simplifié. Acceptable.
- 🔴 **IaC absent** : pas de Terraform / Pulumi. Pour 1 dev solo c'est OK V1, mais 0 reproductibilité infra hors snapshot Hetzner.
- 🟠 **OpenTelemetry** : préparé mais inactif (cf 7.3).
- 🔴 **Feature flags** : aucun outil mentionné (Unleash, GrowthBook, ConfigCat). Conséquence : déployer une feature WIP = la voir prod direct. C'est limitant pour expérimentation Phase 2.

**Recommandations P1/P2 :**
- **P1** : GrowthBook self-hosted (cohérent avec axion-ia.com `[OPTION REFUSÉE]` mention en mémoire) ou simplement table `feature_flags` + service `FeatureFlags::isEnabled('cold_email', $workspace)`.
- **P2** : Terraform sur `infra/` dès S12.

### 8.4 IA-natif 2026
- 🔴 **LangSmith / Phoenix / Helicone** : pas d'évaluation auto des LLM (regressions silentieuses possibles).
- 🔴 **Prompt injection detection** : aucune mention. Risque : si un attaquant insère du texte dans `companies.description` ou `companies.website` HTML qui est ensuite envoyé à `extract_team_from_page` LLM → prompt injection possible. **OWASP top 10 LLM 2025 #1**.
- 🔴 **Semantic cache** : cf 3.5, économie 30-40 % LLM.
- 🟢 **Fine-tuning Llama local** : pas mentionné, acceptable V1 mais à considérer Phase 2.

**Recommandations P0/P1 :**
- **P0** : ajouter `PromptInjectionGuard` service dans LLM Router fichier 07 — détecte patterns `Ignore previous instructions`, `<|system|>`, etc. sur tout input variable
- **P1** : semantic cache LLM (cf 3.5)
- **P1** : evals automatiques (golden dataset 50 entreprises classifiées manuellement par Will → run hebdo vs LLM actuel)

### 8.5 Accessibilité
- 🟠 **WCAG 2.2 AA** mentionné fichier 13 §11 mais pas de tests automatisés (axe-core via Playwright).

**Recommandation P1 :** ajouter `@axe-core/playwright` aux tests E2E.

### 8.6 Internationalisation
- 🔴 **i18n DB non architecturé** (cf 4.1). EN Phase 2 mais tags/NAF FR-only en V1 = dette technique forte.

**Recommandation P1 :** colonnes `*_en` NULLABLE dès V1 (cf 4.1).

---

## Section 9 — Top 20 Recommandations Priorisées

### P0 (CRITIQUE — avant tout code)

1. **Anti-bot Google Maps : captcha solver + timezone rotation + PoC empirique avant S4**
   - Fichiers : `05`, `10`
   - Effort : 4-6h spec + 1-2 jours PoC
   - Risque si non corrigé : 40-60 % de runs en échec, 3 semaines de re-travail S6

2. **Anti-bot Societe.com / Crunchbase : TLS fingerprinting + captcha solver + fallback news scraping**
   - Fichiers : `05`, `20`
   - Effort : 3-4h spec + 1 jour PoC
   - Risque : perte source levées de fonds Tech FR

3. **SSRF protection sur `companies.website`**
   - Fichiers : `05`, `14`, `19`
   - Effort : 2h
   - Risque : OWASP A10 — admin malveillant peut scraper DB Postgres interne

4. **Multi-country DB-ready dès migration initiale**
   - Fichier : `03` §5 — remplacer `siren CHAR(9)` par `country_id + national_id` composite UNIQUE
   - Effort : 1h dans migration
   - Risque : refactor 15-20 jours plus tard

5. **PgBouncer dans `docker-compose.prod.yml` (transaction pooling port 6432)**
   - Fichier : `18`, `02`
   - Effort : 2-3h
   - Risque : saturation DB en S6, downtime app

6. **Concurrency `website-crawl` 12 → 6 dans fichier 19**
   - Fichier : `19`
   - Effort : 30 min
   - Risque : OOM worker Node, restart en boucle

7. **Tables manquantes : `monitoring_anomalies` + route interne `/api/internal/proxies/next`**
   - Fichiers : `03`, `14`
   - Effort : 1h
   - Risque : bugs S11 (alertes fonctionnent pas) et S4 (workers Node pas de proxy)

8. **LIA + DPIA documents papier (templates remplis pour 7 traitements)**
   - Nouveau : `docs/legal/lia.md`, `docs/legal/dpia.md`
   - Effort : ~8h Will + relecture juridique
   - Risque : sanction CNIL en cas de plainte sans LIA documenté

9. **Recalculer fichier 21 : Smartproxy résidentiel au Go + ligne captcha solver**
   - Fichier : `21`
   - Effort : 1-2h
   - Risque : sous-estimation budget mensuel × 3-5 à scale

10. **Prompt injection guard dans LLM Router**
    - Fichier : `07`
    - Effort : 2-3h
    - Risque : OWASP LLM #1 — extraction prompts système + exfiltration data

### P1 (IMPORTANT — pendant Phase 1)

11. **Cookie persistance + Cloudflare bypass plugin sur Infogreffe/Societe.com**
12. **Mouse humanization (ghost-cursor) + scroll velocity progressive sur Google Maps**
13. **OpenTelemetry traces actifs V1 (pas Phase 2)**
14. **Métriques business 5-7 dans dashboard "Vue exécutive"**
15. **Semantic cache LLM (pgvector embedding cosine)**
16. **i18n DB ready : colonnes `*_en` NULLABLE sur `naf_subclasses`, `auto_tag_definitions`, `strategic_keywords`**
17. **Tests E2E étendus à 10 parcours minimum + axe-core a11y**
18. **Worker LinkedIn warm-up procédure documentée + acceptation ToS explicite**
19. **Anthropic Message Batches API (-50 % coût) pour classifications non-temps-réel**
20. **`MonthlyRestoreTestJob` cron qui valide backup restore automatiquement**

### P2 (NICE-TO-HAVE — Phase 2)

- Terraform `infra/` provider Hetzner
- Feature flags (GrowthBook self-hosted ou table custom)
- Redis Sentinel / Cluster
- Pentest externe S12 (~3-5k€)
- Documentation utilisateur `docs/user-guide.md`
- Vidéo tutoriels onboarding

---

## Section 10 — Plan d'action concret

### Phase 0 — Patches spec (3-5 jours, AVANT Prompt 1)

1. **Jour 1** : créer `spec/v1.1/` avec patches P0 #1, #2, #3, #4 (anti-bot + SSRF + multi-country DB)
2. **Jour 2** : patches P0 #5, #6, #7 (PgBouncer + concurrency + tables manquantes)
3. **Jour 3** : rédiger LIA + DPIA templates (P0 #8) avec Will
4. **Jour 4** : patches P0 #9 + #10 (recalcul coûts + prompt injection guard)
5. **Jour 5** : relecture finale + commit `spec(v1.1): patches P0 audit feedback`

### Phase 1 — PoC anti-bot empirique (1-2 jours, AVANT Sprint 4)

Avant de lancer le Prompt 7 (workers Node Playwright), faire un PoC minimal :

```
PoC anti-bot — 2 jours
─ J1 :
   - Provisionner 1 worker-node Hetzner CPX31
   - Installer Playwright + stealth + proxies Smartproxy résidentiel + CapSolver
   - Tester scraping 100 résultats Google Maps "Paris boulangerie"
   - Mesurer : taux captcha (cible < 5 %), taux ban IP (cible < 1 %), latence p95
─ J2 :
   - Idem sur Crunchbase 20 entreprises
   - Idem sur Societe.com 50 SIREN
   - Rapport go/no-go : si taux ban > 10 %, escalate budget proxies ou changer stratégie
```

Coût PoC : ~50 € (proxies + captcha solver + 1 jour Hetzner serveur), + 2 jours Will.

**Si PoC échoue** : redéfinir stratégie scraping (par ex. réduire ambition Google Maps à 500/jour au lieu de 7 000, ou outsourcer scraping Google Maps à un service tiers spécialisé type Apify Actor Google Maps).

### Phase 2 — Implémentation V1 avec patches intégrés

Lancer Prompt 1 → Prompt 12 du fichier 23 partie B.4, en intégrant les patches P0 au passage. Les P1 sont à appliquer en parallèle pendant les sprints concernés.

### Phase 3 — Re-audit post-S12 (1 jour)

Après go-live, re-audit léger qui vérifie :
- Code conforme spec ?
- Métriques business OK ?
- Anti-bot tient à 1 mois de prod ?
- Conformité RGPD : DPO joignable, LIA / DPIA archivés ?

---

## Synthèse finale audit

| Indicateur | Valeur |
|---|---|
| **Note globale** | 7,3 / 10 |
| **P0 identifiés** | 10 |
| **P1 identifiés** | 10 |
| **P2 identifiés** | 6 |
| **Effort patches P0** | 3-5 jours |
| **Effort PoC anti-bot** | 1-2 jours |
| **Verdict** | **CORRECTIONS NÉCESSAIRES AVANT CODE** |

La spec est **techniquement compétente** mais avec des angles morts opérationnels qui coûteraient cher en re-travail. Le ratio "3-5 jours de patches maintenant vs 3-4 semaines de fix en S6-S8" est largement favorable aux corrections immédiates. La majorité des P0 sont des **ajouts ciblés**, pas des refontes — l'architecture générale est bonne.

Recommandation finale : **patch spec → PoC anti-bot → Prompt 1 implémentation**. Coût total avant Sprint 1 = ~5-7 jours Will. Économise probablement 4-6 semaines plus tard.

---

**Audit produit par Claude Opus 4.7 le 2026-05-16**
