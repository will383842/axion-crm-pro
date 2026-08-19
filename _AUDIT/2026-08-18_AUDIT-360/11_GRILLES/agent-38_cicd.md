# AGENT 38 — Auditeur CI/CD

- **Référence mesurée** : `main = e8924b8` (`git log --oneline -3` rejoué au début et à la fin de la
  mission : `e8924b8 fix(rgpd+acces): rectification du registre + acces CRM rendu (#189)`).
  Le SHA du dossier commun (`c0c453d`) est le commit de fusion de la PR #186 ; trois PR de plus ont
  atterri depuis. **Le fichier `.github/workflows/ci.yml` a changé le 19/08 à 11:13**, après le dernier
  déploiement — je le nomme à chaque constat concerné.
- **Périmètre** : 17 fichiers de workflow, **36 jobs déclarés** (hors expansion de matrices).
- **Preuves brutes** : `04_PREUVES/agent-38/` (18 fichiers : `gh run list`, `gh api`, journaux de jobs,
  sortie Pint brute).
- **Méthode** : lecture du YAML **puis** mesure `gh` sur l'historique réel. Aucun `workflow_dispatch`
  n'a été déclenché. Aucun fichier du produit modifié.

---

## 0. Le fil rouge, en une phrase

> *Une garde peut être irréprochable et mesurer le mauvais objet.*

Ce n'est pas une abstraction dans ce dépôt : **la préproduction fonctionne** (`https://staging.axion-crm-pro.com`
→ **200**, mesuré) et **son déploiement est rouge 10 fois sur 10** parce que sa vérification interroge
`127.0.0.1` alors que la pile se lie délibérément à `172.17.0.1`. La garde rougit sur une adresse, pas
sur le service. Et l'étape qui, elle, mesure le bon objet — l'appel depuis internet — ne s'exécute
jamais, puisque la précédente a déjà fait sortir le job.

C'est le même défaut que celui du 19/08, à l'envers : là, une garde verte cachait une faille ; ici, une
garde rouge cache un succès. Dans les deux cas le vert (ou le rouge) ne dit rien de l'état réel.

---

## 1. Tableau de grille — une ligne par job

Colonnes : `workflow | job | déclencheur | ce qu'il prétend faire | ce qu'il exécute réellement |
bloquant ou décoratif | a-t-il déjà tourné ? dernier résultat | verdict`.

« **Bloquant** » = son rouge **empêche** quelque chose de se produire (fusion refusée, ou déploiement
non parti). « **Décoratif** » = son rouge n'empêche rien ; au mieux il est visible, au pire il est
avalé.

### 1.1 `ci.yml` — 6 jobs

