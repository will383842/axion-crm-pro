# AGENT 36 — Auditeur des permissions

> **Référence mesurée** : dépôt CRM, `main = e8924b81ad64c0b236acd99ac5cbac4cd68eada7` (`e8924b8`).
> `git log` relu au début **et à la fin** de la mission, comme l'exige le dossier commun. `main` a
> avancé pendant l'audit (`8db8229`, puis `6c90194`), mais `git diff --stat e8924b8..HEAD --
> backend/ frontend/ infra/` ne rend qu'**une seule ligne** : l'ajout de
> `infra/scripts/verifier-serveur-http.sh`. **Aucun fichier de `backend/` n'a bougé.** Tous les
> constats ci-dessous restent donc valides sur `main` au moment où j'écris.
>
> **Méthode** : celle exigée par le mandat. Base jetable `axion_crm_a36`, conteneur d'API dédié
> `a36-api` (port 58136), **six comptes créés**, **six sessions HTTP réellement ouvertes**
> (`POST /api/v1/auth/login` → 200, cookie Sanctum), puis **117 requêtes jouées par compte
> restreint**. Aucune lecture de policy n'a servi de conclusion : tout constat ci-dessous porte
> le code de statut que le serveur a réellement rendu.
>
> **Production** : jamais touchée. Aucune tentative d'authentification, aucune escalade, aucune
> lecture. Tout est local.

---

## 0. L'atelier, et ce qu'il a coûté

L'atelier partagé était **inutilisable** au moment de la mission — c'est le constat A-009, et il
était plus grave que « lent » : l'API principale (`http://localhost:58080/up`) et celle de
l'agent 35 (`:58135/up`) n'ont **rien rendu du tout** en 80 s et 50 s (code curl `000`, pas de
réponse). Mesuré :

```
=== main api /up ===  000  80.008221s
=== a35 ===           000  50.006096s
```

Cause mesurée, et non supposée : sur un conteneur monté sur le disque Windows,
`require vendor/autoload.php` seul prenait **82,69 s**. Postgres et Redis, eux, répondaient
normalement (`PONG`, 17 connexions dont 4 actives, 0 bloquée) — le goulot est le montage de
fichiers, pas la base.

**Contournement retenu** : j'ai copié le dépôt *dans* le système de fichiers du conteneur
(`docker cp`, 4 min 16 s) au lieu de le monter. `/up` est alors passé de « aucune réponse » à
**200 en 5,4 s**. C'est ce conteneur, `a36-api`, qui a servi à toutes les mesures.

Deux conséquences honnêtes :

1. Les modifications temporaires de code que la doctrine exige (règle 2, règle 3) ont été faites
   **sur la copie du conteneur**, pas sur la copie de travail Windows — précisément pour ne pas
   empoisonner les mesures des autres agents, qui lisent le même dossier monté. `git status` du
   dépôt hôte ne montre **aucun fichier produit modifié** (§6 ci-dessous).
2. Certaines mesures ont dû être relancées en arrière-plan. Celles qui ont expiré sont dites
   comme telles au §7, jamais converties en conclusion.

---

## 1. Quels rôles existent réellement — et peut-on seulement créer un compte restreint ?

### 1.1 Les rôles existent, ils sont quatre

Mesuré en base après `db:seed --class=PermissionsAndRolesSeeder` :

```
 id |   name   | guard_name | team_id
----+----------+------------+---------
  1 | owner    | web        |
  2 | admin    | web        |
  3 | operator | web        |
  4 | viewer   | web        |
```

**16 permissions**, dont `data.export`, `companies.update`, `audit.view`, `contacts.view_pii`.
Attribution mesurée (`database/seeders/PermissionsAndRolesSeeder.php:61-69`) :

| Rôle | Permissions | dont `data.export` | dont `contacts.view_pii` | dont `audit.view` |
|------|-------------|--------------------|--------------------------|-------------------|
| `owner` | les 16 | oui | oui | oui |
| `admin` | 15 | oui | oui | oui |
| `operator` | 8 | oui | oui | non |
| `viewer` | 3 (`companies.view`, `llm.view_usage`, `rgpd.view`) | **non** | **non** | **non** |

Le serveur confirme les rôles à l'exécution — `GET /api/v1/auth/me`, six sessions réelles
(preuve `04_PREUVES/agent-36/01-roles-reels-auth-me.txt`) :

```
a36-owner@test.local    "roles":["owner"]
a36-admin@test.local    "roles":["admin"]
a36-operator@test.local "roles":["operator"]
a36-viewer@test.local   "roles":["viewer"]
a36-norole@test.local   "roles":[]
a36-owner2@test.local   "roles":["owner"]   (autre espace de travail)
```

Le référentiel de rôles est donc **réel, cohérent et vivant**. Ce n'est pas là qu'est le défaut.

### 1.2 Non : on ne peut pas créer un compte restreint par le produit

Le constat B16 de l'agent 16 est **confirmé par la mesure**, et il faut en tirer la conséquence.
`app/Http/Controllers/Api/UsersController.php` :

- `store()` (l. 51), `update()` (l. 59), `destroy()` (l. 67) → `return $this->notImplemented('3')`.
- Mesuré sur session ouverte : `POST /users` → **501**, `PUT /users/{user}` → **501**,
  `DELETE /users/{user}` → **501**.
- Le fichier ne contient **aucune occurrence** de `role` ni de `permission` — vérifié.

**Il n'existe donc, dans les 118 routes d'API, aucun point d'entrée pour créer un utilisateur,
lui attribuer un rôle, le lui retirer, ou l'affecter à un espace de travail.** Les quatre rôles
n'ont aucune interface d'administration : le seul compte que le produit sait fabriquer est le
`owner` initial du seeder (`OwnerUserSeeder`).

C'est ce qui change la nature de la mission, et c'est à dire franchement : **le cloisonnement par
rôles n'est aujourd'hui ni administrable ni observable par un utilisateur.** J'ai donc créé mes
six comptes par insertion directe en base — le seul chemin possible — puis je me suis connecté
avec chacun. Ce que la suite mesure est donc le **potentiel** du cloisonnement, tel qu'il
répondrait si l'on pouvait créer ces comptes. La réponse est qu'il ne cloisonne rien.

---

## 2. Grille des 11 policies — enregistrée / appelée / jamais appelée

**Le point central de la mission.** Le constat B16-004 disait que `AuditLogPolicy::viewAny` n'est
jamais appelée. Il ne s'agit pas d'un cas isolé : **aucune des onze ne l'est.**

| # | Policy | Fichier | Enregistrée ? | Résolue par le noyau ? | **Réellement appelée ?** | Preuve |
|---|--------|---------|---------------|------------------------|--------------------------|--------|
| 1 | `BasePolicy` | `app/Policies/BasePolicy.php` | s.o. (classe abstraite — correct) | s.o. | **JAMAIS** | instrumentation, 117 requêtes, 0 appel |
| 2 | `AuditLogPolicy` | `AuditLogPolicy.php` | oui (`AuthServiceProvider:19`) | oui → `App\Policies\AuditLogPolicy` | **JAMAIS** | idem — et `GET /audit-logs` rend **200** à un `viewer` |
| 3 | `CompanyPolicy` | `CompanyPolicy.php` | oui (l. 11) | oui | **JAMAIS** | `DELETE /companies/{id}` par un `viewer` → **204** |
| 4 | `ContactPolicy` | `ContactPolicy.php` | oui (l. 12) | oui | **JAMAIS** | `GET /contacts/{id}` inter-espaces → **200** |
| 5 | `LlmUseCasePolicy` | `LlmUseCasePolicy.php` | oui (l. 19) | oui | **JAMAIS** | `GET /llm/use-cases` → 200 pour un compte **sans aucun rôle** |
| 6 | `ProxyProviderPolicy` | `ProxyProviderPolicy.php` | oui (l. 20) | oui | **JAMAIS** | `GET /proxy-providers` → 200 pour `norole` |
| 7 | `RgpdRequestPolicy` | `RgpdRequestPolicy.php` | oui (l. 18) | oui | **JAMAIS** | `GET /rgpd/requests` → 200 pour `norole` |
| 8 | `ScraperRunPolicy` | `ScraperRunPolicy.php` | oui (l. 13) | oui | **JAMAIS** | `GET /scraper-runs` → 200 pour `norole` |
| 9 | `TagPolicy` | `TagPolicy.php` | oui (l. 17) | oui | **JAMAIS** | `POST /tags` par un `viewer` → **201** |
| 10 | `UserPolicy` | `UserPolicy.php` | oui (l. 16) | oui | **JAMAIS** | `GET /users` → 200 pour `norole` |
| 11 | `WorkspacePolicy` | `WorkspacePolicy.php` | oui (l. 15) | oui | **JAMAIS** | `GET /workspace` → 200 pour `norole` |

