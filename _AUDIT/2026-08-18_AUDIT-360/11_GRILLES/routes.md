# AGENT 12 — Grille des routes API

> Périmètre : `backend/routes/api.php`, `backend/routes/channels.php`, `backend/routes/web.php`.
> Référence : dépôt `Axion-CRM-Pro`. **`main` était à `1145473` au moment de la mesure**, et non
> `c0c453d` comme l'annonce le dossier commun (trois commits de documentation RGPD ont atterri
> entre-temps). Vérifié : `git diff --stat c0c453d HEAD -- backend/routes/ backend/app/Http/`
> rend **une sortie vide** — mon périmètre est donc rigoureusement identique à la référence
> annoncée, et tout ce qui suit vaut pour `c0c453d` comme pour `1145473`.
> Toutes les mesures sont jouées dans l'atelier local (`axion-crm-api`, `axion_crm`,
> `axion_crm_a12`). **Aucune écriture en préproduction ni en production.**

---

## 0. Recensement — l'écart avec le prompt d'audit

| Ce qui était annoncé | Ce qui est mesuré | Commande |
|---|---|---|
| « ~110 routes » | **114 déclarations** `Route::verb(...)` dans `api.php` | `grep -cE "^\s*Route::(get\|post\|put\|patch\|delete\|any\|match\|apiResource\|resource)\(" backend/routes/api.php` |
| « 112 déclarations » (dossier commun) | **114** — l'écart de 2 vient des 5 lignes `Route::` qui ne déclarent pas une route (4 groupes + 1 commentaire) | idem |
| — | **117 routes réellement enregistrées** (113 sous `/api/v1`, 4 sous `/api/internal`) | `docker exec axion-crm-api php artisan route:list --path=api` |
| — | **1 déclaration perdue** : `Route::apiResource('saved-views')` en produit 5, et `GET /search` est déclaré deux fois → 110 v1 − 1 + 5 − 1 = **113** | voir §2 |
| `api.php` = 311 lignes | **328 lignes** (le dossier commun avait raison) | `wc -l` |
| — | `channels.php` **55 lignes, 2 canaux**, tous deux inertes (voir B12-019) | `cat` |
| — | `web.php` **9 lignes, 1 route** `GET /` qui rend `{name, env, docs}` | `cat` |

**117 routes recensées, 117 auditées.** Sortie brute :
`04_PREUVES/agent-12/route-list-api.{txt,json}` et `middleware-par-route.txt`.

### Décompte par verdict — compté sur la colonne 18 du tableau, pas estimé

| Verdict | Nombre |
|---|---|
| **Vivante** — implémentée, atteignable, elle fait ce qu'elle annonce | **86** |
| **Factice — répond 501** « à implémenter en Sprint N » | **19** |
| **Factice mais répond 200** avec un corps codé en dur | **9** |
| **Inerte par drapeau** (503 tant que le canal est fermé) | **3** |
| **Total** | **117** |
| *hors total :* **déclaration morte**, jamais atteinte par le routeur | **1** — la fermeture `GET /search` de `api.php:99` |

À quoi il faut ajouter, hors de cette colonne, **une dixième route qui ment sans être un 501** :
`POST /v1/proxy-providers/{p}/test` — un contrôle de santé de fournisseur mandataire qui rend
`{"healthy": true}` **inconditionnellement**, sans jamais contacter quoi que ce soit.
Elle est comptée « vivante » ci-dessus ; elle est pourtant le mensonge le plus dangereux du lot,
parce qu'un contrôle de santé qui répond toujours oui est pire que pas de contrôle du tout.

### Décomptes transversaux — tous comptés sur le tableau

| Mesure | Routes |
|---|---|
| Portent `auth:sanctum` (donc concernées par A-001) | **106** / 117 |
| Portent une **autorisation** réelle (middleware `permission:`) | **4** / 117 |
| Portent un **scope d'espace explicite** dans le code | **50** / 117 |
| **Ne s'appuient QUE sur la RLS** pour l'étanchéité | **21** / 117 |
| Portent une **limitation de débit** | **29** / 117 |
| **N'en portent aucune** | **88** / 117 |
| **Aucun test ne cite la route**, même pour vérifier un 200 | **42** / 117 |
| Listes rendues **sans pagination** (plafond en dur ou rien) | **10** |
| `POST` créant **sans clé d'idempotence** | **18** |
| Lectures sensibles **hors du journal d'audit** (le middleware n'audite que les écritures) | **53** |

---

## 1. Les cinq axes nommés dans la commande — réponses

### 1.1 `GET /search` est-il déclaré deux fois, et laquelle gagne ?

**Oui, deux fois. C'est `GlobalSearchController@index` qui gagne, et l'autre disparaît en silence.**

- `routes/api.php:99` : `Route::get('/search', function (Request $request) { … })` — une fermeture
  anonyme qui rend `{companies:[], contacts:[], tags:[]}`.
- `routes/api.php:207` : `Route::get('/search', [GlobalSearchController::class, 'index'])`.

Le routeur Laravel indexe par `méthode + URI` : la **seconde** déclaration écrase la première.
Mesuré en énumérant la table de routage :

```
entree #1 : GET|HEAD -> App\Http\Controllers\Api\GlobalSearchController@index
total d entrees 'api/v1/search' RETENUES par le routeur : 1
```

L'ironie est complète : **le gagnant rend exactement le même corps vide codé en dur** que le
perdant (`GlobalSearchController::index` = `return $this->ok(['companies' => [], 'contacts' => [],
'tags' => []]);`, 20 lignes de fichier). La duplication est donc **invisible à l'usage** — et c'est
précisément pour cela qu'elle a survécu. Le jour où quelqu'un implémentera la recherche dans la
fermeture de la ligne 99, elle ne s'exécutera pas, et rien ne le dira.
Preuve : `04_PREUVES/agent-12/sondes-internal-a.txt`, section « routage ».

### 1.2 42 contrôleurs, 4 FormRequest — où est la validation du reste ?

Décompte refait à la main : `backend/app/Http/Controllers/` contient **44 fichiers**, dont
`Controller.php` et `ApiController.php` qui sont des classes de base → **42 contrôleurs réels**.
`backend/app/Http/Requests/` contient **4 FormRequest** : `Auth/LoginRequest`,
`StoreEmailAudienceRequest`, `StoreScrapingCampaignRequest`, `UpdateScrapingCampaignRequest`.

La réponse mesurée, route par route (colonne 5 de la grille, comptée sur le tableau) :

| Où vit la validation | Routes | Détail |
|---|---|---|
| **FormRequest** | **4** | `POST /auth/login`, `POST /audiences`, `POST /campaigns`, `PUT /campaigns/{c}` |
| **`validate()` inline** dans la méthode | **23** | 22 occurrences de `->validate(` réparties sur 13 contrôleurs (une sert deux routes) |
| **Contrat d'entrée écrit à la main** (routes internes) | **3** | `/internal/site-sync`, `/internal/site-sync/gdpr`, `/internal/email/zeptomail` |
| **Aucune validation, alors qu'une entrée est lue** | **16** | liste ci-dessous |
| **Stub 501** — la méthode ne lit jamais la requête | **19** | validation sans objet |
| **Rien à valider** — ni corps, ni paramètre de requête | **52** | `GET` de détail, actions sans charge utile |
| **Total** | **117** | |

Les **16 routes qui lisent une entrée sans jamais la valider** :

| Route | Entrée non validée | Ce que ça donne |
|---|---|---|
| `GET /v1/search` | `q` | la documentation OpenAPI annonce `required, minLength=2` ; le code ne lit même pas `q` |
| `GET /v1/companies` | `per_page`, `filter[…]` | bornage à la main (`min(100, max(1, …))`), pas de `validate()` |
| `GET /v1/companies/export` | `filter[…]` | mêmes filtres fermés que la liste, mais aucun contrat |
| `GET /v1/contacts` | `per_page`, `filter[…]` | idem |
| `GET /v1/media` | `per_page`, `filter[…]` | idem |
| `GET /v1/media/export` | `filter[…]` | idem |
| `GET /v1/journalists` | `per_page`, `filter[…]` | idem |
| `GET /v1/journalists/export` | `filter[…]` | idem |
| `GET /v1/campaigns` | `status`, `search`, `per_page` | valeurs libres passées à `where()` |
| `GET /v1/tags` | `category`, `kind` | valeurs libres passées à `where()`, aucune énumération fermée |
| `GET /v1/rgpd/requests` | `status` | valeur libre passée à `where('status', $s)` |
| `GET /v1/coverage` | `level` | valeur libre, aiguillée par un `match` à branche par défaut |
| `GET /v1/coverage/next-zone` | `preferred_dept` | chaîne libre transmise au rotateur de zones |
| `GET /v1/audiences/{a}/members` | `limit` | borné à la main `[1,500]` |
| `GET /v1/rgpd/export/{token}` | le jeton | aucun format imposé — un jeton de longueur quelconque est accepté |
| `POST /internal/scraper-result` | le corps entier | avec `CRM_SCRAPE_FUNNEL_ENABLED=false` (défaut), le corps n'est que journalisé, jamais validé |

**Aucune de ces seize n'ouvre une injection SQL** : toutes passent par des valeurs liées ou par les
listes fermées de `spatie/query-builder` (`CompanyQueryFilters::allowed()`, `allowedSorts`). Le
défaut n'est pas une faille, c'est une **absence de contrat** : une valeur aberrante ne rend jamais
422, elle rend une liste vide qu'on prend pour « il n'y a rien ».

C'est le motif exact du défaut déjà corrigé sur `ContactsController::index` et documenté en tête de
ce même fichier (« la page Contacts était vide pour tout le monde, alors que la base en compte
1,3 M »). Il subsiste sur seize routes.

### 1.3 Les stubs Phase 2

Le dossier commun a raison : il y a **3** contrôleurs sous `Api/Phase2/` — `CampaignsController`,
`ColdEmailController`, `LinkedInController`. `AnalyticsController` et `CrmController` n'existent pas.

Mais la mesure ajoute un fait que le prompt n'annonce pas :

- **`Api/Phase2/CampaignsController` n'est plus routé du tout.** Il n'est pas importé dans
  `api.php` (le fichier note « /campaigns retiré — implémenté en Sprint 19.7 ») et
  `route:list` ne le mentionne nulle part. **C'est un contrôleur mort de 20 lignes.**
- `ColdEmailController` et `LinkedInController` sont routés par deux fourre-tout
  `Route::any('/cold-email{any?}')` / `('/linkedin{any?}')` → **2 entrées de routage** couvrant
  7 verbes chacune, qui répondent 501.
- Le garde-fou `F7` tient : `/v1/crm/inexistant` et `/v1/analytics` rendent bien **404**, plus 501
  (mesuré, `sondes-internal-a.txt`). Le piège de nommage a bien été retiré.

En revanche, **le vocabulaire du cahier des charges reste employé par des routes factices ailleurs**,
et c'est là que le piège s'est déplacé : `/v1/saved-views` (5 routes), `/v1/notifications`
(3 routes), `/v1/users` (3 routes sur 4), `PUT /v1/workspace`, `/v1/rotations`, `/v1/ai-act/register`
(POST), `/v1/llm/use-cases/{u}` et ses prompts, `/v1/proxy-providers/{p}` — **22 routes répondent
501 sous des noms qui décrivent des fonctions attendues du produit**.

### 1.4 `Internal/ZeptoMailWebhookController` — la route absente du prompt

`POST /api/internal/email/zeptomail`, nommée `internal.email.zeptomail`, `throttle:internal`.

- **Signature : il n'y en a pas.** L'authentification est un **jeton partagé passé dans l'URL**
  (`?t=<jeton>`), comparé par `hash_equals`. Le contrôleur documente que c'est la seule chose que
  l'interface de ZeptoMail permet de régler. Conséquence non documentée : **le jeton atterrit dans
  les journaux d'accès, les en-têtes `Referer` et l'historique des proxys**, et **le corps de la
  requête n'est authentifié par rien** — quiconque connaît le jeton peut injecter des adresses
  arbitraires dans la liste de suppression.
- **Rejeu : possible et sans conséquence.** `ListeSuppression::inscrire()` incrémente une occurrence
  au lieu de dupliquer — c'est la bonne réponse, et elle est documentée.
- **Inertie : réelle et mesurée.** Sans `MAIL_WEBHOOK_TOKEN`, la route rend **503** — y compris
  avec un mauvais jeton, ce qui ne révèle rien (mesuré : deux appels, `?t=` absent puis `?t=mauvais`,
  tous deux 503).

### 1.5 Les trois routes internes signées — témoin négatif ET témoin positif

Toutes les mesures : `04_PREUVES/agent-12/sondes-internal-a.txt`.

| Route | Mauvaise signature | Signature calculée avec le secret **réellement configuré** | Corps altéré, même signature | Rejeu à l'identique | Horodatage hors fenêtre |
|---|---|---|---|---|---|
| `POST /internal/scraper-result` | **401** `bad_signature` ✔ | **200 `{"ingested":true}`** ✘ | **401** ✔ | **200 / 200 / 200** ✘ | *pas d'horodatage du tout* |
| `POST /internal/site-sync` | **401** ✔ | **401** ✔ (refus du secret vide) | — | **401** | **401** |
| `POST /internal/site-sync/gdpr` | **401** ✔ | **401** ✔ | — | — | — |

