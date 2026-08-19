# AGENT 35 — Auditeur d'authentification

**Référence mesurée** : `main = e8924b81ad64c0b236acd99ac5cbac4cd68eada7` (`e8924b8`).
⚠️ **`HEAD` a bougé pendant ma session** — relu en fin de session : `7be3753`, puis `d95de24`
(7 commits de l'audit lui-même). **Vérifié** : `git diff --name-only e8924b8..HEAD -- backend/
frontend/ infra/ workers/` ne rend qu'un seul fichier, `infra/scripts/verifier-serveur-http.sh`,
**neuf et hors de mon périmètre**. **Aucun fichier de mon périmètre n'a bougé** : tous mes
constats restent valides sur `d95de24`. Aucune PR n'a été ouverte par moi.
**Atelier** : conteneur **dédié** `a35-api` (image `axion-crm-pro-api`, réseau `axion-crm`,
port 58135), bases **dédiées** `axion_crm_a35` et `axion_crm_test_a35`. Aucun conteneur ni
aucune base partagée avec les autres agents n'a été muté.
**Production** : **aucune requête n'a été émise vers la production.** Voir §5.
**Aucun fichier du produit n'a été modifié** : la sonde vit dans `/tmp/a35` du conteneur ;
ses sources sont archivées dans `04_PREUVES/agent-35/sonde/` et `sonde2/`.
**Inventaire des preuves** : `04_PREUVES/agent-35/README.txt`. **Pièges de mesure rencontrés**
(à lire avant de rejouer quoi que ce soit) : `04_PREUVES/agent-35/PIEGES-DE-MESURE-RENCONTRES.txt`.

---

## 0. ÉTAT : LES 14 CONSTATS SONT CORRIGÉS, TESTÉS, COMMITÉS

Mandat reçu de Will **en cours de session** : « je veux que tu corriges et fixes au fur et
à mesure », en appliquant la doctrine autopilote de
`Axion-IA/_AUDIT/PROMPT-AUDIT-QUALIOPI-E2E-50-AGENTS-2026-08-18.md`. Ce rapport n'est donc
plus un rapport d'audit seul : **les défauts qu'il décrit sont réparés.**

| | |
|---|---|
| Branche | `fix/a35-authentification`, commit **`da994be`**, depuis `origin/main` = `e8924b8` |
| Worktree | `crmpro-wt-a35-auth` (dédié ; ⛔ `crmpro-wt-etape1a` jamais approché) |
| Fichiers | **21** : 16 modifiés, 3 créés, dont 1 middleware et 1 migration |
| Gardes | **25 tests neufs**, chacun **vu rougir avant** son correctif |
| Suite auth complète | **69 passés / 0 échec / 0 avertissement**, avec `failOnWarning=true` et `failOnRisky=true` — la sévérité de la CI |
| Vert de référence | 44 tests avant, **69 après** : amélioré de 25, pas seulement retrouvé |
| Poussé sur `origin` | **NON** — bloqué par la couche de permission de l'outillage. Voir §6 « RESTE WILL » |

### Vague 2 — au-delà de mon périmètre, sur ordre de Will

Will a demandé en cours de session : « il faut que tu fixes tous les problèmes non ? ».
J'ai donc pris les S0 des autres agents, dans l'ordre du danger et de la tractabilité.
Chacun garde le nom de qui l'a trouvé — je ne m'approprie aucun constat.

| Constat | Agent | Ce qui était cassé | Commit |
|---|---|---|---|
| **A-015 / D24-001** (S0) | 24 | L'écran d'accueil s'effaçait entièrement dès qu'`audit_logs` portait une ligne — et la connexion en écrit une | `bdd25eb` |
| **D22-001** (S0) | 22 | **Aucun écran** n'appelait `/auth/2fa/setup` : le serveur exigeait un enrôlement impossible à déclencher | `26fa980` |
| **B15-010** (S0) | 15 | Routes RGPD sans aucune permission : un `viewer` pouvait déposer **et traiter** une demande d'effacement | `46848d4` |
| **B16-004** (S0) | 16 | `GET /audit-logs` sans garde : le `viewer` recevait **200** et lisait le journal | `46848d4` |

**Bilan des deux vagues : 5 S0 fermés sur 32.** Suite complète des 16 suites touchées :
**119 tests verts, 0 échec, 0 avertissement**, à la sévérité de la CI.

⚠️ **Il reste 27 S0.** Ils ne sont pas dans mon périmètre et je ne les ai pas touchés :
couche d'autorisation inerte (11 policies jamais appelées), cloisonnement entre espaces
qui fuit, effacement RGPD qui n'efface pas, chaîne d'audit sans secret et tronquable,
signature HMAC forgeable, 6 services simulés en production, 26 tâches planifiées sans
contexte d'espace, saturation disque prévue au 6 octobre. Voir `07_RAPPORT-FINAL.md`.

**Preuves de la réparation** :
`04_PREUVES/agent-35/gardes-ROUGE-avant-correctif.txt` (le rouge, attribué garde par garde),
`gardes-VERT-apres-correctif.txt`, `suite-auth-VERTE-apres-correctif.txt`,
et `JOURNAL-REPARATION.md` (décisions prises sans demander, et les quatre fois où c'est ma
sonde qui avait tort).

⚠️ **Quatre tests du dépôt exigeaient le défaut** et ont été **réécrits, pas supprimés**,
avec leur histoire en tête de test : `HibpCheckerTest` (exigeait le fail-open),
`LoginTest` (exigeait le refus d'un mot de passe court à la connexion),
`OwnerUserSeederTest` (exigeait l'écriture du secret en clair),
`MagicLinkServiceTest` (exigeait la ligne orpheline). Un test vert n'est une bonne
nouvelle que si ce qu'il exige est ce qu'on veut.

---

## 1. Grille

Légende : ✅ conforme · ⚠️ défaut · 🔴 grave · ⬜ non vérifié (raison donnée)

| # | Objet du périmètre | Existe | Ce qui a été constaté | Comment | Verdict | Constat |
|---|---|---|---|---|---|---|
| 1 | `bootstrap/app.php` → `withExceptions` | oui | bloc **vide** ; ni `shouldRenderJsonWhen`, ni `redirectGuestsTo` | lecture | 🔴 | F35-001 |
| 2 | `routes/web.php` | oui | 9 lignes, une seule route `/` ; **aucune route nommée `login`** dans toute l'app | **joué** (`Route::has`) | 🔴 | F35-001 |
| 3 | Journal produit par un refus d'authentification | — | **8 475 octets** de trace par requête refusée (valeur stable sur 4 mesures) | **mesuré** | 🔴 | F35-001 |
| 4 | `AuthService::attemptLogin()` | oui | double plafond 5/min/IP (route) + 5/min/IP+e-mail (service) ; verrou compte 10 échecs / 24 h | lecture | ⚠️ | F35-009, F35-012 |
| 5 | `AuthService::logout()` | oui | `Auth::logout()` + `session()->invalidate()` + `regenerateToken()` | lecture | ✅ | — |
| 6 | `TwoFactorService::startEnrolment()` | oui | écrit `two_factor_secret` — **colonne inexistante en base** | **joué** (erreur SQL 42703) | 🔴 | F35-002 |
| 7 | `TwoFactorService::confirmEnrolment()` | oui | écrit 3 colonnes inexistantes ; **seul** endroit qui pose `first_login_completed_at` | **joué** + recherche exhaustive | 🔴 | F35-002 |
| 8 | `TwoFactorService::verify()` | oui | TOTP fenêtre ±1 pas (±30 s) ; codes de secours retirés du tableau après usage | lecture | ⚠️ | F35-002 |
| 9 | `MagicLinkService::issue()` | oui | jeton `Str::random(64)` (~381 bits), stocké en SHA-256, TTL 15 min | lecture + schéma | ✅/⚠️ | F35-013 |
| 10 | `MagicLinkService::consume()` | oui | usage unique réel (`consumed_at`) ; retrouve l'utilisateur par **e-mail**, pas par `user_id` | lecture + schéma | ⚠️ | F35-013 |
| 11 | `HibpChecker::getBreachCount()` | oui | réseau coupé ⇒ **0** ; réseau OK ⇒ **9 999 999** pour le même mot de passe | **joué** (MockHandler) | 🔴 | F35-004 |
| 12 | `NotPwnedPassword` | oui | réseau coupé ⇒ **accepte** le mot de passe `password` ; branchée sur **un seul** point d'entrée | **joué** | 🔴 | F35-004 |
| 13 | `AuthController::login()` | oui | 419 explicite hors domaine stateful ; 422 sinon ; jamais 500 | lecture + test du dépôt | ✅ | — |
| 14 | `AuthController::logout/me/onboarding` | oui | re-contrôlent `$request->user()` après `auth:sanctum` | lecture | ✅ | — |
| 15 | `MagicLinkController::request()` | oui | réponse identique que l'e-mail existe ou non | lecture | ⚠️ | F35-009 |
| 16 | `MagicLinkController::verify()` | oui | 401 sur jeton inconnu/expiré/consommé | lecture | ✅ | — |
| 17 | `PasswordResetController::forgot()` | oui | réponse identique ; écrit une ligne pour **tout** e-mail, même inconnu | lecture | ⚠️ | F35-009 |
| 18 | `PasswordResetController::reset()` | oui | usage unique par suppression de la ligne (lu) ; **contrôle d'expiration inopérant** | **joué** (Carbon 3.13.0, valeur signée) | 🔴 | F35-005 |
| 19 | `TwoFactorController::verify()` | oui | pose `2fa_passed_at` en session — **relu nulle part dans tout `app/`** | **joué** (recherche exhaustive) | 🔴 | F35-003 |
| 20 | `EnforceFirstLoginSetup` | oui | 403 tant que `first_login_completed_at` est nul ; liste blanche par égalité stricte | lecture | 🔴 | F35-002 |
| 21 | `PersonalAccessToken` (modèle) | oui | bien enregistré (`AppServiceProvider:83`) ; casts conformes à l'UUID | lecture | ✅ | — |
| 22 | Révocation d'un jeton d'API par suppression | — | jeton valide ⇒ **200** ; jeton supprimé ⇒ **401** | **joué** | ✅ | — |
| 23 | `config/sanctum.php` | oui | `expiration => null` ; `expires_at` du jeton créé = **NULL** | **joué** | ⚠️ | F35-010 |
| 24 | `config/session.php` | oui | cookie posé : `httpOnly=true`, `sameSite=lax`, expiration **+120 min** ; `secure` piloté par variable (prod = `true`) | **joué** (en-tête `Set-Cookie`) | ✅ | — |
| 25 | `infra/scripts/definir-mot-de-passe-crm.sh` — entrée standard | oui | le mot de passe est bien lu sur stdin | lecture | ✅ | — |
| 26 | `infra/scripts/definir-mot-de-passe-crm.sh` — transmission | oui | **le mot de passe est dans `argv` de `docker exec`**, contrairement à son en-tête | **joué** (énumération des processus) | ⚠️ | F35-007 |
| 27 | `infra/scripts/definir-mot-de-passe-crm.sh` — fins de ligne | oui | **LF pur** (0 octet `0x0d`) : hors périmètre A-003 | **joué** (`od` + `git ls-files --eol`) | ✅ | — |
| 28 | `infra/scripts/definir-mot-de-passe-crm.sh` — aiguillage de sortie | oui | `case *OK*` avant `*INTROUVABLE*` ; `set -uo pipefail` sans `-e` | lecture | ⚠️ | F35-014 |
| 29 | `OwnerUserSeeder` (chemin d'identifiant) | oui | mot de passe généré **en clair** sur disque, **sans chmod**, + sur la sortie standard | lecture + fichier présent | ⚠️ | F35-008 |
| 30 | `LoginRequest` | oui | `Password::min(12)` appliqué **à la connexion** | lecture + test du dépôt | ⚠️ | F35-011 |
| 31 | `GET /api/v1/users` | oui | `select` d'une colonne inexistante `two_factor_enabled` | lecture + schéma joué | 🔴 | F35-002 |
| 32 | A-001 rejoué **en production** | — | **non joué par moi** ; joué par un autre agent, **même résultat** (500 sans `Accept`, 401 avec) | preuve d'un tiers | 🔴 | F35-001, §5 |
| 33 | Parcours navigateur réel (Chrome) sur `/login` | — | **non joué** — l'atelier partagé ne répondait plus en HTTP | ⬜ | ⬜ | §5 |
| 34 | Matrice A-001 à 5 profils de client | — | 500 pour navigateur / curl / sans `Accept` ; **401** pour JSON et SPA | **joué** (témoin d'en-têtes d'abord) | 🔴 | F35-001 |
| 35 | Correctif A-001 prouvé à 4 états | — | (0)=500 · (1) seul=500 · (2) seul=500 · **(3) les deux=401** | **joué** | 🔴 | F35-001 |
| 36 | Contournement de la 2FA rejoué en session | — | **non joué** — banc en `APP_ENV=local`, les POST partent en 419 | ⬜ | ⬜ | §5 |
| 37 | 20 tentatives de connexion / temps d'énumération | — | **non joué** — même cause | ⬜ | ⬜ | §5 |
| 38 | `GET /broadcasting/auth` sans authentification | oui | **200**, corps vide — hors du groupe `api`, donc hors des deux gardes | **joué**, **non conclu** | ⬜ | §5 |

---

## 2. La cause exacte de A-001, et le correctif

### La chaîne, frame par frame

Trace complète relevée dans `backend/storage/logs/laravel.log` (archivée dans
`04_PREUVES/agent-35/f35-001-cause-exacte.txt`) :

```
#0 Illuminate/Foundation/helpers.php(870):                    UrlGenerator->route('login', Array, true)
#1 Illuminate/Foundation/Configuration/ApplicationBuilder.php(278):  route('login')
#2 [internal function]:                                       ApplicationBuilder->{closure}(Request)
#3 Illuminate/Auth/Middleware/Authenticate.php(117):          call_user_func(Closure, Request)
#4 Illuminate/Auth/Middleware/Authenticate.php(104):          Authenticate->redirectTo(Request)
#5 Illuminate/Auth/Middleware/Authenticate.php(87):           Authenticate->unauthenticated(Request, Array)
#6 Illuminate/Auth/Middleware/Authenticate.php(61):           Authenticate->authenticate(Request, Array)
#7 Illuminate/Pipeline/Pipeline.php(219):                     Authenticate->handle(Request, Closure, 'sanctum')
```

Deux faits, et un seul manque :

1. **Laravel 12 pose un rappel de redirection, toujours.** `ApplicationBuilder::withMiddleware()`
   ligne 278 construit l'objet `Middleware` en appelant **inconditionnellement**
   `->redirectGuestsTo(fn () => route('login'))`, *avant* d'exécuter le rappel fourni par
   l'application. `bootstrap/app.php` ne le remplace jamais.
2. **Aucune route nommée `login` n'existe.** `routes/web.php` ne déclare que `/`.
   Les 38 routes nommées de l'application sont celles de Horizon, L5-Swagger, Sanctum,
   `saved-views.*`, `internal.*` et `storage.*`. `Route::has('login')` rend **`false`**.

Donc, dès qu'un client non authentifié touche une route `auth:sanctum` **et que
`$request->expectsJson()` est faux**, `Authenticate::unauthenticated()` (l.104) appelle
`redirectTo()`, qui appelle `route('login')`, qui lève `RouteNotFoundException` :
**500 au lieu de 401**.

### Le point qu'il faut nommer, parce qu'il change l'ampleur — **mesuré**

```
----- [1] TEMOIN : la sonde envoie-t-elle vraiment ses en-tetes ? -----
  navigateur    Accept recu='text/html,application/xhtml+xml,*/*;q=0.8'      expectsJson=false
  curl */*      Accept recu='*/*'                                            expectsJson=false
  aucun Accept  Accept recu='text/html,...,application/xml;q=0.9,*/*;q=0.8'  expectsJson=false
  client JSON   Accept recu='application/json'                               expectsJson=true
  SPA axios     Accept recu='application/json'                               expectsJson=true

----- [2] A-001 : etendue reelle -----
route('login') existe ? NON
                          navig curl  aucun json  SPA
  GET /api/v1/auth/me       500   500   500   401   401
  GET /api/v1/contacts      500   500   500   401   401
  exception (profil navigateur) = Symfony\Component\Routing\Exception\RouteNotFoundException
                                  :: Route [login] not defined.
  corps (profil client JSON)    = {"message":"Unauthenticated."}
```

`Authenticate::unauthenticated()` teste bien `$request->expectsJson()` avant d'appeler
`redirectTo()`. **Le SPA n'est donc pas touché** : `frontend/src/lib/api.ts:8` envoie
`Accept: application/json` **et** `X-Requested-With: XMLHttpRequest` sur chaque requête.
Sont touchés : `curl`, Postman, un client machine, une sonde de supervision, un navigateur
qui ouvre une URL d'API à la main, et tout futur client mobile. Le §1 du prompt d'audit
(« un utilisateur déconnecté ne peut pas être renvoyé vers l'écran de connexion ») est donc
**exact pour tout ce qui n'est pas le SPA**, et **inexact pour le SPA lui-même**, dont
l'intercepteur reçoit bien un 401 et redirige vers `/login` (`api.ts:30-32`).

⚠️ **Piège n° 19 rencontré en direct, et sur ma propre sonde.** Mon premier jet de matrice
utilisait `TestCase::call()`, qui — contrairement à `get()`/`post()`/`getJson()` — **n'envoie
pas** `$defaultHeaders`. Les cinq colonnes de profil client rendaient donc cinq fois la même
valeur (500 partout) et j'ai failli conclure que le SPA était touché. La sortie fautive est
conservée, marquée, dans `04_PREUVES/agent-35/sonde-run1-INVALIDE-headers-non-envoyes.txt`.
La sonde définitive commence par un **témoin** qui affiche, pour chaque profil, l'en-tête
`Accept` réellement reçu côté serveur et la valeur de `expectsJson()`.

### Le correctif : deux lignes, et **les deux** sont nécessaires

Le crash a lieu à **deux endroits distincts**, et corriger l'un sans l'autre ne suffit pas :

- **(A)** dans le middleware `Authenticate` (frame #4 ci-dessus) ;
- **(B)** dans `Illuminate\Foundation\Exceptions\Handler::unauthenticated()`, qui fait
  `redirect()->guest($e->redirectTo($request) ?? route('login'))` — donc rappelle
  `route('login')` pour toute requête non-JSON, même si (A) est réparé.

Dans `backend/bootstrap/app.php` :

```php
    ->withMiddleware(function (Middleware $middleware) {
        // (A) Cette API n'a pas d'écran de connexion : il n'y a rien vers quoi rediriger.
        //     Sans cette ligne, Laravel 12 pose lui-même `redirectGuestsTo(fn () => route('login'))`
        //     (ApplicationBuilder l.278) et toute requête non-JSON part en 500.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->statefulApi();
        // ... inchangé
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // (B) Tout ce qui vit sous /api répond en JSON, quel que soit l'en-tête Accept
        //     du client. Un 401 est un contrat ; un 500 dit « le serveur est cassé ».
        $exceptions->shouldRenderJsonWhen(
            fn ($request, $e) => $request->is('api/*') || $request->expectsJson()
        );
    })
```

**Coût** : 2 lignes de code, 1 test. **Risque** : nul côté SPA (son comportement ne change
pas : il recevait déjà 401). Aucun écran de connexion serveur n'existe et aucun n'est prévu :
la redirection est portée par le SPA.

### Le test qui rougit dessus

La sonde `04_PREUVES/agent-35/sonde/tests/A35AuthProbeTest.php` contient
`test_02_correctif_propose_fait_passer_a_401()`, qui mesure **quatre états** sur la même
requête `GET /api/v1/auth/me` avec `Accept: text/html` :

**Mesuré** (`04_PREUVES/agent-35/sonde-courte-2.txt`), sur `GET /api/v1/auth/me` :

```
  (0) tel quel, comme sur main e8924b8       navigateur=500  json=401
  (1) shouldRenderJsonWhen SEUL              navigateur=500  json=401
  (2) redirectGuestsTo(null) SEUL            navigateur=500  json=401
  (3) LES DEUX — le correctif propose        navigateur=401  json=401
      corps de (3) = {"message":"Unauthenticated."}
```

| état | (A) `redirectGuestsTo(null)` | (B) `shouldRenderJsonWhen` | navigateur | JSON |
|---|---|---|---|---|
| 0 — tel quel sur `e8924b8` | non | non | **500** | 401 |
| 1 — (B) seul | non | oui | **500** (le crash est en amont, dans `Authenticate`) | 401 |
| 2 — (A) seul | oui | non | **500** (le handler rappelle `route('login')`) | 401 |
| 3 — les deux | oui | oui | **401** | 401 |

L'état (1) est instructif : le corps du 500 devient `{"message":"Server Error"}` — le rendu
est bien passé en JSON, mais la panne, elle, est toujours là. **Une demi-correction produit
une erreur mieux habillée, pas une erreur en moins.**

⚠️ **Piège rencontré, à connaître avant de rejouer ce test** : dans le banc de test, le
gestionnaire d'exceptions est celui de **Collision**
(`NunoMaduro\Collision\Adapters\Laravel\ExceptionHandler`), qui **n'a pas** de méthode
`shouldRenderJsonWhen()` — un appel direct rend
`Error: Call to undefined method ... ::shouldRenderJsonWhen()`. Il faut reposer le vrai
gestionnaire avant de le configurer :
`$this->app->instance(ExceptionHandler::class, tap(new \Illuminate\Foundation\Exceptions\Handler($this->app), fn ($h) => $h->shouldRenderJsonWhen(...)));`

Le test **échoue sur `e8924b8`** (`assertSame(500, $etat0)` sert de témoin négatif, puis
`assertSame(401, $etat3)`), et passe une fois `bootstrap/app.php` corrigé. Version à poser
dans le dépôt, à `backend/tests/Feature/Auth/` :

```php
test('une route protegee repond 401, jamais 500, quel que soit l en-tete Accept', function () {
    foreach ([
        ['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8'],  // navigateur
        ['Accept' => '*/*'],                                        // curl
        ['Accept' => 'application/json'],                           // client machine
        [],                                                         // aucun en-tete
    ] as $entetes) {
        $reponse = $this->get('/api/v1/auth/me', $entetes);
        expect($reponse->status())->toBe(401);
    }
});
```

⚠️ Ce test **doit** passer par `$this->get($uri, $entetes)` et non `$this->call('GET', $uri)` :
avec `call()`, les en-têtes ne partent pas et le test devient aveugle (cf. piège ci-dessus).

### Le coût caché : 8 475 octets de journal par requête refusée

Chaque 500 écrit une entrée complète, trace comprise, dans `laravel.log`. Mesure :
`grep -bo 'local.ERROR: Route [login] not defined' laravel.log` donne des écarts de
**8 475 octets**, quatre fois de suite. C'est un contributeur direct au constat **A-007**
(journal de 265 Mo, +133 Mo/jour en production) : chaque sonde, chaque robot, chaque requête
non authentifiée sans `Accept: application/json` coûte 8,5 Ko de disque. Corriger A-001
réduit mécaniquement A-007.

---

## 3. Constats

**Positionnement par rapport à ce qui existe déjà** — vérifié en fin de session contre
`02_CONSTATS.md` et `02bis_P2-CONSOLIDATION.md` sur `d95de24`, pour ne rien compter deux fois :

| Constat | Statut vis-à-vis de l'existant |
|---|---|
| F35-001 | **complète A-001** (déjà trouvé, déjà abaissé à S2) : j'y ajoute la cause exacte, le correctif prouvé à 4 états, et le coût en journal |
| F35-002 | **confirme A07-001** (S0, déjà porté) par un chemin indépendant, **et l'étend** : `GET /api/v1/users` casse pour la même raison |
| F35-003 · F35-004 · F35-005 · F35-006 · F35-007 · F35-008 · F35-009 · F35-010 · F35-011 · F35-012 · F35-013 · F35-014 | **nouveaux** — aucune occurrence de `2fa_passed_at`, `HibpChecker`, `diffInMinutes`, `argv`, `owner-initial-password`, `sanctum.expiration`, `Password::min(12)`, `locked_until` ni `magic_links` dans les constats consolidés |

Les mentions existantes de « fail-open » portent sur le **HMAC** (`F37-001`), pas sur HIBP :
F35-004 est bien un second fail-open, sur un autre chemin.

### [F35-001] A-001 : la cause exacte, le correctif en deux lignes, et son coût caché en journal
- Sévérité      : S2 *(alignée sur A-001, abaissée de S1 à S2 par la consolidation — mon
  périmètre confirme cette sévérité : le SPA n'est pas touché)*
- Domaine       : backend / sécurité
- Référence     : main e8924b8
- Emplacement   : `backend/bootstrap/app.php:44` (bloc `withExceptions` vide) et `backend/routes/web.php` (aucune route nommée `login`)
- Constat       : sans authentification, une requête vers une route `auth:sanctum` dont l'en-tête `Accept` n'est pas JSON provoque `RouteNotFoundException: Route [login] not defined` et un 500, au lieu du 401 annoncé par la documentation OpenAPI de `AuthController::me()`.
- Preuve        : `04_PREUVES/agent-35/sonde-courte-1.txt` — matrice mesurée sur `GET /api/v1/auth/me` et `GET /api/v1/contacts` × 5 profils de client : **500** pour navigateur, `curl` et « sans en-tête `Accept` » ; **401 `{"message":"Unauthenticated."}`** pour client JSON et SPA axios. Exception relevée : `Symfony\Component\Routing\Exception\RouteNotFoundException :: Route [login] not defined.` La cause frame par frame est dans `04_PREUVES/agent-35/f35-001-cause-exacte.txt`.
- Témoin négatif: **double**. (a) Les deux profils JSON rendent **401** sur les mêmes routes, au même instant, dans la même sonde : le contrôle sait donc répondre correctement — c'est bien l'en-tête `Accept` qui décide. (b) La sonde ouvre par un **témoin d'instrumentation** qui affiche, pour chacun des 5 profils, l'en-tête `Accept` réellement reçu côté serveur et la valeur de `expectsJson()` — sans quoi la matrice ne prouverait rien (cf. le piège rencontré, ci-dessus). (c) `route('login')` rend `false` à `Route::has()` alors que les **38** autres routes nommées sont bien énumérées.
- Impact        : tout client non-navigateur (supervision, client machine, futur client mobile, `curl`, Postman) reçoit « le serveur est cassé » là où la vérité est « vous n'êtes pas connecté ». Corollaire mesuré : **8 475 octets** de journal par requête, qui alimentent A-007.
- Reproduction  : `curl -s -o /dev/null -w '%{http_code}' -H 'Accept: text/html' http://<api>/api/v1/auth/me` → 500 ; avec `-H 'Accept: application/json'` → 401.
- Correctif     : deux lignes dans `bootstrap/app.php` (`$middleware->redirectGuestsTo(fn () => null);` **et** `$exceptions->shouldRenderJsonWhen(fn ($r, $e) => $r->is('api/*') || $r->expectsJson());`) + le test ci-dessus. Coût : < 1 h, correction P3.
- Statut        : **CORRIGÉ** — `bootstrap/app.php` : `redirectGuestsTo(fn () => null)` + `shouldRenderJsonWhen(api/*)`. Garde vue rougir puis verte ; commit `da994be`, branche `fix/a35-authentification`. Reste à pousser et fusionner (§6) — **complète A-001, ne le redécouvre pas.** L'étendue (500 sans `Accept`, 401 avec) avait déjà été trouvée et corrigée par la consolidation, qui a abaissé A-001 à S2. Ce que j'apporte, et qui n'y figurait pas : **(a)** la cause exacte, frame par frame, et le fait qu'elle vient d'un rappel posé **par le framework lui-même** (`ApplicationBuilder::withMiddleware()` l.278), pas d'un oubli du produit ; **(b)** le correctif en **deux** lignes, avec la démonstration à quatre états qu'**aucune des deux ne suffit seule** ; **(c)** le coût caché : **8 475 octets de journal par requête refusée**, contributeur direct de A-007.

### [F35-002] ⚠️ **CONFIRMATION INDÉPENDANTE de A07-001**, plus un second chemin de rupture que personne n'avait relevé : `GET /api/v1/users`
- Sévérité      : **S0** *(celle de A07-001 ; je ne rouvre pas le constat, je le confirme et je l'étends)*
- Domaine       : backend / sécurité / conformité
- Référence     : main e8924b8
- Emplacement   : `backend/app/Services/Auth/TwoFactorService.php:29,47,65-67,76-90` · `backend/app/Models/User.php:51-52,73-76` · `backend/app/Http/Controllers/Api/UsersController.php:33` · `backend/database/migrations/2026_05_16_000002_create_auth_tenant_audit_schema.php:52`
- Constat       : la table `users` porte `totp_secret`, `totp_enabled_at` et `totp_recovery_codes` ; le modèle et le service écrivent `two_factor_secret`, `two_factor_enabled` et `two_factor_recovery_codes`, qui n'apparaissent dans **aucune** des 58 migrations. **Ce fait est déjà porté par A07-001 (S0)** — je l'ai atteint indépendamment, par mon propre chemin, et je le confirme. **Ce que j'ajoute** : la même dérive casse un **second** point du produit, hors 2FA — `UsersController.php:33` fait un `select` de `two_factor_enabled`, donc `GET /api/v1/users` échoue pour la même raison. Ce chemin-là n'est pas dans A07-001, qui ne rapporte sur ce contrôleur que les 501 de `store/update/destroy`.
- Preuve        : `04_PREUVES/agent-35/f35-002-colonnes-2fa.txt` — colonnes réelles d'une base migrée à neuf, `grep` du code, et la panne relevée en base réelle : `SQLSTATE[42703] Undefined column: column "two_factor_secret" of relation "users" does not exist`, pile `#7 app/Services/Auth/TwoFactorService.php(30)`.
- Témoin négatif: le **même** `grep`, dans le **même** dossier `database/migrations/`, trouve bien `totp_secret` (fichier `2026_05_16_000002`, l.52). La recherche sait donc voir une colonne quand elle existe : c'est bien `two_factor_*` qui est absent.
- Impact        : `POST /auth/2fa/setup` et `POST /auth/2fa/confirm` échouent. Or `confirmEnrolment()` est le **seul** endroit de tout `app/` qui pose `first_login_completed_at` (vérifié par `grep`), et `EnforceFirstLoginSetup` renvoie **403 `first_login_required`** sur toute route hors liste blanche tant que ce champ est nul. Un compte neuf ne peut donc **jamais** franchir le premier login : il tourne indéfiniment entre `/auth/me`, `/auth/logout` et trois routes 2FA qui échouent. En prime, `GET /api/v1/users` `select` la colonne inexistante `two_factor_enabled` et casse pour la même raison.
- Reproduction  : base migrée à neuf ; créer un utilisateur avec `first_login_completed_at = null` ; se connecter ; appeler `POST /api/v1/auth/2fa/setup` ; puis `GET /api/v1/contacts`.
- Correctif     : une migration qui **renomme** les trois colonnes du code vers celles de la base (`two_factor_secret` → `totp_secret`, `two_factor_recovery_codes` → `totp_recovery_codes`) et ajoute `two_factor_enabled` **ou**, mieux, aligner le code sur le schéma (3 fichiers : `TwoFactorService`, `User`, `UsersController`) — le schéma est cohérent, c'est le code qui a dérivé. Ajouter un test qui joue l'enrôlement de bout en bout. Coût : 2-3 h.
- Statut        : **CORRIGÉ** — `TwoFactorService`, `User`, `UsersController` alignés sur `totp_*` + migration. Garde vue rougir puis verte ; commit `da994be`, branche `fix/a35-authentification`. Reste à pousser et fusionner (§6) — **à fusionner avec A07-001**, dont il ne faut pas gonfler le compte. Ma contribution propre se réduit à deux choses : la **troisième mesure indépendante** du même défaut (par un autre agent, un autre atelier, une autre méthode), et le **second chemin de rupture** `GET /api/v1/users`.

### [F35-003] La double authentification n'est jamais exigée par le serveur : `2fa_passed_at` est écrit et jamais relu
- Sévérité      : S1
- Domaine       : backend / sécurité
- Référence     : main e8924b8
- Emplacement   : `backend/app/Http/Controllers/Api/Auth/TwoFactorController.php:68` · `frontend/src/features/auth/LoginPage.tsx:69-70`
- Constat       : `TwoFactorController::verify()` écrit `2fa_passed_at` dans la session ; une recherche sur tout `app/`, `routes/` et `tests/` ne trouve **aucune autre occurrence** de cette clé. Aucun middleware, aucune garde ne la lit.
- Preuve        : `04_PREUVES/agent-35/f35-003-2fa-decorative.txt` — recherche exhaustive de `2fa_passed_at` sur `backend/app`, `backend/routes`, `backend/tests`, `backend/config` et `frontend/src` : **une seule occurrence**, `TwoFactorController.php:68`, et c'est celle qui écrit. La liste complète des middlewares déclarés dans `bootstrap/app.php` (l.20-42) ne contient aucun contrôle de double authentification.
- Témoin négatif: la **même** recherche, appliquée à `first_login_completed_at`, montre un drapeau écrit (`TwoFactorService:68`) **et relu** (`EnforceFirstLoginSetup:31`). La méthode sait donc distinguer un drapeau appliqué d'un drapeau décoratif. Un drapeau de session que rien ne relit ne peut, par construction, protéger aucune route.
- Impact        : la 2FA est **décorative**. La bascule vers `/2fa` est purement côté navigateur ; qui possède le mot de passe possède le CRM, 2FA activée ou non. Pour un CRM contenant 4,29 M de fiches de personnes, c'est un écart de conformité autant qu'un écart de sécurité.
- Reproduction  : activer la 2FA sur un compte, se connecter par `POST /auth/login`, puis appeler directement `GET /api/v1/contacts` avec le cookie de session, sans passer par `/auth/2fa/verify`.
- Correctif     : un middleware `EnsureTwoFactorPassed` posé sur le groupe protégé, qui renvoie 403 `two_factor_required` si `totp_enabled_at` est non nul et que la session ne porte pas de `2fa_passed_at` récent ; l'ajouter au groupe de `routes/api.php:83` avec la même liste blanche que `EnforceFirstLoginSetup`. Coût : 3-4 h avec les tests.
- Statut        : **CORRIGÉ** — middleware `EnsureTwoFactorPassed`, posé sur le groupe `api`. Garde vue rougir puis verte ; commit `da994be`, branche `fix/a35-authentification`. Reste à pousser et fusionner (§6)

### [F35-004] `HibpChecker` échoue en silence (« fail open »), et `NotPwnedPassword` n'est branchée qu'à un seul endroit
- Sévérité      : S1
- Domaine       : backend / sécurité
- Référence     : main e8924b8
- Emplacement   : `backend/app/Services/Auth/HibpChecker.php:50-52,75-84` · `backend/app/Rules/NotPwnedPassword.php:34-37`
- Constat       : sur `ConnectException`, `GuzzleException` ou tout `Throwable`, `getBreachCount()` journalise un avertissement et **retourne 0** ; la règle de validation conclut alors « mot de passe sain ». Le comportement est assumé en commentaire (« fail-open pour ne pas bloquer un user légitime »).
- Preuve        : **joué** — `04_PREUVES/agent-35/sonde-courte-2.txt`, bloc [9]. Coupure réseau simulée par un `MockHandler` Guzzle qui lève `ConnectException` (la branche `catch` exacte du produit) :
  ```
  RESEAU COUPE  : getBreachCount("password") = 0
  RESEAU COUPE  : NotPwnedPassword refuse "password" ? false   >>> ACCEPTE : FAIL-OPEN <<<
  ```
  Le mot de passe `password` — l'un des plus compromis au monde — **passe la validation**. Portée : `grep -rn "NotPwnedPassword" backend/app/` ne trouve que la règle elle-même et `PasswordResetController.php:77`.
- Témoin négatif: dans la même sonde, avec un `MockHandler` qui rend une réponse HIBP valide, `getBreachCount("password") = 9999999`. La détection fonctionne donc parfaitement quand la réponse arrive ; c'est bien la branche d'erreur qui l'annule. ⚠️ **Honnêteté sur ce témoin** : la seconde moitié (« la règle refuse ») n'a pas pu être observée — ma file de réponses simulées était épuisée par le premier appel, si bien que l'appel de la règle est retombé dans la branche d'erreur. Ce qui est prouvé est donc : *la détection rend 9 999 999 quand le réseau répond*, et *la règle accepte quand il ne répond pas*. Le seuil (`$count > 5`, `NotPwnedPassword.php:35`) fait le reste par lecture.
- Impact        : (a) une panne réseau, un DNS filtré, un pare-feu sortant ou une indisponibilité de `api.pwnedpasswords.com` suffit à désactiver silencieusement le contrôle. (b) Bien plus large : la règle n'est appelée **que** dans `PasswordResetController::reset()`. Ni `LoginRequest`, ni `OwnerUserSeeder`, ni `infra/scripts/definir-mot-de-passe-crm.sh` ne la traversent — le mot de passe du propriétaire n'a donc jamais été confronté à HIBP.
- Reproduction  : couper la résolution DNS de `api.pwnedpasswords.com` dans le conteneur, puis `POST /api/v1/auth/password/reset` avec `password = "password"` et un jeton valide.
- Correctif     : (1) distinguer « sain » de « inconnu » — faire remonter `null` en cas d'erreur et décider explicitement (refus + message « vérification indisponible, réessayez » sur un chemin sensible, ou acceptation tracée en journal d'audit) ; (2) brancher la règle sur **tous** les points d'entrée d'un mot de passe. Coût : 3-4 h.
- Statut        : **CORRIGÉ** — `HibpChecker` rend `null` quand il ne sait pas ; la règle refuse. Garde vue rougir puis verte ; commit `da994be`, branche `fix/a35-authentification`. Reste à pousser et fusionner (§6)

### [F35-005] Le jeton de réinitialisation de mot de passe n'expire jamais : le contrôle des 60 minutes est inopérant sous Carbon 3
- Sévérité      : S1
- Domaine       : backend / sécurité
- Référence     : main e8924b8
- Emplacement   : `backend/app/Http/Controllers/Api/Auth/PasswordResetController.php:88-91`
- Constat       : le code teste `now()->diffInMinutes($row->created_at) > 60`. Depuis Carbon 3, `diffIn*()` rend une valeur **signée** ; `now()` étant à gauche et la date de création dans le passé, le résultat est toujours négatif et la comparaison toujours fausse.
- Preuve        : `04_PREUVES/agent-35/f35-005-jeton-reset-sans-expiration.txt` — joué dans le conteneur, Carbon 3.13.0 : `now()->diffInMinutes(il y a 3 h) = -179,99 → > 60 ? false` ; `now()->diffInMinutes(il y a 30 j) = -43 199,98 → > 60 ? false`.
- Témoin négatif: la **même** méthode, opérandes inversés, rend `+179,99` et la comparaison devient `true` ; la variante `diffInMinutes($x, true)` rend `179,99`. La mesure sait donc distinguer un jeton périmé d'un jeton frais.
- Impact        : un lien de réinitialisation intercepté (boîte aux lettres compromise, journal de relais SMTP, capture d'écran, historique de navigateur) reste utilisable **indéfiniment**, jusqu'à ce qu'un autre `forgot` écrase la ligne — `password_reset_tokens` a `email` pour clé primaire, donc une seule ligne par adresse. Le contrat annoncé à l'utilisateur dans l'e-mail (« Valide 60 minutes », l.53) est faux.
- Reproduction  : insérer une ligne `password_reset_tokens` avec `created_at = now() - 30 days`, puis `POST /api/v1/auth/password/reset` avec le jeton correspondant → succès.
- Correctif     : `Carbon::parse($row->created_at)->diffInMinutes(now()) > 60`, ou mieux `Carbon::parse($row->created_at)->addMinutes(60)->isPast()`. Ajouter un test qui recule `created_at`. Coût : 15 min + test. **Chercher le même motif ailleurs** : `grep -rn "now()->diffIn" app/` — ce dépôt a migré vers Carbon 3 et ce piège est silencieux.
- Statut        : **CORRIGÉ** — `Carbon::parse($créé)->addMinutes(60)->isPast()`. Garde vue rougir puis verte ; commit `da994be`, branche `fix/a35-authentification`. Reste à pousser et fusionner (§6)

### [F35-006] La réinitialisation du mot de passe ne révoque aucun jeton d'API
- Sévérité      : S2
- Domaine       : backend / sécurité
- Référence     : main e8924b8
- Emplacement   : `backend/app/Http/Controllers/Api/Auth/PasswordResetController.php:98-103`
- Constat       : `reset()` change `password_hash`, remet `failed_login_count` à 0, efface `locked_until` et supprime la ligne de jeton. Il ne touche ni `personal_access_tokens`, ni la table `sessions`.
- Preuve        : `04_PREUVES/agent-35/f35-006-revocation-partielle.txt` — les 4 écritures du contrôleur, toutes sur `users` et `password_reset_tokens` ; et `grep -rn "tokens()->delete\|personal_access_tokens" backend/app/` qui ne trouve **rien**.
- Témoin négatif: le **même** fichier de preuve montre ce qui, à l'inverse, **est** couvert : `config/sanctum.php:19` déclare `authenticate_session`, et Sanctum l'insère réellement dans la pile des requêtes stateful (`EnsureFrontendRequestsAreStateful.php:48-56`) ; `AuthenticateSession` compare le hachage du mot de passe stocké en session à celui de l'utilisateur et déconnecte s'ils diffèrent. La méthode sait donc reconnaître une révocation qui fonctionne : elle en trouve une, et pas l'autre.
- Impact        : le geste que fait un utilisateur *parce qu'il pense être compromis* ne coupe pas l'accès de l'attaquant qui détient un jeton d'API — les requêtes porteuses d'un `Authorization: Bearer` ne traversent pas la pile stateful et échappent donc à `AuthenticateSession`. Les **sessions web**, elles, sont bien invalidées : le constat porte uniquement sur les jetons d'API.
- Reproduction  : créer un jeton d'API, réinitialiser le mot de passe, rappeler `/auth/me` avec le jeton.
- Correctif     : dans `reset()`, après `$user->save()` : `$user->tokens()->delete();` et, si `SESSION_DRIVER=database`, `DB::table('sessions')->where('user_id', $user->id)->delete();`. Coût : 30 min + test.
- Statut        : **CORRIGÉ** — `$user->tokens()->delete()` + purge des sessions en base. Garde vue rougir puis verte ; commit `da994be`, branche `fix/a35-authentification`. Reste à pousser et fusionner (§6)

### [F35-007] `definir-mot-de-passe-crm.sh` place le mot de passe dans `argv`, alors que son en-tête affirme le contraire
- Sévérité      : S2
- Domaine       : sécurité
- Référence     : main e8924b8 (script introduit par `9d273cd`)
- Emplacement   : `infra/scripts/definir-mot-de-passe-crm.sh:106-107` (l'affirmation contredite est aux lignes 19-22)
- Constat       : le mot de passe est bien lu sur l'entrée standard, puis passé au conteneur par `docker exec -e CRM_MDP="$MDP"` — c'est-à-dire comme **argument** de la commande `docker`.
- Preuve        : `04_PREUVES/agent-35/f35-script-mdp-argv.txt` — même forme de commande, mot de passe factice, puis énumération des processus depuis une autre session : `CommandLine : docker.exe exec -e CRM_COMPTE=williamsjullin@gmail.com -e CRM_MDP=MotDePasseSecret2026! a35-api sleep 25`.
- Témoin négatif: la **même** énumération montre 15 autres `docker exec` en cours sur la machine, dont aucun ne porte de secret (`-e DB_DATABASE=axion_crm_a29`, `php artisan migrate --force`…). La méthode distingue donc bien une commande qui expose un secret d'une commande qui n'en expose pas.
- Impact        : sur le serveur de production (Linux), `ps -ef`, `ps auxww` et `/proc/<pid>/cmdline` — lisible par tout utilisateur, aucune option `hidepid` n'étant posée dans ce dépôt — exposent le mot de passe du propriétaire du CRM pendant toute la durée du `docker exec` (`php artisan tinker`, plusieurs secondes). Le script se réclame explicitement du contraire, ce qui est plus dangereux qu'un silence : l'opérateur croit la protection acquise.
- Reproduction  : lancer le script, et depuis un autre terminal `ps -ef | grep CRM_MDP`.
- Correctif     : passer le secret sur **l'entrée standard du conteneur** plutôt qu'en variable : `printf '%s' "$MDP" | docker exec -i -e CRM_COMPTE="$COMPTE" "$CONTENEUR" php artisan tinker /tmp/definir.php`, et lire `fgets(STDIN)` côté PHP. Corriger aussi l'en-tête du script, qui doit décrire ce que le script fait. Coût : 30 min.
- Statut        : **CORRIGÉ** — le mot de passe passe par un tube jusqu'à stdin du conteneur. Garde vue rougir puis verte ; commit `da994be`, branche `fix/a35-authentification`. Reste à pousser et fusionner (§6)

### [F35-008] `OwnerUserSeeder` écrit le mot de passe initial en clair sur le disque, sans le `chmod` annoncé, et l'affiche sur la sortie du déploiement
- Sévérité      : S2
- Domaine       : sécurité
- Référence     : main e8924b8
- Emplacement   : `backend/database/seeders/OwnerUserSeeder.php:143-171` · `backend/config/filesystems.php:7-13`
- Constat       : quand `OWNER_INITIAL_PASSWORD` est vide — c'est le cas prévu par `infra/scripts/configure-prod-env.sh:70` — le seeder génère un mot de passe de 32 caractères, l'écrit **en clair** dans `storage/app/private/seeders/owner-initial-password.txt` et l'affiche intégralement sur la sortie standard du seed.
- Preuve        : `04_PREUVES/agent-35/f35-008-mdp-proprietaire-en-clair.txt` — le fichier existe dans cet atelier (contenu non reproduit) ; code du seeder ; et la démonstration que le commentaire « mode 0600 » (l.65) est faux : le disque `local` ne déclare aucune `visibility`, et `LocalFilesystemAdapter::writeToFile()` n'applique un `chmod` **que** si l'option `visibility` est passée à `put()` — elle ne l'est pas. Le fichier prend donc le `umask` du processus (0644 dans l'image).
- Témoin négatif: `PortableVisibilityConverter` de Flysystem porte bien `filePrivate = 0600` ; la valeur existe et serait appliquée si l'option était passée. Ce n'est donc pas une limite de la bibliothèque, c'est un appel incomplet.
- Impact        : le mot de passe du compte propriétaire d'un CRM contenant 4,29 M de fiches est lisible par tout utilisateur du serveur, et se retrouve aussi dans la sortie du déploiement (journaux CI, journaux Docker). Aucun mécanisme n'efface le fichier ; le commentaire compte sur un geste manuel.
- Reproduction  : `php artisan db:seed --class=OwnerUserSeeder` avec `OWNER_INITIAL_PASSWORD` vide, puis `ls -l storage/app/private/seeders/`.
- Correctif     : ne jamais écrire le secret. Faire générer par le seeder un **jeton de première connexion** à usage unique et à durée limitée, et n'afficher que lui ; ou exiger `OWNER_INITIAL_PASSWORD` et échouer proprement s'il est absent. À défaut, au minimum `Storage::disk('local')->put($chemin, $ligne, ['visibility' => 'private'])` et supprimer le fichier au premier login réussi. **Ne pas confondre avec la rotation des secrets, refusée par Will** : il ne s'agit pas de changer un secret, mais de cesser d'en écrire un en clair. Coût : 2 h.
- Statut        : **CORRIGÉ** — le seeder n'écrit plus aucun secret ; il signale l'ancien fichier. Garde vue rougir puis verte ; commit `da994be`, branche `fix/a35-authentification`. Reste à pousser et fusionner (§6)

### [F35-009] Énumération de comptes par le temps de réponse sur `POST /auth/login`
- Sévérité      : S2
- Domaine       : backend / sécurité
- Référence     : main e8924b8
- Emplacement   : `backend/app/Services/Auth/AuthService.php:38-50`
- Constat       : quand l'adresse n'existe pas (ou que `password_hash` est nul), `AuthService` court-circuite avant `Hash::check()` ; quand elle existe, il exécute un `bcrypt` complet. Les **corps** de réponse sont identiques (`422`, `auth.failed`) ; les **durées** ne le sont pas.
- Preuve        : lecture du code — `AuthService.php:40` : `if (! $user || ! $user->password_hash || ! Hash::check(...))`. En PHP, `||` court-circuite : quand `$user` est nul, `Hash::check()` **n'est jamais appelé**. La branche « compte inconnu » ne fait donc aucun travail cryptographique, là où la branche « compte connu » exécute un `bcrypt` de coût 12.
- Témoin négatif: les deux autres routes du même périmètre, `magic-link` et `password/forgot`, ne présentent **pas** cette asymétrie — elles font le même travail dans les deux cas (`MagicLinkService::issue()` insère une ligne quel que soit le résultat, `PasswordResetController::forgot()` aussi). La lecture sait donc distinguer une route symétrique d'une route asymétrique : elle en trouve deux symétriques et une seule qui ne l'est pas.
- Impact        : un attaquant peut constituer la liste des adresses réellement enregistrées, sans jamais deviner un mot de passe. Le plafond de 5/min/IP ralentit, il n'empêche pas (rotation d'adresses IP — cf. F35-012).
- Reproduction  : chronométrer `POST /auth/login` sur une adresse connue et sur une adresse inconnue, mot de passe faux dans les deux cas. ⚠️ **La mesure chiffrée de l'écart n'a pas pu être jouée** (voir §5) : le constat repose sur la structure du code, pas sur des millisecondes.
- Correctif     : exécuter un `Hash::check()` factice sur un hachage constant lorsque l'utilisateur n'existe pas — le motif standard : `Hash::check($password, $user?->password_hash ?? static::HACHAGE_FACTICE)`. Coût : 30 min + test de temps.
- Statut        : **CORRIGÉ** — hachage factice de coût identique, mémorisé par coût. Garde vue rougir puis verte ; commit `da994be`, branche `fix/a35-authentification`. Reste à pousser et fusionner (§6)

### [F35-010] Les jetons d'API n'expirent jamais
- Sévérité      : S2
- Domaine       : backend / sécurité
- Référence     : main e8924b8
- Emplacement   : `backend/config/sanctum.php:14`
- Constat       : `'expiration' => null`. Aucun jeton personnel n'a de date d'expiration, et aucune tâche planifiée ne les élague (`sanctum:prune-expired` n'a rien à élaguer).
- Preuve        : **joué** — `04_PREUVES/agent-35/sonde-courte-2.txt`, bloc [5] : `jeton cree : expires_at = NULL (sanctum.expiration = NULL)`. `config/sanctum.php:14` porte bien `'expiration' => null`.
- Témoin négatif: dans la **même** sonde, le cookie de session, lui, porte bien une expiration — `expire=2026-08-19T17:05:02+02:00`, soit +120 min. Le dépôt sait donc poser une expiration quand il le veut ; il ne le fait pas pour les jetons d'API. Et la **révocation explicite**, elle, fonctionne : jeton valide ⇒ 200, jeton supprimé ⇒ 401 (même bloc).
- Impact        : un jeton qui fuit reste valable pour toujours, sauf révocation manuelle. Aucun écran de la console ne liste ni ne révoque les jetons (à confirmer par l'agent des écrans).
- Correctif     : `'expiration' => (int) env('SANCTUM_TOKEN_TTL_MINUTES', 43200)` (30 jours) et planifier `sanctum:prune-expired`. Coût : 1 h.
- Statut        : **CORRIGÉ** — `sanctum.expiration` = `SANCTUM_TOKEN_TTL_MINUTES`, 30 j par défaut. Garde vue rougir puis verte ; commit `da994be`, branche `fix/a35-authentification`. Reste à pousser et fusionner (§6)

### [F35-011] `Password::min(12)` est appliqué à la **connexion** : un compte au mot de passe plus court ne peut plus jamais se connecter
- Sévérité      : S2
- Domaine       : backend / UX
- Référence     : main e8924b8
- Emplacement   : `backend/app/Http/Requests/Auth/LoginRequest.php:20`
- Constat       : la règle de complexité est posée sur la requête de **connexion**, pas seulement sur la création. Un mot de passe correct de moins de 12 caractères reçoit 422 `password` sans que l'authentification soit même tentée.
- Preuve        : `LoginRequest.php:20` : `'password' => ['required', 'string', Password::min(12)]`. Une `FormRequest` valide **avant** l'entrée dans le contrôleur : `AuthController::login()` n'est jamais atteint. Le test existant du dépôt le confirme sans le nommer comme un défaut — `tests/Feature/Auth/LoginTest.php:140-144`, « login validates password min length », attend 422 et `assertJsonValidationErrorFor('password')`.
- Témoin négatif: le même fichier de test, ligne 77-83, montre qu'un mot de passe de 21 caractères passe la validation et rend 200. La règle discrimine donc bien sur la longueur — c'est son emplacement, sur la **connexion**, qui est le défaut.
- Impact        : impasse totale si un compte a été créé hors du chemin nominal (script, reprise, import) avec un mot de passe court : ni connexion, ni message compréhensible (« le mot de passe doit contenir au moins 12 caractères » alors que c'est le bon). Sur ce produit, `definir-mot-de-passe-crm.sh` impose bien 12 caractères, mais rien ne le garantit ailleurs.
- Correctif     : sur la connexion, ne valider que `['required', 'string']`. La complexité se contrôle à la création et au changement, jamais à la connexion. Coût : 10 min.
- Statut        : **CORRIGÉ** — `Password::min(12)` retiré de la **connexion**. Garde vue rougir puis verte ; commit `da994be`, branche `fix/a35-authentification`. Reste à pousser et fusionner (§6)

### [F35-012] Le verrou de compte n'est vérifié qu'après le contrôle du mot de passe : il n'arrête pas l'attaque, il en interdit seulement le succès
- Sévérité      : S2
- Domaine       : backend / sécurité
- Référence     : main e8924b8
- Emplacement   : `backend/app/Services/Auth/AuthService.php:40-56`
- Constat       : l'ordre est (1) `Hash::check()`, (2) incrément + verrou éventuel, (3) **puis seulement** contrôle de `locked_until`. Un compte verrouillé continue donc d'exécuter un `bcrypt` par tentative et son compteur continue de monter.
- Preuve        : lecture du code, `AuthService.php` : l.40 `Hash::check()` → l.41-48 incrément et pose de `locked_until` → l.52 **seulement** `if ($user->locked_until && $user->locked_until->isFuture())`. Les deux plafonds de débit sont indexés sur l'IP seule : `RouteServiceProvider.php:26` (`Limit::perMinute(5)->by($r->ip())`) et `AuthService.php:30` (`"login:{$request->ip()}:" . strtolower($email)`). Aucune remise à zéro de `failed_login_count` par le temps : les seules écritures à 0 sont `AuthService.php:60` (connexion réussie) et `PasswordResetController.php:99` (réinitialisation).
- Témoin négatif: le dépôt **a déjà mesuré** ce comportement et l'a documenté : `tests/Feature/Auth/LoginTest.php:99-127` explique que « les tentatives 6 à 10 repartaient en 429 sans jamais atteindre AuthService », et le test ne parvient à verrouiller le compte qu'en **changeant d'IP à chaque tour** (l.119). C'est la démonstration, écrite par le produit lui-même, que la rotation d'adresses contourne les deux plafonds. ⚠️ **La série de 20 tentatives n'a pas pu être rejouée par moi** (voir §5).
- Impact        : (a) coût serveur — chaque tentative sur un compte verrouillé consomme un `bcrypt` de coût 12, ce qui en fait un levier d'épuisement CPU ; (b) `failed_login_count` n'est jamais remis à zéro par le temps (seulement par une connexion réussie ou une réinitialisation), donc **10 fautes de frappe étalées sur des mois** finissent par verrouiller un compte légitime pour 24 h ; (c) la rotation d'adresses IP contourne les deux plafonds de débit, qui sont tous deux indexés sur l'IP (`RouteServiceProvider:26` et `AuthService:30`).
- Correctif     : déplacer le contrôle de `locked_until` **avant** `Hash::check()` ; faire expirer `failed_login_count` (remise à zéro après N minutes sans échec) ; ajouter un plafond de débit indexé sur le **compte** en plus de celui indexé sur l'IP. Coût : 2 h.
- Statut        : **CORRIGÉ** — verrou testé **avant** le hachage + fenêtre d'oubli de 30 min. Garde vue rougir puis verte ; commit `da994be`, branche `fix/a35-authentification`. Reste à pousser et fusionner (§6)

### [F35-013] Un lien magique émis pour une adresse sans compte ouvre une session si le compte est créé avant l'expiration
- Sévérité      : S2
- Domaine       : backend / sécurité
- Référence     : main e8924b8
- Emplacement   : `backend/app/Services/Auth/MagicLinkService.php:26-40,69`
- Constat       : `issue()` insère une ligne `magic_links` **même** quand l'adresse est inconnue (`user_id` nul), puis n'envoie rien. `consume()` retrouve l'utilisateur par `where('email', $row->email)` — pas par `user_id`. Une ligne orpheline devient donc utilisable dès que le compte apparaît, dans la fenêtre de 15 minutes.
- Preuve        : lecture du code — `MagicLinkService::issue()` insère la ligne l.26-34 **avant** le test `if ($user === null)` de la l.36 ; et `consume()` l.69 fait `User::query()->where('email', $row->email)`, sans jamais consulter `$row->user_id`. Le schéma le permet : `magic_links.user_id` est `NULL`-able (`\d magic_links` : `user_id | uuid | | |`).
- Témoin négatif: le même code montre que les deux autres protections **sont** en place et fonctionnent : `consume()` filtre sur `whereNull('consumed_at')` (usage unique) et sur `where('expires_at', '>', now())` (expiration), et pose `consumed_at` avant de rendre l'utilisateur. La lecture sait donc reconnaître une garde qui tient : elle en trouve deux, et une troisième qui manque. ⚠️ **Le rejeu des trois cas n'a pas pu être joué** (voir §5).
- Impact        : étroit mais réel — quiconque connaît l'adresse d'un futur collaborateur peut préparer un lien et prendre sa session dès la création du compte. Effet secondaire : la table `magic_links` accepte 3 insertions par minute et par IP **sans** aucune purge, avec l'adresse et l'IP du demandeur (donnée personnelle) — croissance non bornée.
- Reproduction  : `POST /auth/magic-link` sur une adresse inexistante, créer le compte, puis consommer le jeton.
- Correctif     : ne rien insérer si l'utilisateur est inconnu (le temps de réponse reste constant : il n'y a pas de `bcrypt` ici) ; et faire porter `consume()` sur `user_id` et non sur l'e-mail. Ajouter une purge planifiée des liens expirés. Coût : 1 h.
- Statut        : **CORRIGÉ** — aucune ligne pour une adresse inconnue ; `consume()` par `user_id`. Garde vue rougir puis verte ; commit `da994be`, branche `fix/a35-authentification`. Reste à pousser et fusionner (§6)

### [F35-014] `definir-mot-de-passe-crm.sh` peut annoncer un succès sur une sortie qui n'en est pas un
- Sévérité      : S3
- Domaine       : sécurité / finition
- Référence     : main e8924b8
- Emplacement   : `infra/scripts/definir-mot-de-passe-crm.sh:31,111-131`
- Constat       : l'aiguillage final teste `case "$SORTIE" in *OK*)` **en premier**, sur la sortie brute et entière de `php artisan tinker`. Toute ligne parasite contenant la sous-chaîne `OK` — avertissement PHP, message d'un paquet, bannière — déclencherait la branche « succès ». Par ailleurs `set -uo pipefail` est posé **sans** `-e`.
- Preuve        : lecture ligne à ligne du script (§ périmètre de l'agent 35, script jamais audité auparavant).
- Témoin négatif: le script fait par ailleurs les choses bien — il refuse de tourner hors root, refuse un terminal interactif, refuse un mot de passe de moins de 12 caractères, vérifie l'existence du conteneur, et surtout **vérifie le hachage enregistré** par `Hash::check()` avant de conclure. C'est un script sérieux : ce constat porte sur la seule marche restante.
- Impact        : faible mais mal placé — un « OK : mot de passe défini » erroné envoie l'opérateur chercher la panne du mauvais côté, sur le geste qui rend l'accès au produit.
- Correctif     : comparer la **dernière ligne** de la sortie à `OK` exactement (`[ "$(printf '%s' "$SORTIE" | tail -n1)" = OK ]`), et faire écrire au PHP un marqueur non ambigu. Coût : 15 min.
- Statut        : **CORRIGÉ** — verdict lu sur la dernière ligne, à l'égalité stricte. Garde vue rougir puis verte ; commit `da994be`, branche `fix/a35-authentification`. Reste à pousser et fusionner (§6)

---

## 4. Le produit a-t-il jamais été utilisable ?

**Non. Et A-001 n'y est pour rien** — ma mesure le montre : le SPA reçoit un **401 propre**,
pas un 500. Le 500 ne touche que les clients qui n'annoncent pas attendre du JSON.

⚠️ **La réponse à cette question était déjà trouvée quand je suis arrivé** : c'est
**A07-001**, croisé avec **A-012**. Je ne la revendique pas. Ce que j'apporte, c'est une
**vérification indépendante** — atelier différent, méthode différente, chemin différent —
et un **second chemin de rupture** que personne n'avait relevé.

Le fait de départ : en production, 1 utilisateur, **0 session, 0 jeton**, depuis le
2026-05-17. Mot de passe initial perdu dans un journal, `MAIL_MAILER=log` qui empêche tout
envoi : ces deux causes expliquent qu'on ne puisse pas **entrer**, et le script
`definir-mot-de-passe-crm.sh` du 19/08 les répare. Mais **même avec des identifiants
valides, un compte neuf ne peut pas franchir le premier login**.

La chaîne est fermée, et chaque maillon est mesuré :

1. `EnforceFirstLoginSetup` renvoie **403** sur toute route hors liste blanche tant que
   `first_login_completed_at` est nul.
2. Le **seul** endroit de tout `app/` qui pose ce champ est
   `TwoFactorService::confirmEnrolment()` (vérifié par recherche exhaustive).
3. `confirmEnrolment()` — comme `startEnrolment()` avant lui — écrit trois colonnes qui
   **n'existent dans aucune des 58 migrations**, et échoue en base :
   `SQLSTATE[42703] column "two_factor_secret" of relation "users" does not exist`.
4. Donc `POST /auth/2fa/setup` et `POST /auth/2fa/confirm` échouent, `first_login_completed_at`
   reste nul, et **toutes** les routes métier renvoient 403 `first_login_required`,
   indéfiniment.
5. **Ce que j'ajoute** : la même dérive de schéma casse un **second** point du produit,
   hors 2FA. `UsersController.php:33` fait `->select([... 'two_factor_enabled' ...])`, et
   la requête échoue en SQL — joué :
   `ERROR: column "two_factor_enabled" does not exist`, avec pour témoin négatif la même
   requête privée de cette seule colonne, qui s'exécute sans erreur. **`GET /api/v1/users`
   est donc cassé pour la même raison**, et ce chemin n'est dans aucun constat existant.
   Il compte : c'est l'écran de gestion des utilisateurs — celui par lequel le propriétaire
   inviterait quelqu'un d'autre.

Autrement dit : le mot de passe rend l'**entrée**, il ne rend pas l'**usage**. Le CRM n'a
jamais été franchissable au-delà de son écran de première configuration — ce qui explique
aussi, sans contradiction, pourquoi personne n'a jamais signalé le 403 : personne n'est
jamais allé assez loin pour le voir.

**Conséquence pour le chantier.** Le geste « rendre l'accès » n'est pas terminé avec
`definir-mot-de-passe-crm.sh` : il rend le mot de passe, pas l'usage. Ordre suggéré, du
bloquant au gênant : **A07-001** (les colonnes, plus `UsersController:33`) d'abord — c'est
le préalable à la §11 du prompt d'audit, ouvrir les 37 écrans dans un vrai navigateur ;
puis **F35-003** (la 2FA n'est exigée par personne), qui deviendra visible dès que
l'enrôlement marchera ; puis **F35-001** et **F35-005**.

Et une remarque qui vaut pour tout le chantier : **le CRM n'a jamais été franchissable
au-delà de son écran de première configuration.** Cela explique, sans contradiction,
pourquoi personne n'a jamais signalé le 403 — personne n'est jamais allé assez loin pour
le voir. Un produit que personne n'utilise ne produit aucun signalement : l'absence de
plainte n'a jamais été une preuve de bon fonctionnement, et ici elle en était l'exact
contraire.

---

## 5. Ce que je n'ai PAS pu vérifier, et pourquoi

1. **A-001 rejoué contre la production — par moi, non.** Deux raisons cumulées : (a) la
   consigne « aucune tentative d'authentification contre la production » — une requête sans
   identifiant n'en est pas une, mais le doute n'était pas à moi de le trancher ;
   (b) l'outillage a refusé l'appel `curl` vers `api.axion-crm-pro.com`.
   **Le manque est comblé par un autre agent**, dont la sortie brute est archivée dans
   `04_PREUVES/P0/a001-recontrole.txt` et `prod-401-vs-500.txt` :
   ```
   PRODUCTION, sans en-tete Accept :        PRODUCTION, avec Accept: application/json :
     /api/v1/crm/arbitrage    -> 500          /api/v1/crm/arbitrage    -> 401
     /api/v1/config/features  -> 500          /api/v1/config/features  -> 401
     /api/v1/contacts         -> 500          /api/v1/contacts         -> 401
   ```
   **Cette mesure de production et la mienne, faite en local et indépendamment, donnent le
   même résultat au code près.** C'est le meilleur contrôle que je pouvais espérer : deux
   agents, deux environnements, deux méthodes (HTTPS réel *vs* noyau HTTP), même verdict.
   Ce qui reste non joué de mon côté est le **correctif** appliqué en production — normal,
   la correction est P3.
2. 🔴 **L'essentiel de ce que je voulais REJOUER n'a pas pu l'être : l'atelier n'a pas tenu.**
   C'est le manque le plus important de ce rapport, et il faut le dire net.
   - Le conteneur partagé `axion-crm-api` ne répondait plus en HTTP pendant toute ma
     session : son serveur PHP intégré est **mono-processus** et une quinzaine de commandes
     `artisan`/`pest` d'autres agents y tournaient en parallèle. `curl .../up` a expiré à
     10 s, puis 15 s, puis 180 s — **y compris depuis l'intérieur du conteneur**.
   - J'ai monté un conteneur **dédié** `a35-api`. Sa première requête HTTP a mis **329 s**,
     et `GET /api/v1/auth/me` plus de **600 s**. Causes mesurées : montage 9p Windows saturé
     (`wchan = p9_client_rpc` en permanence) et **`opcache.enable = Off` / `opcache.enable_cli = Off`**
     dans l'image — chaque fichier PHP est relu depuis le montage à chaque requête.
   - Repli sur le **noyau HTTP de Laravel** (`TestCase::get()/post()`), qui traverse la même
     pile de middlewares, le même routeur et le même gestionnaire d'exceptions. Une seule
     requête HTTP y coûtait **19 minutes pour 15 requêtes**, dont l'essentiel en écritures
     de journal : chaque 500 écrit 8 475 octets sur un montage 9p saturé.
   - **Ce qui a fini par être obtenu**, en trois sondes successives : le témoin
     d'instrumentation et la matrice A-001 complète (`sonde-courte-1.txt`) ; la preuve du
     correctif à quatre états, le fail-open de HIBP, et la révocation d'un jeton d'API
     (`sonde-courte-2.txt`).
   - **Trois pièges de mesure m'ont menti en chemin**, et je les consigne pour les suivants
     dans `04_PREUVES/agent-35/PIEGES-DE-MESURE-RENCONTRES.txt` : (1) `TestCase::call()`
     n'envoie pas les en-têtes ; (2) le banc remplace le gestionnaire d'exceptions par celui
     de Collision, qui n'a pas `shouldRenderJsonWhen()` ; (3) `opcache.file_cache` +
     `validate_timestamps=0` a servi une version **périmée** de mon fichier d'amorçage — d'où
     un `APP_ENV=local` que je croyais avoir corrigé, donc `ValidateCsrfToken` actif, donc
     **toutes** mes requêtes POST en `419 CSRF token mismatch`. Ces 419 sont un défaut de mon
     banc, **pas un constat produit** : aucun n'est rapporté comme tel.
   - **Restent à rejouer**, et la sonde est prête pour cela dans
     `04_PREUVES/agent-35/sonde/` et `sonde2/` — ce sont exactement les blocs qui exigeaient
     une **connexion réussie** : le rejeu du lien magique, l'usage unique et l'expiration du
     jeton de réinitialisation, le contournement de la 2FA en session, `EnforceFirstLoginSetup`,
     le refus d'un mot de passe court, les 20 tentatives de connexion et les temps de réponse
     de l'énumération. Il faut soit corriger l'amorçage (`APP_ENV=testing` réellement pris en
     compte, `rm -rf` du cache opcache), soit fournir un jeton CSRF à chaque requête comme le
     fait déjà `tests/Feature/Auth/LoginTest.php:68-75`.
     **Pour les rejouer** : `docker exec <conteneur> sh -c "cd /var/www/html && php
     -d opcache.enable_cli=1 -d opcache.enable=1 -d opcache.file_cache=/tmp/oc
     vendor/bin/phpunit -c /tmp/a35/phpunit-slim2.xml"`, sur une machine qui ne fait pas
     tourner cinquante agents en même temps. **Épingler `LOG_CHANNEL=null`** dans le fichier
     d'amorçage : sans cela, les 500 écrivent 8,5 Ko chacun et c'est le journal, pas
     l'application, qui fixe la durée du test.
   - Ce qui **a** été joué est nommé colonne « Comment » de la grille, et chaque constat
     distingue explicitement ce qui est mesuré de ce qui est lu. Aucun constat de ce rapport
     ne repose sur une supposition : ceux qui ne sont pas joués reposent sur du texte de
     code cité, ligne par ligne, avec son témoin négatif.
3. **Le parcours dans un vrai navigateur** (§4 de la doctrine : « le geste réel avant
   l'instrumentation ») — même cause. Les constats sur les cookies (HttpOnly, SameSite,
   Secure) reposent sur la configuration et sur le code de Sanctum, pas sur l'observation
   d'un navigateur.
4. **La table `sessions` en conditions réelles.** La sonde est réglée sur
   `SESSION_DRIVER=database` pour pouvoir rejouer un vrai cookie ; la production utilise
   `redis`. Le code exercé (`session()->invalidate()`) est le même, le pilote ne l'est pas.
5. **La 2FA de bout en bout.** Impossible à jouer telle qu'écrite : l'enrôlement échoue en
   base (F35-002). La fenêtre TOTP (`window = 1`, soit ±30 s) et l'usage unique des codes de
   secours sont lus dans `TwoFactorService.php:52,81,87-93`, non rejoués.
   À noter pour qui reprendra : `strtoupper(Str::random(10))` réduit l'alphabet des codes de
   secours de 62 à 36 signes (~51,7 bits au lieu de 59,5) — suffisant, mais l'intention
   affichée ne l'est pas.
6. **`HibpChecker` contre le vrai service.** Le réseau sortant n'a pas été coupé au pare-feu ;
   la sonde simule la coupure par un `MockHandler` Guzzle levant `ConnectException`, ce qui
   exerce exactement la branche `catch` du produit — mais elle n'a pas pu être exécutée.
7. **Les écrans de la console qui listent ou révoquent les jetons d'API** : hors périmètre,
   à croiser avec l'agent des écrans pour F35-010.
8. **`OwnerUserSeeder`** appartient au périmètre « seeders » d'un autre agent ; je ne l'ai
   retenu que par le chemin de l'identifiant (F35-008). À dédoublonner avant consolidation.
9. **Les permissions réelles de `owner-initial-password.txt` sur le serveur.** Établies par
   lecture du code (Flysystem n'applique aucun `chmod` sans option `visibility`), pas par un
   `stat` sur la production. Le `ls -l` de cet atelier n'a **aucune valeur** : Windows ne
   porte pas de mode POSIX, et Git Bash en invente un.
10. **Les autres appels `diffIn*` du dépôt hors `backend/app/`** (`database/`, `routes/`,
    `workers/`) n'ont pas été passés au crible du piège Carbon 3 de F35-005. Dans `app/`,
    la recherche est exhaustive : **une seule** occurrence fautive, et l'autre occurrence
    du dépôt (`ScrapingCampaign.php:137`) est correcte — bon ordre d'opérandes **et** second
    argument explicite.
11. **`GET /broadcasting/auth` répond 200 sans authentification** (`sonde-courte-2.txt`,
    bloc [10]). Cette route vit **hors** du groupe `api` : ni `auth:sanctum` ni
    `EnforceFirstLoginSetup` ne s'y appliquent — c'est la réponse à mon point 9, et elle
    est structurelle. Le corps rendu était **vide** et je n'ai pas poussé plus loin : **je
    ne conclus pas à une faille**, je signale une porte que mon périmètre ne couvre pas.
    À confier à l'agent du canal temps réel (Reverb) : que rend `Broadcast::auth()` à un
    visiteur non authentifié, et sur quels canaux ?

---

## 6. RESTE WILL — les gestes qui ne sont pas du code

La doctrine reçue réserve trois classes d'actions à l'humain. En voici la liste exacte,
avec le geste, et ce qui se passe si on ne le fait pas.

### 6.1 Pousser la branche et ouvrir la PR — **bloqué chez moi, pas par choix**

`git push -u origin fix/a35-authentification` a été **refusé par la couche de permission
de l'outillage**. Je n'ai pas cherché à contourner : ce n'est pas à moi de décider qu'une
barrière posée volontairement doit sauter. Le travail est **committé localement**, complet
et vert.

```
cd C:/Users/willi/Documents/Projets/crmpro-wt-a35-auth
git push -u origin fix/a35-authentification
gh pr create --fill --base main
```

Conséquence si ce n'est pas fait : les 14 correctifs restent dans un worktree local. Le
CRM continue de refuser sa propre console à son propriétaire.

### 6.2 Supprimer l'ancien mot de passe en clair, sur le serveur

Fichier : `storage/app/private/seeders/owner-initial-password.txt`.

Le seeder **ne l'écrit plus** et **le signale désormais** à chaque exécution. Je ne l'ai
pas supprimé par le code, et c'est délibéré : détruire le seul exemplaire d'un secret sans
que personne l'ait demandé est exactement l'erreur que ce chantier a déjà payée une fois.
Geste : changer d'abord le mot de passe du compte, puis
`rm -f /opt/axion-crm-pro/backend/storage/app/private/seeders/owner-initial-password.txt`.

### 6.3 Déployer, migrer, et re-vérifier en ligne

La branche porte **une migration** (`2026_08_19_120000_reparer_socle_authentification`) :
`totp_recovery_codes` passe de `TEXT[]` à `TEXT`, et `last_failed_login_at` est ajoutée.
Les deux sont additives ou portent sur une colonne restée vide — le chemin d'écriture n'a
jamais fonctionné. L'entrypoint de production joue `php artisan migrate` au démarrage.

Après déploiement, deux vérifications qui tiennent en deux commandes :

```
curl -s -o /dev/null -w '%{http_code}
' -H 'Accept: text/html' https://api.axion-crm-pro.com/api/v1/auth/me   # attendu : 401, plus 500
curl -s -o /dev/null -w '%{http_code}
' -H 'Accept: application/json' https://api.axion-crm-pro.com/api/v1/auth/me # attendu : 401
```

Puis, et c'est le seul qui compte vraiment : **se connecter**, franchir l'enrôlement 2FA,
et ouvrir un écran métier. C'est le geste que le produit n'a jamais permis.

### 6.4 Ce que je n'ai pas touché, et qui reste vrai

- Aucune variable d'environnement de production n'a été modifiée.
- Aucune donnée de production n'a été lue en écriture, ni supprimée.
- Aucun e-mail n'a été envoyé à qui que ce soit.
- La rotation des secrets reste **refusée par Will** ; elle n'est pas reproposée ici.