**Enregistrement — mesuré, pas déduit.** `App\Providers\AuthServiceProvider` déclare les dix
policies concrètes et est bien chargé (`bootstrap/providers.php:5`). `Gate::getPolicyFor()` les
résout toutes les dix (preuve `10-policies-enregistrees.txt`) :

```
Company -> App\Policies\CompanyPolicy      Tag           -> App\Policies\TagPolicy
Contact -> App\Policies\ContactPolicy      RgpdRequest   -> App\Policies\RgpdRequestPolicy
ScraperRun -> App\Policies\ScraperRunPolicy AuditLog     -> App\Policies\AuditLogPolicy
Workspace -> App\Policies\WorkspacePolicy  LlmUseCase    -> App\Policies\LlmUseCasePolicy
User -> App\Policies\UserPolicy            ProxyProvider -> App\Policies\ProxyProviderPolicy
```

Aucun attribut `#[UsePolicy]` n'est employé — l'enregistrement explicite suffit, et il fonctionne.
**L'enregistrement n'est pas le problème. L'invocation l'est.**

**Invocation — trois mesures indépendantes qui convergent.**

1. **Statique, exhaustive.** Sur `routes/api.php` : **0** intergiciel `can:` / `Authorize` sur les
   118 routes. Sur `app/` : **0** appel à `$this->authorize(...)`, `Gate::allows/denies/authorize`,
   `$user->cannot(...)`. Le seul `->can(...)` du dépôt applicatif est
   `app/Support/MasquageCoordonnees.php:43`, et il porte sur une **permission**, pas sur une policy.
2. **Dynamique.** J'ai instrumenté `BasePolicy` et `AuditLogPolicy` (copie conteneur) pour
   journaliser chaque appel de méthode, puis rejoué les **117 requêtes** avec la session `viewer`.
   Le fichier de trace **n'a jamais été créé** : zéro appel.
3. **Par inversion.** J'ai réécrit les onze policies pour qu'elles **refusent tout**
   (`return false` partout). Résultat mesuré (preuve `09-policies-inertes.txt`) :

   ```
   viewer  /api/v1/audit-logs     200      owner  /api/v1/audit-logs     200
   viewer  /api/v1/companies      200      owner  /api/v1/companies      200
   viewer  /api/v1/contacts       200      owner  /api/v1/contacts       200
   viewer  /api/v1/tags           200      owner  /api/v1/tags           200
   viewer  /api/v1/users          200      owner  /api/v1/users          200
   viewer  /api/v1/workspace      200      owner  /api/v1/workspace      200

   Tests:  15 passed (33 assertions)
   ```

   **Tout refuser ne change strictement rien**, ni pour l'API ni pour la suite de tests. C'est la
   définition d'un code mort.

**Et il y a pire que « pas appelée » : ce serait fatal de l'appeler.** Les 35 contrôleurs d'API
héritent de `ApiController`, qui étend `Illuminate\Routing\Controller` (`ApiController.php:6`) et
**non** `App\Http\Controllers\Controller` — seule cette dernière porte le trait
`AuthorizesRequests`. J'ai ajouté `$this->authorize('viewAny', AuditLog::class)` dans
`AuditLogsController::index` et joué la requête :

```
status=500
"message": "Method App\\Http\\Controllers\\Api\\AuditLogsController::authorize does not exist."
"exception": "BadMethodCallException"
```

La couche d'autorisation n'est donc pas seulement inutilisée : elle est **inatteignable en l'état**
depuis les contrôleurs qui servent l'API.

---

## 3. Grille des 118 routes — quelle garde, et ce que le serveur rend

L'inventaire commun annonce « 112 déclarations » dans `routes/api.php` : c'est le compte des
**lignes de déclaration**. Le noyau, lui, publie **118 routes d'API** — `Route::apiResource`
en produit cinq à lui seul. Mesuré par `php artisan route:list --json` : 149 routes au total,
dont 118 sous `api/` (113 `api/v1`, 4 `api/internal`, 1 `api/oauth2-callback`).

**Le compte qui répond à la question du mandat :**

| Mesure | Nombre |
|--------|--------|
| Routes d'API publiées | **118** |
| Derrière `auth:sanctum` | 106 |
| Hors `auth:sanctum` (auth, HMAC interne, portabilité RGPD, rappel OAuth) | 12 |
| Portant une **garde d'autorisation** (`permission:` de Spatie) | **4** |
| Portant un intergiciel `can:` (donc une policy) | **0** |
| **Sans aucune garde d'autorisation** | **114 / 118** |
| **Authentifiées mais sans aucune garde d'autorisation** | **102 / 106** |
| Ayant rendu **403** au compte `viewer` | **4** (exactement les quatre ci-dessus) |

Les quatre seules gardes du produit :

| Route | Garde |
|-------|-------|
| `GET /api/v1/companies/export` | `permission:data.export` |
| `GET /api/v1/media/export` | `permission:data.export` |
| `GET /api/v1/journalists/export` | `permission:data.export` |
| `POST /api/v1/companies/tags/bulk` | `permission:companies.update` |

### Tableau complet — une ligne par route

Colonne « Statut rendu au compte `viewer` » : code réellement rendu par le serveur au compte
`a36-viewer@test.local` (rôle `viewer`, lecture seule), session ouverte, corps `{}` pour les
méthodes d'écriture. `non joue` = `POST /auth/logout`, volontairement exclue du balayage : jouée
en cours de route, elle invalidait la session et faussait les 60 requêtes suivantes (première
tentative archivée, elle rendait des 419 en cascade).