Le témoin négatif est net des deux côtés : une signature fausse est refusée partout, et un corps
altéré portant une signature valide est refusé aussi — le contrôle **sait** trouver.

Ce qui sépare les deux familles est une seule ligne. `App\Support\HmacSignature::verify()` commence par :

```php
if ($secret === '' || $received === null || $received === '') {
    return false;
}
```

`ScraperResultController::store()` **réimplémente le HMAC à la main** et n'a pas cette garde :

```php
$secret   = (string) env('WORKER_INTERNAL_HMAC_SECRET', '');
$expected = hash_hmac('sha256', $body, $secret);
if ($sig === null || ! hash_equals($expected, $sig)) { … 401 … }
```

Or `WORKER_INTERNAL_HMAC_SECRET=` est **vide** dans `.env`, dans `backend/.env` **et dans
`.env.example`**. Un secret vide n'est pas un secret : `hash_hmac('sha256', $corps, '')` se calcule
par n'importe qui. C'est le constat **B12-004**.

---

## 2. A-001 — étendue exacte (constat déjà ouvert, ici mesuré)

Je ne rouvre pas A-001. J'en mesure la portée, qui n'était pas connue.

**La condition de déclenchement n'est pas « sans authentification » : c'est « sans en-tête
`Accept: application/json` ».** Mesuré avec `app.debug` forcé à `false`, c'est-à-dire dans les
conditions de la production (`04_PREUVES/agent-12/sondes-a001.txt`) :

| Requête non authentifiée | `Accept: application/json` | `Accept: text/html` ou aucun `Accept` |
|---|---|---|
| `GET /api/v1/auth/me` | **401** `{"message":"Unauthenticated."}` | **500** |
| `GET /api/v1/companies` | **401** | **500** |
| `GET /api/v1/crm/contacts-hub` | **401** | **500** |
| `POST /api/v1/auth/logout` | **401** | **500** |
| `GET /api/v1/cold-email` | **401** | **500** |

**Étendue : 106 routes sur 117.** C'est le nombre exact de routes portant
`Illuminate\Auth\Middleware\Authenticate:sanctum`
(`grep -c "Authenticate:sanctum" middleware-par-route.txt`).

**Les 11 routes publiques sont épargnées**, vérifié une par une :

| Route publique | Sans en-tête JSON |
|---|---|
| `GET /up` | **200** |
| `GET /` (`web.php`) | **200** |
| `POST /api/v1/auth/magic-link` | **200** |
| `GET /api/v1/rgpd/export/{jeton}` | **404** |
| `POST /api/internal/scraper-result` | **401** `bad_signature` |
| `POST /api/v1/auth/login` | **302 vers `http://api.localhost`** ← voir B12-009 |

**Ce que cela signifie pour le SPA : rien, en fonctionnement normal.** Le client HTTP du frontend
envoie `Accept: application/json`, il reçoit donc 401 et sa redirection vers l'écran de connexion
fonctionne. Les 500 frappent tout le reste : une URL d'API ouverte à la main dans un navigateur, un
lien partagé, une sonde de disponibilité externe, un `curl` sans en-tête, un robot d'indexation.
C'est-à-dire **exactement les appelants dont personne ne lit les journaux** — et le journal, lui,
reçoit une trace complète `Route [login] not defined` à chaque fois.

**Observation supplémentaire, atelier seulement.** Avec `APP_DEBUG=true` (valeur locale), le rendu
de cette 500 **ne se termine pas en moins de quinze minutes** sur ce poste : deux sondes lancées à
11 h 58 et 12 h 03 tournaient encore à 12 h 18, et une sonde `curl` équivalente a d'abord fait
expirer trois requêtes consécutives. Le serveur de l'atelier étant `php -S` **mono-processus**,
une seule requête de ce type suffit à bloquer l'API entière. Je ne transforme pas cette observation
en constat de production : `APP_DEBUG` y vaut `false` (mesuré à `false` → la 500 revient en 200 à
4 000 ms). Mais **toute préproduction qui garderait `APP_DEBUG=true` hériterait d'un déni de service
en une requête anonyme**, et cela n'a pas été vérifié.

---

## 3. Autorisation — le constat qui traverse les 117 lignes de la grille

`grep -rn "authorize(\|Gate::allows\|Gate::denies\|->can(" backend/app/Http/Controllers/` rend
**zéro occurrence**. Il y a pourtant **11 policies** dans `backend/app/Policies/`, toutes
enregistrées dans `AuthServiceProvider::$policies`. **Elles ne sont appelées nulle part.**

La seule autorisation réellement exercée est le middleware `permission:` de Spatie, posé sur
**4 routes sur 117** : `data.export` (trois exports) et `companies.update` (l'action de masse sur les
tags). Témoin positif : un compte `viewer` reçoit bien **403** sur `GET /v1/companies/export`
(mesuré). La garde fonctionne — il y en a quatre.

Conséquence mesurée : voir **B12-003**.

---

## 4. Tableau de grille — 117 routes × 18 points

Légende des cases : `oui` / `NON` / `sans objet — <raison>` / `non vérifié — <raison>`.
Aucune case n'est vide. Le tableau est large : il se lit en défilement horizontal.

