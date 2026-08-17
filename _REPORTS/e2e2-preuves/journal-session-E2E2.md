# JOURNAL DE SESSION — E2E n°2 « regard neuf » + clôture du plan CRM Pro

> Ouvert le 2026-08-17 à 03:19 UTC. Écrit **au fil de l'eau** (les sessions
> précédentes ont été coupées sans préavis).
>
> **Contrainte cardinale respectée** : `_SESSIONS/2026-08-13_AUTOPILOT-CRM-journal.md`
> N'A PAS été ouvert avant la fin du §E. Toute connaissance mobilisée ici vient
> uniquement de :
> - `_PLANS/2026-08-14_SCENARIOS-E2E-CRM.md` (§A montage, §B parcours, §E, §F)
> - `_PLANS/2026-08-14_RUNBOOK-ACTIVATION-CRM.md`
> - les README des deux dépôts

## Livrables attendus

1. **E2E n°2** — checklist §E intégrale, montage chronométré depuis le seul §A.
2. **Tests `AudienceBuilderService`** — combinateur `not`, `tags`/`contains_any`,
   chemin `Bus::batch` (> 5 000 fiches).
3. **Rapport de clôture** `_REPORTS/` — « PRODUCTION READY » ou l'inverse, mais
   nommant tous les écarts.

---

## CHRONOMÈTRE DU MONTAGE (§E.1.2 — au-delà d'1 h, la doc est insuffisante)

| Instant | Horodatage UTC | Écoulé |
|---|---|---|
| **T0 — début du montage** | 2026-08-17 03:19:17 | — |
| Pile CRM complète (8 conteneurs) | 2026-08-17 03:23:58 | **4 min 41 s** |
| Base recréée + 53 migrations + 3 seeders | 2026-08-17 03:28:00 | ~9 min |

---

## Fil de l'eau

### 03:19 — Démarrage

- `docker --version` → 29.4.3 ; `docker compose version` → v5.1.3. OK.
- `docker ps` avant montage : `axion-crm-postgres` déjà **Up 7 hours (healthy)**.
  Les autres services du CRM (caddy, api, horizon, scheduler, app, redis) sont
  **absents**. Le §A.1 suppose une pile éteinte ; ici elle est partielle.
- Dépôt CRM sur `main`, arbre propre (1 fichier non suivi dans `_AUDIT/`).
- `docker compose up -d` lancé (§A.1, commande exacte). Pile complète en 4 min 41 s.

---

## DÉFAUTS DE DOCUMENTATION — relevés au fil du montage

> §E.1.4 : « Toute divergence entre le runbook et la réalité est un défaut, même
> mineure (un nom de commande, un chemin, un code de réponse). »

### D-01 — §A.2 : le worktree nommé n'existe pas (chemin faux)

Le §A.2 ouvre par :

```bash
cd /c/Users/willi/Documents/Projets/Axion-IA/axionia-wt-crm-e2e   # ou le worktree de travail
```

Ce répertoire **n'existe pas**. Le répertoire réel est `axionia-wt-e2e-crm`
(segments `crm` et `e2e` **inversés**). Pire : ce répertoire réel n'est **plus un
worktree Git** (`git rev-parse` → `not a git repository`) ; il ne subsiste que
comme dossier orphelin contenant un `node_modules` et un `playwright.config.ts`.
Idem pour `axionia-wt-crm-l4` et `axionia-wt-crm-l5obs`, également orphelins.

**Effet sur un regard neuf** : la toute première commande du §A.2 échoue, et le
répertoire de repli qu'on trouve à tâtons est un faux worktree dans lequel aucune
commande `git` ne fonctionne.

**Correction à porter** : nommer le worktree réel, et préciser qu'il doit être
(re)créé par `git worktree add` — un dossier survivant à un `git worktree prune`
n'est pas un environnement de travail.

### D-02 — §A.1 : la commande de migration échoue sur une base locale existante

Commande du §A.1, jouée mot pour mot :

```
docker compose exec -T api php artisan migrate --force --database=pgsql_owner </dev/null
```

Sortie réelle :

```
2026_05_17_000011_setup_pg_partman_audit_logs ................ 262.33ms FAIL
SQLSTATE[23514]: Check violation: 7 ERROR:  no partition of relation "audit_logs"
found for row
DETAIL:  Partition key of the failing row contains (created_at) = (2026-08-14 14:14:02+00).
```

État de la base locale au moment du montage : **12 migrations appliquées** (l'état
de mai), 45 `companies`, 1 `contact`, **1 ligne `audit_logs` datée du 2026-08-14**.
Cette unique ligne suffit à bloquer la conversion de `audit_logs` en table
partitionnée, donc **toute la suite du §A**.

