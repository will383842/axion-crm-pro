# Runbook — faire tourner la console Axion CRM Pro en local

> **À qui s'adresse ce document** : à quelqu'un qui n'a jamais lancé ce dépôt.
> Tout ce qui suit a été **exécuté** le 2026-08-18 sur Windows 11 + Docker
> Desktop. Aucune commande n'est recopiée d'une note antérieure : ce qui est
> écrit ici a rendu la sortie qui est citée.
>
> **Critère que ce runbook sert** : « la console tourne en local, connexion,
> 2FA, tous les écrans v2 ouverts, sans `NODE_TLS_REJECT_UNAUTHORIZED=0` ».

---

## 0. Ce qu'il faut savoir avant de taper quoi que ce soit

**L'origine est UNIQUE : `https://app.localhost`.** Le SPA et l'API sont servis
par le même hôte, Caddy routant `/api/*`, `/sanctum/*`, `/up`, `/docs`,
`/broadcasting/*` vers Laravel et tout le reste vers le frontend
(`infra/caddy/Caddyfile`, bloc `app.localhost`). Ce n'est pas une commodité :
c'est la condition pour que le cookie de session existe (§2, défaut D-11). Ne
jamais viser `api.localhost`, `127.0.0.1` ou `localhost:5173` pour vérifier la
console — on ne vérifierait alors plus rien de ce qui casse.

Une **seconde porte** existe, en HTTP nu : `http://localhost:58080`. Elle est
réservée à deux usages : le débogage `curl` (lisible, rapide, pas de
certificat), et l'émission du **site Next.js** vers le CRM (§7). Elle ne
remplace pas l'origine unique pour la vérification du navigateur.

---

## 1. Démarrer la pile