| # | Route | Meth. | `auth:sanctum` | Garde d'autorisation posée | Policy réellement appelée | Statut rendu au compte `viewer` |
|---|-------|-------|----------------|----------------------------|---------------------------|---------------------------------|
| 1 | `/api/internal/email/zeptomail` | POST | **NON** | aucune | **aucune** | 503 |
| 2 | `/api/internal/scraper-result` | POST | **NON** | aucune | **aucune** | 401 |
| 3 | `/api/internal/site-sync` | POST | **NON** | aucune | **aucune** | 401 |
| 4 | `/api/internal/site-sync/gdpr` | POST | **NON** | aucune | **aucune** | 401 |
| 5 | `/api/oauth2-callback` | GET | **NON** | aucune | **aucune** | 200 |
| 6 | `/api/v1/ai-act/register` | GET | oui | aucune | **aucune** | 200 |
| 7 | `/api/v1/ai-act/register` | POST | oui | aucune | **aucune** | 501 |
| 8 | `/api/v1/audiences` | GET | oui | aucune | **aucune** | 200 |
| 9 | `/api/v1/audiences` | POST | oui | aucune | **aucune** | 422 |
| 10 | `/api/v1/audiences/preview` | POST | oui | aucune | **aucune** | 422 |
| 11 | `/api/v1/audiences/{audience}` | GET | oui | aucune | **aucune** | 404 |
| 12 | `/api/v1/audiences/{audience}` | PUT | oui | aucune | **aucune** | 404 |
| 13 | `/api/v1/audiences/{audience}` | DELETE | oui | aucune | **aucune** | 404 |
| 14 | `/api/v1/audiences/{audience}/members` | GET | oui | aucune | **aucune** | 404 |
| 15 | `/api/v1/audiences/{audience}/refresh` | POST | oui | aucune | **aucune** | 404 |
| 16 | `/api/v1/audit-logs` | GET | oui | aucune | **aucune** | **200** |
| 17 | `/api/v1/audit-logs/verify-chain` | GET | oui | aucune | **aucune** | **200** |
| 18 | `/api/v1/auth/2fa/confirm` | POST | oui | aucune | **aucune** | 422 |
| 19 | `/api/v1/auth/2fa/setup` | POST | oui | aucune | **aucune** | 500 |
| 20 | `/api/v1/auth/2fa/verify` | POST | **NON** | aucune | **aucune** | 422 |
| 21 | `/api/v1/auth/login` | POST | **NON** | aucune | **aucune** | 422 |
| 22 | `/api/v1/auth/logout` | POST | oui | aucune | **aucune** | non joué (voir ci-dessus) |
| 23 | `/api/v1/auth/magic-link` | POST | **NON** | aucune | **aucune** | 422 |
| 24 | `/api/v1/auth/magic-link/verify` | POST | **NON** | aucune | **aucune** | 422 |
| 25 | `/api/v1/auth/me` | GET | oui | aucune | **aucune** | 200 |
| 26 | `/api/v1/auth/onboarding/complete` | POST | oui | aucune | **aucune** | 200 |
| 27 | `/api/v1/auth/password/forgot` | POST | **NON** | aucune | **aucune** | 429 |
| 28 | `/api/v1/auth/password/reset` | POST | **NON** | aucune | **aucune** | 429 |
| 29 | `/api/v1/campaigns` | GET | oui | aucune | **aucune** | 200 |
| 30 | `/api/v1/campaigns` | POST | oui | aucune | **aucune** | 422 |
| 31 | `/api/v1/campaigns/{campaign}` | GET | oui | aucune | **aucune** | 404 |
| 32 | `/api/v1/campaigns/{campaign}` | PUT | oui | aucune | **aucune** | 404 |
| 33 | `/api/v1/campaigns/{campaign}` | DELETE | oui | aucune | **aucune** | 404 |
| 34 | `/api/v1/campaigns/{campaign}/cancel` | POST | oui | aucune | **aucune** | 404 |
| 35 | `/api/v1/campaigns/{campaign}/pause` | POST | oui | aucune | **aucune** | 404 |
| 36 | `/api/v1/campaigns/{campaign}/resume` | POST | oui | aucune | **aucune** | 404 |
| 37 | `/api/v1/campaigns/{campaign}/start` | POST | oui | aucune | **aucune** | 404 |
| 38 | `/api/v1/campaigns/{campaign}/stats` | GET | oui | aucune | **aucune** | 404 |
| 39 | `/api/v1/cold-email{any?}` | tous verbes | oui | aucune | **aucune** | 501 |
| 40 | `/api/v1/companies` | GET | oui | aucune | **aucune** | 200 |
| 41 | `/api/v1/companies` | POST | oui | aucune | **aucune** | 422 (validation seule) |
| 42 | `/api/v1/companies/bulk-enrich` | POST | oui | aucune | **aucune** | 422 (validation seule) |
| 43 | `/api/v1/companies/export` | GET | oui | **`permission:data.export`** | **aucune** | **403** |
| 44 | `/api/v1/companies/tags/bulk` | POST | oui | **`permission:companies.update`** | **aucune** | **403** |
| 45 | `/api/v1/companies/{company}` | GET | oui | aucune | **aucune** | 200 |
| 46 | `/api/v1/companies/{company}` | PUT | oui | aucune | **aucune** | **200 (modification aboutie)** |
| 47 | `/api/v1/companies/{company}` | DELETE | oui | aucune | **aucune** | **204 (suppression aboutie)** |
| 48 | `/api/v1/companies/{company}/enrich` | POST | oui | aucune | **aucune** | **200** |
| 49 | `/api/v1/companies/{company}/recompute-score` | POST | oui | aucune | **aucune** | **200** |
| 50 | `/api/v1/config/features` | GET | oui | aucune | **aucune** | 200 |
| 51 | `/api/v1/contacts` | GET | oui | aucune | **aucune** | 200 (coordonnées masquées) |
| 52 | `/api/v1/contacts/{contact}` | GET | oui | aucune | **aucune** | **200 (coordonnées EN CLAIR)** |
| 53 | `/api/v1/contacts/{contact}` | PUT | oui | aucune | **aucune** | 501 |
| 54 | `/api/v1/contacts/{contact}` | DELETE | oui | aucune | **aucune** | 404 |
| 55 | `/api/v1/coverage` | GET | oui | aucune | **aucune** | 200 |
| 56 | `/api/v1/coverage/cells/{cell}` | GET | oui | aucune | **aucune** | 200 |
| 57 | `/api/v1/coverage/enrich` | POST | oui | aucune | **aucune** | 422 (validation seule) |
| 58 | `/api/v1/coverage/launch` | POST | oui | aucune | **aucune** | 422 (validation seule) |
| 59 | `/api/v1/coverage/next-zone` | GET | oui | aucune | **aucune** | 200 |
| 60 | `/api/v1/crm/arbitrage` | GET | oui | aucune | **aucune** | 404 (drapeau `crm.console_v2` fermé) |
| 61 | `/api/v1/crm/arbitrage/{activityId}/attach` | POST | oui | aucune | **aucune** | 404 (drapeau fermé) |
| 62 | `/api/v1/crm/arbitrage/{activityId}/dismiss` | POST | oui | aucune | **aucune** | 404 (drapeau fermé) |
| 63 | `/api/v1/crm/bulk` | POST | oui | aucune | **aucune** | 404 (drapeau fermé) |
| 64 | `/api/v1/crm/candidates` | GET | oui | aucune | **aucune** | 404 (drapeau fermé) |
| 65 | `/api/v1/crm/candidates/counts` | GET | oui | aucune | **aucune** | 404 (drapeau fermé) |
| 66 | `/api/v1/crm/contacts-hub` | GET | oui | aucune | **aucune** | 404 (drapeau fermé) |
| 67 | `/api/v1/crm/contacts-hub/counts` | GET | oui | aucune | **aucune** | 404 (drapeau fermé) |
| 68 | `/api/v1/crm/persons/{personKey}/timeline` | GET | oui | aucune | **aucune** | 404 (drapeau fermé) |
| 69 | `/api/v1/dashboard/stats` | GET | oui | aucune | **aucune** | 200 |
| 70 | `/api/v1/journalists` | GET | oui | aucune | **aucune** | 200 |
| 71 | `/api/v1/journalists/export` | GET | oui | **`permission:data.export`** | **aucune** | **403** |
| 72 | `/api/v1/journalists/{journalist}` | GET | oui | aucune | **aucune** | 404 (jeu vide) |
| 73 | `/api/v1/journalists/{journalist}` | DELETE | oui | aucune | **aucune** | 404 (jeu vide) |
| 74 | `/api/v1/journalists/{journalist}/opt-out` | POST | oui | aucune | **aucune** | 404 (jeu vide) |
| 75 | `/api/v1/linkedin{any?}` | tous verbes | oui | aucune | **aucune** | 501 |
| 76 | `/api/v1/llm/usage` | GET | oui | aucune | **aucune** | 200 |
| 77 | `/api/v1/llm/usage/summary` | GET | oui | aucune | **aucune** | 200 |
| 78 | `/api/v1/llm/use-cases` | GET | oui | aucune | **aucune** | 200 |
| 79 | `/api/v1/llm/use-cases/{useCase}` | PUT | oui | aucune | **aucune** | 501 |
| 80 | `/api/v1/llm/use-cases/{useCase}/prompts` | GET | oui | aucune | **aucune** | 200 |
| 81 | `/api/v1/llm/use-cases/{useCase}/prompts/{v}` | PUT | oui | aucune | **aucune** | 501 |
| 82 | `/api/v1/media` | GET | oui | aucune | **aucune** | 200 |
| 83 | `/api/v1/media/export` | GET | oui | **`permission:data.export`** | **aucune** | **403** |
| 84 | `/api/v1/media/{media}` | GET | oui | aucune | **aucune** | 404 (jeu vide) |
| 85 | `/api/v1/notifications` | GET | oui | aucune | **aucune** | 200 |
| 86 | `/api/v1/notifications/read-all` | POST | oui | aucune | **aucune** | 501 |
| 87 | `/api/v1/notifications/{n}/read` | POST | oui | aucune | **aucune** | 501 |
| 88 | `/api/v1/observability/summary` | GET | oui | aucune | **aucune** | 200 |
| 89 | `/api/v1/proxy-providers` | GET | oui | aucune | **aucune** | 200 |
| 90 | `/api/v1/proxy-providers/{p}` | PUT | oui | aucune | **aucune** | 404 (jeu vide) |
| 91 | `/api/v1/proxy-providers/{p}/test` | POST | oui | aucune | **aucune** | 404 (jeu vide) |
| 92 | `/api/v1/referentiels/geo` | GET | oui | aucune | **aucune** | 200 |
| 93 | `/api/v1/rgpd/export/{token}` | GET | **NON** (par conception, art. 20) | aucune | **aucune** | 404 |
| 94 | `/api/v1/rgpd/requests` | GET | oui | aucune | **aucune** | 200 |
| 95 | `/api/v1/rgpd/requests` | POST | oui | aucune | **aucune** | 422 (validation seule) |
| 96 | `/api/v1/rgpd/requests/{req}/process` | POST | oui | aucune | **aucune** | 404 (jeu vide) |
| 97 | `/api/v1/rotations` | GET | oui | aucune | **aucune** | 200 |
| 98 | `/api/v1/rotations/{rotation}` | PUT | oui | aucune | **aucune** | 501 |
| 99 | `/api/v1/saved-views` | GET | oui | aucune | **aucune** | 200 (voir A-002) |
| 100 | `/api/v1/saved-views` | POST | oui | aucune | **aucune** | 501 |
| 101 | `/api/v1/saved-views/{saved_view}` | GET | oui | aucune | **aucune** | 501 |
| 102 | `/api/v1/saved-views/{saved_view}` | PUT/PATCH | oui | aucune | **aucune** | 501 |
| 103 | `/api/v1/saved-views/{saved_view}` | DELETE | oui | aucune | **aucune** | 501 |
| 104 | `/api/v1/scraper-runs` | GET | oui | aucune | **aucune** | 200 |
| 105 | `/api/v1/scraper-runs/{run}` | GET | oui | aucune | **aucune** | 404 (jeu vide) |
| 106 | `/api/v1/scraper-runs/{run}/cancel` | POST | oui | aucune | **aucune** | 422 (validation seule) |
| 107 | `/api/v1/scraper-runs/{run}/retry` | POST | oui | aucune | **aucune** | 422 (validation seule) |
| 108 | `/api/v1/search` | GET | oui | aucune | **aucune** | 200 |
| 109 | `/api/v1/tags` | GET | oui | aucune | **aucune** | 200 |
| 110 | `/api/v1/tags` | POST | oui | aucune | **aucune** | **201 avec charge utile valide** |
| 111 | `/api/v1/tags/{tag}` | PUT | oui | aucune | **aucune** | **200 (modification aboutie)** |
| 112 | `/api/v1/tags/{tag}` | DELETE | oui | aucune | **aucune** | **200 (suppression aboutie)** |
| 113 | `/api/v1/users` | GET | oui | aucune | **aucune** | 200 |
| 114 | `/api/v1/users` | POST | oui | aucune | **aucune** | 501 |
| 115 | `/api/v1/users/{user}` | PUT | oui | aucune | **aucune** | 501 |
| 116 | `/api/v1/users/{user}` | DELETE | oui | aucune | **aucune** | 501 |
| 117 | `/api/v1/workspace` | GET | oui | aucune | **aucune** | 200 |
| 118 | `/api/v1/workspace` | PUT | oui | aucune | **aucune** | 501 |