| Methode | Route | 1 Authentification | 2 Autorisation (policy) verifiee ET testee | 3 Contexte d espace | 4 Autre espace ⇒ 0 ligne (test qui rougit) | 5 Validation des entrees | 6 Types / bornes / defauts | 7 Injection SQL, tri et filtres arbitraires | 8 Pagination obligatoire | 9 N+1 | 10 Index derriere la requete (EXPLAIN) | 11 Codes et forme de reponse | 12 Idempotence des POST creant | 13 Journal d audit | 14 Donnees personnelles dans la reponse | 15 Limitation de debit | 16 Signature (routes internes) | 17 Test automatise, vu rouge | 18 Morte / factice / dupliquee |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| POST | `/internal/email/zeptomail` | NON — route publique (voulu) | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet | sans objet | tolerante par conception — corps illisible ⇒ 200 compteurs a zero | oui — evenements sur liste fermee, details tronques a 500 car. | sans objet | sans objet | sans objet | sans objet | `{error,message}` 503/401 ; `{ok,counts}` 200 | oui — `ListeSuppression::inscrire` incremente au lieu de dupliquer | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee rendue | oui — `throttle:internal` 600/min/IP | jeton partage dans l URL (`?t=`), `hash_equals` — pas de HMAC, donc corps non authentifie ; jeton expose aux journaux d acces | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | inerte — 503 tant que `MAIL_WEBHOOK_TOKEN` absent (mesure) |
| POST | `/internal/scraper-result` | NON — route publique (voulu) | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — canal machine, pas de session | sans objet — n interroge aucune table | aucune si `CRM_SCRAPE_FUNNEL_ENABLED=false` (defaut) ; `ScrapedRecord::fromArray` sinon | aucun — le corps est journalise tel quel | sans objet — aucune requete | sans objet | sans objet | sans objet | `{error:bad_signature}` 401 / `{ingested:true}` 200 | NON — rejeu a l identique accepte 3 fois sur 3 (mesure) | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee rendue | AUCUNE — seule route interne sans `throttle:internal` (mesure : 9/9 sans 429) | OUI mais FAIL-OPEN : HMAC inline, sans garde de secret vide ; secret `""` ⇒ signature forgee acceptee (200 mesure) | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| POST | `/internal/site-sync` | NON — route publique (voulu) | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — canal machine | sans objet | pivot `SiteSyncEvent::fromArray` (422) | oui — enum fermees, sha256 64 hex, fenetre 300 s | sans objet — requetes parametrees | sans objet | sans objet | sans objet | `{error,message}` + 401/422/503/500 coherents | partielle — fenetre de rejeu de 300 s ouverte, aucun nonce ; idempotence par `event_id` en aval | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee rendue (503, drapeau ferme) | oui — `throttle:internal` 600/min/IP | OUI et FAIL-CLOSED — `HmacSignature::verify` refuse un secret vide ; signature forgee REJETEE (401 mesure) | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | inerte — 503 tant que `CRM_INGEST_ENABLED=false` |
| POST | `/internal/site-sync/gdpr` | NON — route publique (voulu) | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — canal machine | sans objet | contrat strict inline (422 sur champ inconnu / action / person_key / email / scope) | oui — enum fermees, sha256 64 hex, fenetre 300 s | sans objet — requetes parametrees | sans objet | sans objet | sans objet | `{error,message}` + 401/422/503/500 coherents | partielle — fenetre de rejeu de 300 s ouverte, aucun nonce ; idempotence par `event_id` en aval | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee rendue (503, drapeau ferme) | oui — `throttle:internal` 600/min/IP | OUI et FAIL-CLOSED — `HmacSignature::verify` refuse un secret vide ; signature forgee REJETEE (401 mesure) | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | inerte — 503 tant que `CRM_INGEST_ENABLED=false` |
| GET/HEAD | `/v1/ai-act/register` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | sans objet — reponse vide en dur | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | FACTICE MAIS 200 — `{data:[]}` en dur (GET) |
| POST | `/v1/ai-act/register` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — 501 | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | NON — aucune cle d idempotence | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | FACTICE — 501 « a implementer en Sprint 11 » |
| GET/HEAD | `/v1/audiences` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | NON — `limit(200)` en dur, aucune meta | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| POST | `/v1/audiences` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | oui — `StoreEmailAudienceRequest` (FormRequest) | oui pour la forme ; `criteria` est un tableau LIBRE, interprete en aval | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | NON — deux appels creent deux audiences homonymes | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| POST | `/v1/audiences/preview` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | inline — `criteria` requis, tableau | aucune sur le CONTENU du tableau | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | 200 `{companies:0,contacts:0,error:"preview_failed"}` en cas d echec — un echec rendu 200 | NON — aucune cle d idempotence | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| DELETE | `/v1/audiences/{audience}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | 200 `{ok:true}` la ou d autres suppressions rendent 204 | sans objet — pas un POST creant | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| GET/HEAD | `/v1/audiences/{audience}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| PUT | `/v1/audiences/{audience}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | inline — `sometimes` sur 5 champs | oui pour la forme | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| GET/HEAD | `/v1/audiences/{audience}/members` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | aucune sur `limit` | oui — `limit` borne a [1,500] | sans objet | NON — plafond `limit`, aucun curseur ni meta | oui — deux jointures, une seule requete | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | **rend `ct.email` des contacts EN CLAIR — aucun masquage** | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| POST | `/v1/audiences/{audience}/refresh` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | 500 `{error:"refresh failed"}` via `ok(...,500)` | oui — recalcul deterministe | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| GET/HEAD | `/v1/audit-logs` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | RLS SEULE — aucun filtre applicatif | non verifie — meme mecanisme que B12-001 (RLS seule, role BYPASSRLS) — fuite presumee, non jouee route par route | sans objet — ni corps ni parametre de requete lu | aucune — `paginate(50)` fixe | sans objet | oui — `paginate(50)` + `meta` | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | rend `ip` et `user_agent` de chaque requete mutative (donnees personnelles de salaries) | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| GET/HEAD | `/v1/audit-logs/verify-chain` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | 200 `{valid:false,degraded:true}` en cas d echec — un echec de verification et une chaine rompue rendent le meme corps | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| POST | `/v1/auth/2fa/confirm` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | inline — `code` requis, `size:6` | oui | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | NON | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | rend les codes de secours (necessaire, une seule fois) | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| POST | `/v1/auth/2fa/setup` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | NON — chaque appel regenere un secret | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | rend le SECRET TOTP et le QR (necessaire a l enrolement) | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| POST | `/v1/auth/2fa/verify` | NON — route publique (voulu) | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — pas de session | sans objet — aucune donnee d espace rendue | inline — `code` requis, chaine | partiel — aucune longueur imposee | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | 422 `ValidationException` sur code invalide | sans objet | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | oui — `throttle:login` | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| POST | `/v1/auth/login` | NON — route publique (voulu) | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — pas de session | sans objet — aucune donnee d espace rendue | oui — `LoginRequest` (le seul FormRequest d authentification) | oui — email/`max:254`, mot de passe `min:12`, `remember` booleen | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | 422 validation / 419 sans session / 429 ; SANS en-tete JSON : **302 vers la racine de l API** (mesure) | sans objet — n ouvre qu une session | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | rend l identite du compte connecte (necessaire) | oui — `throttle:login` | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| POST | `/v1/auth/logout` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | 204 avec un corps `{ok:true}` — un 204 ne doit pas porter de corps | oui — idempotent | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| POST | `/v1/auth/magic-link` | NON — route publique (voulu) | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — pas de session | sans objet — aucune donnee d espace rendue | inline — `email` requis, `email`, `max:254` | oui | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | 200 `{sent:true}` quelle que soit l existence du compte (anti-enumeration, voulu) | sans objet | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | oui — `throttle:magic-link` | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| POST | `/v1/auth/magic-link/verify` | NON — route publique (voulu) | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — pas de session | sans objet — aucune donnee d espace rendue | inline — `token` requis, `size:64` | oui | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | 401 `{error:invalid_or_expired_token}` | oui — jeton a usage unique | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | oui — `throttle:magic-link` | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| GET/HEAD | `/v1/auth/me` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | identite + roles du compte connecte (necessaire) | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| POST | `/v1/auth/onboarding/complete` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | oui — garde `if (onboarding_tour_completed_at === null)` | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| POST | `/v1/auth/password/forgot` | NON — route publique (voulu) | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — pas de session | sans objet — aucune donnee d espace rendue | inline — `email` requis, `email`, `max:254` | oui | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | 200 `{sent:true}` toujours (anti-enumeration) | sans objet — `updateOrInsert` sur l adresse | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | ecrit le lien de reinitialisation EN CLAIR dans le journal quand `MOCK_MODE` (lu par `env()`, hors config) | oui — `throttle:magic-link` | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| POST | `/v1/auth/password/reset` | NON — route publique (voulu) | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — pas de session | sans objet — aucune donnee d espace rendue | inline — email, `token` `size:64`, mot de passe `confirmed` + `Password::min(12)` + `NotPwnedPassword` | oui | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | 401 jeton invalide / **404 `user_not_found`** — un code distinct la ou 401 suffisait | oui — jeton consomme | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | oui — `throttle:magic-link` | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| GET/HEAD | `/v1/campaigns` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | aucune sur `status`/`search`/`per_page` | partiel — bornage a la main dans le controleur | oui — valeurs liees | oui — `paginate($perPage)` | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | oui — `throttle:scraper-list` | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| POST | `/v1/campaigns` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | oui — `StoreScrapingCampaignRequest` (FormRequest) | oui | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | NON — deux appels creent deux campagnes | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | oui — `throttle:scraper-launch` | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| DELETE | `/v1/campaigns/{campaign}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | oui — `throttle:scraper-launch` | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| GET/HEAD | `/v1/campaigns/{campaign}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | sans objet — pas une liste | oui — `limit(20)` sur les runs | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | oui — `throttle:scraper-list` | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| PUT | `/v1/campaigns/{campaign}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | oui — `UpdateScrapingCampaignRequest` (FormRequest) | oui | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | oui — `throttle:scraper-launch` | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| POST | `/v1/campaigns/{campaign}/cancel` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | oui — transition d etat gardee (404/422 hors etat) | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | oui — `throttle:scraper-launch` | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| POST | `/v1/campaigns/{campaign}/pause` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | oui — transition d etat gardee (404/422 hors etat) | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | oui — `throttle:scraper-launch` | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| POST | `/v1/campaigns/{campaign}/resume` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | oui — transition d etat gardee (404/422 hors etat) | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | oui — `throttle:scraper-launch` | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| POST | `/v1/campaigns/{campaign}/start` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | oui — transition d etat gardee (404/422 hors etat) | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | oui — `throttle:scraper-launch` | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| GET/HEAD | `/v1/campaigns/{campaign}/stats` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | sans objet — agregat | oui — `limit(30)` | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | oui — `throttle:scraper-list` | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| GET/HEAD/POST/PUT/PATCH/DELETE/OPTIONS | `/v1/cold-email{any?}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — 501 | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | 501 `{error:not_implemented,message,sprint}` | sans objet | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | FACTICE — 501 « a implementer en Sprint Phase 2 » |
| GET/HEAD | `/v1/companies` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | aucune sur `per_page` ni sur `filter[]` (bornage a la main) | oui — `per_page` borne a [1,100] ; filtres et tris sur listes FERMEES (`CompanyQueryFilters`, 4 tris) | oui — `spatie/query-builder` : colonnes de tri et de filtre en liste fermee, aucune colonne libre | oui — `paginate($perPage)` + `meta` | non mesure sur cette route — aucun `with()`, donc pas de relation chargee | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | email et telephone MASQUES si le compte n a pas `contacts.view_pii` (mesure : masques pour un `viewer`) | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| POST | `/v1/companies` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | inline — `siren` requis `size:9` + `regex:/^d{9}$/`, denomination, source | oui | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | NON — deux appels creent deux fiches du meme SIREN | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| POST | `/v1/companies/bulk-enrich` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | RLS SEULE — aucun filtre applicatif | non verifie — meme mecanisme que B12-001 (RLS seule, role BYPASSRLS) — fuite presumee, non jouee route par route | inline — `ids` requis, tableau, `max:500`, `ids.*` entier | oui pour la forme ; **aucun controle d appartenance** : un identifiant d un autre espace est mis en file tel quel | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | NON — chaque appel remet 500 travaux en file | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| GET/HEAD | `/v1/companies/export` | oui — `auth:sanctum` | partielle — middleware `permission:data.export`, teste (403 mesure) | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | aucune sur les filtres (memes filtres fermes que la liste) | oui — memes listes fermees | oui — memes listes fermees | sans objet — flux CSV ; **AUCUN plafond de lignes** : `chunkById(1000)` deroule tout l espace | oui — `with([contacts, healthPractitioners])` | non verifie — hors budget agent 12 (EXPLAIN non joue) | flux `text/csv` (pas de JSON) — coherent avec les deux autres exports | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | NOMS, EMAILS, TELEPHONES en clair, JAMAIS masques ; opposes exclus (`EligibiliteCampagne`) ; **et la lecture n est PAS auditee** (le journal n enregistre que les ecritures) | oui — `throttle:scraper-list` | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| POST | `/v1/companies/tags/bulk` | oui — `auth:sanctum` | partielle — middleware `permission:companies.update`, teste (403 mesure) | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | inline — `ids` [1,500] entiers, `tag` chaine, `action` `in:add,remove` | oui | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | oui — `insertOrIgnore` sur la cle (company_id, tag_id) | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| DELETE | `/v1/companies/{company}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | RLS SEULE — aucun filtre applicatif | NON — FUITE MESUREE : 200 + fiche de l autre espace (B12-001) | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | 204 ; la documentation annonce un « soft-delete (deleted_at pose) » — c est un **DELETE DEFINITIF** (`Company` n utilise pas `SoftDeletes`) | sans objet | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| GET/HEAD | `/v1/companies/{company}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | RLS SEULE — aucun filtre applicatif | NON — FUITE MESUREE : 200 + fiche de l autre espace (B12-001) | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | sans objet — pas une liste | oui — `load([contacts, tags, healthPractitioners])` | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | **FUITE MESUREE** : email de l entreprise ET emails/telephones des contacts rendus EN CLAIR a un `viewer`, alors que la LISTE les masque (B12-002) | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| PUT | `/v1/companies/{company}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | RLS SEULE — aucun filtre applicatif | NON — FUITE MESUREE : 200 + fiche de l autre espace (B12-001) | inline — priorite (enum), denomination, url, telephone, linkedin | oui — `Rule::in`, `url`, `max:` | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | rend le modele brut mis a jour | sans objet — pas un POST creant | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| POST | `/v1/companies/{company}/enrich` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | RLS SEULE — aucun filtre applicatif | non verifie — meme mecanisme que B12-001 (RLS seule, role BYPASSRLS) — fuite presumee, non jouee route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | oui — `load(contacts)` | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | NON — chaque appel relance la cascade payante | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | rend les contacts en clair, non masques | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| POST | `/v1/companies/{company}/recompute-score` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | RLS SEULE — aucun filtre applicatif | non verifie — meme mecanisme que B12-001 (RLS seule, role BYPASSRLS) — fuite presumee, non jouee route par route | sans objet — ni corps ni parametre de requete lu | sans objet | oui — `DB::statement(..., [$id])` parametre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | oui — recalcul deterministe | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| GET/HEAD | `/v1/config/features` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| GET/HEAD | `/v1/contacts` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | aucune sur `per_page` ni `filter[]` | oui — `per_page` borne a [1,100], filtres/tris en listes fermees | oui — `spatie/query-builder`, 3 tris autorises, tri par defaut `-id` adosse a un index | oui — `paginate` + `meta` + `appends` | oui — `with(company:id,denomination)` | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | email et telephone MASQUES sans `contacts.view_pii` ; projection explicite | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| DELETE | `/v1/contacts/{contact}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | RLS SEULE — aucun filtre applicatif | non verifie — meme mecanisme que B12-001 (RLS seule, role BYPASSRLS) — fuite presumee, non jouee route par route | sans objet — 501 | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | FACTICE — 501 « a implementer en Sprint 5 » |
| GET/HEAD | `/v1/contacts/{contact}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | RLS SEULE — aucun filtre applicatif | non verifie — meme mecanisme que B12-001 (RLS seule, role BYPASSRLS) — fuite presumee, non jouee route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | **rend le MODELE BRUT, sans masquage et sans projection** — toute colonne future sort au fil de l eau | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| PUT | `/v1/contacts/{contact}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | RLS SEULE — aucun filtre applicatif | non verifie — meme mecanisme que B12-001 (RLS seule, role BYPASSRLS) — fuite presumee, non jouee route par route | sans objet — 501 | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | FACTICE — 501 « a implementer en Sprint 5 » |
| GET/HEAD | `/v1/coverage` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | aucune sur `level` (valeur libre, aiguillee par `match`) | partiel — `match` a branche par defaut ; cache 60 s par espace | oui — requetes SQL brutes PARAMETREES (`?`) | NON — liste rendue sans pagination (agregat de cellules) | sans objet — SQL brut agrege | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| GET/HEAD | `/v1/coverage/cells/{cell}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | sans objet — ni corps ni parametre de requete lu | sans objet | oui — parametre lie | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| POST | `/v1/coverage/enrich` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | inline — `validate()` present | oui | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | NON — chaque appel relance un enrichissement payant | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | oui — `throttle:scraper-launch` | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| POST | `/v1/coverage/launch` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | inline — `validate()` present | oui | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | NON — chaque appel lance une collecte payante | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | oui — `throttle:scraper-launch` | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| GET/HEAD | `/v1/coverage/next-zone` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | aucune sur `preferred_dept` | aucune — chaine libre | oui — parametre lie | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| GET/HEAD | `/v1/crm/arbitrage` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | sans objet — ni corps ni parametre de requete lu | oui — `per_page` borne a [1,200] | sans objet | partielle — `limit($perPage)` + `total`, sans curseur ni page suivante | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | rend `pending_match` : nom, email, telephone en attente de rapprochement | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| POST | `/v1/crm/arbitrage/{activityId}/attach` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | inline — `company_id` valide cote controleur | oui — `whereNumber(activityId)` sur la route | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | 409 deja rattache / 404 entreprise hors espace / 200 | oui — `lockForUpdate()` + 409 si `subject_id` deja pose | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| POST | `/v1/crm/arbitrage/{activityId}/dismiss` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | inline — `company_id` valide cote controleur | oui — `whereNumber(activityId)` sur la route | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | 409 deja rattache / 404 entreprise hors espace / 200 | oui — `lockForUpdate()` + 409 si `subject_id` deja pose | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| POST | `/v1/crm/bulk` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | oui — `validate()` inline (action fermee, ids bornes, tag exigeant) | oui — enumerations fermees, tag devant preexister | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{action,matched,updated,skipped,refused_regressions}` | oui — `insertOrIgnore`, et `set_lifecycle` ne recule jamais | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| GET/HEAD | `/v1/crm/candidates` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | oui — `validate()` inline, enumerations fermees (`Taxonomy` candidats) | oui — `per_page` borne a [1,200], `tags` `max:10`, tris fermes | oui — table `SORTS` fermee | oui — `cursorPaginate` + ordre total | oui — `with(tags)` | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | **email et telephone des CANDIDATS rendus EN CLAIR** — `MasquageCoordonnees` n est pas applique dans l univers vivier, alors qu il l est dans l univers business (B12-002) | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| GET/HEAD | `/v1/crm/candidates/counts` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| GET/HEAD | `/v1/crm/contacts-hub` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | oui — `validate()` inline sur 6 parametres, enumerations FERMEES (`Taxonomy`) | oui — `per_page` borne a [1,200], `tags` `max:10`, `q` `max:120`, tris en liste fermee | oui — tri par table `SORTS` fermee + `spatie/query-builder` | oui — `cursorPaginate` + ordre total (`colonne DESC, id DESC`) | oui — `with([contacts,tags])`, projection explicite | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | email et telephone MASQUES sans `contacts.view_pii` | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| GET/HEAD | `/v1/crm/contacts-hub/counts` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | compteurs + `fresh_for_seconds` (fraicheur annoncee) | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| GET/HEAD | `/v1/crm/persons/{personKey}/timeline` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | oui — `person_key` doit etre un sha256 64 hex, sinon 404 | oui | sans objet | NON — `limit` en dur sur activites (constante) et sujets (20) | sans objet — SQL cible | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | rend la chronologie d une personne (objet de la route) ; masquage non applique | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| GET/HEAD | `/v1/dashboard/stats` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | fermeture anonyme declaree DANS `routes/api.php` (ligne 86), hors de tout controleur | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | FACTICE MAIS 200 — tous les compteurs a 0 en dur |
| GET/HEAD | `/v1/journalists` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | RLS SEULE — aucun filtre applicatif | non verifie — meme mecanisme que B12-001 (RLS seule, role BYPASSRLS) — fuite presumee, non jouee route par route | aucune sur `per_page` ni `filter[]` | oui — `per_page` borne a [1,100], filtres/tris fermes | oui — `spatie/query-builder` | oui — `paginate` + `meta` | `allowedIncludes(media)` — chargement a la demande | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | **donnees personnelles de journalistes (nom, email, telephone) rendues EN CLAIR** — aucun masquage | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| GET/HEAD | `/v1/journalists/export` | oui — `auth:sanctum` | partielle — middleware `permission:data.export`, teste (403 mesure) | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | aucune (memes filtres fermes) | oui | sans objet | sans objet — flux CSV, aucun plafond | oui — `with(media)` | non verifie — hors budget agent 12 (EXPLAIN non joue) | flux `text/csv` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | nom, email, telephone ; `opt_out` local + `EligibiliteCampagne` ; lecture non auditee | oui — `throttle:scraper-list` | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| DELETE | `/v1/journalists/{journalist}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | RLS SEULE — aucun filtre applicatif | non verifie — meme mecanisme que B12-001 (RLS seule, role BYPASSRLS) — fuite presumee, non jouee route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | 204 — effacement RGPD par `SoftDeletes` (le modele `Journalist` en porte, contrairement a `Company`) | sans objet — pas un POST creant | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| GET/HEAD | `/v1/journalists/{journalist}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | RLS SEULE — aucun filtre applicatif | non verifie — meme mecanisme que B12-001 (RLS seule, role BYPASSRLS) — fuite presumee, non jouee route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | sans objet — pas une liste | oui — `load(media)` | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | **modele brut, en clair, sans masquage** | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| POST | `/v1/journalists/{journalist}/opt-out` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | RLS SEULE — aucun filtre applicatif | non verifie — meme mecanisme que B12-001 (RLS seule, role BYPASSRLS) — fuite presumee, non jouee route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | oui — pose `opt_out = true`, rejouable | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | rend la fiche complete du journaliste, en clair | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| GET/HEAD/POST/PUT/PATCH/DELETE/OPTIONS | `/v1/linkedin{any?}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — 501 | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | 501 `{error:not_implemented,message,sprint}` | sans objet | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | FACTICE — 501 « a implementer en Sprint Phase 2 » |
| GET/HEAD | `/v1/llm/usage` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | sans objet — reponse vide en dur | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | FACTICE MAIS 200 — `{data:[]}` en dur |
| GET/HEAD | `/v1/llm/usage/summary` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | FACTICE MAIS 200 — `{summary:{total_eur:0}}` en dur |
| GET/HEAD | `/v1/llm/use-cases` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | RLS SEULE — aucun filtre applicatif | non verifie — meme mecanisme que B12-001 (RLS seule, role BYPASSRLS) — fuite presumee, non jouee route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | NON — `limit(50)` en dur, aucune meta | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{data:[…]}` ou `{data:[],degraded:true}` en cas d exception (200 malgre l echec) | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | rend les MODELES BRUTS de configuration (jetons/identifiants de fournisseur si presents en colonne) | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| PUT | `/v1/llm/use-cases/{useCase}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — 501 | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | FACTICE — 501 « a implementer en Sprint 4 » |
| GET/HEAD | `/v1/llm/use-cases/{useCase}/prompts` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | FACTICE MAIS 200 — `{versions:[]}` en dur |
| PUT | `/v1/llm/use-cases/{useCase}/prompts/{v}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — 501 | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | FACTICE — 501 « a implementer en Sprint 4 » |
| GET/HEAD | `/v1/media` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | RLS SEULE — aucun filtre applicatif | non verifie — meme mecanisme que B12-001 (RLS seule, role BYPASSRLS) — fuite presumee, non jouee route par route | aucune sur `per_page` ni `filter[]` | oui — `per_page` borne a [1,100], filtres/tris fermes | oui — `spatie/query-builder` | oui — `paginate` + `meta` | sans objet — aucune relation chargee | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | **email et telephone de redaction rendus EN CLAIR** — aucun `MasquageCoordonnees` sur cette liste, contrairement a `/companies` et `/contacts` | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| GET/HEAD | `/v1/media/export` | oui — `auth:sanctum` | partielle — middleware `permission:data.export`, teste (403 mesure) | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | aucune (memes filtres fermes) | oui | sans objet | sans objet — flux CSV, aucun plafond de lignes | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | flux `text/csv` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | email et telephone en clair ; opposes exclus (`EligibiliteCampagne`) ; lecture non auditee | oui — `throttle:scraper-list` | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| GET/HEAD | `/v1/media/{media}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | RLS SEULE — aucun filtre applicatif | non verifie — meme mecanisme que B12-001 (RLS seule, role BYPASSRLS) — fuite presumee, non jouee route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | sans objet — pas une liste | oui — `load([journalists,parent,children,company])` | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | **modele brut + journalistes rattaches, en clair, sans masquage** | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| GET/HEAD | `/v1/notifications` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | sans objet — reponse vide en dur | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | FACTICE MAIS 200 — `{data:[]}` en dur |
| POST | `/v1/notifications/read-all` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — 501 | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | NON — aucune cle d idempotence | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | FACTICE — 501 « a implementer en Sprint 10 » |
| POST | `/v1/notifications/{n}/read` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — 501 | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | NON — aucune cle d idempotence | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | FACTICE — 501 « a implementer en Sprint 10 » |
| GET/HEAD | `/v1/observability/summary` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | NON — `limit(50)` sur les evenements recents | sans objet — 8 requetes d agregation | non verifie — hors budget agent 12 (EXPLAIN non joue) | `response()->json([data=>…])` — ce controleur etend `Controller`, pas `ApiController` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | deux compteurs (`google_places_quota`, `outbound`) sont GLOBAUX, hors espace — declare comme tel | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| GET/HEAD | `/v1/proxy-providers` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | RLS SEULE — aucun filtre applicatif | non verifie — meme mecanisme que B12-001 (RLS seule, role BYPASSRLS) — fuite presumee, non jouee route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | NON — `limit(50)` en dur, aucune meta | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{data:[…]}` ou `{data:[],degraded:true}` en cas d exception (200 malgre l echec) | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | rend les MODELES BRUTS de configuration (jetons/identifiants de fournisseur si presents en colonne) | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| PUT | `/v1/proxy-providers/{p}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — 501 | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | FACTICE — 501 « a implementer en Sprint 4 » |
| POST | `/v1/proxy-providers/{p}/test` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | 200 `{healthy:true}` INCONDITIONNEL | sans objet | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| GET/HEAD | `/v1/referentiels/geo` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | NON — liste rendue sans pagination — ~120 lignes, cache 1 h | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| GET/HEAD | `/v1/rgpd/export/{token}` | NON — route publique (voulu) | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — pas de session | sans objet — aucune donnee d espace rendue | aucune sur le jeton (aucun format impose) | aucune — un jeton de longueur quelconque est accepte | sans objet | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | 404 `{error:invalid_or_expired_token}` (voulu : jamais 401) | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | REND L EXPORT DE PORTABILITE COMPLET — c est son objet ; possession du jeton (48 car., hache en base, 7 jours) | oui — `throttle:magic-link` | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| GET/HEAD | `/v1/rgpd/requests` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | RLS SEULE — aucun filtre applicatif | non verifie — meme mecanisme que B12-001 (RLS seule, role BYPASSRLS) — fuite presumee, non jouee route par route | aucune sur `status` (valeur libre passee a `where`) | aucune — pas d enumeration fermee | sans objet | oui — `paginate(25)` | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | rend le PAGINATEUR BRUT Laravel (`links`, `from`, `to`…), forme differente du reste de l API | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | rend `subject_email` des demandes (necessaire au traitement) | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| POST | `/v1/rgpd/requests` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | RLS SEULE — aucun filtre applicatif | non verifie — meme mecanisme que B12-001 (RLS seule, role BYPASSRLS) — fuite presumee, non jouee route par route | inline — `type` enum fermee, `subject_email` email, `metadata` array | oui pour type/email ; `metadata` non borne (tableau libre persiste tel quel) | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | NON — deux appels identiques creent deux demandes | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | enregistre l adresse de la personne concernee | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| POST | `/v1/rgpd/requests/{req}/process` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | RLS SEULE — aucun filtre applicatif | non verifie — meme mecanisme que B12-001 (RLS seule, role BYPASSRLS) — fuite presumee, non jouee route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | 409 `{error:already_processed}` / 200 | oui — garde sur `status === done` | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | DECLENCHE UN EFFACEMENT OU UN EXPORT — aucune permission exigee, tout compte authentifie peut le faire | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| GET/HEAD | `/v1/rotations` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | FACTICE MAIS 200 — `{data:[]}` en dur |
| PUT | `/v1/rotations/{rotation}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — 501 | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | FACTICE — 501 « a implementer en Sprint 4 » |
| GET/HEAD | `/v1/saved-views` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | sans objet — reponse vide en dur | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | FACTICE MAIS 200 — `{data:[]}` en dur (GET) — constat A-002 deja ouvert |
| POST | `/v1/saved-views` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — 501 | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | NON — aucune cle d idempotence | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | FACTICE — 501 « a implementer en Sprint 10 » |
| DELETE | `/v1/saved-views/{saved_view}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — 501 | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | FACTICE — 501 « a implementer en Sprint 10 » |
| GET/HEAD | `/v1/saved-views/{saved_view}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — 501 | sans objet | sans objet | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | FACTICE — 501 « a implementer en Sprint 10 » |
| PUT/PATCH | `/v1/saved-views/{saved_view}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — 501 | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | FACTICE — 501 « a implementer en Sprint 10 » |
| GET/HEAD | `/v1/scraper-runs` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | sans objet — ni corps ni parametre de requete lu | aucune — `paginate(25)` fixe, `per_page` non lu | sans objet | oui — `paginate(25)` + `meta` | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{data,meta}` ; modeles bruts | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | oui — `throttle:scraper-list` | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| GET/HEAD | `/v1/scraper-runs/{run}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | 404 (et non 403) sur un run d un autre espace — voulu | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | oui — `throttle:scraper-list` | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| POST | `/v1/scraper-runs/{run}/cancel` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | 422 `{error:invalid_state,message,status}` / 404 / 200 | oui — 422 si deja hors etat annulable | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | oui — `throttle:scraper-launch` | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| POST | `/v1/scraper-runs/{run}/retry` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | 201 avec le MODELE BRUT (`response()->json`, hors `ok()`) | NON — chaque appel cree un nouveau run | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | oui — `throttle:scraper-launch` | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| GET/HEAD | `/v1/search` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | aucune — `q` documente `required, minLength=2`, JAMAIS valide | aucune | sans objet | sans objet — reponse vide en dur | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | FACTICE MAIS 200 — `{companies:[],contacts:[],tags:[]}` en dur |
| GET/HEAD | `/v1/tags` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | aucune sur `category` ni `kind` (valeurs libres passees a `where`) | aucune — pas d enumeration fermee sur les deux filtres | oui — valeurs liees | NON — `limit(500)` en dur, aucune meta | oui — comptage groupe en UNE requete (pas de N+1) | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| POST | `/v1/tags` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | inline — slug `regex:/^[a-z0-9-]+$/`, nom requis, categorie `in:…` | oui | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | 409 `{error:"slug already exists",tag}` — le mot `error` porte une phrase anglaise libre, pas un code | oui de fait — 409 sur slug existant | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| DELETE | `/v1/tags/{tag}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | 200 `{ok:true}` la ou les autres suppressions rendent 204 | sans objet — pas un POST creant | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| PUT | `/v1/tags/{tag}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | inline — `sometimes` sur 4 champs, categorie `in:…` | oui | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | 404 / 403 `{error:"cannot update auto/llm tag"}` — phrases libres | sans objet — pas un POST creant | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | vivante |
| GET/HEAD | `/v1/users` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | NON — `limit(200)` en dur, aucune meta, aucun curseur | sans objet — projection de colonnes explicite | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{data:[…]}`, sans `meta` la ou les autres listes en ont un | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | rend email et nom des collegues (necessaire), non masques | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| POST | `/v1/users` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | oui — `where(workspace_id)` explicite + RLS | oui par construction (filtre applicatif) — non joue route par route | sans objet — 501 | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | NON — aucune cle d idempotence | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | FACTICE — 501 « a implementer en Sprint 3 » |
| DELETE | `/v1/users/{user}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — 501 | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | FACTICE — 501 « a implementer en Sprint 3 » |
| PUT | `/v1/users/{user}` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — 501 | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | AUCUN test ne cite cette route | FACTICE — 501 « a implementer en Sprint 3 » |
| GET/HEAD | `/v1/workspace` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — ni corps ni parametre de requete lu | sans objet | sans objet | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | rend le MODELE `Workspace` brut, sans projection ni resource | sans objet — pas un POST creant | NON — le middleware n audite que POST/PUT/PATCH/DELETE — cette lecture ne laisse aucune trace | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | vivante |
| PUT | `/v1/workspace` | oui — `auth:sanctum` | non — 0 `authorize()`/`Gate::` dans les 42 controleurs | sans objet — la reponse ne lit aucune table | sans objet — aucune donnee d espace rendue | sans objet — 501 | sans objet | sans objet — pas de tri/filtre libre | sans objet — pas une liste | sans objet | non verifie — hors budget agent 12 (EXPLAIN non joue) | `{...}` via `ApiController::ok()` | sans objet — pas un POST creant | oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps) | aucune donnee personnelle rendue | aucune — 0 `throttle`, limiteur `api` declare mais jamais attache | sans objet — route non interne | route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature) | FACTICE — 501 « a implementer en Sprint 3 » |
---