Le §A.3 « pièges de montage » traite le cas `migrate:fresh` / `RefreshDatabase`
sur la base de **test** (piège 3) — il ne dit **rien** du cas, bien plus probable,
d'une base de **développement** laissée dans un état intermédiaire.

**Contournement appliqué** (non documenté, donc consigné comme tel) : sauvegarde
`pg_dump -Fc` de la base avant tout (316 837 octets, dans le scratchpad de
session), puis `DROP DATABASE` / `CREATE DATABASE`, puis rejeu de la commande du
§A.1 → 53 migrations, 101 tables. **Ce geste destructeur devrait figurer au §A.**

### D-03 — Toute commande artisan en local crache une trace d'erreur fatale

Après une migration **réussie**, la sortie se termine par ~25 lignes de pile
d'appel :

```
[previous exception] PDOException(code: 42P01): SQLSTATE[42P01]: Undefined table: 7
ERROR:  relation "telescope_entries" does not exist
```

Cause : Telescope est actif par défaut en environnement `local`, **aucune
migration Telescope n'existe dans `backend/database/migrations/`** (vérifié :
`ls | grep -i telescope` → aucun résultat) et **aucune clé `TELESCOPE_ENABLED`
n'est posée dans le `.env`**.

**Effet sur un regard neuf** : on croit que la migration a échoué. J'ai moi-même
dû requêter `SELECT count(*) FROM migrations` pour établir que la commande avait
en réalité réussi. Une trace de 25 lignes après un succès est un signal faux, et
elle **masquerait une vraie erreur** survenant au même endroit.

### D-04 — §A.1 : le seeder prescrit ne pose RIEN (workspace business absent) 🔴

Le §A.1 prescrit exactement deux gestes de préparation :

```
php artisan migrate --force --database=pgsql_owner
php artisan db:seed --class=GovernedTagsSeeder --force
```

Joués à la lettre sur une base propre, le résultat est :

- workspaces : **`vivier-candidats` seul** ;
- tags : **14** — et **aucun** `src:site-formulaire-*`.

Or `GovernedTagsSeeder::run()` pose les tags business par une boucle
`foreach ($businessIds as $workspaceId)` sur
`workspaces WHERE slug != 'vivier-candidats'` : **la liste est vide**, la boucle
ne s'exécute pas, aucun tag business n'est créé. Le workspace business `axion-ia`
est créé par `OwnerUserSeeder`, qui **n'est mentionné nulle part au §A**.

**Conséquence directe sur les scénarios** : le §B.1 exige de vérifier le tag
`src:site-formulaire-<type>` pour chacun des 12 types, et le §B.2 le tag
`src:site-formulaire-podcast`. En suivant le §A **à la lettre**, ces tags
n'existent pas et **tout le §B.1 serait rouge pour une raison de montage**, pas
pour un défaut du produit. C'est précisément le mode de panne que le §A.3 dit
vouloir éviter (« à lire AVANT de conclure à un bug ») — et ce cas-ci n'y figure
pas.

**Correction à porter au §A.1** : intercaler, avant `GovernedTagsSeeder` :

```
php artisan db:seed --class=PermissionsAndRolesSeeder --force
php artisan db:seed --class=OwnerUserSeeder --force
```

Après ces deux seeders, les **14** tags `src:site-formulaire-*` apparaissent
(`audit, autre, devis, formation, implementation, investisseur, partenariat,
podcast, presse, recrutement, simulateur-roi, speaker, support-client, un-a-un`)
— soit exactement les 14 valeurs de `CRM_FORM_TYPES` que le contre-test « frontière
du contrat » du §B.1 demande de faire accepter une à une.

### D-05 — `docker compose restart` ne recharge PAS `env_file` 🔴 (touche AUSSI la production)

Le §A.1 pose douze clés dans le `.env`, puis prescrit :

```
docker compose exec -T api php artisan config:clear
docker compose restart api horizon scheduler
```

Mesuré, dans cet ordre exact :

```
# après `docker compose restart api`
$ docker compose exec -T api printenv CRM_INGEST_ENABLED
(ABSENTE)

# après `docker compose up -d api`
$ docker compose exec -T api printenv CRM_INGEST_ENABLED
true
```

`docker-compose.yml` déclare `env_file: .env` pour `api`. `restart` relance le
processus **dans le conteneur existant**, dont l'environnement a été figé à sa
création : une clé ajoutée au `.env` n'y entre jamais. Seul `up -d` le recrée.

