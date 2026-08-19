# AGENT 47 — Dépendances et vulnérabilités

> **Référence mesurée** : `main = 1145473` (`docs(rgpd): registre des violations, notification non retenue (#188)`).
> ⚠️ Le `_DOSSIER-AGENT.md` annonce `main = c0c453d`. **C'est faux au moment de cet audit** :
> `c0c453d` est 4 commits en arrière (`git log --oneline -5`). Doctrine §1 : aucun SHA de document n'est
> utilisé, `git log` a été relu. Tous les constats ci-dessous sont posés sur **`1145473`**.
>
> Preuves brutes : `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/agent-47/`
> Date de mesure : 2026-08-19.
> **Aucune PR ouverte, aucune dépendance montée, aucun fichier produit modifié.** Le worktree
> `crmpro-wt-etape1a` n'a été ni lu ni touché.

---

## 0. Rectification du périmètre annoncé

| Affirmation du prompt d'audit | Mesure | Verdict |
|---|---|---|
| « auditer les 20 PR Dependabot **ouvertes** » | `gh pr list --state open` → **0** | ❌ périmé |
| « **20 branches** `dependabot/*` restées sur `origin` » (dossier §6) | `git ls-remote --heads origin` → **38 branches, 0 dependabot** ; `GET /repos/.../branches` → **0 dependabot** | ❌ **périmé aussi** — les branches ont été supprimées depuis la rédaction du dossier |
| « GitHub annonce **57 alertes** (4 crit / 18 hautes / 31 moyennes / 4 basses) » | `GET /dependabot/alerts --paginate` → **57 ouvertes : 4 critical, 18 high, 31 medium, 4 low** | ✅ **exact, au chiffre près** |
| « le SDK Stripe type `apiVersion` comme littéral unique » | `grep -i stripe` sur tout le dépôt → 6 fichiers, **aucun n'est du code** (un nom de variable d'env `STRIPE_SECRET`, un domaine de test `stripe.com`, 3 prompts d'audit, `apiVersion: 1` de Grafana) | ❌ **ne s'applique pas à ce dépôt** — ce contrat est celui du dépôt `Axion-IA`, pas du CRM |

---

## 1. Grille — une ligne par objet du périmètre

