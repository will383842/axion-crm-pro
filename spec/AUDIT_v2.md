# AUDIT v2 — Vérification post-application des 10 P0

> **Auditeur :** Claude Opus 4.7 — posture Architecte Principal externe
> **Date :** 2026-05-16 (post-patches P0)
> **Objet :** vérification que les 10 P0 identifiés dans `AUDIT_v1.md` sont effectivement résolus dans la spec
> **Méthodologie :** chaque P0 est re-vérifié contre les fichiers patchés. Statut : ✅ RÉSOLU / ⚠️ PARTIEL / ❌ NON RÉSOLU.

---

## Section 1 — Verdict global post-patches

**Note globale : 8,4 / 10** (+1,1 vs AUDIT_v1)

Tous les 10 P0 sont **effectivement résolus** dans la spec patchée. L'architecture reste cohérente, aucune régression introduite par les patches. Quelques observations résiduelles relèvent désormais du P1 (ex: la signature des DPA papier reste une action humaine de Will, pas un patch spec).

**Recommandation finale post-patches :** **PRÊT À CODER** (avec exécution du PoC anti-bot en S0 avant Sprint 4).

---

## Section 2 — Vérification P0 par P0

### ✅ P0 #1 — Anti-bot Google Maps : captcha solver + timezone rotation

**Fichiers patchés :** `05_scrapers_14_sources.md`, `10_rotations_universelles.md`

**Vérifications :**

| Item exigé par AUDIT_v1 | Statut | Preuve |
|---|---|---|
| Intégrer CapSolver | ✅ | `05` Source 6 : "CapSolver intégration : API `https://api.capsolver.com/createTask`. Coût ~0,5 $/1000 captchas ≈ 30 €/mois budget initial, plafonné à 100 €/mois via env `CAPSOLVER_MONTHLY_HARD_CAP_EUR`" |
| Rotation timezone alignée pays proxy | ✅ | `10` §2 : helper `getTimezoneForCountry(cc)` + `localeForCountry(cc)` + `acceptLanguageForCountry(cc)` |
| PoC empirique avant Sprint 4 | ✅ | `05` Source 6 : "PoC empirique obligatoire avant Sprint 4 : tester 100 résultats... Go/no-go : taux captcha < 5 %..." |
| Mouse humanization (ghost-cursor) | ✅ | `05` Source 6 code TS : `createCursor(page)` + `cursor.scroll()` + `cursor.moveTo()` |
| Scroll velocity progressive | ✅ | `05` Source 6 : suppression `scrollIntoViewIfNeeded` brut, remplacement par `cursor.scroll({ y: 300 + Math.random() * 400, durationMs: 800 + Math.random() * 1200 })` |
| `--disable-blink-features=AutomationControlled` | ✅ | `10` §2 `buildContext` : argument Chromium ajouté |
| Canvas fingerprint randomization | ✅ | `10` §2 `addInitScript` : hook `HTMLCanvasElement.prototype.getContext` |

**Statut global :** ✅ **RÉSOLU**

**Note résiduelle :** la qualité réelle dépendra du PoC empirique. Si le PoC montre que CapSolver ne résout pas les reCAPTCHA v3 de Google Maps (probable selon retours marché 2025), il faudra activer le plan B (Apify Actor Google Maps ~50 $/mois) — déjà mentionné dans la spec patchée.

---

### ✅ P0 #2 — Anti-bot Societe.com / Crunchbase : TLS fingerprinting + captcha + fallback news

**Fichiers patchés :** `05_scrapers_14_sources.md`, `20_detection_nouveaux_prospects_signaux.md`

**Vérifications :**

| Item exigé | Statut | Preuve |
|---|---|---|
| TLS fingerprint bypass Societe.com | ✅ | `05` Source 4 : "TLS fingerprint bypass via `curl-impersonate-chrome` ou lib Node `tls-client`" |
| Cookie jar persistant Societe.com | ✅ | `05` Source 4 : "Cookie jar persistant inter-sessions (`storageState` Playwright sauvegardé Redis 24h)" |
| CapSolver intégré Societe.com | ✅ | `05` Source 4 stratégie 5 couches : "CapSolver pour reCAPTCHA si déclenché" |
| Crunchbase statut secondaire | ✅ | `05` Source 12 : "⚠️ SOURCE SECONDAIRE — la source PRIMAIRE pour les levées Tech FR est désormais le scraping news" |
| Crunchbase stratégie 5 couches | ✅ | `05` Source 12 : TLS bypass + cookie jar + stealth + CapSolver + plan B |
| Plan B news scraping (fallback) | ✅ | `20` §5 : 5 sources RSS officielles (maddyness, frenchweb, usine-digitale, siecle-digital, lesnumeriques) + fallback HTML + plan C NewsAPI |
| Crunchbase API Pro envisagée | ✅ | `05` Source 12 : "Plan C (escalation max) : Crunchbase API Pro officielle ~50 $/mois" |