🔴 **Le même geste figure dans le runbook de production** (§2, Geste A, étapes 4
et 5), où l'étape 5 est présentée comme :

> « 5. PREUVE que le conteneur voit la variable (**le seul contrôle qui ne
> ment pas**) »

Ce contrôle, joué après un `restart`, revient **vide**. La séquence d'activation
en 14 étapes, telle qu'elle est écrite, ne pose donc **aucun** drapeau — et son
propre contrôle de preuve le dirait, si on le lisait. C'est le défaut le plus
lourd de conséquences trouvé pendant ce montage, parce qu'il ne concerne pas le
local mais la production.

**Correction** : `docker compose up -d api horizon scheduler` au lieu de
`docker compose restart …`, au §A.1 **et** dans le Geste A du runbook.

### D-06 — deux fichiers `.env` pour un seul service

`docker-compose.yml` monte `./backend:/var/www/html` et déclare
`env_file: .env` (racine du dépôt). Or **`backend/.env` existe aussi**
(7 345 octets, daté du 2026-08-14), et c'est lui que Laravel lit comme
`base_path('.env')`. Deux sources pour une même configuration : l'environnement
du processus (racine) l'emporte sur les clés communes, mais toute clé présente
seulement dans `backend/.env` est lue là. Aucun des deux documents ne mentionne
ce second fichier.

### D-07 — le §A exige un geste à privilèges administrateur, sans le dire

Le §A.3 piège 1 et le §G.1 posent la question de la résolution de `api.localhost`
par Node. **Réponse mesurée : Node ne la résout PAS.**

```
$ node -e "require('dns').lookup('api.localhost',(e,a)=>console.log(e?e.code:a))"
ENOTFOUND
$ curl -sSk -o /dev/null -w '%{http_code}' https://api.localhost/up
200          ← curl, lui, la résout
```

Le remède proposé (deux lignes dans `C:\Windows\System32\drivers\etc\hosts`)
exige une **élévation administrateur** :

```
ECHEC: L accès au chemin d accès 'C:\WINDOWS\System32\drivers\etc\hosts' est refusé.
```

Le §G.1 écarte explicitement l'alternative (publier un port pour `api`) au motif
qu'elle « modifierait le `docker-compose.yml` ». Il ne reste donc **aucun** chemin
praticable sans élévation : sur ce point le §A est **inexécutable** par un
opérateur non-administrateur — ni par l'autopilote.

**Ce que ça bloque** : toute émission du SITE vers le CRM, donc la totalité des
§B joués « par le geste », le §B.0 exigeant le parcours navigateur réel.

### D-08 — l'attendu du §B.7.c est PÉRIMÉ

Le §B.7.c annonce :

> « consentement v2 requis (careers-v2-2026-08-13 | memo-v2-2026-08-13), reçu : … »

Message réellement rendu :

> « consentement v2 requis (careers-v2-2026-08-13 | memo-v2-2026-08-13 |
> **vivier-stock-2026-08-14**), reçu : careers-v1-2026-06-09. »

Une **troisième** version a été ajoutée à
`Taxonomy::CANDIDATE_CONSENT_VERSIONS_V2` sans mise à jour du scénario. La garde
fonctionne ; c'est l'attendu écrit qui est faux.

### D-09 — la suite de tests, lancée comme le Makefile la documente, vise la base de DÉVELOPPEMENT et tente de la vider 🔴

`make test-backend` → `docker exec axion-crm-api composer test`.

```
$ docker compose exec -T api php artisan test --filter=AudienceBuilderService
SQLSTATE[42501]: Insufficient privilege: ERROR: must be owner of table activities
(… SQL: drop table "public"."activities", "public"."companies", … cascade)
Connection: pgsql, Database: axion_crm      ← la base de DÉVELOPPEMENT
```

Cause : `backend/phpunit.xml` ligne 33 pose
`<env name="DB_DATABASE" value="axion_crm_test"/>` **sans `force="true"`**,
contrairement aux lignes 27 et 31 (`APP_ENV`, `CACHE_STORE`) qui l'ont. Un
`<env>` sans `force` **n'écrase pas** une variable d'environnement déjà posée — et
`env_file: .env` en pose une : `DB_DATABASE=axion_crm`.

🔴 **Ce qui a sauvé la base** : le drapeau `CRM_DB_APP_ROLE_ENABLED=true` que le
§A.1 fait poser. Laravel se connecte alors comme `axion_app`
(`NOSUPERUSER NOBYPASSRLS`, non-propriétaire) et le `DROP TABLE` échoue. Avec la
valeur par défaut (`false`) — c'est-à-dire l'état de la production jusqu'à
l'étape 1 du runbook — la connexion se fait comme `axion`, **SUPERUSER**, et le
`DROP` réussit.

