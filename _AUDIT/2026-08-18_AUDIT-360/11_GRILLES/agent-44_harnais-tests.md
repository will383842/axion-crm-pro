# AGENT 44 — Auditeur du harnais de tests

> **Ce qui existe**, **ce qui s'exécute réellement**, **ce qui est exclu en silence**.
> Rien ci-dessous n'est déduit d'un document : chaque case tient à une commande jouée,
> archivée dans `04_PREUVES/agent-44/`.

## 0. Référence — relue juste avant d'écrire

| | |
|---|---|
| Dépôt | `C:\Users\willi\Documents\Projets\Axion-CRM-Pro` |
| `main` au moment de la mesure | **`e8924b8`** (`fix(rgpd+acces): rectification du registre + acces CRM rendu (#189)`) |
| ⚠️ Écart avec le dossier commun | Le `_DOSSIER-AGENT.md` nomme `c0c453d`. `main` a avancé trois fois pendant la session : `c0c453d` → `1145473` → `e8924b8`. |
| Ce qui rend la mesure valide sur toute la fenêtre | `git log --oneline c0c453d..HEAD -- backend/phpunit*.xml frontend/vitest.config.ts frontend/playwright.config.ts workers/vitest.config.ts .github/workflows/ci.yml .github/workflows/a11y.yml backend/tests frontend/tests workers/tests` rend **zéro commit**. **Aucun fichier du harnais de tests n'a bougé entre `c0c453d` et `e8924b8`.** Tout ce qui suit vaut donc aussi pour la référence du dossier. |
| Exécution CI de référence | run **`32241133570`** (`Deploy direct SSH Hetzner`, 2026-08-19T10:07:37Z), `head_sha = e8924b81ad64c0…` = **exactement `main` d'aujourd'hui**. Journal complet archivé (4 564 lignes). |

Preuves : `04_PREUVES/agent-44/ci-run-32241133570-main.log`, `a11y-run-32241133030-main.log`.

---

## 1. Tableau de grille — une ligne par objet du périmètre

Légende « s'exécute » : **CI** = joué par un workflow GitHub ; **local** = joué par une cible `make` ; **nulle part** = aucun des deux.