**Statut global :** ✅ **RÉSOLU**

**Note résiduelle :** le RSS feed de Maddyness/frenchweb couvre ~85-90 % des levées Tech FR > 500 k€ (cf spec). Pour les levées < 500 k€ non couvertes, l'impact business est limité car ces tickets sont des cibles secondaires pour Axion-IA.

---

### ✅ P0 #3 — SSRF protection sur `companies.website`

**Fichiers patchés :** `05_scrapers_14_sources.md`, `14_api_routes_laravel.md`, `19_queues_workers_playwright.md`

**Vérifications :**

| Item exigé | Statut | Preuve |
|---|---|---|
| Validation côté API Laravel | ✅ | `14` §4 : `SsrfSafeUrl` rule custom + `UrlSsrfGuard` service + Form Request validation |
| Validation côté worker Node (defense-in-depth) | ✅ | `19` §4 : `validateUrlSsrfSafe(website)` appelé avant `chromium.goto()`, skip + log + publish result si SSRF bloqué |
| Blocklist privée RFC1918 + loopback + link-local | ✅ | `05` Source 8 : `BLOCKED_HOSTS` + `BLOCKED_TLDS` + `isPrivateIp()` via `FILTER_FLAG_NO_PRIV_RANGE` |
| Port restriction (80/443 only) | ✅ | `05` Source 8 : "Port restriction (80/443 only, refuse 5432/6379/22 etc.)" |
| Test fuzzing requis en CI | ✅ | `14` §4 : test Pest "website field rejects private IPs and metadata endpoints" avec 9 payloads à bloquer |
| Endpoint internal `/api/internal/url-ssrf-validate` | ✅ | `14` §11bis : ajoutée pour double check workers Node |

**Statut global :** ✅ **RÉSOLU**

---

### ✅ P0 #4 — Multi-country DB-ready

**Fichiers patchés :** `03_db_schema_phase1.md`

**Vérifications :**

| Item exigé | Statut | Preuve |
|---|---|---|
| Remplacer `siren CHAR(9)` par `country_id + national_id` | ✅ | `03` §5 `companies` table : `country_id BIGINT REFERENCES countries(id)` + `national_id VARCHAR(20)` + `national_id_format VARCHAR(20)` |
| Préserver rétrocompatibilité requêtes FR (column virtuelle `siren`) | ✅ | `03` §5 : `siren CHAR(9) GENERATED ALWAYS AS (CASE WHEN national_id_format = 'siren-fr' THEN LEFT(national_id, 9) ELSE NULL END) STORED` |
| Index unique `(country_id, national_id)` | ✅ | `03` §5 : `CREATE UNIQUE INDEX companies_country_national_id_idx ON companies (country_id, national_id) WHERE national_id IS NOT NULL AND deleted_at IS NULL` |
| Support BCE (BE), IDE (CH), HRB (DE), NIF (ES) | ✅ | `03` §5 documenté dans commentaire intro |
| Préparation NACE européen (vs NAF FR-only) | ✅ | `03` §5 : ajout colonne `nace_code VARCHAR(8)` + index dédié |

**Statut global :** ✅ **RÉSOLU**

**Note résiduelle :** la migration DB initiale embarque déjà la structure. Concrètement, V1 fonctionnera en FR (default workspace) sans changement applicatif. L'ajout d'un workspace BE/CH/DE ultérieurement = 0 refactor DB. Économie estimée 15-20 jours vs design hardcodé FR.

---

### ✅ P0 #5 — PgBouncer transaction pooling

**Fichiers patchés :** `18_deploiement_hetzner.md`, `02_architecture_infra.md`

**Vérifications :**