## 5. Constats

### [B12-001] `GET /v1/companies/{company}` rend la fiche d'un autre espace de travail

- Sévérité      : S0 bloquant
- Domaine       : sécurité
- Référence     : `main` mesuré à `1145473`, `backend/routes/` et `backend/app/Http/` **identiques à `c0c453d`** (`git diff --stat` vide)
- Emplacement   : `backend/app/Http/Controllers/Api/CompaniesController.php:334` (`show(Company $company)`), et 20 autres routes listées ci-dessous
- Constat       : la liaison de modèle de route charge l'entreprise par son seul identifiant, sans aucun filtre d'espace de travail, et le compte d'un espace obtient **200 avec l'enregistrement complet** de l'entreprise d'un autre espace.
- Preuve        : `04_PREUVES/agent-12/sondes-etancheite.txt`

  ```
  ### fiche de l'univers B demandee par un compte de A
      GET /api/v1/companies/400039  (compte : console-locale@axion-ia.test)
      -> HTTP 200
      {"id":400039,"workspace_id":"95cbe9b3-…","denomination":"FICHE-UNIVERS-B-AGENT12", …
      VERDICT : FUITE — la fiche de l autre univers est rendue
  ```

  Le compte utilisé appartient à l'espace `20cd81e4-…` (Axion-IA) ; la fiche rendue porte
  `workspace_id = 95cbe9b3-…` (Vivier candidats). Requête jouée à travers le **noyau HTTP réel**
  (mêmes middlewares : `auth:sanctum` → `SetCurrentWorkspace` → `EnforceFirstLoginSetup`), sur
  `axion_crm_a12`, clone jetable de `axion_crm_perf` (`CREATE DATABASE … TEMPLATE`).

  Le mécanisme, mesuré dans `04_PREUVES/agent-12/sondes-verif.txt` :

  ```
  companies : rowsecurity=true force=true
  politique  : companies_workspace_isolation :
               ((workspace_id)::text = NULLIF(current_setting('app.current_workspace_id', true), ''))
  role de connexion : axion
  rolsuper=true  rolbypassrls=true          ← la RLS ne s'applique pas à ce rôle
  crm.strict_workspace_scope = false        ← la ceinture applicative est désarmée
  variable posee = '20cd81e4-…'
  lignes de l'AUTRE univers encore visibles en SQL direct : 1
  VERDICT RLS : la RLS NE FILTRE PAS pour ce role
  ```

  Les deux ceintures existent et sont **toutes les deux à l'arrêt par défaut** :
  `CRM_DB_APP_ROLE_ENABLED=false` (le rôle non-propriétaire `axion_app`, soumis à la RLS, n'est pas
  utilisé) et `CRM_STRICT_WORKSPACE_SCOPE=false` (`WorkspaceScope` est inerte, cf.
  `BelongsToWorkspace`). Il ne reste donc rien.

  ⚠️ **Ce mécanisme n'est pas une découverte de ma part** : l'agent 11 l'a établi côté base
  (`11_GRILLES/agent-11_cloisonnement.md`, **B11-010** — « l'atelier local n'arme aucun des deux
  dispositifs de cloisonnement »). Ce que j'ajoute, et qui n'existe nulle part ailleurs, c'est la
  **conséquence mesurée au niveau HTTP** : une requête authentifiée normale, passée par toute la
  chaîne de middlewares, obtient 200 et la fiche complète d'un autre espace. Le constat de l'agent
  11 dit que la garde de base est désarmée ; celui-ci dit **quelles routes n'ont plus rien du
  tout derrière** — et il y en a 21.
