# P6 — REGARD NEUF · Points d'API et contrôleurs

> Auditeur neuf, sans accès aux constats des passes 1 et 2. Périmètre : `backend/routes/api.php`
> et les 44 contrôleurs de `backend/app/Http/Controllers/`. Grille du §5.2 du mandat (18 points).
> **Aucune réparation n'a été faite.** Analyse statique et lecture de code uniquement.

---

## 0. LA RÉFÉRENCE SUR LAQUELLE J'AI MESURÉ

Relue moi-même, comme l'exige le §6 du cadre. Aucun identifiant repris d'un document.

```
$ git -C C:/Users/willi/Documents/Projets/crmpro-wt-a35-auth log --oneline -3
23a0e5f fix(infra): la faille du 19 aout se rouvrait en un clic, et par deux autres chemins
a0a6310 test(garde): trois defauts de la garde HMAC, dont un qui polluait toute la suite
3c2c0cf docs(rectificatif): trois affirmations sur la production que ce lot ne pouvait pas mesurer

$ git -C ... rev-parse --abbrev-ref HEAD
fix/a35-authentification
```

```
$ gh pr list --state open
191  fix(auth+rgpd): les cinq verrous qui rendaient le CRM inutilisable, et deux routes ouvertes à tous  fix/a35-authentification  OPEN  2026-08-19T16:14:19Z
190  docs(etape1a): fin de session — la liste qui doit survivre a la conversation                        docs/fin-de-session       OPEN  2026-08-19T11:16:22Z
```

⚠️ **La référence a bougé pendant ma mesure.** En fin de session :

```
$ git -C ... log --oneline -3
b6fa07f fix(runbooks): sept invocations nues de plus — et c'est moi qui ai commis le patron
23a0e5f fix(infra): la faille du 19 aout se rouvrait en un clic, et par deux autres chemins
a0a6310 test(garde): trois defauts de la garde HMAC, dont un qui polluait toute la suite

$ git -C ... show --stat --oneline b6fa07f
 .../Infra/PileDeProductionSansOverlayTest.php  | 22 +
 infra/runbooks/01-restart-workers.md           | 28 +
 infra/runbooks/03-site-down.md                 | 28 +
 infra/runbooks/04-restore-dr.md                | 37 +-
 infra/runbooks/05-rotate-secrets.md            | 28 +
```

Ce commit ne touche **ni `routes/`, ni `app/Http/`, ni `app/Policies/`** : il ne change rien à ce
rapport. **Tout ce qui suit vaut pour l'arbre `23a0e5f`..`b6fa07f`, branche `fix/a35-authentification`,
qui n'est pas fusionnée dans `main`.** Un lecteur qui mesurerait sur `main` verrait *davantage* de
défauts, pas moins : cette branche est celle qui en ferme.

**Volumétrie mesurée**, pas déclarée :

```
$ wc -l backend/routes/api.php
392 backend/routes/api.php

$ find backend/app/Http/Controllers -name "*.php" | wc -l
44
```

```
$ python -c "<strip commentaires> ; compter Route::verbe(...)"
TOTAL routes declarees: 114
SANS permission:: 94
```