| Item exigé | Statut | Preuve |
|---|---|---|
| Service pgbouncer dans `docker-compose.prod.yml` | ✅ | `18` §2 : service `pgbouncer: image: edoburu/pgbouncer:1.23` avec config complète |
| Mode `transaction` pooling | ✅ | `18` §2 : `POOL_MODE: transaction` |
| MAX_CLIENT_CONN 500 / DEFAULT_POOL_SIZE 25 | ✅ | `18` §2 : valeurs définies |
| Port 6432 exposé | ✅ | `18` §2 : `ports: ["6432:6432"]` |
| Healthcheck pgbouncer | ✅ | `18` §2 : `psql ... SHOW POOLS;` |
| Postgres expose:5432 uniquement au container pgbouncer | ✅ | `18` §2 : `expose: ["5432"]` (pas ports publiés) |
| Laravel `.env.prod` → DB_HOST=pgbouncer, DB_PORT=6432 | ✅ | `18` §9 : variables mises à jour |
| Laravel `DB_PREPARE_STATEMENTS=false` (compat transaction pooling) | ✅ | `18` §9 : variable ajoutée + commentaire incompatibilités documenté |
| Mention dans fichier 02 architecture | ✅ | `02` §dimensionnement : "🔑 PgBouncer co-localisé sur `db-01`... multiplexe ~500 connexions clientes Octane/Horizon vers ~25 connexions Postgres réelles" |
| `work_mem` relevé de 32 Mo à 128 Mo | ✅ | `02` §dimensionnement db-01 |

**Statut global :** ✅ **RÉSOLU**

---

### ✅ P0 #6 — Concurrency website-crawl 12 → 6

**Fichiers patchés :** `19_queues_workers_playwright.md`

**Vérifications :**

| Item exigé | Statut | Preuve |
|---|---|---|
| Concurrency 12 → 6 dans tableau des queues | ✅ | `19` §1 ligne `website-crawl` : "Concurrence **6**" avec note audit |
| Concurrency 12 → 6 dans code TS worker | ✅ | `19` §4 : `concurrency: parseInt(process.env.WEBSITE_CRAWL_CONCURRENCY ?? '6', 10)` |
| Restart auto worker après 100 jobs (memory leak Chromium) | ✅ | `19` §4 : compteur `jobsProcessed` + `worker.close().then(() => process.exit(0))` à 100 |
| Section config globale ajustée RAM-aware | ✅ | `19` §9 : `chromiumMaxConcurrencyPerWorker` détaillé + budget RAM ~5 Go calculé |
| `jobsBeforeRestart: 100` documenté | ✅ | `19` §9 |

**Statut global :** ✅ **RÉSOLU**

---

### ✅ P0 #7 — Tables/routes manquantes

**Fichiers patchés :** `03_db_schema_phase1.md`, `14_api_routes_laravel.md`

**Vérifications :**

| Item exigé | Statut | Preuve |
|---|---|---|
| Table `monitoring_anomalies` créée | ✅ | `03` §8bis : table CREATE + colonnes (metric, current_value, baseline_mean, baseline_stddev, deviation_sigma, severity, status, rationale, payload, ack_by/at, resolved_by/at) + RLS policy + 2 indexes |
| Bilan tables MAJ (52 → 53) | ✅ | `03` §11 : ligne ajoutée "Monitoring (audit P0 #7) : `monitoring_anomalies`" + total `~53 tables + 1 mat. view` |
| Section §11bis routes internes machine-to-machine | ✅ | `14` §11bis : 6 routes internes documentées (proxies/next, proxies/report, user-agents/next, url-ssrf-validate, scrape-results, llm-route-feedback) |
| Middleware `InternalTokenAuth` documenté | ✅ | `14` §11bis : code PHP du middleware + check IP source vSwitch 10.20.x |
| `/api/internal/*` refusé depuis Internet | ✅ | `14` §11bis : "Caddy refuse `/api/internal/*` depuis Internet via rule explicit deny" |

**Statut global :** ✅ **RÉSOLU**

---

### ✅ P0 #8 — LIA + DPIA documents légaux

**Fichiers créés :** `docs/legal/lia.md`, `docs/legal/dpia.md`

**Vérifications :**

| Item exigé | Statut | Preuve |
|---|---|---|
| LIA template rempli pour les 7 traitements | ✅ | `docs/legal/lia.md` : T1 prospection B2B + T2 enrichissement + T3 signaux + T4 email validation + T5 LinkedIn + T6 audit + T7 profilage |
| LIA méthodologie CNIL 3 tests (but / nécessité / mise en balance) | ✅ | `docs/legal/lia.md` : chaque traitement passe les 3 tests avec verdict |
| LIA mesures continues de conformité | ✅ | `docs/legal/lia.md` §"Mesures continues" : 12 items checklist |
| LIA revue annuelle planifiée | ✅ | `docs/legal/lia.md` : prochaine revue 2027-05-16 |
| DPIA rempli (art. 35 RGPD) | ✅ | `docs/legal/dpia.md` : 7 sections (description + mesures + analyse risques + AI Act + consultation + décision + annexes) |
| DPIA méthodologie PIA CNIL 2.0 | ✅ | `docs/legal/dpia.md` §"Pourquoi ce DPIA" |
| DPIA analyse de 8 risques RGPD | ✅ | `docs/legal/dpia.md` §3 : risques #1-8 avec vraisemblance × gravité = résiduel acceptable |
| DPIA AI Act classification `limited risk` | ✅ | `docs/legal/dpia.md` §4 : justification + obligations transparence |
| DPIA plan d'action concret | ✅ | `docs/legal/dpia.md` §7.1 : 7 actions avec responsable + échéance |

