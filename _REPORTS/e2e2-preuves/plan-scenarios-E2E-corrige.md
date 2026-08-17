# SCÉNARIOS E2E — AUTOPILOT CRM (site axion-ia.com ↔ Axion CRM Pro)

> Rédigé le 2026-08-14. Compagnon de `_PLANS/2026-08-14_RUNBOOK-ACTIVATION-CRM.md`.
>
> **E2E n°1** (profondeur) et **E2E n°2** (regard neuf) sont les DEUX feux verts
> exigés par l'ordre de mission avant la première bascule de drapeau en
> production. Ils se jouent sur l'**environnement intégré LOCAL** (site en
> développement + CRM en `docker compose`, côte à côte sur la machine de Will),
> AVANT toute fusion — pas en production.
>
> Après la séquence d'activation, un **smoke prod réduit** rejoue en production
> une seule soumission `ZZ TEST` de bout en bout (cf. runbook §4).

---

## TABLE DES MATIÈRES

- [§A. E2E n°1 — montage de l'environnement intégré local](#a-e2e-n1--montage-de-lenvironnement-intégré-local)
  - [A.1 Démarrer le CRM](#a1-démarrer-le-crm)
  - [A.2 Démarrer le site](#a2-démarrer-le-site)
  - [A.3 Pièges de montage (à lire AVANT de conclure à un bug)](#a3-pièges-de-montage-à-lire-avant-de-conclure-à-un-bug)
  - [A.4 Outillage de vérification](#a4-outillage-de-vérification)
- [§B. E2E n°1 — scénarios par type d'événement](#b-e2e-n1--scénarios-par-type-dévénement)
  - [B.0 Grille commune](#b0-grille-commune)
  - [B.1 Formulaire unifié (12 types métier)](#b1-formulaire-unifié-12-types-métier)
  - [B.2 Podcast](#b2-podcast)
  - [B.3 Simulateur de gains](#b3-simulateur-de-gains)
  - [B.4 Lettre d'information — opt-in et opt-out](#b4-lettre-dinformation--opt-in-et-opt-out)
  - [B.5 Avis client](#b5-avis-client)
  - [B.6 Calendly — booked / completed / canceled / no_show](#b6-calendly--booked--completed--canceled--no_show)
  - [B.7 Candidature à une offre — AVEC et SANS case vivier](#b7-candidature-à-une-offre--avec-et-sans-case-vivier)
  - [B.8 Candidature commerciale (tunnel Mémo Isère)](#b8-candidature-commerciale-tunnel-mémo-isère)
  - [B.9 Chatbot — capture de lead](#b9-chatbot--capture-de-lead)
  - [B.10 Opposition vivier (un clic, sans connexion)](#b10-opposition-vivier-un-clic-sans-connexion)
  - [B.11 RGPD art. 15 — export](#b11-rgpd-art-15--export)
  - [B.12 RGPD art. 17 — effacement](#b12-rgpd-art-17--effacement)
- [§C. E2E n°1 — parcours transverses](#c-e2e-n1--parcours-transverses)
- [§D. Pannes simulées](#d-pannes-simulées)
- [§E. E2E n°2 — checklist « regard neuf »](#e-e2e-n2--checklist--regard-neuf-)
- [§F. Critères de sortie](#f-critères-de-sortie)
- [§G. [À VÉRIFIER]](#g-à-vérifier)

---

## §A. E2E n°1 — montage de l'environnement intégré local

### A.1 Démarrer le CRM

```bash
cd /c/Users/willi/Documents/Projets/Axion-CRM-Pro
docker compose up -d
docker compose ps --format '{{.Name}} {{.State}}'
```

Ce que le `docker-compose.yml` expose réellement (vérifié dans le fichier) :

| Service | Exposition | Détail |
|---|---|---|
| `caddy` | **80 / 443** | reverse proxy : `https://api.localhost` → `api:80`, `https://app.localhost` → `app:5173`, `tls internal` (certificat auto-signé) |
| `postgres` | **55432** → 5432 | base `axion_crm`, utilisateur `axion`, mot de passe `axion_dev_only` |
| `redis` | **56379** → 6379 | — |
| `reverb` | **8080** | WebSocket, sans rapport avec ces scénarios |
| `api`, `horizon`, `scheduler`, `app` | *non publiés* | joignables uniquement via Caddy ou par le réseau `axion-crm` |

🔴 **Par défaut, l'API ne publie AUCUN port** : l'unique porte d'entrée depuis
l'hôte est `https://api.localhost` (port 443, certificat auto-signé). Cette
adresse convient à **curl et aux navigateurs**, mais **pas à Node**, qui ne la
résout pas (§A.3 piège 1).

✅ Pour l'E2E, monter la pile avec la surcouche locale, qui publie `api` sur
`58080` (§A.2.0) :

```bash
docker compose -f docker-compose.yml -f docker-compose.local.yml up -d
```

`CRM_SYNC_URL` prend alors la valeur joignable par Node :

```
CRM_SYNC_URL=http://localhost:58080/api/internal/site-sync
```

(`https://api.localhost/api/internal/site-sync` reste valable pour les appels
`curl` du §B et du §D — c'est la même API, par une autre porte.)

Préparer la base et le référentiel.

⚠️ **Partir d'une base SAINE.** Sur une base locale laissée dans un état
intermédiaire, `migrate` échoue :

```
2026_05_17_000011_setup_pg_partman_audit_logs ................ FAIL
SQLSTATE[23514]: ERROR: no partition of relation "audit_logs" found for row
```

Une seule ligne `audit_logs` postérieure à la migration de partitionnement
suffit à bloquer TOUT le §A. Si c'est le cas — sauvegarder, puis recréer :

```bash
docker exec axion-crm-postgres sh -c 'pg_dump -U axion -d axion_crm -Fc -f /tmp/avant.dump'
docker cp axion-crm-postgres:/tmp/avant.dump ./avant-e2e.dump     # filet
cd /c/Users/willi/Documents/Projets/Axion-CRM-Pro && docker compose stop api horizon scheduler reverb
docker exec axion-crm-postgres sh -c "psql -U axion -d postgres -c \"SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='axion_crm';\"; psql -U axion -d postgres -c 'DROP DATABASE axion_crm;'; psql -U axion -d postgres -c 'CREATE DATABASE axion_crm OWNER axion;'"
docker compose up -d api
```

Puis, **dans cet ordre** :

```bash
docker compose exec -T api php artisan migrate --force --database=pgsql_owner </dev/null

# 🔴 CES DEUX SEEDERS D'ABORD. `GovernedTagsSeeder` pose les tags business par
#    une boucle sur `workspaces WHERE slug != 'vivier-candidats'` : sans
#    workspace business, la boucle ne s'exécute pas et AUCUN tag `src:` n'est
#    créé. Or le §B.1 exige de vérifier `src:site-formulaire-<type>` pour les
#    12 types. Le workspace `axion-ia` est créé par `OwnerUserSeeder`.
docker compose exec -T api php artisan db:seed --class=PermissionsAndRolesSeeder --force </dev/null
docker compose exec -T api php artisan db:seed --class=OwnerUserSeeder --force </dev/null

docker compose exec -T api php artisan db:seed --class=GovernedTagsSeeder --force </dev/null
```

**Contrôle** — les 14 valeurs de `CRM_FORM_TYPES` doivent avoir leur tag :

```bash
docker exec axion-crm-postgres psql -U axion -d axion_crm -tAc \
  "SELECT count(*) FROM tags WHERE slug LIKE 'src:site-formulaire-%';"
# ATTENDU : 14   (si 0 → les deux seeders ci-dessus n'ont pas été joués)
```

⚠️ **Le mot de passe de la console est GÉNÉRÉ au seed**, pas documenté ailleurs :

```bash
docker compose exec -T api cat storage/app/private/seeders/owner-initial-password.txt
```

⚠️ **La double authentification est obligatoire au premier accès** : sans elle,
toutes les routes protégées répondent `403 first_login_required` et la console
reste vide. En local, sur une base jetable :

```bash
docker exec axion-crm-postgres psql -U axion -d axion_crm -c \
  "UPDATE users SET first_login_completed_at = now() WHERE email='<l adresse du seed>';"
```

⚠️ **Bruit permanent** : Telescope est actif en local sans que ses tables
existent — chaque commande artisan se termine par ~25 lignes de pile d'appel,
**y compris quand elle réussit**. Poser `TELESCOPE_ENABLED=false` dans le `.env`
pour ne pas confondre ce bruit avec une vraie erreur.

Poser les drapeaux locaux dans `/c/Users/willi/Documents/Projets/Axion-CRM-Pro/.env`
(**local uniquement** — c'est l'inverse de la production, où tout part fermé) :

```
CRM_INGEST_ENABLED=true
CRM_INGEST_CANDIDATES_ENABLED=true
CRM_SCRAPE_FUNNEL_ENABLED=true
CRM_PURGE_ENABLED=true
CRM_OUTBOUND_ENABLED=true
CRM_CONSOLE_V2_ENABLED=true
CRM_DB_APP_ROLE_ENABLED=true
CRM_STRICT_WORKSPACE_SCOPE=true
DB_APP_PASSWORD=axion_app_dev_only
SITE_SYNC_HMAC_SECRET=<64 hex générés pour le local, PARTAGÉS avec le site>
SITE_CRM_WEBHOOK_URL=http://host.docker.internal:3000/api/internal/crm-webhook
CRM_SCRAPE_VALIDATE_MX=false
```

puis :

```bash
docker compose exec -T api php artisan config:clear </dev/null
docker compose up -d --force-recreate --no-deps api horizon scheduler
```

🔴 **`docker compose restart` NE RECHARGE PAS `env_file:`** — il relance le
processus dans le conteneur existant, dont l'environnement est figé depuis sa
création. Vérifié :

```
après `restart api` : docker compose exec -T api printenv CRM_INGEST_ENABLED → (vide)
après `up -d api`   : docker compose exec -T api printenv CRM_INGEST_ENABLED → true
```

**Contrôle obligatoire avant de continuer** (une sortie vide = la variable est
ABSENTE, pas « rien à signaler ») :

```bash
docker compose exec -T api php artisan tinker --execute="printf('ingest=%s cand=%s approle=%s strict=%s secret=%d%s', var_export(config('crm.ingest.enabled'),true), var_export(config('crm.ingest.candidates_enabled'),true), var_export(config('crm.db_app_role'),true), var_export(config('crm.strict_workspace_scope'),true), strlen(config('crm.ingest.hmac_secret')), PHP_EOL);" </dev/null
# ATTENDU : ingest=true cand=true approle=true strict=true secret=64
```

⚠️ Les clés de `config/crm.php` ne portent pas le nom des variables :
`crm.ingest.enabled` (et non `crm.ingest_enabled`), `crm.db_app_role`,
`crm.strict_workspace_scope`, `crm.console_v2`, `crm.purges_enabled`,
`crm.outbound_enabled`, `crm.scrape_funnel.enabled`. Un `config('crm.…')` qui
rend `NULL` signifie « mauvaise clé », pas « drapeau fermé ».

⚠️ `CRM_SCRAPE_VALIDATE_MX=false` en local : sans lui, le funnel fait de vraies
requêtes DNS sur les adresses de test.

⚠️ `CRM_DB_APP_ROLE_ENABLED=true` **en local aussi** : sans ce drapeau, la RLS ne
mord pas et tous les tests d'étanchéité passent au vert pour de mauvaises
raisons.

### A.2 Démarrer le site

#### A.2.0 — Publier le port de l'API (À FAIRE EN PREMIER)

Sans cela, **le site ne peut pas émettre vers le CRM**. Mesuré le 2026-08-17 :

```
node -e "require('dns').lookup('api.localhost', console.log)"   → ENOTFOUND
curl  https://api.localhost/up                                   → 200
```

Les navigateurs (et curl) traitent `*.localhost` spécialement ; **Node ne le
fait pas**. Et le remède longtemps documenté — deux lignes dans
`C:\Windows\System32\drivers\etc\hosts` — exige une **élévation
administrateur** que l'autopilote n'a pas.

Le dépôt CRM porte désormais une surcouche prévue pour ça :

```bash
cd /c/Users/willi/Documents/Projets/Axion-CRM-Pro
docker compose -f docker-compose.yml -f docker-compose.local.yml up -d api
docker port axion-crm-api          # ATTENDU : 80/tcp -> 0.0.0.0:58080
```

Elle n'est **jamais** chargée en production (Compose n'auto-charge que
`docker-compose.yml` et `docker-compose.override.yml` ; la production lance
`-f docker-compose.yml -f docker-compose.prod.yml`).

Contrôle — Node doit joindre le CRM :

```bash
node -e "fetch('http://localhost:58080/api/internal/site-sync',{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'}).then(r=>console.log('HTTP',r.status))"
# ATTENDU : HTTP 401   (joignable, et l'authentification passe AVANT le drapeau)
```

#### A.2.1 — Créer la base du site

🔴 **Le §A ne créait NULLE PART la base du site**, et elle n'existe pas :

```
docker exec bookforge-postgres psql -U axion_ia -d axion_ia_dev -c "\dt"
FATAL:  role "axion_ia" does not exist
```

Le `DATABASE_URL` du worktree pointe `localhost:5433` — l'instance Postgres du
conteneur `bookforge-postgres`. Créer le rôle et la base, **avec le mot de passe
que porte déjà `.env.local`** :

```bash
cd /c/Users/willi/Documents/Projets/Axion-IA/axionia
# lire (sans l'afficher) l'utilisateur et le mot de passe de DATABASE_URL, puis :
docker exec bookforge-postgres psql -U <super-utilisateur> -d postgres \
  -c "CREATE ROLE axion_ia LOGIN PASSWORD '<celui de .env.local>';" \
  -c "CREATE DATABASE axion_ia_dev OWNER axion_ia;"
pnpm prisma migrate deploy
```

#### A.2.2 — Lancer le site

```bash
cd /c/Users/willi/Documents/Projets/Axion-IA/axionia   # dépôt principal
# ou un worktree DÉDIÉ, à créer explicitement :
#   git worktree add ../axionia-wt-e2e-crm <branche>
# ⚠️ `axionia-wt-e2e-crm`, `axionia-wt-crm-l4` et `axionia-wt-crm-l5obs` existent
#    comme DOSSIERS mais ne sont plus des worktrees Git (aucune commande `git`
#    n'y fonctionne). Un dossier qui a survécu à un `git worktree prune` n'est
#    pas un environnement de travail.
pnpm install --frozen-lockfile
pnpm prisma:generate
pnpm dev            # → http://localhost:3000
```

Le worker BullMQ doit tourner **séparément** du serveur Next : c'est lui qui
émet. Vérifier que la queue `crm-sync` et le cron `crm-sync-sweep-cron` sont
bien enregistrés au démarrage.

#### A.2.3 — Variables

⚠️ Le fichier est **`.env.local`**, pas `.env` (le worktree n'a pas de `.env`).
Il ne porte **aucune** des variables ci-dessous au départ :

```
CRM_SYNC_ENABLED=true
CRM_SYNC_CANDIDATES_ENABLED=true
CRM_SYNC_URL=http://localhost:58080/api/internal/site-sync
SITE_SYNC_HMAC_SECRET=<le MÊME secret que côté CRM>
VIVIER_STOCK_ENABLED=true
```

✅ **Plus besoin de `NODE_TLS_REJECT_UNAUTHORIZED=0`** : le port publié parle en
clair sur la boucle locale, il n'y a plus de certificat auto-signé à contourner.
Cette variable ne doit jamais exister — elle désarme la vérification TLS pour
**tout** le processus, y compris les appels aux tiers.

#### A.2.4 — ⚠️ La console CRM n'est PAS utilisable en local

Constaté le 2026-08-17, non résolu :

```
POST /api/v1/auth/login → 200   puis   GET /api/v1/config/features → 401
Set-Cookie: axion_crm_session=… ; domain=.localhost ; samesite=lax
```

`localhost` est un domaine de premier niveau : **les navigateurs refusent un
cookie `Domain=.localhost`**. En repassant le cookie en *host-only*, il est bien
stocké — mais le SPA servi depuis `app.localhost` ne peut plus **lire** le
`XSRF-TOKEN` posé sur `api.localhost` (lecture JS inter-hôtes impossible) → 419.
**Les deux configurations échouent.** En production le problème n'existe pas
(`.axion-crm-pro.com` est un domaine enregistrable ordinaire).

⛔ **Conséquence : les captures 1 à 9 du §E.4 restent inatteignables.** Le
correctif à faire est de servir le SPA **et** l'API sous une seule origine en
local (route `/api/*` + `/sanctum/*` sur `app.localhost` vers `api:80`). Il
touche `infra/caddy/Caddyfile`, **partagé avec la production** : à faire par une
surcouche locale dédiée, pas par une édition en place.

Le worker BullMQ doit tourner **séparément** du serveur Next : c'est lui qui
émet. Vérifier que la queue `crm-sync` et le cron `crm-sync-sweep-cron` sont
bien enregistrés au démarrage.

### A.3 Pièges de montage (à lire AVANT de conclure à un bug)

1. **Résolution de `api.localhost` sous Windows — TRANCHÉ le 2026-08-17.**
   Node ne la résout **pas** (`ENOTFOUND`), curl et les navigateurs si. Ce n'est
   plus une hypothèse, c'est mesuré. ✅ **Traité par le §A.2.0** (port `58080`
   publié via `docker-compose.local.yml`).
   L'édition de `C:\Windows\System32\drivers\etc\hosts` n'est **plus
   nécessaire** — et de toute façon inaccessible sans élévation :
   ```
   ECHEC: L'accès au chemin d'accès 'C:\WINDOWS\System32\drivers\etc\hosts' est refusé.
   ```
2. **`docker run -w /app` sous Git Bash** devient `C:/Program Files/Git/app` →
   « working directory is invalid ». Préfixer `MSYS_NO_PATHCONV=1`.
3. **`migrate:fresh` / `RefreshDatabase` échouent sur une base déjà migrée** :
   `cannot drop table part_config because extension pg_partman requires it`.
   **Recréer `axion_crm_test` avant chaque suite complète.** Un premier run rouge
   avec des `QueryException` partout n'est PAS une vraie rougeur.
4. **`pint --test` rougit sur `line_ending`** pour tout fichier du worktree
   (`core.autocrlf=true` pose des CRLF que le blob n'a pas). Normaliser en LF
   avant de conclure.
5. **PHPStan sur bind-mount Windows** = délai de 600 s dépassé. Le lancer dans
   une COPIE du code DANS le conteneur (`docker cp` → ~40 s).
6. **Playwright et le certificat auto-signé** : `ignoreHTTPSErrors: true` sur les
   contextes qui visitent `https://app.localhost`.
7. **Le worker doit voir les drapeaux**, pas seulement le serveur Next. C'est le
   même piège qu'en production, en local aussi.

### A.4 Outillage de vérification

```bash
# --- Base du CRM (local) ---------------------------------------------------
crmsql() { docker exec axion-crm-postgres psql -U axion -d axion_crm -c "$1"; }

# --- Base du site (local) --------------------------------------------------
# DATABASE_URL du .env du worktree
sitesql() { psql "$DATABASE_URL" -c "$1"; }
```

Trois requêtes qui servent dans presque tous les scénarios :

```sql
-- 1. L'outbox du site, du plus récent au plus ancien
SELECT event_type, subject_ref, universe, status, attempts, response_status, crm_result, last_error
FROM crm_sync_outbox ORDER BY created_at DESC LIMIT 10;

-- 2. La timeline côté CRM
SELECT id, external_ref, kind, occurred_at, title, subject_type, subject_id
FROM activities WHERE external_ref LIKE 'site:event:%' ORDER BY id DESC LIMIT 10;

-- 3. Les tags posés sur la dernière fiche touchée
SELECT t.slug, ct.assigned_by FROM company_tag ct JOIN tags t ON t.id = ct.tag_id
WHERE ct.company_id = <id> ORDER BY t.slug;
```

---

## §B. E2E n°1 — scénarios par type d'événement

### B.0 Grille commune

Chaque scénario se joue en **quatre temps**, toujours les mêmes :

1. **Geste** — le parcours utilisateur réel, dans le navigateur (Playwright ou à
   la main). Jamais un appel direct à une fonction interne : c'est précisément ce
   qui a masqué un blocage total du tunnel que 116 tests unitaires ne voyaient
   pas.
2. **Base SITE** — la ligne d'outbox attendue.
3. **Base CRM** — la fiche, les tags, la timeline attendus.
4. **Contre-test** — le rejeu à l'identique doit être **idempotent**.

**Attendus communs à tous les scénarios**, sauf mention contraire :

- ligne d'outbox : `status = 'sent'`, `attempts = 1`, `response_status = 200` ;
- `crm_result` ∈ `created` | `updated` | `pending_match` ;
- côté CRM : **une** ligne `activities` avec
  `external_ref = 'site:event:<event_id>'` et `person_key` renseigné ;
- **contre-test** : rejouer l'émission (remettre la ligne en `pending` et relancer
  le balayage) → HTTP 200 avec `crm_result = 'noop_idempotent'`, et **aucune**
  nouvelle ligne dans `activities` (contrainte `UNIQUE (workspace_id,
  external_ref)`).

**Convention de données** : préfixe `ZZ TEST`, adresses en `@example.invalid`.

---

### B.1 Formulaire unifié (12 types métier)

**Geste** — `http://localhost:3000/fr/contact`, remplir et envoyer, une fois par
type métier. Les 12 types : `audit`, `implementation`, `formation`, `un_a_un`,
`devis`, `partenariat`, `presse`, `recrutement`, `speaker`, `investisseur`,
`support_client`, `autre`.

**Base SITE**

```sql
SELECT o.event_type, o.universe, o.status, o.crm_result, o.payload->>'form_type' AS form_type
FROM crm_sync_outbox o WHERE o.subject_ref = 'site:submission:<id>';
```

| Attendu | Valeur |
|---|---|
| `event_type` | `form_submission` |
| `payload.form_type` | le type choisi |
| `universe` | `business` — **SAUF `recrutement` → `vivier`** |
| `subject_ref` | `site:submission:<uuid>` |

🔴 **Le type `recrutement` bascule dans l'univers vivier** (règle
`universeOf()`). Il est donc gaté par `CRM_SYNC_CANDIDATES_ENABLED`, au même
titre qu'une candidature. À vérifier explicitement : c'est contre-intuitif.

**Base CRM**

```sql
SELECT a.kind, a.title, a.payload->>'form_type' FROM activities a WHERE a.external_ref = 'site:event:<event_id>';
```

- avec SIREN renseigné → la fiche `companies` correspondante est rattachée
  (`subject_type = 'company'`), `crm_result = 'created'` ou `'updated'` ;
- **sans SIREN** → `crm_result = 'pending_match'`, activité seule, `match_hint`
  dans le `payload`, **aucune** `company` fabriquée. C'est le comportement
  NOMINAL, pas un échec ;
- tag de provenance **`src:site-formulaire-<type>`** posé — un tag par type
  (`src:site-formulaire-audit`, `-devis`, `-presse`…), il n'existe **pas** de tag
  générique « formulaire unifié ». Vérifier le tag correspondant au type envoyé,
  pas un tag fourre-tout.

**Contre-tests spécifiques**

- **Type inconnu** : forger un appel avec `form_type: "inexistant"` (Geste E du
  runbook) → **422 `unknown_form_type`**, ligne en `gave_up`. C'est exactement le
  défaut qui a failli faire perdre tous les leads du simulateur.
- **Frontière du contrat** : les 14 valeurs de `CRM_FORM_TYPES` (site) doivent
  être acceptées une à une par le CRM. Aucun compilateur ne relie les deux
  dépôts : ce test EST le compilateur.
- **Le froid ne se réchauffe pas** : si la `company` existait déjà en
  `prospect / nouveau`, vérifier que son `lifecycle_stage` a bien monté (lead
  entrant) et que la fiche n'a **pas** été rétrogradée. On ne recule jamais.

---

### B.2 Podcast

**Geste** — formulaire de demande de podcast, envoyer.

| Attendu | Valeur |
|---|---|
| `event_type` | `form_submission` |
| `payload.form_type` | `podcast` |
| `subject_ref` | `site:podcast_request:<uuid>` |
| `source_slug` | `site-formulaire-podcast` |
| `universe` | `business` |

**Base CRM** : activité créée, tag `src:site-formulaire-podcast`. Le podcast est
une **vue par tag**, pas un type de relation : vérifier que `relation_type` ne
prend **aucune** valeur « podcast ».

**Contre-test** : rejeu → `noop_idempotent`.

---

### B.3 Simulateur de gains

**Geste** — parcourir le simulateur jusqu'au rapport, saisir l'adresse.

| Attendu | Valeur |
|---|---|
| `payload.form_type` | **`simulateur_roi`** |
| `subject_ref` | `site:submission:<uuid>` |
| `universe` | `business` |

🔴 **Ce scénario est obligatoire et non négociable** : `simulateur_roi` était
émis par le site et absent du CRM → 422 `unknown_form_type` → `gave_up` → **tous
les leads du simulateur définitivement perdus, en silence**. Le test de
non-régression existe des deux côtés ; ce parcours le confirme en conditions
réelles.

⚠️ Ne pas confondre avec `simulateur_roi_rappel`, qui est le champ d'une
notification interne et **n'est pas** une valeur de `CRM_FORM_TYPES`.

---

### B.4 Lettre d'information — opt-in et opt-out

**Geste (opt-in)** — s'inscrire, **puis cliquer le lien de confirmation reçu**.
L'événement n'est émis qu'à la **confirmation** du double opt-in, pas à
l'inscription.

| Attendu | Valeur |
|---|---|
| `event_type` | `newsletter_optin` |
| `subject_ref` | `site:newsletter_subscriber:<uuid>` |
| `universe` | `business` |

**Contre-test** : vérifier qu'une inscription **non confirmée** (`pending`)
n'écrit **aucune** ligne d'outbox. Le consentement, c'est la confirmation.

**Geste (opt-out)** — cliquer le lien de désinscription.

| Attendu | Valeur |
|---|---|
| `event_type` | `newsletter_optout` |
| `subject_ref` | le même `site:newsletter_subscriber:<uuid>` |

**Base CRM**

```sql
SELECT scope, email_hash IS NOT NULL AS hash_pose FROM opt_out ORDER BY id DESC LIMIT 5;
```

ATTENDU : `scope = 'business'`, `email_hash` renseigné, **email jamais en clair**.

**Contre-test d'étanchéité (essentiel)** : se désinscrire de la lettre
d'information **n'efface pas** une candidature et **ne crée aucune** opposition
de scope `vivier`.

```sql
SELECT scope, count(*) FROM opt_out GROUP BY 1;
-- ATTENDU : business = 1, vivier = 0
```

---

### B.5 Avis client

**Geste** — déposer un avis (formulaire public).

| Attendu | Valeur |
|---|---|
| `event_type` | `review_posted` |
| `subject_ref` | `site:customer_review:<uuid>` |
| `payload.rating` | la note |

**Base CRM** : l'auteur crée ou met à jour une fiche `relation_type = client` —
c'est le **seul** événement entrant du site qui porte cette qualité.
**Le CONTENU de l'avis reste sur le site** : vérifier qu'aucun texte d'avis
n'apparaît côté CRM.

**Contre-test** : modérer/republier l'avis ne doit pas créer une seconde activité.

---

### B.6 Calendly — booked / completed / canceled / no_show

Quatre chemins d'émission distincts, tous à jouer :

| Chemin | Geste | `event_type` |
|---|---|---|
| Route client (embed) | réserver un créneau depuis le site | `calendly_booked` |
| Découverte par l'API | déclencher la synchronisation Calendly | `calendly_booked` |
| Création en console | créer un rendez-vous depuis la console d'administration | `calendly_booked` |
| Changement de statut en console | passer le rendez-vous à réalisé / annulé / absent | `calendly_completed` / `calendly_canceled` / `calendly_no_show` |

`subject_ref` : `site:calendly_event:<id>` dans les quatre cas.
`source_slug` : `calendly`.

**Contre-test capital** : **ré-enregistrer le rendez-vous sans changer son
statut ne doit émettre AUCUN événement.** Une garde « vrai changement de statut »
existe précisément pour ça — sans elle, chaque réédition dupliquerait l'entrée
dans la timeline CRM.

**Base CRM** : quatre `kind` distincts dans `activities`, la même personne
(`person_key`), une seule fiche.

---

### B.7 Candidature à une offre — AVEC et SANS case vivier

**Pré-requis** : textes de consentement v2 servis (branche
`feat/crm-L4-consents`).

#### B.7.a — case vivier COCHÉE

**Geste** — `http://localhost:3000/fr/carrieres/<slug>/postuler`, remplir, cocher
la case **optionnelle** de conservation en vivier, envoyer.

**Base SITE**

```sql
SELECT id, "consentVersion", "vivierInfoSentAt", "vivierOpposedAt" FROM job_applications ORDER BY "submittedAt" DESC LIMIT 1;
SELECT form_ref, consent_version, action FROM consent_events ORDER BY occurred_at DESC LIMIT 4;
```

| Attendu | Valeur |
|---|---|
| `consentVersion` | **`careers-v2-2026-08-13`** |
| `consent_events` | **DEUX** lignes : `job-application-form` (étude) **et** `job-application-vivier` (conservation) — finalités DISTINCTES |
| outbox `event_type` | `application_submitted` |
| outbox `universe` | **`vivier`** |
| `subject_ref` | `site:job_application:<uuid>` |
| `source_slug` | `site-candidature-offre` |

**Base CRM**

```sql
SELECT c.first_name, c.last_name, c.relation_type, c.consent_version, c.consent_vivier_at, w.slug
FROM candidates c JOIN workspaces w ON w.id = c.workspace_id ORDER BY c.id DESC LIMIT 1;
```

| Attendu | Valeur |
|---|---|
| workspace | **`vivier-candidats`** exclusivement |
| `relation_type` | la famille de métier (`candidat_commercial` / `candidat_video` / `candidat_tech` / `candidat_autre`) |
| `consent_version` | `careers-v2-2026-08-13` |
| `consent_vivier_at` | **renseigné** |
| tags | `src:site-candidature-offre` + `cand-offre:<slug>` |
| `cv_ref` | une **référence**, jamais le fichier — le CV reste sur le disque du site |

#### B.7.b — case vivier DÉCOCHÉE

Mêmes gestes, case laissée décochée (état par défaut — **le vérifier
visuellement**).

| Attendu | Valeur |
|---|---|
| `consent_events` | **UNE seule** ligne : `job-application-form` |
| `consent_vivier_at` côté CRM | **NULL** |
| `hasVivierConsent()` | faux → la fiche ne peut pas être conservée en vivier au-delà du recrutement en cours |

🔴 C'est le contraste entre B.7.a et B.7.b qui prouve que la case est bien
optionnelle **et** que son effet est réel. Jouer les deux, dans cet ordre.

#### B.7.c — contre-test de la garde de consentement

Forger un appel `application_submitted` avec
`consent.version = "careers-v1-2026-06-09"` (Geste E du runbook).

**ATTENDU** : **422 `candidate_consent_v2_required`**, message
« consentement v2 requis (careers-v2-2026-08-13 | memo-v2-2026-08-13), reçu :
careers-v1-2026-06-09 », ligne d'outbox en `gave_up`, **aucune** fiche créée.

🔴 Si ce test renvoie 200, la garde ne mord pas : une candidature v1 (la version
des 71 candidatures du stock) vient d'entrer au vivier. C'est exactement ce que
la CNIL interdit.

#### B.7.d — contre-test de destination

Forger un appel candidat en tentant d'imposer un workspace business dans le
payload. **ATTENDU** : rejet (clé inconnue → 422). Le payload **ne peut pas**
porter la destination : la classification est une décision du CRM, sinon un
émetteur compromis choisirait l'univers d'atterrissage.

---

### B.8 Candidature commerciale (tunnel Mémo Isère)

**Geste** — `/fr/devenir-commercial-ia/candidature`, parcourir le tunnel jusqu'à
l'envoi.

⚠️ **Rectifié le 2026-08-17 (joué en production)** : le tunnel compte **un écran
d'accueil + 9 étapes**, et l'interface annonce « ÉTAPE n SUR 9 ». La rédaction
« 10 écrans du wizard » faisait chercher une dixième étape qui n'existe pas.

🔑 **La production est une voie d'essai valable.** Ce scénario a été joué sur
`https://axion-ia.com`, dans un vrai navigateur, alors que le banc local restait
hors d'atteinte. Purger la donnée de test ensuite (§4 du runbook) et relever les
comptages avant/après.

| Attendu | Valeur |
|---|---|
| `consentVersion` | **`memo-v2-2026-08-13`** |
| `consent_events` | `commercial-tunnel` (+ `commercial-tunnel-vivier` si la case est cochée) |
| outbox `event_type` | `application_submitted` |
| outbox `universe` | `vivier` |
| `subject_ref` | `site:submission:<uuid>` — **une candidature Mémo est une `Submission`**, pas une `JobApplication` |
| `source_slug` | `site-candidature-commerciale` |
| famille | `candidat_commercial`, `offer_slug = commercial-memo` |

🔴 **Rejouer le parcours COMPLET au Playwright**, écran par écran. C'est ainsi
qu'un blocage total du tunnel a été trouvé, que 116 tests unitaires ne voyaient
pas. Ne pas se contenter d'appeler l'action serveur.

**Contre-test** : reprendre le wizard depuis le `localStorage` après un
rechargement, puis envoyer → **une seule** candidature, **une seule** ligne
d'outbox.

---

### B.9 Chatbot — capture de lead

**Geste** — dialoguer avec le chatbot jusqu'à la capture de coordonnées, accepter
le consentement.

⛔ **INJOUABLE EN PRODUCTION (constaté le 2026-08-17)** — `GET
/api/chatbot/widget-config` rend `{"enabled":false}` : aucune bulle n'apparaît
sur le site. C'est un **coupe-circuit délibéré**, à deux verrous (env
`CHATBOT_ENABLED` **et** `ChatTenant.actif`), donc **pas un défaut**. Mais il
faut le savoir avant d'aller chercher un widget absent — et en tirer la
conséquence : **aucun lead chatbot n'est capté aujourd'hui**. L'allumer est une
décision de Will, pas un geste d'audit.

| Attendu | Valeur |
|---|---|
| `payload.form_type` | `autre` |
| `source_slug` | `chatbot` |
| `subject_ref` | `site:submission:<uuid>` |
| `universe` | `business` |

🔴 **Particularité unique** : le chatbot est le **seul** émetteur transactionnel
— la ligne d'outbox est écrite DANS la transaction de la soumission. Tous les
autres émettent après commit, délibérément (un échec d'outbox ne doit jamais
faire perdre un lead).

**Contre-test** : provoquer un échec APRÈS l'écriture de l'outbox et vérifier que
soumission ET ligne d'outbox disparaissent ensemble (atomicité), sans laisser de
ligne orpheline qui pousserait un lead inexistant.

---

### B.10 Opposition vivier (un clic, sans connexion)

**Geste** — depuis le courriel d'information au stock, **cliquer** le lien
d'opposition. La route est en **`GET`** (un lien dans un client de messagerie),
sans connexion.

⛔ **Ce que ce scénario exige, et qui n'était pas écrit (constaté le
2026-08-17)** : le jeton est un HMAC signé avec l'`AUTH_SECRET` **du site**,
portant l'identifiant d'une `job_applications`. Le jouer suppose donc **l'un des
trois** : accès à la base du site, accès à son secret de signature, ou accès à la
boîte aux lettres qui reçoit le courriel. Aucun des trois n'est fourni par le §A.
Même remarque pour §B.11 et §B.12 (jeton HMAC du parcours self-service RGPD).

⚠️ Une candidature du tunnel Mémo (§B.8) ne convient PAS comme point de départ :
elle crée une `Submission`, pas une `JobApplication`.

**Base SITE**

```sql
SELECT id, email, "vivierInfoSentAt", "vivierOpposedAt" FROM job_applications WHERE email = '<zz test>';
SELECT form_ref, action, consent_version FROM consent_events ORDER BY occurred_at DESC LIMIT 2;
```

| Attendu | Valeur |
|---|---|
| `vivierOpposedAt` | renseigné, effet immédiat |
| `consent_events` | une ligne `vivier-opposition`, `action = 'optout'` |
| outbox | `event_type = 'opt_out'`, **`universe` forcé à `vivier`**, `payload.scope = 'vivier'` |
| `subject_ref` | `site:job_application:<uuid>` |

**Base CRM**

```sql
SELECT scope, email_hash IS NOT NULL FROM opt_out ORDER BY id DESC LIMIT 3;
```

ATTENDU : `scope = 'vivier'` (**pas** `business`), `email_hash` posé, email jamais
en clair.

**Contre-tests, tous obligatoires**

1. **Anti-réinsertion** : rejouer une candidature de la même adresse → elle ne
   doit **PAS** réapparaître au vivier. L'opposition prime.
2. **Étanchéité inverse** : l'opposition vivier **ne désinscrit pas** de la lettre
   d'information (`opt_out.scope = 'business'` reste absent).
3. **Second clic** : re-cliquer le lien → idempotent, pas d'erreur, pas de
   seconde ligne.
4. **Jeton falsifié** : modifier un caractère du jeton → refus.
5. **Pré-chargement** : le lien est en `GET` ; vérifier que le comportement reste
   correct si un client de messagerie pré-charge l'URL (le geste doit rester
   explicite ou, au minimum, idempotent et journalisé).

---

### B.11 RGPD art. 15 — export

**Geste** — parcours self-service d'export sur le site (jeton HMAC), jusqu'à la
remise du fichier.

🔴 **Ce chemin ne passe PAS par l'outbox** : le site appelle directement
`POST /api/internal/site-sync/gdpr` (`action: "export"`). Ne pas chercher de
ligne dans `crm_sync_outbox`.

**Attendus**

- réponse agrégée : contenu du SITE **plus** contenu du CRM, dans un seul
  document remis à la personne ;
- côté CRM, la recherche se fait par **`person_key` ET par `email`** — les
  deux, pas l'un ou l'autre : les fiches nées de la collecte n'ont pas de
  `person_key` (le sel vit côté site), et sans la recherche par email elles
  seraient invisibles ;
- une ligne dans `rgpd_requests` **par workspace** concerné (journal
  d'exécution), avec sa chaîne d'audit.

**Contre-test (défaut réel déjà rencontré)** : vérifier que l'export **n'est pas
VIDE** alors que la réponse annonce « succès ». Créer d'abord des données dans
les deux univers, puis compter les éléments du document remis.

```sql
SELECT type, status, subject_email, requested_at FROM rgpd_requests ORDER BY id DESC LIMIT 5;
```

---

### B.12 RGPD art. 17 — effacement

**Geste** — parcours self-service d'effacement, jusqu'à confirmation.

**Attendus**

- effacement **bi-univers** (business ET vivier), **timeline comprise** ;
- inscription de l'**empreinte** de l'adresse en opposition, par univers, **sans
  l'email en clair** ;
- côté site : les enregistrements source sont effacés ou anonymisés, CV compris ;
- `rgpd_requests` journalise l'exécution.

**Contre-tests**

1. **L'effacement efface vraiment** (défaut réel : « zéro ligne supprimée » avec
   réponse « succès »). Compter AVANT et APRÈS, dans les deux bases.
   ```sql
   SELECT count(*) FROM candidates WHERE email = '<zz test>';   -- ATTENDU après : 0
   SELECT count(*) FROM activities WHERE person_key = '<hash>'; -- ATTENDU après : 0
   ```
2. **Anti-réinsertion** : re-soumettre un formulaire avec la même adresse → la
   fiche ne renaît pas dans l'univers effacé (opposition par empreinte).
3. **Portée respectée** : un effacement de scope `vivier` ne touche **pas** les
   fiches business, et réciproquement.

---

## §C. E2E n°1 — parcours transverses

Ces scénarios ne portent pas sur un type d'événement mais sur le système entier.

### C.1 Même personne, trois chemins

La même adresse est : **scrapée** (funnel de collecte), puis **candidate**, puis
**prospecte** (formulaire de devis).

**ATTENDU** : **deux fiches, une par univers, JAMAIS fusionnées**, reliées par
`person_key`. La console peut afficher « existe aussi dans l'autre univers :
oui », **jamais le contenu**.

```sql
SELECT 'contact' AS t, count(*) FROM contacts   WHERE person_key = '<hash>'
UNION ALL
SELECT 'candidat', count(*) FROM candidates WHERE person_key = '<hash>';
```

### C.2 Doublon SIREN multi-sources

Le même SIREN arrive par le funnel de collecte ET par un formulaire.
**ATTENDU** : **une** fiche `companies` (contrainte `UNIQUE (workspace_id,
siren)`), enrichie ; la règle « le DÉCLARÉ gagne » s'applique et
`field_origins` porte la trace de l'origine de chaque champ. Le collecté
n'écrase JAMAIS un champ déclaré.

### C.3 Reclassement journalisé

Depuis la console, reclasser un contact deux fois de suite.
**ATTENDU** : un journal complet des deux changements (qui, quand, de quoi vers
quoi). Le franchissement de la frontière candidat ↔ business exige une **action
explicite**, réservée aux rôles admin/owner, et n'est **jamais** possible en
masse.

### C.4 Étanchéité par les humains

Un compte membre du seul workspace `vivier-candidats` :
- ne voit **aucune** table business (l'accès doit ÉCHOUER, pas rendre une liste
  vide) ;
- ne voit pas les colonnes de coordonnées complètes en liste s'il est `viewer`
  (affichage masqué `p***@domaine.fr`) ;
- n'a pas accès à l'export CSV (réservé admin).

**Faire ROUGIR d'abord** : réintroduire le défaut (rendre la policy permissive)
et constater que le test échoue, puis rétablir. Une garde ne vaut que si elle
rougit.

### C.5 Volumes

Liste de **100 000+ lignes** : défilement sans gel visible (trace Playwright ou
capture vidéo) **et** p95 des requêtes de liste **< 500 ms** côté serveur.
Au besoin, gonfler la base locale par un seed.

### C.6 Cohérence des compteurs

Chaque compteur de la barre latérale et du tableau de bord est **égal** au
`COUNT` SQL de contrôle correspondant. **Écart toléré : 0.**

### C.7 Observabilité

- Couper volontairement la synchro → l'alerte Telegram `CRM_SYNC_ALERT` doit
  partir (voir §D).
- La rétablir → signal de vie visible.
- Le batch de réconciliation (quotidien, 04:30) produit un résumé même quand
  tout va bien : **l'absence de nouvelles doit être visible, pas supposée**.

---

## §D. Pannes simulées

### D.1 Le CRM tombe pendant une soumission

**Geste**

```bash
docker compose -f /c/Users/willi/Documents/Projets/Axion-CRM-Pro/docker-compose.yml stop caddy api
# → soumettre un formulaire sur le site
```

**Attendus, dans l'ordre**

1. **Le parcours utilisateur aboutit normalement.** Aucune erreur affichée,
   aucun blocage. Un CRM en panne ne casse jamais un formulaire.
2. La ligne d'outbox existe, `status = 'pending'` puis `'failed'`,
   `last_error = 'network'`, `attempts = 1`, `next_attempt_at` ≈ +1 min.
3. Le backoff suit : 1, 2, 4, 8, 16, 32, 64 minutes, puis plafond de 6 heures.
4. Redémarrer le CRM (`docker compose start api caddy`) et relancer le balayage
   → la ligne passe `sent`, **sans perte ni doublon**.

```sql
SELECT status, attempts, last_error, next_attempt_at FROM crm_sync_outbox ORDER BY created_at DESC LIMIT 3;
```

**Contre-test du plafond** : forcer 8 échecs consécutifs → la ligne passe
`gave_up` et **une alerte `CRM_SYNC_ALERT` de type `gave_up` part**.

### D.2 Le CRM répond 503 (drapeau d'ingestion fermé)

**Geste** : `CRM_INGEST_ENABLED=false` côté CRM, puis soumettre.

**Attendu — le comportement le plus important de tout le dispositif** :

- `status = 'failed'`, `response_status = 503`, **`attempts` INCHANGÉ** ;
- la ligne **n'atteint jamais** `gave_up`, même après des dizaines de passages ;
- rouvrir le drapeau → la ligne part au balayage suivant.

C'est ce qui garantit que tout ce qui s'accumule pendant la phase inerte est
délivré le jour de la bascule (runbook, étape 7). **Si `attempts` s'incrémente
sur un 503, c'est un défaut bloquant.**

### D.3 Le CRM répond 422 (refus définitif)

**Geste** : émettre un `form_type` inconnu, ou une candidature en consentement v1.

**Attendus**

- `status = 'gave_up'` **immédiatement**, sans consommer de tentative ;
- `last_error` porte le code d'erreur du CRM ;
- une alerte `CRM_SYNC_ALERT` de type `gave_up` part ;
- la ligne reste visible pour traitement humain (payload intact, rejouable après
  correction du contrat).

**Contre-test** : corriger le contrat, remettre la ligne en `pending`, relancer
→ elle passe `sent`. Rien n'a été perdu.

### D.4 Secret faux (401)

**Geste** : modifier `SITE_SYNC_HMAC_SECRET` d'un seul côté, puis soumettre.

**Attendus**

- `response_status = 401`, `last_error` mentionne `bad_signature` ;
- `attempts` **s'incrémente** (un 401 est un vrai échec, contrairement au 503) ;
- après 8 tentatives → `gave_up` + alerte.

**Variante `stale_signature`** : signer avec un horodatage vieux de 10 minutes
(fenêtre de tolérance : 300 s) → **401 `stale_signature`**.

**Variante des en-têtes croisés** : envoyer `X-Crm-Timestamp` /
`X-Crm-Signature` sur l'endpoint d'ingestion (au lieu de `X-Site-*`)
→ **401 `bad_signature`**. Les deux sens ont des jeux d'en-têtes **différents** ;
les intervertir est une erreur facile.

### D.5 Backlog

**Geste** : CRM arrêté, produire **51 événements**.

**Attendu** : une alerte `CRM_SYNC_ALERT` de type `backlog`. Le seuil est
**strictement supérieur à 50** — à exactement 50, **aucune alerte** (comportement
volontaire, testé). Anti-bruit : **une** alerte par heure et par type au maximum.

### D.6 Réconciliation

**Geste** : créer un enregistrement source **sans** sa ligne d'outbox (insertion
directe en base, pour simuler un événement perdu), puis déclencher le batch.

**Attendus** : alerte `reconcile_gap`, l'écart est nommé, et le rejeu rattrape.
Provoquer ensuite une exception dans le batch → alerte `reconcile_failed`.

### D.7 Convergence CRM → site

**Geste** : poser une opposition depuis la console CRM, attendre le passage de
`crm:flush-outbound` (toutes les 5 minutes) ou le forcer.

**Attendus**

- la ligne `crm_outbound_events` passe `pending` → `sent` ;
- côté site, l'opposition est appliquée (`newsletter_subscribers.status =
  'unsubscribed'`, opposition locale) ;
- **anti-boucle** : l'événement porte `origin = 'crm'` et le site ne le ré-émet
  **jamais** vers le CRM. Vérifier qu'aucune ligne `crm_sync_outbox` n'est née de
  cette propagation ;
- **rejeu** : rejouer le même `event_id` → `duplicate`, aucun double effet ;
- **site en 503** → la ligne CRM est différée **sans consommer de tentative**
  (symétrie exacte du sens aller).

---

## §E. E2E n°2 — checklist « regard neuf »

> **Exécuté par un agent DIFFÉRENT de celui du n°1.** Contrainte cardinale : il
> ne rejoue pas les gestes de mémoire, il **suit uniquement le runbook et les
> README**. Tout ce qu'il ne trouve pas dans la documentation est un DÉFAUT de
> documentation, à consigner — pas une chose à deviner.

### E.1 Règles du n°2

1. **Aucune connaissance préalable mobilisée.** Si le runbook ne dit pas où
   cliquer, c'est le runbook qu'il faut corriger.
2. **Montage de l'environnement à partir du seul §A** de ce document. Chronométrer :
   si le montage dépasse une heure, la documentation est insuffisante.
3. **Chaque écran de console donne lieu à une capture REGARDÉE**, en desktop
   1440 px **et** en mobile < 1024 px (viewport réel de Will). Un rendu n'est
   jamais déclaré bon sans avoir été vu.
4. **Toute divergence entre le runbook et la réalité est un défaut**, même
   mineure (un nom de commande, un chemin, un code de réponse).

### E.2 Parcours à rejouer intégralement

Les mêmes que §B.1 à B.12, dans le même ordre, avec leurs contre-tests.
Cocher un à un :

- [ ] B.1 Formulaire unifié — les 12 types, dont `recrutement` → univers vivier
- [ ] B.2 Podcast
- [ ] B.3 Simulateur de gains (`simulateur_roi`)
- [ ] B.4 Lettre d'information — opt-in confirmé, opt-out, étanchéité
- [ ] B.5 Avis client (contenu non transféré)
- [ ] B.6 Calendly — 4 chemins + garde « vrai changement de statut »
- [ ] B.7 Candidature offre — AVEC vivier, SANS vivier, garde v1 → 422
- [ ] B.8 Candidature commerciale (wizard complet au Playwright)
- [ ] B.9 Chatbot (émetteur transactionnel)
- [ ] B.10 Opposition vivier + anti-réinsertion
- [ ] B.11 RGPD art. 15 (export NON vide)
- [ ] B.12 RGPD art. 17 (effacement RÉEL, bi-univers)

### E.3 Croisements complémentaires propres au n°2

- [ ] **Même personne, trois chemins** (scrapée → candidate → prospecte) : trois
      parcours, fiches correctes et étanches (§C.1)
- [ ] **Doublon SIREN multi-sources** (§C.2)
- [ ] **Opposition puis re-scrape** : anti-réinsertion (§B.10, contre-test 1)
- [ ] **Contact reclassé deux fois** : journal complet (§C.3)
- [ ] **Volumes** : liste 100 000+ fluide, requêtes < 500 ms (§C.5)
- [ ] **Cohérence des compteurs** : écart 0 partout (§C.6)
- [ ] **Pannes simulées** : les sept cas du §D
- [ ] **Mobile** : tous les écrans de console < 1024 px

### E.4 Captures exigées, écran par écran

Pour **chacun** de ces écrans : une capture desktop 1440 **et** une capture
mobile, toutes deux REGARDÉES, archivées avec le journal.

| # | Écran | Ce qu'on vérifie de ses yeux |
|---|---|---|
| 1 | Console CRM — espace Business | 3 espaces distincts, compteurs cohérents |
| 2 | Console CRM — espace Vivier | séparation visuelle nette, aucun contact business visible |
| 3 | Console CRM — base froide | volumétrie affichée, défilement fluide |
| 4 | Console CRM — fiche 360° | identité, consentements (version + date), tags, étape, timeline |
| 5 | Console CRM — file d'arbitrage | les `pending_match` sont listés et rattachables |
| 6 | Console CRM — actions de masse | aucun reclassement de frontière proposé en masse |
| 7 | Console CRM — recherche globale | résultats sectionnés, aucune fuite hors workspace |
| 8 | Console SITE — carte « Synchro CRM » | dernier succès, backlog, échecs 24 h, bouton « rejouer » |
| 9 | Console SITE — lignes en erreur | payload visible, rejeu fonctionnel |
| 10 | Site — page de candidature à une offre | case vivier optionnelle **décochée**, texte v2 mot à mot |
| 11 | Site — wizard Mémo, écran de consentement | idem, version `memo-v2-2026-08-13` |
| 12 | Courriel `vivier-information` (rendu réel) | texte du plan §2.3 mot à mot, lien d'opposition cliquable |
| 13 | Page de confirmation d'opposition vivier | effet immédiat, message clair, sans connexion |

### E.5 Livrable du n°2

Un compte rendu qui donne, pour chaque case : **vert / rouge**, la capture
associée, et pour chaque rouge : la commande exacte jouée, la sortie obtenue, la
sortie attendue.

---

## §F. Critères de sortie

L'ensemble est déclaré prêt quand, et seulement quand :

1. **E2E n°1** : tous les scénarios §B, §C et §D verts, sorties réelles à
   l'appui (jamais un « ça devrait marcher »).
2. **E2E n°2** : toutes les cases §E cochées par un agent différent, captures
   archivées et REGARDÉES.
3. **Preuve par la rougeur** faite pour chaque garde critique : garde de
   consentement v2, étanchéité vivier/business, anti-réinsertion, idempotence,
   « le froid ne se réchauffe pas ». Une garde qui n'a jamais rougi n'est pas
   une garde.
4. **Aucune ligne `gave_up`** non expliquée dans l'outbox locale.
5. **Compteurs** : écart 0 entre l'affichage et le `COUNT` SQL de contrôle.
6. **Performance** : liste 100 000+ fluide, p95 < 500 ms.
7. Le journal `_SESSIONS/2026-08-13_AUTOPILOT-CRM-journal.md` porte le compte
   rendu des deux campagnes, avec les identifiants des données de test créées.

Alors, et alors seulement, ouvrir
`_PLANS/2026-08-14_RUNBOOK-ACTIVATION-CRM.md` à l'étape 1.

---

## §G. [À VÉRIFIER]

1. ✅ **TRANCHÉ (2026-08-17) — Node ne résout PAS `api.localhost`.** Mesuré :
   `ENOTFOUND` côté Node, `200` côté curl. L'entrée `hosts` exige une élévation
   administrateur (refus constaté), donc la voie retenue est bien celle qui
   avait été écartée : **publier un port pour `api`** — mais dans un fichier
   Compose SÉPARÉ (`docker-compose.local.yml`), jamais chargé en production.
   Voir §A.2.0. Bénéfice supplémentaire : `NODE_TLS_REJECT_UNAUTHORIZED=0`
   disparaît.

2. ✅ **TRANCHÉ (2026-08-17)** — le fichier est **`.env.local`** (il n'y a pas
   de `.env` dans le worktree), et il ne porte **aucune** des cinq variables du
   §A.2.3. Plus important : la base qu'il désigne (`axion_ia_dev` sur
   `localhost:5433`) **n'existe pas**, ni le rôle `axion_ia`. Création
   documentée au §A.2.1.

3. **[À VÉRIFIER] Déclencheur de la campagne d'information au stock** :
   `sendVivierInformationBatch()` n'a aujourd'hui **aucun appelant hors tests**.
   Le scénario B.10 suppose qu'un courriel a été envoyé : en local, l'appeler par
   un script de test dédié, en passant un `windowDays` explicite pour raccourcir
   la fenêtre **du test seulement** — jamais en modifiant
   `VIVIER_OPPOSITION_WINDOW_DAYS`.

4. **[À VÉRIFIER] Seed de volumétrie pour le critère « 100 000+ lignes »** : la
   base CRM locale porte déjà un volume important si elle a été restaurée, mais
   le vivier est vide. Prévoir un seed dédié pour le scénario C.5 côté vivier.

5. **[À VÉRIFIER] Chemin exact des pages de candidature en local** :
   `/fr/carrieres/<slug>/postuler` suppose l'existence d'une offre publiée dans
   la base locale. En créer une (ou en publier une) avant de jouer B.7.

6. **[À VÉRIFIER] `SITE_CRM_WEBHOOK_URL` en local** : la valeur proposée
   (`http://host.docker.internal:3000/api/internal/crm-webhook`) suppose que le
   conteneur `scheduler` du CRM peut joindre l'hôte Windows par
   `host.docker.internal`. À confirmer par un `curl` depuis le conteneur avant de
   jouer le scénario D.7.

7. **[À VÉRIFIER] Le tableau de bord « Synchro CRM » et le webhook entrant
   n'existent que sur la branche site `feat/crm-L5-observabilite`** ; les
   consentements v2, le registre `consent_events` et le vivier n'existent que sur
   `feat/crm-L4-consents` ; la console v2 n'existe que sur la branche CRM
   `feat/crm-L6-console` (non commitée au 2026-08-14). Les scénarios B.7, B.8,
   B.10 à B.12, C.3, C.4, C.6, D.5 à D.7 et les captures 1 à 9 et 10 à 13
   supposent donc un environnement local où **ces branches sont fusionnées
   ensemble**. Le montage du §A doit en tenir compte : construire une branche
   d'intégration locale, ou jouer les scénarios par lots.