En CI le défaut ne se voit pas : le conteneur d'actions ne charge pas ce `.env`,
donc `phpunit.xml` fait autorité. **Le défaut n'existe que sur le poste de
travail** — c'est-à-dire exactement là où se trouvent des données qu'on n'a pas
envie de perdre.

Contournement employé pour toute la session (`pest.sh`) : forcer
`-e DB_DATABASE=axion_crm_test` et neutraliser les drapeaux fuités.

Effet de bord du même défaut : le test `valeurs par défaut des drapeaux : tout
est fermé` — une garde d'inertie — répond selon le `.env` du poste et non selon
le code livré, puisqu'il relit `config/crm.php`, qui lit `env()`.

### D-10 — aucun moyen documenté de se connecter à la console

Le §E.4 exige **neuf** captures d'écrans de console. Ni le §A, ni le runbook, ni
les README n'indiquent d'identifiant. Le mot de passe est en réalité **généré au
seed**, dans le conteneur :

```
storage/app/private/seeders/owner-initial-password.txt
```

Chemin trouvé en lisant le code de `OwnerUserSeeder`. Un regard neuf qui s'en
tient à la documentation ne peut pas ouvrir la console.

### D-11 — la console ne PEUT PAS s'authentifier en local, tel que le §A la monte 🔴

Après avoir levé D-10 :

```
POST /api/v1/auth/login      → 200   (mot de passe bon, Hash::check → true)
GET  /api/v1/config/features → 401
```

Cause, lue dans l'en-tête de réponse :

```
Set-Cookie: axion_crm_session=… ; domain=.localhost ; httponly ; samesite=lax
```

`localhost` est un domaine de premier niveau : **les navigateurs refusent un
cookie portant `Domain=.localhost`**. Le cookie de session n'est donc jamais
stocké, et aucune requête authentifiée n'aboutit.

Contre-épreuve faite : en repassant le cookie en *host-only* (`SESSION_DOMAIN=`
vide, `SameSite=None`, `Secure=true`), il est bien stocké — mais le SPA, servi
depuis `app.localhost`, ne peut plus **lire** le `XSRF-TOKEN` posé sur
`api.localhost` (lecture JS inter-hôtes impossible) :

```
POST /api/v1/auth/login → 419   CSRF token mismatch
```

**Les deux configurations échouent.** Le découpage `app.localhost` /
`api.localhost` prescrit par le §A.1 rend la console inutilisable en local. En
production le problème n'existe pas : `.axion-crm-pro.com` est un domaine
enregistrable ordinaire. La configuration livrée a été **restaurée à
l'identique** après ces mesures.

### D-12 — la double authentification est obligatoire, et personne ne le dit

Une fois une session obtenue par cookies en ligne de commande, **toutes** les
routes protégées répondent :

```
HTTP 403 {"error":"first_login_required",
          "message":"Vous devez activer la double authentification avant utilisation.",
          "next_step":"/auth/2fa/setup"}
```

Le §A ne mentionne aucune activation TOTP. Sans elle, la console reste vide quoi
qu'on fasse. Contourné en local par
`UPDATE users SET first_login_completed_at = now()` — un geste de jeu d'essai,
qui n'a pas à être deviné.

### D-13 — la base de données du SITE n'existe pas, et le §A.2 ne la mentionne jamais 🔴

Le §A.2 tient en trois commandes : `pnpm install`, `pnpm prisma:generate`,
`pnpm dev`. Aucune ne crée ni ne démarre la base du site.

```
$ docker ps | grep -i postgres
axion-crm-postgres    Up 9 hours (healthy)   0.0.0.0:55432->5432
bookforge-postgres    Up 9 hours (healthy)   0.0.0.0:5433->5432

$ grep DATABASE_URL .env.local
postgresql://axion_ia:***@localhost:5433/axion_ia_dev

$ docker exec bookforge-postgres psql -U axion_ia -d axion_ia_dev -c "\dt"
FATAL:  role "axion_ia" does not exist
```

**Le rôle n'existe pas, la base n'existe pas.** Le site ne peut pas démarrer.

Accessoirement, cela tranche le §G.2 : le fichier d'environnement du worktree est
**`.env.local`**, et il ne porte **aucune** des cinq variables que le §A.2 demande
de poser (`CRM_SYNC_ENABLED`, `CRM_SYNC_URL`, `SITE_SYNC_HMAC_SECRET`,
`VIVIER_STOCK_ENABLED`, `NODE_TLS_REJECT_UNAUTHORIZED`).