114 déclarations de route (dont un `apiResource` qui en vaut 5 : ~118 points d'entrée réels).
**94 déclarations sur 114 ne portent aucune garde `permission:`.**

---

## 1. CE QUE J'AI COUVERT, ET COMMENT

| Points de la grille §5.2 | Traitement |
|---|---|
| 1 (authentification), 2 (autorisation), 3 (contexte d'espace), 4 (autre espace → 0 ligne) | **couverts route par route**, par lecture du code de chaque contrôleur |
| 5, 6 (validation, types/bornes) | couverts sur les routes qui écrivent |
| 7 (injection / tri arbitraire) | couvert : `spatie/query-builder` avec `allowedSorts`/`allowedFilters` explicites partout où il est employé ; pas d'interpolation de colonne trouvée |
| 8 (pagination), 11 (codes d'erreur), 18 (route morte / 501 / doublon) | **couverts systématiquement** |
| 14 (données personnelles / masquage) | **couvert systématiquement** |
| 15 (débit), 16 (signature interne) | couverts |
| 17 (test existant, vu rouge) | **partiellement** : j'ai lu les tests, je ne les ai **pas exécutés** (interdit d'exécution) |
| 9 (N+1), 10 (`EXPLAIN`) | **NON couverts** — voir §5 |
| 12 (idempotence des `POST` créateurs), 13 (journal d'audit des écritures) | **partiellement** — voir §5 |

---

## 2. CONSTATS

### P6-API-001 — `GET /v1/journalists` rend les journalistes de TOUS les espaces de travail · **S0**

**Fichier** : `backend/app/Http/Controllers/Api/JournalistsController.php:22` (`index`), requête
construite en `:61` (`buildFilteredQuery`).

**Preuve.**

```
$ grep -n "workspace_id" app/Http/Controllers/Api/JournalistsController.php
105:                ->where('workspace_id', $workspaceId)

$ grep -n "public function index\|private function buildFilteredQuery\|public function export" \
      app/Http/Controllers/Api/JournalistsController.php
22:    public function index(Request $r): JsonResponse
61:    private function buildFilteredQuery(): QueryBuilder
77:    public function export(Request $r): StreamedResponse
```

L'**unique** filtre d'espace du fichier est à la ligne 105 — c'est-à-dire **dans `export()`**
(qui commence en 77). `index()` (ligne 22) appelle `buildFilteredQuery()` (ligne 61), dont le
corps entier est :

```php
return QueryBuilder::for(Journalist::query()->whereNull('deleted_at'))
    ->allowedFilters(...);
```

Aucune couche ne rattrape :
- `App\Models\Journalist` **n'utilise pas** le trait `BelongsToWorkspace` (grep sur `app/Models/`
  ne rend que `Candidate`, `Company`, `Contact`, `Tag`) ;
- le global scope Eloquent est inerte par défaut (`config/crm.php:53` :
  `'strict_workspace_scope' => env('CRM_STRICT_WORKSPACE_SCOPE', false)`, et
  `.env.example:78` = `false`) ;
- la RLS Postgres est **posée mais contournée** par défaut : `config/crm.php:36`
  `'db_app_role' => env('CRM_DB_APP_ROLE_ENABLED', false)`, `.env.example:76` = `false`, et la
  migration `2026_08_14_000001_harden_workspace_isolation.php:37-43` l'écrit elle-même
  (« les policies, même strictes et même en FORCE, restent contournées »).

**Impact, du point de vue de celui qui s'en sert.** N'importe quel compte authentifié — y compris
un `viewer`, qui n'a que `companies.view`, `llm.view_usage`, `rgpd.view`
(`database/seeders/PermissionsAndRolesSeeder.php:69`) — ouvre l'écran « Journalistes » et lit
**prénom, nom, rôle, rubrique, e-mail, téléphone** de tous les journalistes de **tous les clients
du CRM**. `journalists.workspace_id` est pourtant `UUID NOT NULL`
(`2026_07_06_000002_create_media_and_journalists.php`) : la colonne existe, elle n'est simplement
jamais interrogée sur cette route.

**Pourquoi S0.** Donnée personnelle (le fichier le dit lui-même en tête : « ⚠️ DONNÉE PERSONNELLE
(RGPD) »), d'un **autre responsable de traitement**, sans aucune permission requise, dans la
configuration **par défaut**. C'est la définition d'une violation de données au sens de l'art. 33.

---

### P6-API-002 — `GET /v1/rgpd/requests` rend les demandes RGPD de TOUS les espaces · **S0**

**Fichier** : `backend/app/Http/Controllers/Api/RgpdRequestsController.php:30-48`.

**Preuve.**

```
$ grep -n "workspace_id" app/Http/Controllers/Api/RgpdRequestsController.php
74:            'workspace_id' => app()->bound('workspace.id') ? app('workspace.id') : null,
```

Ligne 74 est dans `store()` (l'écriture). La lecture, `index()` en ligne 30, est :

```php
$rows = RgpdRequest::query()
    ->when($r->query('status'), fn ($q, $s) => $q->where('status', $s))
    ->orderByDesc('requested_at')
    ->paginate(25);
```

`rgpd_requests.workspace_id` est `UUID NOT NULL`
(`2026_05_16_000006_create_coverage_rgpd_aiact_schema.php:74`) et la table porte
`subject_email CITEXT NOT NULL` (:77) et `metadata JSONB` (:83), où `process()` archive le
**résultat** du traitement.

**Ce qui rend ce constat particulièrement lourd** : le contrôleur voisin,
`AuditLogsController.php:31-45`, porte **exactement le raisonnement qui manque ici**, écrit noir
sur blanc :

> « Un premier correctif a pose la permission `audit.view` […] mais une permission separe les
> ROLES, jamais les CLIENTS. L'administrateur de l'espace A lisait toujours le journal de
> l'espace B. »

et il pose son filtre explicite avec un `whereRaw('1 = 0')` quand le contexte manque. **Le même
défaut, à deux fichiers de là, dans le même lot, n'a pas été corrigé.** La route `/rgpd/requests`
a reçu une permission (`permission:rgpd.view`, `routes/api.php:255`) et **c'est tout** — c'est
littéralement l'erreur que le fichier d'à côté décrit comme insuffisante.

**Impact.** Un `viewer` (qui porte `rgpd.view`) lit l'adresse e-mail de **chaque personne ayant
exercé un droit RGPD chez n'importe quel client du CRM**, plus le contenu de `metadata`.

---

### P6-API-003 — `GET /v1/media` rend les médias de TOUS les espaces, et son docbloc affirme le contraire · **S1**

**Fichier** : `backend/app/Http/Controllers/Api/MediaController.php:22` (`index`), requête en `:63`.

**Preuve.**

```
$ grep -n "workspace_id" app/Http/Controllers/Api/MediaController.php
115:            $this->buildFilteredQuery()->where('workspace_id', $workspaceId),
```

Ligne 115 est dans `export()` (qui commence en 91). `index()` (ligne 22) n'a rien.

Le docbloc de classe (`:15-19`) écrit pourtant : « Calquée sur `CompaniesController` : index paginé
\+ query filtrée partagée avec l'export CSV streamé, **scoping workspace explicite**, défensif ».
`CompaniesController::index` **porte** ce scope (`:79`) ; `MediaController::index` **ne le porte
pas**. Le commentaire décrit une intention, pas le code.

**Impact.** Nom, e-mail de rédaction, téléphone, éditeur, n° CPPAP/ARCOM des médias de tous les
locataires, sans permission. Et `show()` (`:149`), lui protégé par `refuserHorsEspace`, charge
`->load(['journalists', …])` : la fiche média d'un espace **rend les journalistes complets**, sans
masquage (cf. P6-API-006).

Moindre que S0 parce que la donnée « média » est majoritairement une donnée d'organisation
(rédaction) et non de personne — mais elle est bien une donnée **de client tiers**.

---

### P6-API-004 — `GET /v1/proxy-providers` : pas de filtre d'espace, et pas la permission qui existe pour ça · **S2**

**Fichier** : `backend/app/Http/Controllers/Api/ProxyProvidersController.php:19-31`.

```php
return $this->ok(['data' => ProxyProvider::query()->orderBy('slug')->limit(50)->get()]);
```

`proxy_providers_config.workspace_id` est `UUID NOT NULL`
(`2026_05_16_000004_create_llm_proxies_rotations_schema.php:77`), la colonne `metadata JSONB` est
rendue telle quelle, et la permission `proxies.config` **existe** (seeder :22) et n'est portée que
par `owner` et `admin`. La route n'exige **rien** (`routes/api.php:202`).

**Impact.** Un `viewer` de l'espace A lit la configuration proxy (fournisseur, type, zone, poids,
état de santé, `metadata` libre) de l'espace B. Pas de PII, mais c'est de la donnée d'exploitation
d'un concurrent potentiel.

---

### P6-API-005 — Cinq listes sont FAIL-OPEN : « pas de contexte d'espace ⇒ je montre tout » · **S1**

Le motif est identique partout : le filtre d'espace est posé **sous condition**, et l'absence de
condition vaut absence de filtre.

**Preuve (extrait, `grep -n -B2 -A2 "if (\$workspaceId"`)**

```
app/Http/Controllers/Api/TagsController.php-29-  $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
app/Http/Controllers/Api/TagsController.php-30-  $q = Tag::query()->orderBy('category')->orderBy('name');
app/Http/Controllers/Api/TagsController.php:31:  if ($workspaceId) {
app/Http/Controllers/Api/TagsController.php-32-      $q->where('workspace_id', $workspaceId);
app/Http/Controllers/Api/TagsController.php-33-  }

app/Http/Controllers/Api/AudiencesController.php:34:  if ($workspaceId) { $q->where('workspace_id', $workspaceId); }
app/Http/Controllers/Api/ScraperRunsController.php:32: if ($workspaceId !== null) { $query->where('workspace_id', $workspaceId); }
app/Http/Controllers/Api/ScrapingCampaignsController.php:65: if ($workspaceId !== null) { $query->where('workspace_id', $workspaceId); }
```

(et `LlmUseCasesController:25`, `ProxyProvidersController:26` n'ont même pas la condition.)

**Ce qui rend ce constat non théorique** : `users.current_workspace_id` est **nullable**
(`2026_05_16_000002_create_auth_tenant_audit_schema.php:51` :
`current_workspace_id UUID REFERENCES workspaces(id) ON DELETE SET NULL`). Un compte créé sans
espace courant — ou dont le workspace a été supprimé, ce que le `ON DELETE SET NULL` produit
automatiquement — traverse `SetCurrentWorkspace` sans que `workspace.id` soit lié
(`SetCurrentWorkspace.php:45-50` : `if ($workspaceId !== null)`), et **voit alors les étiquettes,
audiences, exécutions de collecte et campagnes de tous les locataires**.

**L'ironie mesurée** : le même dépôt a explicitement **supprimé** ce motif dans
`AudiencesController::assertWorkspace` (`:152-160`, « Cette methode etait FAIL-OPEN […] Rien ne
distingue un test d'une production »), dans `ScraperRunsController::belongsToCurrentWorkspace`
(`:177-184`) et dans `TagsController::update/destroy` (`:122`, `:153`). **Il l'a laissé intact
dans les `index()` des mêmes fichiers**, quinze à cent lignes plus haut. La correction a suivi les
routes unitaires ; elle n'a pas suivi les listes.

---

### P6-API-006 — Le masquage des coordonnées (`contacts.view_pii`) se contourne en un clic · **S1**

**Preuve.**

```
$ grep -rn "MasquageCoordonnees" app/ | grep -v "Support/MasquageCoordonnees.php"
app/Http/Controllers/Api/CompaniesController.php:11,87,93,97
app/Http/Controllers/Api/ContactsController.php:7,42,103,106
app/Http/Controllers/Api/Crm/ContactsHubController.php:10,68,268,276,277
```

**Trois** contrôleurs, et uniquement leurs **méthodes `index()`**. Or les points d'entrée suivants
rendent e-mail et/ou téléphone **en clair**, et aucun n'exige `contacts.view_pii` :

| Route | Où | Ce qui sort en clair |
|---|---|---|
| `GET /companies/{company}` | `CompaniesController.php:334-346` — `->load(['contacts','tags'])` | **tous** les contacts de la fiche, e-mail + téléphone bruts |
| `GET /contacts/{contact}` | `ContactsController.php:126-132` — `$this->ok($contact)` | le modèle entier |
| `GET /journalists` et `/journalists/{j}` | `JournalistsController.php:36`, `:133` | `email`, `phone` |
| `GET /media/{media}` | `MediaController.php:149-156` — `->load(['journalists',…])` | les journalistes rattachés, en entier |
| `GET /audiences/{audience}/members` | `AudiencesController.php:132-148` — `select('ct.first_name','ct.last_name','ct.email')` | nom + e-mail des contacts de l'audience |
| `GET /crm/persons/{personKey}/timeline` | `PersonTimelineController.php:137-141` | `contacts.email`, `contacts.phone` |
| `GET /crm/candidates` | `CandidatesController.php:203-204` | `email`, `phone` des **candidats** (vivier) |

**Impact.** Le §2.10 dit : « Un `viewer` doit pouvoir consulter et se repérer, pas repartir avec
665 771 adresses lisibles à l'écran » (`MasquageCoordonnees.php:6-12`). Le viewer n'a effectivement
que `p***@acme.fr` dans la liste — et l'adresse complète dès qu'il **ouvre une fiche**, ce qui est
le geste normal. La garde ne réduit pas l'exposition, elle ralentit d'un clic.
`GET /companies/{company}` n'exige d'ailleurs **aucune** permission (`routes/api.php:139`).

---

### P6-API-007 — Dix routes destructrices ou coûteuses n'exigent aucune permission · **S1**

Le modèle de droits existe et est juste (`PermissionsAndRolesSeeder.php:60-70`) :
`viewer` = `companies.view` + `llm.view_usage` + `rgpd.view`, rien d'autre. Il n'est pas exigé sur :

| Route | Ce qu'un `viewer` peut faire | Fichier |
|---|---|---|
| `DELETE /journalists/{journalist}` | **effacer** (soft-delete) une personne | `JournalistsController.php:186-196` |
| `POST /journalists/{journalist}/opt-out` | opposer une personne, et **émettre** l'événement vers le site (`ConsentOutboundRecorder`) | `:151-183` |
| `POST /coverage/launch` | lancer une collecte facturée (proxy + quota) | `CoverageController.php:180-247` |
| `POST /coverage/enrich` | mettre en file **jusqu'à 50 000** enrichissements LLM | `:263-311` |
| `POST /campaigns` + `/start` + `/pause` + `/resume` + `/cancel`, `PUT`, `DELETE` | créer, démarrer, supprimer une campagne de collecte | `ScrapingCampaignsController.php` |
| `POST /scraper-runs/{run}/cancel` et `/retry` | interrompre ou relancer une exécution | `ScraperRunsController.php:78`, `:127` |
| `POST /audiences`, `PUT`, `DELETE`, `/refresh` | créer/supprimer une audience d'e-mailing | `AudiencesController.php:44`, `:75`, `:89`, `:118` |
| `POST /crm/bulk` | action de masse sur 500 fiches (tags, étape de cycle de vie) | `Crm/BulkController.php:42` |

**L'écart est interne, pas théorique** : `POST /companies/{company}/enrich` — l'enrichissement
**unitaire** — exige `companies.update` depuis ce lot (`routes/api.php:147-148`), tandis que
`POST /coverage/enrich`, sa version **de masse à 50 000 fiches**, n'exige rien
(`routes/api.php:174`). Le `throttle:scraper-launch` posé dessus limite la **cadence**, pas le
**droit** — c'est exactement la phrase que le dépôt écrit lui-même pour `/companies/export`
(`routes/api.php:118-120`).

---

### P6-API-008 — `POST /companies/bulk-enrich` écrit sur les fiches d'un autre espace · **S1**

**Fichier** : `backend/app/Http/Controllers/Api/CompaniesController.php:452-460`.

```php
public function bulkEnrich(Request $r): JsonResponse
{
    $ids = $r->validate(['ids' => 'required|array|max:500', 'ids.*' => 'integer'])['ids'];
    foreach ($ids as $id) {
        EnrichCompanyJob::dispatch((int) $id);
    }
    return $this->ok(['queued' => count($ids)]);
}
```

Aucune vérification que les identifiants appartiennent à l'espace courant. Et le job est
dispatché **sans** son second paramètre :

```php
// app/Jobs/EnrichCompanyJob.php:29-49
public function __construct(public readonly int $companyId, public readonly ?string $workspaceId = null) {}
public function handle(WaterfallOrchestrator $waterfall): void
{
    if ($this->workspaceId === null) { $this->enrich($waterfall); return; }   // ← branche prise
    $this->inWorkspace($this->workspaceId, fn () => $this->enrich($waterfall));
}
private function enrich(...): void { $company = Company::find($this->companyId); … }
```

`Company::find()` **hors de tout contexte d'espace** : le global scope est inerte (drapeau à
false), la RLS est contournée (rôle superutilisateur). `WaterfallOrchestrator::enrich()` **écrit**
sur la fiche.

**Le voisin fait bien** : `CompanyTagsBulkController` (`:80-92`) réintersecte explicitement les
identifiants reçus avec ceux de l'espace (« on ne touche que des fiches de cet univers, même si des
identifiants d'ailleurs sont glissés dans la requête »). Le même auteur, la même semaine, le même
type d'action de masse — et `bulk-enrich` ne le fait pas.

**Impact.** Un opérateur de l'espace A énumère 500 identifiants (ce sont des `BIGSERIAL`
consécutifs) et déclenche l'enrichissement — donc l'**écriture**, et la dépense LLM/proxy — sur les
fiches de l'espace B. `CoverageController::enrich` (`:302`) dispatche de la même façon, mais après
avoir filtré la requête sur le workspace : lui est correct.

---

### P6-API-009 — Douze points d'API répondent « rien à afficher » quand la base est en panne, et personne ne le lit · **S1**

**Preuve.**

```
$ grep -rc "degraded" backend/app/Http/Controllers/ --include=*.php | grep -v ":0"
Audiences:1  AuditLogs:2  Companies:1  Contacts:1  Coverage:2  Journalists:1
LlmUseCases:1  Media:1  ProxyProviders:1  RgpdRequests:1  ScraperRuns:1
ScrapingCampaigns:1  Tags:1  Users:1

$ grep -rn "degraded" frontend/src/ | wc -l
0
```

Le motif, identique partout (ex. `ContactsController.php:69-77`) :

```php
} catch (\Throwable $e) {
    Log::error('contacts.index failed', …);  report($e);
    return $this->ok(['data' => [], 'meta' => … + ['degraded' => true]]);
}
```

**Le drapeau `degraded` est émis par le serveur et lu par exactement zéro ligne du frontend.**

**Impact, du point de vue de celui qui s'en sert.** Un délai d'attente Postgres, une policy RLS qui
refuse, une colonne manquante après migration — et l'écran « Contacts » affiche « aucun résultat »
sur une base de 1,3 M de lignes. Le même mécanisme s'applique à `/rgpd/requests` : un opérateur
conclut « aucune demande RGPD en attente » alors que la requête a échoué. Sur une obligation
légale à délai (art. 12 §3 : un mois), une liste vide mensongère est plus dangereuse qu'une erreur
franche. Le fichier `ContactsController.php:19-28` documente lui-même que « la page Contacts était
vide pour tout le monde » et que « personne ne l'a vu » ; le mécanisme qui a produit cet aveuglement
est toujours en place, simplement déplacé du bouchon vers le `catch`.

---

### P6-API-010 — Le tableau de bord affiche des zéros fabriqués, et le frontend les consomme · **S1**

**Fichier** : `backend/routes/api.php:84-97` — la route n'a pas de contrôleur, c'est une fermeture :

```php
Route::get('/dashboard/stats', function () {
    return response()->json([
        'companies_total' => 0, 'companies_enriched_24h' => 0, 'contacts_qualified' => 0,
        'scraper_runs_24h' => 0, 'llm_cost_eur_month' => 0,
        'quality_distribution' => ['complete' => 0, 'partielle' => 0, 'basique' => 0],
        'size_distribution' => ['artisan' => 0, 'tpe' => 0, 'pme' => 0, 'eti' => 0, 'grande_entreprise' => 0],
    ]);
});
```

```
$ grep -rn "dashboard/stats" frontend/src/
frontend/src/features/dashboard/DashboardPage.tsx:80:
    queryFn: async () => (await api.get<DashboardStats>('/dashboard/stats', { params: { period } })).data,
```

**Impact.** L'écran d'accueil du CRM annonce **0 entreprise, 0 contact qualifié, 0 € de coût LLM**
sur une base de 4,29 M de fiches. Le paramètre `period` envoyé par le frontend est ignoré. C'est
pire qu'un 501 : un 501 se voit, un zéro se croit. Sévérité S1 et non S2 parce que la donnée est
**fausse et affirmée**, pas absente.

---

### P6-API-011 — Trente et un points d'API sont des bouchons : 20 en 501, 11 en liste vide · **S1**

**Preuve.**

```
$ grep -rn "notImplemented(" app/Http/Controllers --include=*.php | grep -v "function notImplemented" | wc -l
20
```

Répartition (fichier:ligne) :
`SavedViews` store/show/update/destroy (`:22,30,38,46`) · `Users` store/update/destroy
(`:64,77,90`) · `Workspace` update (`:41`) · `Notifications` markRead/markAllRead (`:23,30`) ·
`Contacts` update/destroy (`:149,167`) · `LlmUseCases` update/updatePrompt (`:44,71`) ·
`ProxyProviders` update (`:44`) · `Rotations` update (`:23`) · `AiActRegister` store (`:22`) ·
`Phase2` Campaigns/ColdEmail/LinkedIn (`:18` ×3).

```
$ grep -rn "ok(\['data' => \[\]\])" app/Http/Controllers --include=*.php
AiActRegister:15  LlmUsage:15  Notifications:15  Rotations:15  SavedViews:15  (+ Coverage:36,44 ; GlobalSearch:18 ; LlmUseCases:57 ; ProxyProviders:21)
```

**Ce qui compte pour l'utilisateur**, en clair :

- **On ne peut pas inviter un utilisateur.** `POST /users` → 501, `PUT /users/{user}` → 501,
  `DELETE /users/{user}` → 501, `PUT /workspace` → 501. Toute la section « Équipe et sécurité »
  du CDC §19 n'a **aucun** point d'API. La création de comptes se fait donc en base, à la main.
- **On ne peut pas modifier ni supprimer un contact.** `PUT /contacts/{contact}` → 501,
  `DELETE /contacts/{contact}` → 501. Le CDC §23.4 budgète « créer un contact complet (1 clic +
  saisie) » : il n'existe même pas de `POST /contacts`.
- **La recherche globale (⌘K) rend toujours vide** : `GlobalSearchController.php:18` →
  `['companies' => [], 'contacts' => [], 'tags' => []]`, en dur.
- **Les notifications** : la liste est vide en dur, « marquer comme lu » rend 501. Le compteur du
  bandeau ne peut donc jamais bouger.
- **Les vues enregistrées** (5 routes) : liste vide en dur, tout le reste en 501.
- **Le registre AI Act** : la liste est vide en dur, la création rend 501 — alors que c'est une
  obligation réglementaire tenue par ce registre.
- **`GET /coverage/cells/{cell}`** rend `['id' => $cell]` — c'est-à-dire le paramètre qu'on lui a
  passé (`CoverageController.php:318-321`).
- **`POST /proxy-providers/{p}/test`** rend `['healthy' => true]` **sans rien tester**
  (`ProxyProvidersController.php:52-57`). Un contrôle de santé qui répond toujours « oui » est
  exactement le motif « faute de savoir, répondre oui ».

Je classe S1 et non S2 parce que six de ces surfaces sont **atteignables depuis la navigation** et
qu'une console où l'on ne peut ni inviter un collègue, ni éditer un contact, ni rechercher, ne
remplit pas sa fonction.

---

### P6-API-012 — Les dix policies enregistrées ne sont invoquées nulle part, et leur comparaison est fausse · **S1**

**Preuve — le code mort.**

```
$ grep -rn "authorize(\|Gate::\|->can(\|authorizeResource" app/ routes/ --include=*.php
app/Http/Requests/Auth/LoginRequest.php:9:              public function authorize(): bool
app/Http/Requests/StoreEmailAudienceRequest.php:11:     public function authorize(): bool
app/Http/Requests/StoreScrapingCampaignRequest.php:11:  public function authorize(): bool
app/Http/Requests/UpdateScrapingCampaignRequest.php:11: public function authorize(): bool
app/Providers/HorizonServiceProvider.php:17:            Gate::define('viewHorizon', …)
app/Providers/TelescopeServiceProvider.php:36:          Gate::define('viewTelescope', …)
app/Support/MasquageCoordonnees.php:43:                  return ! $user->can(self::PERMISSION);
```

Sept résultats, **et pas un seul appel de policy**. Les quatre `authorize()` sont les méthodes des
`FormRequest` (elles rendent `true`), les deux `Gate::define` concernent Horizon et Telescope, et
le `->can()` est la permission de masquage. `AuthServiceProvider.php:10-21` enregistre pourtant
**dix** policies (`Company`, `Contact`, `ScraperRun`, `Workspace`, `User`, `Tag`, `RgpdRequest`,
`AuditLog`, `LlmUseCase`, `ProxyProvider`) et le provider est bien chargé
(`bootstrap/providers.php:5`). **Elles ne sont jamais consultées.**

Réponse à la question 2 de la grille §5.2 (« Autorisation (policy) vérifiée **et testée** ? ») :
**non, sur les 118 points d'entrée.**

**Preuve — la comparaison fausse.** `app/Policies/BasePolicy.php:21-27` :

```php
protected function sameWorkspace(User $user, $model): bool
{
    if (! isset($model->workspace_id)) { return true; }
    return (int) $user->current_workspace_id === (int) $model->workspace_id;
}
```

`workspaces.id` est un **UUID** (`2026_05_16_000002…:51`). En PHP, `(int) "a3f1-…"` vaut **0**, et
`(int) "b7c2-…"` vaut **0** aussi : la comparaison rend **`true` pour deux espaces différents** dès
que les deux UUID commencent par une lettre. Le dépôt connaît ce piège et l'a écrit ailleurs —
`SetCurrentWorkspace.php:41-43` : « ne PAS caster en int (PHP `(int)"1db1…"` = 1) » — mais la
policy n'a pas été relue.

Et la première ligne, `if (! isset($model->workspace_id)) { return true; }`, est un fail-open de
plus : `isset()` rend `false` sur une valeur `null`, donc un enregistrement à `workspace_id` nul est
accordé à tout le monde.

**Sévérité S1 et non S0** : ce code ne cause aucun dommage **aujourd'hui**, précisément parce qu'il
est mort. Mais il constitue un piège actif : le jour où quelqu'un ajoute un `$this->authorize(…)`
en croyant poser une garde, il posera une garde qui dit toujours « oui ».

---

### P6-API-013 — `refuserHorsEspace()` laisse passer tout enregistrement sans espace · **S2**

**Fichier** : `backend/app/Http/Controllers/Api/ApiController.php:71-89`.

```php
$espaceDuModele = $modele->{$colonne} ?? null;
// Un enregistrement sans espace n'appartient a personne en particulier
// (referentiels globaux) : ce n'est pas a cette garde de trancher.
if ($espaceDuModele === null || $espaceDuModele === '') { return; }
```

C'est la garde durcie sur laquelle reposent **20 méthodes** (grep `refuserHorsEspace` : 20 appels).
La justification (« référentiels globaux ») est vraie pour **une seule** table : la migration de
durcissement le dit et le mesure (`2026_08_14_000001…:66-77`, `GLOBAL_ROW_TABLES = ['llm_use_cases']`,
« 0 ligne à workspace_id NULL sur les 23 autres tables scopées »). Sur les 19 autres appels, la
branche « je ne tranche pas » est une exception ouverte sans besoin.

Or deux contrôleurs **fabriquent** des lignes candidates à cette branche :

```php
// CompaniesController.php:312  (POST /companies)
'workspace_id' => app()->bound('workspace.id') ? app('workspace.id') : null,
// RgpdRequestsController.php:74  (POST /rgpd/requests)
'workspace_id' => app()->bound('workspace.id') ? app('workspace.id') : null,
```

Les deux colonnes étant `NOT NULL` en base, l'insertion échoue — mais en **exception PDO non
rattrapée**, donc en **500** au lieu du 422 attendu (point 11 de la grille). La garde reste
néanmoins ouverte pour toute table qui deviendrait un jour nullable, et le commentaire l'autorise
explicitement.

---

### P6-API-014 — `GET /v1/search` est déclaré deux fois · **S3**

`routes/api.php:98-104` (fermeture rendant `['companies'=>[],'contacts'=>[],'tags'=>[]]`) et
`routes/api.php:236` (`[GlobalSearchController::class, 'index']`). La seconde déclaration écrase la
première dans la table de routage de Laravel. Les deux rendent une réponse vide, donc l'écart n'a
aucun effet observable — mais deux propriétaires pour une URL est la façon dont une correction se
perd : quelqu'un corrigera la fermeture et ne comprendra pas pourquoi rien ne change.

---

### P6-API-015 — `POST /internal/scraper-result` : pas de limitation de débit, pas d'anti-rejeu · **S2**

`routes/api.php:364` :

```php
Route::post('/scraper-result', [ScraperResultController::class, 'store'])->name('internal.scraper-result');
```

Les **trois autres** canaux internes du même groupe portent `->middleware('throttle:internal')`
(`:379`, `:387`, `:394`). Celui-ci, non. Et la signature qu'il vérifie ne couvre que le **corps
brut**, sans horodatage (`ScraperResultController.php:95-104`), là où `/internal/site-sync` signe
`timestamp . '.' . body` et rejette hors fenêtre (`SiteSyncController.php:46-59`).

**Impact.** Un point d'entrée non authentifié par session, sans plafond de requêtes, sur lequel une
signature interceptée reste valable indéfiniment. Le contrôleur documente lui-même la limite de
rejeu ; il ne documente pas l'absence de plafond.

À noter aussi, dans `HmacSignature::timestampWithinWindow` (`:70-73`) :
`if ($maxSkewSeconds <= 0) { return true; }`. La valeur vient de
`(int) env('CRM_INGEST_MAX_CLOCK_SKEW', 300)` : **toute valeur non numérique posée dans
l'environnement** (`"5m"`, `"off"`, une chaîne vide explicite) est castée en `0` et **désactive
silencieusement** l'anti-rejeu. Un contrôle qui, faute de savoir lire sa configuration, répond
« oui ». S3 en soi, mais c'est le même motif que le reste.

---

### P6-API-016 — `GET /audit-logs/verify-chain` vérifie la chaîne de tous les espaces · **S3**

`AuditLogsController.php:79-98`. `index()` a reçu son filtre d'espace (constat cité en
P6-API-002) ; `verifyChain()` appelle `$this->chain->verifyChain()` **sans aucun paramètre
d'espace**. L'administrateur de l'espace A obtient donc un verdict d'intégrité qui dépend des
écritures de l'espace B : un `valid: false` provoqué par un autre locataire lui sera imputé, et
inversement. Pas une fuite de contenu, mais un élément de preuve rendu inexploitable.

---

### P6-API-017 — Le jeton de portabilité RGPD est réutilisable, contrairement à ce que le service annonce · **S3**

`app/Services/Rgpd/GdprPortabilityService.php:13` : « fournit un token téléchargement
**one-shot** ». `retrieve()` (`:105-119`) ne pose ni ne lit aucun marqueur de consommation :

```php
$row = DB::table('rgpd_requests')->where('export_token', $hash)
    ->where('export_expires_at', '>', now())->first();
if (! $row) { return null; }
… return Crypt::decryptString(…);
```

Le jeton fonctionne autant de fois qu'on veut pendant 7 jours, et le fichier
`gdpr-exports/{token}.enc` n'est jamais supprimé. La route `/rgpd/export/{token}` est
**non authentifiée** par construction (`routes/api.php:76-77`, choix documenté et défendable :
la personne concernée n'a pas de compte). Le modèle de sécurité étant la seule possession du
jeton, « one-shot » n'est pas un détail de rédaction : c'est ce qui limite la fenêtre après une
fuite du lien (historique de navigateur, journal de serveur mandataire, transfert de courriel).

Second point, mineur mais réel : `$token` est concaténé dans un chemin de fichier
(`"gdpr-exports/{$token}.enc"`) **sans validation de format**, alors que la route ne pose aucune
contrainte `->where('token', …)`. L'exploitation exige de connaître d'abord un jeton dont le
sha256 figure en base, ce qui la rend improbable — mais la contrainte de route coûte une ligne.

---

### P6-API-018 — Un jeton d'API franchit la double authentification sans la présenter · **S2**

`app/Http/Middleware/EnsureTwoFactorPassed.php:68-75` :

```php
private function franchie(Request $request): bool
{
    if (! $request->hasSession()) {
        // Requête par jeton d'API : pas de session, donc pas de 2FA de session.
        return $request->user()?->currentAccessToken() !== null;
    }
    …
}
```

Le middleware qui rend la 2FA **exigible** (et dont le docbloc explique, à juste titre, qu'« un
drapeau de session que personne ne relit ne protège rien ») se laisse traverser par tout porteur
d'un jeton personnel. C'est un choix défendable et **assumé en commentaire** — mais alors la 2FA
n'est pas une exigence du compte, c'est une exigence du **navigateur**, et le vol d'un jeton
personnel rend la 2FA inopérante. Je le note parce que la grille demande « authentification
exigée ? » et que la réponse honnête est « oui, sauf par ce chemin ».

---

### P6-API-019 — L'étanchéité de l'univers business repose sur un pointeur que le code lui-même déclare inapte · **S2**

`app/Crm/Console/ConsoleAccess.php:19-31` pose la règle :

> « `users.current_workspace_id` est un simple pointeur d'affichage, modifiable par l'utilisateur
> lui-même via le sélecteur de workspace. Faire reposer une frontière RGPD sur un pointeur
> d'affichage, c'est n'avoir aucune frontière. »

Elle applique cette règle au **vivier** (`isMemberOf()` lit `user_workspaces`, `:44-52`) et
**seulement** au vivier :

```php
// :70-80
public static function businessWorkspaceId(User $user): ?string
{
    $current = $user->current_workspace_id;      // ← le pointeur d'affichage
    …
}
```

```
$ grep -rn "user_workspaces" backend/app/
ConsoleAccess.php:19,48   ConsoleController.php:54 (commentaire)
CandidatesController.php:17 (commentaire)   Models/Workspace.php:34   Models/User.php:102
```

**L'appartenance n'est vérifiée nulle part pour l'univers business** — ni dans `ConsoleAccess`, ni
dans `SetCurrentWorkspace` (`:45`, qui lit le pointeur), ni dans `ApiController::espaceCourantOuNull()`
(`:112-117`, qui retombe dessus). Toute l'étanchéité inter-clients du CRM tient donc à la valeur de
cette colonne.

**Ce qui empêche l'exploitation aujourd'hui** — et je l'ai mesuré, je ne le suppose pas : aucune
route n'écrit `current_workspace_id`. `PUT /workspace` rend 501 (`WorkspaceController.php:41`),
`PUT /users/{user}` rend 501 (`UsersController.php:77`), et le grep sur `app/` ne trouve que des
**lectures**. Il n'existe donc pas de « sélecteur de workspace » côté API. **S2 et non S0** : la
faiblesse est structurelle et documentée, l'exploitation est fermée par l'absence — accidentelle —
de la fonctionnalité qui l'ouvrirait. Le jour où quelqu'un implémente le sélecteur d'espace annoncé
par le commentaire, il ouvre la porte sans le savoir.

---

### P6-API-020 — `GET /users` ne liste pas l'équipe, mais qui pointe en ce moment vers l'espace · **S2**

`UsersController.php:31-33` : `User::query()->where('current_workspace_id', $workspaceId)`.

La table d'appartenance est `user_workspaces` (clé primaire `(user_id, workspace_id)`, colonne
`revoked_at` — `2026_05_16_000002…:73-82`). L'écran « Utilisateurs » lit l'autre colonne. Un
membre de l'espace dont le pointeur est ailleurs **n'apparaît pas dans la liste de son propre
espace** ; un membre révoqué dont le pointeur n'a pas été remis à zéro **y apparaît toujours**. La
liste est plafonnée à 200 sans pagination (`:36`).

**Impact.** C'est l'écran par lequel on révoque un accès. Il ne dit pas la vérité sur qui a accès.

---

### P6-API-021 — Pagination absente sur sept listes · **S2**

Point 8 de la grille (« pagination obligatoire sur toute liste »). Listes rendues **sans
pagination**, avec un plafond dur et silencieux :

| Route | Plafond | Fichier |
|---|---|---|
| `GET /tags` | `limit(500)` | `TagsController.php:41` |
| `GET /audiences` | `limit(200)` | `AudiencesController.php:37` |
| `GET /audiences/{a}/members` | `limit($limit)`, max 500 | `AudiencesController.php:134,149` |
| `GET /users` | `limit(200)` | `UsersController.php:36` |
| `GET /llm/use-cases` | `limit(50)` | `LlmUseCasesController.php:25` |
| `GET /proxy-providers` | `limit(50)` | `ProxyProvidersController.php:26` |
| `GET /coverage` (niveau `city`) | `LIMIT 500` en SQL | `CoverageController.php:118,133` |

Au-delà du plafond, la ligne n'existe pas pour l'utilisateur : il n'y a **ni compteur total, ni
page suivante, ni indication de troncature**. Le 501ᵉ tag est invisible et rien ne le dit.

---

### P6-API-022 — Idempotence et journal d'audit : la question reste ouverte sur les créations · **S3 (observation partielle)**

Point 12 : `POST /companies`, `POST /audiences`, `POST /campaigns`, `POST /rgpd/requests`
n'acceptent **aucune** clé d'idempotence et ne dédupliquent pas. Un double clic sur « Créer »
produit deux enregistrements (sauf sur `companies`, où `UNIQUE (workspace_id, siren)` rattrape).

Point 13 : `AuditHashChainLogger` est ajouté globalement au groupe `api`
(`bootstrap/app.php:44`), donc il couvre a priori toutes les écritures — **je n'ai pas mesuré**
son périmètre exact (quelles méthodes, quels corps, quelles exclusions). Voir §5.

---

## 3. LES TESTS EXISTANTS (point 17 de la grille)

**Le test d'étanchéité inter-espaces existe, et il couvre cinq surfaces sur une centaine.**

```
$ grep -on "/api/v1/[a-z0-9/_-]*" tests/Feature/EtancheiteUniversTest.php | sort -u
85:/api/v1/companies
85:/api/v1/contacts
104:/api/v1/crm/contacts-hub
112:/api/v1/crm/arbitrage
130:/api/v1/crm/candidates
148:/api/v1/crm/candidates

$ grep -c "journalists\|/media\|rgpd/requests\|audiences\|scraper-runs\|campaigns\|/tags" \
      tests/Feature/EtancheiteUniversTest.php
0
```

Le fichier s'ouvre pourtant sur : « Trois couches défendent cette frontière, et ce fichier vérifie
qu'**aucune ne se repose sur les autres** ». Il le vérifie sur `/companies` et `/contacts`. Il ne
le vérifie **sur aucune** des routes des constats 001 à 005. C'est l'explication mécanique de leur
survie : le garde-fou existe, sa liste de surfaces n'a jamais été alignée sur `api.php`.

**Il manque un test qui n'existe pas et qui aurait tout attrapé** : un test *paramétré par la table
de routage* — « pour chaque route `GET` qui rend une liste, un compte d'un autre espace obtient
zéro ligne ». Tant que la liste des surfaces est écrite à la main, elle divergera de `api.php` à
chaque route ajoutée.

---

## 4. TÉMOINS NÉGATIFS

Un « rien trouvé » ne vaut que si le contrôle a d'abord été prouvé capable de rendre 1.

**T1 — le grep de filtre d'espace fonctionne.** Il rend 0 dans `JournalistsController::index` ;
il rend 2 dans un fichier où le filtre existe :

```
$ grep -n "where..workspace_id" app/Http/Controllers/Api/CompaniesController.php
79:                ->where('workspace_id', $workspaceId);
189:            ->where('workspace_id', $workspaceId)
```

Le zéro de P6-API-001/002/003/004 est donc un vrai zéro.

**T2 — le grep d'invocation de policy fonctionne.** Il rend 0 appel de policy, mais il **trouve
bien** sept autres formes d'autorisation (4 `FormRequest::authorize`, 2 `Gate::define`, 1
`->can()`) : cf. §P6-API-012. Le motif n'est pas cassé.