Le fichier de projet Compose **et** la surcouche de développement sont tous
deux **versionnés à la racine du dépôt principal** (`git ls-files
docker-compose.local.yml` — corrigé le 2026-08-22, constat A07-009 : ce runbook
envoyait chercher `docker-compose.local.yml` dans le worktree `crmpro-wt-etape0`
alors qu'il est suivi ici). Les deux `-f` restent obligatoires, dans cet ordre,
et la commande se lance depuis le dépôt principal :

```bash
cd C:/Users/willi/Documents/Projets/Axion-CRM-Pro

docker compose \
  -f docker-compose.yml \
  -f docker-compose.local.yml \
  up -d
```

Le projet Compose s'appelle `axion-crm-pro`. Toute commande ultérieure
(`ps`, `logs`, `down`, `build`) **doit reprendre les deux `-f`** : sans la
surcouche, Compose considère les services comme différents et en recrée une
seconde copie.

### Ce que fait la surcouche, et pourquoi elle n'est pas optionnelle

`docker-compose.local.yml` pose, sur `api` / `horizon` / `scheduler` /
`reverb` :

| Variable | Valeur | Sans elle |
|---|---|---|
| `APP_URL` | `https://app.localhost` | URL absolues fabriquées sur la mauvaise origine |
| `SESSION_DOMAIN` | *(vide)* | `Domain=.localhost` → cookie **refusé** par le navigateur → 401 |
| `SESSION_SECURE_COOKIE` | `false` | — |
| `SANCTUM_STATEFUL_DOMAINS` | `app.localhost,localhost,127.0.0.1` | `/auth/login` répond **419 `session_requise`** |
| `FRONTEND_URL` | `https://app.localhost` | liens des e-mails vers la mauvaise origine |
| `CRM_CONSOLE_V2_ENABLED` | `true` | **les 4 écrans console v2 n'existent pas** (§4) |
| `TELESCOPE_ENABLED` | `false` | **500 à la terminaison de chaque requête** (§3) |
| `DB_HOST` / `REDIS_HOST` | `postgres` / `redis` | `horizon` et `scheduler` ne joignent rien |

Elle publie aussi `58080:80` sur `api`, et fige le build-arg
`VITE_API_BASE_URL=https://app.localhost` sur `app`.

> ⚠️ **Le défaut de `VITE_API_BASE_URL` dans `docker-compose.yml` est
> `https://app.axion-crm-pro.com`, c'est-à-dire la PRODUCTION.** Un poste qui
> lance la pile *sans* la surcouche construit donc, en silence, une console
> locale qui parle à l'API de production avec les cookies du navigateur. Rien
> ne le signale : elle s'ouvre, elle se connecte, elle affiche de vraies
> fiches. C'est la raison d'être de la garde « origine étrangère » du spec de
> vérification (§6).

### Ordre des services et temps d'attente réalistes

| Service | Conteneur | Attendre | Signal de disponibilité |
|---|---|---|---|
| 1. `postgres` | `axion-crm-postgres` | 10–20 s | `docker ps` → `(healthy)` |
| 2. `redis` | `axion-crm-redis` | 5 s | `(healthy)` |
| 3. `api` | `axion-crm-api` | 20–40 s (migrations à la 1re fois) | `curl -sk https://app.localhost/up` → `200` |
| 4. `app` | `axion-crm-app` | 10 s (image `prod`, Caddy sert `dist/`) | `(healthy)` |
| 5. `caddy` | `axion-crm-caddy` | 5 s | `curl -sk https://app.localhost/` → `200` |

**La toute première requête après un démarrage coûte 20 s.** Mesuré :
`GET /api/v1/crm/contacts-hub` = **21,4 s à froid**, **5,8 s à chaud**, sur une
base *vide*. C'est le prix du bind-mount Windows ; `infra/php/opcache-local.ini`
(monté par la surcouche) le ramène de ~26 s à ces valeurs. Ne pas conclure « la
pile est cassée » avant d'avoir rejoué la requête une seconde fois.

### Vérifier que la pile répond

```bash
curl -sk -o /dev/null -w '%{http_code}\n' https://app.localhost/up
# → 200

curl -sS -H 'Accept: application/json' http://localhost:58080/api/v1/config/features
# → {"message":"Unauthenticated."}   (401 : c'est le bon signe, l'API vit)
```

> **Défaut mineur connu, à ne pas corriger ici** : la *même* URL **sans**
> l'en-tête `Accept: application/json` rend **500 « Route [login] not
> defined »**. C'est le comportement de Laravel quand une requête qui accepte
> du HTML tombe sur `auth:sanctum` dans une application sans route `login`
> nommée. Ce n'est pas une panne de la pile ; c'est un 401 mal habillé.

---

## 2. Cause de panne n° 1 — D-11, l'origine unique

**Comment ça se manifeste** : la connexion aboutit, puis **401** sur tout appel
suivant ; ou bien la connexion elle-même rend **419 CSRF token mismatch**.

**Pourquoi** — deux impasses, et la configuration doit éviter les deux :

- `SESSION_DOMAIN=.localhost` → les navigateurs **refusent** un cookie de
  domaine sur `localhost` (c'est un domaine de premier niveau). Aucun cookie
  n'est stocké → **401**.
- SPA servi depuis `app.localhost` et API sur `api.localhost` → le cookie
  `XSRF-TOKEN` est host-only sur `api.localhost` et **illisible en JavaScript**
  depuis `app.localhost` → l'en-tête `X-XSRF-TOKEN` part vide → **419**.

**Le remède est la configuration de §1**, pas un contournement. Trois valeurs
doivent se répondre, et elles sont posées en `environment:` (donc au-dessus du
`.env` personnel du poste, que personne ne relit) :

```
APP_URL            = https://app.localhost
SESSION_DOMAIN     = (vide)
VITE_API_BASE_URL  = https://app.localhost   (build-arg de l'image `app`)
```

**Comment savoir que c'est revenu** : le spec de vérification (§6) échoue avec
`Signature D-11 : cookie de session non transmis (401) ou session/CSRF perdue
(419)`. Il liste l'URL exacte fautive.

---

## 3. Cause de panne n° 2 — Telescope non migré

**Comment ça se manifeste** : **500 sur tout** — y compris sur des commandes
`php artisan` qui ont pourtant fait leur travail. La trace parle de
`terminate()`, jamais de la route demandée.

**Pourquoi** : `laravel/telescope` est une dépendance **dure**
(`backend/composer.json`) et son défaut est `enabled = true`. Or ses migrations
ne sont **jamais publiées** dans ce dépôt — `backend/database/migrations/` n'en
contient aucune. Mesuré le 2026-08-18 sur une base à jour (54 migrations
passées, 0 en attente, 104 tables) : **0 table `telescope*`**. Telescope
enregistre à la *terminaison* de chaque requête et de chaque commande : l'échec
survient donc **après** le travail utile, ce qui casse tout sans jamais rien
empêcher de s'exécuter. C'est la panne qui frappe la **première requête de
toute installation neuve**.

**Remède** : `TELESCOPE_ENABLED=false`, posé par la surcouche et ajouté à
`.env.example`. Vérification :

```bash
docker exec axion-crm-postgres psql -U axion -d axion_crm -t -c \
  "select count(*) from information_schema.tables where table_name like 'telescope%';"
# → 0   (et c'est sans conséquence tant que le drapeau est à false)
```

---

## 4. Cause de panne n° 3 — le drapeau `CRM_CONSOLE_V2_ENABLED`

**Comment ça se manifeste** : **les écrans sont absents, sans message
d'erreur.** C'est la panne la plus trompeuse des trois, parce qu'elle
ressemble à « ce n'est pas encore développé ».

Précisément, drapeau fermé :

- `GET /api/v1/config/features` → `{"console_v2": false, …}` (la route reste
  ouverte à dessein : la mettre derrière le drapeau qu'elle annonce serait
  circulaire) ;
- toutes les routes `/api/v1/crm/*` → **404**, corps 404 standard de Laravel
  (un message « console désactivée » trahirait ce que le 404 est censé taire) ;
- côté navigateur, `ConsoleGate` affiche « **Console non activée** ».

Le défaut `false` de `.env.example` vise la **production**. En local, ne pas
l'ouvrir revient à ne jamais pouvoir vérifier ce qu'on écrit.

**Vérification, une fois connecté (§5)** :

```
GET /api/v1/config/features
→ {"console_v2":true,"universes":{"business":true,"vivier":true}}
```

`universes.vivier` ne dépend **pas** du drapeau seul : il exige une ligne
`user_workspaces` **non révoquée** vers le workspace `vivier-candidats`
(`App\Crm\Console\ConsoleAccess`). Sans elle, l'écran `/console/vivier` affiche
« **Univers vivier candidats non accessible** » — un refus lisible, pas une
liste vide.

---

## 5. Fabriquer un compte de vérification

Le seul utilisateur en base est `williamsjullin@gmail.com`, dont le mot de
passe n'est pas connu, et qui n'est membre **que** du workspace business — il
ne peut donc pas ouvrir l'écran vivier. On crée un compte dédié.

### 5.1 La commande, verbatim

Une seule commande, idempotente (rejouable sans dommage) :

```bash
docker exec axion-crm-api php artisan tinker --execute='
$wsB = "20cd81e4-de5d-4875-a759-07d64fe1f168";
$wsV = "95cbe9b3-378e-4c9a-87cf-1d0faa629643";
$g = new \PragmaRX\Google2FA\Google2FA();
$secret = $g->generateSecretKey();
$u = \App\Models\User::query()->where("email", "console-locale@axion-ia.test")->first();
if (! $u) { $u = new \App\Models\User(); $u->id = (string) \Illuminate\Support\Str::uuid(); }
$u->email = "console-locale@axion-ia.test";
$u->name = "Console Locale";
$u->password_hash = \Illuminate\Support\Facades\Hash::make("ConsoleLocale2026!");
$u->current_workspace_id = $wsB;
$u->totp_secret = $secret;
$u->totp_enabled_at = now();
$u->first_login_completed_at = now();
$u->onboarding_tour_completed_at = now();
$u->email_verified_at = now();
$u->failed_login_count = 0;
$u->locked_until = null;
$u->save();
foreach ([$wsB, $wsV] as $ws) {
  \Illuminate\Support\Facades\DB::table("user_workspaces")->updateOrInsert(
    ["user_id" => $u->id, "workspace_id" => $ws],
    ["role_slug" => "owner", "joined_at" => now(), "revoked_at" => null]
  );
  app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($ws);
  $u->unsetRelation("roles");
  if (! $u->hasRole("owner")) { $u->assignRole("owner"); }
}
echo "USER_ID=" . $u->id . "\n";
echo "TOTP_SECRET=" . $secret . "\n";
'
```

Sortie obtenue le 2026-08-18 :

```
USER_ID=ff845f78-5bbe-4b33-99e6-e487ab9fa8ff
TOTP_SECRET=<affiché par la commande — NE PAS le recopier ici : la valeur du 18/08 a été publiée par erreur, puis TOURNÉE le soir même>
```

> ⏱ **Compter environ 90 secondes.** `php artisan` coûte **79 s d'horloge par
> invocation** dans ce conteneur — mesuré :
> `time docker exec axion-crm-api php artisan --version` → `real 1m18.889s`,
> pour **0,15 s de CPU**. Le reste est de l'attente disque : amorcer Laravel
> lit des milliers de fichiers à travers le bind-mount Windows, et l'opcache du
> `php-fpm` (qui, lui, sauve les requêtes HTTP) ne sert à rien pour un
> processus CLI neuf. **La commande n'est pas bloquée ; elle démarre.** Ne pas
> l'interrompre, et ne jamais donner à un outil qui l'invoque un délai
> d'attente inférieur à 3 minutes.
>
> **Notez le `TOTP_SECRET` affiché** : le fournir au spec via
> `E2E_TOTP_SECRET` lui épargne exactement ces 79 s (§6).

> ⚠️ Les identifiants des deux workspaces sont ceux de **cette** base. Sur une
> base neuve, les relire d'abord :
> `docker exec axion-crm-postgres psql -U axion -d axion_crm -c "SELECT id, slug FROM workspaces;"`
> — le vivier est celui dont le `slug` vaut `vivier-candidats`
> (`App\Crm\Taxonomy::VIVIER_WORKSPACE_SLUG`).

### 5.2 Pourquoi chaque ligne est là

- **`password_hash`, pas `password`.** La colonne s'appelle `password_hash` et
  `User::getAuthPassword()` la désigne ; `AuthService` fait
  `Hash::check($password, $user->password_hash)`. Écrire `password` ne
  produirait aucune erreur — et aucune connexion.
- **Mot de passe ≥ 12 caractères.** `LoginRequest` impose `Password::min(12)` :
  un mot de passe plus court est refusé en **validation**, pas en
  authentification, et le message ne le dit pas clairement.
- **`totp_secret` via Eloquent, jamais en SQL direct.** La colonne porte un
  cast `encrypted` : une valeur écrite par `psql` serait illisible par
  l'application. C'est aussi pourquoi le secret se *relit* par `artisan`, pas
  par `psql`.
- **`first_login_completed_at` rempli.** Le middleware `first-login`
  (`EnforceFirstLoginSetup`) répond **403 `first_login_required`** sur toute
  route hors d'une liste blanche de 5 routes tant que ce champ est nul. Sans
  lui, aucun écran ne s'ouvre.
- **`totp_enabled_at` rempli.** C'est ce seul champ qui décide de
  `requires_2fa` dans la réponse de login, donc du passage par l'écran `/2fa`.
  On veut ce passage : il fait partie du critère de sortie.
- **Membre des DEUX workspaces, `role_slug = owner`.** `owner` est le rôle le
  plus ouvert des quatre (`owner|admin|operator|viewer`). La ligne
  `user_workspaces` non révoquée vers `vivier-candidats` est ce qui débloque
  `universes.vivier` (§4).
- **`setPermissionsTeamId()` avant `assignRole()`.** Spatie est en mode
  « teams » (`config/permission.php`) et `model_has_roles.team_id` est **NOT
  NULL** : sans contexte d'équipe, le rôle serait attribué à l'équipe `null` et
  ne serait jamais retrouvé. Un rôle par workspace, donc deux appels.

### 5.3 Vérifier ce qui vient d'être écrit

```bash
docker exec axion-crm-postgres psql -U axion -d axion_crm -c "
SELECT u.email, w.slug, uw.role_slug, uw.revoked_at
FROM user_workspaces uw
JOIN users u ON u.id = uw.user_id
JOIN workspaces w ON w.id = uw.workspace_id
WHERE u.email = 'console-locale@axion-ia.test';"
```

Attendu : deux lignes, `axion-ia` et `vivier-candidats`, `owner`, `revoked_at`
vide.

### 5.4 Le mot de passe et le secret ne sont écrits dans aucun `.env`

Le spec (§6) lit le secret TOTP **depuis la base**, via `artisan`, s'il n'est
pas fourni par `E2E_TOTP_SECRET`. Rien à poser, rien à versionner. Pour
supprimer le compte :

```bash
docker exec axion-crm-postgres psql -U axion -d axion_crm -c \
  "DELETE FROM users WHERE email = 'console-locale@axion-ia.test';"
```

---

## 6. Lancer le spec de vérification

Comme au §1, **depuis le dépôt principal** (`cd
C:/Users/willi/Documents/Projets/Axion-CRM-Pro`) : le spec est versionné dans
`frontend/tests/e2e/`, et c'est le code de ce dépôt que la pile Docker sert
(§9.1).

```bash
CI=1 E2E_TOTP_SECRET=<affiché par la commande — NE PAS le recopier ici : la valeur du 18/08 a été publiée par erreur, puis TOURNÉE le soir même> \
  pnpm --dir frontend \
  exec playwright test tests/e2e/console-locale.spec.ts \
  --project=chromium --reporter=list --retries=0 --workers=1
```

`E2E_TOTP_SECRET` est **facultatif** : sans lui, le spec relit le secret dans
la base via `artisan`, ce qui lui coûte les 79 s de §5.1. Le poser est le
chemin rapide. Le secret est celui imprimé par la commande de création du
compte ; il change à chaque exécution de celle-ci.

Si le navigateur manque :

```bash
pnpm --dir frontend \
  exec playwright install chromium
```

### `CI=1` n'est pas décoratif

`playwright.config.ts` démarre `pnpm dev` **sauf** si `CI` est posé (et que
`E2E_PREVIEW` ne l'est pas). Comme la pile Docker sert déjà `app.localhost`, ce
serveur est superflu — et il échoue : sans `CI=1`, le run s'arrête sur
`Timed out waiting 60000ms from config.webServer` **avant même d'ouvrir un
onglet**. Mesuré.

### Les trois réglages qui font la valeur de preuve du spec

1. **`E2E_BASE_URL` reste `https://app.localhost`.** C'est l'origine unique.
   Y mettre `127.0.0.1` ou `localhost:5173` casserait précisément ce que le
   test doit prouver.
2. **`locale: 'fr-FR'` sur le contexte navigateur.** L'application détecte la
   langue par `['localStorage','navigator']` ; Chromium annonce `en-US` par
   défaut, et la console s'affiche alors **en anglais**. L'échec ressemble à
   « la page ne charge pas » alors qu'elle charge parfaitement, dans une autre
   langue. (C'est la raison pour laquelle `auth.spec.ts`, qui cherche des
   libellés français sans épingler la locale, échoue aujourd'hui — voir §8.)
3. **`ignoreHTTPSErrors: true` (déjà dans `playwright.config.ts`) et
   RIEN d'autre.** Voir §7.

### Ce que le spec vérifie sur chaque écran

- l'URL n'a pas été renvoyée vers `/login` ni `/2fa` (la session a survécu au
  chargement direct de l'URL) ;
- `#main` est rendu — cet identifiant n'existe que dans `RootLayout`, donc son
  absence signifie 404, écran blanc ou plantage au montage ;
- le corps ne contient ni « Une erreur est survenue. » (ErrorBoundary), ni
  « Page introuvable » (catch-all 404), ni « Console non activée », ni
  « Univers vivier candidats non accessible » ;
- **aucune réponse 401 ni 419** pendant la visite (signature D-11) ;
- **aucune requête vers une origine autre que `https://app.localhost`** ;
- une capture plein écran est écrite dans
  `frontend/tests/e2e/__captures__/console-locale/`.

---

## 7. Côté site Next.js — et pourquoi `NODE_TLS_REJECT_UNAUTHORIZED=0` ne doit jamais reparaître

À poser dans l'environnement du site :

```
CRM_SYNC_URL=http://localhost:58080/api/internal/site-sync
```

**Pourquoi ce port, et pas `https://api.localhost`** — trois raisons, dont deux
sont des impossibilités :

1. **Node ne résout pas `*.localhost`.** Les navigateurs et curl traitent ce
   suffixe spécialement ; Node passe par le résolveur du système.
   `require('dns').lookup('api.localhost')` → **ENOTFOUND**, alors que
   `curl https://api.localhost/up` → **200**. Le site ne *peut pas* émettre
   vers cette adresse.
2. **Le remède documenté jusqu'ici — deux lignes dans
   `C:\Windows\System32\drivers\etc\hosts` — exige une élévation
   administrateur**, que ni l'autopilote ni un montage scripté n'ont.
3. **En HTTP nu sur un port publié, il n'y a plus de certificat auto-signé du
   tout.** Donc plus rien à contourner.

C'est là tout l'argument. `NODE_TLS_REJECT_UNAUTHORIZED=0` était le
contournement de la difficulté n° 3 — et il la « règle » en **désactivant la
vérification TLS de tout le processus Node**, pas seulement de l'appel au CRM.
Chaque requête sortante du site, vers n'importe quel tiers, cesse alors de
vérifier son interlocuteur. Le trou ne reste pas dans le fichier où on l'a
écrit : il voyage avec le processus. Et il ne se voit pas — rien n'échoue, tout
continue de marcher.

La règle tient en une phrase : **le contournement de certificat reste borné au
navigateur de test** (`ignoreHTTPSErrors` de `playwright.config.ts`, qui ne
concerne que les onglets ouverts par Playwright) ; **rien au niveau du
processus Node**. Le spec porte un test dédié qui échoue si
`NODE_TLS_REJECT_UNAUTHORIZED` vaut `0` — c'est la seule manière qu'une
interdiction a de survivre à quelqu'un de pressé.

---

## 7 bis. Vérifier connexion + 2FA en HTTP nu (utile pour trier une panne)

Avant d'ouvrir un navigateur, ce parcours `curl` dit en 30 secondes si le
problème est côté API ou côté cookie. Il vise le port 58080, avec l'en-tête
`Origin: https://app.localhost` — **obligatoire** : sans lui, la requête n'est
pas *stateful* pour Sanctum et `/auth/login` répond `419 session_requise`
(l'en-tête `Origin: http://localhost:58080` ne convient pas non plus, le port
fait partie de l'hôte comparé).

```bash
JAR=/tmp/cj.txt && rm -f "$JAR"

# 1. Cookie CSRF
curl -sS -o /dev/null -w 'HTTP %{http_code}\n' -c "$JAR" \
  -H 'Origin: https://app.localhost' -H 'Accept: application/json' \
  http://localhost:58080/sanctum/csrf-cookie
# → HTTP 204

# 2. Connexion (le jeton XSRF doit être URL-décodé avant d'être renvoyé)
XSRF_RAW=$(grep -i 'XSRF-TOKEN' "$JAR" | awk '{print $7}')
XSRF=$(printf '%b' "${XSRF_RAW//%/\\x}")
curl -sS -b "$JAR" -c "$JAR" \
  -H 'Origin: https://app.localhost' -H 'Accept: application/json' \
  -H 'Content-Type: application/json' -H 'X-Requested-With: XMLHttpRequest' \
  -H "X-XSRF-TOKEN: $XSRF" \
  -d '{"email":"console-locale@axion-ia.test","password":"ConsoleLocale2026!","remember":true}' \
  http://localhost:58080/api/v1/auth/login
# → {"user":{...,"totp_enabled_at":"..."},"requires_2fa":true}

# 3. Code TOTP calculé par la bibliothèque du serveur (compter 79 s, cf. §5.1)
CODE=$(docker exec axion-crm-api php artisan tinker --execute='$u=\App\Models\User::where("email","console-locale@axion-ia.test")->first(); echo (new \PragmaRX\Google2FA\Google2FA())->getCurrentOtp($u->totp_secret);' | tr -d '\r\n')

# 4. Vérification du second facteur
XSRF_RAW=$(grep -i 'XSRF-TOKEN' "$JAR" | awk '{print $7}')
XSRF=$(printf '%b' "${XSRF_RAW//%/\\x}")
curl -sS -b "$JAR" -c "$JAR" \
  -H 'Origin: https://app.localhost' -H 'Accept: application/json' \
  -H 'Content-Type: application/json' -H 'X-Requested-With: XMLHttpRequest' \
  -H "X-XSRF-TOKEN: $XSRF" \
  -d "{\"code\":\"$CODE\"}" \
  http://localhost:58080/api/v1/auth/2fa/verify
# → {"verified":true}

# 5. Drapeaux de la console
curl -sS -b "$JAR" -H 'Origin: https://app.localhost' -H 'Accept: application/json' \
  http://localhost:58080/api/v1/config/features
# → {"console_v2":true,"universes":{"business":true,"vivier":true}}
```

Lecture des pannes :

| Symptôme | Cause |
|---|---|
| `419 session_requise` sur `/auth/login` | `Origin`/`Referer` absent ou hors `SANCTUM_STATEFUL_DOMAINS` |
| `419 CSRF token mismatch` | jeton `X-XSRF-TOKEN` non URL-décodé, ou pot de cookies non réutilisé |
| `500` sur n'importe quoi | Telescope (§3) |
| `{"console_v2":false}` | drapeau (§4) |
| `"universes":{"vivier":false}` | pas de ligne `user_workspaces` vers `vivier-candidats` (§5) |

---

## 8. Écrans vérifiés

Run du **2026-08-18**, `--project=chromium --retries=0 --workers=1` :
**35 tests, 35 passés, 0 échec, 3,9 min.**

Décompte : **32 écrans sous coquille** (= la totalité des enfants de
`layoutRoute` dans `routeTree.tsx`, `/crm` et `/analytics` retirés par l'étape
0), **+ 2 écrans hors coquille** traversés par le parcours (`/login`, `/2fa`),
**+ 1 test de garde** (`NODE_TLS_REJECT_UNAUTHORIZED`).

Captures dans `frontend/tests/e2e/__captures__/console-locale/`.

| # | Écran | URL | Capture |
|---|---|---|---|
| — | Connexion (formulaire rempli, renvoi vers 2FA) | `/login` | `00a-login-vers-2fa.png` |
| — | 2FA validée, session ouverte | `/2fa` | `00b-2fa-valide.png` |
| 1 | Tableau de bord | `/` | `01-dashboard.png` |
| 2 | Entreprises | `/companies` | `02-companies.png` |
| 3 | Entreprise — fiche † | `/companies/{id}` | `03-company-detail.png` |
| 4 | Contacts | `/contacts` | `04-contacts.png` |
| 5 | International — Roumanie | `/international/roumanie` | `05-international-roumanie.png` |
| 6 | Médias | `/media` | `06-media.png` |
| 7 | Média — fiche † | `/media/{id}` | `07-media-detail.png` |
| 8 | Journalistes | `/journalists` | `08-journalists.png` |
| 9 | Couverture territoriale | `/coverage` | `09-coverage.png` |
| 10 | Exécutions de collecte | `/scraper-runs` | `10-scraper-runs.png` |
| 11 | LLM — routeur | `/llm/router` | `11-llm-router.png` |
| 12 | LLM — fournisseurs proxy | `/llm/proxy-providers` | `12-llm-proxy-providers.png` |
| 13 | LLM — rotations | `/llm/rotations` | `13-llm-rotations.png` |
| 14 | RGPD — demandes | `/rgpd/requests` | `14-rgpd-requests.png` |
| 15 | RGPD — registre AI Act | `/rgpd/ai-act` | `15-rgpd-ai-act.png` |
| 16 | Journal d'audit | `/audit-logs` | `16-audit-logs.png` |
| 17 | Utilisateurs ‡ | `/users` | `17-users.png` |
| 18 | Réglages | `/settings` | `18-settings.png` |
| 19 | Campagnes | `/campaigns` | `19-campaigns.png` |
| 20 | Campagne — assistant | `/campaigns/new` | `20-campaigns-new.png` |
| 21 | Campagne — détail † | `/campaigns/{id}` | `21-campaign-detail.png` |
| 22 | Étiquettes | `/tags` | `22-tags.png` |
| 23 | Audiences | `/audiences` | `23-audiences.png` |
| 24 | Audience — constructeur | `/audiences/new` | `24-audiences-new.png` |
| 25 | Audience — détail † | `/audiences/{id}` | `25-audience-detail.png` |
| 26 | Observabilité | `/admin/observability` | `26-admin-observability.png` |
| **27** | **Console v2 — hub contacts** | `/console/contacts` | `27-console-contacts.png` |
| **28** | **Console v2 — vivier candidats** | `/console/vivier` | `28-console-vivier.png` |
| **29** | **Console v2 — arbitrage** | `/console/arbitrage` | `29-console-arbitrage.png` |
| **30** | **Console v2 — fiche 360°** † | `/console/personnes/{clé}` | `30-console-person.png` |
| 31 | Cold email (bouchon Phase 2) | `/cold-email` | `31-cold-email.png` |
| 32 | LinkedIn (bouchon Phase 2) | `/linkedin` | `32-linkedin.png` |

**†** — écran de détail ouvert sur un identifiant **inexistant** : la base
locale est vide (0 entreprise, 0 contact, 0 campagne, 0 audience). Ce qui est
prouvé est que l'écran s'ouvre, se route et rend sa coquille sans erreur, **pas
qu'il sait afficher une fiche**. La différence est réelle : ne pas la lisser.

**‡** — `/users` est vert au sens du test (l'écran s'ouvre, aucune erreur
affichée) mais **il n'affichera jamais personne** : voir §9.

Les quatre écrans v2 ont été **regardés**, pas seulement comptés. Sur
`/console/contacts` : sidebar « CONSOLE CRM » (Contacts / À arbitrer / Vivier
candidats), quatre compteurs (Clients, Prospects, Opportunités, Dormants),
onglets par type, filtres, et l'état vide « Aucun contact dans cette vue ». Sur
`/console/vivier` : « Univers étanche — base légale et durées de conservation
distinctes de la base commerciale », compteurs de pipeline (À qualifier,
Présélection, Entretien, Conservés en vivier), état vide « Aucun candidat dans
cette vue ». **Aucun des deux n'affiche « Console non activée » ni « Univers
vivier candidats non accessible »** — c'est-à-dire que le drapeau ET
l'appartenance aux deux univers sont effectivement en place.

---

## 9. Ce qui ne marche pas encore

Tout ce qui suit a été **mesuré** le 2026-08-18, pas déduit. Rien n'a été
corrigé : ces points sortent du périmètre de la ligne 1, et une correction non
demandée dans le dos d'autres agents serait pire que le constat.

### 9.1 🔴 La pile locale sert le DÉPÔT PRINCIPAL, pas le worktree

C'est le point le plus important de cette section, parce qu'il change la
portée de tout ce qui précède.

```bash
docker inspect axion-crm-api --format '{{range .Mounts}}{{.Source}} -> {{.Destination}}{{println}}{{end}}'
# → C:\Users\willi\Documents\Projets\Axion-CRM-Pro\backend -> /var/www/html
```

Compose résout les chemins relatifs (`./backend`, `context: .`) contre le
**répertoire de projet**, c'est-à-dire le dossier du **premier** `-f` — donc
`Axion-CRM-Pro`, jamais un worktree. La mesure du 2026-08-18 avait été prise
avec une surcouche pointée sur `crmpro-wt-etape0` : cela n'y changeait rien, et
depuis le 2026-08-22 le §1 prend la surcouche **versionnée du dépôt principal**
(A07-009). Dans les deux cas, la pile monte le backend du dépôt principal et
construit le frontend depuis ses sources.

Preuves croisées :

- `routes/api.php` du worktree a retiré le fourre-tout `Route::any('/crm{any?}')` ;
  le conteneur, lui, l'a toujours (ligne 290), et `GET /api/v1/crm/contacts`
  répond **501** au lieu du 404 attendu.
- le bundle servi contient `path:"/analytics"`, une route que le worktree a
  supprimée.

**Conséquence à énoncer clairement : ce runbook prouve que la console du dépôt
principal tourne en local. Il ne prouve rien sur le code de l'étape 0.** Pour
vérifier le worktree, il faut lancer Compose avec le worktree comme répertoire
de projet (`--project-directory`, ou une copie du `docker-compose.yml`), et
reconstruire l'image `app`.

### 9.2 🔴 L'enrôlement 2FA par l'interface est mort — il écrit trois colonnes qui n'existent pas

`App\Services\Auth\TwoFactorService` écrit `two_factor_secret`
(`startEnrolment`), puis `two_factor_enabled` et `two_factor_recovery_codes`
(`confirmEnrolment`). Or la table `users` ne porte **aucune** de ces trois
colonnes — elle a `totp_secret`, `totp_enabled_at`, `totp_recovery_codes` — et
**aucune migration ne les crée** (`grep` sur les 54 migrations : zéro
occurrence). Le modèle `User` les déclare pourtant en `$fillable` et en
`casts()`, ce qui rend l'incohérence invisible à la lecture du seul modèle.

Enchaînement, et c'est là que ça devient bloquant :

1. `EnforceFirstLoginSetup` répond **403 `first_login_required`** sur tout, tant
   que `first_login_completed_at` est nul ;
2. la **seule** ligne de tout le backend qui remplit ce champ est dans
   `TwoFactorService::confirmEnrolment()` ;
3. cette méthode ne peut pas aboutir.

**Un utilisateur nouvellement créé ne peut donc jamais finir son premier login
par le produit.** C'est pourquoi la commande de §5.1 pose
`first_login_completed_at` et `totp_secret` à la main : ce n'est pas un
raccourci de confort, c'est le contournement d'un chemin cassé.

**Mesuré**, avec une session fraîchement ouverte
(`POST /api/v1/auth/2fa/setup`) :

```
{"message":"SQLSTATE[42703]: Undefined column: 7 ERROR:  column \"two_factor_secret\" of relation \"users\" does not exist
LINE 1: update \"users\" set \"two_factor_secret\" = $1, \"updated_at\" = ...
(Connection: pgsql, Host: postgres, Port: 5432, Database: axion_crm, SQL: update \"users\" set \"two_factor_secret\" = ...)"}
```

L'écriture échoue avant tout `COMMIT` : le compte n'est pas abîmé par la
tentative (`totp_secret` et `totp_enabled_at` inchangés, vérifié après coup).

Aucun test ne couvre `2fa/setup` ni `2fa/confirm` — `backend/tests/Feature/Auth/`
ne contient que `LoginTest.php` et `OnboardingTourTest.php`. D'où
l'invisibilité.

La **vérification** TOTP, elle, fonctionne : `TwoFactorService::verify()` lit
`$user->two_factor_secret ?? $user->totp_secret`, et comme le premier n'est pas
une colonne il vaut toujours `null`, donc c'est `totp_secret` qui sert. Prouvé
en curl et au navigateur (§6).

### 9.3 🔴 La 2FA ne garde rien : la session est pleinement authentifiée avant le code

Mesuré, avec la session issue du seul `POST /auth/login` et **sans** avoir
soumis de code :

```
GET /api/v1/auth/me  → 200
{"user":{...},"roles":["owner"]}
```

`TwoFactorController::verify()` pose bien `2fa_passed_at` en session — mais
`grep -rn "2fa_passed_at" backend/` ne rend **qu'une seule ligne : celle qui
l'écrit**. Aucun middleware, aucune policy, aucun contrôleur ne le lit. Le
second facteur est donc un écran que le SPA choisit d'afficher, pas une
condition d'accès : un client qui ignore la redirection vers `/2fa` obtient les
mêmes droits. Le parcours de §6 est réel, mais il prouve que la 2FA
*fonctionne*, pas qu'elle *protège*.

### 9.4 `/users` est vert au test et vide en vérité

`UsersController::index()` fait
`->select([... 'two_factor_enabled' ...])` — la colonne fantôme de §9.2. La
requête lève `SQLSTATE[42703]`, un `catch (\Throwable)` l'avale, et l'API
répond **200** :

```
GET /api/v1/users → {"data":[],"degraded":true}
```

Le journal le dit, et le disait déjà avant cette session
(`storage/logs/laravel.log`) :

```
[2026-08-17 08:41:42] local.ERROR: users.index failed {"exception":"SQLSTATE[42703]: Undefined column: 7 ERROR:  column "two_factor_enabled" does not exist
[2026-08-18 12:53:12] local.ERROR: users.index failed {"exception":"SQLSTATE[42703]: Undefined column: 7 ERROR:  column "two_factor_enabled" does not exist
```

(la seconde ligne est la visite de `/users` par le spec, run du 2026-08-18)

L'écran affiche donc une liste vide alors que le workspace compte deux
utilisateurs. Le spec le voit passer : il n'y a ni écran d'erreur, ni 401. Le
drapeau `degraded: true` est le seul témoin, et **rien dans l'interface ne
l'affiche**. C'est l'illustration du piège que ce runbook doit éviter : un
écran « ouvert » n'est pas un écran « qui marche ».

### 9.5 Les 15 specs e2e préexistants échouent, faute de locale épinglée

`playwright.config.ts` ne fixe aucune `locale`, et aucun des 15 specs de
`tests/e2e/` n'en pose une (`grep` : la seule occurrence est
`console-locale.spec.ts`). Chromium annonce donc `en-US`, `i18next` détecte
l'anglais par `navigator`, et la console s'affiche en anglais — mesuré :

```
- heading "Sign in" [level=1]
- textbox "Email address"
- button "Sign in"
```

… pendant que `auth.spec.ts` cherche `getByRole('heading', {name: /connexion/i})`.
L'échec ressemble à « la page ne charge pas ». Remède d'une ligne : `locale:
'fr-FR'` dans `use:` de `playwright.config.ts` — **hors périmètre de cette
ligne, à faire par qui possède ce fichier.**

### 9.6 La base locale est vide

0 entreprise, 0 contact, 0 média, 0 campagne, 0 audience. Deux workspaces, deux
utilisateurs. Aucun jeu de données de démonstration n'a été semé — délibérément,
pour ne pas écrire dans une base que d'autres chantiers utilisent en parallèle.
Conséquence : les cinq écrans de détail (†) et les quatre écrans v2 sont
vérifiés **à vide**. Les grilles, tris, filtres, pagination et actions de masse
ne sont donc **pas** vérifiés.

### 9.7 L'API est lente, et `artisan` est très lent

Mesuré sur une base vide :

| Appel | À froid | À chaud |
|---|---|---|
| `GET /api/v1/crm/contacts-hub` | 21,4 s | 5,8 s |
| `GET /api/v1/crm/candidates` | 8,9 s | 2,2 s |
| `GET /api/v1/auth/me` | — | 1,6 s |
| `php artisan --version` (CLI) | **78,9 s** | 78,9 s |

Ce n'est pas une régression : c'est le coût du bind-mount Windows.
`opcache-local.ini` sauve les requêtes HTTP (26 s → 2-6 s) mais ne peut rien
pour un processus CLI neuf. Deux conséquences pratiques : ne jamais donner
moins de 3 minutes à un outil qui appelle `artisan`, et prévoir ~4 minutes pour
le run complet du spec.

### 9.8 Défauts mineurs, constatés et non corrigés

- **`GET /api/v1/config/features` sans `Accept: application/json` → 500
  « Route [login] not defined »** au lieu d'un 401. Comportement Laravel
  standard pour une requête qui accepte du HTML sous `auth:sanctum` dans une
  application sans route `login` nommée.
- **Le sélecteur de workspace affiche `Workspace 20cd81`**, c'est-à-dire les
  premiers caractères de l'UUID, et non `Axion-IA`. Visible sur les 34
  captures.
- **Le tour d'accueil est en anglais** (`Next (Step 1 of 7)`) alors que le
  reste de l'interface est en français, locale `fr-FR` posée. Ses libellés ne
  passent pas par `i18next`.
- **`playwright.config.ts` démarre `pnpm dev` en l'absence de `CI`**, et le run
  meurt sur `Timed out waiting 60000ms from config.webServer` alors que la pile
  Docker sert déjà. D'où le `CI=1` de §6.