### D-14 — divergences mineures

- **§A.1, tableau des services** : `app` y est décrit comme servant Vite sur
  `app:5173`. Le conteneur exécute en réalité **Caddy sur un build de production
  statique** (`/srv/app/dist`) — `[Boot] MODE= production PROD= true`. Le port
  est bon, la nature du service ne l'est pas : aucun rechargement à chaud, et
  toute modification du frontend exige une reconstruction d'image.
- **Runbook §1.1** : le tableau des préconditions déclare `feat/crm-L4-consents`
  et `feat/crm-L5-observabilite` **non fusionnées**. Les modèles `CrmSyncOutbox`
  et `ConsentEvent` sont pourtant présents dans `prisma/schema.prisma` sur le
  `HEAD` courant du site. Le tableau n'a pas été tenu à jour depuis le 2026-08-14.
- **Runbook Geste E** : le `event_id` de l'exemple,
  `00000000-0000-4000-8000-00000000zz01`, contient deux caractères non
  hexadécimaux. Il est **accepté** (HTTP 200) : le CRM ne valide pas la forme
  UUID de `event_id`. Ce n'est pas un défaut du runbook, mais une propriété du
  contrat qui n'est écrite nulle part.
- **Bruit permanent** : chaque commande artisan émet ~25 lignes de pile d'appel
  (cf. D-03). Sur une commande qui réussit, c'est un signal faux.

---

## RÉSULTATS — synthèse

Le détail complet, avec les sorties réelles, est dans le rapport de clôture :
`Axion-CRM-Pro/_REPORTS/2026-08-17_CLOTURE-PLAN-CRM-E2E2.md`.

### Ce qui est VERT

- **Frontière du contrat (§B.1)** : **13 des 14** valeurs de `CRM_FORM_TYPES`
  acceptées ; `recrutement` → 422 consentement, ce qui **confirme** sa bascule
  vers l'univers vivier. `simulateur_roi` accepté — la non-régression tient.
- **Idempotence** : rejeu → `noop_idempotent`, 14 activités, 0 doublon.
- **Gardes candidat (§B.7)** : v1 → 422 ; v2 → `vivier-candidats`
  **exclusivement** ; contraste `consent_vivier_at` renseigné / NULL **prouvé** ;
  `cv_ref` = référence ; clé `workspace` forgée → 422 `unknown_field`.
- **Authentification (§D.4)** : les **quatre** attendus sont exacts
  (`bad_signature`, `stale_signature`, en-têtes croisés, préfixe `sha256=`).
- **Drapeaux fermés (§D.2)** : 503 `ingest_disabled` et
  503 `candidates_ingest_disabled`.
- **Doublon SIREN (§C.2)** : une fiche, enrichie, `field_origins` complet,
  tags de provenance **cumulés**.
- **Étanchéité par les humains** : `/crm/candidates` → **403** pour un compte
  business. L'accès **échoue**, il ne rend pas une liste vide.

### Ce qui est ROUGE

- **§B.10 opposition vivier** — deux conséquences mesurées, corrigées par la
  PR #143 (dépôt CRM).
- **`AudienceBuilderService`** — deux défauts, 5 tests rouges avant correctif,
  PR #142.
- **Montage §A** — inexécutable pour la moitié « site » (D-07, D-13) et pour la
  console (D-10, D-11, D-12).

### Livrables

| # | Livrable | État |
|---|---|---|
| 1 | E2E n°2, checklist §E | **partiel et documenté** — 7 écrans sur 13, B.8→B.12 et 4 pannes non jouées, causes nommées |
| 2 | Tests `AudienceBuilderService` (`not`, `tags`/`contains_any`, `Bus::batch`) | **livré** — PR #142, 15 tests, rouge → vert |
| 3 | Rapport de clôture | **livré** — `_REPORTS/2026-08-17_CLOTURE-PLAN-CRM-E2E2.md`, verdict **NON CLOS** |

### État laissé derrière

- `.env` du CRM local : drapeaux du §A.1 posés, `SESSION_*` **restaurés à
  l'identique** après les mesures de D-11. Sauvegarde : `.env.bak-e2e2-*`.
- Base locale `axion_crm` recréée ; état antérieur sauvegardé
  (`axion_crm_avant_e2e2.dump`, 316 837 octets, scratchpad de session).
- **Production : aucune écriture.** Lectures seules uniquement.