**T3 — le grep de masquage fonctionne.** `grep -rn "MasquageCoordonnees" app/` rend **12
occurrences** dans 3 fichiers. Son zéro sur `JournalistsController`, `MediaController`,
`AudiencesController`, `PersonTimelineController`, `CandidatesController` est réel — et j'ai
**lu** ces cinq fichiers pour confirmer qu'ils rendent bien `email`/`phone` (lignes citées au
constat 006). Le témoin est doublé d'une lecture, pas seulement d'un grep.

**T4 — le grep sur `degraded` fonctionne.** 12 occurrences côté backend, 0 côté
`frontend/src/`. Les deux mesures ont été jouées avec la **même** commande sur les deux arbres.

**T5 — règle des accents respectée.** Aucun de mes contrôles ne porte sur une sous-chaîne
accentuée. J'ai cherché `notImplemented`, `degraded`, `workspace_id`, `permission:`,
`MasquageCoordonnees`, `user_workspaces`, `refuserHorsEspace` — que des identifiants ASCII. Là où
j'avais besoin d'un mot français (« verrouillé », « présent »), j'ai lu le fichier au lieu de le
grepper.

**T6 — une hypothèse que j'ai mesurée et qui s'est révélée FAUSSE.** `config/auth.php:9-14` ne
déclare **que** le garde `web` ; aucun garde `sanctum`. J'ai soupçonné que `auth:sanctum`, employé
sur toutes les routes protégées, lèverait « Auth guard [sanctum] is not defined ». Vérification :

