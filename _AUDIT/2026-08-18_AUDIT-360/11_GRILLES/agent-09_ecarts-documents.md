# AGENT 9 — Écarts document ↔ code

> **Mission** : traquer toute affirmation d'un document du dépôt que le code contredit,
> et produire la liste des affirmations fausses à corriger. Livrable destiné à servir
> de lot de correction (P3).
>
> **Référence mesurée** : `main` a **avancé deux fois pendant cet audit** — `c0c453d`
> au début, `1145473` à mi-parcours, **`e8924b8`** à la fin. `git diff --name-only
> c0c453d..e8924b8` ne rend que **deux documents de `_REPORTS/`** et **un script neuf**
> (`infra/scripts/definir-mot-de-passe-crm.sh`) : **le code audité est identique à
> `c0c453d`**, et tous les constats ci-dessous valent pour les trois SHA. Le seul
> chiffre affecté est le nombre de scripts `.sh` suivis : **15** sur `c0c453d`, **16**
> sur `e8924b8`. Il est donné sous les deux formes là où il compte.
>
> **Aucun document n'a été modifié.** Le worktree `crmpro-wt-etape1a` n'a pas été touché.
> Sorties brutes : `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/agent-09/`.

---

## 0. Le socle de mesure — les chiffres auxquels tout le reste se compare

Rejoué sur `main`, archivé dans `04_PREUVES/agent-09/00_comptes.txt` :

| Objet | Mesuré |
|---|---|
| migrations (`backend/database/migrations/*.php`) | **58** |
| modèles Eloquent | **18** |
| contrôleurs (`app/Http/Controllers/**`) | **44** |
| fichiers sous `app/Services/` | **65** |
| seeders | **20** |
| commandes artisan (`app/Console/Commands/*.php`) | **49** |
| `routes/api.php` | **328 lignes** |
| fichiers `spec/*.md` | **26** |
| fichiers TypeScript dans `poc/` | **20** |
| specs Playwright (`frontend/tests/e2e/*.spec.ts`) | **16** |
| écrans (`features/**/*Page.tsx`) | **35** — routes déclarées : **37** |
| dashboards Grafana | **9** · jobs Prometheus : **7** · règles d'alerte : **8** |
| contrôleurs `Api/Phase2/` | **3** (Campaigns, ColdEmail, LinkedIn) |
| clés `CRM_*` dans `.env.example` | **15** |
| base locale (58/58 migrations appliquées) | **114 tables**, **55** avec RLS, **72** portant `workspace_id` |

⚠️ **Piège d'atelier rencontré, à consigner** : la base locale `axion_crm` est **mutée en
concurrence** par les autres agents de cet audit. Une mesure prise à 12h02 rendait
« 2 tables » — elle est tombée **pendant** un `migrate:fresh` d'un autre agent. Toute
mesure en base de cet audit doit être **horodatée** et **recroisée avec les fichiers de
migration**, sinon elle est ininterprétable. Les chiffres ci-dessus ont été repris une
fois `select count(*) from migrations` = 58 = nombre de fichiers.

---

## 1. Grille — un objet de périmètre par ligne