> **Lire correctement les 404 et 501.** Ce ne sont **pas** des refus d'autorisation. Un `501`
> signifie « fonction non écrite » ; un `404` signifie « objet absent du jeu de données » ou
> « drapeau `crm.console_v2` fermé ». Aucun des deux ne protège quoi que ce soit : le jour où la
> fonction sera écrite ou le drapeau ouvert, la route s'ouvrira **sans garde**. Sur les 102 routes
> authentifiées sans garde, ce qui tient aujourd'hui ne tient que par l'inachèvement.

---

## 4. La matrice par rôle — ce que le serveur rend, compte par compte

Preuve `11-matrice-roles.txt`, sessions réelles, cache de permissions purgé avant mesure.

| Route | `norole` | `viewer` | `operator` | `admin` | `owner` | `owner2` (autre espace) |
|-------|----------|----------|------------|---------|---------|--------------------------|
| `/companies/export` | **403** | **403** | 200 | 200 | 200 | 200 |
| `/media/export` | **403** | **403** | 500 | 500 | 500 | 500 |
| `/journalists/export` | **403** | **403** | 500 | 500 | 500 | 500 |
| `/audit-logs` | 200 | 200 | 200 | 200 | 200 | **200 (données d'un autre espace)** |
| `/users` | 200 | 200 | 200 | 200 | 200 | 200 |
| `/proxy-providers` | 200 | 200 | 200 | 200 | 200 | 200 |
| `/rgpd/requests` | 200 | 200 | 200 | 200 | 200 | 200 |
| `/scraper-runs` | 200 | 200 | 200 | 200 | 200 | 200 |

Un compte **sans aucun rôle** (`"roles":[]`) obtient exactement les mêmes réponses qu'un
propriétaire, sur toutes les routes sauf les quatre gardées. Le rôle n'a, à trois exceptions près,
**aucun effet observable**.

**Note d'honnêteté sur une fausse piste que j'ai levée.** Un premier passage de cette matrice
rendait `403` à *tout le monde*, y compris au propriétaire, sur les trois exports. J'ai failli
l'écrire comme constat — « la garde bloque ses propres ayants droit ». C'était **faux** :
diagnostic joué (`hasPermissionTo=false` alors que `getPermissionsViaRoles()` listait les 16
permissions), cause trouvée = **cache de permissions Spatie périmé dans mon propre environnement
de mesure**. Après `php artisan permission:cache-reset`, `can('data.export')` rend `true` et la
matrice donne les valeurs ci-dessus. **Artefact de mon atelier, pas défaut du produit** : je ne le
rapporte donc pas comme constat.

---

## 5. `contacts.view_pii` — où elle est contrôlée, et où elle ne l'est pas

**Où elle est contrôlée** : nulle part comme intergiciel de route. Uniquement à l'intérieur de
trois contrôleurs, via `App\Support\MasquageCoordonnees::requis()`
(`= ! auth()->user()->can('contacts.view_pii')`), aux six points suivants :

| Emplacement | Champs masqués | Mesuré |
|-------------|----------------|--------|
| `CompaniesController.php:87,93,97` (index) | `email_generic`, `phone` | **masque correctement** |
| `ContactsController.php:42,103,106` (index) | `email`, `phone` | **masque correctement** |
| `Crm/ContactsHubController.php:68,268,276,277` | `email_generic`, `email`, `phone` | non vérifiable (drapeau `crm.console_v2` fermé → 404) |

**Où elle ne l'est pas** : sur les **fiches individuelles**. `ContactsController::show()`
(l. 125-128) est en entier :

```php
public function show(Contact $contact): JsonResponse
{
    return $this->ok($contact);
}
```

Le modèle brut, sans masquage, sans contrôle d'espace de travail, sans policy.

**Comparaison champ par champ, même compte, même personne** (preuve `06-view-pii-comparaison.txt`,
confirmée par `12-verification-finale.txt`) :

| Compte | `contacts.view_pii` | `GET /contacts` (liste) | `GET /contacts/{id}` (fiche) |
|--------|---------------------|--------------------------|-------------------------------|
| `viewer` | **non** | `"email":"p***@alpha-a36.fr"` · `"phone":"+336******44"` | **`"email":"pierre.durand@alpha-a36.fr"` · `"phone":"+33611223344"`** |
| `norole` | **non** | `"email":"p***@alpha-a36.fr"` · `"phone":"+336******44"` | **`"email":"pierre.durand@alpha-a36.fr"` · `"phone":"+33611223344"`** |
| `operator` | oui | `"email":"pierre.durand@alpha-a36.fr"` · `"phone":"+33611223344"` | idem (attendu) |
| `owner` | oui | `"email":"pierre.durand@alpha-a36.fr"` · `"phone":"+33611223344"` | idem (attendu) |

La garde fonctionne — et c'est justement ce qui la rend trompeuse. Elle mesure **l'affichage en
liste**, pas **l'accès à la donnée**. Un clic sur la fiche livre au compte non habilité l'adresse
et le numéro complets. C'est le piège 19 du dossier commun, en grandeur nature : une garde
irréprochable posée sur le mauvais objet.

---

## 6. Règle 2 et règle 3 — les deux preuves exigées

### 6.1 Le test qui rougit (règle 2)

Sur la seule garde d'autorisation réellement en service, `permission:data.export`.

**Avant** (`08-test-qui-rougit.txt`) :

```
PASS  Tests\Feature\ExportPermissionTest
✓ un propriétaire peut exporter — la garde ne verrouille pas les ayants droit
✓ la garde couvre AUSSI les médias et les journalistes
✓ un viewer NE PEUT PAS exporter les entreprises
✓ un compte SANS aucun rôle ne peut pas exporter
✓ un viewer garde l'accès en LECTURE
✓ un opérateur peut exporter (la permission existe pour lui)
Tests:  6 passed (7 assertions)
```

**Retrait** de `, 'permission:data.export'` sur les trois routes d'export (3 occurrences → 0) :

```
FAILED  ExportPermissionTest > un viewer NE PEUT PAS exporter les entreprises
Expected response status code [403] but received 200.
Tests:  3 failed, 3 passed (6 assertions)
```

**Restauration** : 3 occurrences rétablies, suite reverte — `Tests: 6 passed (7 assertions)`.

**État du dépôt hôte après manipulation** :

```
$ git status --porcelain -- backend/
(aucune ligne)
```

La manipulation a porté sur la copie du conteneur, jamais sur la copie de travail — choix
délibéré (§0) pour ne pas fausser les mesures des agents concurrents, qui lisent le même dossier
monté. Empreintes vérifiées identiques après restauration :

```
9ee4c9e3aa26a1eda86488a53ebdc176  app/Policies/BasePolicy.php     (conteneur ET hôte)
3f8154b85476a9c991abdc5177685477  app/Policies/AuditLogPolicy.php (conteneur ET hôte)
```

### 6.2 Le témoin négatif (règle 3)

Avant d'écrire « aucune policy n'est appelée », il fallait prouver que mon contrôle **aurait vu**
un appel. J'ai donc fabriqué la fuite : ajout du trait `AuthorizesRequests` sur `ApiController`
puis d'un `$this->authorize('viewAny', AuditLog::class)` dans `AuditLogsController::index`, sur la
copie conteneur. Résultat (`03-temoin-negatif-auditlogpolicy.txt`) :

```
viewer    status=403 "This action is unauthorized."
operator  status=403 "This action is unauthorized."
admin     status=403 "This action is unauthorized."
owner     status=200 {"data":[...]}
owner2    status=200 {"data":[...]}

=== POLICY HITS (témoin) ===
App\Policies\AuditLogPolicy::viewAny
App\Policies\AuditLogPolicy::viewAny
App\Policies\AuditLogPolicy::viewAny
App\Policies\AuditLogPolicy::viewAny
App\Policies\AuditLogPolicy::viewAny
```

**Cinq appels enregistrés, trois 403 rendus.** Le détecteur voit. Le même détecteur, sur le code
réel, a enregistré **zéro appel sur 117 requêtes**. Le « rien trouvé » est donc recevable.

Second témoin négatif, pour l'affirmation « aucun test ne rattraperait une régression de policy » :
les onze policies réécrites en refus total laissent **15 tests verts** et l'API **inchangée**
(§2). Et statiquement : **aucun fichier de `tests/` ne référence `App\Policies`** — les cinq
occurrences du mot « Policy » dans la suite désignent les policies **RLS de Postgres**, objet
différent.

---

## 7. Ce que je n'ai PAS pu vérifier — et pourquoi

C'est un livrable, pas un aveu.

1. **Les 9 routes du groupe `crm-console`** (`/crm/contacts-hub`, `/crm/candidates`,
   `/crm/arbitrage`, `/crm/bulk`, `/crm/persons/{k}/timeline`, et leurs `counts`) : le drapeau
   `crm.console_v2` est fermé, elles rendent 404. Je n'ai **pas** forcé le drapeau : cela aurait
   modifié la configuration d'un atelier partagé avec une vingtaine d'autres agents. Le masquage
   de `ContactsHubController` (`:68,268,276,277`) reste donc **non vérifié en exécution**. Sa
   lecture suggère qu'il masque en liste comme les deux autres — donc probablement le même défaut
   que F36-006 sur les fiches, mais je ne l'affirme pas.
2. **Le comportement en production.** Interdit, et je m'y suis tenu. Deux conséquences précises :
   (a) F36-007 (RLS inerte) est mesurée **sur le Postgres local** — le rôle applicatif
   non-propriétaire `axion_app` existe dans le dépôt et pourrait changer la donne en production ;
   il faut le vérifier côté serveur, sans moi. (b) Les codes de statut de la matrice §4 sont ceux
   du local.
3. **`POST /auth/logout`** : volontairement exclue du balayage (elle détruit la session en cours
   de mesure). Sa garde d'autorisation est `aucune`, ce qui est correct pour cette route.
4. **Le frontend.** Je n'ai pas mesuré si l'interface masque des boutons selon le rôle. Ce n'est
   pas mon périmètre, et cela ne changerait rien : les mesures ci-dessus sont faites contre l'API,
   qu'un navigateur atteint directement.
5. **Les 4 routes `api/internal`** (HMAC) : elles rendent 401/503 sans signature valide. Je n'ai
   pas fabriqué de signature HMAC valide — hors périmètre « permissions par rôle », et l'agent
   dédié au canal interne est mieux placé.
6. **Comment reproduire.** La base jetable `axion_crm_a36` et le conteneur `a36-api` sont
   **conservés** (conteneur arrêté, pour ne pas peser sur un atelier déjà saturé).
   `docker start a36-api` puis `sh /tmp/login.sh a36-viewer@test.local viewer` et
   `sh /tmp/sweep.sh viewer` rejouent l'ensemble. Mot de passe des six comptes :
   `MotDePasseA36!2026`. Espaces : ALPHA `c6a16436-…`, BETA `5c654b31-…`. La base de test
   `axion_crm_a36_test` a été supprimée après usage.
7. **Mesures expirées, et dites comme telles** : le premier balayage de la matrice par rôle a
   dépassé 10 min et n'a rien produit ; il a été relancé en arrière-plan. Deux requêtes `psql` ont
   également expiré à 300 s et ont été rejouées. Aucune conclusion n'a été tirée d'une mesure
   expirée. La ligne `/workspace` de la matrice §4 a été perdue à l'écriture concurrente du
   fichier ; elle est remplacée par la mesure directe du §« cloisonnement » (`viewer` → son
   espace, `owner2` → le sien, tous deux 200 et **correctement cloisonnés sur cette route-là**).

---

## 8. Constats

### [F36-001] Aucune des 11 policies n'est jamais invoquée : la couche d'autorisation du produit est du code mort
- Sévérité      : S0 bloquant
- Domaine       : sécurité / backend
- Référence     : main e8924b8
- Emplacement   : `backend/app/Policies/` (11 fichiers) · `backend/routes/api.php` · `backend/app/Http/Controllers/Api/` (35 contrôleurs)
- Constat       : les 11 policies sont écrites, les 10 concrètes sont enregistrées et résolues par le noyau, et **aucune n'est appelée** — 0 intergiciel `can:` sur 118 routes, 0 appel à `authorize()`/`Gate::` dans `app/`, 0 appel enregistré par instrumentation sur 117 requêtes réelles.
- Preuve        : `04_PREUVES/agent-36/02-balayage-viewer.tsv` (117 requêtes) ; `09-policies-inertes.txt` (les 11 policies réécrites en refus total → API strictement identique, 12 codes 200 inchangés, 15 tests verts) ; `10-policies-enregistrees.txt` (`Gate::getPolicyFor()` résout les 10) ; instrumentation de `BasePolicy` → fichier de trace jamais créé.
- Témoin négatif: `03-temoin-negatif-auditlogpolicy.txt` — un `$this->authorize('viewAny', AuditLog::class)` ajouté fait apparaître **5 appels** dans la trace et **3 codes 403**. Le détecteur voit ; sur le code réel il ne voit rien.
- Impact        : la totalité du modèle d'autorisation par rôle (owner/admin/operator/viewer) est décorative. Toute revue de sécurité qui lit `app/Policies/` conclura à tort que le produit est cloisonné. C'est la racine de F36-003, F36-004 et F36-005.
- Reproduction  : `grep -rn "authorize(\|Gate::\|->cannot(" backend/app/` → seuls des `authorize()` de FormRequest ; `php artisan route:list --json | grep -c Authorize` → 0 ; puis réécrire les policies en `return false` et rejouer l'API : rien ne change.
- Correctif     : deux temps. (a) Rendre la couche utilisable : faire hériter `ApiController` de `App\Http\Controllers\Controller` **ou** y ajouter le trait `AuthorizesRequests` — 2 lignes, cf. F36-002. (b) Poser les appels : `authorizeResource()` sur les contrôleurs de ressource, ou l'intergiciel `can:` sur les routes. Coût estimé : 1 à 2 j pour les 102 routes, plus une passe de tests par rôle. À faire **avant** toute ouverture du drapeau `crm.console_v2`, qui publiera 9 routes de plus sans garde.
- Statut        : ouvert

### [F36-002] `$this->authorize()` est fatal dans les 35 contrôleurs d'API : la couche policy y est structurellement inatteignable
- Sévérité      : S0 bloquant
- Domaine       : sécurité / backend
- Référence     : main e8924b8
- Emplacement   : `backend/app/Http/Controllers/Api/ApiController.php:6` et `:47`
- Constat       : `ApiController` étend `Illuminate\Routing\Controller`, qui ne porte pas le trait `AuthorizesRequests` ; `App\Http\Controllers\Controller` le porte, mais aucun contrôleur d'API n'en hérite — appeler `authorize()` y lève `BadMethodCallException`.
- Preuve        : ajout de `$this->authorize('viewAny', AuditLog::class)` dans `AuditLogsController::index`, requête jouée → `status=500`, `"message":"Method App\\Http\\Controllers\\Api\\AuditLogsController::authorize does not exist."`, `"exception":"BadMethodCallException"`. Puis ajout du trait sur `ApiController` → la même requête rend **403** au `viewer` et **200** au `owner`.
- Témoin négatif: la seconde moitié de la manipulation *est* le témoin : la même ligne de code, avec le trait, produit exactement le refus attendu. L'échec initial n'est donc pas dû à la ligne mais bien à la classe de base.
- Impact        : un développeur qui suit la convention Laravel et écrit `$this->authorize(...)` dans un contrôleur d'API provoque une 500 en production, pas un 403. C'est une trappe : la manière correcte de faire échoue plus bruyamment que l'absence de garde.
- Reproduction  : `grep -n "^use Illuminate" backend/app/Http/Controllers/Api/ApiController.php` puis `grep -rn "AuthorizesRequests" backend/app/` → une seule occurrence, dans `Controller.php`, dont aucun contrôleur d'API n'hérite.
- Correctif     : ajouter `use Illuminate\Foundation\Auth\Access\AuthorizesRequests;` et `use AuthorizesRequests;` dans `ApiController` — 2 lignes, mesurées comme suffisantes. Coût : < 1 h avec le test de non-régression.
- Statut        : ouvert

### [F36-003] Un compte en lecture seule crée, modifie et supprime définitivement des entreprises et des étiquettes
- Sévérité      : S0 bloquant
- Domaine       : sécurité / conformité
- Référence     : main e8924b8
- Emplacement   : `backend/routes/api.php:122-128` et `:185-188` · `backend/app/Policies/BasePolicy.php:18-19`
- Constat       : le compte `a36-viewer@test.local`, rôle `viewer` (permissions : `companies.view`, `llm.view_usage`, `rgpd.view`), a créé une étiquette, renommé une entreprise et l'a **supprimée de la base**, en session HTTP réelle.
- Preuve        : `04_PREUVES/agent-36/07-escalade-viewer.txt` —
  `POST /api/v1/tags` → **201** (`"name":"ESCALADE-A36"`) ;
  `PUT /api/v1/companies/4` → **200** (`"denomination":"MODIFIE PAR UN LECTEUR SEUL"`) ;
  `DELETE /api/v1/companies/4` → **204**, puis vérification en base : la ligne a disparu (`companies` ne contient plus que l'entreprise de l'autre espace), et le contact rattaché a disparu par cascade. Également : `PUT /tags/{tag}` → 200, `DELETE /tags/{tag}` → 200, `POST /companies/{id}/enrich` → 200, `POST /companies/{id}/recompute-score` → 200.
- Témoin négatif: `BasePolicy::delete()` exige `owner|admin` et `create()`/`update()` exigent `owner|admin|operator` — la règle métier existe et est correcte. La preuve qu'elle *fonctionnerait* si elle était appelée est le témoin de F36-001 : branchée sur `/audit-logs`, la même mécanique rend 403 au `viewer` et 200 au `owner`. Le défaut est l'absence d'appel, pas la règle.
- Impact        : tout compte destiné à la consultation — sous-traitant, stagiaire, client en lecture, compte de démonstration — peut détruire irréversiblement des fiches nominatives. Sur la base de production (4,29 M de fiches), un `DELETE` en boucle est une perte de données ; au sens du RGPD, c'est aussi une atteinte à l'intégrité (art. 32-1-b).
- Reproduction  : créer un utilisateur avec le rôle `viewer` (par insertion en base — aucune interface, cf. F36-010), se connecter via `POST /api/v1/auth/login`, puis `DELETE /api/v1/companies/{id}`.
- Correctif     : couvert par F36-001 + F36-002. En attendant, mesure d'urgence à coût quasi nul : poser `permission:companies.delete` sur `DELETE /companies/{company}`, `permission:companies.update` sur `PUT /companies/{company}` et sur les routes `/tags` — l'intergiciel Spatie est déjà câblé et démontré fonctionnel sur 4 routes. Coût : ~2 h pour les routes destructrices, contre 1-2 j pour la reprise complète.
- Statut        : ouvert

### [F36-004] `/audit-logs` : ni garde, ni cloisonnement — le journal d'audit d'un espace de travail est lisible depuis un autre (B16-004 CONFIRMÉ et étendu)
- Sévérité      : S0 bloquant
- Domaine       : sécurité / conformité
- Référence     : main e8924b8
- Emplacement   : `backend/app/Http/Controllers/Api/AuditLogsController.php:22-37` · `backend/routes/api.php:218-219` · `backend/app/Policies/AuditLogPolicy.php:9`
- Constat       : `AuditLogPolicy::viewAny` exige le rôle `owner` et n'est jamais appelée ; `AuditLogsController::index` exécute `AuditLog::query()->orderByDesc('id')->paginate(50)` **sans filtre de workspace** ; la table `audit_logs` est la seule des quatre examinées à ne porter **aucune** policy RLS (`rowsecurity = f`).
- Preuve        : `04_PREUVES/agent-36/04-audit-logs-cloisonnement.txt` — le compte `owner2`, dont l'espace courant est BETA (`5c654b31-…`), reçoit **200** et **49 entrées portant toutes `"workspace_id":"c6a16436-…"` (ALPHA)**, plus 1 entrée à `workspace_id: null`, avec `"total":68` = la **totalité** des lignes de la table (vérité en base : 59 ALPHA + 8 NULL + 1 BETA = 68). Le compte `viewer` (ni `owner`, ni `audit.view`) obtient la même réponse. `GET /audit-logs/verify-chain` → 200 pour tous.
- Témoin négatif: `03-temoin-negatif-auditlogpolicy.txt` — la policy branchée rend 403 à `viewer`/`operator`/`admin` et 200 à `owner`. Elle est correcte et opérante ; elle n'est pas appelée. Noter que **même branchée elle ne suffirait pas** : `owner2` est `owner` et obtient toujours les lignes d'ALPHA, la policy ne portant aucune condition de workspace sur `viewAny`.
- Impact        : le journal d'audit contient chemins, adresses IP, agents utilisateurs et identifiants d'utilisateur. Un locataire lit l'activité complète d'un autre locataire. Violation de confidentialité (RGPD art. 5-1-f, art. 32) et perte de valeur probante du journal lui-même.
- Reproduction  : deux espaces de travail, un compte dans chacun ; `GET /api/v1/audit-logs` depuis l'un renvoie les lignes de l'autre.
- Correctif     : trois gestes, cumulatifs et tous nécessaires : (a) `->where('workspace_id', app('workspace.id'))` dans `index()` et `verifyChain()` ; (b) l'appel de policy (F36-001) ; (c) une policy RLS `audit_logs_workspace_isolation` alignée sur les 60 autres tables — attention, `audit_logs` est **partitionnée par plage**, la policy doit être posée sur la table mère et héritée. Coût : ~4 h, plus la reprise des 8 lignes à `workspace_id` nul (les connexions, qui sont écrites avant que le workspace ne soit résolu).
- Statut        : ouvert

### [F36-005] Les fiches individuelles traversent la frontière entre espaces de travail : `GET /contacts/{id}` et `GET /companies/{id}` rendent la fiche d'un autre locataire
- Sévérité      : S0 bloquant
- Domaine       : sécurité / conformité
- Référence     : main e8924b8
- Emplacement   : `backend/app/Http/Controllers/Api/ContactsController.php:125-128` · `backend/app/Http/Controllers/Api/CompaniesController.php` (méthode `show`) · `backend/app/Policies/BasePolicy.php:16` (`view()` → `sameWorkspace()`, jamais appelée)
- Constat       : les **listes** sont correctement cloisonnées (portée applicative explicite `->where('workspace_id', $workspaceId)`), mais les **fiches** ne le sont pas : la liaison automatique de modèle charge l'objet par identifiant sans condition de workspace, et le contrôleur le rend tel quel.
- Preuve        : `04_PREUVES/agent-36/05-cloisonnement-workspaces.txt` et `12-verification-finale.txt` — `viewer` (espace ALPHA) sur `GET /api/v1/companies/3` → **200** avec `"workspace_id":"5c654b31-…"` (BETA) ; sur `GET /api/v1/contacts/2` → **200** avec la fiche nominative de BETA. Symétriquement, `owner2` (BETA) lit la fiche d'ALPHA. Les mêmes comptes, sur `GET /api/v1/companies` (liste), ne voient **que** leur propre espace — la frontière existe en liste et disparaît en fiche.
- Témoin négatif: la comparaison liste/fiche **est** le témoin : sur la même requête, le même compte, la même donnée, la liste cloisonne et la fiche ne cloisonne pas. Le contrôle n'est donc pas aveugle — il voit bien la différence.
- Impact        : énumération d'identifiants entiers séquentiels (`/contacts/1`, `/contacts/2`, …) suffit à extraire la base nominative de tous les autres locataires, fiche par fiche. Sur la base de production, 4,29 M de fiches. RGPD art. 5-1-f et art. 32.
- Reproduction  : deux espaces ; se connecter dans le premier ; `GET /api/v1/contacts/{id}` avec un identifiant du second → 200 et données complètes.
- Correctif     : (a) court terme, ajouter une portée globale de workspace sur `Company` et `Contact` (le dépôt possède déjà `WorkspaceContext` et un drapeau `crm.strict_workspace_scope`, aujourd'hui à `false` — l'activer et le tester est la piste la moins coûteuse) ; (b) l'appel de `view()` de la policy, qui contient déjà `sameWorkspace()`. Coût : ~1 j avec les tests d'étanchéité par ressource.
- Statut        : ouvert

### [F36-006] La garde `contacts.view_pii` protège la liste et pas la fiche : un compte non habilité obtient l'adresse et le téléphone complets en un clic
- Sévérité      : S1 grave
- Domaine       : conformité / sécurité
- Référence     : main e8924b8
- Emplacement   : `backend/app/Http/Controllers/Api/ContactsController.php:125-128` (fiche, non masquée) contre `:42,103,106` (liste, masquée) · `backend/app/Support/MasquageCoordonnees.php`
- Constat       : `MasquageCoordonnees::requis()` n'est invoqué que dans les méthodes d'index de `CompaniesController`, `ContactsController` et `ContactsHubController` ; `ContactsController::show()` rend le modèle brut.
- Preuve        : `04_PREUVES/agent-36/06-view-pii-comparaison.txt` — compte `viewer` (sans `contacts.view_pii`) : `GET /contacts` → `"email":"p***@alpha-a36.fr"`, `"phone":"+336******44"` ; `GET /contacts/4` → `"email":"pierre.durand@alpha-a36.fr"`, `"phone":"+33611223344"`. Idem pour le compte sans aucun rôle. Les comptes `operator` et `owner`, eux, voient le clair dans les deux cas — la garde fonctionne donc bien, mais au mauvais endroit.
- Témoin négatif: le contrôle discrimine correctement quand il s'applique — `viewer` masqué, `operator` en clair, sur la même liste et la même personne. Il aurait donc vu une absence de masquage en liste. Il n'y en a pas : le défaut est exclusivement sur la fiche.
- Impact        : la mesure de minimisation revendiquée par le commentaire de `MasquageCoordonnees` (« un `viewer` doit pouvoir consulter, pas repartir avec 665 771 adresses ») est contournée par la navigation normale du produit — ouvrir une fiche. Le masquage protège contre la recopie en masse à l'écran, pas contre l'extraction fiche à fiche, qui est aussi automatisable.
- Reproduction  : compte sans `contacts.view_pii`, `GET /api/v1/contacts` (masqué) puis `GET /api/v1/contacts/{id}` (en clair).
- Correctif     : appliquer `MasquageCoordonnees` dans `show()` des trois contrôleurs concernés, et sortir la sérialisation dans une ressource partagée pour que liste et fiche ne puissent plus diverger — c'est la divergence qui a créé le trou (piège 15 du dossier commun). Coût : ~4 h. **Cette correction ne suffit pas seule** : sans F36-005, la fiche reste lisible depuis un autre espace, masquée ou non.
- Statut        : ouvert

### [F36-007] Les 60+ policies RLS de Postgres sont inertes en local : le rôle de connexion est superutilisateur et contourne la sécurité au niveau ligne
- Sévérité      : S1 grave
- Domaine       : sécurité / backend
- Référence     : main e8924b8
- Emplacement   : conteneur `axion-crm-postgres`, rôle `axion` · `backend/database/migrations/` (policies `*_workspace_isolation`)
- Constat       : les tables `companies`, `contacts`, `tags` portent bien `rowsecurity = t` **et** `relforcerowsecurity = t`, avec une policy d'isolation par `app.current_workspace_id` — mais le rôle utilisé par l'application est `rolsuper = t` **et** `rolbypassrls = t`, ce qui neutralise inconditionnellement la RLS.
- Preuve        : `select rolname,rolsuper,rolbypassrls from pg_roles where rolname='axion'` → `axion | t | t`. Et, sans aucun contexte posé : `select count(*) from companies` → **2** (les deux espaces confondus) là où la policy imposerait 0.
- Témoin négatif: le contrôle voit bien la RLS quand elle existe : il distingue `audit_logs` (`rowsecurity = f`, aucune policy) des trois autres tables (`t`, avec policy nommée et prédicat lu). Il aurait donc signalé une table protégée. Ce qu'il montre ici, c'est que la protection déclarée n'est jamais évaluée.
- Impact        : la « défense en profondeur » revendiquée dans les commentaires du code (`ContactsController:44-46`, `WorkspaceContext`) n'a **qu'une seule couche** en réalité — la portée applicative. C'est exactement ce qui rend F36-005 exploitable : là où le contrôleur oublie le `where`, plus rien ne rattrape.
- Reproduction  : `docker exec axion-crm-postgres psql -U axion -d <base> -c "select rolsuper,rolbypassrls from pg_roles where rolname='axion'"`.
- Correctif     : faire tourner l'application sous le rôle applicatif non-propriétaire que le dépôt prévoit déjà (`DB_APP_USERNAME=axion_app`, drapeau `CRM_DB_APP_ROLE_ENABLED`) plutôt que sous `axion`. ⚠️ **À vérifier en production avant de conclure** : je n'ai pas le droit d'y regarder, et la posture peut y être différente. Coût de la vérification : 5 min pour qui a l'accès ; coût du basculement si nécessaire : ~1 j (droits, migrations, entrypoint).
- Statut        : ouvert
- Note          : le voisinage RLS relève d'un autre agent ; je le rapporte parce qu'il est le filet censé rattraper F36-005, et qu'il ne le rattrape pas.

### [F36-008] `GET /media/export` et `GET /journalists/export` rendent 500 à tous les comptes habilités : la garde est la seule partie qui fonctionne
- Sévérité      : S1 grave
- Domaine       : backend / conformité
- Référence     : main e8924b8
- Emplacement   : `backend/app/Http/Controllers/Api/MediaController.php:114` · `backend/app/Http/Controllers/Api/JournalistsController.php:103` · `backend/app/Support/EligibiliteCampagne.php:130`
- Constat       : les deux contrôleurs passent un `Spatie\QueryBuilder\QueryBuilder` à `EligibiliteCampagne::exclureOpposes()`, dont la signature exige un `Illuminate\Database\Eloquent\Builder` — le typage échoue à chaque appel.
- Preuve        : `04_PREUVES/agent-36/11-matrice-roles.txt` — `/media/export` et `/journalists/export` : `norole=403 viewer=403 operator=500 admin=500 owner=500 owner2=500`. Corps de la réponse : `"App\\Support\\EligibiliteCampagne::exclureOpposes(): Argument #1 ($query) must be of type Illuminate\\Database\\Eloquent\\Builder, Spatie\\QueryBuilder\\QueryBuilder given, called in .../MediaController.php on line 114"`, `"exception":"TypeError"`.
- Témoin négatif: la troisième route du même trio, `/companies/export`, rend **200** aux mêmes comptes habilités — elle appelle `exclureOpposes($relation->getQuery(), …)`, c'est-à-dire un vrai `Eloquent\Builder`. Le contrôle distingue donc bien un export qui marche d'un export qui casse ; il n'accuse pas les trois en bloc.
- Impact        : deux exports sur trois sont totalement hors service, et personne ne l'a vu — parce que `ExportPermissionTest` n'affirme sur eux que le **refus** (`assertForbidden` pour un `viewer`), jamais le succès pour un ayant droit. Le test est vert et la fonction est morte. Conséquence de conformité : les portes d'opposition (`opt_out`, `email_suppressions`) ajoutées le 2026-08-16 sur ces deux exports **ne s'exécutent jamais**, puisque l'appel échoue avant.
- Reproduction  : se connecter avec un compte `owner`, `GET /api/v1/media/export` → 500.
- Correctif     : passer `->getEloquentBuilder()` (ou `->toBase()` selon l'usage aval) aux deux appels, et **ajouter à `ExportPermissionTest` un `assertOk()` pour un ayant droit sur les trois exports** — sans quoi le défaut reviendra. Coût : ~2 h.
- Statut        : ouvert

### [F36-009] Les 11 policies n'ont aucune couverture de test : les réécrire en refus total laisse la suite verte
- Sévérité      : S2 défaut
- Domaine       : tests / sécurité
- Référence     : main e8924b8
- Emplacement   : `backend/tests/` (21 fichiers) · `backend/app/Policies/`
- Constat       : aucun fichier de test ne référence `App\Policies` ; les cinq fichiers contenant le mot « Policy » désignent les policies RLS de Postgres.
- Preuve        : `grep -rn "App\\Policies\|app/Policies" backend/tests/` → aucune occurrence. Et `04_PREUVES/agent-36/09-policies-inertes.txt` : les 11 policies réécrites en `return false` → `Tests: 15 passed (33 assertions)`, aucune régression détectée.
- Témoin négatif: la même suite **rougit correctement** quand on touche une garde réellement branchée — `08-test-qui-rougit.txt`, retrait de `permission:data.export` → `3 failed`. La suite n'est donc pas aveugle en général ; elle est aveugle sur cet objet précis.
- Impact        : rien n'empêchera la couche d'autorisation de se dégrader davantage, ni ne signalera qu'elle est morte. Une fois F36-001 corrigé, la correction elle-même sera sans filet.
- Reproduction  : réécrire `BasePolicy` avec `return false` partout et lancer `./vendor/bin/pest` — vert.
- Correctif     : un test par ressource et par rôle (owner/admin/operator/viewer/sans rôle) sur les verbes lecture / création / modification / suppression, écrit **contre l'API** et non contre la classe de policy — une garde ne vaut que si on l'a vue rougir sur la route, pas sur la classe. Coût : ~1 j, à écrire en même temps que F36-001.
- Statut        : ouvert

### [F36-010] Aucune interface ne permet de créer un compte, de lui donner un rôle ou de le lui retirer : le modèle de rôles n'est pas administrable
- Sévérité      : S1 grave
- Domaine       : backend / UX
- Référence     : main e8924b8
- Emplacement   : `backend/app/Http/Controllers/Api/UsersController.php:51,59,67` · `backend/routes/api.php:110-113`
- Constat       : `store()`, `update()` et `destroy()` rendent 501 ; le fichier ne contient aucune occurrence de `role` ni de `permission` ; aucune des 118 routes ne permet d'attribuer ou de retirer un rôle, ni de changer un utilisateur d'espace de travail.
- Preuve        : mesuré en session ouverte — `POST /api/v1/users` → **501**, `PUT /api/v1/users/{user}` → **501**, `DELETE /api/v1/users/{user}` → **501** (`04_PREUVES/agent-36/02-balayage-viewer.tsv`, lignes `users`). `GET /api/v1/users` rend bien la liste (200) mais **sans le rôle** : les champs projetés sont `id, email, name, current_workspace_id, first_login_completed_at, two_factor_enabled, last_login_at` (`UsersController.php:33`).
- Témoin négatif: le contrôle voit les routes qui, elles, sont implémentées — sur le même balayage, `GET /users` rend 200 et `GET /workspace` rend 200. Il ne confond donc pas « non implémenté » avec « absent ».
- Impact        : deux conséquences. (a) Le dirigeant ne peut pas créer un compte restreint pour un collaborateur ou un sous-traitant — le seul compte fabricable est le `owner` initial du seeder. (b) **Personne ne peut voir qui a quel rôle** : la liste des utilisateurs ne l'affiche pas, et `/auth/me` ne renvoie que le rôle du demandeur. Un cloisonnement qu'on ne peut ni poser ni observer ne peut pas être exploité, ni audité de l'intérieur.
- Reproduction  : session ouverte, `POST /api/v1/users` avec n'importe quelle charge utile → 501.
- Correctif     : implémenter l'invitation et la gestion de rôle (`store`/`update`), et ajouter le rôle à la projection de `index()`. ⚠️ **À faire après F36-001** : ouvrir la création d'utilisateurs avant de brancher les policies donnerait à n'importe quel compte le pouvoir d'inviter — `POST /users` n'a, elle non plus, aucune garde. Coût : ~3 j avec l'écran associé.
- Statut        : ouvert

---

## 9. Réponses directes aux sept questions du mandat

1. **Quels rôles existent réellement ?** Quatre : `owner`, `admin`, `operator`, `viewer`, avec 16 permissions et une répartition cohérente. **Mais on ne peut pas créer de compte restreint par le produit** — `POST/PUT/DELETE /users` rendent 501 (F36-010). J'ai dû passer par la base.
2. **Chaque policy est-elle enregistrée ? appelée ?** Les 10 concrètes sont enregistrées et résolues par le noyau. **Aucune des 11 n'est jamais appelée.** B16-004 n'était pas un cas isolé, c'était la règle (F36-001).
3. **Combien de routes sans policy sur 112 ?** Le noyau publie **118** routes d'API (et non 112, qui est le compte des lignes de déclaration). **118 sur 118 sont sans policy.** 114 sur 118 n'ont **aucune** garde d'autorisation ; **102 des 106 routes authentifiées** n'en ont aucune. Les 4 exceptions sont des permissions Spatie, pas des policies.
4. **`contacts.view_pii` ?** Contrôlée uniquement dans trois méthodes d'index, via `MasquageCoordonnees`. **La fiche individuelle n'est pas masquée** : le même compte voit `p***@alpha-a36.fr` en liste et `pierre.durand@alpha-a36.fr` en fiche (F36-006).
5. **Test qui rougit ?** Fait : retrait de `permission:data.export` → `3 failed`, restauration → `6 passed`, `git status -- backend/` vide (§6.1).
6. **Témoin négatif ?** Fait : une policy branchée volontairement produit 5 appels tracés et 3 codes 403 ; le code réel en produit 0 sur 117 requêtes (§6.2).
7. **Escalade ?**
   - *S'octroyer une permission* : **non** — aucune route ne le permet (501 partout). Sans objet, du reste : le `viewer` a déjà le pouvoir d'un `admin` (F36-003).
   - *Changer d'espace de travail* : **non** — `PUT /workspace` rend 501, aucune route de bascule.
   - *Lire les journaux d'audit d'un autre espace* : **OUI, B16-004 CONFIRMÉ et aggravé** — le compte `owner2` (espace BETA) reçoit 49 entrées appartenant toutes à ALPHA, et `total` = la totalité de la table (F36-004). Et cela ne s'arrête pas au journal : les **fiches** entreprises et contacts traversent aussi la frontière (F36-005).