- Témoin négatif: la même requête sur une fiche du **propre** espace rend elle aussi 200
  (`GET /api/v1/companies/100006` → 200) : la sonde sait produire un 200 légitime, donc le 200 sur
  l'autre espace n'est pas un artefact. Et le contrôle **sait** produire un refus quand il existe :
  `GET /v1/scraper-runs/{run}` d'un autre espace rend 404, parce que ce contrôleur-là compare
  explicitement `$run->workspace_id`.
- Impact        : les **21 routes** qui n'ont aucun filtre applicatif rendent, modifient ou
  suppriment les données d'un autre espace de travail à qui connaît un identifiant entier
  séquentiel. La frontière business / vivier candidats — celle qui sépare des prospects
  commerciaux de candidatures à l'emploi, avec deux bases légales différentes — n'est tenue par
  rien. Les routes concernées : `GET|PUT|DELETE /companies/{c}`, `POST /companies/{c}/enrich`,
  `POST /companies/{c}/recompute-score`, `POST /companies/bulk-enrich`,
  `GET|PUT|DELETE /contacts/{c}`, `GET /media`, `GET /media/{m}`, `GET /journalists`,
  `GET|DELETE /journalists/{j}`, `POST /journalists/{j}/opt-out`, `GET|POST /rgpd/requests`,
  `POST /rgpd/requests/{req}/process`, `GET /audit-logs`, `GET /proxy-providers`,
  `GET /llm/use-cases`.
- Reproduction  : `docker exec axion-crm-postgres psql -U axion -d postgres -c "CREATE DATABASE axion_crm_a12 TEMPLATE axion_crm_perf;"` puis jouer `04_PREUVES/agent-12/sonde-etancheite.php` dans le conteneur `axion-crm-api`.
- Correctif     : deux chemins, et il faut les deux. (a) Immédiat, ~2 h : poser dans chacune de ces
  21 méthodes le filtre explicite que `CompaniesController::index` et `export` portent déjà
  (`->where('workspace_id', app('workspace.id'))`, 404 sinon) — c'est du copier du motif existant,
  pas une invention. (b) De fond : basculer `CRM_DB_APP_ROLE_ENABLED=true` pour que la connexion
  passe par `axion_app` et que la RLS morde enfin, ce qui suppose de vérifier que les tâches
  planifiées et les migrations gardent le rôle privilégié — travail de l'agent 11, à coordonner.
  **Ne pas se contenter de (b)** : le dossier commun rappelle qu'une garde qui ne rougit que dans
  une couche n'est pas une garde.
- Statut        : ouvert

### [B12-002] Le masquage des coordonnées couvre trois listes ; la fiche détaillée de la même entreprise livre tout en clair

- Sévérité      : S1 grave
- Domaine       : conformité
- Référence     : `main` `1145473` (périmètre identique à `c0c453d`)
- Emplacement   : `backend/app/Http/Controllers/Api/CompaniesController.php:87` (masque) vs `:334` (ne masque pas) ; `backend/app/Support/MasquageCoordonnees.php`
- Constat       : `MasquageCoordonnees` n'est appelé que dans trois méthodes de liste — `GET /companies`, `GET /contacts`, `GET /crm/contacts-hub` — et nulle part ailleurs sur les 117 routes.
- Preuve        : `04_PREUVES/agent-12/sondes-etancheite.txt`. Même compte `viewer`
  (`contacts.view_pii` = `false`, vérifié), même entreprise, deux requêtes successives :

  ```
  ### LISTE (index) vue par le VIEWER
      GET /api/v1/companies?per_page=1&filter[denomination]=Société froide 1  -> HTTP 200
      email en clair dans la LISTE ? NON (masque)

  ### FICHE (show) vue par le MEME VIEWER
      GET /api/v1/companies/100006                                            -> HTTP 200
      email entreprise en clair dans la FICHE ? OUI
      email du CONTACT en clair dans la FICHE ? OUI
      … "phone":"+33611223344" …
  ```

  `grep -rn "MasquageCoordonnees" backend/app/` rend **13 lignes, dans 3 contrôleurs sur 42**.
- Témoin négatif: la liste **est** masquée pour ce même compte, dans la même seconde, avec les mêmes
  données. Le masquage fonctionne ; il n'est simplement pas posé aux autres portes.
- Impact        : le masquage est une clôture avec un portail ouvert à côté. Un compte en lecture
  seule — celui à qui l'on refuse déjà l'export par `permission:data.export` — récupère
  l'intégralité des adresses et des téléphones en ouvrant les fiches une à une. Les portes
  laissées ouvertes, mesurées ligne à ligne dans la grille : `GET /companies/{c}` (entreprise +
  **tous ses contacts**), `GET /contacts/{c}` (modèle brut), `GET /media` et `GET /media/{m}`,
  `GET /journalists` et `GET /journalists/{j}`, `POST /journalists/{j}/opt-out`,
  `GET /audiences/{a}/members` (`ct.email` en clair), `GET /crm/candidates` — et celle-ci est la
  plus lourde : **les candidats à l'emploi**, base légale `precontractual` ou `consent`, sont les
  seules personnes du système dont les coordonnées sortent en clair alors même que l'univers
  business voisin les masque.
- Reproduction  : jouer `04_PREUVES/agent-12/sonde-etancheite.php`, sections « 14. masquage ».
- Correctif     : ~3 h. Le masquage doit descendre d'un cran : appliqué dans une `JsonResource`
  partagée (ou dans un `casts` de modèle) plutôt que recopié dans chaque méthode de liste. Recopier
  l'appel dans les neuf méthodes manquantes coûterait moins d'une heure mais reproduirait le défaut
  au dixième point de sortie.
- Statut        : ouvert

### [B12-003] Aucune policy n'est jamais appelée : un compte en lecture seule a supprimé définitivement une entreprise

- Sévérité      : S0 bloquant
- Domaine       : sécurité
- Référence     : `main` `1145473` (périmètre identique à `c0c453d`)
- Emplacement   : `backend/app/Http/Controllers/Api/CompaniesController.php:391` (`destroy`), `backend/routes/api.php:125`, `backend/app/Policies/` (11 fichiers), `backend/app/Providers/AuthServiceProvider.php`
- Constat       : `grep -rn "authorize(\|Gate::allows\|Gate::denies\|->can("` sur `backend/app/Http/Controllers/` rend **zéro occurrence** ; les 11 policies enregistrées ne sont invoquées nulle part, et un compte de rôle `viewer` a obtenu **204** puis la ligne a disparu de la base.
- Preuve        : `04_PREUVES/agent-12/sondes-etancheite.txt` et `sondes-verif.txt`

  ```
  roles viewer : viewer
  viewer a-t-il companies.update ? false

  ### DELETE par le VIEWER
      DELETE /api/v1/companies/400005  (compte : viewer-agent12@exemple.test)
      -> HTTP 204
  ```
  ```
  ligne 400005 en base : DISPARUE — suppression DEFINITIVE
  le modele Company utilise-t-il SoftDeletes ? NON
  ```

  Deux défauts se superposent : (a) la route ne porte **ni** `permission:` **ni** `authorize()`,
  alors que `BasePolicy::delete()` dit explicitement « owner + admin seulement » ; (b) le bloc de
  documentation de la méthode annonce « **Soft-delete une entreprise (deleted_at posé)** » et
  `CompaniesController::index` filtre bien sur `whereNull('deleted_at')` — mais `Company` n'utilise
  **pas** le trait `SoftDeletes`. `$company->delete()` est un `DELETE` SQL. La fiche ne revient pas.
- Témoin négatif: la même sonde, sur le même compte `viewer`, obtient **403** sur
  `GET /v1/companies/export`, qui porte `permission:data.export`. La chaîne d'autorisation
  fonctionne parfaitement — elle est posée sur 4 routes sur 117.