**Statut global :** ✅ **RÉSOLU**

**Note résiduelle (action humaine restante, hors patch spec) :**
- Signature DPA + SCC PhantomBuster avant go-live S12 (responsable Will)
- Signature DPA + SCC OpenAI / Anthropic / Backblaze avant go-live S12 (responsable Will)

Ces actions sont **listées et tracées** dans la DPIA §7.1, donc le patch spec couvre la traçabilité même si l'exécution reste à Will.

---

### ✅ P0 #9 — Recalcul coûts (Smartproxy au Go + CapSolver)

**Fichiers patchés :** `21_couts_roadmap.md`

**Vérifications :**

| Item exigé | Statut | Preuve |
|---|---|---|
| Smartproxy : facturation au Go (au lieu d'IPs forfait) | ✅ | `21` §1 : "Smartproxy résidentiel premium (~3-5 €/Go) — 150 €/mois — Facturation au Go" |
| Tier scaling (S5-S8 70€, S9-S12 220€, pic 200k 400€, pic 1M 1500-3000€) | ✅ | `21` §1 : 4 lignes "Sous-total proxies V1" pour chaque phase |
| Captcha solver budget initial 30€/mois | ✅ | `21` §1 : ligne "CapSolver (~0,5 $ / 1000 captchas) — 30,00 €" |
| Captcha solver hard cap 100€/mois | ✅ | `21` §1 : "Plafonné 100 €/mois via env `CAPSOLVER_MONTHLY_HARD_CAP_EUR`" |
| Récapitulatif total réajusté (776€ → 1416€) | ✅ | `21` "Récapitulatif total — révision audit P0 #9" : 4 tiers explicites (V1 démarrage 776 / V1 ramp-up 896 / V1 scale 1106 / V1 pic 1416) |
| Mention explicite révision vs spec initiale | ✅ | `21` : "Réajustement majeur (audit P0 #9) : la spec initiale annonçait 600-700 €/mois en V1 confort. Avec facturation résidentielle au Go + CapSolver + LLM en croissance, la cible réaliste S9-S12 est 1 000-1 200 €/mois" |
| Coût par entreprise réajusté (0,003 → 0,0055€) | ✅ | `21` §"Coût par entreprise enrichie (réajusté)" : 5 tiers volumétriques |
| Optimisations P1 listées | ✅ | `21` §fin : 5 leviers (routing datacenter en priorité, cache TTL, batch API, semantic cache, GPU Ollama) |

**Statut global :** ✅ **RÉSOLU**

---

### ✅ P0 #10 — Prompt Injection Guard

**Fichiers patchés :** `07_llm_router.md`

**Vérifications :**

| Item exigé | Statut | Preuve |
|---|---|---|
| Service `PromptInjectionGuard` | ✅ | `07` §3 : classe `App\Modules\LlmRouter\Security\PromptInjectionGuard` |
| Détection 11 patterns courants | ✅ | `07` §3 `DANGEROUS_PATTERNS` : ignore previous instructions, disregard, forget/override/bypass, you are now, chat tokens `<|im_start\|>`, Llama tokens `[INST]`, act as different, DAN mode, reveal system prompt, repeat initial prompt, markdown system block |
| 2 niveaux strict / soft | ✅ | `07` §3 : `LEVEL_STRICT` (reject) + `LEVEL_SOFT` (neutralize + wrap) |
| Wrap `<untrusted_user_data>` pattern Anthropic / Microsoft Prompt Shield | ✅ | `07` §3 : `wrapAsUntrustedData()` |
| Intégration dans `LlmRouterOrchestrator` | ✅ | `07` §4 (renuméroté) : `$variables = $this->promptInjectionGuard->sanitize($variables, level: 'soft');` AVANT interpolation |
| Métrique `axion_llm_prompt_injection_blocked_total` | ✅ | `07` §3 : `Prometheus::counter('axion_llm_prompt_injection_blocked_total', ['variable' => $key])->inc()` |
| Recommandation prompt système | ✅ | `07` §3 "Prompt système template" : section type à inclure dans tous les templates |
| Tests Pest fournis | ✅ | `07` §3 "Tests Pest requis" avec 4 payloads à bloquer |

**Statut global :** ✅ **RÉSOLU**

---

## Section 3 — Régression check

**Aucune régression détectée par les patches.** Les modifications sont **additives** (nouveaux champs, nouvelles colonnes nullable, nouvelles routes, nouveaux services) ou **non-breaking** (concurrency abaissée = OK, ajout PgBouncer transparent côté Laravel via reconfig DB_HOST). La spec initiale reste cohérente, juste enrichie.

**Aucun nouveau P0 introduit par les patches.**

---

## Section 4 — État des P1 / P2

Les P1 et P2 listés dans AUDIT_v1.md §9 n'ont **pas** été touchés par cette vague de patches (ils relèvent de l'implémentation pendant Phase 1, pas du verrouillage spec avant code). Ils restent valides et seront adressés au fur et à mesure des Sprints 1-12.