| Objet | Ce qui a été joué | Résultat mesuré | Verdict |
|---|---|---|---|
| **Alertes Dependabot (compte réel)** | `gh api repos/will383842/axion-crm-pro/dependabot/alerts --paginate` | **67 au total** : 57 `open` + 10 `auto_dismissed`. Ouvertes : **4 critical, 18 high, 31 medium, 4 low** | ✅ le chiffre 57 du document est **confirmé** |
| **Écosystème des alertes** | idem, ventilation | **57/57 sont npm. 0 composer. 0 docker. 0 github-actions.** | mesuré |
| **Date de création des alertes** | idem, champ `created_at` | **57/57 créées le 2026-08-19** — les alertes ont été activées la veille de cet audit | mesuré |
| **Ventilation par manifeste** | idem | `workers/pnpm-lock.yaml` **31** + `workers/package.json` **1** = **32** ; `frontend/pnpm-lock.yaml` **23** + `frontend/package.json` **1** = **24** ; `poc/05_dedup_performance/pnpm-lock.yaml` **1**. Total **57** ✓ | mesuré |
| **Ventilation par portée (`scope`)** | idem | frontend : **11 runtime** / **13 development** ; workers : 32 (tous non déployés, cf. H47-005) ; poc : 1 development | mesuré |
| **Sort des 20 PR Dependabot** | `gh pr list --state all --search "author:app/dependabot"` + timeline de #164 | #145→#164 **fermées sans fusion**, toutes entre `18:44:47Z` et `18:44:49Z` le 2026-08-18, **par `dependabot[bot]` lui-même** | ✅ **fermées volontairement**, pas abandonnées — voir §2 |
| **Branches `dependabot/*` sur origin** | `git ls-remote --heads origin`, `GET /branches?per_page=100 --paginate` | **0 sur 38 branches** | le dossier §6 est périmé |
| **`backend/composer.lock` — audit natif** | `docker exec axion-crm-api composer audit --locked --format=plain` | **`No security vulnerability advisories found.`** — 143 paquets prod + 43 dev | ✅ **0 vulnérabilité PHP** |
| **Témoin négatif de `composer audit`** | même conteneur, `composer.json` jetable avec `guzzlehttp/guzzle:6.5.0` | **`Found 18 security vulnerability advisories affecting 2 packages`** | ✅ l'outil sait trouver ; le zéro ci-dessus est un vrai zéro |
| **`frontend/pnpm-lock.yaml` — audit natif** | `pnpm audit` (local) | **31 vulnérabilités — 12 moderate, 18 high, 1 critical**, sortie 1 | mesuré |
| **`workers/pnpm-lock.yaml` — audit natif** | `pnpm audit` (local) | **33 vulnérabilités — 3 low, 19 moderate, 10 high, 1 critical**, sortie 1 | mesuré |
| **`poc/05_dedup_performance/pnpm-lock.yaml`** | `pnpm audit` (local) | **1 vulnérabilité — 1 low**, sortie 1 | mesuré, **hors de toute garde** (H47-004) |
| **Écart pnpm audit ↔ Dependabot** | comparaison des deux sorties | pnpm : 31+33+1 = **65** ; Dependabot : **57** ouvertes (+10 auto-écartées = 67). Les deux outils ne comptent pas la même chose (pnpm dédoublonne par chemin, Dependabot par manifeste ; l'auto-écartement dev de GitHub retire 10) | écart **expliqué**, pas de constat |
| **🪤 Piège 5 — clés dupliquées des lockfiles** | `js-yaml.load(..., {json:false})` (rejette les clés dupliquées) sur les 3 `pnpm-lock.yaml` | **`OK (aucune clé dupliquée)` × 3** | ✅ **réfuté** |
| **Témoin négatif du détecteur de doublons** | copie de `frontend/pnpm-lock.yaml` + une clé racine réinjectée | **`duplicated mapping key (201:1)`** | ✅ le détecteur voit |
| **`backend/composer.lock` — doublons** | parse JSON + comparaison des `name` | `packages` : 143, doublons **aucun** ; `packages-dev` : 43, doublons **aucun** | ✅ |
| **Intégrité `composer.lock` ↔ `composer.json`** | `composer validate --no-check-publish` dans le conteneur | **`./composer.json is valid`** | ✅ |
| **`security.yml` — job `composer-audit`** | lecture + journal du run **32239147620** (`main`, 2026-08-19 09:44) | `composer audit --no-dev \|\| true` → **`No installed packages found. Please run "composer install"...`** puis **`success`** | 🔴 **H47-001** — n'a **jamais** audité un seul paquet |
| **`security.yml` — job `pnpm-audit`** | même run | affiche **31** (frontend) et **33** (workers) vulnérabilités, puis **`success`** grâce à `\|\| true` | 🔴 **H47-002** — décoratif |
| **`security.yml` — job `alerte`** | lecture (`needs:` + `if: failure()`) | dépend de `composer-audit` et `pnpm-audit`, qui **ne peuvent pas échouer** (`\|\| true`) → l'alerte ne peut **jamais** se déclencher pour une vulnérabilité de dépendance | 🔴 inclus dans **H47-002** |
| **`security.yml` — job `trivy`** | lecture | `exit-code: '1'`, **pas** de `continue-on-error`, `ignore-unfixed: true`, `.trivyignore.yaml` daté | ✅ **réellement bloquant** — seule garde vivante du fichier |
| **`security.yml` — a-t-il tourné ?** | `gh run list --workflow=security.yml --limit 20` | oui, en continu ; dernier run `main` **32239147620 = success** ; les 12 jobs listés, `zap-baseline` et `alerte` **skipped** | mesuré |
| **`.trivyignore.yaml`** | lecture | exceptions **datées** (`expired_at: 2026-10-01`) sur le binaire Caddy, motivées | ✅ conforme |
| **Contrats écrits figeant des versions** | lecture de `.github/dependabot.yml`, `_REPORTS/2026-08-18_POLITIQUE-DEPENDANCES-ETAPE-0.md`, `ci.yml`, `Dockerfile.worker`, `frontend/package.json` | **6 contrats** — voir §3 | mesuré |
| **Canal de sécurité Dependabot** | `GET /vulnerability-alerts` → **204** ; `security_and_analysis.dependabot_security_updates` → **`enabled`** ; `GET /automated-security-fixes` → **`{"enabled":true,"paused":false}`** | **actif** — mais **0 PR de sécurité** ~24 h après la création des 57 alertes | ⚠️ **H47-003** (prémisse écrite périmée) + point à re-mesurer lundi |
| **Plafonds `open-pull-requests-limit`** | lecture + `gh pr list --state open` → 0 | 8 / 8 / 5 déclarés, **0 PR ouverte** → plafonds **non saturés** | ✅ le mode de panne du 2026-08-16 n'est **pas** rejoué |
| **Exposition réelle — frontend** | `vite build` rejoué (81 s, sortie 0) + comptage de marqueurs sur les 5 fichiers JS produits (2 009 422 o) | `follow-redirects` 0, `proxy-from-env` 0, `combined-stream` 0, `getBoundary` 0, `NO_PROXY` 0, `node:http` 0, `httpAdapter` 0, `formDataToJSON` **0** ; témoins : `XMLHttpRequest` **16**, `maxBodyLength` **4**, `axios` présent | **l'adaptateur Node et `form-data` ne sont pas dans le paquet livré** |
| **Exposition réelle — workers** | `grep -nE "^  [a-z0-9-]+:"` sur les 5 `docker-compose*.yml` | service `worker*` présent **uniquement** dans `docker-compose.test.yml`. **Absent** de `docker-compose.yml`, `.prod.yml`, `.staging.yml`, `.local.yml` | 🔴 **les workers Node ne tournent nulle part** — H47-005 |
| **Exposition réelle — chaîne de prospection** | lecture des 8 workflows `prospection-*.yml` | **8/8 exécutent `php artisan prospection:*` via SSH dans le conteneur `api`. 0/8 exécutent du Node** | confirme H47-005 |
| **Chemin d'appel axios (frontend)** | lecture de `frontend/src/lib/api.ts` + `grep FormData\|auth:\|axios.create` sur `frontend/src` | **1 seul `axios.create`**, configuration **statique** ; **aucune** option `auth:` ; le seul `new FormData` (`SettingsPage.tsx:144`) est un `FormData` **natif du DOM** converti en objet simple — **jamais passé à axios** | aucun chemin d'appel vers les CVE `toFormData`/`auth` |
| **Amorce de pollution de prototype (frontend)** | `grep __proto__\|constructor[\|Object.assign({}\|deepMerge\|merge(` sur `frontend/src` | **0 occurrence** (témoin : `grep axios` sur le même dossier → **6 occurrences / 3 fichiers**, le contrôle voit) | aucune amorce identifiée — **recherche non exhaustive**, cf. §5 |
| **Classement par exposition réelle** | croisement des trois mesures ci-dessus avec les 57 alertes | **Classe A (chemin d'appel démontré en production) : 0.** Classe B (chargé, conditionnel) : **5**. Classe C (absent de la production) : **52** | voir §4 |

---

## 2. Ce qu'il est advenu des 20 PR Dependabot

**Elles ont été fermées volontairement, en application d'une politique écrite, datée et motivée — et c'est `dependabot[bot]` lui-même qui les a fermées.**

La chaîne est complète et vérifiée :

1. Commit `fccc9d1` — `chore(etape0-l9): geler Dependabot et écrire la politique de dépendances (F13)` réécrit `.github/dependabot.yml` : `ignore: [{dependency-name: '*', update-types: [semver-major, semver-minor, semver-patch]}]` sur **les 5 écosystèmes**.
2. Le 2026-08-18 entre **18:44:47Z et 18:44:49Z**, Dependabot relit sa configuration et **ferme ses 20 PR** (#145 → #164), `mergedAt: null`.
   `GET /issues/164/timeline` → `PR#164 closed_by=dependabot[bot] at=2026-08-18T18:44:48Z`.
3. Les branches ont été supprimées dans la foulée (0 branche `dependabot/*` sur `origin`).
4. La politique est écrite dans `_REPORTS/2026-08-18_POLITIQUE-DEPENDANCES-ETAPE-0.md` (441 lignes) : décision, motifs, **inventaire PR par PR avec le sort de chacune** (« gelée »), procédure de dégel ordonnée, et l'incident de production du dépôt `axionia` qui la motive (deux montées fusionnées séparément → clé dupliquée dans le lockfile → `ERR_PNPM_BROKEN_LOCKFILE` → plus aucun déploiement).

**Ce n'est donc PAS un constat de négligence.** L'hypothèse du prompt (« fermées sans être traitées, les alertes sont restées ») est **réfutée** : les PR ont été fermées *par décision*, la décision est écrite, chiffrée, datée et signée dans le dépôt, et elle réserve explicitement le canal de sécurité (`ignore` par `update-types`, jamais par `versions:`).

**Deux nuances, mesurées, qui restent à la charge du dépôt :**

- **16 des 20 PR étaient des MAJEURES** (80 %), dont 6 franchissant plus d'une majeure d'un coup. Aucune n'était corrective de sécurité. Le gel n'a donc renoncé à **aucun** correctif de faille.
- **Une seule des 20 aurait éteint des alertes** : **#148 `axios 1.16.1 → 1.19.0` (mineure, `/workers`)**, qui couvrait les 10 alertes axios des workers. Elle a été gelée avec les autres — sans conséquence, puisque les workers ne sont déployés nulle part (H47-005).

---

## 3. Versions figées par contrat écrit dans le dépôt

| # | Contrat | Emplacement | Portée | Force |
|---|---|---|---|---|
| 1 | **Gel total des montées de version**, tous écosystèmes, jusqu'à la fin de l'étape 0 | `.github/dependabot.yml` (règles `ignore` × 5) + `_REPORTS/2026-08-18_POLITIQUE-DEPENDANCES-ETAPE-0.md` | composer /backend, npm /frontend, npm /workers, github-actions /, docker / | **Contraignant** — décision tranchée. Les mises à jour de **sécurité** en sont explicitement exclues. |
| 2 | **`postgis/postgis` — majeure interdite, RÈGLE PERMANENTE** | `.github/dependabot.yml`, bloc `docker` | image Postgres | **Permanent, « à NE PAS retirer au dégel »**. Motif écrit : `16-3.5 → 17-3.5` ne migre pas le répertoire de données, Postgres 17 refuse de démarrer dessus. PR #14 fermée pour cette raison. Toute majeure est un chantier `pg_upgrade`. |
| 3 | **`ARG PLAYWRIGHT_VERSION=v1.62.1-noble` doit égaler la version de `playwright` résolue dans `workers/node_modules`** | `Dockerfile.worker:16` ; garde dans `.github/workflows/ci.yml:490-509` | image worker | **Bloquant et vu rougir** : la garde `exit 1` sur écart. Historique écrit : 13 révisions de dérive silencieuse. Toute montée de `playwright` doit changer les deux **dans le même commit**. |
| 4 | **`pnpm.overrides.vite: ^6.0.0`** | `frontend/package.json` | tout l'arbre frontend | Épingle `vite` sur la v6 pour **toutes** les dépendances transitives. Une montée en v7 casse l'override. |
| 5 | **`packageManager: pnpm@9.12.0`**, `engines.node >= 22.0.0`, `engines.pnpm >= 9.0.0` | `frontend/package.json`, `workers/package.json` | outillage | Contraignant (Corepack). |
| 6 | **Exceptions Trivy DATÉES** (`expired_at: 2026-10-01`) sur le binaire Caddy | `.trivyignore.yaml` | garde `trivy` | Auto-expirantes **par construction** : passée la date, la garde redevient rouge. Modèle sain, à ne pas convertir en exception permanente. |
| 7 | **`composer.json` — intervalles `^` qui interdisent 4 des majeures gelées** | `backend/composer.json` | `spatie/laravel-permission ^6.10`, `spatie/laravel-query-builder ^6.2`, `pragmarx/google2fa-laravel ^2.2`, `laravel/tinker ^2.10` | Les PR #152, #147, #154, #149 proposaient des majeures **hors intervalle déclaré** : leur fermeture était de toute façon obligatoire sans modification du manifeste. |

**Aucun contrat de type « Stripe `apiVersion` » dans ce dépôt** — il n'y a pas de SDK Stripe. Voir §0.

---

## 4. Exposition réelle — le classement qui compte

> Consigne : « Ne classe pas par sévérité affichée, classe par exposition réelle. » Trois mesures indépendantes fondent ce classement : le paquet livré au navigateur (reconstruit), les services déclarés dans les 5 fichiers compose, et la chaîne de prospection réelle (8 workflows).

### 4.1 Vue d'ensemble

| Classe d'exposition | Alertes | dont crit. | dont hautes | Fondement mesuré |
|---|---|---|---|---|
| **A — chemin d'appel démontré en production** | **0** | 0 | 0 | — |
| **B — chargé en production, chemin conditionnel non démontré** | **5** | 0 | 0 | 5 CVE axios présentes dans le paquet navigateur, sans chemin d'appel dans le code applicatif |
| **C1 — `workers/`, déployé nulle part** | **32** | 2 | 8 | aucun service `worker` hors `docker-compose.test.yml` ; 8/8 workflows de prospection passent par `artisan` |
| **C2 — dépendance de développement pure (frontend)** | **13** | 2 | 8 | `scope: development`, absente du paquet livré |
| **C3 — `poc/`, hors produit** | **1** | 0 | 0 | répertoire de preuve de concept |
| **C4 — dans `frontend` runtime mais absent du paquet construit** | **6** | 0 | 2 | mesuré sur les 5 fichiers JS reconstruits |
| **TOTAL** | **57** | **4** | **18** | |

*(C1+C2+C3+C4 = 52. Les 6 de C4 sont pris sur les 11 alertes « runtime frontend » ; les 5 restantes sont la classe B.)*

### 4.2 Les 4 critiques — toutes éteintes par la mesure

Les 4 alertes `critical` sont **la même CVE**, `CVE-2026-47429` (`vitest < 3.2.6`), déclarée 4 fois (`frontend/package.json`, `frontend/pnpm-lock.yaml`, `workers/package.json`, `workers/pnpm-lock.yaml`).

- `scope: development` — vitest n'est dans aucun artefact de production.
- La CVE **exige que le serveur d'interface de Vitest écoute** (« When Vitest UI server is listening »).
- **`@vitest/ui` n'est pas installé** : `ls frontend/node_modules/@vitest/` → `coverage-v8, expect, mocker, pretty-format, runner, snapshot, spy, utils` — **pas de `ui`** ; `workers/node_modules/@vitest/` n'existe pas. Dans les deux lockfiles, `@vitest/ui` n'apparaît que comme **pair optionnelle non installée** (`optional: true`).
- L'option `--ui` n'apparaît dans **aucun** `package.json`, workflow ou source.

**Les 4 critiques ne sont atteignables ni en production, ni en développement, en l'état.** Elles restent à corriger au dégel (montée majeure vitest 2 → 3, chantier propre), sans urgence.

### 4.3 Les 11 alertes du seul périmètre réellement livré (`frontend`, runtime)

Mesure de référence : `vite build` rejoué le 2026-08-19 (sortie 0, 81 s) ; comptage sur `dist/assets/*.js` (5 fichiers, 2 009 422 octets) — `04_PREUVES/agent-47/bundle-marqueurs.txt`.

| # | Sév. affichée | Paquet | Installé | Corrigé | CVE/GHSA | Le code de production l'atteint-il ? | Décision |
|---|---|---|---|---|---|---|---|
| 21 | **high** | axios | 1.16.1 | 1.18.0 | GHSA-gcfj-64vw-6mp9 | **NON.** Adaptateur HTTP **Node**. Marqueurs `follow-redirects`, `proxy-from-env`, `node:http`, `httpAdapter` = **0** dans le paquet livré | **Geler** — la garde du dégel suffit |
| 6 | **high** | form-data | 4.0.5 | 4.0.6 | CVE-2026-12143 | **NON.** Paquet **absent** du paquet livré : `getBoundary` = 0, `combined-stream` = 0. Les 3 occurrences de « form-data » sont la chaîne MIME `multipart/form-data` | **Geler** |
| 15 | medium | axios | 1.16.1 | 1.18.0 | GHSA-f4gw-2p7v-4548 | **NON.** `NO_PROXY` = 0 — notion Node | **Geler** |
| 18 | medium | axios | 1.16.1 | 1.18.0 | GHSA-mwf2-3pr3-8698 | **NON.** HTTP/2 — Node uniquement | **Geler** |
| 10 | medium | axios | 1.16.1 | 1.18.0 | GHSA-42h9-826w-cgv3 | **NON.** `formDataToJSON` = **0** dans le paquet livré | **Geler** |
| 8 | medium | axios | 1.16.1 | 1.18.0 | GHSA-pmv8-rq9r-6j72 | **NON.** idem, `formDataToJSON` = 0 | **Geler** |
| 20 | medium | axios | 1.16.1 | 1.18.0 | GHSA-hcpx-6fm6-wx23 | **CONDITIONNEL.** Le chemin `toFormData` existe dans le paquet, mais l'application **ne passe jamais de `FormData` à axios** : l'unique `new FormData` (`SettingsPage.tsx:144`) est natif DOM, lu puis converti en objet simple | **Geler**, corriger au dégel |
| 17 | medium | axios | 1.16.1 | 1.18.0 | GHSA-jqh4-m9w3-8hp9 | **CONDITIONNEL.** Contournement de `maxBodyLength`, non appliqué côté navigateur (`maxBodyLength: -1`) ; l'app utilise l'adaptateur XHR (`XMLHttpRequest` = 16) | **Geler**, corriger au dégel |
| 19 | medium | axios | 1.16.1 | 1.18.0 | GHSA-7q8q-rj6j-mhjq | **CONDITIONNEL.** *Gadget* : exige une amorce de pollution de prototype ailleurs dans l'app. Aucune trouvée (§1) | **Geler**, corriger au dégel |
| 16 | medium | axios | 1.16.1 | 1.18.0 | GHSA-mmx7-hfxf-jppx | **CONDITIONNEL.** idem gadget | **Geler**, corriger au dégel |
| 9 | medium | axios | 1.16.1 | 1.18.0 | CVE-2026-67314 | **CONDITIONNEL.** Injection Basic auth via sous-champs `auth` pollués. L'app **ne définit aucune option `auth:`** ; exige aussi une amorce | **Geler**, corriger au dégel |

**Toutes ces 11 alertes se corrigent par UNE seule montée : `axios 1.16.1 → 1.18.0` (mineure, dans l'intervalle `^1.7.0` déclaré) + `form-data 4.0.5 → 4.0.6` (transitive, entraînée).**

### 4.4 Recommandations de montée — 🪤 Piège 6 : **une par une**, jamais en lot

Ordre **imposé** par le risque croissant. ⛔ Ne jamais enchaîner deux montées sans avoir vu `main` vert entre les deux, et **vérifier l'unicité des clés du lockfile fusionné avant chaque fusion** (le commit de fusion, pas la branche — 🪤 Piège 5).

| Ordre | Montée | Périmètre | Éteint | Risque propre | Coût |
|---|---|---|---|---|---|
| **1** | `axios 1.16.1 → 1.18.0` | `/frontend` | **10** alertes (1 haute, 9 moyennes) | **Faible** : mineure dans l'intervalle `^1.7.0`, 3 fichiers appelants, une seule instance `axios.create` à configuration statique. Entraîne `form-data → 4.0.6` (11ᵉ alerte) | ~30 min + rejeu des tests frontend |
| **2** | `axios 1.16.1 → 1.18.0` | `/workers` | **10** alertes | Faible techniquement, **valeur nulle tant que H47-005 tient** (les workers ne tournent nulle part) | ~20 min |
| **3** | `undici` (transitif de `cheerio 1.2.0`) `7.25.0 → 7.29.0` | `/workers` | **10** alertes | Transitif : impose de monter `cheerio` ou de poser un `override`. Un `override` sur un transitif est une dette — le documenter | ~1 h, **conditionné à H47-005** |
| **4** | `vite 6.4.2 → 6.4.3` | `/frontend` (+ override) | 2 alertes dev | Correctif de patch, mais l'`override` `^6.0.0` doit rester cohérent | ~15 min |
| **5** | `postcss → 8.5.23`, `js-yaml → 4.3.1`, `ws → 8.21.0`, `brace-expansion`, `esbuild` | `/frontend`, `/workers` | 13 alertes dev | Transitifs de l'outillage — se règlent surtout par la montée de leurs parents | à traiter au dégel, **une par une** |
| **6** | `vitest 2.1.9 → 3.2.6` | `/frontend` + `/workers` | **les 4 critiques** | **MAJEURE** — chantier à part entière, sa propre PR, son propre plan de test, son propre revert. Conflit direct avec le harnais de tests de l'étape 0 | à faire **après** l'étape 0, jamais pendant |

⚠️ **Aucune de ces montées ne doit être ouverte maintenant** : le gel de `.github/dependabot.yml` est un contrat écrit (§3, contrat 1), et **un rouge de CI ne justifie pas de violer un contrat écrit dans le dépôt**. La correction est un lot P3, à passer par la procédure de dégel documentée.

---

## 5. Constats

### [H47-001] Le job `composer-audit` de `security.yml` n'a jamais audité un seul paquet PHP, et rend `success`
- Sévérité      : S1 grave
- Domaine       : sécurité / tests
- Référence     : main 1145473
- Emplacement   : `.github/workflows/security.yml:170-178` (job `composer-audit`)
- Constat       : la commande `composer audit --no-dev || true` s'exécute sans `composer install` préalable et sort `No installed packages found. Please run "composer install" before running "audit" or pass "--locked" to audit the lock file.` — puis le job est vert.
- Preuve        : `gh run view 32239147620 --log` (run `main` du 2026-08-19 09:44) → `04_PREUVES/agent-47/ci-audit-jobs-log.txt`, ligne `PHP deps audit  Run composer audit --no-dev || true  No installed packages found.` ; `gh api .../actions/runs/32239147620/jobs` → `success  PHP deps audit`.
- Témoin négatif: la même commande, corrigée en `--locked`, joue et rend un résultat exploitable — `docker exec axion-crm-api composer audit --locked` → `No security vulnerability advisories found.` (`04_PREUVES/agent-47/composer-audit-locked.txt`). Et sur un `composer.json` jetable avec `guzzlehttp/guzzle:6.5.0`, la même commande dans le même conteneur rend `Found 18 security vulnerability advisories affecting 2 packages` (`04_PREUVES/agent-47/temoin-negatif-composer-audit.txt`). L'outil sait donc trouver ; c'est l'invocation en CI qui ne mesure rien.
- Impact        : depuis la création du job, **zéro paquet PHP n'a été examiné en CI**. Les 143 paquets de production et 43 de développement du backend Laravel — le seul composant réellement déployé qui traite les requêtes — n'ont aucune surveillance de vulnérabilité côté CI. Le vert du job est un silence, pas une absence de problème (🪤 Piège 7). Le fait que `composer.lock` soit propre aujourd'hui est une chance, pas un résultat de la garde.
- Reproduction  : `gh run view 32239147620 --log | grep "PHP deps audit"` ; ou relancer `security.yml` et lire le job `PHP deps audit`.
- Correctif     : remplacer `composer audit --no-dev || true` par `composer audit --locked --no-dev` (sans `|| true`). `--locked` évite d'avoir à installer les dépendances. Retirer `|| true` rend la garde bloquante — elle est **verte aujourd'hui** (0 avis), donc l'armer ne bloque rien au moment où on l'arme, exactement comme cela a été fait pour Trivy le 2026-08-16. Coût : 1 ligne, ~10 min.
- Statut        : ouvert

### [H47-002] Les deux jobs `pnpm-audit` affichent 64 vulnérabilités et rendent `success`, et le job `alerte` ne peut jamais se déclencher sur eux
- Sévérité      : S2 défaut
- Domaine       : sécurité / tests
- Référence     : main 1145473
- Emplacement   : `.github/workflows/security.yml:180-193` (job `pnpm-audit`) et `.github/workflows/security.yml:245-249` (job `alerte`, clauses `needs:` / `if: failure()`)
- Constat       : `pnpm audit --audit-level=high || true` imprime `31 vulnerabilities found / Severity: 12 moderate | 18 high | 1 critical` (frontend) et `33 vulnerabilities found / Severity: 3 low | 19 moderate | 10 high | 1 critical` (workers), et les deux jobs se terminent en `success`.
- Preuve        : `04_PREUVES/agent-47/ci-audit-jobs-log.txt` (extraits du run 32239147620) ; conclusions des jobs : `success  Node deps audit (frontend)`, `success  Node deps audit (workers)`. Reproduit hors CI : `pnpm audit` rend **sortie 1** dans `frontend/` et dans `workers/` (`04_PREUVES/agent-47/pnpm-audit-frontend.txt`, `pnpm-audit-workers.txt`).
- Témoin négatif: la sortie 1 mesurée localement prouve que `pnpm audit` **sait** rougir sur ce dépôt, dans cet état exact du lockfile. C'est bien le `|| true` qui l'annule, pas une absence de détection.
- Impact        : double. (a) Aucune PR n'a jamais pu rougir sur une vulnérabilité npm, y compris `critical`. (b) Plus grave, le job `alerte` — le « chaînon qui manquait », ajouté le 2026-08-16 précisément pour qu'un échec cesse d'être invisible — déclare `needs: [..., composer-audit, pnpm-audit]` et `if: failure()`. Comme ces trois jobs **ne peuvent pas échouer**, l'alerte est structurellement inatteignable par cette voie : le seul mécanisme de notification du dépôt est aveugle aux vulnérabilités de dépendances, qui sont pourtant la seule famille de risques que ces jobs surveillent. Le job `alerte` porte le commentaire « Un rouge que personne ne regarde ne vaut pas mieux qu'un vert menteur » — ici le vert menteur est en amont de l'alerte.
- Reproduction  : `cd frontend && pnpm audit ; echo $?` → `1`. Puis lire le job `Node deps audit (frontend)` du run 32239147620 → `success`.
- Correctif     : retirer `|| true` des deux jobs. ⚠️ **Cela rend la CI rouge immédiatement** (64 vulnérabilités présentes) — c'est donc à faire **après** la montée `axios` n°1 et n°2 du §4.4, ou en posant d'abord un seuil réaliste (`--audit-level=critical`) puis en le durcissant. Alternative sans blocage, cohérente avec l'esprit du fichier : garder le job non bloquant mais **remplacer `|| true` par une capture explicite du code de sortie** qui alimente le job `alerte`, afin qu'une vulnérabilité ouvre une issue au lieu de disparaître. Coût : ~1 h avec la remise à plat du seuil.
- Statut        : ouvert

### [H47-003] La prémisse écrite du gel Dependabot — « les alertes sont DÉSACTIVÉES » — est fausse depuis le 2026-08-19
- Sévérité      : S3 finition
- Domaine       : sécurité / conformité
- Référence     : main 1145473
- Emplacement   : `.github/dependabot.yml:62-72` (bloc « ⚠️ PRÉREQUIS NON REMPLI, mesuré le 2026-08-18 ») et `_REPORTS/2026-08-18_POLITIQUE-DEPENDANCES-ETAPE-0.md`
- Constat       : le fichier affirme « `GET /repos/will383842/axion-crm-pro/vulnerability-alerts` → 404 », « `dependabot_security_updates.status` → "disabled" », « il n'y a donc, à ce jour, AUCUN canal de mise à jour de sécurité à préserver » et « la précaution ci-dessus est correcte mais **INERTE** » ; les trois affirmations sont aujourd'hui fausses.
- Preuve        : `04_PREUVES/agent-47/etat-dependabot-depot.txt` — `GET /vulnerability-alerts` → **`HTTP/2.0 204 No Content`** (activé) ; `security_and_analysis.dependabot_security_updates` → **`{"status":"enabled"}`** ; `GET /automated-security-fixes` → **`{"enabled":true,"paused":false}`**. Corroboré par les 57 alertes, **toutes créées le 2026-08-19** (`created_at`, `04_PREUVES/agent-47/dependabot-alerts-all.json`).
- Témoin négatif: la même méthode d'interrogation restitue bien un état négatif quand il existe — `secret_scanning_non_provider_patterns` et `secret_scanning_validity_checks` remontent `"disabled"` dans la même réponse. L'API ne renvoie donc pas « enabled » par défaut.
- Impact        : la conséquence opérationnelle du gel a changé sans que le document le dise. Écrit le 2026-08-18, le gel n'avait aucun canal de sécurité à préserver ; depuis le 2026-08-19, il en a un, et c'est **la seule voie de correction restante** puisque toutes les montées de version sont gelées. Toute personne qui lit le fichier pour décider du dégel raisonnera sur une prémisse périmée — et le critère d'entrée du dégel qu'il énonce (« les alertes Dependabot sont ACTIVES sur le dépôt ») est en réalité **déjà rempli**.
- Reproduction  : `gh api repos/will383842/axion-crm-pro/automated-security-fixes` ; `gh api repos/will383842/axion-crm-pro --jq '.security_and_analysis'`.
- Correctif     : remplacer le bloc « PRÉREQUIS NON REMPLI » par l'état mesuré du 2026-08-19 et cocher le critère d'entrée correspondant dans la procédure de dégel. Coût : ~15 min, documentation seule.
- Statut        : ouvert

### [H47-004] `poc/05_dedup_performance/pnpm-lock.yaml` porte une alerte, hors de la configuration Dependabot et hors de la matrice `pnpm-audit`
- Sévérité      : S3 finition
- Domaine       : sécurité
- Référence     : main 1145473
- Emplacement   : `.github/dependabot.yml:130-177` (les 5 blocs `directory:`) et `.github/workflows/security.yml:185` (`matrix.path: [frontend, workers]`)
- Constat       : l'alerte **#33** (`esbuild`, low, GHSA-g7r4-m6w7-qqqr) porte sur `poc/05_dedup_performance/pnpm-lock.yaml` ; ce répertoire n'apparaît dans aucun `directory:` de `dependabot.yml` (qui ne déclare que `/backend`, `/frontend`, `/workers`, `/` × 2) ni dans la matrice du job `pnpm-audit` (qui ne parcourt que `frontend` et `workers`).
- Preuve        : `04_PREUVES/agent-47/alerts-table.txt`, ligne `33|low|npm|esbuild|poc/05_dedup_performance/pnpm-lock.yaml|development|transitive` ; `grep -n "directory:" .github/dependabot.yml` → 5 lignes, aucune `/poc/…` ; `grep -n "path: \[" .github/workflows/security.yml`.
- Témoin négatif: le lockfile est bien auditable et bien vulnérable — `cd poc/05_dedup_performance && pnpm audit` → **`1 vulnerabilities found / Severity: 1 low`**, sortie 1 (`04_PREUVES/agent-47/pnpm-audit-poc.txt`). L'absence de couverture n'est donc pas due à un lockfile vide ou illisible.
- Impact        : une alerte pour laquelle **aucune PR ne sera jamais proposée** (les *mises à jour* Dependabot suivent la configuration, alors que les *alertes* scannent tous les manifestes) et **aucune garde ne s'exécute**. La gravité intrinsèque est faible ici (dépendance de développement, dans un répertoire de preuve de concept), mais le mécanisme est général : tout nouveau lockfile ajouté hors des 3 répertoires déclarés hérite du même angle mort, silencieusement.
- Reproduction  : `gh api repos/will383842/axion-crm-pro/dependabot/alerts --paginate` puis filtrer `manifest_path` commençant par `poc/`.
- Correctif     : deux options exclusives. (a) Si `poc/` est du code vivant : ajouter un bloc `- package-ecosystem: npm / directory: /poc/05_dedup_performance` dans `dependabot.yml` (avec le même `ignore` de gel) et l'entrée correspondante dans la matrice `pnpm-audit`. (b) Si `poc/` est un vestige : le sortir du dépôt ou l'archiver. **Trancher explicitement** plutôt que de laisser la question ouverte — c'est le choix, pas la couverture, qui manque. Coût : ~20 min pour (a).
- Statut        : ouvert

### [H47-005] Le paquet `workers/` — 32 des 57 alertes, dont 2 critiques — n'est déployé par aucun fichier compose hors tests, mais son image est construite à chaque déploiement de préproduction
- Sévérité      : S2 défaut
- Domaine       : sécurité / backend
- Référence     : main 1145473
- Emplacement   : `docker-compose.prod.yml`, `docker-compose.staging.yml`, `docker-compose.yml`, `docker-compose.local.yml` ; `.github/workflows/deploy-staging.yml:25` ; `.github/workflows/security.yml:74`
- Constat       : aucun service dont le nom contient `worker` n'est déclaré dans `docker-compose.prod.yml` (services : `api, horizon, scheduler, app, reverb, postgres, redis, caddy, api-storage`), ni dans `docker-compose.staging.yml`, ni dans `docker-compose.yml`, ni dans `docker-compose.local.yml` — le seul fichier qui en déclare est `docker-compose.test.yml` (`worker-google-maps`, `worker-pages-jaunes`, `worker-google-search`). Or `deploy-staging.yml` construit l'image `worker` à chaque exécution et `security.yml` la scanne.
- Preuve        : `grep -nE "^  [a-z0-9-]+:" docker-compose*.yml` (sortie complète des 5 fichiers, §1 de cette grille). Corroboration indépendante : les **8** workflows `prospection-*.yml` exécutent tous `php artisan prospection:*` via SSH dans le conteneur `api` — `grep -c artisan` rend 2, 3, 7, 2, 1, 3, 7, 2 ; `grep -cE "pnpm|node dist|tsx "` rend **0 sur les 8**. La collecte, l'enrichissement et la reclassification sont donc faits par le backend PHP, pas par les workers Node.
- Témoin négatif: le contrôle sait distinguer les deux cas — le même `grep -nE "^  [a-z0-9-]+:"` **trouve** bien les trois services `worker-*` dans `docker-compose.test.yml`. Un service worker déclaré serait donc vu.
- Impact        : triple. (a) **Distorsion de l'audit** : 32 alertes sur 57 (56 %), dont **2 des 4 critiques** et **8 des 18 hautes**, proviennent d'un paquet qui ne s'exécute nulle part. Toute priorisation faite sur la sévérité affichée engagera plus de la moitié de l'effort sur du risque nul — c'est exactement le travers que cette grille doit empêcher. (b) **Coût de CI** : l'image `worker` (base `mcr.microsoft.com/playwright`, lourde) est construite à chaque déploiement de préproduction pour n'être jamais démarrée. (c) **Ambiguïté de conception** : soit les workers Node sont morts et doivent être retirés ou marqués comme tels, soit ils sont censés tourner et **leur absence de tous les compose de déploiement est un défaut de déploiement**. En l'état, un lecteur du dépôt ne peut pas trancher — et une garde de sécurité (Trivy) dépense du temps sur une image sans exécutant.
- Reproduction  : `grep -nE "^  [a-z0-9-]+:" docker-compose.prod.yml` → aucun `worker` ; `grep -l "worker" docker-compose*.yml` → `docker-compose.test.yml` seul.
- Correctif     : **question à trancher par Will, pas par un correctif technique.** Si les workers Node sont abandonnés au profit de `artisan prospection:*` : retirer `workers/` du périmètre de `dependabot.yml`, de la matrice `pnpm-audit`, du job Trivy et de `deploy-staging.yml` — ce qui **éteint 32 alertes d'un coup, sans monter une seule dépendance**, et allège chaque déploiement de préproduction. S'ils doivent tourner : ajouter le service manquant à `docker-compose.prod.yml` — auquel cas les 32 alertes redeviennent réelles, dont 10 sur `undici` (transitif de `cheerio`, chemin de requête sortante) et 10 sur `axios`, et la montée n°2 du §4.4 devient prioritaire. ⚠️ Ne rien faire est le pire des trois : c'est l'état actuel, où l'on paie le scan sans avoir le service.
- Statut        : ouvert

---

## 6. Ce que je n'ai PAS pu vérifier, et pourquoi

1. **L'état réel des conteneurs de production** (🪤 Piège 18). Je n'ai qu'un accès HTTP en lecture à `api.axion-crm-pro.com` / `app.axion-crm-pro.com`, pas de shell : je n'ai **pas** pu jouer `docker inspect` ni `docker compose ps` sur le VPS. La conclusion « les workers ne tournent nulle part » (H47-005) repose sur les **5 fichiers compose** et sur les **8 workflows de prospection**, deux sources concordantes mais toutes deux déclaratives. Le dossier avertit précisément qu'un fichier peut mentir sur ce que fait un conteneur. **Un `docker compose ps` sur la production trancherait définitivement** — à jouer avant d'agir sur H47-005.
2. **Le paquet frontend réellement servi en production.** J'ai mesuré un `vite build` **reconstruit localement** le 2026-08-19 depuis `main = 1145473`. Je n'ai pas téléchargé les fichiers `dist/assets/*.js` servis par `app.axion-crm-pro.com` pour vérifier qu'ils portent les mêmes marqueurs. Un décalage de déploiement invaliderait la classe C4 (6 alertes). Contrôle possible et peu coûteux : récupérer le bundle servi et rejouer `bundle-marqueurs.txt` dessus.
3. **L'exploitabilité effective des 5 CVE de classe B.** J'ai établi qu'il n'existe **aucune amorce de pollution de prototype dans `frontend/src`** (grep sur `__proto__`, `constructor[`, `Object.assign({}`, `deepMerge`, `merge(`, `setIn(` → 0, avec témoin positif sur `axios` → 6 occurrences). Mais je n'ai **pas** audité les 27 dépendances de production du frontend pour une amorce transitive, ni conduit d'analyse de teinte. Le classement « conditionnel » est donc un plancher, pas une preuve d'innocuité.
4. **Si le canal de mise à jour de sécurité produit effectivement des PR.** Mesuré : alertes actives, `automated-security-fixes` `enabled` et **non** `paused`, plafonds `open-pull-requests-limit` **non saturés** (0 PR ouverte — le mode de panne silencieux du 2026-08-16 n'est donc pas rejoué), et pourtant **0 PR de sécurité** environ 24 h après la création des 57 alertes. Les alertes n'ayant qu'un jour, **ce n'est pas encore une preuve de canal coupé** : je refuse de le déclarer rompu sans une seconde mesure. **À re-mesurer après l'exécution planifiée du lundi 06:00 UTC** (`gh pr list --state open --search "author:app/dependabot"`). Si le compte est toujours 0 alors qu'`axios 1.16.1 → 1.18.0` est une correction **dans l'intervalle `^1.7.0` déjà déclaré**, alors la réserve documentaire invoquée par `dependabot.yml` (« `ignore` par `update-types` n'affecte pas les mises à jour de sécurité ») sera **infirmée par la mesure sur ce dépôt** — et ce sera un constat S1, car le gel aurait alors coupé le canal de sécurité en silence, exactement le dégât que sa rédaction cherchait à éviter.
5. **L'historique complet des exécutions de `security.yml`.** J'ai lu les **20 derniers** runs et le journal complet du dernier run `main` (32239147620). Je n'ai pas dépouillé les runs antérieurs : je ne peux donc pas dater le jour où le job `composer-audit` a commencé à ne rien mesurer (H47-001), seulement établir qu'il ne mesure rien **aujourd'hui**. Le libellé de la commande n'ayant jamais changé dans `git log -- .github/workflows/security.yml`, il est probable que ce soit depuis l'origine du job — **probable, non mesuré**.
6. **Les 10 alertes `auto_dismissed`.** GitHub les a écartées automatiquement le 2026-08-19 (`nanoid` ×4, `brace-expansion` ×6, toutes `development`). Je n'ai pas pu lire la **règle** d'auto-écartement qui les a produites (pas d'API publique la restituant) : je ne peux donc pas garantir que cette règle n'écartera pas, demain, une alerte qui compte. Elle n'a touché que des dépendances de développement à ce jour.