| # | Objet | Existe (compté) | S'exécute — où | Résultat chiffré **mesuré** | Exclusions silencieuses | Piège 7 (l'installation est-elle bloquante ?) | Preuve |
|---|---|---|---|---|---|---|---|
| 1 | **Pest/PHPUnit** `backend/tests/` | **95** fichiers `*Test.php` (39 `Unit` + 56 `Feature`) ; **0** fichier `.php` hors `Unit`/`Feature` autre que `bootstrap.php`, `Pest.php`, `TestCase.php`, `Support/*` | **CI** — `ci.yml` job `backend`, étape « Pest (BLOQUANT) », vérification **requise** sur `main` ; **local** — `make test-backend` (voir ligne 11) | **780 tests passés, 6 503 assertions, 39,31 s**, 0 échec, 0 ignoré, 0 risqué (run `32241133570`, `main e8924b8`) | **AUCUNE.** 1 seul `markTestSkipped` dans tout `tests/` (`NeDoitPasRegresserTest.php:169`, conditionnel à l'absence de `pg_dump` — **absent** de la sortie CI, donc **non déclenché** en CI). 0 `->skip()`, 0 `->todo()`, 0 `@group`. Aucune balise `<exclude>`, `<groups>`, `<group>` dans **ni l'un ni l'autre** des `phpunit*.xml` | ✅ **Oui.** `composer install` = étape « Install (BLOQUANT) », **sans** `continue-on-error`, **sans** `\|\| true`. Aucun `continue-on-error` nulle part dans `ci.yml` (les 2 occurrences du mot sont des commentaires historiques, l. 6-7) | `ci-run-32241133570-main.log`, `comparaison-phpunit-xml-vs-ci.txt` |
| 2 | **`backend/phpunit.xml` vs `backend/phpunit-ci.xml`** — comparés ligne à ligne | 2 fichiers, 2 `testsuites` **identiques** dans les deux (`Unit` → `tests/Unit`, `Feature` → `tests/Feature`) | les deux : `phpunit.xml` en local (`composer test` = `pest --colors`, config par défaut), `phpunit-ci.xml` en CI (`--configuration phpunit-ci.xml`) | **Une seule différence fonctionnelle : `executionOrder="random"` (local) vs `"default"` (CI).** Tout le reste du `diff` est du commentaire. Les 15 `<env>` sont identiques valeur par valeur | **AUCUN répertoire, groupe ou fichier n'est écarté par `phpunit-ci.xml`.** La quarantaine de `tests/QUARANTAINE.md` (23 fichiers, 61 tests jamais exécutés) est **effectivement levée** : le fichier ne contient plus aucune balise d'exclusion. ⚠️ Voir néanmoins **H44-011** : la seule différence conservée est précisément celle qui, de l'aveu du fichier, faisait passer un même commit de 262 verts à 48 rouges | s.o. | `comparaison-phpunit-xml-vs-ci.txt` |
| 3 | **Vitest frontend** `frontend/vitest.config.ts` | **21** fichiers `*.test.ts(x)` (11 `components`, 6 `screens`, 3 `lib`, 1 `smoke`). ⚠️ Les « 37 fichiers de test frontend » du dossier = **21 Vitest + 16 specs Playwright** | **CI** — `ci.yml` job `frontend`, étape « Test » (`pnpm test` = `vitest run`), vérification **requise** ; **local** — `make test-frontend` | **21 fichiers, 118 tests, 118 passés, 0 échec, 0 ignoré, 213,96 s** (rejoué localement le 2026-08-19 12:00) — identique en CI (« 21 passed (21) ») | **AUCUNE.** 0 `it.skip` / `describe.skip` / `test.todo` / `.only` / `.fixme` dans `frontend/tests` et `frontend/src` (témoin négatif joué). `exclude: ['**/node_modules/**','**/dist/**','tests/e2e/**']` écrase la liste par défaut de Vitest mais ne masque aucun fichier de test existant | ✅ **Oui.** `pnpm install --frozen-lockfile` = « Install (BLOQUANT — lockfile figé) », sans `continue-on-error` | `vitest-frontend-full.txt`, `temoin-negatif-fake.test.ts.txt` |
| 4 | **Le prompt d'audit : « tests Vitest sur la branche seulement »** | — | — | **DÉJÀ RÉFUTÉ** (dossier §6). Non re-rapporté. Vérifié au passage : les 21 fichiers sont bien sur `main e8924b8` | — | — | — |
| 5 | **Playwright** `frontend/playwright.config.ts` | **16** fichiers `*.spec.ts` dans `tests/e2e/`, **285 tests** au total (95 par projet × 3 projets : `chromium`, `firefox`, `mobile-safari`) | **CI — 2 fichiers sur 16, chromium seul** : `a11y.yml` lance nommément `tests/e2e/a11y.spec.ts` puis `tests/e2e/navigation.spec.ts`. **Les 14 autres : nulle part.** `make test-e2e` (`pnpm e2e`) existe mais n'est appelé par **aucun** workflow | **18 tests exécutés sur 285** : « Running 4 tests using 2 workers → 4 passed (10.3 s) » (a11y) puis « Running 14 tests → 14 passed (12.6 s) » (navigation), run `32241133030` sur `e8924b8` | **267 tests jamais joués** : 77 tests chromium des 14 autres specs + les 190 tests `firefox`/`mobile-safari` de **toutes** les specs. Pas de `testIgnore`, pas de `test.skip`, pas de `.only` : l'exclusion se fait **par la ligne de commande du workflow**, donc invisible dans la configuration → **H44-001** | ✅ Oui pour `playwright install --with-deps chromium`. ⚠️ **Mais** l'installation des dépendances est `pnpm install --frozen-lockfile \|\| pnpm install` : un lockfile désynchronisé est rattrapé **en silence** → **H44-008** | `playwright-list-all.txt`, `a11y-run-32241133030-main.log` |
| 6 | **Piège 9 — `localhost` vs `127.0.0.1`** | — | — | **Pas de défaut ici, et c'est mesuré, pas supposé.** `baseURL` par défaut = `https://app.localhost` (le bon choix, serveur de dev). Le `127.0.0.1:4173` n'apparaît **que** dans la branche `E2E_PREVIEW=true`, qui sert le **build** via `vite preview --host 127.0.0.1` — pas un serveur de dev, et l'hôte d'écoute et l'URL cible sont la même chaîne. La CI a11y passe `E2E_BASE_URL=http://127.0.0.1:4173`, cohérent | — | — | `frontend/playwright.config.ts:36-52`, `a11y.yml:44-59` |
| 7 | **`navigation.spec.ts` « rouge en silence »** | 14 tests | **CI** depuis `da97826` (étape 0, F17, 2026-08-18) | **VERT.** Étape « Run navigation E2E (BLOQUANT) » = `success`, « 14 passed (12,6 s) », run `32241133030` sur `e8924b8`. Les 15 derniers runs `Accessibility` sont `success` | — | — | `a11y-run-32241133030-main.log` |
| 8 | ↳ **mais** ce job n'est pas une vérification requise | — | — | `gh api repos/:owner/:repo/branches/main/protection` → contextes requis = `Backend Laravel (…Pest)`, `Frontend React/Vite`, `Workers Node + Playwright`, `Secrets scan (Gitleaks)`. **`axe-core Playwright` n'y est pas.** `enforce_admins: false`. `deploy-direct-ssh.yml` ne dépend que de `ci.yml` | — | — | → **H44-002** |
| 9 | **Vitest workers** `workers/` | **6** fichiers `tests/*.test.ts` | **CI** — `ci.yml` job `workers`, étape « Test » (`pnpm test`), vérification **requise** ; **local** — `make test-workers` (hors conteneur) | **6 fichiers, 61 tests, 61 passés, 0 échec, 0 ignoré, 7,32 s** (rejoué localement) — identique en CI (« 6 passed (6) ») | **AUCUNE aujourd'hui.** `passWithNoTests: false` (une suite vide **doit** rougir — bonne garde). ⚠️ Risque latent : `include: ['tests/**/*.test.ts']` ne collecte **que** `tests/` ; un test posé sous `workers/src/` serait ignoré sans un mot → **H44-010** | ✅ Oui, `pnpm install --frozen-lockfile` BLOQUANT | `vitest-workers-full.txt` |
| 10 | **`MOCKS-STRATEGY.md`** + **`MockServicesProvider.php`** | 208 l. / 94 l. ; **14** contrats dans `app/Contracts/`, **14** liaisons réel/double dans le fournisseur | Chargé à chaque test (`MOCK_MODE=true` dans les deux `phpunit*.xml` **et** dans les 10 variables `MOCK_*` du job CI `backend`) | **Le harnais ne sort pas sur le réseau.** Vérifié sur 2 points chauds : `HibpCheckerTest` et `NotPwnedPasswordRuleTest` injectent un `GuzzleHttp\Handler\MockHandler` (les journaux « connect timeout » / « 503 » de la sortie locale sont **fabriqués par le double**, pas des appels réels) ; en CI les clients à clé (`GooglePlacesClient`, `DomainFinder` Brave) journalisent « skipped (no API key) ». Témoin : `curl` depuis le conteneur atteint bien `api.pwnedpasswords.com` en 0,33 s — l'egress **existe**, il n'est simplement pas emprunté | Le document décrit **2 contrats et 2 doubles qui n'existent pas** (`DnsManager`/`MockDnsManager`, `EmailSender`/`MockEmailSender`) et nomme `RealSmtpProber` comme implémentation de production alors que le fournisseur câble `HunterSmtpProber` → **H44-009** | s.o. | `pest-ci-config-full.txt`, `MOCKS-STRATEGY.md:26-27`, `MockServicesProvider.php:76` |
| 11 | **Base de test `axion_crm_test`** | existe | utilisée par les deux configs (`DB_DATABASE` `force="true"` **+** épinglage `$_SERVER`/`$_ENV`/`putenv` dans `tests/bootstrap.php` **+** garde d'exécution `Tests\TestCase::setUp()`) | **Locale `C` / `C`** (conforme prod, `datcollate=C datctype=C`) et **58 migrations sur 58** (`select count(*) from migrations` = 58 ; `ls database/migrations \| wc -l` = 58 ; dernière : `2026_08_19_000002_crm_activites_et_motifs`). **Aucune recréation nécessaire** — le piège `pg_partman` ne se déclenche pas. Base de développement `axion_crm` **intacte** après mes exécutions (116 tables) | — | — | commandes `psql` ci-dessous |
| 12 | **Le harnais backend **local** (`make test-backend`)** | la cible existe (`Makefile:114`) | **local seulement** | 🔴 **Il ne va pas au bout, et il n'a AUCUNE isolation.** Suite complète lancée dans `axion-crm-api` : **tuée (code 137) après ~55 min et ~230 tests sur 780**, avec 3 fichiers rouges là où la CI est verte sur le même commit. **En cherchant la cause j'ai mesuré le vrai défaut** : `ps aux` dans le conteneur montrait **4 processus Pest simultanés** (dont 3 lancés par d'autres agents de cet audit) **plus deux `php artisan migrate --force`**, tous pointés — par `tests/bootstrap.php` — sur **la même et unique base `axion_crm_test`**, où `RefreshDatabase` émet `DROP TABLE … CASCADE`. **Ma mesure est donc invalide comme mesure du harnais, et probante comme démonstration de l'absence d'isolation** → **H44-004**. La lenteur (`artisan --version` = 3 min 12 s) a été mesurée sous cette même charge : non attribuable en l'état → §4.2 | — | — | `concurrence-conteneur-api.txt`, `pest-ci-config-full.txt` |
| 13 | **Ce qui n'est lancé nulle part** | — | — | Recensement exhaustif des fichiers de test du dépôt (hors `node_modules`/`vendor`) : **138 fichiers**, tous dans 4 racines (`backend/tests`, `frontend/tests`, `workers/tests`) — **aucun répertoire de test orphelin**. Seuls **14 fichiers Playwright** (267 tests) ne sont atteints par aucun workflow | — | — | `croisement-tests-workflows.txt` |
| 14 | **Couverture de code** | seuils déclarés : Vitest `lines/statements/functions 60`, `branches 50` | **nulle part** | `pnpm test:coverage` n'apparaît dans **aucun** `.github/workflows/*.yml` ; le job CI `backend` pose `coverage: none` sur `setup-php`. **Aucune mesure de couverture n'est produite ni évaluée nulle part.** Les seuils sont décoratifs — le fichier de config l'écrit lui-même, je le confirme par la mesure | — | — | → **H44-007** |
| 15 | **Couverture réelle des écrans** | **37 écrans de route** dans `src/app/routeTree.tsx` (36 + `NotFoundPage`) — conforme au dossier §4 | — | **6 écrans sur 37** sont **montés** par un test Vitest : `LoginPage`, `DashboardPage`, `CompanyDetailPage`, `AudienceBuilderPage`, `ContactsHubPage`, `PersonTimelinePage`. **10 écrans sur 37** sont touchés par un test qui **s'exécute en CI** (les 6 précédents + `CompaniesListPage`, `CoveragePage`, `RgpdRequestsPage`, `MediaListPage`, atteints par les 2 specs Playwright jouées). **27 écrans sur 37 ne sont touchés par rien qui tourne.** ⚠️ Le prompt d'audit dit « 1 écran couvert sur ~37 » : **c'est faux, c'est 6** (ou 10 au sens large) | — | — | → **H44-006** |
| 16 | **Piège 7 sur chaque suite** | — | — | **Aucune des 4 suites n'est protégée par un vert de silence à l'installation.** `ci.yml` : 0 `continue-on-error`, 0 `\|\| true` sur une étape d'installation ou de test. `a11y.yml` : 1 seul `continue-on-error`, sur le job **`lighthouse`** (l. 75), qui n'exécute aucun test du dépôt ; l'installation du job `axe-playwright` a en revanche un repli `\|\| pnpm install` (→ H44-008). `security.yml` porte `continue-on-error`/`\|\| true` sur Semgrep, `composer audit`, `pnpm audit` — **hors périmètre tests**, signalé pour l'agent sécurité | — | — | `croisement-tests-workflows.txt` |
| 17 | **`deploy-staging.yml`** (porte de déploiement préproduction) | existe, se déclenche sur **chaque** `push` sur `main`, **sans `paths-ignore`** | — | **Il n'appelle pas `ci.yml` et ne déclare aucun `needs` vers un job de test.** Le seul `needs` est `build-and-push`. La préproduction est donc déployée **sans qu'un seul test ait été exécuté**, en parallèle de la CI et non après elle | — | — | → **H44-003** |
| 18 | **Rejeu croisé local ↔ CI** | — | — | Frontend : **118/118 identique**. Workers : **61/61 identique**. Backend : **divergent** (voir ligne 12) | — | — | — |

---

## 2. Constats

### [H44-001] 14 des 16 fichiers de test Playwright ne sont exécutés par aucun workflow : 267 des 285 tests end-to-end ne tournent nulle part
- Sévérité      : S2
- Domaine       : tests
- Référence     : main `e8924b8` (harnais inchangé depuis `c0c453d`)
- Emplacement   : `.github/workflows/a11y.yml:48` et `:58` ; `frontend/tests/e2e/` (16 specs) ; `Makefile:120`
- Constat       : le seul workflow qui lance Playwright appelle nommément deux fichiers (`a11y.spec.ts`, `navigation.spec.ts`) avec `--project=chromium` ; les 14 autres specs et les projets `firefox` / `mobile-safari` ne sont invoqués par aucun `.yml`.
- Preuve        :
  - `CI=true pnpm exec playwright test --list` → `Total: 285 tests in 16 files` ; par projet chromium : `a11y 4, audiences-builder 2, auth 4, campaigns-wizard 1, companies 3, console-locale 35, coverage 2, dark-mode 4, dashboard 2, global-search 4, llm 9, navigation 14, onboarding 4, rgpd 3, settings 3, tags-manager 1` → 95 par projet.
  - `grep -rn "e2e\|spec.ts" .github/workflows/*.yml` → **3 lignes**, toutes dans `a11y.yml`, dont une de commentaire.
  - Journal du run `32241133030` (main `e8924b8`) : `Running 4 tests using 2 workers` → `4 passed`, puis `Running 14 tests` → `14 passed`. **18 tests joués.**
  - Fichiers : `04_PREUVES/agent-44/playwright-list-all.txt`, `a11y-run-32241133030-main.log`, `croisement-tests-workflows.txt`.
- Témoin négatif: la même commande `grep` **trouve** les deux specs qui sont réellement lancées, et la même liste Playwright **compte** les 4 + 14 tests observés dans le journal CI. Le contrôle sait donc distinguer « lancé » de « non lancé » ; il ne rend « nulle part » que pour les 14 autres.
- Impact        : `console-locale.spec.ts` (35 tests, le plus gros fichier e2e du dépôt), `auth.spec.ts`, `campaigns-wizard.spec.ts`, `global-search.spec.ts`, `llm.spec.ts`, `onboarding.spec.ts`… peuvent être rouges depuis des semaines sans que rien ne l'annonce. Une régression de parcours utilisateur passe en production sans obstacle. Les projets `firefox` et `mobile-safari` sont déclarés dans la configuration — le dépôt **affirme** couvrir trois navigateurs et n'en mesure aucun en dehors de chromium sur deux fichiers.
- Reproduction  : `cd frontend && CI=true pnpm exec playwright test --list` ; puis `grep -rn "playwright test" ../.github/workflows/`.
- Correctif     : ajouter au job `axe-playwright` une étape qui lance `pnpm exec playwright test --project=chromium` **sans liste de fichiers** (la `testDir` sert alors de périmètre), après avoir constaté et réparé ce qui rougit. Coût : la découverte des rouges est l'essentiel du travail — 18 tests aujourd'hui verts contre 95 à faire passer ; compter une demi-journée de tri, puis quelques heures par spec cassée. Élargir à `firefox`/`mobile-safari` suppose de les installer (`playwright install --with-deps firefox webkit`, ~2 min de CI en plus).
- Statut        : ouvert

### [H44-002] Le seul job qui exécute des tests Playwright n'est pas une vérification requise : il peut rougir sans bloquer ni la fusion ni le déploiement
- Sévérité      : S2
- Domaine       : tests
- Référence     : main `e8924b8`
- Emplacement   : protection de branche `main` ; `.github/workflows/a11y.yml:12` (job `axe-playwright`) ; `.github/workflows/deploy-direct-ssh.yml:64-74`
- Constat       : les vérifications requises sur `main` sont `Backend Laravel (install + PHPStan + Pint + Pest)`, `Frontend React/Vite`, `Workers Node + Playwright`, `Secrets scan (Gitleaks)` — le job `axe-core Playwright` n'en fait pas partie, et le déploiement ne dépend que de `ci.yml`.
- Preuve        :
  - `gh api repos/:owner/:repo/branches/main/protection` →
    `"contexts":["Backend Laravel (install + PHPStan + Pint + Pest)","Frontend React/Vite","Workers Node + Playwright","Secrets scan (Gitleaks)"]`, `"enforce_admins":{"enabled":false}`.
  - `deploy-direct-ssh.yml` : `ci: uses: ./.github/workflows/ci.yml` puis `deploy: needs: [ci]` — **aucune** mention d'`a11y.yml`.
  - Fichier : `04_PREUVES/agent-44/croisement-tests-workflows.txt`.
- Témoin négatif: la même requête **rend** les quatre contextes qui sont, eux, requis ; et le nom exact du job (`axe-core Playwright`, cf. `a11y.yml:13`) a été cherché dans la liste. Le contrôle sait trouver un contexte requis quand il y en a un.
- Impact        : le travail de l'étape 0 F17 (remettre `navigation.spec.ts` sous exécution, et le déclarer « BLOQUANT » dans le `.yml`) ne bloque en réalité rien. Le mot « BLOQUANT » à `a11y.yml:56` décrit une intention, pas un mécanisme : la seule chose qu'il fait échouer est le job lui-même, qui n'a de conséquence sur rien. C'est exactement le piège 19 du dossier — une garde irréprochable qui ne tient pas la porte qu'elle prétend tenir. Mêmes conséquences pour les 4 contrôles axe-core : une violation d'accessibilité critique n'arrête rien.
- Reproduction  : `gh api repos/:owner/:repo/branches/main/protection --jq '.required_status_checks.contexts'`.
- Correctif     : ajouter `axe-core Playwright` aux contextes requis (`gh api -X PATCH …/branches/main/protection/required_status_checks --field contexts[]=…`), **ou** — plus solide car cela couvre aussi le déploiement direct sur `main` — déplacer le job Playwright dans `ci.yml`, qui est déjà appelé par `deploy-direct-ssh.yml`. Coût : ~1 h, plus ~3 min de CI par exécution (installation du navigateur + build). Prérequis : H44-001, sinon on rend requis un job qui ne mesure que 18 tests.
- Statut        : ouvert

### [H44-003] La préproduction est déployée à chaque poussée sur `main` sans qu'un seul test soit exécuté
- Sévérité      : S1
- Domaine       : tests
- Référence     : main `e8924b8`
- Emplacement   : `.github/workflows/deploy-staging.yml:3-6` (déclencheur) et `:73-75` (`needs: build-and-push`)
- Constat       : `deploy-staging.yml` se déclenche sur tout `push` sur `main`, n'appelle pas `ci.yml`, et son job de déploiement ne dépend que de la construction de l'image.
- Preuve        :
  - `sed -n '1,20p' .github/workflows/deploy-staging.yml` → `on: push: branches: [main]`, **sans `paths-ignore`**.
  - `grep -n "needs:\|uses:" .github/workflows/deploy-staging.yml` → `needs: build-and-push` (l. 75) ; aucune ligne `uses: ./.github/workflows/ci.yml`.
  - `gh run list` pour la poussée `e8924b8` : `Deploy Staging` démarre à `10:07:37Z`, **en même temps** que `CI (gate bloquant)`, pas après.
  - Fichier : `04_PREUVES/agent-44/croisement-tests-workflows.txt`.
- Témoin négatif: le contrôle est le même que celui qui **trouve** la porte dans `deploy-direct-ssh.yml` (`uses: ./.github/workflows/ci.yml` l. 66 + `needs: [ci]` l. 74). Le motif recherché existe donc dans le dépôt et le `grep` le voit ; il ne le voit pas dans `deploy-staging.yml` parce qu'il n'y est pas.
- Impact        : la préproduction — celle dont le rôle est justement de rattraper ce que la production ne doit pas subir — reçoit du code non testé. Un commit qui casse la suite Pest atterrit sur `staging.axion-crm-pro.com` pendant que la CI est encore en train de le mesurer. Aggravant : sans `paths-ignore`, même un commit purement documentaire redéploie. Le run `Deploy Staging` de `e8924b8` est d'ailleurs en `failure` alors que le déploiement de production est en `success` — la préproduction diverge de la production sans que rien ne l'arrête.
- Reproduction  : `grep -n "needs:\|uses:\|paths-ignore" .github/workflows/deploy-staging.yml` puis comparer à `deploy-direct-ssh.yml`.
- Correctif     : ajouter à `deploy-staging.yml` le même bloc que `deploy-direct-ssh.yml` — un job `ci: uses: ./.github/workflows/ci.yml` et un `needs: [ci]` sur `build-and-push`. Coût : ~15 min d'édition ; ajoute ~2 min au délai de déploiement en préproduction (la CI tourne de toute façon en parallèle sur le même commit et son résultat serait réutilisé). Ajouter au passage le même `paths-ignore` que le déploiement de production.
- Statut        : ouvert

### [H44-004] Le harnais de tests local n'a aucune isolation : toute exécution est épinglée sur l'unique base `axion_crm_test`, où `RefreshDatabase` émet `DROP TABLE … CASCADE`
- Sévérité      : S2
- Domaine       : tests
- Référence     : main `e8924b8` (harnais inchangé depuis `c0c453d`)
- Emplacement   : `backend/tests/bootstrap.php:27` (`const TEST_DATABASE_NAME = 'axion_crm_test';`) ; `backend/tests/TestCase.php:31` (la garde valide le **préfixe**) ; `Makefile:112-118`
- Constat       : `tests/bootstrap.php` écrit `axion_crm_test` dans `$_SERVER`, `$_ENV` et `putenv()` avant tout démarrage de l'application — deux exécutions locales lancées en même temps visent donc la même base, et la première à atteindre `RefreshDatabase` détruit les tables de l'autre. Rien dans le harnais ne l'empêche ni ne l'annonce.
- Preuve        :
  - Observation directe pendant ma propre exécution — `docker exec axion-crm-api sh -c 'ps aux | grep "[p]est"'` →
    **4 processus Pest simultanés** : `pest --filter=Etancheite`, `pest --configuration phpunit-ci.xml --testsuite Unit` (le mien), `pest --configuration=/var/www/html/phpunit.xml --filter=CrmOutbound`, `pest --configuration=/var/www/html/phpunit.xml --filter=EtancheiteParTable` ;
    et dans le même `ps`, **deux `php artisan migrate --force`**, un `php artisan crm:flush-outbound`, un `php /tmp/seed.php`.
  - Conséquence mesurée sur mon exécution : `docker exec axion-crm-api php vendor/bin/pest --configuration phpunit-ci.xml` → **`EXIT=137` (SIGKILL)** après ~55 min et ~230 tests sur 780, avec 3 fichiers rouges (`Unit\Audiences\AudienceBuilderServiceTest`, `Unit\Auth\AuthServiceTest`, `Unit\Classification\AutoClassifierServiceTest`) **verts en CI sur le même SHA** (`Tests: 780 passed (6503 assertions)` / `39.31s`).
  - `docker inspect axion-crm-api` → `Memory=0`, `OOMKilled=false` : la mort n'est pas un dépassement de limite du conteneur.
  - La base porte déjà la trace du problème : `axion_crm_test`, `axion_crm_test_test_1`, `axion_crm_test_test_2` coexistent — ce sont les suffixes de l'exécution **parallèle** de Laravel (`--parallel`), qui isole les processus **d'une même exécution** mais **pas deux exécutions distinctes**.
  - Fichiers : `04_PREUVES/agent-44/concurrence-conteneur-api.txt`, `pest-ci-config-full.txt`, `ci-run-32241133570-main.log`, `base-de-test-axion_crm_test.txt`.
- Témoin négatif: la garde `Tests\TestCase::setUp()` **existe et fonctionne** — elle refuse de démarrer si la connexion ne pointe pas une base dont le nom commence par `axion_crm_test`, et c'est elle qui a protégé la base de développement (`axion_crm` **intacte, 116 tables**, après toutes mes exécutions). Le harnais sait donc protéger la base de *développement* ; ce qu'il ne fait pas, c'est protéger une exécution de test d'une autre. Ce n'est pas une cécité de ma mesure : c'est une garde qui existe et qui s'arrête exactement une marche trop tôt.
- Impact        : c'est le piège 12 du dossier retourné — non pas un test qui pré-insère ce qu'il doit produire, mais un test dont l'état est détruit par un tiers. C'est grave parce que le résultat est **indiscernable d'un vrai défaut** : un rouge de collision ressemble en tout point à une régression, et un vert de collision (table recréée entre l'insertion et l'assertion) ressemble en tout point à un succès. Aujourd'hui même, plusieurs agents de cet audit se sont mutuellement invalidés sans le savoir, et **je ne peux pas dire si mes 3 rouges sont des défauts ou des collisions** — c'est exactement l'information qu'un harnais doit rendre impossible à perdre. Hors audit, le même mécanisme frappe dès que deux personnes, ou une personne et l'exécution automatique de son éditeur, lancent la suite en même temps sur le même poste.
- Reproduction  : dans deux terminaux, simultanément : `docker exec axion-crm-api php vendor/bin/pest --filter=Audit` et `docker exec axion-crm-api php vendor/bin/pest --filter=Audiences`. Observer `ps aux | grep pest` dans le conteneur, puis les échecs erratiques.
- Correctif     : dériver le nom de la base d'un identifiant du processus — `tests/bootstrap.php` peut composer `axion_crm_test_` + `getmypid()` (ou la valeur d'un `TEST_DB_SUFFIX`), en conservant le préfixe que la garde de `TestCase` contrôle déjà : **elle valide le préfixe, pas l'égalité, le correctif est donc compatible tel quel**. Prévoir la création à la volée (`CREATE DATABASE … TEMPLATE axion_crm_test`) et la suppression en fin d'exécution. Coût : ~½ j, plus la vérification que `--parallel` continue de fonctionner. Repli gratuit et immédiat : un verrou `flock` dans la cible `test-backend` du `Makefile` — il sérialise au lieu d'isoler, ~15 min, et il supprime au moins la collision *silencieuse*.
- Statut        : ouvert

### [H44-005] `memory_limit` vaut 128 M dans le conteneur qui exécute la suite de 780 tests
- Sévérité      : S3
- Domaine       : tests
- Référence     : main `e8924b8`
- Emplacement   : configuration PHP du conteneur `axion-crm-api` (`php -i` → `memory_limit => 128M`) ; `infra/php/` ne pose que `opcache-local.ini`
- Constat       : le conteneur qui sert de harnais local exécute Pest avec la valeur par défaut de PHP, 128 Mo, là où la CI laisse la valeur du profil `shivammathur/setup-php` et où aucune des deux n'est déclarée dans le dépôt.
- Preuve        : `docker exec axion-crm-api sh -c 'php -i | grep -E "^memory_limit|^opcache.enable|^opcache.validate_timestamps"'` →
  `memory_limit => 128M => 128M`, `opcache.enable => On`, `opcache.enable_cli => On`, `opcache.validate_timestamps => Off`.
  Fichier : `04_PREUVES/agent-44/api-container-mounts-et-boot-laravel.txt`.
- Témoin négatif: la même commande **rend** les trois réglages d'opcache que `infra/php/opcache-local.ini` déclare — le contrôle sait donc lire la configuration effective du conteneur, et il ne trouve aucune ligne `memory_limit` posée par le dépôt parce qu'il n'y en a pas.
- Impact        : faible mais réel, et sournois. Un dépassement de `memory_limit` en PHP produit une erreur fatale au milieu de la suite ; selon l'endroit, elle peut être lue comme un échec de test plutôt que comme une limite atteinte. Surtout, la valeur n'est **pas déclarée dans le dépôt** : elle dépend de l'image de base, donc elle peut changer à une montée de version sans que rien ne le signale, et elle diffère silencieusement entre le poste et la CI.
- Reproduction  : `docker exec axion-crm-api php -i | grep "^memory_limit"`.
- Correctif     : poser explicitement `memory_limit=512M` dans un fichier `infra/php/` monté par `docker-compose.local.yml`, et la même valeur dans l'étape `Setup PHP` de `ci.yml` (`ini-values: memory_limit=512M`) pour que les deux environnements soient déclarés et identiques. Coût : ~20 min.
- Statut        : ouvert
### [H44-006] 6 écrans de route sur 37 sont montés par un test ; 27 sur 37 ne sont touchés par rien qui s'exécute
- Sévérité      : S2
- Domaine       : tests
- Référence     : main `e8924b8`
- Emplacement   : `frontend/src/app/routeTree.tsx` (37 routes) ; `frontend/tests/screens/` (6 fichiers) ; `frontend/tests/e2e/a11y.spec.ts`, `navigation.spec.ts`
- Constat       : les tests Vitest montent 6 des 37 composants d'écran, et les seules specs Playwright réellement jouées en CI visitent 6 adresses, dont 2 déjà couvertes — soit 10 écrans touchés et 27 jamais atteints par un test qui tourne.
- Preuve        :
  - `grep -n "createRoute" src/app/routeTree.tsx` → 37 routes portant un `component:` (36 + `notFoundRoute`), conforme au dossier §4.
  - `grep -rhno "@/features/[A-Za-z0-9/_-]*" tests/ | sort -u` → les seuls composants d'écran importés sont `auth/LoginPage`, `dashboard/DashboardPage`, `companies/CompanyDetailPage`, `audiences/AudienceBuilderPage`, `crm-console/ContactsHubPage`, `crm-console/PersonTimelinePage` → **6**.
  - `a11y.spec.ts` visite `/login`, `/companies`, `/coverage`, `/rgpd/requests` ; `navigation.spec.ts` visite `/` et `/media` → 6 adresses, dont `/login` et `/` déjà couvertes par Vitest → **+4 écrans**.
  - Fichiers : `04_PREUVES/agent-44/vitest-frontend-full.txt`, `a11y-run-32241133030-main.log`.
- Témoin négatif: la même extraction **trouve** bien 6 imports d'écran (et distingue les imports non-écran : `ConsoleGate`, `useConsoleFeatures`, `types`). Elle sait donc reconnaître un écran monté ; le « rien » des 31 autres est une absence constatée, pas une cécité de l'outil.
- Impact        : 27 écrans — dont `SettingsPage`, `UsersPage`, `CampaignWizardPage`, `TagsManagerPage`, `AuditLogsPage`, `ArbitragePage`, `CandidatesPage`, les 3 écrans `llm/*`, les 2 écrans `media/*` — peuvent cesser de se rendre sans qu'aucune porte ne s'en aperçoive. ⚠️ **Le prompt d'audit affirme « 1 écran de route couvert sur ~37 » : c'est faux.** Le chiffre exact est **6 montés par Vitest**, **10 touchés par un test qui s'exécute en CI**. Ce constat corrige le prompt autant qu'il signale le manque.
- Reproduction  : `cd frontend && grep -rhno "@/features/[A-Za-z0-9/_-]*" tests/ | sed 's|.*@/features/||' | sort -u`.
- Correctif     : la voie la moins chère n'est pas 31 tests d'écran mais **H44-001** — `console-locale.spec.ts` seul (35 tests) parcourt déjà une grande partie des écrans manquants, et il est écrit. Le remettre sous exécution transforme 0 écran couvert en une dizaine pour le coût de la réparation de la spec. Coût : cf. H44-001. Ensuite seulement, ajouter des tests de montage Vitest pour les écrans à formulaire (`SettingsPage`, `CampaignWizardPage`, `UsersPage`) — ~1 j chacun sur le modèle de `AudienceBuilderPage.test.tsx`.
- Statut        : ouvert

### [H44-007] Aucune mesure de couverture n'est produite ni évaluée nulle part : les seuils Vitest sont décoratifs et le backend tourne sans couverture
- Sévérité      : S3
- Domaine       : tests
- Référence     : main `e8924b8`
- Emplacement   : `frontend/vitest.config.ts:44-56` (`thresholds: { lines: 60, statements: 60, functions: 60, branches: 50 }`) ; `.github/workflows/ci.yml:244` (`coverage: none`)
- Constat       : `pnpm test:coverage` n'est appelé par aucun workflow, et le job CI backend configure PHP avec `coverage: none` — aucune des deux piles ne produit de rapport de couverture.
- Preuve        :
  - `grep -rn "coverage" .github/workflows/*.yml` → une seule occurrence : `coverage: none` (`ci.yml`, étape `Setup PHP 8.3`).
  - `ci.yml:459` : `run: pnpm test` (= `vitest run`), jamais `pnpm test:coverage`.
  - Fichier : `04_PREUVES/agent-44/croisement-tests-workflows.txt`.
- Témoin négatif: le même `grep` **trouve** `coverage: none` — le motif « coverage » est bien détectable dans les workflows. Son absence ailleurs est donc une absence réelle.
- Impact        : quatre nombres (60/60/60/50) figurent dans la configuration et n'ont jamais rien mesuré ni rien bloqué. Toute revue qui écrirait « la couverture est gardée à 60 % » raisonnerait sur une fausse sécurité — c'est le même patron que le paragraphe « vérité des gates » du `AGENTS.md`. Le fichier de configuration le reconnaît lui-même en commentaire ; je le confirme par la mesure et je l'ouvre en constat pour qu'il soit tranché plutôt que documenté.
- Reproduction  : `grep -rn "coverage" .github/workflows/*.yml`.
- Correctif     : soit rendre les seuils réels (`pnpm test:coverage` dans le job `frontend`, après avoir mesuré la couverture actuelle — avec 6 écrans sur 37 montés, 60 % de lignes n'est probablement pas atteint et le passage serait immédiatement rouge), soit **retirer les seuils** pour ne pas laisser un nombre qui ment. Coût : ~30 min pour la mesure, la décision ensuite. Recommandation : mesurer d'abord, puis fixer le seuil au niveau réel constaté et n'autoriser qu'une progression.
- Statut        : ouvert

### [H44-008] Le job Playwright rattrape en silence un lockfile désynchronisé et mesure alors d'autres dépendances que la CI
- Sévérité      : S3
- Domaine       : tests
- Référence     : main `e8924b8`
- Emplacement   : `.github/workflows/a11y.yml:25-26`
- Constat       : `run: pnpm install --frozen-lockfile || pnpm install` — là où `ci.yml` (l. 450) écrit `pnpm install --frozen-lockfile` seul, sous l'intitulé « Install (BLOQUANT — lockfile figé) ».
- Preuve        :
  - `sed -n '23,27p' .github/workflows/a11y.yml` → `run: pnpm install --frozen-lockfile || pnpm install`.
  - `sed -n '448,451p' .github/workflows/ci.yml` → `run: pnpm install --frozen-lockfile` (sans repli).
  - Fichier : `04_PREUVES/agent-44/croisement-tests-workflows.txt`.
- Témoin négatif: la comparaison est faite avec un job du **même** dépôt qui installe la **même** application (`frontend`) et n'a pas le repli. La différence est donc bien une différence de traitement, pas une contrainte de la plateforme.
- Impact        : c'est le piège 7 du dossier sous une forme atténuée. L'installation ne meurt pas — mais si le lockfile diverge de `package.json`, `ci.yml` rougit tandis qu'`a11y.yml` résout silencieusement d'autres versions et déclare vert des tests joués sur une pile que personne n'a validée. Le résultat n'est pas un silence complet ; c'est pire à sa manière, c'est un vert sur un autre objet que celui qu'on croit mesurer (piège 19).
- Reproduction  : `grep -n "pnpm install" .github/workflows/a11y.yml .github/workflows/ci.yml`.
- Correctif     : retirer ` || pnpm install`. Coût : 1 min. Si le repli avait été ajouté pour contourner une dérive réelle du lockfile, c'est la dérive qu'il faut corriger, pas la porte qu'il faut assouplir.
- Statut        : ouvert

### [H44-009] `MOCKS-STRATEGY.md` décrit deux contrats et deux doubles qui n'existent pas, et nomme la mauvaise implémentation de production pour le sondage SMTP
- Sévérité      : S3
- Domaine       : tests
- Référence     : main `e8924b8`
- Emplacement   : `MOCKS-STRATEGY.md:16` et `:26-27` ; `backend/app/Contracts/` ; `backend/app/Providers/MockServicesProvider.php:76`
- Constat       : le tableau de la stratégie annonce les couples `DnsManager`/`MockDnsManager` et `EmailSender`/`MockEmailSender`, absents du code ; et il donne `RealSmtpProber` comme implémentation de production de `SmtpProber` alors que le fournisseur câble `HunterSmtpProber`.
- Preuve        :
  - `ls backend/app/Contracts/` → 14 fichiers : `AnnuaireEntreprisesClient, BanGeocoder, BodaccClient, CaptchaSolver, DirectionFinder, FranceTravailClient, GoogleMapsScraper, InseeClient, LLMClient, PagesJaunesScraper, ProxyProvider, SearchEngine, SmtpProber, WebsiteScraper`. **Ni `DnsManager` ni `EmailSender`.**
  - `grep -rln "DnsManager\|EmailSender" backend/app/` → **aucun fichier**.
  - `MockServicesProvider.php:76` → `$bind(SmtpProber::class, HunterSmtpProber::class, MockSmtpProber::class, 'MOCK_SMTP');`, avec en commentaire « `RealSmtpProber` gardé en classe pour fallback manuel mais plus wired par défaut ».
- Témoin négatif: le même `grep` **trouve** `IPRoyalProvider` (autre nom cité par le document) dans `app/Services/Proxies/IPRoyalProvider.php`, et `MockCaptchaSolver` dans `app/Services/Captcha/Mocks/`. Le contrôle sait donc trouver ce que le document nomme, quand cela existe.
- Impact        : le document est la référence citée par `MockServicesProvider.php` (« Cf. MOCKS-STRATEGY.md ») et par la liste de bascule vers les vrais services. Deux lignes sur seize y décrivent des doubles inexistants — une bascule `MOCK_MODE=false` conduite depuis ce tableau chercherait à couper des mocks qui ne sont pas là, et surtout **ne se poserait pas la question de l'envoi d'e-mails ni du DNS**, qui ne sont couverts par aucun drapeau. Défaut de finition, mais sur le document qui sert précisément à ne pas se tromper le jour du basculement.
- Reproduction  : `ls backend/app/Contracts/ && grep -rln "DnsManager\|EmailSender" backend/app/`.
- Correctif     : mettre le tableau à jour — retirer les deux lignes ou marquer « prévu, non implémenté », et corriger `RealSmtpProber` → `HunterSmtpProber`. Coût : 15 min.
- Statut        : ouvert

### [H44-010] La collecte Vitest des workers est bornée à `tests/` : tout test posé ailleurs serait ignoré sans un mot
- Sévérité      : S3
- Domaine       : tests
- Référence     : main `e8924b8`
- Emplacement   : `workers/vitest.config.ts:26` (`include: ['tests/**/*.test.ts']`)
- Constat       : la configuration ne collecte que `workers/tests/**` ; un fichier `*.test.ts` placé à côté du code dans `workers/src/` ne serait ni collecté ni signalé.
- Preuve        :
  - `cat workers/vitest.config.ts` → `include: ['tests/**/*.test.ts']`.
  - `find workers -name "*.test.ts" -not -path "./node_modules/*"` → **6** fichiers, tous dans `workers/tests/` — donc **0 test masqué aujourd'hui**.
  - Exécution : `pnpm test` → `Test Files 6 passed (6)`, `Tests 61 passed (61)`, 7,32 s. Fichier : `04_PREUVES/agent-44/vitest-workers-full.txt`.
- Témoin négatif: `passWithNoTests: false` est posé, et l'en-tête du fichier documente l'incident qui l'a motivé (la configuration remontait jusqu'à `C:\Users\willi\vitest.config.ts`, hors du dépôt, et rendait vert avec zéro test). La garde qui existe est bonne ; elle ne couvre simplement pas ce cas-ci, puisqu'il resterait 6 tests collectés et donc pas de suite vide.
- Impact        : latent, nul aujourd'hui. Il devient réel le jour où quelqu'un adopte la convention « test à côté du code », très répandue en TypeScript : la suite resterait verte à 61 tests et personne ne verrait que les nouveaux ne tournent pas. C'est le même mécanisme, en plus discret, que celui décrit dans l'en-tête du fichier.
- Reproduction  : `cat workers/vitest.config.ts` puis `find workers/src -name "*.test.ts"`.
- Correctif     : élargir à `include: ['tests/**/*.test.ts', 'src/**/*.test.ts']`. Coût : 2 min. Le risque de régression est nul (0 fichier concerné aujourd'hui), et la garde `passWithNoTests: false` reste inchangée.
- Statut        : ouvert

---

### [H44-011] La CI est épinglée sur l'ordre d'exécution qui passe, et aucune porte ne mesure plus le couplage entre tests
- Sévérité      : S2
- Domaine       : tests
- Référence     : main `e8924b8`
- Emplacement   : `backend/phpunit-ci.xml:27` (`executionOrder="default"`) vs `backend/phpunit.xml:6` (`executionOrder="random"`) ; `.github/workflows/ci.yml:425`
- Constat       : la **seule** différence fonctionnelle entre les deux configurations est l'ordre d'exécution, et c'est précisément le réglage dont l'en-tête de `phpunit-ci.xml` écrit que deux exécutions du même commit ont donné « 262 verts puis 48 rouges ». L'ordre aléatoire, qui débusque ce couplage, n'est plus joué que par une exécution locale — laquelle, faute d'isolation (H44-004), ne produit pas de verdict exploitable.
- Preuve        :
  - `diff` des deux fichiers, commentaires de fin de ligne neutralisés → une seule ligne de contenu diffère : `executionOrder="random"` / `executionOrder="default"`. Aucune balise `<exclude>`, `<groups>`, `<group>` dans l'un ou l'autre ; les deux `testsuites` sont identiques ; les 15 `<env>` sont identiques valeur par valeur. Fichier : `04_PREUVES/agent-44/comparaison-phpunit-xml-vs-ci.txt`.
  - `.github/workflows/ci.yml:425` → `run: php vendor/bin/pest --colors --configuration phpunit-ci.xml`. Aucune autre invocation de Pest dans aucun workflow (`grep -rn "pest" .github/workflows/`).
  - `backend/composer.json` → `"test": "pest --colors"` (sans `--configuration`) : `make test-backend` utilise donc bien `phpunit.xml`, l'ordre aléatoire.
  - CI sur `e8924b8` : `Tests: 780 passed (6503 assertions)` — vert, en ordre fixe.
- Témoin négatif: le même `diff`, joué sur les mêmes fichiers, **rend bien les différences de commentaires** (une vingtaine de lignes) — il ne rate donc pas les écarts textuels. C'est bien qu'il n'y a qu'un seul écart fonctionnel, et que c'est celui-là.
- Impact        : le dépôt a fait le bon geste en refusant qu'une porte « change d'avis à code constant » — mais la contrepartie n'a jamais été armée. Aujourd'hui, un couplage entre tests (état résiduel, ordre d'insertion, cache non vidé) peut s'installer sans qu'aucune porte ne le voie, et il ne se manifestera qu'au jour où l'ordre par défaut changera — c'est-à-dire à l'ajout ou au renommage d'un fichier de test, donc au pire moment. Le commentaire de `phpunit-ci.xml` annonce d'ailleurs l'intention inverse (« l'ordre aléatoire reste actif en local pour continuer à débusquer ces couplages ») : c'est une intention qui repose sur une exécution locale que H44-004 et l'état du poste rendent impraticable.
- Reproduction  : `diff <(sed 's/<!--.*//' backend/phpunit.xml) <(sed 's/<!--.*//' backend/phpunit-ci.xml)`.
- Correctif     : ajouter au job `backend` une **seconde** exécution, `php vendor/bin/pest --configuration phpunit-ci.xml --order-by=random --random-order-seed=${{ github.run_id }}`, d'abord **non bloquante** et avec la graine imprimée dans le journal pour pouvoir rejouer un rouge à l'identique. Une fois le couplage résorbé, la rendre bloquante. Coût : ~30 min d'édition, +40 s par exécution de CI. ⚠️ Ne pas remettre `executionOrder="random"` dans `phpunit-ci.xml` : ce serait re-créer la porte qui change d'avis — c'est bien une **seconde** exécution qu'il faut, pas la même autrement configurée.
- Statut        : ouvert
---

## 3. Ce qui a été VÉRIFIÉ ET RÉFUTÉ — à ne pas re-rapporter comme ouvert

| Affirmation | Verdict mesuré |
|---|---|
| « `phpunit-ci.xml` exclut des tests en silence » | **Faux.** Aucune balise `<exclude>`, `<groups>`, `<group>` dans l'un ou l'autre fichier ; les deux `testsuites` sont identiques ; les 95 fichiers `*Test.php` sont collectés par les deux. La seule différence fonctionnelle est `executionOrder`. La quarantaine de `tests/QUARANTAINE.md` est bien levée. |
| « `navigation.spec.ts` est rouge en silence » | **Faux depuis `da97826` (2026-08-18).** Étape « Run navigation E2E (BLOQUANT) » = `success`, 14 tests passés en 12,6 s sur `e8924b8`. ⚠️ Mais elle ne bloque rien → **H44-002**. |
| « Piège 9 : `127.0.0.1` avec un serveur de dev » | **Faux ici.** Le `127.0.0.1:4173` ne sert que `vite preview` (le build), avec `--host 127.0.0.1` en face. Le serveur de dev utilise bien `https://app.localhost`. |
| « Piège 7 : une porte qui meurt à l'installation » | **Aucune des 4 suites.** 0 `continue-on-error` et 0 `\|\| true` sur une étape d'installation ou de test de `ci.yml`. Le seul `continue-on-error` d'`a11y.yml` porte sur le job `lighthouse`, qui n'exécute aucun test du dépôt. Réserve unique : le repli `\|\| pnpm install` d'`a11y.yml` → **H44-008**. |
| « La base `axion_crm_test` est à recréer (piège `pg_partman`) » | **Non.** Locale `C`/`C` (conforme prod), 58 migrations sur 58, `RefreshDatabase` s'exécute. Aucune recréation faite ni nécessaire. |
| « Les tests Vitest frontend ne sont que sur la branche » | Déjà réfuté au §6 du dossier. Re-constaté : 21 fichiers Vitest + 16 specs Playwright sur `main e8924b8`. |
| « 1 écran de route couvert sur ~37 » (prompt d'audit) | **Faux : 6** montés par Vitest, **10** touchés par un test qui s'exécute → **H44-006**. |
| « Le harnais sort sur le réseau pendant les tests » (hypothèse née des journaux `HibpChecker: connect timeout` / `503`) | **Faux.** Ces journaux sont produits par un `GuzzleHttp\Handler\MockHandler` injecté dans `HibpCheckerTest.php:52-72`. Témoin : depuis le même conteneur, `curl https://api.pwnedpasswords.com/range/5BAA6` répond **200 en 0,33 s** — l'egress existe, la suite ne l'emprunte pas. |

---

## 4. Ce que je n'ai PAS pu vérifier, et pourquoi

> Cette section est un livrable. Elle contient notamment **une mesure que je retire moi-même** (§4.2) :
> je l'avais d'abord écrite comme un constat, la contamination l'a rendue non concluante, et il vaut
> mieux un audit qui se corrige qu'un audit qu'on ne peut pas croire.

1. **Le décompte local complet de la suite Pest (780 attendus), et la cause des 3 échecs locaux.**
   Ma première exécution est morte (`EXIT=137`) à ~230 tests sur 780 ; la seconde, bornée à la suite
   `Unit`, je l'ai **arrêtée moi-même** en découvrant qu'elle détruisait les mesures des autres agents
   (cf. H44-004). Le chiffre autoritatif de « ce qui s'exécute » reste donc celui de la CI —
   780 tests / 6 503 assertions / 39,31 s, mesurés sur le SHA exact de `main`, et l'archive du
   journal le porte ligne à ligne. **Ce qui manque : les messages d'assertion des 3 fichiers rouges**
   (`AudienceBuilderServiceTest`, `AuthServiceTest`, `AutoClassifierServiceTest`), que Pest n'imprime
   qu'en fin d'exécution. **H44-004 ne prétend pas qu'ils sont des défauts du produit** — il constate
   précisément qu'on ne peut pas le savoir, et pourquoi.

2. **🔻 MESURE RETIRÉE — la lenteur du harnais local n'est PAS attribuable en l'état.**
   J'avais mesuré, dans le conteneur `axion-crm-api` : `php -r "echo 1;"` = **0,22 s** contre
   `php artisan --version` = **3 min 12,92 s** (dont 2,00 s de CPU utilisateur), et j'en avais tiré un
   constat sur le montage lié Windows `C:\…\backend → /var/www/html`. **Je le retire.** Le `ps aux`
   joué ensuite montre qu'au même instant une quinzaine de processus PHP d'autres agents tournaient
   dans ce conteneur, dont 4 exécutions Pest et 2 `artisan migrate`. La mesure est réelle, son
   attribution ne l'est pas : je ne peux pas distinguer le coût du montage lié de celui de la
   contention. Ce qui subsiste, et qui est solide : **la CI exécute 780 tests en 39,31 s**, soit
   0,050 s par test — c'est la seule vitesse dont je puisse répondre. Un benchmark apparié
   (lecture des mêmes 2 000 fichiers depuis le montage lié puis depuis le disque du conteneur) a été
   lancé pour trancher, mais n'avait pas rendu de résultat à l'heure de la remise ; il reste
   reproductible en une commande, sur un poste au repos. **Le dépôt porte par ailleurs sa propre
   mesure datée** dans l'en-tête de `infra/php/opcache-local.ini` (26,08 s d'horloge pour 1,28 s de
   CPU au 2026-08-17) — c'est un document, donc une hypothèse, pas une preuve au sens de la doctrine.

3. **La cause précise du `SIGKILL` (137).** `OOMKilled=false` et `Memory=0` excluent la limite cgroup
   du conteneur ; restent le tueur mémoire de la machine virtuelle WSL2 (7,86 Go partagés entre tous
   les agents) et la contention décrite ci-dessus. Non tranché : il aurait fallu instrumenter `dmesg`
   côté hôte WSL2, hors de ma portée ici.

4. **La couleur réelle des 14 specs Playwright non exécutées.** Les jouer suppose une pile servie
   (`pnpm build` + `vite preview`, ou `https://app.localhost` avec l'API), soit ~20 min de mise en
   place plus l'exécution, sur un conteneur que je partageais avec une dizaine d'agents.
   **H44-001 constate qu'elles ne tournent nulle part — il n'affirme pas qu'elles sont rouges.**
   Personne ne le sait, et c'est exactement le problème.

5. **La couverture de code chiffrée.** `pnpm test:coverage` n'a pas été joué : la suite Vitest coûte
   déjà 214 s et l'instrumentation v8 l'aurait allongée sans changer H44-007, dont le constat porte
   sur le fait qu'**aucun workflow** ne la mesure.

6. **L'historique long des exécutions `Accessibility`.** J'ai relu les 15 derniers runs (tous
   `success`) ; je ne suis pas remonté au-delà, donc je ne peux pas dire combien de temps
   `navigation.spec.ts` est resté hors exécution avant `da97826`.

7. **`security.yml`.** Ses `continue-on-error` (Semgrep) et `|| true` (`composer audit`,
   `pnpm audit`) sont hors de mon périmètre « harnais de tests » ; je les signale à l'agent sécurité
   sans les instruire.

8. **`load-tests/` et `poc/`.** `load-tests/audience-refresh.yml` (Artillery) et les 5 dossiers de
   `poc/` ne contiennent aucun fichier de test au sens des 4 suites, et aucun workflow ne les
   invoque. Recensés par le `find` exhaustif du dépôt, non instruits.

---

## 5. Réponses directes aux six questions posées

1. **Combien existent, combien s'exécutent.** Pest : **95 fichiers / 780 tests**, **780 s'exécutent** (6 503 assertions, 39,31 s, CI bloquante et requise). Vitest frontend : **21 fichiers / 118 tests**, **118 s'exécutent** (213,96 s). Vitest workers : **6 fichiers / 61 tests**, **61 s'exécutent** (7,32 s). Playwright : **16 fichiers / 285 tests**, **18 s'exécutent** (2 fichiers, chromium seul).
2. **Ce qui n'est lancé nulle part.** Les 14 specs Playwright autres qu'`a11y.spec.ts` et `navigation.spec.ts` — `audiences-builder`, `auth`, `campaigns-wizard`, `companies`, `console-locale` (35 tests), `coverage`, `dark-mode`, `dashboard`, `global-search`, `llm`, `onboarding`, `rgpd`, `settings`, `tags-manager` — et, pour **toutes** les specs, les projets `firefox` et `mobile-safari`. Soit **267 tests sur 285**. Côté backend, frontend et workers : **rien** n'échappe aux workflows.
3. **Ce qui est exclu en silence.** **Rien dans les configurations** : 0 `<exclude>`, 0 `<groups>`, 0 `@group`, 0 `->skip()`, 0 `->todo()`, 0 `it.skip`, 0 `describe.skip`, 0 `test.todo`, 0 `.only`, 0 `testIgnore`, 0 `exclude` Vitest masquant un test existant — et un seul `markTestSkipped`, conditionnel à l'absence de `pg_dump`, jamais déclenché en CI (témoins négatifs joués sur les deux familles de motifs). **Les exclusions réelles sont toutes hors configuration** : la ligne de commande d'`a11y.yml` qui n'appelle que 2 specs sur 16 (H44-001) ; l'absence de ce job dans les vérifications requises (H44-002) ; l'absence de toute porte sur la préproduction (H44-003) ; la couverture qu'aucun workflow ne mesure (H44-007) ; l'ordre aléatoire qui n'est plus joué nulle part de fiable (H44-011). **C'est le schéma à retenir : ce dépôt n'exclut plus rien dans ses fichiers de configuration — il exclut dans ses workflows et dans sa protection de branche, où personne ne va lire.**
4. **Piège 7.** Aucune installation n'est en `continue-on-error` dans `ci.yml`. Un vert n'y est pas un silence. Seule réserve : le repli `|| pnpm install` d'`a11y.yml` (H44-008).
5. **La base de test.** `axion_crm_test` : locale **`C`/`C`** (conforme production), **58 migrations sur 58**, `pg_partman` bien dans son schéma `partman`. **Conforme — aucune recréation nécessaire ni faite.** La base de développement `axion_crm` est intacte (116 tables) après toutes mes exécutions : la garde de `Tests\TestCase::setUp()` fonctionne. En revanche, cette base unique est partagée par **toutes** les exécutions locales simultanées, qui s'y détruisent mutuellement — c'est **H44-004**, le défaut le plus coûteux que j'aie trouvé, parce qu'il rend les autres mesures indéchiffrables.
6. **Couverture réelle des écrans.** **6 sur 37** montés par Vitest ; **10 sur 37** touchés par un test qui s'exécute en CI ; **27 sur 37** touchés par rien. Le « 1 sur 37 » du prompt est faux.