- Impact        : perte de données irréversible déclenchable par le rôle le moins privilégié du
  produit. Le même raisonnement vaut pour `PUT /companies/{c}`, `POST /companies/{c}/enrich`
  (dépense d'API payante), `POST /companies/bulk-enrich` (500 travaux), `DELETE /journalists/{j}`,
  `POST /rgpd/requests/{req}/process` (effacement RGPD), `PUT|DELETE /tags/{t}` — aucune n'exige
  autre chose qu'une session valide.
- Reproduction  : `04_PREUVES/agent-12/sonde-etancheite.php`, section « 2. autorisation ».
- Correctif     : ~1 jour. (a) Ajouter `SoftDeletes` sur `Company` — la colonne `deleted_at` existe
  déjà et toutes les requêtes la filtrent déjà, c'est une ligne. (b) Poser `permission:` sur les
  routes d'écriture, ou brancher les policies par `$this->authorize()`. **Attention** : brancher les
  policies en l'état armerait B12-012 (ci-dessous) — le corriger d'abord.
- Statut        : ouvert

### [B12-004] `POST /internal/scraper-result` accepte une signature forgée : le HMAC est réimplémenté sans garde de secret vide

- Sévérité      : S0 bloquant
- Domaine       : sécurité
- Référence     : `main` `1145473` (périmètre identique à `c0c453d`)
- Emplacement   : `backend/app/Http/Controllers/Internal/ScraperResultController.php:36-45` ; `.env:166`, `backend/.env:180`, **`.env.example:238`**
- Constat       : le contrôleur calcule `hash_hmac('sha256', $body, env('WORKER_INTERNAL_HMAC_SECRET', ''))` sans vérifier que le secret est non vide, et ce secret est vide dans les trois fichiers d'environnement du dépôt, y compris le modèle livré.
- Preuve        : `04_PREUVES/agent-12/sondes-internal-a.txt`

  ```
  WORKER_INTERNAL_HMAC_SECRET  = ''
      corps     = {"run_id":424242,"source":"audit-agent-12","status":"ok"}
      secret    = ''
      signature = 5c8e748281d8ccf3aa57ad9a991db73b5993ee8d64151cde35679ee290021e0b

  ### scraper-result — signature FORGEE avec ce secret
      POST /api/internal/scraper-result   -> HTTP 200
      {"ingested":true}
  ```
- Témoin négatif: **triple**. (1) Une signature bidon rend 401 `bad_signature`. (2) Une requête sans
  en-tête de signature rend 401. (3) **Le même corps altéré d'un caractère, avec la même signature,
  rend 401** — la vérification cryptographique fonctionne, elle est simplement clefée avec une
  valeur publique. Et le témoin de contraste est dans le même fichier : les trois autres routes
  internes, qui utilisent `App\Support\HmacSignature::verify()`, **refusent** la même attaque
  (401 mesuré) grâce à sa première ligne : `if ($secret === '' || …) return false;`.
- Impact        : quiconque atteint `POST /api/internal/scraper-result` peut se faire passer pour un
  collecteur. Aujourd'hui, avec `CRM_SCRAPE_FUNNEL_ENABLED=false`, l'effet se limite à polluer le
  journal ; avec le drapeau ouvert, c'est **l'injection arbitraire de fiches et de contacts dans le
  CRM par un tiers**. C'est aussi le patron qui a été copié pour `/site-sync` : deux
  implémentations du même contrôle, dont une seule a la garde — le piège n° 15 du dossier commun,
  pris en flagrant délit.
- Reproduction  : jouer `04_PREUVES/agent-12/sonde-internal-a.php` dans `axion-crm-api`.
- Correctif     : ~30 min. Remplacer les dix lignes inline par `HmacSignature::verify($secret,
  $body, $r->header('X-Worker-Signature'))`, et faire lire le secret par `config()` et non par
  `env()` (un `config:cache` rendrait `env()` nul, donc le secret vide, sans le moindre signal).
  Puis générer et poser un secret réel — le laisser vide dans `.env.example` est ce qui rend le
  défaut réplicable à chaque nouveau déploiement.
- Statut        : ouvert

### [B12-005] La même route interne n'a ni limitation de débit, ni fenêtre de rejeu

- Sévérité      : S2 défaut
- Domaine       : sécurité
- Référence     : `main` `1145473`
- Emplacement   : `backend/routes/api.php:307`
- Constat       : `POST /internal/scraper-result` est la seule des quatre routes internes à ne porter **aucun** `throttle`, et sa signature ne couvre que le corps — ni horodatage, ni nonce.
- Preuve        : `04_PREUVES/agent-12/sondes-internal-a.txt` (rejeu) et `sondes-debit.txt` (débit)

  ```
  ### scraper-result — signature FORGEE avec ce secret   -> HTTP 200
  ### scraper-result — REJEU identique #2                -> HTTP 200
  ### scraper-result — REJEU identique #3                -> HTTP 200
  ```
  ```
  POST /api/internal/scraper-result  x9
      codes : 401 401 401 401 401 401 401 401 401
      un 429 apparait ? NON
  ```
- Témoin négatif: la même rafale de 9 appels sur `POST /api/v1/auth/login`, qui porte
  `throttle:login` (5/min), rend `422 422 422 422 422 **429 429 429 429**` — la sonde voit bien un
  429 quand il y en a un. Et le contraste sur le rejeu : `/site-sync`, qui signe
  `<horodatage>.<corps>` et vérifie une fenêtre de 300 s, refuse un horodatage à t−4000 s.
- Impact        : une requête légitime interceptée reste rejouable indéfiniment, et rien ne borne la
  cadence — ni pour le rejeu, ni pour la recherche de signature. Le commentaire de
  `HmacSignature` explique précisément pourquoi l'horodatage a été ajouté ; la route modèle ne l'a
  jamais reçu.
- Reproduction  : `04_PREUVES/agent-12/sonde-debit.php` et `sonde-internal-a.php`.
- Correctif     : ~1 h. Ajouter `->middleware('throttle:internal')` (une ligne, cohérente avec les
  trois voisines) et migrer la route sur `HmacSignature::signedPayload()` en même temps que
  B12-004 — les deux corrections touchent les mêmes dix lignes.
- Statut        : ouvert

### [B12-006] `GET /search` est déclaré deux fois ; la première déclaration est morte et rien ne le dit

- Sévérité      : S2 défaut
- Domaine       : backend
- Référence     : `main` `1145473`
- Emplacement   : `backend/routes/api.php:99` (fermeture anonyme) et `backend/routes/api.php:207` (`GlobalSearchController@index`)
- Constat       : deux `Route::get('/search', …)` sont déclarés dans le même groupe ; le routeur ne retient que le second, et le premier n'est jamais atteint.
- Preuve        : `04_PREUVES/agent-12/sondes-internal-a.txt`

  ```
  ----- routage : /search declare deux fois ? ------------------------
      entree #1 : GET|HEAD -> App\Http\Controllers\Api\GlobalSearchController@index
      total d entrees 'api/v1/search' RETENUES par le routeur : 1
  ```

  Et l'arithmétique le confirme : 114 déclarations dans le fichier, 4 sur `/internal`, 110 sur
  `/v1` dont une `apiResource` qui en produit 5 → 114 attendues, **113 enregistrées**. L'écart
  d'exactement une route est cette collision.
- Témoin négatif: la même énumération trouve bien **5** entrées pour `api/v1/saved-views*` (soit
  l'`apiResource` au complet) : le contrôle sait compter plusieurs entrées quand elles existent.
- Impact        : faible aujourd'hui — les deux déclarations rendent le **même** corps vide codé en
  dur, donc la collision est invisible. Élevé demain : quiconque implémentera la recherche dans la
  fermeture de la ligne 99 verra son code ne jamais s'exécuter, sans erreur, sans avertissement, et
  cherchera la cause ailleurs. C'est le motif du défaut déjà vécu sur `ContactsController::index`.
- Reproduction  : `docker exec axion-crm-api php artisan route:list --path=api | grep "v1/search"` → une seule ligne, pour deux déclarations.
- Correctif     : 5 min — supprimer les lignes 99-105 de `routes/api.php`. Et, pour que cela ne
  revienne pas : un test qui compare le nombre de `Route::verb(` du fichier au nombre d'entrées de
  `route:list` (~20 lignes) rougirait sur toute future collision.
- Statut        : ouvert

### [B12-007] Neuf routes répondent 200 avec un corps codé en dur, et un contrôle de santé répond toujours « en bonne santé »

- Sévérité      : S1 grave
- Domaine       : backend
- Référence     : `main` `1145473`
- Emplacement   : `GlobalSearchController.php:18`, `routes/api.php:86`, `AiActRegisterController.php:15`, `LlmUsageController.php:15,22`, `RotationsController.php:15`, `NotificationsController.php:15`, `SavedViewsController.php:15`, `LlmUseCasesController.php:47`, `ProxyProvidersController.php:47`
- Constat       : dix routes rendent un corps figé dans le code, avec un code 200 qui les rend indiscernables d'une fonctionnalité qui marche.
- Preuve        : lecture du code — chaque méthode tient sur une ligne :

  | Route | Corps rendu | Ce que l'écran comprend |
  |---|---|---|
  | `GET /v1/search` | `{companies:[],contacts:[],tags:[]}` | « aucun résultat » |
  | `GET /v1/dashboard/stats` | tous les compteurs à `0` | « la base est vide » |
  | `GET /v1/ai-act/register` | `{data:[]}` | « aucun système IA déclaré » (registre AI Act) |
  | `GET /v1/llm/usage` | `{data:[]}` | « aucun appel LLM » |
  | `GET /v1/llm/usage/summary` | `{summary:{total_eur:0}}` | « 0 € dépensé » |
  | `GET /v1/rotations` | `{data:[]}` | « aucune rotation » |
  | `GET /v1/notifications` | `{data:[]}` | « aucune notification » |
  | `GET /v1/saved-views` | `{data:[]}` | constat **A-002**, déjà ouvert |
  | `GET /v1/llm/use-cases/{u}/prompts` | `{versions:[]}` | « aucune version de prompt » |
  | `POST /v1/proxy-providers/{p}/test` | **`{healthy:true}`** | « ce mandataire fonctionne » |

- Témoin négatif: les routes voisines du même contrôleur, elles, interrogent bien la base —
  `LlmUseCasesController::index` fait `LlmUseCase::query()->…->get()`. La différence n'est donc pas
  une convention du projet, c'est un manque.
- Impact        : A-002 a été ouvert sur une seule de ces routes ; il y en a dix. La plus grave
  n'est pas une liste vide mais `POST /proxy-providers/{p}/test` : un contrôle de santé qui répond
  `healthy: true` sans rien contacter fait passer une panne de mandataire pour un fonctionnement
  normal — c'est la leçon « un job vert qui ne relaie rien » que le dépôt s'applique déjà ailleurs
  (`ScraperResultController`, en-tête). Vient ensuite `GET /ai-act/register` : un registre de
  conformité qui affirme « rien à déclarer » est une déclaration, pas une absence.
- Reproduction  : `cat backend/app/Http/Controllers/Api/{GlobalSearch,LlmUsage,Rotations,Notifications,SavedViews}Controller.php`
- Correctif     : ~30 min pour la moitié utile : ces dix méthodes doivent rendre **501** comme leurs
  voisines d'écriture, via le `notImplemented()` qui existe déjà dans `ApiController`. Un 501 se
  voit ; un 200 vide se confond avec la vérité. L'implémentation réelle, elle, relève des sprints
  annoncés.
- Statut        : ouvert

### [B12-008] 88 routes sur 117 n'ont aucune limitation de débit, et le limiteur global déclaré n'est attaché à rien

- Sévérité      : S2 défaut
- Domaine       : sécurité
- Référence     : `main` `1145473`
- Emplacement   : `backend/app/Providers/RouteServiceProvider.php:19-24`, `backend/bootstrap/app.php:25-29`
- Constat       : `RateLimiter::for('api', … 60/min …)` est défini, mais aucune route ne porte `throttle:api` — le groupe de middlewares `api` construit dans `bootstrap/app.php` ne l'ajoute pas.
- Preuve        : `04_PREUVES/agent-12/middleware-par-route.txt`

  ```
  grep -c "throttle:"     middleware-par-route.txt  → 29
  grep -c "throttle:api"  middleware-par-route.txt  → 0
  ```
  et `04_PREUVES/agent-12/sondes-debit.txt` :
  ```
  GET /api/v1/companies  x9
      codes : 401 401 401 401 401 401 401 401 401
      un 429 apparait ? NON
  ```
- Témoin négatif: la même rafale sur `POST /api/v1/auth/login` (`throttle:login`, 5/min) rend
  `422 ×5` puis `429 ×4`. La sonde voit les 429 quand il y en a.
- Impact        : 88 routes acceptent une cadence illimitée par compte et par adresse — dont
  `GET /companies` sur 4,29 M de fiches, `GET /crm/contacts-hub`, `GET /audit-logs`, et toutes les
  écritures non protégées. Le budget de performance du projet n'a aucun garde-fou côté entrée.
  Le limiteur est écrit, testé nulle part, attaché nulle part : c'est une garde qui mesure le vide.
- Reproduction  : `04_PREUVES/agent-12/sonde-debit.php`.
- Correctif     : 15 min — ajouter `'throttle:api'` au groupe `api` dans `bootstrap/app.php`, avec
  une valeur assez large pour ne rien casser (`RATE_LIMIT_PER_MINUTE` existe déjà). Mesurer ensuite
  qu'aucune page de la console ne dépasse ce plafond en usage normal — la console v2 enchaîne
  plusieurs appels par écran.
- Statut        : ouvert

### [B12-009] `POST /v1/auth/login` sans en-tête JSON répond 302 vers la racine de l'API

- Sévérité      : S2 défaut
- Domaine       : interface
- Référence     : `main` `1145473`
- Emplacement   : `backend/app/Http/Requests/Auth/LoginRequest.php` (comportement par défaut de `FormRequest`)
- Constat       : un envoi de formulaire d'authentification sans `Accept: application/json` ne reçoit pas 422 mais une **redirection 302 vers `http://api.localhost`** — le comportement « retour au formulaire » de Laravel appliqué à une API qui n'a pas de formulaire.
- Preuve        : `04_PREUVES/agent-12/sondes-a001.txt`

  ```
  ### PUBLIQUE  POST /api/v1/auth/login (html)
      POST /api/v1/auth/login
      -> HTTP 302 Location: http://api.localhost
  ```
- Témoin négatif: le même appel avec `Accept: application/json` rend bien **422** avec le corps de
  validation attendu (`sondes-debit.txt`, rafale de connexion).
- Impact        : plus modeste que A-001 mais de la même famille — l'API a deux comportements selon
  un en-tête, et le second n'a aucun sens pour un client machine. Un client mobile, un test manuel,
  un `curl` d'appui reçoivent une redirection au lieu d'une erreur de validation, et le développeur
  conclut à un problème de routage.
- Reproduction  : `curl -i -X POST http://localhost:58080/api/v1/auth/login -d '{}'`
- Correctif     : 20 min — surcharger `failedValidation()` dans `LoginRequest` pour lever une
  `HttpResponseException` JSON, ou déclarer `expectsJson` sur le groupe `api`. Le même geste
  refermerait A-001, qui a exactement la même cause racine.
- Statut        : ouvert

### [B12-010] Les trois exports CSV de données nominatives ne laissent aucune trace dans le journal d'audit

- Sévérité      : S1 grave
- Domaine       : conformité
- Référence     : `main` `1145473`
- Emplacement   : `backend/app/Http/Middleware/AuditHashChainLogger.php:23`
- Constat       : le middleware d'audit filtre `if (! in_array($request->method(), ['POST','PUT','PATCH','DELETE'], true)) return $response;` — **les `GET` ne sont jamais journalisés**, y compris `GET /companies/export`, `GET /media/export`, `GET /journalists/export`.
- Preuve        : lecture du middleware (une ligne, citée ci-dessus) ; et la colonne 13 de la grille
  compte **53 routes de lecture hors journal**. Aucun des trois contrôleurs d'export n'écrit non
  plus d'entrée d'audit de sa propre initiative : `grep -rni "audit" backend/app/Http/Controllers/`
  ne rend que `AuditLogsController` et deux commentaires.
- Témoin négatif: le journal fonctionne — il enregistre bien méthode, chemin, statut, adresse IP et
  empreinte du corps pour toute écriture, et `GET /audit-logs` les rend. Le contrôle sait écrire ;
  il n'est pas armé sur les lectures.
- Impact        : l'opération la plus lourde du produit — l'extraction hors du système de plusieurs
  millions de fiches nominatives, avec noms, adresses électroniques et téléphones — est la seule
  qui ne laisse rien derrière elle. En cas de fuite de données, il est impossible de dire qui a
  exporté quoi et quand. Le RGPD n'impose pas nommément un journal de lecture, mais l'article 32 et
  l'obligation de rendre compte s'accommodent mal d'une exfiltration légitime intraçable — et le
  registre des violations rédigé le 19/08 par ailleurs dans ce dépôt se serait appuyé dessus.
  Deuxième conséquence : l'export n'a **aucun plafond de lignes** (`chunkById(1000)` déroule tout
  l'espace de travail) ; le dossier commun signale un « plafond réel 5 000, silencieux, à
  re-vérifier » — **je ne l'ai trouvé nulle part dans les trois contrôleurs d'export**.
- Reproduction  : `sed -n '19,30p' backend/app/Http/Middleware/AuditHashChainLogger.php`
- Correctif     : ~2 h. Journaliser explicitement les trois exports dans le contrôleur (nombre de
  lignes sorties, filtres appliqués, empreinte de la requête) plutôt que d'étendre le middleware à
  tous les `GET`, ce qui noierait le journal. Décider en même temps d'un plafond de lignes et le
  rendre visible dans la réponse — un export tronqué en silence est le défaut suivant.
- Statut        : ouvert

### [B12-011] 42 routes sur 117 ne sont citées par aucun test, dont tout le parcours d'authentification secondaire et les huit routes d'audiences

- Sévérité      : S2 défaut
- Domaine       : tests
- Référence     : `main` `1145473`
- Emplacement   : `backend/tests/` (100 fichiers)
- Constat       : en extrayant toutes les URI littérales citées par les 100 fichiers de test et en les rapprochant des 117 routes enregistrées, **42 routes ne sont mentionnées nulle part** — pas même pour vérifier qu'elles répondent 200.
- Preuve        : `04_PREUVES/agent-12/couverture-tests.txt` (et le script `couverture.py` qui le produit)

  ```
  ROUTES v1+internal : 117
  CITEES au moins une fois par un fichier de test : 75
  JAMAIS citees par aucun test                    : 42
  ```

  Parmi les 42 : **les 8 routes `/v1/audiences`** (création, aperçu, rafraîchissement, membres),
  **`POST /v1/auth/password/forgot` et `/reset`**, **`POST /v1/auth/magic-link` et `/verify`**,
  **les trois routes 2FA**, `POST /v1/auth/logout`, `GET /v1/media` et `GET /v1/media/{m}`,
  `GET /v1/journalists`, `GET|DELETE /v1/journalists/{j}`, `GET|PUT|DELETE /v1/contacts/{c}`,
  `POST /v1/companies/{c}/enrich`, `PUT|DELETE /v1/tags/{t}`,
  et **`POST /v1/rgpd/requests/{req}/process`** — l'effacement RGPD.
- Témoin négatif: la même extraction trouve bien 71 routes citées, dont des URI construites par
  interpolation PHP (`/api/v1/campaigns/{$c->id}/start`) : le contrôle sait reconnaître une route
  citée, y compris sous forme dynamique, et il ne se laisse pas abuser par une URI statique qui
  ressemble à un paramètre (`/media/export` ne compte pas comme une citation de `/media/{media}` —
  ce sont deux routes, et le fichier de routes signale lui-même le piège). Une route absente de la
  liste l'est vraiment.
- Impact        : « citée » n'est d'ailleurs pas « testée » — plusieurs des 71 ne sont vérifiées que
  sur le code 200, ce que le dépôt s'est déjà fait prendre à faire (`ContactsIndexTest`,
  commentaire en tête de `ContactsController`). Le point aveugle qui compte le plus est la
  réinitialisation de mot de passe et le lien magique : deux chemins d'authentification complets,
  manipulant des jetons, sans un seul test.
- Reproduction  : `python 04_PREUVES/agent-12/couverture.py`
- Correctif     : le chiffrer route par route n'aurait pas de sens ici. Priorité proposée, ~2 jours :
  (1) les 4 routes de mot de passe / lien magique ; (2) `POST /rgpd/requests/{req}/process` ;
  (3) les 8 routes d'audiences. Le reste peut attendre une reprise fonctionnelle.
- Statut        : ouvert

### [B12-012] `BasePolicy::sameWorkspace()` compare deux UUID castés en entier — le défaut de `channels.php` corrigé le 2026-08-16, jamais propagé

- Sévérité      : S1 grave (dormant aujourd'hui, armé au premier `authorize()`)
- Domaine       : sécurité
- Référence     : `main` `1145473`
- Emplacement   : `backend/app/Policies/BasePolicy.php:25`
- Constat       : la méthode qui décide de l'appartenance à un espace de travail s'écrit
  `return (int) $user->current_workspace_id === (int) $model->workspace_id;` alors que les deux
  colonnes sont de type `uuid` en Postgres.
- Preuve        : le code, et le type des colonnes mesuré en base :

  ```
  docker exec axion-crm-postgres psql -U axion -d axion_crm \
    -c "SELECT table_name, column_name, data_type FROM information_schema.columns
        WHERE column_name IN ('workspace_id','current_workspace_id')"
  → companies | workspace_id | uuid      (et 40+ tables identiques)
  ```

  `(int) 'a1b2c3d4-…'` vaut `0` en PHP, **des deux côtés** : l'expression est `0 === 0`, donc
  toujours vraie. C'est mot pour mot le défaut décrit en tête de `backend/routes/channels.php`,
  corrigé le 2026-08-16 par `hash_equals((string) …, …)`, avec cet avertissement explicite dans le
  fichier : « ⚠️ NE PAS retyper ces paramètres en `int`. Ce sont des UUID, ils se comparent en
  CHAÎNES. » La correction n'a pas été cherchée ailleurs.
- Témoin négatif: la correction voisine **est** correcte — `channels.php:49` et `:53` utilisent bien
  `hash_equals` sur des chaînes. Le contrôle « ce dépôt sait comparer des UUID » est positif ; c'est
  bien un oubli de propagation, pas une convention.
- Impact        : nul **aujourd'hui**, parce qu'aucun contrôleur n'appelle `authorize()` (B12-003) —
  et c'est précisément ce qui rend le défaut vicieux. Le correctif naturel de B12-003 (« branchons
  les policies, elles existent ») transformerait `view`, `update` et `delete` en autorisations qui
  disent oui à tout le monde, sur tous les espaces de travail, sans qu'aucune erreur ne soit levée.
  Le dossier commun demande de chercher les autres occurrences de ce patron : en voici une.
- Reproduction  : `sed -n '20,26p' backend/app/Policies/BasePolicy.php`
- Correctif     : 10 min — `return hash_equals((string) $user->current_workspace_id, (string) $model->workspace_id);`
  et un test qui rougit avec deux UUID différents (ce que le test actuel ne peut pas faire : il n'y
  en a aucun sur les policies).
- Statut        : ouvert

### [B12-013] `POST /v1/companies/bulk-enrich` met en file 500 identifiants sans vérifier qu'ils appartiennent à l'espace de travail

- Sévérité      : S2 défaut
- Domaine       : sécurité
- Référence     : `main` `1145473`
- Emplacement   : `backend/app/Http/Controllers/Api/CompaniesController.php:433-441`
- Constat       : la validation borne la **forme** (`ids` tableau, `max:500`, entiers) et rien d'autre ; chaque identifiant part directement en `EnrichCompanyJob::dispatch((int) $id)`.
- Preuve        : le code, cinq lignes :

  ```php
  $ids = $r->validate(['ids' => 'required|array|max:500', 'ids.*' => 'integer'])['ids'];
  foreach ($ids as $id) { EnrichCompanyJob::dispatch((int) $id); }
  ```
- Témoin négatif: le contrôleur voisin `CompanyTagsBulkController` fait exactement ce qu'il faut, et
  le documente : il recharge les identifiants **filtrés par `workspace_id`** avant d'agir, et rend
  le nombre d'ignorées. Le motif juste existe dans le même dépôt, à quatre fichiers de distance.
- Impact        : un compte d'un espace fait enrichir — donc modifier, et facturer — les fiches d'un
  autre espace. Combiné à B12-001, il n'y a même pas besoin de deviner : les identifiants sont des
  entiers séquentiels visibles.
- Reproduction  : `sed -n '433,441p' backend/app/Http/Controllers/Api/CompaniesController.php`
- Correctif     : 20 min — recopier le filtre de `CompanyTagsBulkController` (`whereIn('id', …)
  ->where('workspace_id', …)->pluck('id')`) et rendre le nombre d'identifiants écartés.
- Statut        : ouvert

### [B12-014] Trois gardes d'espace de travail sont « ouvertes en cas de doute »

- Sévérité      : S2 défaut
- Domaine       : sécurité
- Référence     : `main` `1145473`
- Emplacement   : `ScraperRunsController.php:175-182`, `AudiencesController.php:151-157`, `TagsController.php:306` et `:335`
- Constat       : trois gardes d'appartenance laissent passer quand le contexte d'espace n'est pas résolu, au lieu de refuser.

  ```php
  // ScraperRunsController — commentaire d'origine : « Tolérant … : ne bloque pas »
  if ($workspaceId === null) { return true; }
  // AudiencesController / TagsController
  if ($workspaceId && $audience->workspace_id !== $workspaceId) { abort(404); }
  ```
- Preuve        : lecture du code aux quatre emplacements ci-dessus.
- Témoin négatif: les gardes correctes existent dans le même dépôt et ferment par défaut —
  `CompaniesController::index` rend une liste vide quand `workspace.id` est absent, et
  `MasquageCoordonnees::requis()` documente explicitement « Ferme par DÉFAUT : sans utilisateur ou
  sans droit, on masque. Une garde dont l'état inconnu vaut “montre tout” n'est pas une garde. »
  La règle est écrite dans le produit ; trois gardes ne la suivent pas.
- Impact        : un compte dont `current_workspace_id` est nul — c'est le cas d'un compte
  fraîchement créé, ou d'un compte dont le workspace a été supprimé — accède à **tous** les
  scraper runs, **toutes** les audiences et **tous** les tags, tous espaces confondus. Le cas est
  rare, ce qui le rend d'autant moins susceptible d'être découvert autrement que par un incident.
- Reproduction  : `sed -n '171,196p' backend/app/Http/Controllers/Api/ScraperRunsController.php`
- Correctif     : 30 min — inverser les trois gardes (`if ($workspaceId === null) { abort(403); }`),
  et un test par garde avec un compte sans espace courant.
- Statut        : ouvert

### [B12-015] Seize routes lisent une entrée sans jamais la valider, et sept formes de réponse d'erreur coexistent

- Sévérité      : S2 défaut
- Domaine       : backend
- Référence     : `main` `1145473`
- Emplacement   : voir le tableau du §1.2 ; formes de réponse : `TagsController.php:280,311`, `AudiencesController.php:113,125`, `RgpdRequestsController.php:201`, `ScraperRunsController.php:164`, `ObservabilityController.php:223`
- Constat       : (a) seize routes lisent `per_page`, `filter[]`, `status`, `category`, `kind`, `level`, `q`, `limit`, `preferred_dept` ou un jeton sans `validate()` ni FormRequest ; (b) l'API rend des erreurs sous au moins sept formes différentes.
- Preuve        : décompte du §1.2, fait sur la colonne 5 du tableau (4 FormRequest, 23 routes en
  `validate()` inline, 3 contrats internes écrits à la main, **16 sans rien**, 19 stubs, 52 sans
  entrée) et relevé des formes :

  | Forme | Où |
  |---|---|
  | `{"message":"…","errors":{…}}` | `validate()` standard, 22 routes |
  | `{"error":"code_machine"}` | `bad_signature`, `invalid_or_expired_token`, … |
  | `{"error":"code","message":"phrase"}` | routes internes, `invalid_state` |
  | `{"error":"slug already exists","tag":{…}}` | `POST /tags` — le champ `error` porte une **phrase anglaise libre** |
  | `{"error":"cannot update auto/llm tag"}` | `PUT /tags/{t}` — idem |
  | paginateur Laravel brut (`links`, `from`, `to`) | `GET /rgpd/requests` |
  | `{"data":…}` sans `ok()` | `GET /observability/summary` (ce contrôleur étend `Controller`, pas `ApiController`) |

  Et trois codes de succès pour la même intention : `DELETE /companies/{c}` rend **204**,
  `DELETE /tags/{t}` rend **200 `{ok:true}`**, `DELETE /audiences/{a}` rend **200 `{ok:true}`**.
  `POST /auth/logout` rend un **204 avec un corps** — ce qu'un 204 ne doit pas porter.
- Témoin négatif: la console v2 (`ContactsHubController`, `CandidatesController`, `BulkController`)
  valide systématiquement, avec des énumérations fermées tirées de `Taxonomy`, et rend une forme
  unique. Le projet sait faire ; les couches plus anciennes n'ont pas été reprises.
- Impact        : une valeur aberrante dans l'URL ne rend jamais 422, elle rend une liste vide — le
  défaut exact que le dépôt a déjà payé sur `ContactsController::index`. Côté frontend, sept formes
  d'erreur signifient sept branches de traitement, ou une seule qui en ignore six.
- Reproduction  : `sed -n '205,225p' backend/app/Http/Controllers/Api/TagsController.php` — la valeur
  de `category` part telle quelle dans `where('category', $category)` ; aucune énumération, donc
  aucun 422 possible, seulement une liste vide.
- Correctif     : ~1,5 jour pour les seize routes (un FormRequest de liste réutilisable, avec
  `per_page` borné et les énumérations fermées), plus ~2 h pour unifier les formes d'erreur sur
  `ApiController`. Faible risque, gain immédiat de lisibilité.
- Statut        : ouvert

### [B12-016] `POST /v1/rgpd/requests/{req}/process` déclenche un effacement définitif sans aucune permission ni aucun test

- Sévérité      : S1 grave
- Domaine       : conformité
- Référence     : `main` `1145473`
- Emplacement   : `backend/app/Http/Controllers/Api/RgpdRequestsController.php:252-281`, `backend/routes/api.php:215`
- Constat       : la route qui exécute les articles 15 et 17 ne porte que `auth:sanctum`, `workspace`, `first-login` ; elle n'appelle aucune policy, ne vérifie pas l'espace de travail de la demande liée, et aucun test ne la cite.
- Preuve        : `04_PREUVES/agent-12/middleware-par-route.txt` (aucune entrée `permission:`),
  `couverture-tests.txt` (route dans la liste « JAMAIS citées »), et le code : `process(Request $r,
  RgpdRequest $req)` agit sur `$req` sans le moindre `where('workspace_id', …)`.
- Témoin négatif: les trois exports de données, bien moins destructifs, portent `permission:data.export`
  et un compte `viewer` y prend un **403** mesuré. Le produit sait poser une permission sur une
  route sensible ; celle-ci n'en a pas.
- Impact        : n'importe quel compte authentifié, y compris en lecture seule, peut faire exécuter
  un effacement RGPD — `GdprErasureService::erase($req->subject_email)` — sur une adresse portée par
  une demande d'un **autre** espace de travail (mécanisme B12-001). L'effacement est propagé au site
  par la file sortante. Il n'existe aucun test pour dire que ce chemin fonctionne, ni pour dire
  qu'il refuse.
- Reproduction  : `sed -n '252,281p' backend/app/Http/Controllers/Api/RgpdRequestsController.php`
- Correctif     : ~2 h — `permission:rgpd.process` (ou `data.export` à défaut), assertion d'espace de
  travail sur `$req`, et deux tests : un qui passe, un qui rougit avec un compte `viewer`.
- Statut        : ouvert

### [B12-017] `Api/Phase2/CampaignsController` est un contrôleur mort : aucune route ne le désigne

- Sévérité      : S3 finition
- Domaine       : backend
- Référence     : `main` `1145473`
- Emplacement   : `backend/app/Http/Controllers/Api/Phase2/CampaignsController.php` (20 lignes)
- Constat       : la classe n'est importée nulle part dans `routes/api.php` — le fichier note
  « /campaigns retiré — implémenté en Sprint 19.7 ci-dessus » — et `route:list` ne la mentionne pas.
- Preuve        :
  ```
  grep -c "Phase2\\CampaignsController" backend/routes/api.php   → 0
  docker exec axion-crm-api php artisan route:list --path=api | grep -c "Phase2.Campaigns"  → 0
  ```
  À comparer avec ses deux voisines, bien routées : `ANY api/v1/cold-email{any?}` et
  `ANY api/v1/linkedin{any?}` apparaissent dans `route:list`.
- Témoin négatif: la même recherche trouve bien `ColdEmailController` et `LinkedInController` dans
  les deux sorties. Le contrôle sait reconnaître un contrôleur routé.
- Impact        : nul en exécution. Réel en lecture : le prochain qui compte les stubs Phase 2 en
  trouvera trois dans le dossier et deux dans les routes, et cherchera l'écart — exactement ce que
  je viens de faire. Sa documentation OpenAPI (`@OA\Get(path="/campaigns" … 501)`) **contredit** la
  route réelle `/v1/campaigns`, qui est implémentée depuis le sprint 19.7.
- Reproduction  : les deux commandes ci-dessus.
- Correctif     : 5 min — supprimer le fichier. Le commentaire de `routes/api.php` explique déjà
  pourquoi il n'a plus lieu d'être.
- Statut        : ouvert

### [B12-018] `GET /v1/users` liste les comptes par leur pointeur d'affichage, pas par leur appartenance

- Sévérité      : S2 défaut
- Domaine       : interface
- Référence     : `main` `1145473`
- Emplacement   : `backend/app/Http/Controllers/Api/UsersController.php:32`
- Constat       : la liste des membres de l'espace de travail s'obtient par
  `User::query()->where('current_workspace_id', $workspaceId)` — c'est-à-dire par la colonne qui dit
  « quel espace ce compte regarde en ce moment », et non par la table d'appartenance.
- Preuve        : le code, une ligne. Et le produit dit lui-même que ce n'est pas la bonne colonne —
  `Api/Crm/ConsoleController.php:54` : « l'appartenance est exigée, et elle se lit dans
  `user_workspaces`, **jamais** dans `users.current_workspace_id` (pointeur d'affichage que
  l'utilisateur modifie lui-même) ».
- Témoin négatif: `ConsoleAccess::isMemberOf()` existe et lit bien `user_workspaces` ; la console v2
  l'utilise. La bonne méthode est dans le dépôt, à un appel de distance.
- Impact        : un collègue qui a basculé sur l'autre univers **disparaît de la liste des
  utilisateurs**. L'administrateur croit qu'il n'a plus accès, ou qu'il a été supprimé. Et comme
  `POST /users`, `PUT /users/{u}` et `DELETE /users/{u}` répondent tous **501**, il n'existe aucun
  moyen de vérifier ou de corriger depuis la console : **la gestion des comptes n'existe pas**,
  seule sa liste — partielle — est implémentée.
- Reproduction  : `sed -n '25,40p' backend/app/Http/Controllers/Api/UsersController.php`
- Correctif     : 30 min — joindre `user_workspaces` comme le fait `ConsoleAccess`. La mise en œuvre
  des trois routes 501 est un chantier à part.
- Statut        : ouvert

### [B12-019] `channels.php` : les deux canaux privés ne sont jamais enregistrés, et la correction UUID du 16/08 n'a jamais été exercée

- Sévérité      : S3 finition
- Domaine       : canal
- Référence     : `main` `1145473`
- Emplacement   : `backend/routes/channels.php:46-55`, `backend/config/broadcasting.php:13-17`
- Constat       : le fichier n'enregistre `workspace.{workspaceId}` et `user.{userId}` que si le
  pilote de diffusion n'est ni `log` ni `null` ; or `BROADCAST_CONNECTION` n'est défini dans aucun
  `.env` du dépôt, la valeur par défaut est `log`, et le conteneur `axion-crm-reverb` est **arrêté**
  (`Exited (0)`).
- Preuve        :
  ```
  grep -n "BROADCAST_CONNECTION" .env backend/.env .env.example   → aucune ligne
  backend/config/broadcasting.php:13  $broadcastDefault = env('BROADCAST_CONNECTION', 'log');
  docker ps -a --filter name=reverb   → axion-crm-reverb  Exited (0) 2 hours ago
  ```
  La route `broadcasting/auth` existe pourtant bien dans `route:list` : elle refusera tout abonnement
  à un canal privé, puisqu'aucun canal n'est déclaré.
- Témoin négatif: la même énumération de routes montre que `broadcasting/auth` **est** enregistrée
  (`GET|POST|HEAD broadcasting/auth`), donc le contrôle sait voir ce qui existe ; ce sont bien les
  deux `Broadcast::channel()` qui ne s'exécutent pas.
- Impact        : deux conséquences. (1) Fonctionnelle : le temps réel annoncé (notifications
  in-app, résultats de collecte, `ScraperRunCancelled` émis par `ScraperRunsController::cancel`)
  ne parvient à personne — les événements sont écrits dans un fichier de journal. (2) De sûreté :
  la correction du 2026-08-16, qui remplace un `(int)` sur UUID par `hash_equals`, est du code
  **jamais exécuté** ; elle ne peut donc pas être dite vérifiée, et le jour où Reverb sera rebranché
  ce sera la première fois que ces deux fermetures tourneront. Le fichier lui-même le dit :
  « Il se serait activé à la seconde où Reverb aurait été rebranché — c'est-à-dire au moment où
  personne ne l'aurait cherché. » Le même raisonnement s'applique maintenant à sa correction.
- Reproduction  : les trois commandes ci-dessus.
- Correctif     : ~1 h — un test qui appelle directement les deux fermetures avec deux UUID
  différents (sans dépendre du pilote), pour que la correction soit prouvée avant que le canal ne
  soit rouvert. La remise en service de Reverb est un chantier distinct.
- Statut        : ouvert

---

## 6. Ce que je n'ai PAS pu vérifier, et pourquoi

Cette liste est un livrable.

1. **Point 10 de la grille — index derrière la requête (`EXPLAIN`).** Non joué, sur les 117 routes.
   Deux raisons : la base de travail `axion_crm` a été **vidée par le `migrate:fresh` d'un autre
   agent** pendant mon audit (0 workspace, 0 utilisateur, 0 entreprise à 12 h 05), et le conteneur
   `axion-crm-api` tournait à une charge moyenne de **26 à 28** avec une dizaine de suites Pest,
   PHPStan et deux migrations concurrentes. Toutes les cases 10 portent
   « non vérifié — hors budget agent 12 ». Les commentaires du code citent des mesures précises
   (3 487 ms sans l'index `(workspace_id, created_at DESC)`, 6,4 s sur un tri `last_name`) : elles
   sont plausibles et cohérentes, mais je ne les ai **pas** rejouées.

2. **Point 17 — « et vu rouge ».** Je rends la couverture **nominale** (quelles routes sont citées
   par un test : 71 sur 117) mais **je n'ai pas rejoué la suite Pest**, pour la même raison de
   saturation. Aucune de mes cases 17 n'affirme qu'un test rougit ; elles disent « route citée,
   rougeur non vérifiée ». C'est une limite réelle : « cité » n'est pas « testé », et le dépôt
   s'est déjà fait prendre à confondre les deux.

3. **Point 4 — étanchéité, route par route.** J'ai joué la mesure sur **une** route
   (`GET /companies/{company}`, B12-001) avec un témoin positif. Les 20 autres routes sans filtre
   applicatif portent « non vérifié — même mécanisme, fuite présumée, non jouée route par route ».
   Le mécanisme est prouvé (rôle `BYPASSRLS`, deux ceintures désarmées) ; l'exhaustivité ne l'est pas.

4. **La production et la préproduction.** Aucune requête n'y a été jouée : la consigne est la
   lecture seule et je n'avais aucun moyen de lire sans risquer d'écrire (une session, un jeton).
   En particulier je **ne sais pas** si `CRM_DB_APP_ROLE_ENABLED`, `WORKER_INTERNAL_HMAC_SECRET`,
   `MAIL_WEBHOOK_TOKEN` et `APP_DEBUG` y valent autre chose que leurs valeurs du dépôt. B12-001 et
   B12-004 reposent sur des valeurs par défaut **livrées dans `.env.example`** : si la production
   les surcharge, leur gravité y baisse — et personne ne pourra le dire sans regarder.

5. **La lenteur de rendu de la 500 d'A-001 avec `APP_DEBUG=true`.** Observée (deux sondes non
   terminées au bout de 15 min, trois expirations `curl`), **non chiffrée proprement** : le
   conteneur était saturé par d'autres agents, je ne peux pas isoler la part du rendu de la part de
   la contention. Je n'en fais donc pas un constat, seulement une observation au §2.

6. **Le plafond d'export « 5 000 lignes, silencieux »** mentionné par le dossier commun : je ne l'ai
   trouvé **nulle part** dans les trois contrôleurs d'export (`chunkById(1000)` déroule sans borne).
   Soit il vit ailleurs (une commande, un service, le frontend), soit il n'existe plus. Je le signale
   dans B12-010 sans trancher — ce n'est pas mon périmètre.

7. **`Api/Phase2/CampaignsController` et `Api/Crm/ConsoleController`** : j'ai vérifié que le premier
   n'est routé nulle part (B12-017) et que le second est une **classe abstraite de base**, donc
   normalement non routée. Je n'ai pas cherché s'il existe d'autres classes non atteintes dans les
   42 contrôleurs.

8. **Le frontend.** Je n'ai pas vérifié quelles routes la console appelle réellement. Une route
   « vivante » peut n'être appelée par personne, et une route 501 peut être appelée à chaque
   ouverture d'écran. C'est le périmètre d'un autre agent, mais cela change la gravité de plusieurs
   constats ci-dessus — en particulier B12-007.