Colonnes : **Lu** (document parcouru) · **Mesuré** (au moins une affirmation rejouée par
commande) · **Écarts** (nombre d'affirmations fausses retenues) · **Verdict**.

### 1.1 Documents racine

| Document | Lu | Mesuré | Écarts | Verdict |
|---|---|---|---|---|
| `README.md` (127 l.) | ✅ | ✅ | 5 | Décrit un dépôt globalement réel, mais 3 commandes de sa section « Tests » ne peuvent pas s'exécuter |
| `TODO.md` (230 l.) | ✅ | ✅ | 6 | **Périmé en bloc.** Se déclare « source de vérité » et décrit un dépôt d'avant le code |
| `CHANGELOG.md` (57 l.) | ✅ | ✅ | 4 | Arrêté au 2026-05-17 ; 3 mois de travail non consignés |
| `ARCHITECTURE.md` (113 l.) | ✅ | ✅ | 3 | Chemins tous justes (12/12 vérifiés) ; les **comptes** sont faux |
| `CONTRIBUTING.md` (90 l.) | ✅ | ✅ | 5 | Annonce des portes de qualité qui n'existent pas |
| `MOCKS-STRATEGY.md` (208 l.) | ✅ | ✅ | 2 | Le plus fidèle des six ; 2 classes annoncées manquent |
| `docker-compose.local.yml` (commentaires) | ✅ | ✅ | 1 | Commentaires remarquablement exacts ; un compte off-by-one |
| `.gitattributes` (commentaires) | ✅ | ✅ | 1 | La promesse centrale du commentaire est fausse pour **8 scripts sur 16** |
| `backend/routes/console.php` (commentaires) | ✅ | ✅ | 2 | Deux commentaires « la commande n'existe pas encore » — elles existent |
| `Makefile` | ✅ | ✅ | 0 | Toutes les cibles citées par les autres docs (`up`, `ps`, `fresh`, `test`, `dr-drill`, `pentest`) existent |

### 1.2 `spec/` — 26 fichiers

| Objet | Lu | Mesuré | Écarts | Verdict |
|---|---|---|---|---|
| `00_INDEX.md` | ✅ | ✅ | 3 | Se déclare « implémentation à venir » ; annonce 24 fichiers pour 26 ; colonne « Lignes » fausse sur 13 lignes sur 24 |
| `13_ui_admin_phase1.md` | ✅ (titre + structure) | ✅ | 1 | « 17 pages Phase 1 + 5 Phase 2 » ⇒ 22 ; mesuré 37 routes, dont **3** stubs Phase 2 |
| `14_api_routes_laravel.md` | ✅ (via index) | ✅ | 1 | « 60-80 endpoints » ; mesuré 112 déclarations de route |
| `03_db_schema_phase1.md` / `04_db_schema_phase2_scaffold.md` | ✅ (via index) | ✅ | 1 | « ~32 + ~30 tables » = 62 ; mesuré 114 tables |
| 21 autres specs (`01`,`02`,`05`–`12`,`15`–`24`, `AUDIT_v1.md`) | 🟡 partiel | ❌ | — | **NON VÉRIFIÉ — raison au §4.** Documents de conception antérieurs au code (2026-05-16), sans prétention d'état ; le budget de l'agent est allé aux documents qui prétendent décrire l'**état** |

### 1.3 `_AUDIT/` — 18 documents, hors `2026-08-18_AUDIT-360/`

| Document | Lu | Mesuré | Écarts | Verdict |
|---|---|---|---|---|
| `DEPLOY-PIPELINE.md` (99 l.) | ✅ | ✅ | **4** | 🔴 **Le plus dangereux du lot.** Décrit une commande de déploiement qui n'est pas celle qui tourne, et deux pipelines Coolify qui n'existent pas |
| `MONITORING.md` (144 l.) | ✅ (60 l.) | ✅ | 1 | Décision GlitchTip cohérente ; `docker-compose.observability.yml` existe |
| `TODO-AXION-CRM-PRO.md` (207 l.) | ✅ (45 l.) | 🟡 | 0 retenu | Actions **hors dépôt** (facturation Google, budgets Mistral) : invérifiables depuis le code, cf. §4 |
| `AUDIT_1/2/3_2026-05-17.md`, `HARDENING-*`, `PROMPT-PROSPECTION-*`, `PROSPECTION-PIPELINE.md`, `SESSION-2026-05-18-*`, `SPRINT-H9-*`, `COST-ESTIMATION-*`, `PROD-ACTIVATION-RUNBOOK.md`, `AUDIT-E2E-PHASE1-2026-05-17/` | 🟡 inventoriés | ❌ | — | **NON VÉRIFIÉ — raison au §4** (sous-agents indisponibles : plafond de 20 agents concurrents atteint par l'audit) |

### 1.4 `_REPORTS/`

| Document | Lu | Mesuré | Écarts | Verdict |
|---|---|---|---|---|
| `PROGRESS.md` (111 l.) | ✅ | ✅ | **7** | 🔴 **Périmé en bloc, et lié depuis `README.md`.** Annonce S3→S12 « ⏳ pending » |
| `2026-08-19_INVENTAIRE-ETAPE-1A.md` (162 l.) | ✅ | ✅ | **4** | 🔴 Règle 7 appliquée : écrit aujourd'hui, **dépassé par le code du même jour** |
| `VALIDATION_PLAN.md` (140 l.) | ✅ (70 l.) | ✅ | 2 | Honnête sur sa méthode (« code non exécuté ») ; deux « bugs probables » depuis tranchés |
| `RUNBOOK-CONSOLE-LOCALE.md` (739 l.) | ✅ (60 l.) | ✅ | 1 | 🔴 Renvoie 4 fois vers le worktree résiduel `crmpro-wt-etape0` alors que le fichier est versionné à la racine |
| `_SESSIONS/2026-08-19_CRM-ETAPE-1A.md` (1398 l.) | ✅ | ✅ | 3 | Règle 7 appliquée. Journal chronologique de très bonne tenue ; 2 sections périmées non annotées + 1 SHA obsolète |
| `DPIA_2026-05-17.md`, `AIPD_2026-08-18.md`, `2026-08-17_CLOTURE-PLAN-CRM-E2E2.md`, `2026-08-18_*` (6 doc.), `2026-08-19_MESURE-COMPTEURS-HUB.md`, `REGISTRE-DES-VIOLATIONS-*`, `2026-08-19_BROUILLON-CNIL-*` | 🟡 inventoriés | ❌ | — | **NON VÉRIFIÉ — raison au §4** |

**Périmètre total : 70 objets** — 6 documents racine + 26 fichiers `spec/` + 18 documents
`_AUDIT/` (hors audit en cours) + 16 documents `_REPORTS/` + le journal `_SESSIONS/` +
3 fichiers de code porteurs de commentaires factuels.

- **22 objets passés en revue avec au moins une affirmation rejouée par commande.**
- **48 objets inventoriés sans mesure** — listés nommément au §4, avec la raison.
- **51 affirmations fausses retenues**, dont **12 qui feraient prendre une mauvaise décision**.

---

## 2. Le tableau de correction — les affirmations fausses

Format demandé : `document:ligne | ce qu'il affirme | commande jouée | ce que le code dit | mauvaise décision ? | correction`.

### 2.1 Rang 1 — celles qui font prendre une mauvaise décision (**oui**)

| # | document:ligne | Ce qu'il affirme | Commande jouée | Ce que le code dit | Mauvaise décision ? | Correction à apporter |
|---|---|---|---|---|---|---|
| 1 | `_AUDIT/DEPLOY-PIPELINE.md:14`, `:84`, `:97` | Le déploiement lance `docker compose up -d --force-recreate api app` | `grep -n 'docker compose up' .github/workflows/deploy-direct-ssh.yml` | Ligne 200 : `docker compose up -d --build --force-recreate --no-deps api app horizon scheduler`. Le document **omet `--no-deps`** et **omet `horizon scheduler`** | **OUI** | Recopier la commande réelle et **écrire explicitement** que `--no-deps` rend inapplicable toute modification de `docker-compose*.yml` portant sur `postgres`, `redis`, `reverb` |
| 2 | `_AUDIT/DEPLOY-PIPELINE.md:67-74` | « Les pipelines Coolify restent disponibles pour les déploiements officiels » ; tableau comparatif « staging → Coolify staging webhook », « prod → Coolify prod webhook + DR drill » | `sed -n '50,60p' .github/workflows/deploy-staging.yml` | Le fichier dit lui-même, en tête : « 🔴 **RÉÉCRIT le 2026-08-19 — Coolify RETIRÉ**, remplacé par le patron SSH […] **le CRM ne se déploie pas par Coolify** » | **OUI** | Supprimer les deux colonnes Coolify ; écrire que le CRM se déploie **uniquement** par `deploy-direct-ssh.yml` et que Coolify déploie le **site**, pas ce produit |
| 3 | `_AUDIT/DEPLOY-PIPELINE.md:63`, `:67` | Il existe un `deploy-prod.yml` | `ls .github/workflows/deploy-prod.yml` | `No such file or directory`. Les 17 workflows sont listés dans `00_comptes.txt` | **OUI** | Retirer la colonne « prod » ; il n'y a **pas** de pipeline de production distinct |
| 4 | `_AUDIT/DEPLOY-PIPELINE.md:16`, `:98` | Le pipeline exécute `php artisan config:clear` | `grep -n 'config:clear' .github/workflows/deploy-direct-ssh.yml` | Ligne 218 : « ⚠️ `config:clear` et `route:clear` ont été **RETIRÉS** d'ici » | OUI | Retirer l'étape du schéma ; dire pourquoi elle a été retirée |
| 5 | `_REPORTS/PROGRESS.md:11-21` | Tableau de bord : S2 « 🟡 step A done », **S3 à S12 « ⏳ pending »** | `git log -1 -- _REPORTS/PROGRESS.md` → `6331b02`, **2026-05-16** ; `ls backend/app/Services/Waterfall/`, `ls frontend/tests/e2e/`, `ls app/Services/Rgpd/` | Waterfall, LLM Router, workers Playwright, RGPD, 16 specs E2E, 49 commandes artisan **existent tous**. `README.md:50` dit « Sprints livrés (12/12) » | **OUI** — deux documents racine se contredisent frontalement sur l'état du produit | Soit mettre `PROGRESS.md` à jour, soit le marquer **ARCHIVÉ au 2026-05-16** en tête et retirer le lien de `README.md:125` |
| 6 | `_REPORTS/2026-08-19_INVENTAIRE-ETAPE-1A.md:38-39` | « **Activités (§2.3)** — ❌ aucune table, aucune colonne, aucune constante » et « **Motifs d'échange** — ❌ idem » | `ls backend/database/migrations/2026_08_19_000002_crm_activites_et_motifs.php` ; `ls backend/app/Crm/ActivitesEtMotifs.php` ; `grep -n 'DB::table' …/ActivitesEtMotifsSeeder.php` | Migration, constante `App\Crm\ActivitesEtMotifs`, seeder et **tables `crm_activites` / `crm_motifs`** existent — posés par `504737f`, **postérieur** au commit `a832d88` de l'inventaire | **OUI** — un lecteur d'aujourd'hui rebâtit ce qui existe, exactement la faute que le §28.5 cherche à éviter | Ajouter un encadré daté en tête du §2 : « ✅ livré depuis `504737f` (PR #176) — les lignes « Activités » et « Motifs » de ce tableau sont **caduques** » |
| 7 | `_REPORTS/2026-08-19_INVENTAIRE-ETAPE-1A.md:54`, `:62` | « **Aucune** n'a de modèle Eloquent, de contrôleur, de route ni d'écran » ; ligne `saved_views` → colonne « qui la cite » = « — » | `ls app/Http/Controllers/Api/SavedViewsController.php` ; `grep -n SavedViewsController backend/routes/api.php` | `SavedViewsController` **existe** ; `Route::apiResource('saved-views', …)` est enregistrée **ligne 195**. Aggravant : `index()` (ligne 15) rend `200 {"data":[]}` — les 4 autres verbes rendent 501 | **OUI** — et c'est le constat A-002 vu depuis l'autre bout : la seule route de `saved_views` **ment** | Corriger la ligne : « contrôleur **présent**, route **enregistrée** ; `index` rend un 200 vide trompeur, les 4 autres verbes rendent 501 ». Corriger aussi « Six tables » : **sept** sont nommées |
| 8 | `.gitattributes:19-21` (commentaire) | « `eol=lf` force la copie de travail elle-même en LF pour tout ce qui est exécuté par une machine — **plus de divergence** entre ce qu'on lit, ce qu'on commite et ce qu'on envoie » | `git ls-files '*.sh' \| while read f; do od -An -tx1 -v "$f" \| tr ' ' '\n' \| grep -c '^0d$'; done` | **8 scripts `.sh` sur 16 sont encore intégralement en CRLF** : `dr-drill.sh` 205/205, `backup-postgres.sh` 181/181, `verifier-sauvegarde.sh` 155/155, `setup-hetzner-cpx22.sh` 149/149, `setup-backup.sh` 116/116, `configure-prod-env.sh` 103/103, `entrypoint-prod.sh` 51/51, `mesure_reference.sh` 29/29. `.gitattributes` n'agit qu'à la **prochaine extraction** : la renormalisation n'a jamais été jouée | **OUI** — le défaut qui a cassé le déploiement du 19/08 reste **armé sur la moitié des scripts**, pendant que le commentaire affirme qu'il ne l'est plus (recoupe A-003) | Remplacer par : « `eol=lf` ne prend effet qu'à l'**extraction suivante**. Sur une copie de travail existante il faut jouer `git add --renormalize . && git checkout -- .` — **non fait : 8 scripts sur 16 sont encore en CRLF au 2026-08-19** » |
| 9 | `CONTRIBUTING.md:16-17` | Portes de qualité : « Pest backend **≥ 75 %** de couverture sur services métier » et « Vitest frontend **≥ 60 %** de couverture » | `cat frontend/vitest.config.ts` ; `grep -n coverage .github/workflows/ci.yml` | `vitest.config.ts` porte lui-même l'avertissement : « ⚠️ **SEUILS DÉCORATIFS EN L'ÉTAT.** La CI lance `pnpm test`, jamais `pnpm test:coverage` : ces nombres ne bloquent rien et n'ont **jamais** rien bloqué ». Côté backend, `ci.yml:245` pose `coverage: none` et `composer test` = `pest --colors`, **sans seuil** | **OUI** — une revue qui écrit « la couverture est gardée » raisonne sur une fausse sécurité, exactement le patron du §Vérité-des-gates | Déplacer ces deux lignes hors de « Quality gates » vers une section « Objectifs **non gardés** », en recopiant l'avertissement de `vitest.config.ts` |
| 10 | `_REPORTS/RUNBOOK-CONSOLE-LOCALE.md:41`, `:331`, `:344`, `:575` | Pour démarrer la pile : `-f C:/Users/willi/Documents/Projets/**crmpro-wt-etape0**/docker-compose.local.yml` | `git ls-files docker-compose.local.yml` → **suivi à la racine** ; `diff --strip-trailing-cr` des deux copies → **contenu identique** | Le fichier est **versionné à la racine du dépôt** depuis. Le runbook fait dépendre le démarrage d'un **worktree résiduel** que le dossier commun désigne comme « à ne pas utiliser comme référence » | **OUI** — quiconque supprime `crmpro-wt-etape0` ne peut plus suivre le runbook, et le lecteur croit que l'atelier local n'est pas autonome | Remplacer les 4 occurrences par `-f docker-compose.local.yml` (chemin relatif à la racine) |
| 11 | `ARCHITECTURE.md:64` · `CHANGELOG.md:38` · `_REPORTS/PROGRESS.md` (§ décisions) | « RLS : **30+ tables** workspace-scoped » / « RLS PostgreSQL sur **30 tables** » | `psql -c "SELECT count(*) … WHERE relrowsecurity"` = **55** ; `… column_name='workspace_id'` = **72** ; liste des tables `workspace_id` sans RLS | 55 tables portent RLS, mais **72** portent `workspace_id` : **`audit_logs` (table partitionnée), `audit_logs_default`, `sessions`, `user_workspaces` n'ont PAS de RLS** | **OUI** — le chiffre « 30 » sous-estime la couverture *et* masque le trou ; `audit_logs` est précisément la pièce que `ARCHITECTURE.md:47` et `CONTRIBUTING.md:44` présentent comme le socle inviolable | Écrire le chiffre mesuré (**55/72**) **et** nommer les 4 exceptions avec leur justification — ou ouvrir un constat si elles n'en ont pas |
| 12 | `TODO.md:21` | « **Code Sprint 1 → S12** : 🟡 **Sprint 1 en cours** » — dans un document qui s'annonce ligne 3 comme « **Source de vérité** de ce qu'il reste à faire » | `git log -1 -- TODO.md` → **2026-05-16** ; comparer à `README.md:50` « Sprints livrés (12/12) » et aux 58 migrations / 44 contrôleurs / 49 commandes | Les 12 sprints sont livrés. Le document décrit un dépôt d'avant la première ligne de code | **OUI** — deux « sources de vérité » racine se contredisent ; celle qui revendique le titre est la plus fausse | Retirer la mention « source de vérité » **ou** réécrire le document. Le §2 (« Démarrer UNIQUEMENT après POCs validés ») et le §7 (calendrier) sont entièrement caducs |

### 2.2 Rang 2 — faux, mais sans conséquence de décision (**non**)

| # | document:ligne | Ce qu'il affirme | Commande jouée | Ce que le code dit | Correction |
|---|---|---|---|---|---|
| 13 | `README.md:85` · `CONTRIBUTING.md:77-78` | `docker exec axion-crm-worker-google-maps pnpm test` / `pnpm typecheck` | `docker ps --format '{{.Names}}'` ; `grep -nE '^  [a-z-]+:' docker-compose.yml` | **Aucun conteneur `axion-crm-worker-*`.** `docker-compose.yml` n'a pas de service worker (seul `docker-compose.test.yml` en déclare 3) ; `docker-compose.yml:187` le dit : le dossier `workers/` est « conservé » et testé **par la CI**, pas par la pile locale | Remplacer par `cd workers && pnpm test` |
| 14 | `README.md:13` | `docker compose up -d` démarre « Postgres + Redis + Caddy + api + horizon + scheduler + app + **workers** » | `grep -nE '^  [a-z-]+:' docker-compose.yml` | 8 services, **aucun worker** | Retirer « + workers » |
| 15 | `README.md:88` · `:91` | `cd frontend && pnpm e2e` ; `k6 run … infra/loadtest/k6-api.js` | `ls frontend/e2e` → absent ; `ls infra/loadtest/` → `k6-api.js` présent | `pnpm e2e` existe bien (script `playwright test`) mais les specs sont dans `frontend/tests/e2e/`, pas `frontend/e2e/`. Le chemin k6 est **juste** | Rien sur k6 ; préciser `frontend/tests/e2e/` là où le README parle du dossier |
| 16 | `README.md:65` | Sprint 12 : « Playwright **× 17** » | `find frontend/tests/e2e -name '*.spec.ts' \| wc -l` | **16** | 16 |
| 17 | `CHANGELOG.md:33` | Sprint 12 : « **5** specs Playwright E2E » | idem | **16** — et contredit `README.md:65` qui dit 17 | Aligner les deux sur 16 |
| 18 | `CHANGELOG.md:13` · `README.md:55` | « **9** migrations » / « **9** migrations Phase 1+2 + **13** seeders » | `ls backend/database/migrations/*.php \| wc -l` ; `ls backend/database/seeders/*.php \| wc -l` | **58** migrations, **20** seeders | Actualiser, ou dater explicitement la ligne comme un instantané de sprint |
| 19 | `CHANGELOG.md:14` | « Sprint 2 — **8** artisan commands » | `ls backend/app/Console/Commands/*.php \| wc -l` | **49** | 49 — et surtout : **aucune entrée de changelog depuis le 2026-05-17**, alors que le dépôt a reçu 3 mois de commits |
| 20 | `_REPORTS/PROGRESS.md:39` | « **27** controllers API » | `find backend/app/Http/Controllers -name '*.php' \| wc -l` | **44** | 44 |
| 21 | `_REPORTS/PROGRESS.md:37` | « **10** models Eloquent » | `ls backend/app/Models/*.php \| wc -l` | **18** | 18 |
| 22 | `_REPORTS/PROGRESS.md:43` | « TanStack Router (**20** routes) » | `grep -coE "path: '[^']+'" frontend/src/app/routeTree.tsx` | **37** | 37 |
| 23 | `_REPORTS/PROGRESS.md:59` · `:21` | « Phase 2 (campaigns/cold-email/linkedin/**crm**/**analytics**) → **5** `__invoke` retournent 501 » | `ls backend/app/Http/Controllers/Api/Phase2/` | **3** fichiers : Campaigns, ColdEmail, LinkedIn. `AnalyticsController` et `CrmController` **n'existent pas** (recoupe l'inventaire §4 du dossier) | 3 stubs, et nommer lesquels |
| 24 | `_REPORTS/PROGRESS.md:94` (Sprint 2, reste à faire) | « pg_partman bootstrap (audit_logs partitioning) » — présenté comme **restant à faire** | `psql -c "SELECT relkind …"` | `audit_logs` est **`relkind = 'p'`** (table partitionnée), `audit_logs_default` + **14 partitions mensuelles** existent, extension `pg_partman` installée | Fait ; retirer de la liste |
| 25 | `_REPORTS/VALIDATION_PLAN.md:63-64` | « Migration `audit_logs` BIGSERIAL PRIMARY KEY (id seul) — **pas partitionné** contrairement à la spec/03 (partman bootstrap retiré pour simplicité MVP, ré-ajouter Sprint 13) » | idem ligne 24 | Partitionné, avec `pg_partman` | Retirer ce « bug probable » : il est tranché, dans l'autre sens |
| 26 | `_REPORTS/VALIDATION_PLAN.md:37-39` | Trois « bugs probables restants (déduits sans exec) » : `cheerio`, `@axe-core/playwright`, Spatie QueryBuilder | `cat frontend/tests/e2e/a11y.spec.ts` (`import AxeBuilder from '@axe-core/playwright'` en place et utilisé) | La question `AxeBuilder` est tranchée. Le document est **honnête** sur sa méthode (« le code livré n'a pas été exécuté ») — mais il n'a jamais été repassé après exécution | Ajouter un bandeau « repassé le …, N/3 tranchés » ou archiver le document |
| 27 | `CONTRIBUTING.md:7` | « PR template auto-rempli (description + test plan + check-list sécurité) » | `ls .github/PULL_REQUEST_TEMPLATE*` | **Absent** de `.github/` (qui ne contient que `dependabot.yml` et `workflows/`) | Créer le template, ou retirer la ligne |
| 28 | `CONTRIBUTING.md:85` | « Ouvrir un ticket post-mortem dans `_INCIDENTS/YYYY-MM-DD-<topic>.md` » | `ls _INCIDENTS` | **Le dossier n'existe pas.** Les post-mortems réels vivent dans `_REPORTS/` et `_SESSIONS/` | Pointer vers `_REPORTS/`, ou créer `_INCIDENTS/` |
| 29 | `TODO.md:123` | « Produire `_DOCS/DPIA-2026.md` » — case non cochée | `ls _DOCS` → absent ; `ls _REPORTS/DPIA_2026-05-17.md` → **présent** | Le DPIA **existe**, sous un autre chemin ; une AIPD complémentaire existe aussi (`_REPORTS/AIPD_2026-08-18.md`) | Cocher la case et corriger le chemin |
| 30 | `TODO.md:17` | « POCs codés ✅ 100 % — **50 fichiers TypeScript** prêts dans `./poc/` » | `find poc -name '*.ts' -not -path '*/node_modules/*' \| wc -l` | **20** | 20 |
| 31 | `TODO.md:80` | « [ ] Créer `poc/SYNTHESIS.md` » — non coché, alors que la **ligne 40 du même document** dit « Voir `poc/SYNTHESIS.md` § POC #5 » | `ls poc/SYNTHESIS.md` | Le fichier **existe**. Le document se contredit à 40 lignes d'intervalle | Cocher la case |
| 32 | `TODO.md:13` | « **26 fichiers spec** + AUDIT_v1 » (⇒ 27) | `ls spec/*.md \| wc -l` | **26 au total**, AUDIT_v1 compris (= `00_INDEX` + 24 numérotés + `AUDIT_v1`) | « 25 fichiers spec + AUDIT_v1 » |
| 33 | `spec/00_INDEX.md:7` | « **Statut** : Spec exhaustive — **implémentation à venir** » | `ls backend/app/Services \| wc -l` = 65 fichiers ; 58 migrations | L'implémentation est là depuis 3 mois | « Implémentée — écarts consignés dans `_AUDIT/` » |
| 34 | `spec/00_INDEX.md:6` · `:20` | « Format : **24 fichiers** Markdown » / « Sommaire des **24 fichiers** » | `ls spec/*.md \| wc -l` | **26** (24 numérotés + `00_INDEX` + `AUDIT_v1`) | 26 |
| 35 | `spec/00_INDEX.md:25-60` (colonne « Lignes ») | `00_INDEX` ~400 · `01` ~600 · `02` ~700 · `03` ~1500 · `04` ~1000 · `05` ~1800 · `06` ~900 · `08` ~800 · `09` ~700 · `10` ~700 · `11` ~700 · `12` ~800 · `13` ~1500 · `14` ~1100 | `wc -l spec/*.md` | **289 · 397 · 806 · 1932 · 844 · 1576 · 524 · 337 · 347 · 310 · 437 · 328 · 614 · 473**. `08` annoncé ~800 fait **337** (÷2,4) ; `13` annoncé ~1500 fait **614** (÷2,4) ; `06` annoncé ~900 fait **524** | Recalculer la colonne, ou la supprimer (elle n'apporte rien et vieillit mal) |
| 36 | `spec/13_ui_admin_phase1.md:1` | « UI admin Phase 1 (**17 pages**) + Phase 2 scaffold (**5 pages**) » ⇒ 22 | `grep -coE "path: '[^']+'" frontend/src/app/routeTree.tsx` ; `ls app/Http/Controllers/Api/Phase2/` | **37** routes, dont **3** stubs Phase 2. `/crm` et `/analytics` n'existent pas | Écrire le compte réel, ou marquer le titre comme une cible de conception et non un état |
| 37 | `spec/14_api_routes_laravel.md` (via `00_INDEX:59`) | « **60-80 endpoints** REST » | `wc -l backend/routes/api.php` = 328 ; 112 déclarations de route | **112** | 112 |
| 38 | `ARCHITECTURE.md:82` | « Grafana — **8** dashboards provisioned » | `ls infra/monitoring/grafana/dashboards/ \| wc -l` | **9** | 9 |
| 39 | `ARCHITECTURE.md:81` | « Prometheus — metrics scrape (**6 jobs**) + alerts (**8 rules**) » | `grep -c job_name …/prometheus.yml` ; `grep -c '^\s*- alert:' …/alerts.yml` | **7** jobs (les 8 règles sont **justes**) | 7 jobs |
| 40 | `ARCHITECTURE.md:3` | « Source de vérité détaillée : `spec/00_INDEX.md` + **25 fichiers spec** » | `ls spec/*.md \| wc -l` | 26 au total ⇒ `00_INDEX` + **25 autres** : **exact**. Signalé ici parce qu'il **contredit** `TODO.md:13` (26+1) | Aligner `TODO.md` sur `ARCHITECTURE.md`, qui a raison |
| 41 | `MOCKS-STRATEGY.md:15` · `:26` · `:27` | Tableau des mocks : `MockCaptchaSolver`, `MockDnsManager`, `MockEmailSender` | `find backend/app -name 'Mock*.php'` | `MockCaptchaSolver` **existe**. `MockDnsManager` et `MockEmailSender` **n'existent pas**. En revanche `MockPagesJaunesScraper` existe et **n'est pas au tableau** | Retirer les 2 lignes non implémentées, ajouter Pages Jaunes |
| 42 | `MOCKS-STRATEGY.md:86-111` | Arborescence des fixtures : 7 dossiers (`insee`, `annuaire-entreprises`, `google-maps`, `google-search`, `llm`, `direction-finder`, `smtp`) | `ls backend/tests/fixtures/` | **4** : `google-maps`, `insee`, `llm`, `smtp`. `annuaire-entreprises`, `google-search`, `direction-finder` absents | Aligner l'arborescence sur le réel |
| 43 | `docker-compose.local.yml:40` (commentaire) | « il lui manquait les **16 drapeaux `CRM_*`** ajoutés depuis à `.env.example` » | `grep -oE '^#?\s*CRM_[A-Z0-9_]+' .env.example \| sort -u \| wc -l` | **15** | 15 |
| 44 | `backend/routes/console.php:28-30` | « La commande `companies:rescrape-archives` **est codée dans le Sprint Hardening (H6)**. **En attendant**, le schedule est posé mais s'auto-skip si la commande n'existe pas » | `grep -n signature app/Console/Commands/RescrapeArchivesCommand.php` | La commande **existe** (`companies:rescrape-archives`). Le `skip()` est donc toujours faux : **le re-scrape mensuel tourne** le 1er du mois sur le stock archivé | Réécrire : « commande livrée ; le `skip()` est conservé comme garde-fou et ne se déclenche plus » |
| 45 | `backend/routes/console.php:44-49` | Même patron pour `companies:retry-google-places` | `grep -n signature app/Console/Commands/RetryGooglePlacesCommand.php` | La commande **existe** | idem |
| 46 | `_SESSIONS/2026-08-19_CRM-ETAPE-1A.md:1001` | « `main` = `d4910c8`. Aucune PR ouverte » | `git log -1 --format=%H` | `main` = **`1145473`** ; 7 commits depuis. Le dossier commun l'écrit : « **N'utilise AUCUN SHA écrit dans un document. Ils ont été faux trois fois** » | Retirer le SHA, ou le dater explicitement (« à la clôture de la session du 19/08 à 09h ») |
| 47 | `_SESSIONS/2026-08-19_CRM-ETAPE-1A.md:418` (§3.1) | Titre : « 🔴 **La préproduction N'EXISTE PAS** » | `ls docker-compose.staging.yml` ; `git log --oneline` | Le fichier existe (12 080 o.) et le §11 du **même document** s'intitule « **La PRÉPRODUCTION existe** » ; commits `8030a1b`, `377b902` | Le §11 supersède, mais le §3.1 n'a **aucune annotation**. Ajouter en tête du §3.1 : « ⚠️ **caduc** — voir §11 » |
| 48 | `_SESSIONS/2026-08-19_CRM-ETAPE-1A.md:771` (§6.5) | « État : **RIEN n'a été écrit** pour la préproduction » | idem | idem | idem — annoter « caduc, voir §11 » |
| 49 | `_REPORTS/2026-08-19_INVENTAIRE-ETAPE-1A.md:53` | « **Six** tables de la phase 2 » | Compter les noms cités lignes 58-62 | **Sept** sont nommées : `crm_tasks`, `crm_notes`, `crm_pipelines`, `pipeline_stages`, `deals`, `deal_history`, `saved_views` | Sept |
| 51 | `frontend/vitest.config.ts:40` (commentaire) | « La CI (**`.github/workflows/ci.yml:295-297`**) lance `pnpm test`, jamais `pnpm test:coverage` » | `sed -n '293,299p' .github/workflows/ci.yml` ; `grep -n 'pnpm test' .github/workflows/ci.yml` | Les lignes 293-299 sont un bloc `git diff --diff-filter` **Pint backend PHP**, sans rapport. Le vrai `pnpm test` frontend est **ligne 459** (workers : 531). Le **fond** du commentaire est en revanche **exact** : `grep -rn 'test:coverage' .github/workflows/` ne rend **aucune** occurrence | Corriger la référence en `ci.yml:459`. Ce commentaire est par ailleurs le meilleur du dépôt : c'est lui qui a permis de qualifier l'écart n°9 |
| 50 | `_REPORTS/2026-08-19_INVENTAIRE-ETAPE-1A.md:64-66` | « Les **seuls** fichiers qui les nomment sont `PentestSelfCheck.php`, `SemeurTablesScopees.php` et `PasDeStub501SousCrmEtAnalyticsTest.php` » | `grep -rl 'saved_views' backend/ frontend/src/` ; `ls app/Http/Controllers/Api/SavedViewsController.php` | Faux pour `saved_views` : contrôleur + route. Vrai pour les six autres — **vérifié** : `grep -rl` sur `crm_tasks`, `crm_notes`, `crm_pipelines`, `pipeline_stages`, `deals`, `deal_history` dans `backend/app`, `backend/routes`, `frontend/src` ne rend **rien** | Sortir `saved_views` de la liste des tables « inertes » |

---

## 3. Constats

### [A09-001] `_AUDIT/DEPLOY-PIPELINE.md` décrit une commande de déploiement qui n'est pas celle qui tourne, et omet `--no-deps`
- Sévérité      : **S1**
- Domaine       : conformité / sécurité (documentation d'exploitation)
- Référence     : main `c0c453d` (code identique à `1145473`)
- Emplacement   : `_AUDIT/DEPLOY-PIPELINE.md:14`, `:84`, `:97`
- Constat       : le document écrit `docker compose up -d --force-recreate api app` là où `.github/workflows/deploy-direct-ssh.yml:200` lance `docker compose up -d --build --force-recreate --no-deps api app horizon scheduler`.
- Preuve        : `grep -n 'docker compose up' .github/workflows/deploy-direct-ssh.yml` → `200: … --no-deps api app horizon scheduler` ; `grep -n 'docker compose up' _AUDIT/DEPLOY-PIPELINE.md` → 3 occurrences sans `--no-deps`. Fichier : `04_PREUVES/agent-09/03_deploiement.txt`.
- Témoin négatif: le même `grep` sur le workflow **trouve** la ligne réelle (200) — le contrôle sait donc trouver ce qu'il cherche ; c'est bien le document qui diverge, pas la commande qui manque.
- Impact        : c'est **le document de référence du pipeline**. Quiconque le lit conclut qu'un changement de `docker-compose*.yml` est appliqué par le déploiement. C'est faux pour `postgres`, `redis` et `reverb` — et c'est exactement ce qui a laissé la faille du 19/08 ouverte alors que le déploiement était vert (journal §7). Les procédures « rollback rapide » (:88) et « manuelle équivalente » (:97) laisseraient en outre `horizon` et `scheduler` sur l'ancien code, sans que rien ne le signale.
- Reproduction  : lire `_AUDIT/DEPLOY-PIPELINE.md` § « Vue d'ensemble », puis `.github/workflows/deploy-direct-ssh.yml:200`.
- Correctif     : recopier la commande réelle et ajouter un encadré nommant les trois services hors d'atteinte du déploiement. ~30 min.
- Statut        : ouvert

### [A09-002] `_REPORTS/PROGRESS.md`, lié depuis `README.md`, annonce S3→S12 « pending » alors que `README.md` annonce 12/12 livrés
- Sévérité      : **S1**
- Domaine       : conformité (pilotage)
- Référence     : main `c0c453d`
- Emplacement   : `_REPORTS/PROGRESS.md:11-21` ; lien depuis `README.md:125`
- Constat       : le tableau de bord de `PROGRESS.md` porte S2 « 🟡 step A done » et S3 à S12 « ⏳ pending », tandis que `README.md:50` titre « Sprints livrés (12/12) ».
- Preuve        : `git log -1 --format='%h %ad' -- _REPORTS/PROGRESS.md` → `6331b02 2026-05-16` (jamais retouché depuis) ; `ls backend/app/Services/Waterfall/WaterfallOrchestrator.php`, `ls backend/app/Services/Rgpd/`, `find frontend/tests/e2e -name '*.spec.ts' | wc -l` → 16, `ls backend/app/Console/Commands/*.php | wc -l` → 49. Fichier : `04_PREUVES/agent-09/00_comptes.txt`.
- Témoin négatif: le même `git log -1 --` sur `_SESSIONS/2026-08-19_CRM-ETAPE-1A.md` rend bien une date du 19/08 — la commande sait distinguer un document vivant d'un document figé.
- Impact        : deux documents racine se contredisent sur l'état du produit, et le plus détaillé des deux est le plus faux. Un arrivant qui suit le lien du `README` conclut que 10 sprints sur 12 restent à faire.
- Reproduction  : ouvrir `README.md:50` puis `_REPORTS/PROGRESS.md:12`.
- Correctif     : marquer `PROGRESS.md` « ARCHIVÉ au 2026-05-16 » en tête et retirer le lien du `README`, **ou** le réécrire. Archivage : ~15 min. Réécriture : ~3 h.
- Statut        : ouvert

### [A09-003] L'inventaire de l'étape 1a déclare absentes des activités et des motifs qui existent en base depuis le même jour
- Sévérité      : **S2**
- Domaine       : conformité (pilotage du chantier cible)
- Référence     : main `c0c453d`
- Emplacement   : `_REPORTS/2026-08-19_INVENTAIRE-ETAPE-1A.md:38-39`
- Constat       : le tableau « Ce qui N'EXISTE PAS DU TOUT — à construire » porte « Activités (§2.3) — ❌ aucune table, aucune colonne, aucune constante » et « Motifs d'échange — ❌ idem », alors que la migration, la constante, le seeder et les tables existent sur `main`.
- Preuve        : `ls backend/database/migrations/2026_08_19_000002_crm_activites_et_motifs.php` ; `ls backend/app/Crm/ActivitesEtMotifs.php` ; `grep -n 'DB::table' backend/database/seeders/ActivitesEtMotifsSeeder.php` → `crm_activites`, `crm_motifs`. Chronologie : `git log -1 -- _REPORTS/2026-08-19_INVENTAIRE-ETAPE-1A.md` → `a832d88` ; `git merge-base --is-ancestor 504737f a832d88` → **faux** (le code est **postérieur** à l'inventaire). Fichier : `04_PREUVES/agent-09/04_inventaire_etape1a.txt`.
- Témoin négatif: le même contrôle appliqué aux six autres lignes du tableau (« Dossiers », « Écran d'entretien », « Vue aujourd'hui », « Règles d'attribution », « Compte rendu », « Telegram ») ne trouve **rien** — le contrôle sait donc rendre « absent » quand c'est absent.
- Impact        : l'inventaire est le document qui, par le §28.5, **fixe l'ordre des pièces**. Un lecteur d'aujourd'hui reconstruit une taxonomie qui existe — la faute exacte que le §28.5 cherche à éviter (« on étend, on ne réinvente pas »).
- Reproduction  : lire le §2 du document, puis `ls backend/app/Crm/`.
- Correctif     : encadré daté en tête du §2. ~10 min. (Le document reste juste sur les six autres lignes.)
- Statut        : ouvert

### [A09-004] L'inventaire de l'étape 1a déclare `saved_views` sans contrôleur ni route ; les deux existent
- Sévérité      : **S2**
- Domaine       : backend / conformité (pilotage)
- Référence     : main `c0c453d`
- Emplacement   : `_REPORTS/2026-08-19_INVENTAIRE-ETAPE-1A.md:54`, `:62`
- Constat       : le §3 affirme que des six (en réalité **sept**) tables de la phase 2, « aucune n'a de modèle Eloquent, de contrôleur, de route ni d'écran », et met « — » dans la colonne « qui la cite » pour `saved_views`.
- Preuve        : `ls backend/app/Http/Controllers/Api/SavedViewsController.php` → présent ; `grep -n 'SavedViewsController' backend/routes/api.php` → `33: use …` et **`195: Route::apiResource('saved-views', SavedViewsController::class);`**. `sed -n '15p'` du contrôleur → `public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }`. Fichier : `04_PREUVES/agent-09/04_inventaire_etape1a.txt`.
- Témoin négatif: le même `grep -rl` sur `crm_tasks`, `crm_notes`, `crm_pipelines`, `pipeline_stages`, `deals`, `deal_history` dans `backend/app`, `backend/routes` et `frontend/src` ne rend **aucun** fichier — le contrôle sait rendre vide quand c'est vide.
- Impact        : la table présentée comme « inerte et sans surface » a en réalité **cinq routes publiées**, dont un `index` qui rend `200 {"data":[]}` au lieu de 501 (c'est A-002 vu depuis le document). Un lecteur qui planifie « réveiller `saved_views` » ignore qu'il doit d'abord corriger une route qui ment déjà.
- Reproduction  : lire le §3 du document, puis `backend/routes/api.php:195`.
- Correctif     : corriger la ligne et le compte (« sept tables »). ~10 min.
- Statut        : ouvert

### [A09-005] Le commentaire de `.gitattributes` affirme qu'il n'y a « plus de divergence » ; 8 scripts `.sh` sur 16 sont encore en CRLF
- Sévérité      : **S1**
- Domaine       : conformité / déploiement
- Référence     : main `c0c453d` (15 scripts `.sh` suivis, 8 en CRLF) ; état identique sur `e8924b8` (16 suivis, 8 en CRLF — le script neuf est en LF)
- Emplacement   : `.gitattributes:19-21`
- Constat       : le commentaire écrit « `eol=lf` force la copie de travail elle-même en LF […] **plus de divergence** entre ce qu'on lit, ce qu'on commite et ce qu'on envoie ». La moitié des scripts `.sh` de la copie de travail sont encore intégralement en CRLF.
- Preuve        : `git ls-files '*.sh' | while read -r f; do cr=$(od -An -tx1 -v "$f" | tr ' ' '\n' | grep -c '^0d$'); printf "%s CR=%s lignes=%s\n" "$f" "$cr" "$(wc -l < "$f")"; done` → **8 en CRLF** (`dr-drill.sh` 205/205, `backup-postgres.sh` 181/181, `verifier-sauvegarde.sh` 155/155, `setup-hetzner-cpx22.sh` 149/149, `setup-backup.sh` 116/116, `configure-prod-env.sh` 103/103, `entrypoint-prod.sh` 51/51, `mesure_reference.sh` 29/29) et **8 en LF**. Fichier : `04_PREUVES/agent-09/02_crlf_scripts.txt`.
- Témoin négatif: 🔴 **il a servi, et il a démasqué mon propre contrôle.** Le premier contrôle écrit pour ce constat était `grep -c $'\r'`, et il rendait « 15 sur 15 en CRLF ». Passé au témoin — un fichier LF fabriqué par `printf '#!/bin/sh\necho ok\n'` — il a rendu **CR=2 sur un fichier qui ne contient aucun octet `0x0d`** (`wc -c` = 18, `od` = 0 occurrence de `0d`). `grep -c $'\r'` est **aveugle dans ce Git Bash** : il compte toutes les lignes, quel que soit le fichier. Il aurait « prouvé » que tout le dépôt est en CRLF. Le contrôle retenu — comptage des octets `0x0d` par `od` — rend **0** sur le témoin LF et **2** sur le témoin CRLF : il distingue. La mesure ci-dessus est celle du contrôle validé, et elle recoupe l'outil `file`, que j'avais écarté à tort.
- Impact        : le défaut qui a rendu un `.sh` inexécutable sur le serveur le 19/08 (`$'\r': command not found`) reste armé sur **la moitié** des scripts — dont `dr-drill.sh` (restauration après sinistre), `backup-postgres.sh` et `restore`/`setup-backup.sh` (sauvegardes), `entrypoint-prod.sh` (démarrage du conteneur de production). Ce sont précisément les scripts qu'on envoie sur un serveur Linux le jour où quelque chose va mal. Pendant ce temps, un commentaire posé le même jour affirme que la divergence n'existe plus : une garde documentaire qui rassure.
- Reproduction  : `od -An -tx1 -v infra/scripts/dr-drill.sh | tr ' ' '\n' | grep -c '^0d$'` → 205 ; `wc -l` → 205.
- Correctif     : corriger le commentaire **et** jouer `git add --renormalize . && git checkout -- .` (le correctif de fond ne relève pas de ce lot). Commentaire : ~10 min.
- Statut        : ouvert
- Note pour l'audit : le constat ouvert **A-003** cite « `verifier-ports-publies.sh` : 167 lignes CRLF ». Ce fichier précis est **en LF** aujourd'hui — le commit `873d8f7` l'a corrigé, seul, le 19/08 (`git show --stat 873d8f7` : `.gitattributes` + `deploy-staging.yml` + ce script). A-003 reste **fondé**, mais son exemple est périmé et son ampleur est de **8 fichiers**, pas d'un seul.

### [A09-006] `CONTRIBUTING.md` présente comme « quality gates » deux seuils de couverture que la CI ne mesure jamais
- Sévérité      : **S2**
- Domaine       : tests
- Référence     : main `c0c453d`
- Emplacement   : `CONTRIBUTING.md:16-17`
- Constat       : la section « Quality gates » annonce « Pest backend ≥ 75 % couverture » et « Vitest frontend ≥ 60 % couverture » ; aucune des deux n'est évaluée par la CI.
- Preuve        : `cat frontend/vitest.config.ts` → le fichier porte lui-même « ⚠️ **SEUILS DÉCORATIFS EN L'ÉTAT.** La CI lance `pnpm test`, jamais `pnpm test:coverage` : ces nombres ne bloquent rien et n'ont jamais rien bloqué ». Vérifié : `grep -rn 'test:coverage' .github/workflows/` → **aucune occurrence** ; `grep -n coverage .github/workflows/ci.yml` → `245: coverage: none` (setup-php) ; `grep -n 'pnpm test' .github/workflows/ci.yml` → `459` (frontend) et `531` (workers), sans couverture ; `composer.json:69` → `"test": "pest --colors"`, sans `--coverage --min`. **Le fond du commentaire de `vitest.config.ts` est donc exact** — seule sa référence de ligne est fausse (cf. ligne 51 du tableau §2.2).
- Témoin négatif: le même `grep -n coverage` **trouve** bien 5 occurrences ailleurs dans `.github/workflows/` (jobs `prospection-*`) — le contrôle sait trouver le mot quand il est là ; il est absent de la porte de test.
- Impact        : c'est le patron « fausse sécurité » déjà consigné pour les budgets Web Vitals. Une revue qui écrit « la couverture est gardée » raisonne sur une porte inexistante ; un patch qui effondre la couverture ne rougira jamais. Le dépôt n'a par ailleurs **aucun hook Git** : la CI est le seul filet, et il n'a pas cette maille.
- Reproduction  : ouvrir `CONTRIBUTING.md:11-18`, puis `frontend/vitest.config.ts` § `coverage`.
- Correctif     : déplacer les deux lignes hors de « Quality gates » vers « Objectifs non gardés », en recopiant l'avertissement de `vitest.config.ts`. ~15 min. (Les rendre mordantes est un autre lot.)
- Statut        : ouvert

### [A09-007] Le runbook de la console locale fait dépendre le démarrage d'un worktree résiduel, alors que le fichier est versionné à la racine
- Sévérité      : **S2**
- Domaine       : navigation / conformité (documentation d'atelier)
- Référence     : main `c0c453d`
- Emplacement   : `_REPORTS/RUNBOOK-CONSOLE-LOCALE.md:41`, `:331`, `:344`, `:575`
- Constat       : le runbook prescrit `-f C:/Users/willi/Documents/Projets/crmpro-wt-etape0/docker-compose.local.yml` ; le même fichier est suivi par git à la racine du dépôt.
- Preuve        : `git ls-files docker-compose.local.yml` → `docker-compose.local.yml` (suivi) ; `diff --strip-trailing-cr crmpro-wt-etape0/docker-compose.local.yml ./docker-compose.local.yml` → **aucune différence de contenu** (seuls les EOL diffèrent) ; `grep -c 'crmpro-wt-etape0' _REPORTS/RUNBOOK-CONSOLE-LOCALE.md` → 4.
- Témoin négatif: `ls crmpro-wt-etape0/docker-compose.local.yml` rend bien le fichier — le worktree existe encore, donc le runbook *fonctionne aujourd'hui*. Le contrôle ne signale pas une absence : il signale une **dépendance qui n'a pas lieu d'être**.
- Impact        : le dossier commun désigne `crmpro-wt-etape0` comme « résiduel, ne pas s'en servir comme référence ». Le jour où il est nettoyé, le runbook devient infaisable, et son lecteur conclura que l'atelier local n'est pas autonome. Le runbook s'annonce pourtant « à qui n'a jamais lancé ce dépôt ».
- Reproduction  : suivre `_REPORTS/RUNBOOK-CONSOLE-LOCALE.md` §1 après `rm -rf crmpro-wt-etape0`.
- Correctif     : remplacer les 4 chemins absolus par `docker-compose.local.yml` et `frontend/` relatifs à la racine. ~15 min.
- Statut        : ouvert

### [A09-008] Trois documents annoncent « RLS sur 30 tables » ; 55 tables en portent, et 4 tables workspace-scoped n'en ont pas — dont `audit_logs`
- Sévérité      : **S2**
- Domaine       : sécurité
- Référence     : main `c0c453d` ; base locale `axion_crm` à 58/58 migrations appliquées, mesurée le 2026-08-19
- Emplacement   : `ARCHITECTURE.md:64` (« RLS : 30+ tables ») ; `ARCHITECTURE.md:30` (schéma, « RLS 30 tbl ») ; `CHANGELOG.md:38` ; `_REPORTS/PROGRESS.md` § « Décisions appliquées »
- Constat       : la couverture RLS réelle est de 55 tables sur les 72 qui portent `workspace_id` ; les documents annoncent 30 et ne nomment aucune exception.
- Preuve        : `psql -tAc "SELECT (SELECT count(*) FROM pg_tables WHERE schemaname='public'), (SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE c.relrowsecurity AND n.nspname='public'), (SELECT count(DISTINCT table_name) FROM information_schema.columns WHERE table_schema='public' AND column_name='workspace_id')"` → **`114|55|72`**. Liste des tables `workspace_id` **sans** RLS, hors partitions : **`audit_logs`, `audit_logs_default`, `sessions`, `user_workspaces`**. Fichier : `04_PREUVES/agent-09/01_rls_et_partitionnement.txt`.
- Témoin négatif: la même requête, jouée à 12h02 pendant qu'un autre agent rejouait `migrate:fresh`, rendait `2|0|0`. La mesure retenue n'a été prise qu'après avoir vérifié `select count(*) from migrations` = **58** = nombre de fichiers de migration. Sans ce recoupement, le contrôle aurait « prouvé » qu'il n'y a aucune RLS.
- Impact        : `ARCHITECTURE.md:47` et `CONTRIBUTING.md:44` présentent `audit_logs` comme la chaîne inviolable du produit, et `CONTRIBUTING.md:43` écrit « RLS PostgreSQL activée — toute requête doit passer par `SetCurrentWorkspace` ». Le lecteur en conclut une couverture totale. `audit_logs` porte `workspace_id` et **n'est pas cloisonnée par RLS** : la protection y repose entièrement sur `AuditLogPolicy` côté application, ce qu'aucun document ne dit.
- Reproduction  : rejouer la requête ci-dessus sur une base à 58 migrations.
- Correctif     : écrire le chiffre mesuré (55/72) et nommer les 4 exceptions avec leur justification. ~30 min. **Si elles n'en ont pas, c'est un constat de sécurité à ouvrir séparément** — hors périmètre de cet agent, qui n'audite que la parole des documents.
- Statut        : ouvert

### [A09-009] `TODO.md` se déclare « source de vérité » et décrit un dépôt d'avant la première ligne de code
- Sévérité      : **S2**
- Domaine       : conformité (pilotage)
- Référence     : main `c0c453d`
- Emplacement   : `TODO.md:3`, `:13`, `:17`, `:21`, `:80`, `:88`, `:123`, `:203-214`
- Constat       : le document s'annonce « **Source de vérité** de ce qu'il reste à faire avant Sprint 1 + production », daté 2026-05-16, et porte six affirmations que le code contredit (lignes 30-32 du tableau §2.2 : 26 fichiers spec au lieu de 25, 50 fichiers TS de POC au lieu de 20, « Sprint 1 en cours », `poc/SYNTHESIS.md` « à créer » alors qu'il est cité 40 lignes plus haut, DPIA « à produire » dans un `_DOCS/` qui n'existe pas, calendrier §7 entièrement révolu).
- Preuve        : `ls spec/*.md | wc -l` → 26 (soit 25 + `AUDIT_v1`) ; `find poc -name '*.ts' -not -path '*/node_modules/*' | wc -l` → 20 ; `ls poc/SYNTHESIS.md` → présent ; `ls _DOCS` → absent ; `ls _REPORTS/DPIA_2026-05-17.md` → présent. Fichier : `04_PREUVES/agent-09/00_comptes.txt`.
- Témoin négatif: le même jeu de contrôles appliqué à `ARCHITECTURE.md` valide **12 chemins sur 12** — le contrôle sait rendre « exact » quand le document l'est.
- Impact        : le §2 dit « Démarrer UNIQUEMENT après POCs validés » alors que les 12 sprints sont livrés et déployés en production. Un arrivant qui lit le document désigné comme source de vérité conclut que le produit n'est pas commencé.
- Reproduction  : lire `TODO.md:3` puis `README.md:50`.
- Correctif     : retirer la mention « source de vérité » et dater le document comme archive, ou le réécrire. Archivage : ~15 min.
- Statut        : ouvert

### [A09-010] `README.md` et `CONTRIBUTING.md` prescrivent trois commandes de test qui ne peuvent pas s'exécuter
- Sévérité      : **S3**
- Domaine       : tests
- Référence     : main `c0c453d`
- Emplacement   : `README.md:13`, `:85` ; `CONTRIBUTING.md:77-78`
- Constat       : les deux documents prescrivent `docker exec axion-crm-worker-google-maps pnpm test` / `pnpm typecheck`, et le `README:13` annonce que `docker compose up -d` démarre « + workers ».
- Preuve        : `docker ps --format '{{.Names}}'` → `axion-crm-{api,app,caddy,postgres,redis,horizon,scheduler}`, **aucun `worker`** ; `grep -nE '^  [a-zA-Z0-9_-]+:' docker-compose.yml` → 8 services, aucun worker ; `docker-compose.yml:187` l'écrit : le dossier `workers/` est « conservé » et « le job CI `workers` continue de le tester ». Seul `docker-compose.test.yml` déclare `worker-google-maps`, `worker-pages-jaunes`, `worker-google-search`.
- Témoin négatif: `docker exec axion-crm-api composer test` (ligne voisine du même README) s'exécute — le contrôle distingue une commande valide d'une commande impossible.
- Impact        : un contributeur qui suit la check-list « Avant de pusher » de `CONTRIBUTING.md` reçoit `Error: No such container` sur les deux dernières commandes et peut conclure que sa pile est cassée.
- Reproduction  : `docker exec axion-crm-worker-google-maps pnpm test`.
- Correctif     : remplacer par `cd workers && pnpm test` ; retirer « + workers » de `README.md:13`. ~10 min.
- Statut        : ouvert

### [A09-011] `spec/00_INDEX.md` se déclare « implémentation à venir » et annonce des tailles de fichiers fausses jusqu'à un facteur 2,4
- Sévérité      : **S3**
- Domaine       : conformité (documentation de conception)
- Référence     : main `c0c453d`
- Emplacement   : `spec/00_INDEX.md:6`, `:7`, `:20`, `:25-60`
- Constat       : l'index de la spec porte « Statut : Spec exhaustive — implémentation à venir », « 24 fichiers » (il y en a 26), et une colonne « Lignes » fausse sur 13 des 24 entrées vérifiées.
- Preuve        : `wc -l spec/*.md` → `08` annoncé ~800 fait **337** ; `13` annoncé ~1500 fait **614** ; `03` annoncé ~1500 fait **1932** ; `02` annoncé ~700 fait **806**. `ls spec/*.md | wc -l` → 26. `ls backend/app/Services | wc -l` → 65 fichiers.
- Témoin négatif: `07_llm_router.md` est annoncé ~900 et fait **908** — la colonne est donc parfois juste, et le contrôle ne signale pas systématiquement.
- Impact        : faible en soi ; mais l'index est la porte d'entrée de la spec, et son statut « implémentation à venir » induit en erreur sur l'état du produit.
- Reproduction  : `wc -l spec/*.md` en regard du tableau lignes 27-58.
- Correctif     : corriger le statut et le compte ; supprimer la colonne « Lignes » (elle vieillit mal et n'apporte rien). ~20 min.
- Statut        : ouvert

### [A09-012] Deux commentaires de `routes/console.php` déclarent inexistantes des commandes artisan qui existent
- Sévérité      : **S3**
- Domaine       : backend
- Référence     : main `c0c453d`
- Emplacement   : `backend/routes/console.php:28-30`, `:44-49`
- Constat       : les commentaires posent que `companies:rescrape-archives` « est codée dans le Sprint Hardening (H6) » et qu'« en attendant » le `skip()` évite l'erreur ; même patron pour `companies:retry-google-places`. Les deux commandes existent.
- Preuve        : `grep -n 'signature' backend/app/Console/Commands/RescrapeArchivesCommand.php` → `companies:rescrape-archives` ; idem `RetryGooglePlacesCommand.php` → `companies:retry-google-places`. Fichier : `04_PREUVES/agent-09/05_console_php.txt`.
- Témoin négatif: `ls backend/app/Console/Commands/*.php | wc -l` → 49 fichiers, et aucun ne porte de signature `companies:enrich-archives` (nom voisin inventé) — le contrôle ne rend pas « présent » à tort.
- Impact        : le `skip()` est désormais toujours faux : **le re-scrape mensuel tourne réellement** le 1er du mois sur le stock archivé, et le retry Google Places le 1er à 03:00. Un lecteur qui planifie une charge mensuelle croit ces deux planifications inertes.
- Reproduction  : lire `routes/console.php:28`, puis `ls backend/app/Console/Commands/RescrapeArchivesCommand.php`.
- Correctif     : réécrire les deux commentaires (« commande livrée ; le `skip()` reste comme garde-fou »). ~10 min.
- Statut        : ouvert

### [A09-013] Le journal d'étape 1a porte un SHA de `main` périmé et deux sections « n'existe pas » que le même document dément plus bas
- Sévérité      : **S3**
- Domaine       : conformité (pilotage)
- Référence     : main `c0c453d` / HEAD `1145473`
- Emplacement   : `_SESSIONS/2026-08-19_CRM-ETAPE-1A.md:1001`, `:418` (§3.1), `:771` (§6.5)
- Constat       : le journal écrit « `main` = `d4910c8`. Aucune PR ouverte », et porte deux sections intitulées « La préproduction N'EXISTE PAS » et « RIEN n'a été écrit pour la préproduction », que son propre §11 (« La PRÉPRODUCTION existe ») dément sans qu'aucune annotation ne renvoie de l'une à l'autre.
- Preuve        : `git log -1 --format=%H` → `1145473…` (7 commits après `d4910c8`) ; `ls docker-compose.staging.yml` → présent, 12 080 o. ; `git log --oneline` → `8030a1b docs(preprod): la preproduction existe`, `377b902 feat(preprod): … preproduction remplie`.
- Témoin négatif: le §10 du même document annote **explicitement** ses propres passages caducs (« les passages antérieurs qui les contredisent sont caducs ») — l'auteur sait le faire ; il ne l'a pas fait pour §3.1 et §6.5.
- Impact        : faible — la structure chronologique protège le lecteur attentif. Mais le document s'annonce ligne 6 « la seule source de vérité de l'avancement », et le dossier commun rappelle que les SHA écrits dans les documents « ont été faux trois fois ».
- Reproduction  : lire §3.1 puis §11 du même fichier.
- Correctif     : deux bandeaux « ⚠️ caduc — voir §11 » et retrait du SHA. ~10 min.
- Statut        : ouvert

---

## 4. Ce que je n'ai PAS pu vérifier, et pourquoi

Cette liste est un livrable. Un audit qui prétend tout avoir vu est un audit qu'on ne peut pas croire.

1. **48 objets inventoriés sans mesure.** Le plafond de 20 sous-agents concurrents de cet audit était **atteint** : mes trois délégations (`spec/`, `_AUDIT/`, `_REPORTS/`) ont toutes échoué sur `Concurrent subagent limit reached`. J'ai donc traité seul, et arbitré en faveur des documents qui prétendent décrire **l'état** du dépôt plutôt que sa **conception**. Restent non mesurés :
   - `spec/` : `01`, `02`, `05`–`12`, `15`–`24`, `AUDIT_v1.md` (21 fichiers, ~13 000 lignes). Ce sont des documents de conception du 2026-05-16 ; ils contiennent néanmoins du SQL et du PHP présentés comme « exécutables tels quels » (`00_INDEX:14`), qui mériteraient une comparaison ligne à ligne avec les migrations réelles.
   - `_AUDIT/` : `AUDIT_1/2/3_2026-05-17.md`, `HARDENING-VERIFICATION-{FIXES,RAPPORT}`, les 3 `PROMPT-PROSPECTION-*` (2 013 lignes), `PROSPECTION-PIPELINE.md`, `SESSION-2026-05-18-*`, `SPRINT-H9-*`, `COST-ESTIMATION-*`, `PROD-ACTIVATION-RUNBOOK.md`, `AUDIT-E2E-PHASE1-2026-05-17/`. **`PROD-ACTIVATION-RUNBOOK.md` est le plus regrettable** : c'est un runbook de production, donc le genre de document dont chaque commande devrait être rejouée.
   - `_REPORTS/` : `DPIA_2026-05-17.md`, `AIPD_2026-08-18.md`, `2026-08-17_CLOTURE-PLAN-CRM-E2E2.md`, `2026-08-18_ARBITRAGES-*`, `2026-08-18_ETAT-PARE-FEU.md`, `2026-08-18_MESURE-PERFORMANCE-REFERENCE.md`, `2026-08-18_OPT-OUT-*`, `2026-08-18_POLITIQUE-DEPENDANCES-*`, `2026-08-18_RECONSTRUCTION-BASE.md`, `2026-08-19_MESURE-COMPTEURS-HUB.md`, `REGISTRE-DES-VIOLATIONS-*`, `2026-08-19_BROUILLON-CNIL-*`. **Les deux documents RGPD (`DPIA`, `AIPD`) sont les plus regrettables** : une mesure de protection annoncée « en place » et absente du schéma est une non-conformité, pas un défaut de rédaction.
2. **Les commentaires de migrations n'ont pas été passés au crible.** 58 fichiers, dont plusieurs portent de longs commentaires factuels. Seuls `.gitattributes`, `docker-compose.local.yml` et `routes/console.php` — nommés dans mon périmètre — l'ont été.
3. **`_AUDIT/TODO-AXION-CRM-PRO.md` : ses actions critiques sont hors dépôt** (compte de facturation Google rattaché à « SOS-Expat.com global », budgets Mistral). Elles sont **invérifiables depuis le code** et n'ont pas été comptées comme écarts — mais elles n'ont pas été vérifiées non plus. Piège 14 du dossier : une valeur « inexistante » peut vivre hors du dépôt.
4. **Les affirmations portant sur la production n'ont pas été rejouées** : `_SESSIONS/…:9.3` (« Telescope : 6 erreurs par minute en production »), `…:9.5` (« `activities` en production = 649 lignes »), `…:6.2` (RAM/disque du VPS). La production est en lecture seule et je n'y ai pas d'accès SSH. Ces trois affirmations sont **plausibles et non vérifiées**.
5. **La base locale a été rebâtie sous moi pendant l'audit.** Toute mesure en base de ce rapport est horodatée et recroisée avec `select count(*) from migrations` = 58. Les mesures des autres agents prises sans ce recoupement sont à considérer comme suspectes.
6. **Je n'ai pas vérifié les affirmations de `_AUDIT/2026-08-18_AUDIT-360/`** — c'est l'audit en cours, explicitement hors périmètre. Deux exceptions signalées par honnêteté, parce qu'elles touchent des constats que d'autres agents vont réutiliser :
   - le dossier commun §5 illustre **A-003** par « `verifier-ports-publies.sh` : 167 lignes CRLF ». **Ce fichier est en LF aujourd'hui** (`od` → 0 octet `0x0d`) : le commit `873d8f7` l'a corrigé le 19/08. A-003 reste fondé — **8 scripts sur 16** sont en CRLF — mais son exemple est périmé ;
   - le dossier commun §7 piège 1 dit qu'« un test statique qui cherche un `\n` littéral est aveugle ici ». **Il faut y ajouter le symétrique, que j'ai payé** : `grep -c $'\r'` est aveugle lui aussi dans ce Git Bash — il compte **toutes** les lignes, y compris celles d'un fichier LF pur. Un agent qui l'utilise conclura que tout est en CRLF. Le contrôle qui distingue est le comptage d'octets `0x0d` par `od` (ou, plus simple, `file`, que j'avais écarté à tort).
7. **Une de mes propres mesures a été fausse pendant plusieurs heures** (le « 15/15 CRLF » ci-dessus), et seule la règle 3 du dossier — témoin négatif obligatoire — l'a rattrapée, à la toute fin. C'est un argument pour l'appliquer **avant** d'écrire le constat, pas après.