```
$ grep -n "auth.guards.sanctum" -A 6 backend/vendor/laravel/sanctum/src/SanctumServiceProvider.php
24:            'auth.guards.sanctum' => array_merge([
25-                'driver' => 'sanctum',
26-                'provider' => null,
27:            ], config('auth.guards.sanctum', [])),
```

Sanctum déclare le garde lui-même dans son `register()`. **Pas de constat.** Je le consigne parce
qu'une passe qui ne rapporte que ses succès ne prouve pas qu'elle a cherché.

**T7 — deuxième hypothèse invalidée.** J'ai soupçonné `Crm/ArbitrageController::attach` d'écrire
sur une entreprise d'un autre espace (elle prend un `company_id` en corps de requête). Lecture de
`:115-127` : la requête est
`DB::table('companies')->where('workspace_id', $workspaceId)->where('id', $companyId)`, et
l'absence rend 404 avec la bonne justification écrite au-dessus. **Correct.** De même
`CompanyTagsBulkController:80-92` et `Crm/BulkController` : ces trois-là font exactement ce que
`bulkEnrich` ne fait pas, ce qui rend le constat 008 d'autant plus net.

---

## 5. CE QUE JE N'AI PAS PU MESURER, ET POURQUOI

Sans cette section, une couverture bornée passerait pour complète.