| workflow | job | déclencheur | prétend | exécute réellement | bloquant ? | déjà tourné ? dernier résultat | verdict |
|---|---|---|---|---|---|---|---|
| ci.yml | `config-prod` | `pull_request`, `workflow_call` (depuis deploy-direct-ssh), `workflow_dispatch` | « La config de production ne publie que 80 et 443 » | `docker compose config` sur `docker-compose.yml + prod.yml`, extraction `awk` des `published:`, **témoin positif** (80 et 443 doivent être trouvés, sinon `exit 1`), liste fermée | **Bloque le déploiement de production** (via `needs: [ci]`). **NE bloque PAS la fusion** : absent des `required_status_checks` | oui — `success` sur run 32240894728 (PR #189) | ✅ garde honnête, témoin positif réel, **mais mesure le FICHIER, pas le conteneur** → voir F38-003. Et son rouge n'arrête pas une fusion → F38-002 |
| ci.yml | `caddyfile-valide` | idem | « Le Caddyfile est-il valide ? » | témoin négatif **rejoué à chaque exécution** (Caddyfile fautif soumis, exige `adapting config using caddyfile` dans l'erreur) puis `caddy validate` sur `infra/caddy/` | Bloque le déploiement. Pas requis pour la fusion | oui — `success` | ✅ **le meilleur job du dépôt** : témoin négatif exécuté à chaque run, et contrôle du *message* d'erreur. Mesure le bon objet (le fichier que Caddy lit) |
| ci.yml | `scripts-executables` | idem | « Les scripts d'infra sont-ils exécutables ? » | `git ls-files -s infra/scripts/*.sh`, exige `100755` | Bloque le déploiement. Pas requis pour la fusion | oui — `success` | ✅ mesure le **mode dans git**, seul objet qui survive à un clone. Angle mort : ne dit rien des **fins de ligne** — 8 des 16 `.sh` portent des CR (A-003), et un `.sh` en CRLF est inexécutable sur Linux même en 755 |
| ci.yml | `backend` | idem | install + PHPStan 8 + Pint + Pest | `composer install` (bloquant), `composer analyse`, `pint --test` **sur les fichiers modifiés seulement**, Postgres GHCR en `--network host` avec `--lc-collate=C`, `pest --configuration phpunit-ci.xml` | **Bloquant ×2** : requis pour la fusion **et** pour le déploiement | oui — `success` 1m32s (32240894728) | ✅ install BLOQUANT (piège 7 couvert). ⚠️ `coverage: none` (l.245) : **aucune couverture n'est mesurée** malgré CONTRIBUTING.md → F38-009. ⚠️ le commentaire « 276 fichiers non formatés » est **faux** → F38-010 |
| ci.yml | `frontend` | idem | typecheck + lint + test + build | `pnpm install --frozen-lockfile` (bloquant), `pnpm typecheck`, `pnpm lint`, `pnpm test`, `pnpm build` | **Bloquant ×2** | oui — `success` | ✅ aucune tolérance. Install au lockfile figé : piège 7 couvert |
| ci.yml | `workers` | idem | typecheck + lint + test + build + garde Playwright | idem + garde d'alignement `playwright` bibliothèque ↔ `ARG PLAYWRIGHT_VERSION` de `Dockerfile.worker` | **Bloquant ×2** | oui — `success` | ✅ la garde Playwright mesure le **lock résolu**, pas l'intervalle de `package.json` : bon objet |

### 1.2 `a11y.yml` — 2 jobs

| workflow | job | déclencheur | prétend | exécute réellement | bloquant ? | déjà tourné ? dernier résultat | verdict |
|---|---|---|---|---|---|---|---|
| a11y.yml | `axe-playwright` | `push:main`, `pull_request` | axe-core + navigation E2E | **exécute vraiment** : `4 passed (11.3s)` pour `a11y.spec.ts`, `14 passed (9.6s)` pour `navigation.spec.ts` (journal du run 32240894684) | **Décoratif** : absent des `required_status_checks`, absent de la chaîne de déploiement. Son rouge est visible, il n'empêche rien | oui — `success` sur les PR ; **6 runs bloqués ≥ 1 h 28 min** au moment de la mesure | ⚠️ le seul job qui exécute des tests E2E, et il ne bloque rien. Pire : il **se fige** à `playwright install --with-deps` sans `timeout-minutes` → F38-008. Et il n'exécute que **2 des 16 spécifications** → F38-011 |
| a11y.yml | `lighthouse` | `pull_request` seulement (`if: github.event_name == 'pull_request'`) | Lighthouse CI sur `https://staging.axion-crm-pro.com` | `lhci autorun` **sort en code 1** : `Runtime error encountered: Lighthouse was unable to reliably load the page (net::ERR_ABORTED)`, tous les audits en `FAILED_DOCUMENT_REQUEST` | **Décoratif à 100 %** — `continue-on-error: true` | oui — job `success`, commande `exit 1`. Rejoué : run 32240894684, 19/08 10:05 | 🔴 **n'a jamais produit un seul score.** La préproduction existe désormais et répond **200** depuis internet (mesuré), donc la cible n'est plus le problème — **le job reste inutile** → F38-006 |

### 1.3 `security.yml` — 8 jobs

| workflow | job | déclencheur | prétend | exécute réellement | bloquant ? | déjà tourné ? dernier résultat | verdict |
|---|---|---|---|---|---|---|---|
| security.yml | `gitleaks` | `push:main`, `pull_request`, cron lundi 04:00 | scan de secrets | `gitleaks-action@v2`, `fetch-depth: 0` | **Bloquant pour la fusion** (seul job de ce workflow dans `required_status_checks`) — pas pour le déploiement | oui — `success` | ✅ réellement bloquant. Seule garde de sécurité qui le soit |
| security.yml | `semgrep` | idem | SAST OWASP + Laravel + TypeScript | `semgrep ci` avec 4 rulesets → SARIF | **Décoratif** — `continue-on-error: true` (l.41) | oui — `success` systématique | ⚠️ un `continue-on-error` **non commenté**, au milieu d'un fichier qui explique longuement pourquoi on les a retirés ailleurs. Le job ne peut pas rougir → F38-013 |
| security.yml | `trivy` (matrice ×3 : api, app, worker) | idem | garde sur vulnérabilités **corrigeables** | build de l'image + SARIF inventaire (`exit-code: 0`) + **garde `exit-code: 1`, `ignore-unfixed: true`, `.trivyignore.yaml`** | **Décoratif malgré `exit-code: 1`** : `security.yml` n'est ni requis pour la fusion ni dans la chaîne de déploiement | oui — **`failure` sur `worker`** au run 32236507006 (PR `docs/cnil-decision`) | 🔴 la garde a rougi ; **la PR a été fusionnée 2 minutes plus tôt, sans l'attendre** (`mergedAt 09:15:13`, run terminé ~09:17). Preuve directe : ce rouge n'empêche rien → F38-002 |
| security.yml | `trivy-images-tirees` (matrice ×2 : caddy, redis) | idem | inventaire des images tirées | SARIF + tableau, `exit-code: 0` partout | **Décoratif — assumé et documenté** | oui — `success` | ✅ honnête : le fichier dit qu'il est non bloquant et pourquoi. Un décoratif déclaré n'est pas un mensonge |
| security.yml | `composer-audit` | idem | « PHP deps audit » | `composer audit --no-dev \|\| true` **sans `composer install` préalable** → `No installed packages found. Please run "composer install" before running "audit"` | **Décoratif** — `\|\| true`, et de toute façon rien à auditer | oui — `success` à chaque run. Preuve rejouée sur `e8924b8` (run 32241133011) | 🔴 **piège 7 pur, confirmé** : le job n'a jamais audité un seul paquet PHP. Vert = silence → F38-004 (confirme H47-001) |
| security.yml | `pnpm-audit` (matrice ×2 : frontend, workers) | idem | « Node deps audit » | `pnpm audit --audit-level=high \|\| true` — affiche **31 vulnérabilités** (12 moderate / 18 high / 1 critical) côté frontend et **33** (3 low / 19 moderate / 10 high / 1 critical) côté workers | **Décoratif** — `\|\| true` | oui — `success` à chaque run, mesuré sur `e8924b8` | 🔴 confirme H47-002 première moitié → F38-005 |
| security.yml | `zap-baseline` | `if: github.event_name == 'schedule' && vars.ZAP_TARGET_URL != ''` | analyse dynamique OWASP ZAP | **rien** | Décoratif | **JAMAIS TOURNÉ.** `gh api .../actions/variables` → `{"variables":[],"total_count":0}` : la variable n'a jamais été posée | ⚠️ job en sommeil depuis le 2026-08-16. Le choix appartient toujours à Will (poser la variable ou supprimer le job) ; il n'a pas été fait |
| security.yml | `alerte` | `needs:` les 6 précédents, `if: failure() && github.event_name != 'pull_request'` | ouvrir/compléter une issue `securite` | `gh issue create` / `gh issue comment`, une seule issue vivante | Non bloquant **par conception** | **OUI, IL A TOURNÉ** : run 32230359916, `success` | ✅ **H47-002 seconde moitié REFUTÉE** (règle 7 appliquée) : `if: failure()` en GitHub Actions signifie « *un* job précédent a échoué », pas « les `needs` ont échoué » — le job **peut** et **a** déjà rougi. L'énoncé exact est : *il ne peut jamais se déclencher **à cause d'un résultat d'audit de dépendances***, ceux-ci rendant toujours `success` → F38-005 |

### 1.4 `deploy-direct-ssh.yml` — 2 jobs (la PRODUCTION)

| workflow | job | déclencheur | prétend | exécute réellement | bloquant ? | déjà tourné ? dernier résultat | verdict |
|---|---|---|---|---|---|---|---|
| deploy-direct-ssh.yml | `ci` | `push:main` (avec `paths-ignore` : `_AUDIT/**`, `_REPORTS/**`, `spec/**`, `docs/**`, `**/*.md`, `.github/workflows/**`), `workflow_dispatch` | « CI (gate bloquant) » | appelle `./.github/workflows/ci.yml` en `workflow_call` — les 6 jobs ci-dessus | **Bloquant** — `deploy` porte `needs: [ci]` **et** `if: needs.ci.result == 'success'` (ceinture + bretelles : sans le `if`, un `needs` sur un job `skipped` passerait) | oui — `success` (run 32241133570, `e8924b8`) | ✅ **le seul vrai enchaînement bloquant du dépôt.** ⚠️ conséquence de `paths-ignore` : un `push` sur `main` ne touchant que `.github/workflows/**` ou des `.md` **ne déclenche AUCUN job de `ci.yml`** — les workflows eux-mêmes ne sont validés qu'au moment de la PR |
| deploy-direct-ssh.yml | `deploy` | idem, `needs: [ci]` | SSH + reset sur le SHA validé + migrate + vérifications | `COMPOSE_FILE=docker-compose.yml:prod.yml`, `git reset --hard $EXPECTED_SHA` + comparaison, `up -d --build --force-recreate --no-deps api app horizon scheduler`, `migrate --force` **bloquant**, `cache:clear`, contrôle `running`/`unhealthy` sur `api app horizon postgres redis caddy`, `migrate:status` sans `Pending`, **sentinelle `AXION_DEPLOY_SCRIPT_COMPLETE`** relue côté runner, smoke HTTP externe | C'est l'action, pas une garde ; ses vérifications sont bloquantes | oui — `success` (3m23s, `e8924b8`) | ✅ la sentinelle est une vraie trouvaille (elle prouve que le heredoc n'a pas été avalé). 🔴 **`--no-deps` + liste nommée** : `postgres`, `redis`, `reverb` ne sont jamais recréés, et **aucune étape ne mesure les ports RÉELLEMENT publiés par la pile de production** → F38-003 |

### 1.5 `deploy-staging.yml` — 2 jobs (la PRÉPRODUCTION)

| workflow | job | déclencheur | prétend | exécute réellement | bloquant ? | déjà tourné ? dernier résultat | verdict |
|---|---|---|---|---|---|---|---|
| deploy-staging.yml | `build-and-push` (matrice ×3) | `push:main` (**sans `paths-ignore`**), `workflow_dispatch` | build + push GHCR `:staging` et `:${sha}` | `docker/build-push-action@v6`, cache GHA | Bloque `deploy-staging` (`needs:`) | oui — `success` (3/3, run 32241132949) | ✅ ⚠️ pas de `paths-ignore` : **trois images sont reconstruites et poussées à chaque commit de documentation.** Le run `e8924b8` a duré 6 min 30 pour un diff `.md` |
| deploy-staging.yml | `deploy-staging` | `needs: build-and-push` | SSH → `/opt/axion-crm-pro-staging`, up, migrate, vérifications | tout se passe **bien** (`Container … Recreated`, `INFO Nothing to migrate.`, `PRODUCTION intacte : oui`, `OK : la pile ne publie RIEN sur internet`) **puis** : `curl 127.0.0.1:8082/up` → **MUETTE**, `curl 127.0.0.1:8081` → `Failed to connect` → `exit 1` | C'est l'action | oui — **`failure` 10 fois sur 10** depuis sa création le 19/08 07:58 (historique complet archivé) | 🔴 **LE CONSTAT PHARE.** La pile publie sur `172.17.0.1:8081/8082` (`docker-compose.staging.yml:138`, correctif de la PR #181 « 502 — liaison sur la passerelle du pont Docker ») ; la vérification est restée sur `127.0.0.1`. La préproduction **répond 200 depuis internet**, mesuré. L'étape « Contrôle depuis l'extérieur » — la seule qui vise le bon objet — **n'est jamais atteinte** → F38-001 |

**Le vestige Coolify est mort** : les jobs `trigger-coolify` et `smoke` conditionnés à
`vars.COOLIFY_STAGING_ENABLED` **ont été retirés**, le workflow a été réécrit le 19/08 pour rejouer le
patron SSH de production. Le piège 3 de ma mission est donc **résolu dans le YAML** — mais le
remplacement est rouge à 100 %.

### 1.6 Workflows d'exploitation et de surveillance — 5 jobs

| workflow | job | déclencheur | prétend | exécute réellement | bloquant ? | déjà tourné ? dernier résultat | verdict |
|---|---|---|---|---|---|---|---|
| surveillance-sauvegarde.yml | `verifier` | cron 05:00 UTC, `workflow_dispatch` (avec seuils réglables **pour faire rougir la garde**) | « La sauvegarde d'hier soir a-t-elle eu lieu ? » | SSH → `infra/scripts/verifier-sauvegarde.sh`, distingue le code du script du **code 255 de ssh** (serveur injoignable ≠ tout va bien), passe le rapport en output, puis étape `Verdict` qui `exit 1` | Non bloquant par conception ; déclenche `alerte` | oui — `success` les 19, 18, 17/08 ; **`failure` le 17/08 02:10** | ✅ **la meilleure garde du dépôt après `caddyfile-valide`** : elle a été **vue rouge en vrai** (pas seulement en théorie), elle traite l'absence de signal comme un signal, et ses seuils sont réglables pour la ré-éprouver. Mesure le bon objet : la **copie hors-site**, pas le script de sauvegarde |
| surveillance-sauvegarde.yml | `alerte` | `needs: [verifier]`, `if: failure()` | ouvrir/compléter une issue `sauvegarde` | idem security | Non bloquant par conception | **OUI** — `success` sur les runs 31987194878 et 31987144988 (17/08) | ✅ chaîne d'alerte **prouvée de bout en bout**. C'est la seule du dépôt dont on ait la preuve qu'elle a réveillé quelqu'un |
| build-postgres-image.yml | `build` | `workflow_dispatch`, `push:main` sur `Dockerfile.postgres` / `infra/postgres/init/**` / lui-même | build + push GHCR de l'image Postgres PostGIS+pgvector+partman | buildx + metadata + push 3 tags | Non bloquant | oui — `success` le 18/08 | ✅ ⚠️ pas de `timeout-minutes`. L'image qu'il produit est **la dépendance du job `backend`** de la CI : si elle disparaît de GHCR, la CI retombe sur un build depuis les sources (repli déjà documenté comme fragile dans `ci.yml`) |
| release-tracking.yml | `sentry_release` | `release: published`, `push: tags v*`, `workflow_dispatch` | release GlitchTip + upload des source maps | conditionné à `steps.skip.outputs.skip == 'false'` (secrets `GLITCHTIP_AUTH_TOKEN` + `SENTRY_URL`) | Non bloquant | **JAMAIS TOURNÉ.** `gh run list --workflow 278256548` → **0 run** | 🔴 aucun tag `v*` n'a jamais été poussé, aucune release publiée. Et même déclenché il ferait `no-op` : les secrets ne sont pas posés → F38-012 |
| diag-website-status.yml | `recover` | `workflow_dispatch` uniquement | « Recover scraping + restore access » | **ajoute une clé publique SSH dans `/root/.ssh/authorized_keys` de la PRODUCTION**, puis `docker compose up -d` **sans `COMPOSE_FILE`**, `systemctl restart axion-find-websites`, requêtes `psql` de comptage | Non bloquant | oui — 1 seul run, 2026-07-07, `success` | 🔴 **danger dormant.** Sans `COMPOSE_FILE=…:docker-compose.prod.yml`, `up -d` recrée la pile depuis `docker-compose.yml` **seul** — donc en cible `dev`, avec le bind mount qui masque le `vendor` de l'image (défaut corrigé le 16/08) **et avec `ports: "55432:5432"` / `"56379:6379"`** (l. 23 et 44), c'est-à-dire **la faille critique du 19/08 rouverte en un clic** → F38-002bis / F38-007 |

### 1.7 Les 8 workflows `prospection-*` — 11 jobs

Tous `workflow_dispatch` **uniquement**, tous SSH vers la production, tous avec `timeout-minutes`
(seuls du dépôt à en avoir). Aucun n'est dans une chaîne de gate : ce sont des **outils
d'exploitation**, pas des gardes. Dernière activité : **juillet 2026**.

| workflow | job | déclencheur | prétend | exécute réellement | bloquant ? | déjà tourné ? dernier résultat | verdict |
|---|---|---|---|---|---|---|---|
| prospection-collect.yml | `collect` | dispatch | collecte INSEE d'un département | `ssh` → `artisan prospection:collect`, `timeout-minutes: 180` | Décoratif (outil) | oui — 2026-07-04, `cancelled` (dernier), `success` le même jour | ⚠️ `set -e` **local** mais le heredoc distant n'est **pas quoté** (`bash <<EOF`) : les `$` s'expansent côté runner. Fonctionne, mais fragile |
| prospection-collect-paris.yml | `collect` (matrice 20 arrondissements) | dispatch | collecte par arrondissement | idem, `timeout-minutes: 90` | Décoratif | oui — 2026-07-05, **20/20 `success`** | ✅ |
| prospection-collect-paris.yml | `finalize` | `needs: collect`, `if: always()` | « Coverage + stats » | `coverage:refresh-matrix \|\| echo "coverage KO (non bloquant)"` puis `prospection:stats` | Décoratif | oui — `success` | ⚠️ `if: always()` + `\|\| echo` : ce job ne peut pratiquement pas rougir |
| prospection-collect-region.yml | `collect` (matrice départements) | dispatch | collecte FRANCE entière | idem, `timeout-minutes: 180` | Décoratif | oui — 2026-07-04, **`Collecte 75 : failure`** parmi 30 | ⚠️ un département perdu dans une matrice de 30, sans agrégation ni alerte |
| prospection-collect-region.yml | `reclassify` | `needs: collect`, `if: always()` | reclassement + stats | idem | Décoratif | **non observé** sur le dernier run (la matrice a échoué avant) | ⚠️ non vérifié |
| prospection-enrich.yml | `enrich` | dispatch | enrichissement en sources gratuites | **modifie le `.env` de PRODUCTION** (`sed -i` sur `MOCK_*`) puis lance l'enrichissement, `timeout-minutes: 30` | Décoratif | oui — 5 runs le 2026-07-06, **tous `success`** | 🔴 un `workflow_dispatch` **écrit dans le `.env` de production** et n'a aucun `finally` pour restaurer l'état antérieur. Hors périmètre strict (agent sécurité/infra), signalé |
| prospection-find-websites.yml | `prepare` | dispatch | découpage du travail | `timeout-minutes` absent sur `prepare` | Décoratif | oui — `success` | — |
| prospection-find-websites.yml | `find` (matrice ~29) | `needs: prepare` | recherche de sites web | `timeout-minutes: 340` | Décoratif | oui — 2026-07-06 : **21 `failure` sur 30** | 🔴 workflow massivement en échec, jamais réparé, jamais relancé depuis 6 semaines |
| prospection-find-websites-distributed.yml | `find` (matrice ~20) | dispatch | version distribuée | `timeout-minutes: 350` | Décoratif | oui — 2026-07-06, `failure` (2 des 3 derniers runs) | 🔴 idem |
| prospection-reclassify.yml | `reclassify` | dispatch | reclassement taille + secteur | 6 `docker compose exec -T … < /dev/null`, dont un `\|\| echo "coverage refresh KO (non bloquant)"` | Décoratif | oui — 5 runs, tous `success` | ✅ le `< /dev/null` est correctement posé sur chaque `exec` (piège du heredoc avalé) |
| prospection-stats.yml | `stats` | dispatch | diagnostic | 1 `docker compose exec -T` — **sans `< /dev/null`**, mais c'est la dernière commande du heredoc | Décoratif | oui — 2026-07-07, `failure` (dernier) | ⚠️ dernier run rouge, jamais rejoué |

---

## 2. Inventaire exhaustif des mécanismes de neutralisation

Réponse au point 1 de la mission : **toutes** les occurrences, job par job.

### 2.1 `continue-on-error: true` — 2 occurrences

| fichier:ligne | job | effet mesuré |
|---|---|---|
| `a11y.yml:75` | `lighthouse` | `lhci` sort en **1** (`net::ERR_ABORTED`) et le job rend **`success`**. Mesuré sur le run 32240894684 |
| `security.yml:41` | `semgrep` | le SAST ne peut jamais rougir. Aucun commentaire ne justifie ce `continue-on-error`, dans un fichier qui documente longuement le retrait des autres |

### 2.2 `|| true` — 4 occurrences fonctionnelles

| fichier:ligne | job | effet |
|---|---|---|
| `security.yml:178` | `composer-audit` | **neutralise un job qui n'auditait déjà rien** (double silence) |
| `security.yml:193` | `pnpm-audit` (×2) | 31 et 33 vulnérabilités → `success` |
| `deploy-direct-ssh.yml:180` | `deploy` | `docker compose pull --ignore-pull-failures \|\| true` — **légitime et documenté** (images construites localement) |
| `release-tracking.yml:99` | `sentry_release` | `set-commits --auto \|\| true` — légitime (dépôt sans historique complet) |
| `ci.yml:290` | `backend` | `git rev-parse HEAD^ 2>/dev/null \|\| true` — **légitime**, capture d'une base absente, suivie d'un test explicite |

### 2.3 `|| echo` — le déguisement le plus efficace (5 occurrences)

Un `|| echo` est **exactement** un `|| true` : il retourne 0. Il est plus difficile à repérer parce
qu'il produit du texte, ce qui donne l'illusion d'un traitement.

| fichier:ligne | job | ce qui est avalé |
|---|---|---|
| **`deploy-staging.yml:176`** | `deploy-staging` | **le code de sortie de `verifier-ports-publies.sh`** — le script écrit précisément parce que `config-prod` mesure le mauvais objet. Codes 1 (port interdit) **et** 2 (mesure impossible) avalés → F38-003 |
| `prospection-collect-paris.yml:83` | `finalize` | `coverage:refresh-matrix` |
| `prospection-collect-region.yml:106` | `reclassify` | idem |
| `prospection-reclassify.yml:42` | `reclassify` | idem |
| `diag-website-status.yml:68,73` | `recover` | `systemctl restart` et la lecture des journaux |

### 2.4 `|| <commande de repli>` — piège 7 caractérisé (1 occurrence)

| fichier:ligne | job | effet |
|---|---|---|
| **`a11y.yml:24`** | `axe-playwright` | `pnpm install --frozen-lockfile \|\| pnpm install` : si le lockfile est périmé, l'installation **repart en mode non figé** et le job teste un arbre de dépendances **différent de celui que `ci.yml` valide**. Le rouge que `--frozen-lockfile` devait produire est converti en un vert sur autre chose → F38-014 |

### 2.5 `exit 0` — 3 occurrences, toutes légitimes

`ci.yml:294`, `ci.yml:300` : sortie du contrôle Pint quand il n'y a pas de base de comparaison ou
aucun fichier PHP modifié — annoncées, tracées dans le journal. `ci.yml:383` : sortie de la boucle
d'attente Postgres **après** succès (le `exit 1` de fin couvre l'échec). Aucun `exit 0` masquant.

### 2.6 `if:` toujours faux — 1 occurrence

| fichier:ligne | job | mesure |
|---|---|---|
| `security.yml:215` | `zap-baseline` | `vars.ZAP_TARGET_URL != ''`. **`gh api repos/…/actions/variables` → `total_count: 0`.** La condition est fausse depuis le 2026-08-16, sans exception |

**Faux positif écarté** (règle 7) : `security.yml:249`, `alerte`, `if: failure() && …`. J'ai vérifié
au lieu de reprendre H47-002 : **le job a tourné**, run 32230359916, conclusion `success`.
`failure()` en GitHub Actions est vrai dès qu'**un** job précédent du workflow a échoué.

### 2.7 `timeout-minutes` — l'absence la plus coûteuse

**Aucun** des 8 workflows de la chaîne qualité/déploiement n'en porte :
`ci.yml`, `a11y.yml`, `security.yml`, `deploy-staging.yml`, `deploy-direct-ssh.yml`,
`build-postgres-image.yml`, `surveillance-sauvegarde.yml`, `release-tracking.yml`.
Seuls les 8 `prospection-*` et `diag-website-status.yml` en ont.

Conséquence mesurée : **6 runs de `Accessibility` figés entre 1 h 28 min et 2 h 25 min**, tous à la
même étape (`Install Playwright browsers`), tous encore `in_progress` au moment de l'audit. Ils
tourneront jusqu'au plafond GitHub de 6 h.

---

## 3. Le crible du piège 19 : sur quel objet chaque garde rougit-elle ?

C'est la question centrale de ma mission. Une ligne par garde, sans exception.

| garde | objet sur lequel elle rougit | est-ce l'objet qui casse ? | écart |
|---|---|---|---|
| `config-prod` | le **fichier fusionné** `docker-compose.yml + prod.yml` | ❌ **NON** | Ce qui casse est le **conteneur**. `deploy` ne recrée que `api app horizon scheduler` avec `--no-deps` : une correction de `ports:` sur `postgres`/`redis`/`reverb` est **inapplicable par le déploiement**. C'est exactement l'écart par lequel 4 295 349 fiches sont restées ouvertes sur internet sous un déploiement vert. **La garde est irréprochable et mesure le mauvais objet.** |
| `verifier-ports-publies.sh` | les **conteneurs** (`docker ps --filter label=…project`) | ✅ **OUI** — c'est la garde écrite pour combler l'écart ci-dessus, témoin négatif inclus (0 conteneur → `exit 2`) | 🔴 mais elle n'est appelée que sur la pile **`axion-crm-staging`**, et son code de retour y est **avalé par `\|\| echo`**. **La pile de production — celle qui a été percée — n'est mesurée par aucun job.** La bonne garde existe, elle ne regarde pas le bon serveur |
| `caddyfile-valide` | le fichier `infra/caddy/Caddyfile` **tel que Caddy l'adapte** | ✅ **OUI** | aucun. Et son témoin négatif est rejoué à chaque exécution, avec contrôle du message d'erreur |
| `scripts-executables` | le **mode dans git** (`git ls-files -s`) | ✅ **OUI** — un `chmod` serveur serait effacé au déploiement suivant | ⚠️ il ne voit pas les **fins de ligne** : un `.sh` en `100755` avec des CR est inexécutable sur Linux. 8 des 16 `.sh` sont dans ce cas (A-003). La garde couvre une moitié du problème « le script ne s'exécute pas » |
| garde Playwright (`workers`) | la version **résolue dans `node_modules`** vs `ARG PLAYWRIGHT_VERSION` | ✅ **OUI** — le lock, pas l'intervalle de `package.json` | aucun |
| vérification post-déploiement production (`running`/`unhealthy`, `migrate:status`) | l'état **réel** des conteneurs sur le serveur, + sentinelle prouvant que le script est allé au bout | ✅ **OUI** | aucun. C'est la garde la mieux construite du dépôt |
| **vérification post-déploiement préproduction** | `127.0.0.1:8081` / `127.0.0.1:8082` | ❌ **NON** | La pile se lie à `172.17.0.1` (`docker-compose.staging.yml:138`). **La garde rougit sur une adresse que la pile a délibérément quittée.** 10 rouges sur 10 sur une préproduction qui répond 200 |
| `lighthouse` | `https://staging.axion-crm-pro.com` chargé par le Chrome de `lhci` | ❌ **NON** — elle ne rougit sur rien : `continue-on-error` | Le job n'a jamais rendu de score. Même si la cible est désormais vivante, la mesure échoue en `ERR_ABORTED` et personne ne le sait |
| `composer-audit` | **rien** (`No installed packages found`) | ❌ **NON** | Piège 7 : la mesure meurt avant d'exister, et le `\|\| true` transforme ce silence en vert |
| `pnpm-audit` | les vulnérabilités **sont bien listées** | ❌ le résultat n'est relié à rien | 64 vulnérabilités affichées, 2 verts |
| `trivy` (garde) | les vulnérabilités **corrigeables** des images construites | ✅ **bon objet, bon réglage** (`ignore-unfixed`) | 🔴 mais **rien n'attend son verdict** : la PR #186 a fusionné 2 min avant la fin de son run rouge |
| `surveillance-sauvegarde` | la **copie hors-site**, âge et taille, mesurée depuis GitHub (pas depuis le serveur surveillé) | ✅ **OUI, exemplairement** | aucun. Vue rouge en conditions réelles, et l'alerte a effectivement ouvert une issue |
| `axe-playwright` | l'application **réellement servie** par `vite preview` | ✅ **OUI** (correction du 16/08 : `http://app:5173` ne résolvait pas) | ⚠️ le périmètre est trop étroit : 2 spécifications sur 16 |
| protection de branche | 4 contextes de statut | ❌ **partiellement** | Les 3 gardes nées d'incidents (`config-prod`, `caddyfile-valide`, `scripts-executables`) **n'y figurent pas**, et `enforce_admins: false` |

---

## 4. La CI est-elle réellement bloquante ? — mesuré par `gh api`, pas par lecture du YAML

`GET /repos/will383842/axion-crm-pro/branches/main/protection` (sortie brute :
`04_PREUVES/agent-38/gh-branch-protection.txt`) :

```json
"required_status_checks": {
  "strict": false,
  "contexts": [
    "Backend Laravel (install + PHPStan + Pint + Pest)",
    "Frontend React/Vite",
    "Workers Node + Playwright",
    "Secrets scan (Gitleaks)"
  ]
},
"enforce_admins":                 { "enabled": false },
"required_linear_history":        { "enabled": false },
"required_conversation_resolution": { "enabled": false },
"allow_force_pushes":             { "enabled": false },
"allow_deletions":                { "enabled": false }
```

`GET /rulesets` → `[]`. Aucune file de fusion. `allow_auto_merge: false`. Dépôt **public**.
**`required_pull_request_reviews` est absent** : aucune revue n'est exigée.
`GET /environments` → `production-direct-ssh` et `staging-direct-ssh`, **`protection_rules: []`** tous
les deux : les « environnements » n'imposent ni relecteur ni délai.

**Ce que cela donne, en clair** :

1. **4 contextes requis sur 36 jobs.**
2. **Les trois gardes nées d'un incident ne sont pas requises** : `config-prod` (faille critique du
   19/08), `caddyfile-valide` (Caddy refuse de démarrer → tout tombe), `scripts-executables`
   (91 sauvegardes ratées sur 91). Une PR peut les rendre rouges et être fusionnée.
3. **`enforce_admins: false`** : le propriétaire — seul contributeur — n'est soumis à **aucun** de ces
   4 contextes. La protection ne s'applique à personne.
4. **Aucun job de `security.yml` autre que Gitleaks n'est requis**, et la preuve n'est pas théorique :
   **PR #186 fusionnée à 09:15:13 alors que son run Security s'est conclu `failure` (Trivy `worker`)
   à ~09:17** — la fusion n'a même pas attendu le résultat.
5. `strict: false` : une branche en retard peut fusionner sur un vert périmé. Atténué par le fait que
   `deploy-direct-ssh` rejoue **toute** la CI sur le commit de `main` — sauf pour les chemins de
   `paths-ignore`.
6. **Le seul enchaînement réellement bloquant du dépôt est `deploy` → `needs: [ci]` + `if:
   needs.ci.result == 'success'`.** Ce n'est pas la fusion qui est gardée, c'est le déploiement.

---

## 5. Jobs qui n'ont JAMAIS tourné

| job | workflow | pourquoi | preuve |
|---|---|---|---|
| `zap-baseline` | `security.yml` | `vars.ZAP_TARGET_URL` jamais posée | `gh api …/actions/variables` → `total_count: 0` |
| `sentry_release` | `release-tracking.yml` | aucun tag `v*`, aucune release ; et secrets GlitchTip absents | `gh run list --workflow 278256548` → **0 run** |
| `trigger-coolify`, `smoke` | `deploy-staging.yml` (ancienne version) | conditionnés à `vars.COOLIFY_STAGING_ENABLED` | **jobs supprimés le 19/08** — le vestige n'existe plus dans le YAML |

**Jamais vus verts** (distinct de « jamais tournés ») : `deploy-staging` — 10 exécutions, 10 échecs.

**Faux « jamais tourné » écartés** : `alerte` de `security.yml` (a tourné : 32230359916) et `alerte` de
`surveillance-sauvegarde.yml` (a tourné 2×, 17/08).

---

## 6. Constats

### [F38-001] La vérification post-déploiement de la préproduction interroge `127.0.0.1` alors que la pile se lie à `172.17.0.1` — 10 déploiements rouges sur 10 pour une préproduction qui répond 200
- Sévérité      : S1
- Domaine       : tests / infrastructure
- Référence     : main `e8924b8` (workflow inchangé depuis `main` 19/08 07:58)
- Emplacement   : `.github/workflows/deploy-staging.yml:156` et `:160` ; contredit `docker-compose.staging.yml:138`
- Constat       : la pile de préproduction publie sur `${STAGING_BIND_IP:-172.17.0.1}:8082:80` et `:8081:5173`, et les deux `curl` de l'étape « Vérifications » visent `http://127.0.0.1:8082/up` et `http://127.0.0.1:8081`.
- Preuve        :
  - `gh run view 32241132949 --log-failed` (→ `04_PREUVES/agent-38/deploy-staging-32241132949-failed.log`) :
    `API préprod (127.0.0.1:8082/up) : MUETTE` · `Frontend préprod (127.0.0.1:8081) : curl: (7) Failed to connect to 127.0.0.1 port 8081` · `##[error]Vérifications post-déploiement en échec.`
  - le **même journal**, quelques lignes plus haut : `Container axion-crm-staging-api Started`, `INFO Nothing to migrate.`, `PRODUCTION intacte : oui`, `axion-crm-staging-api … 172.17.0.1:8082->80/tcp`, `axion-crm-staging-app … 172.17.0.1:8081->5173/tcp`, `OK : la pile ne publie RIEN sur internet`
  - `curl -s -o /dev/null -w '%{http_code}' https://staging.axion-crm-pro.com` → **200** ; `https://staging-api.axion-crm-pro.com/up` → **200** (→ `mesures-externes.txt`)
  - historique complet : `gh run list --workflow 278156307 --limit 100` → **10 `failure` consécutifs**, du 19/08 07:58 au 19/08 10:07 (→ `deploy-staging-historique.txt`)
- Témoin négatif: le témoin positif interne du job (« PRODUCTION intacte ») **répond `oui`** dans le même run — la mécanique de `curl` fonctionne, seule l'adresse est fausse. Et `verifier-ports-publies.sh`, exécuté deux lignes plus bas, **imprime les bonnes adresses** (`172.17.0.1:8081`, `172.17.0.1:8082`) sans que personne ne les compare aux `curl`.
- Impact        : (1) le déploiement de préproduction est rouge en permanence — un rouge permanent cesse d'être lu, et c'est précisément le mécanisme décrit dans `security.yml` à propos de ZAP (5 échecs hebdomadaires invisibles) ; (2) l'étape **« Contrôle depuis l'extérieur »**, la seule qui mesure le bon objet (`https://staging.axion-crm-pro.com` depuis internet), **n'est jamais atteinte** : le `run:` précédent sort en 1 ; (3) le jour où la préproduction tombera vraiment, rien ne changera à l'écran.
- Reproduction  : `gh run view 32241132949 --log-failed | grep -E "MUETTE|MUET|172\.17\.0\.1"` ; puis `curl -I https://staging.axion-crm-pro.com`.
- Correctif     : remplacer `127.0.0.1` par `${STAGING_BIND_IP:-172.17.0.1}` aux lignes 156 et 160 (la variable est déjà celle du compose) — **2 lignes**. Le correctif s'impose : la PR #181 a déplacé la liaison sans mettre à jour la vérification qui la suit. Coût : ~10 min + un push pour observer le vert.
- Statut        : ouvert

### [F38-002] La protection de branche n'exige que 4 contextes sur 36 jobs, n'inclut aucune des trois gardes nées d'un incident, et `enforce_admins: false` la rend inapplicable au seul contributeur
- Sévérité      : S1
- Domaine       : tests / sécurité
- Référence     : main `e8924b8` — mesuré sur l'API GitHub, pas sur le YAML
- Emplacement   : `GET /repos/will383842/axion-crm-pro/branches/main/protection`
- Constat       : les contextes requis sont `Backend Laravel (…)`, `Frontend React/Vite`, `Workers Node + Playwright`, `Secrets scan (Gitleaks)` ; `config-prod`, `caddyfile-valide`, `scripts-executables`, `axe-core Playwright`, les 3 `Container scan (Trivy)` et `SAST (Semgrep)` n'en font pas partie ; `enforce_admins` vaut `false` ; `rulesets` est vide ; les deux environnements ont `protection_rules: []`.
- Preuve        : `04_PREUVES/agent-38/gh-branch-protection.txt` (JSON intégral) et `vars-env-alerte.txt`.
  **Preuve que ce n'est pas théorique** : `gh pr view 186` → `mergedAt: 2026-08-19T09:15:13Z` ; `gh run view 32236507006` → `Container scan (Trivy) (worker, Dockerfile.worker) : failure`, run démarré à 09:13:28 pour 3 min 50 s, soit terminé vers **09:17**. **La PR a fusionné avant même que la garde ne rende son verdict.**
- Témoin négatif: le contrôle sait distinguer requis et non requis — les 4 contextes listés apparaissent bien, avec leur `app_id`, dans `checks[]`. L'absence des trois autres n'est pas un défaut de lecture.
- Impact        : la garde écrite après la faille critique du 19/08 (`config-prod`) peut rougir sans empêcher la fusion. Idem pour la garde née des 91 sauvegardes ratées. Elles ne bloquent que le **déploiement** — donc un code fautif peut atterrir sur `main`, y rester, et bloquer toute mise en production suivante, y compris un correctif urgent sans rapport.
- Reproduction  : `gh api repos/will383842/axion-crm-pro/branches/main/protection`.
- Correctif     : ajouter les 3 contextes CI manquants + `axe-core Playwright` à `required_status_checks.contexts` ; passer `enforce_admins: true` **est une décision de Will** (elle l'empêcherait de forcer un correctif d'urgence — à trancher, pas à imposer). Coût : un `gh api -X PUT` de 5 min. ⚠️ Ne pas ajouter les jobs Trivy tant que `deploy-staging` est rouge : deux gardes bloquantes rouges simultanément figeraient le dépôt.
- Statut        : ouvert

### [F38-003] La seule garde qui mesure les ports RÉELLEMENT publiés ne tourne que sur la préproduction, et son code de retour y est avalé — la production n'est mesurée par aucun job
- Sévérité      : S1
- Domaine       : sécurité / infrastructure
- Référence     : main `e8924b8`
- Emplacement   : `.github/workflows/deploy-staging.yml:175-176` (seul appel) ; absence dans `.github/workflows/deploy-direct-ssh.yml`
- Constat       : `infra/scripts/verifier-ports-publies.sh` — écrit le 19/08 précisément parce que `config-prod` valide le fichier et non l'état qui tourne — n'est invoqué que sur le projet Compose `axion-crm-staging`, et son appel se termine par `|| echo "(contrôle des ports en échec — voir ci-dessus)"`.
- Preuve        : `grep -rn "verifier-ports-publies" --include=*.yml .` → **une seule occurrence dans un workflow**, `deploy-staging.yml:175` (→ `pint-et-ports.txt`). Le script lui-même documente l'écart : *« `config-prod` prouve que le FICHIER est juste. Ce script prouve que le RÉEL l'est. […] une garde ne vaut que si elle rougit SUR L'OBJET QUI CASSE. »*
- Témoin négatif: le script **a** un témoin négatif interne (`exit 2` si aucun conteneur du projet n'est trouvé, avec le commentaire « un résultat conforme sur zéro conteneur serait un mensonge »). Ce témoin est **précisément ce que le `|| echo` annule** : un `exit 2` « je n'ai rien pu mesurer » devient un 0 silencieux.
- Impact        : la pile qui a été percée — `axion-crm-pro` en production — n'est vérifiée par **aucun** job après déploiement. `deploy` recrée `api app horizon scheduler` avec `--no-deps` : `postgres`, `redis` et `reverb` gardent la configuration de leur création indéfiniment. Si l'un d'eux est recréé un jour hors overlay (voir F38-007), la faille se rouvre **sous un déploiement vert**, exactement comme le 19/08.
- Reproduction  : `grep -rn verifier-ports-publies .github/` ; puis lire `deploy-direct-ssh.yml` de bout en bout — aucun appel.
- Correctif     : (1) retirer le `|| echo` de `deploy-staging.yml:176` ; (2) ajouter dans `deploy-direct-ssh.yml`, après le bloc « santé des conteneurs », `bash "$PROJECT_PATH/infra/scripts/verifier-ports-publies.sh" axion-crm-pro` **sans tolérance**. Coût : 3 lignes, ~20 min. ⚠️ à jouer d'abord à la main sur le serveur pour confirmer que la production est conforme, sinon le prochain déploiement sera rouge.
- Statut        : ouvert

### [F38-004] `composer-audit` n'a jamais audité un seul paquet PHP — confirmé sur `e8924b8`
- Sévérité      : S2
- Domaine       : sécurité / tests
- Référence     : main `e8924b8` (run 32241133011, push de la PR #189)
- Emplacement   : `.github/workflows/security.yml:170-178`
- Constat       : le job ne fait pas `composer install` avant `composer audit --no-dev`, et n'utilise pas `--locked` ; la commande répond `No installed packages found. Please run "composer install" before running "audit" or pass "--locked"`, puis le `|| true` rend le job `success`.
- Preuve        : `gh run view 32241133011 --log` → `04_PREUVES/agent-38/security-audits-preuve.txt`, ligne littérale de la sortie du job « PHP deps audit ».
- Témoin négatif: le job **imprime** bien la sortie de `composer` — la mécanique fonctionne ; ce qui manque est la matière à auditer. Le témoin est donc l'inverse : la commande dit elle-même, en clair, qu'elle n'a rien vu. Personne ne l'a lu pendant six semaines.
- Impact        : aucune CVE PHP ne sera jamais signalée. Le dépôt croit avoir un audit de dépendances PHP ; il n'en a pas. Confirme **H47-001** (agent 47) sur la référence courante.
- Reproduction  : `gh run view 32241133011 --log | grep "No installed packages"`.
- Correctif     : remplacer par `composer audit --no-dev --locked` (aucune installation requise, lit `composer.lock`), et retirer le `|| true`. **1 ligne.** ⚠️ le job rougira dès la première CVE : c'est le but, mais il faut décider s'il devient requis (voir F38-002).
- Statut        : ouvert

### [F38-005] Les deux jobs `pnpm-audit` affichent 64 vulnérabilités et rendent `success` ; aucune alerte ne peut naître d'un résultat d'audit
- Sévérité      : S2
- Domaine       : sécurité
- Référence     : main `e8924b8` (run 32241133011)
- Emplacement   : `.github/workflows/security.yml:180-193`
- Constat       : `pnpm audit --audit-level=high || true` — frontend : `31 vulnerabilities found · Severity: 12 moderate | 18 high | 1 critical` ; workers : `33 vulnerabilities found · Severity: 3 low | 19 moderate | 10 high | 1 critical`. Les deux jobs concluent `success`.
- Preuve        : `04_PREUVES/agent-38/security-audits-preuve.txt` (extraits du journal, avec les avis GHSA nommés : `GHSA-r28c-9q8g-f849` postcss, `GHSA-2v37-7h3g-55p8`).
- Témoin négatif: **rectification d'une hypothèse existante.** H47-002 conclut que le job `alerte` « ne peut jamais se déclencher ». J'ai vérifié plutôt que de recopier : `alerte` **a tourné**, run 32230359916, conclusion `success`. En GitHub Actions, `if: failure()` est vrai dès qu'**un** job du workflow a échoué, indépendamment de `needs:`. L'énoncé exact est donc : *`alerte` ne peut jamais se déclencher **à cause d'un résultat d'audit de dépendances***, puisque ces jobs rendent toujours `success` ; il se déclenche bien sur un échec de Gitleaks, Trivy ou Semgrep.
- Impact        : 64 vulnérabilités connues, dont 2 critiques et 28 hautes, restent invisibles hors du journal d'un job vert. À rapprocher du §6 du dossier commun : les 57 alertes GitHub sont réelles mais sans chemin d'appel démontré en production — le défaut ici n'est pas le risque, c'est que **le dispositif ne peut pas le signaler**.
- Reproduction  : `gh run view 32241133011 --log | grep "vulnerabilities found"`.
- Correctif     : retirer `|| true` **est prématuré** (31/33 vulnérabilités → rouge permanent immédiat, donc garde ignorée puis désarmée — le mécanisme que `security.yml` documente lui-même à propos de `ignore-unfixed`). Proposition : ajouter `--audit-level=critical` avec un fichier d'exceptions daté sur le modèle de `.trivyignore.yaml`, et **retirer alors le `|| true`**. Coût : ~2 h (triage des 2 critiques + fichier d'exceptions).
- Statut        : ouvert

### [F38-006] Le job Lighthouse rend `success` depuis toujours en n'ayant produit aucun score : `lhci` sort en 1 sous `continue-on-error`
- Sévérité      : S2
- Domaine       : performance / tests
- Référence     : main `e8924b8` (run 32240894684, PR de `fix/registre-correction-sessions`, 19/08 10:05)
- Emplacement   : `.github/workflows/a11y.yml:65-75`
- Constat       : `lhci autorun --collect.url=https://staging.axion-crm-pro.com` échoue avec `Runtime error encountered: Lighthouse was unable to reliably load the page you requested. (Details: net::ERR_ABORTED)` et **tous** les audits en `FAILED_DOCUMENT_REQUEST` ; `##[error]Process completed with exit code 1` ; le job conclut `success` grâce à `continue-on-error: true`.
- Preuve        : `04_PREUVES/agent-38/a11y-32240894684-full.log` (journal complet, 1 166 lignes) ; extrait dans la section 1.2.
- Témoin négatif: **la cible n'est plus l'excuse.** J'ai mesuré `https://staging.axion-crm-pro.com` → **200 en 0,34 s** et `https://staging-api.axion-crm-pro.com/up` → **200**, depuis ce poste, à la même heure (`mesures-externes.txt`). La préproduction existe et répond ; c'est le Chrome de `lhci` qui est refusé (`ERR_ABORTED` — signature d'une protection anti-robot ou d'une redirection). Le job échoue donc pour une raison **différente** de celle qui était documentée (`NXDOMAIN`), et personne ne peut le savoir puisqu'il est vert.
- Impact        : aucun budget Web Vitals n'est mesuré sur ce produit, alors qu'un job porte ce nom sur chaque PR. C'est le cas exact décrit dans `AGENTS.md` à propos du site : « toute revue qui écrit "le risque est couvert par la gate" raisonne sur une fausse sécurité ».
- Reproduction  : `gh run view 32240894684 --log | grep "Runtime error encountered"`.
- Correctif     : trois options, à trancher par Will. (a) supprimer le job — coût nul, et le dépôt cesse de prétendre mesurer ; (b) le pointer sur le `vite preview` local déjà démarré par `axe-playwright` (mesure de laboratoire fiable, cible correcte) — ~1 h ; (c) diagnostiquer l'`ERR_ABORTED` sur la préproduction — durée inconnue. **Dans tous les cas, retirer `continue-on-error`** : un job qui ne peut pas mesurer doit rougir.
- Statut        : ouvert

### [F38-007] `diag-website-status.yml` recréerait la pile de production **sans l'overlay de prod** — un `workflow_dispatch` rouvre la faille critique du 19/08
- Sévérité      : S1
- Domaine       : sécurité / infrastructure
- Référence     : main `e8924b8`
- Emplacement   : `.github/workflows/diag-website-status.yml:62` (et `:45-54` pour la clé SSH)
- Constat       : le job `recover` exécute `docker compose up -d` sur `/opt/axion-crm-pro` **sans définir `COMPOSE_FILE`** ; la pile serait donc recréée depuis `docker-compose.yml` **seul**, qui déclare `- "55432:5432"` (l. 23) et `- "56379:6379"` (l. 44). Le même job ajoute par ailleurs une clé publique SSH permanente dans `/root/.ssh/authorized_keys` de la production.
- Preuve        : `grep -c COMPOSE_FILE .github/workflows/diag-website-status.yml` → **0** ; `grep -n "5432\|6379" docker-compose.yml` → `23: - "55432:5432"` et `44: - "56379:6379"` (→ `pr186-et-diag.txt`). À comparer avec `deploy-direct-ssh.yml:149`, qui pose explicitement `export COMPOSE_FILE="docker-compose.yml:docker-compose.prod.yml"` et documente pourquoi.
- Témoin négatif: le contrôle sait détecter la présence de `COMPOSE_FILE` — il la trouve bien dans `deploy-direct-ssh.yml:149` et `deploy-staging.yml:132`. Son absence dans `diag-website-status.yml` est mesurée, pas supposée.
- Impact        : un seul `workflow_dispatch` — accessible à quiconque a le droit d'écriture, sans relecteur (`protection_rules: []`) — suffirait à republier Postgres et Redis sur `0.0.0.0`, c'est-à-dire à rouvrir sur internet une base de 4 295 349 fiches contenant des données personnelles, **avec le mot de passe `axion_dev_only` écrit en clair dans un dépôt public**. Et cela se ferait sans passer par la CI, donc sans que `config-prod` ne soit jamais consulté. Le job ramènerait aussi la cible `dev` et le bind mount qui masque le `vendor` de l'image (défaut corrigé le 16/08).
- Reproduction  : lecture du fichier ; **je n'ai PAS déclenché ce workflow** (interdiction explicite).
- Correctif     : au minimum ajouter `export COMPOSE_FILE="docker-compose.yml:docker-compose.prod.yml"` avant le `up -d` (1 ligne) ; mieux : **supprimer ce workflow**, dont l'unique exécution date du 2026-07-07 et dont l'objet (« restaurer l'accès de Claude ») est caduc. Coût : 10 min. ⚠️ à trancher par Will, la clé SSH concerne son accès.
- Statut        : ouvert

### [F38-008] Aucun `timeout-minutes` dans les 8 workflows de la chaîne qualité — six exécutions figées entre 1 h 28 et 2 h 25 au moment de la mesure
- Sévérité      : S2
- Domaine       : tests
- Référence     : main `e8924b8`, mesure du 19/08 vers 11:30
- Emplacement   : `.github/workflows/{ci,a11y,security,deploy-staging,deploy-direct-ssh,build-postgres-image,surveillance-sauvegarde,release-tracking}.yml`
- Constat       : `grep -n "timeout-minutes" .github/workflows/*.yml` ne remonte que les 8 `prospection-*` et `diag-website-status.yml`. Six runs de `Accessibility` étaient `in_progress`, tous arrêtés à la même étape, `Install Playwright browsers` (`pnpm exec playwright install --with-deps chromium`).
- Preuve        : `04_PREUVES/agent-38/a11y-runs-bloques.txt` — pour les runs 32232235879 (démarré 08:21:50), 32233507063 (08:37:24) et 32236095020 (09:08:38), l'API renvoie `{"name":"Install Playwright browsers","status":"in_progress"}` et toutes les étapes suivantes en `pending`. `gh run list` les affiche à `2h19m49s`, `2h4m15s`, `1h33m2s`. Trois autres runs (32236507137, 32235944850, 32231819833) sont dans le même état. Le grep `timeout-minutes` est dans `grep-pieges.txt`.
- Témoin négatif: le même job se termine en **76 s** sur d'autres runs (32240894684 : `4 passed (11.3s)` puis `14 passed (9.6s)`) — le blocage est intermittent, pas structurel, et le contrôle sait donc distinguer un job lent d'un job figé.
- Impact        : (1) sur ces commits, la porte d'accessibilité **ne rend aucun verdict** — ni vert ni rouge, ce qui est pire que les deux ; (2) ces jobs consommeront des minutes GitHub jusqu'au plafond de 6 h ; (3) `axe-playwright` étant le seul job qui exécute des tests E2E, son silence est un angle mort complet. Cause probable : `--with-deps` lance `apt-get` en root et attend un verrou dpkg (`unattended-upgrades`) — non confirmée, le journal d'un job en cours n'est pas récupérable.
- Reproduction  : `gh api repos/will383842/axion-crm-pro/actions/runs/32236095020/jobs --jq '.jobs[].steps[]|select(.status=="in_progress")'`.
- Correctif     : (1) `timeout-minutes: 20` sur `axe-playwright`, et une valeur adaptée sur chacun des 8 workflows — ~30 min ; (2) mettre en cache les navigateurs Playwright (`actions/cache` sur `~/.cache/ms-playwright`) et remplacer `--with-deps` par une installation des dépendances système en une étape séparée. Coût total ~2 h.
- Statut        : ouvert

### [F38-009] Aucun workflow ne mesure la couverture de tests, alors que `CONTRIBUTING.md` l'annonce comme une quality gate
- Sévérité      : S2
- Domaine       : tests / conformité documentaire
- Référence     : main `e8924b8`
- Emplacement   : `.github/workflows/ci.yml:245` (`coverage: none`)
- Constat       : `shivammathur/setup-php@v2` est configuré avec `coverage: none` — aucun pilote de couverture (Xdebug/PCOV) n'est installé, donc `pest --coverage` serait impossible ; et aucun des 17 workflows n'invoque `--coverage`, `--min`, ni ne produit de rapport clover.
- Preuve        : `grep -n "coverage:" .github/workflows/ci.yml` → `245: coverage: none` ; `grep -rniE "coverage|--coverage|clover" .github/workflows/*.yml` → **aucune occurrence de mesure** hors les jobs `coverage:refresh-matrix` (commande métier de couverture géographique, homonymie) et le nom de job « Coverage + stats » (→ `specs-et-couverture.txt`).
- Témoin négatif: la recherche remonte bien les faux positifs (`coverage:refresh-matrix`, `prospection-collect-paris.yml:59`) — elle sait trouver le mot ; il n'y a simplement aucune mesure de couverture de code.
- Impact        : les seuils « Pest ≥ 75 % » et « Vitest ≥ 60 % » de `CONTRIBUTING.md` sont des affirmations sans mécanisme. Confirme **A09-006** sur la référence courante.
- Reproduction  : les deux `grep` ci-dessus.
- Correctif     : soit retirer ces deux seuils de `CONTRIBUTING.md` (10 min, honnête), soit poser `coverage: pcov` et `pest --coverage --min=…` — ce qui **rallongera le job `backend`** (aujourd'hui 1 min 32 s) et exige d'abord de mesurer la couverture réelle avant de fixer un seuil. Décision de Will.
- Statut        : ouvert

### [F38-010] Le commentaire de `ci.yml` chiffre à 276 les fichiers PHP non formatés ; la mesure en donne 386 (311 hors artefact CRLF)
- Sévérité      : S3
- Domaine       : tests
- Référence     : main `e8924b8`, copie de travail de ce poste
- Emplacement   : `.github/workflows/ci.yml:20-22`
- Constat       : `php vendor/bin/pint --test` rend `"result":"fail"` sur **386** fichiers. 240 d'entre eux échouent (entre autres) sur le correcteur `line_ending` — artefact Windows/CRLF (piège 1) ; **75** n'échouent **que** sur `line_ending`. Il reste donc **311** fichiers non formatés pour des raisons indépendantes des fins de ligne.
- Preuve        : `04_PREUVES/agent-38/pint-test-sortie-brute.json` (52,8 Ko, sortie JSON intégrale) et `pint-compte.txt` :
  `total non formatés (poste Windows) : 386` · `dont line_ending SEUL : 75` · `non formatés hors CRLF : 311`.
- Témoin négatif: le décompte distingue bien les deux causes — il isole 75 fichiers dont `fixers` vaut exactement `["line_ending"]`. La méthode sait donc ne pas confondre un défaut de format avec l'artefact de plateforme dénoncé au piège 1 du dossier commun.
- Impact        : faible en soi, mais le chiffre sert d'argument à la décision de ne contrôler que les fichiers modifiés. Un chiffre faux dans un commentaire de CI est une hypothèse déguisée en mesure — c'est la règle 1 de la doctrine.
- Reproduction  : `cd backend && php vendor/bin/pint --test` (≈ 4 min sur ce poste saturé), puis compter `files[]`.
- Correctif     : corriger le commentaire (1 ligne), en indiquant la méthode et la date. Coût : 5 min. ⚠️ le chiffre exact sur un runner Linux propre peut différer, `.gitattributes` normalisant une partie des fins de ligne au clone — le nombre honnête à écrire est **311, hors fins de ligne**.
- Statut        : ouvert

### [F38-011] 14 des 16 spécifications Playwright ne sont exécutées par aucun pipeline
- Sévérité      : S2
- Domaine       : tests
- Référence     : main `e8924b8`
- Emplacement   : `.github/workflows/a11y.yml:48` et `:58` — seuls appels à Playwright de tout `.github/`
- Constat       : `git ls-files 'frontend/tests/e2e/*.spec.ts'` → **16** fichiers. Les workflows n'en nomment que deux : `a11y.spec.ts` et `navigation.spec.ts`. Les 14 autres — `audiences-builder`, `auth`, `campaigns-wizard`, `companies`, **`console-locale`**, `coverage`, `dark-mode`, `dashboard`, `global-search`, `llm`, `onboarding`, `rgpd`, `settings`, `tags-manager` — ne sont exécutés nulle part.
- Preuve        : `04_PREUVES/agent-38/specs-et-couverture.txt` (liste des 16 fichiers + les 3 seules lignes `spec.ts` de `.github/workflows/`).
- Témoin négatif: le grep trouve bien les deux spécifications exécutées, et rien d'autre — il n'est pas aveugle à `spec.ts`.
- Impact        : confirme **A05-009**. Ces 14 fichiers sont du code de test qui ne protège rien : ni rouge, ni vert, juste présent. Ils donnent l'impression d'une couverture E2E qui n'existe pas.
- Reproduction  : `git ls-files 'frontend/tests/e2e/*.spec.ts' | wc -l` puis `grep -rn "spec.ts" .github/workflows/`.
- Correctif     : remplacer les deux commandes ciblées par `playwright test --project=chromium` (toute la suite). ⚠️ **à ne pas faire à l'aveugle** : ces 14 spécifications n'ayant jamais tourné en CI, il faut d'abord les jouer localement pour savoir combien rougissent, et traiter les échecs avant de rendre le job bloquant. Coût : ~1 jour, dont l'essentiel en réparation.
- Statut        : ouvert

### [F38-012] `release-tracking.yml` n'a jamais été exécuté une seule fois et resterait sans effet s'il l'était ; `zap-baseline` n'a jamais rempli sa condition
- Sévérité      : S3
- Domaine       : tests / observabilité
- Référence     : main `e8924b8`
- Emplacement   : `.github/workflows/release-tracking.yml` (workflow id 278256548) ; `.github/workflows/security.yml:215`
- Constat       : `gh run list --workflow 278256548` renvoie **zéro exécution** — aucun tag `v*` n'a jamais été poussé, aucune release publiée. Et son étape `Skip if no GlitchTip configured` le rendrait `no-op` : les secrets `GLITCHTIP_AUTH_TOKEN` et `SENTRY_URL` ne sont pas posés. En parallèle, `zap-baseline` est conditionné à `vars.ZAP_TARGET_URL != ''` et l'API renvoie `{"variables":[],"total_count":0}`.
- Preuve        : `04_PREUVES/agent-38/gh-run-history-par-workflow.txt` (la section `===== workflow 278256548 =====` est vide) et `vars-env-alerte.txt`.
- Témoin négatif: la même commande renvoie bien des exécutions pour les 16 autres workflows, y compris ceux dormant depuis juillet (`diag-website-status` : 1 run le 2026-07-07). Le vide de 278256548 n'est donc pas un défaut d'interrogation.
- Impact        : deux workflows entretenus (mis à jour par Dependabot, lus lors des revues) qui n'ont aucun effet. Ils gonflent la perception de couverture du dispositif. Les source maps ne sont uploadées nulle part : une pile d'erreur de production restera illisible.
- Reproduction  : `gh run list --workflow 278256548 --limit 5` ; `gh api repos/will383842/axion-crm-pro/actions/variables`.
- Correctif     : décision de Will pour chacun : supprimer, ou poser les secrets/variables. Coût de la suppression : 5 min chacun. **Ne rien faire est le pire des trois** : un workflow qui n'a jamais tourné passe pour une garde à chaque revue.
- Statut        : ouvert

### [F38-013] Le SAST Semgrep porte un `continue-on-error: true` non commenté, dans le fichier même qui explique pourquoi on les a retirés
- Sévérité      : S2
- Domaine       : sécurité
- Référence     : main `e8924b8`
- Emplacement   : `.github/workflows/security.yml:41`
- Constat       : `semgrep ci --config=p/owasp-top-ten --config=p/security-audit --config=p/laravel --config=p/typescript` porte `continue-on-error: true`. Le job conclut `success` sur tous les runs observés. Le fichier consacre 14 lignes de commentaire (l. 46-61) à expliquer que le `continue-on-error` de Trivy a été retiré parce qu'il « annulait `exit-code: 1` » — sans mentionner celui-ci, resté en place trois lignes plus haut.
- Preuve        : `04_PREUVES/agent-38/grep-pieges.txt`, section `continue-on-error` : deux occurrences seulement dans tout `.github/workflows/`, dont `security.yml:41`. Historique : `semgrep` conclut `success` sur les 19 runs `main` inspectés (`vars-env-alerte.txt`).
- Témoin négatif: le même grep trouve `a11y.yml:75`, dont j'ai prouvé par le journal qu'il masque un `exit 1` réel. La détection fonctionne ; ce qui manque ici est la preuve qu'un `exit 1` de Semgrep a déjà été masqué — **je ne l'ai pas** : je n'ai vu aucun run où Semgrep aurait rougi. C'est donc un défaut de conception établi, pas un incident constaté.
- Impact        : le seul SAST du dépôt ne peut pas rougir. Ses résultats partent en SARIF dans l'onglet Security — celui dont `security.yml` écrit lui-même, l. 56, que « personne ne consulte ».
- Reproduction  : `grep -n "continue-on-error" .github/workflows/security.yml`.
- Correctif     : **ne pas retirer sèchement** (même piège que F38-005 : un rouge permanent se désarme). Étape 1, jouer `semgrep ci` sur `e8924b8` et compter les remontées ; étape 2, poser un `.semgrepignore` daté ; étape 3 seulement, retirer le `continue-on-error`. Coût : ~3 h. **À défaut, écrire dans le fichier pourquoi il reste** — un décoratif déclaré vaut infiniment mieux qu'un décoratif silencieux.
- Statut        : ouvert

### [F38-014] `pnpm install --frozen-lockfile || pnpm install` : la seule porte E2E teste un arbre de dépendances que rien d'autre ne valide
- Sévérité      : S3
- Domaine       : tests
- Référence     : main `e8924b8`
- Emplacement   : `.github/workflows/a11y.yml:24`
- Constat       : l'installation du job `axe-playwright` retombe sur `pnpm install` non figé si `--frozen-lockfile` échoue, alors que les jobs `frontend` et `workers` de `ci.yml` portent, eux, un `pnpm install --frozen-lockfile` strictement bloquant.
- Preuve        : `04_PREUVES/agent-38/grep-pieges.txt`, section `|| true / || echo` : `a11y.yml:24: run: pnpm install --frozen-lockfile || pnpm install`. À comparer avec `ci.yml:450` et `ci.yml:481`, sans repli.
- Témoin négatif: sur le run 32240894684, le journal montre `Lockfile is up to date, resolution step is skipped` — le premier appel a réussi, le repli n'a pas servi. Le défaut est latent, pas actif aujourd'hui.
- Impact        : c'est le **piège 7 sous sa forme inversée** : au lieu de tuer la suite, l'installation tolérante la fait tourner sur autre chose. Le jour où le lockfile divergera, ce job restera vert en ayant testé un arbre de dépendances que le reste de la CI n'a jamais vu — et son vert dira moins que rien.
- Reproduction  : `grep -n "frozen-lockfile" .github/workflows/*.yml`.
- Correctif     : supprimer ` || pnpm install`. **Une demi-ligne.** Aucun risque : si le lockfile est à jour (il l'est), le comportement ne change pas.
- Statut        : ouvert

---

## 7. Ce que je n'ai PAS pu vérifier, et pourquoi

Ce chapitre est un livrable, pas un aveu.

1. **La cause exacte du blocage de `playwright install --with-deps`.** Le journal d'un job encore
   `in_progress` n'est pas récupérable par `gh run view --log`. Mon hypothèse (verrou `apt`) n'est pas
   mesurée et je ne la présente pas comme un fait. Vérifiable en ajoutant `set -x` + `timeout 600` à
   l'étape et en attendant une récurrence.
2. **La cause exacte de l'`ERR_ABORTED` de Lighthouse.** Depuis ce poste, la préproduction répond 200
   à `curl` ; Chrome en mode headless via `lhci` est refusé. Distinguer une protection anti-robot d'une
   redirection exigerait de rejouer `lhci` localement contre la préproduction, ce que la saturation de
   l'atelier (constat A-009) ne permet pas de faire dans un délai raisonnable.
3. **Le comportement de `pint --test` sur un runner Linux propre.** Mes 386/311 sont mesurés sur cette
   copie de travail Windows, dont A-003 établit qu'elle n'a jamais été renormalisée. Le chiffre que
   verrait la CI peut différer. **J'ai donné les deux nombres plutôt qu'un seul faussement précis.**
4. **Le job `reclassify` de `prospection-collect-region.yml`.** Le dernier run (2026-07-04) a échoué
   dans la matrice `collect` ; l'API ne renvoie que les jobs `Collecte NN`. Je ne peux pas dire si
   `reclassify` a déjà tourné. Le workflow est manuel et dormant depuis six semaines.
5. **Le contenu réel des `.trivyignore.yaml` et l'ancienneté de ses exceptions.** Le fichier existe
   (4 545 octets, 2026-08-16). Vérifier que ses exceptions sont datées et encore justifiées relève du
   périmètre de l'agent sécurité, pas du mien.
6. **Si `enforce_admins: false` a déjà servi à fusionner ou pousser en contournant les 4 contextes
   requis.** L'API ne fournit pas d'historique des contournements. Ce que j'ai prouvé est plus fort et
   suffit : la PR #186 a fusionné **avant la fin** de son run Security rouge — donc ce workflow n'a
   jamais été un gate, contournement ou non.
7. **L'effet réel d'un `docker compose up -d` par `diag-website-status.yml`** (F38-007). Je ne l'ai pas
   déclenché — c'est interdit, et l'hypothèse est précisément qu'il rouvrirait la faille. Le constat
   repose sur la lecture du YAML **plus** la mesure des ports déclarés dans `docker-compose.yml`
   (l. 23 et 44), ce qui est suffisant pour alerter mais pas pour affirmer le résultat d'une exécution.
8. **Un 18e workflow existe côté GitHub** — `TMP — génération baseline PHPStan`, id 333858977, marqué
   `active`, 5 exécutions le 2026-08-13 — **sans fichier correspondant** dans `.github/workflows/`
   (17 fichiers suivis par git). C'est un vestige d'un fichier supprimé ; hors de mon périmètre nommé,
   signalé pour l'inventaire.