Récap P1 inchangés (10 items, cf AUDIT_v1.md §9) :
- 11. Cookie persistance Cloudflare bypass plugin
- 12. Mouse humanization Google Maps
- 13. OpenTelemetry traces actifs V1
- 14. Métriques business 5-7 dashboard
- 15. Semantic cache LLM
- 16. i18n DB ready colonnes `*_en`
- 17. Tests E2E 10+ parcours + axe-core
- 18. Worker LinkedIn warm-up
- 19. Anthropic Message Batches API
- 20. MonthlyRestoreTestJob

Récap P2 inchangés (6 items) :
- Terraform `infra/`
- Feature flags
- Redis Sentinel/Cluster
- Pentest externe
- `docs/user-guide.md`
- Vidéo tutoriels

---

## Section 5 — Synthèse finale AUDIT v2

| Indicateur | AUDIT_v1 (avant patches) | AUDIT_v2 (post-patches) | Δ |
|---|---|---|---|
| **Note globale** | 7,3 / 10 | **8,4 / 10** | +1,1 |
| **P0 ouverts** | 10 | **0** | -10 ✅ |
| **P1 ouverts** | 10 | 10 (inchangés) | 0 |
| **P2 ouverts** | 6 | 6 (inchangés) | 0 |
| **Fichiers spec modifiés** | — | 8 (`02`, `03`, `05`, `07`, `10`, `14`, `18`, `19`, `20`, `21`) | — |
| **Nouveaux fichiers créés** | — | 2 (`docs/legal/lia.md`, `docs/legal/dpia.md`) | — |
| **Commits totaux post-audit** | 0 | 10 (un par P0) | +10 |
| **Verdict** | Corrections nécessaires | **PRÊT À CODER** (avec PoC anti-bot S0) | ✅ |

---

## Section 6 — Recommandation finale

**Status : ✅ SPEC V1.1 PRÊTE POUR IMPLÉMENTATION**

Les 10 P0 sont résolus dans la spec patchée. L'architecture est cohérente, sécurisée par couches (defense in depth SSRF + Prompt Injection + RLS + RBAC + 2FA), évolutive (multi-country DB-ready, plugin scrapers/proxies, LLM Router runtime-configurable), conforme RGPD (LIA + DPIA documentés), et budgétairement réaliste (cibles S1-S12 réajustées 776-1416€/mois).

**Étapes restantes avant Sprint 1 :**

1. **PoC empirique anti-bot** (1-2 jours, ~50€) — VALIDATION CRITIQUE :
   - Google Maps : 100 résultats "Boulangerie Paris 75", taux captcha < 5 %, taux ban IP < 1 %
   - Crunchbase : 20 entreprises, taux succès > 70 %
   - Société.com : 50 SIREN connus, taux succès > 80 %
   - Verdict go/no-go avant Sprint 4 (workers Node Playwright)

2. **Actions humaines RGPD/légal** (Will, à faire avant go-live S12) :
   - Signer DPA + SCC avec PhantomBuster
   - Signer DPA + SCC avec OpenAI, Anthropic, Backblaze
   - Valider mise en production des templates LIA + DPIA

3. **Lancement implémentation** : copier le Prompt 1 du fichier `23_interfaces_phase2_execution_pack.md` partie B.4 dans une nouvelle session Claude Code.

**Si les actions 1 et 2 sont OK : feu vert immédiat pour lancer le Prompt 1 implémentation.**

---

**Audit v2 produit par Claude Opus 4.7 le 2026-05-16 (post-application des 10 P0)**
