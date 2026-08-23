# 01 — INVENTAIRE RÉEL, RECOMPTÉ DANS LE CODE

> **Référence de l'audit : `main = c0c453d`** (`origin/main` identique), **0 PR ouverte**,
> mesuré le **2026-08-19T09:25Z**. Aucun SHA de `PROMPT_AUDIT_360_CRM_PRO_2026-08-18.md`
> n'a été réutilisé (règle 6 : le document a eu tort trois fois de suite).
> Agent 2 — gardien du contexte. §4.0 : « recompte chaque liste dans le code et publie l'écart ».

---

## 0. La référence, et les écarts avec le document qui pilote l'audit

| Ce que le document v2.1 affirme | Mesuré le 19/08 |
|---|---|
| `main` = `e577828`, puis `65e39a6` (§3) | **`c0c453d`** — 8 commits plus loin (PR #177 → #186 fusionnées depuis la rédaction) |
| Journal étape 1a §9.1 : `main = d4910c8` | **périmé de 5 PR** |
| worktree `crmpro-wt-etape0` « n'est plus le worktree actif » | il **existe toujours** (`702253c`, branche `chore/etape-0-prealables`) |
| worktree étape 1a sur `feat/etape-1a` | il est sur la branche **`travail`**, à `c0c453d` — **interdit d'accès par consigne du dirigeant** |
| 0 PR Dependabot ouverte | ✅ **confirmé** — mais **20 branches `dependabot/*` subsistent sur `origin`** |

⚠️ Note de méthode : `_PROMPTS/PROMPT_AUDIT_360_CRM_PRO_2026-08-18.v2.0-original-sauvegarde.md.bak`
porte le **même MD5** que la v2.1 (`44c6f70053057cabefec69d50b00d6e6`). La « sauvegarde de la
v2.0 » est en réalité une copie de la v2.1 : **la v2.0 n'existe plus nulle part**. Le fichier
`.bak` ne prouve rien et ne permet aucune comparaison. Les deux fichiers sont non versionnés (`??`).

---

## 1. Les écarts de comptage — §4.0

**Un élément présent dans le code et absent du §4 entre au périmètre. Voici les écarts.**

| Liste du §4 | Le document annonce | **Recompté dans le code** | Écart |
|---|---|---|---|
| §4.1 modèles | 21 | **18 fichiers `Models/*.php`** | **−3** : `Concerns/` et `Scopes/` sont des **répertoires** (1 trait, 1 scope), pas des modèles |
| §4.2 contrôleurs | 44 | **44 fichiers**, dont **2 classes de base** (`Controller.php`, `Api/ApiController.php`) → **42 contrôleurs réels** | composition **fausse** (ci-dessous) |
| §4.2 « Phase 2 — stubs (5) » | 5 | **3** (`CampaignsController`, `ColdEmailController`, `LinkedInController`) | **−2** : `Api/Phase2/AnalyticsController` et `Api/Phase2/CrmController` **n'existent pas** |
| §4.2 « Internes (3) » | 3 | **4** | **+1** : `Internal/ZeptoMailWebhookController` **absent du document** |
| §4.3 points d'API | ~110 | **112 déclarations** dans `routes/api.php` (**328 l.**, pas 311) ; **143 lignes** rendues par `route:list --path=api` | à ouvrir un par un |
| §4.5 tâches planifiées | **10** | **35 `Schedule::command`** dans `routes/console.php` (**170 l.**) | 🔴 **+25 tâches jamais nommées par le document** |
| §4.5 jobs | 7 | **6 jobs + 1 trait** (`Concerns/RunsInWorkspace`) | composition à corriger |
| §4.5 commandes Artisan | 49 | **49** | ✅ exact |
| §4.7 écrans | 39 (dont 4 stubs) | **36 écrans** dans `routeTree.tsx` | **−3** : `/crm` (`CrmStub`) et `/analytics` (`AnalyticsStub`) **n'existent pas** ; et le 37ᵉ `createRoute` est **`layoutRoute`**, la coquille de mise en page — `id: 'layout'`, **aucun `path`**, donc pas un écran (corrigé le 2026-08-23, `grep -oP "^const \K\w+(?= = createRoute)"` → 37 déclarations dont `layoutRoute`). La fiche 360° est `/console/personnes/$personKey`, **pas** `/persons/{key}` |
| §4.7 composants | 33 | **34 fichiers** (`layout` 5 + `OnboardingTour` + `ui` 28, dont `cn.ts` et `index.ts`) | +1 |
| §4.9 migrations | 54 | **58** | **+4** |
| §4.10 workflows | 17 | **17** | ✅ exact |
| §4.6 workers | 34 fichiers | **34** | ✅ exact |
| §4.4 services | 68 | **84 fichiers** (`app/Services` + `app/Crm`) | **+16** |
| policies | 11 | **11** | ✅ exact |
| tests backend | — | **95 fichiers `*Test.php`** | — |
| tests frontend | 37 (§3, révisé) | **37** | ✅ confirmé — « le frontend n'est pas testé » est **faux** |
| tests workers | — | **6** | — |
| tables en base (locale) | — | **104** | — |

### 1.1 Les 25 tâches planifiées que le §4.5 ne nomme pas

`companies:retry-google-places` · `media:extract-from-companies` · `media:sync-from-companies` ·
`media:sync-emissions-from-parent` · `media:link-to-companies` · `media:tag-emissions-status` ·
`media:find-websites` · `media:import-opendatasoft cppap` · `media:import-opendatasoft spel` ·
`media:import-opendatasoft agences` · `media:import-emissions-wikidata` · `media:import-arcom` ·
`media:generate-redaction-emails` · `journalists:scrape-ours` · `media:score-confidence` ·
`media:link-emissions-to-channels` · `media:backfill-periodicity` · `media:import-blogs` ·
`prospection:score-email-confidence` · `retention:prune-scraper-runs` · `media:enrich` ·
`media:clean-emails` · `rgpd:purge-vivier` · `rgpd:purge-business-prospects` · `crm:flush-outbound`

Dont **trois destructives** que le §4.5 ne signale pas comme planifiées :
`retention:prune-scraper-runs`, `rgpd:purge-vivier`, `rgpd:purge-business-prospects`.
Toutes passent la grille §5.4.

---

## 2. Ce que le §3 bis affirme, et ce que la mesure en dit (règle 7 : contre-vérification)

> Le §3 bis reprend `_REPORTS/2026-08-19_INVENTAIRE-ETAPE-1A.md`, produit **le jour même par un
> agent**. Le document exige lui-même de le contre-vérifier plutôt que de le recopier. Fait.

### 2.1 « Six tables mortes » — **partiellement réfuté**

Les sept tables citées **existent toutes en base** (7/7 dans `information_schema.tables`).

| Table | Modèle | Contrôleur | Route | Écran | Verdict |
|---|---|---|---|---|---|
| `crm_tasks` | ✗ | ✗ | ✗ | ✗ | **morte — confirmé** |
| `crm_notes` | ✗ | ✗ | ✗ | ✗ | **morte — confirmé** |
| `crm_pipelines` | ✗ | ✗ | ✗ | ✗ | **morte — confirmé** |
| `pipeline_stages` | ✗ | ✗ | ✗ | ✗ | **morte — confirmé** |
| `deals` | ✗ | ✗ | ✗ | ✗ | **morte — confirmé** |
| `deal_history` | ✗ | ✗ | ✗ | ✗ | **morte — confirmé** |
| `saved_views` | ✗ | **✓ `Api/SavedViewsController`** | **✓ `apiResource('saved-views')`** | ✗ | 🔴 **RÉFUTÉ** |

🔴 **`saved_views` n'est pas « morte » au sens annoncé** : elle a un contrôleur **et** une route
enregistrée (`routes/api.php:195`). Elle est pire que morte — elle **ment** :

```php
public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }  // 200, liste VIDE
public function store(...)   { return $this->notImplemented('10'); }                    // 501
public function show(...)    { return $this->notImplemented('10'); }                    // 501
public function update(...)  { return $this->notImplemented('10'); }                    // 501
public function destroy(...) { return $this->notImplemented('10'); }                    // 501
```

`GET /saved-views` répond **200 avec une liste vide** — donc « tu n'as aucune vue enregistrée »
et non « cette fonction n'existe pas ». Un appelant ne peut pas distinguer les deux.
Le §3 bis est donc à corriger : **six tables mortes + une table à façade mensongère**.

### 2.2 « Aucun hook Git configuré » — ✅ **confirmé**

```
$ git config core.hooksPath        -> (vide)
$ ls .git/hooks | grep -v .sample  -> 0
```
La CI est bien le seul filet.

### 2.3 « `reportUnmatchedIgnoredErrors: false` », « baseline 2 045 l. » — 🔴 **RÉFUTÉ**

```
$ grep -n "level:|reportUnmatchedIgnoredErrors" backend/phpstan.neon
13:    level: 8
27:    reportUnmatchedIgnoredErrors: true      <- true, PAS false
$ wc -l backend/phpstan-baseline.neon
1321                                           <- 1 321 lignes, PAS 2 045
```
Le défaut décrit au §7 (agent 46) et au §10 (piège 13) **a été corrigé** et le document ne l'a
pas suivi. **Ne pas le re-rapporter comme ouvert.**

---

## 3. Le terrain (P0.3) — état mesuré

| Exigence du §8 P0.3 | État | Preuve |
|---|---|---|
| Console CRM en local, une seule origine, TLS non contourné | ⚠️ **partiel** — pile relancée avec `docker-compose.local.yml` ; `https://app.localhost` → **200**, `https://api.localhost/up` → **200** ; mais **toute route authentifiée répond 500** (A-001) | `04_PREUVES/P0/etat-local.txt` |
| `migrate:fresh` deux fois de suite | P0.3b | `04_PREUVES/P0/` |
| Suites (Pest, Vitest ×2, Playwright, PHPStan, Pint, ESLint, build) | P0.3c | `04_PREUVES/P0/` |
| Jeu au volume de référence | ✅ **existe, versionné** : `backend/database/perf/seed_reference_50k.sql` (300 000 fiches) et `seed_volume_production_4m.sql` (2,8 M) | journal étape 1a §2.6, §2.11 |

Pile locale relancée depuis **le dépôt principal** : les conteneurs tournaient auparavant avec
un fichier de surcouche pris dans le worktree `crmpro-wt-etape0`
(`com.docker.compose.project.config_files` le prouvait) — une référence périmée.

---

## 4. 🔴 Le premier constat est tombé pendant l'amorçage — A-001

Le §8 P0.3 prévient : « chaque échec ici est lui-même un constat de sévérité élevée ».

**Toute route API protégée par `auth:sanctum` répond HTTP 500 à un appel non authentifié,
au lieu de 401 — en local, en préproduction ET EN PRODUCTION.**

```
                                    local   PRODUCTION   préprod
/up                                  200        200        200     <- témoin positif
/api/v1/auth/login                   405         -          -      <- témoin positif (la route existe)
/api/v1/config/features              500      **500**       500
/api/v1/dashboard/stats              500      **500**        -
/api/v1/companies                    500      **500**        -

$ docker exec axion-crm-api tail storage/logs/laravel.log
local.ERROR: Route [login] not defined.
```

Détail et sévérité : `02_CONSTATS.md`.
Preuves : `04_PREUVES/P0/500-au-lieu-de-401.txt`, `04_PREUVES/P0/prod-401-vs-500.txt`.

---

## 5. Tableaux de suivi — une ligne par élément du §4

Les tableaux nominatifs vivent dans `11_GRILLES/` (`ecrans.md`, `routes.md`, `tables.md`,
`automatismes.md`, `fonctionnalites.md`, `parcours.md`, `raccordement.md`). Ouverts en P0,
remplis par les agents de P1. **Aucune ligne ne doit rester vide à la clôture (§12-1).**