1. **Aucune requête HTTP réelle n'a été jouée.** Interdits d'exécution : pas de production, et
   interdiction expresse de lancer la suite PHP dans le conteneur `a35r` (un autre agent y mesure).
   `backend/vendor/` est **vide dans ce worktree** (`ls vendor/ | wc -l` → `0`), donc je ne pouvais
   pas non plus démarrer l'application localement. **Tous mes constats sont statiques.** Un constat
   comme P6-API-001 est certain au niveau du code ; son observation en 200 avec le corps fuité
   reste à faire, et c'est une mesure de dix minutes pour qui a un environnement.

2. **Points 9 (N+1) et 10 (index derrière la requête / `EXPLAIN`) : non couverts.** Ils exigent une
   base peuplée et un profileur. Je n'ai relevé que ce qui saute aux yeux en lecture — par exemple
   que `AudiencesController::members` fait deux `leftJoin` sans index cité, et que
   `CoverageController::queryCityCells` joint sur `LEFT(cm.postcode, 2) = ci.department`, une
   expression qui ne peut utiliser aucun index sur `postcode`. **Ce sont des soupçons, pas des
   mesures**, et je refuse de les compter comme constats.

3. **Point 13 (journal d'audit des écritures sensibles) : partiel.** `AuditHashChainLogger` est
   ajouté au groupe `api` (`bootstrap/app.php:44`) ; je n'ai pas lu son implémentation ni établi
   quelles méthodes/routes il enregistre réellement, ni ce qu'il fait des corps de requête (une
   trace d'audit qui recopie un mot de passe est un défaut à part entière). **À reprendre.**

4. **Les cinq contrôleurs de la console CRM v2 ne sont audités qu'en partie.** `ContactsHubController`
   (282 l.), `CandidatesController` (226 l.), `BulkController` (329 l.) : j'ai vérifié la résolution
   d'univers et le masquage, **pas** la totalité de leurs filtres ni leur pagination. Ils sont de
   toute façon derrière `crm.console_v2` (défaut `false` → 404), donc inertes en configuration par
   défaut — ce qui réduit l'urgence, pas la dette.

5. **Les contrôleurs `Internal/SiteGdprController` (104 l.) et `Internal/ZeptoMailWebhookController`
   (239 l.) n'ont été lus que sur leur garde d'authentification**, pas sur leur logique d'ingestion.
   `SiteSyncController` a été lu jusqu'à la ligne 70 seulement.

6. **Je n'ai pas croisé `api.php` avec `spec/14_api_routes_laravel.md`.** Le cahier des charges
   déclare des routes (`/scraping/search-engines`, `/llm/usage/cost-per-enrichment`,
   `/rgpd/ai-act-register`, un total de 18 routes « Scraping ») dont plusieurs **n'existent pas**
   sous ce nom dans `api.php`, et inversement. Établir cette matrice promesse↔réalité demande une
   passe entière ; c'est probablement là que se trouve le plus gros écart fonctionnel restant.

7. **`frontend/` n'a été interrogé que sur deux points** (`degraded`, `dashboard/stats`). Je ne sais
   donc pas combien des 31 bouchons sont réellement atteignables depuis la navigation — donc combien
   des 501 se traduisent par un écran cassé plutôt que par un bouton absent.

8. **La valeur réelle des drapeaux en production est inconnue de moi.** `CRM_DB_APP_ROLE_ENABLED`,
   `CRM_STRICT_WORKSPACE_SCOPE`, `CRM_CONSOLE_V2_ENABLED` : je n'ai mesuré que leurs **défauts de
   code** (`config/crm.php`) et leurs valeurs dans `.env.example` et `backend/.env` du dépôt.
   Interdiction d'interroger l'hôte. **Mes constats 001–005 sont écrits pour la configuration par
   défaut** ; si `CRM_DB_APP_ROLE_ENABLED=true` était posé sur le serveur, la RLS rattraperait
   001, 003 et 005 — mais pas 006, 007, 008, 009, 010, 011, 012. Que quelqu'un qui a l'accès pose
   la question ; elle change la sévérité de trois constats et d'aucun autre.

---

## 6. RÉCAPITULATIF

| ID | Sévérité | En une ligne |
|---|---|---|
| P6-API-001 | **S0** | `GET /journalists` rend les journalistes de tous les espaces (PII, sans permission) |
| P6-API-002 | **S0** | `GET /rgpd/requests` rend les demandes RGPD de tous les espaces |
| P6-API-003 | S1 | `GET /media` idem, et son docbloc affirme le scoping qu'il n'a pas |
| P6-API-004 | S2 | `GET /proxy-providers` idem, sans la permission `proxies.config` qui existe |
| P6-API-005 | S1 | 5 listes fail-open : pas de contexte d'espace ⇒ tout est montré |
| P6-API-006 | S1 | Le masquage `contacts.view_pii` se contourne en ouvrant une fiche |
| P6-API-007 | S1 | 10 routes destructrices/coûteuses sans aucune permission |
| P6-API-008 | S1 | `bulk-enrich` écrit sur les fiches d'un autre espace |
| P6-API-009 | S1 | 12 points d'API rendent « liste vide » sur panne ; le drapeau `degraded` n'est lu nulle part |
| P6-API-010 | S1 | Le tableau de bord affiche des zéros en dur, et le frontend les consomme |
| P6-API-011 | S1 | 31 bouchons : ni invitation d'utilisateur, ni édition de contact, ni recherche |
| P6-API-012 | S1 | 10 policies mortes, dont la comparaison d'espace est fausse sur des UUID |
| P6-API-013 | S2 | `refuserHorsEspace` laisse passer tout enregistrement sans espace |
| P6-API-014 | S3 | `GET /search` déclaré deux fois |
| P6-API-015 | S2 | `/internal/scraper-result` sans plafond de débit ni anti-rejeu |
| P6-API-016 | S3 | `verify-chain` vérifie la chaîne de tous les espaces |
| P6-API-017 | S3 | Le jeton de portabilité RGPD n'est pas « one-shot » comme annoncé |
| P6-API-018 | S2 | Un jeton d'API franchit la 2FA sans la présenter |
| P6-API-019 | S2 | L'étanchéité business tient à un pointeur que le code déclare inapte |
| P6-API-020 | S2 | `GET /users` ne liste pas l'équipe mais qui pointe vers l'espace |
| P6-API-021 | S2 | 7 listes sans pagination, plafonnées silencieusement |
| P6-API-022 | S3 | Pas d'idempotence sur les `POST` créateurs (observation partielle) |

**2 S0 · 8 S1 · 7 S2 · 5 S3.**

Le motif qui revient dans les deux tiers de ces constats est le même, et il n'est pas une
inattention : **une correction juste a été appliquée aux routes unitaires et jamais aux listes.**
`refuserHorsEspace` couvre 20 méthodes `show`/`update`/`destroy` ; aucune méthode `index`. Le
masquage couvre trois listes ; aucune fiche. Le test d'étanchéité couvre deux surfaces ; il en
existe une centaine. **Chaque fois, la garde existe, elle est bonne, et sa liste d'application a
été écrite à la main.** C'est cette liste manuelle qu'il faut remplacer par une énumération de la
table de routage — sinon le prochain lot rouvrira les mêmes trous par la même porte.
