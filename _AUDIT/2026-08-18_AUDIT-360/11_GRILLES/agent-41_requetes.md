# Agent 41 — Auditeur des requêtes

> Référence : `main = e8924b8` (relu par `git log` le 2026-08-19 à 13 h 30 ; le dossier commun
> annonçait `e8924b8`, c'est bien la tête au moment de la campagne).
> Preuves brutes : `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/agent-41/`.

## 0. Comment ces chiffres ont été obtenus — et ce qu'ils ne disent pas

**Où.** `docker exec axion-crm-postgres psql`, PostgreSQL 16.9. **Aucune mesure en production**
(lecture seule, pas d'accès `psql`). **Aucune écriture** dans `axion_crm` (vidée par un
`migrate:fresh`, inutilisable), ni dans `axion_crm_perf` / `axion_crm_perf4m` : ces deux bases n'ont
été lues qu'en `EXPLAIN`. Les seules écritures de cette campagne ont eu lieu dans **`axion_crm_a41`**,
une base **que j'ai créée** (`CREATE DATABASE axion_crm_a41 TEMPLATE axion_crm_perf`) conformément au
§5 bis n°2 du dossier commun, et supprimée à la fin.

**⚠️ Locale (piège 10).** Les trois bases sont en **`datcollate = C` / `datctype = C`**, comme la
production. Aucune mesure n'a été faite en `en_US.utf8`. Les temps des prédicats `lower()` / `LIKE`
ci-dessous valent donc pour la **locale de production**, pas pour celle de la CI.
Preuve : `04_PREUVES/agent-41/00-environnement.txt`.

**Volumes réellement disponibles — et c'est une limite majeure de ce rapport.**

| table | `axion_crm_perf` (« volume de référence ») | `axion_crm_perf4m` (« volume de production ») |
|---|---:|---:|
| `companies` | 300 000 | **2 800 000** |
| `contacts` | 50 000 | **0** |
| `activities` | 500 000 | **0** |
| `company_tag` | 300 000 | **0** |
| `tags` | 59 | 59 |
| `candidates`, `media`, `journalists`, `campaigns`, `scraper_runs`, `email_audiences`, `rgpd_requests` | **0** | **0** |
| `audit_logs` | 80 | 80 |
| `users` | 2 | 2 |

Autrement dit : **seul l'univers `companies` est mesurable au volume de production.** Tout ce qui
suit sur `/media`, `/journalists`, `/campaigns`, `/scraper-runs`, `/audiences`, `/users`,
`/audit-logs`, `/rgpd/requests`, `/console/vivier` est un **verdict de structure** (l'index existe-t-il ?)
et non une mesure de temps. Je le dis à chaque ligne plutôt que de laisser croire à une mesure.

**Froid / chaud.** « Froid » = **première exécution** de cette requête exacte, dans une session psql
neuve ; « chaud » = **quatrième** exécution consécutive. `shared_buffers` vaut **128 Mo** pour un jeu
de 2,1 Go : à froid les pages viennent du disque, à chaud elles sont dans le cache de pages de l'hôte.
J'ai vérifié que la méthode discrimine (témoin : `/companies` page par défaut à 2,8 M —
**551 ms** au premier passage, **0,42 ms** au deuxième). J'ai aussi vérifié qu'une éviction de
`shared_buffers` par balayage d'index **ne suffit pas** à reproduire le froid : c'est le cache de
l'hôte qui domine, et il ne se vide qu'au redémarrage du conteneur — que je n'ai pas fait pour ne pas
casser les mesures des autres agents. **« Froid » est donc « premier accès disque réel », pas
« cache totalement vide ».**

**🔴 Les temps ci-dessous sont un PLANCHER, pas un pire cas.** Trois raisons cumulatives :
1. `CRM_DB_APP_ROLE_ENABLED` vaut **`false` en local** et **`true` en production** (B11-010) : les
   plans mesurés **n'incluent pas** le prédicat de politique RLS que la production ajoute à chaque
   table. La production paye ce prédicat en plus.
2. Le volume de production réel est **4,29 M** de fiches, pas 2,8 M.
3. Ces temps sont ceux de **PostgreSQL seul**. Ils n'incluent ni l'hydratation Eloquent, ni la
   sérialisation JSON, ni le temps PHP.

**Mesure à un seul utilisateur** (§5 bis n°0). Toutes les mesures sont séquentielles, un seul client.
C'est précisément ce qui rend A-010 invisible — et c'est pourquoi la colonne « gel » existe.

**« Temps de gel de l'application ».** A-010 (S0) : la production sert toute l'API par `php -S`,
**un seul processus**. Une requête qui occupe la base pendant *t* occupe aussi l'unique travailleur
PHP pendant *t* : **pendant ce temps, aucune autre requête de aucun autre utilisateur n'est servie.**
La colonne « gel » du tableau est donc le temps de blocage de **toute l'application**, pas seulement
de l'écran concerné. Elle vaut le temps base **au minimum**.

**Comment j'ai obtenu les requêtes.** L'API locale ne répondait pas pendant la campagne
(`curl http://localhost:58080/up` → code 000 ; A-009) : je **n'ai pas pu** capturer le SQL par
`DB::listen` ni par `pg_stat_statements` (extension **non préchargée**, `shared_preload_libraries`
vide → impossible à activer sans redémarrer PostgreSQL). Les requêtes ont donc été **transcrites
depuis les contrôleurs**, ligne par ligne, avec les paramètres réellement envoyés par le SPA
(`per_page`, filtres, tri) relevés dans le code du frontend. Chaque requête mesurée est reproduite
en toutes lettres en tête de son fichier de preuve.

---

## 1. La grille — un écran par ligne

Sauf mention contraire : temps en millisecondes, `EXPLAIN (ANALYZE, BUFFERS)`, locale `C`.
Fichiers de preuve : `04_PREUVES/agent-41/<base>/<code>.txt`.

### 1.1 Volume de PRODUCTION (`axion_crm_perf4m`, 2 800 000 `companies`)

| # | Écran / geste | Requête réellement émise (source) | Nœud principal du plan | Tampons (froid) | Froid | Chaud | Index qui la sert | **Gel de l'application** |
|---|---|---|---|---|---:|---:|---|---:|
| A01 | `/companies` — le `count(*)` de la pagination | `CompaniesController::index:81` (`->paginate($perPage)`) | `Parallel Index Only Scan` sur `idx_companies_ws_counts`, `Heap Fetches: 0` | `hit=6 read=2475` | **2 193** | 830 | `idx_companies_ws_counts` (posé pour les compteurs du hub — il sert ici par accident) | **2,2 s** |
| A02 | `/companies` — la page (per_page=100, tri par défaut) | `CompaniesController::index:77-81` | `Index Scan` sur `idx_companies_workspace_score` | `hit=5 read=1` | 21 (551 au tout premier accès disque) | 0,46 | `idx_companies_workspace_score` ✅ | 0,02 s |
| A03 | `/companies?sort=denomination` | `allowedSorts('denomination')`, `CompaniesController:77` | **`Parallel Seq Scan`** + `top-N heapsort` + **JIT 1 551 ms** | `hit=4771 read=75127` (= tout le tas, 624 Mo) | **15 552** | 8 571 | **AUCUN** | **15,6 s** |
| A11 | `/companies?sort=enriched_at` | idem | **`Parallel Seq Scan`** + tri | `hit=2783 read=77115` | **93 496** | 7 213 | **AUCUN** | **93,5 s** |
| A05 | `/companies?filter[naf]=6201Z` | `CompanyQueryFilters::allowed()` | `Index Scan` sur `idx_companies_workspace_naf` | `hit=3 read=3` | 4,2 | 2,5 | `idx_companies_workspace_naf` ✅ | 0,004 s |
| A06 | `/companies?filter[cree_apres]=…` | `CompanyQueryFilters:50` | `Index Scan` sur `idx_companies_workspace_score` + filtre | `hit=6 read=286` | 702 | 19 | partiel (le tri gagne sur le filtre) | 0,7 s |
| A04 | `/companies?filter[denomination]=marti` — la page | `AllowedFilter::partial` → `LOWER("companies"."denomination") LIKE '%marti%'` | `Index Scan` + **filtre sur 2 800 000 lignes rejetées** | `hit=292 read=81743` | **43 144** | 6 667 | **AUCUN** | **43,1 s** |
| A04b | idem — le `count(*)` qui l'accompagne | idem | **`Parallel Seq Scan`** | `hit=12766 read=67052` | **22 101** | 4 630 | **AUCUN** | **22,1 s** |
| — | **A04 + A04b = un seul affichage de la liste filtrée** | | | | **65 245** | 11 297 | | **65,2 s** |
| A10 | `/companies?page=1000` (pagination profonde) | `OFFSET 99900` | `Index Scan` sur `idx_companies_workspace_score` | `read=2785` | **5 383** | 281 | index présent mais `OFFSET` payé ligne à ligne | **5,4 s** |
| A07 | **`/console/contacts` — l'écran par défaut** (`temperature=actifs`) | `ContactsHubController::applyTemperature:213` | `Index Scan` sur `idx_companies_ws_updated_id`, **`Rows Removed by Filter: 2 800 000`** | `hit=533421 read=2288631` (**2,8 M tampons**) | **188 518** | **60 118** | aucun ne peut servir : `OR` + `EXISTS` corrélé | **3 min 08 s** |
| A08 | `/console/contacts?q=marti` (une frappe) | `ContactsHubController::applySearch:234` | `Index Scan` + filtre, mêmes 2,8 M lignes rejetées | `hit=537967 read=2284085` | **61 841** | 56 624 | **AUCUN** (voir G41-002) | **61,8 s** |
| A09 | `/companies/export` — un lot de 1 000 | `CompaniesController::export:206` (`chunkById(1000)`) | `Index Scan` sur `companies_pkey` | `hit=6 read=26` | 13 | 6 | `companies_pkey` ✅ | 0,013 s |
| — | **`/companies/export` — les 2 800 lots, base seule** | boucle `chunkById` complète, rejouée | | | **113 547** | — | | **1 min 54 s** |
| — | `/console/contacts` — compteurs des pastilles | `CompteursHub::calculer` | `Parallel Index Only Scan`, `Heap Fetches: 0` | — | 4 249 | 878–1 229 | `idx_companies_ws_counts` ✅ | 4,2 s (mesure agent 10, **non refaite**) |
| — | recherche globale ⌘K (`GET /search`) | `GlobalSearchController::index:18` | **aucune requête SQL** | — | — | — | sans objet | 0 s |
| — | tableau de bord `GET /dashboard/stats` | `routes/api.php:86-98` (fermeture bouchon) | **aucune requête SQL** | — | — | — | sans objet | 0 s |

### 1.2 Volume de RÉFÉRENCE (`axion_crm_perf` : 300 000 `companies`, 50 000 `contacts`, 500 000 `activities`, 300 000 `company_tag`)

| # | Écran / geste | Nœud principal | Tampons (froid) | Froid | Chaud | Index qui la sert | **Gel** |
|---|---|---|---|---:|---:|---|---:|
| B01 | `/companies` — `count(*)` | `Index Only Scan` `idx_companies_ws_counts` | `read=268` | 1 453 | 88 | ✅ | 1,5 s |
| B02 | `/companies` — la page | `Index Scan` `idx_companies_workspace_score` | `read=7` | 447 | 0,49 | ✅ | 0,45 s |
| B03 | `/companies?sort=denomination` | **`Parallel Seq Scan`** | `read=10204` (tout le tas) | **1 928** | 1 257 | **AUCUN** | 1,9 s |
| B04 | `/companies?filter[denomination]=marti` | `Index Scan` + filtre | `read=55` | 17 | 1,8 | **AUCUN** (rapide **par chance** : 100 correspondances trouvées après 1 585 lignes) | 0,02 s |
| B05 | `/contacts` — `count(*)` | `Index Only Scan` `idx_contacts_ws_lower_last_name` | `read=43` | 802 | 28 | de biais ✅ | 0,8 s |
| B06 | `/contacts` — la page (per_page=50, tri `-id`) | `Index Scan Backward` `contacts_pkey` | `read=5` | 17 | 0,17 | `idx_contacts_ws_id_desc` prévu, `contacts_pkey` retenu ✅ | 0,02 s |
| B07 | `/contacts?sort=last_name` | **`Seq Scan`** + tri | `read=1787 written=283` | **14 545** | 185 | **AUCUN** (`idx_contacts_ws_lower_last_name` porte `lower(last_name)`, pas `last_name`) | **14,5 s** |
| B08 | `/contacts?filter[last_name]=mar` | `Index Scan Backward` `contacts_pkey` + filtre | `hit=20 read=3` | 4,5 | 0,70 | **AUCUN** (rapide par chance, même raison que B04) | 0,005 s |
| B09 | **`/console/contacts` — l'écran par défaut** | `Index Scan` `idx_companies_ws_updated_id` + **`SubPlan` qui hache les 300 000 lignes de `company_tag` à chaque exécution** | `read=2213` | **4 158** | 2 315 | aucun ne peut servir | **4,2 s** |
| B10 | `/console/contacts?q=marti` (une frappe) | idem | `hit=2232 read=1787` | **4 884** | 745 | aucun | **4,9 s** |
| B11 | `/console/arbitrage` — `count(*)` | **`Parallel Seq Scan`** sur `activities` | `read=15484` (toute la table) | **3 518** | 215 | **AUCUN** (prédicats JSONB non indexés) | **3,5 s** |
| B12 | `/console/arbitrage` — la page | **`Parallel Seq Scan`** sur `activities` | `hit=283 read=15207` | 334 | 218 | **AUCUN** | 0,33 s |
| B13 | `/console/personnes/{key}` — les activités | `Index Scan` `idx_activities_workspace_person_key` | `hit=6 read=14` | 52 | 0,24 | ✅ | 0,05 s |
| B14 | `/console/personnes/{key}` — les sujets business | jointure `contacts`↔`companies` sur `person_key` | `hit=4 read=5` | 12 | 2,2 | `idx_contacts_workspace_person_key` ✅ | 0,01 s |
| B15/16 | `/audit-logs` — `count(*)` + page | `Seq Scan` sur `audit_logs_default` | `read=4` | 1,3 / 0,3 | 0,26 / 133 | **table à 80 lignes : non concluant** | n/c |
| B17 | `/tags` — la liste | `Seq Scan` sur `tags` (59 lignes) | `hit=3 read=2` | 0,23 | 0,37 | table minuscule | 0,0002 s |
| B18 | `/tags` — le comptage par tag | **`Seq Scan` sur `company_tag` (300 000 lignes)** | `read=2206` | **863** | 1 638 | **AUCUN** (`company_tag` n'a pas d'index sur `tag_id` seul) | **0,9 s** |
| B19 | `/console/vivier` | `Index Scan` `idx_candidates_workspace_stage` | `hit=6 read=1` | 0,30 | 0,24 | **table VIDE — mesure sans valeur** (verdict de structure ci-dessous) | n/c |
| B20 | `/companies/export` — un lot de 1 000 | `Index Scan` `companies_pkey` | `read=32` | 246 | 1,9 | ✅ | 0,25 s |
| — | `/companies/export` — les 300 lots, base seule | boucle complète | — | **30 034** | — | | **30,0 s** |
| — | `/companies/export` — chargement des contacts d'un lot | `Index Scan` `idx_contacts_company` + 2 anti-jointures sur l'empreinte SHA-256 | — | 2,8 **d'exécution** mais **96,8 de PLANIFICATION** (liste `IN` de 1 000 identifiants) | — | ✅ pour l'exécution | 0,1 s **par lot** |

### 1.3 Écrans dont la requête n'a PAS pu être mesurée au volume — verdict de structure seulement

| Écran | Requête (source) | Tri / filtre par défaut | Index qui la servirait | Verdict |
|---|---|---|---|---|
| `/media` | `MediaController::index:34-37` | `defaultSort('name')`, `paginate(100)` | **aucun index sur `name`** ; et la requête **ne porte AUCUN prédicat `workspace_id`** (contrairement à `export()`, l. 115) → aucun index composite `(workspace_id, …)` ne peut servir | tri sur disque garanti dès que la table grossit |
| `/journalists` | `JournalistsController::index:34-38` | `defaultSort('last_name')`, `include=media` | **aucun index sur `last_name`** ; **aucun prédicat `workspace_id`** non plus | idem |
| `/campaigns` | `ScrapingCampaignsController::index:55-64` | `orderByRaw("CASE status …")` puis `created_at DESC` | **aucun index ne peut servir un `CASE`** | tri sur disque garanti ; + rafraîchissement automatique **toutes les 10 s** côté SPA |
| `/scraper-runs` | `ScraperRunsController::index:31-35` | `started_at DESC` + `workspace_id` | `idx_runs_workspace_started` ✅ | **le seul écran de liste correctement indexé de ce groupe** ; mais rafraîchissement **toutes les 10 s** |
| `/audiences` | `AudiencesController::index:33-37` | `created_at DESC`, `limit(200)`, **pas de pagination** | pas d'index `(workspace_id, created_at)` ; table plafonnée à 200 | tri sur disque au-delà de quelques milliers d'audiences ; **rafraîchissement toutes les 30 s** |
| `/users` | `UsersController::index:32-38` | `name ASC`, `limit(200)`, **pas de pagination** | pas d'index sur `name` | acceptable (population bornée) |
| `/audit-logs` | `AuditLogsController::index:32` | `id DESC`, `paginate(50)`, **aucun prédicat `workspace_id`** | `audit_logs_default_pkey (id, created_at)` sert le tri ; le `count(*)` de `paginate` balaye **toutes les partitions** | le `count(*)` deviendra le coût dominant ; cf. B16-004 pour le volet étanchéité |
| `/rgpd/requests` | `RgpdRequestsController::index:37-41` | `requested_at DESC`, `paginate(25)`, **aucun prédicat `workspace_id`** | **aucun index sur `requested_at`** | tri sur disque |
| `/console/vivier` | `CandidatesController::index:82` | `derniere_interaction_at DESC, id DESC` | **aucun index `(workspace_id, derniere_interaction_at)`** ; `idx_candidates_purge_clock` est partiel sur `anonymized_at IS NULL` et ne porte pas `workspace_id` | tri sur disque dès que le vivier se remplit |
| `/coverage` | `CoverageController::index:66-78` | vue matérialisée `coverage_matrix_cells` | `idx_coverage_matrix_cells_pk` ✅ pour `department`/`region` ; le niveau `city` joint sur **`LEFT(cm.postcode, 2) = ci.department`**, **expression non indexable** | voir G41-013 |
| tableau de bord | `routes/api.php:86` + `/audit-logs` + `/coverage` | — | — | `GET /dashboard/stats` est un **bouchon** : zéro requête, zéros en dur |
| recherche globale ⌘K | `GlobalSearchController::index:18` | — | — | **zéro requête** : corps figé `{companies:[], contacts:[], tags:[]}` |

### 1.4 Nombre de requêtes par écran — compté dans le code (pas d'instrumentation possible)

| Écran | Requêtes SQL au montage | N+1 ? |
|---|---:|---|
| `/companies` | **2** (`count(*)` + page) | non |
| `/contacts` | **3** (`count(*)` + page + chargement empressé `company:id,denomination`) | non — le chargement empressé est en place |
| `/console/contacts` | **3** (page en curseur — *pas* de `count(*)* — + `contacts` + `tags`) + **1** compteurs (en cache 5 min) | non |
| `/console/vivier` | **2** (page + `tags`) + **1** compteurs (**sans cache**, contrairement au hub) | non |
| `/console/arbitrage` | **2** (`count(*)` + page) | non |
| `/console/personnes/{key}` | **6** (activités ×2 univers, sujets ×2, existence ×2) | non |
| `/media`, `/journalists` | **2** (+1 si `include=media`) | non |
| `/campaigns`, `/scraper-runs`, `/rgpd/requests`, `/audit-logs` | **2** | non |
| `/tags` | **2** (liste + comptage groupé) | non — le commentaire « left join optimisé » décrit bien ce qui est fait |
| `/users`, `/audiences` | **1** | non |
| `/coverage` | **1** (+1 sonde `pg_extension` au niveau `city`) | non |
| tableau de bord | **2** en base (`/audit-logs`, `/coverage`) — `/dashboard/stats` n'en fait aucune | non |
| `/companies/export` | **3 × (nombre de lots)** = **8 400 requêtes** à 2,8 M | non, mais voir G41-007 |

**Conclusion sur le N+1 : je n'en ai trouvé aucun.** Tous les chargements de relations passent par
`with()` / une requête groupée. C'est le seul point de la grille qui soit franchement sain, et il
mérite d'être dit. Témoin négatif : le contrôle sait voir un N+1 — il l'aurait vu sur
`CompaniesController::export` si `resolveBestConfidence()` avait interrogé la base par ligne
(elle ne lit que des relations déjà chargées, l. 275-280), et sur `TagsController::index` si le
comptage avait été fait tag par tag (il est groupé, l. 45-51).

---

## 2. Ce que coûtent les index jamais lus (B10-014, chiffré)

**Le constat de départ, revérifié.** À 2 800 000 fiches : `companies` porte **624 Mo de tas** pour
**1 491 Mo d'index** (ratio 2,4 ×). Après une campagne complète de mesures — les **32 requêtes** de
tous les écrans de liste, jouées 4 fois chacune sur les deux bases — le relevé de
`pg_stat_user_indexes` (`04_PREUVES/agent-41/90-idx-scan-apres-campagne.txt`) montre que **19 des 27
index n'ont jamais été parcourus une seule fois**.

**Mais `idx_scan = 0` ne prouve pas qu'un index est mort.** Neuf de ces dix-neuf servent un filtre
que le produit expose réellement (`filter[department_code]`, `[region_code]`, `[sector_main]`,
`[size_category]`, `[prospection_status]`, `[relation_type]`, `[lifecycle_stage]`, `[naf]`) ou une
contrainte d'intégrité (`companies_workspace_id_siren_key`, 141 Mo, **à garder**). Je ne les compte
donc pas comme morts. J'ai croisé chaque index restant avec un `grep` de tout le code produit
(`04_PREUVES/agent-41/91-cout-ecriture-index.txt`). **Neuf index survivent au crible : aucune requête
du produit, d'aucun écran, d'aucune commande, ne peut les utiliser.**

| Index jamais lu **et** sans requête possible | Taille à 2,8 M | Pourquoi il est mort |
|---|---:|---|
| `idx_companies_ws_stage_updated_id` | **221 Mo** | conçu pour le hub trié par `updated_at` avec un `lifecycle_stage` fixé — mais la requête réelle du hub porte un `OR … EXISTS` qui l'interdit (A07) |
| `idx_companies_geo` (GiST) | **114 Mo** | `geo_point` n'est **qu'écrit** (`WaterfallOrchestrator:340, 490, 514`) ; **aucun `ST_DWithin` / `ST_Distance` nulle part** |
| `idx_companies_denomination_trgm` (GIN) | **110 Mo** | trigrammes sur `denomination_normalized`, alors que les deux recherches du produit portent sur la colonne **brute** : `LOWER(denomination) LIKE '%…%'` (liste) et `denomination ILIKE 'x%'` (hub). **L'index existe pour la bonne requête, sur la mauvaise colonne.** |
| `idx_companies_workspace_dept` | **30 Mo** | `(workspace_id, postcode)` — doublon sémantique de `idx_companies_dept (workspace_id, department_code)`, et aucun `filter[postcode]` exact n'existe (le filtre `postcode` est `partial`, donc `LIKE '%…%'`) |
| `idx_companies_signals` (GIN) | 7,3 Mo | aucun `@>` / `whereJsonContains('signals')` dans tout le code |
| `idx_companies_archive_reason` · `idx_companies_best_email_confidence` · `idx_companies_revalidate` · `idx_companies_workspace_country_nature` | **8 ko chacun** | ⚠️ ces quatre-là sont **vides** dans les jeux perf (aucune fiche ne porte `archive_reason`, `best_email_confidence`, `website_status='found'` ni `country_code <> 'FR'`). Leur `idx_scan = 0` ne prouve donc **rien** sur la production : je les ai inclus dans la mesure d'écriture parce qu'ils y sont, mais **je ne recommande pas de les retirer** sans les avoir vus vides en production. |
| **total retiré pour la mesure** | **482 Mo sur 1 491 Mo (32 %)**, dont **482 Mo pour les cinq premiers seuls** | |

**Ce qu'ils coûtent à l'écriture — mesuré, pas déduit.** Sur `axion_crm_a41` (ma copie jetable de
`axion_crm_perf`), quatre insertions de **50 000 fiches** chacune :

| tour | index présents | temps | par fiche |
|---|---|---:|---:|
| 2 | 18 (les 9 retirés) | **79 195 ms** | 1,58 ms |
| 3 (**témoin**) | 27 (les 9 remis, table **plus grosse** qu'au tour 2) | **195 041 ms** | 3,90 ms |
| 4 (**contre-témoin**) | 18 (retirés à nouveau, table **encore plus grosse** qu'au tour 3) | **66 143 ms** | 1,32 ms |

Le contre-témoin est décisif : à table **croissante**, l'insertion **accélère** dès qu'on retire les
neuf index, et **triple** dès qu'on les remet. L'écart n'est donc pas un effet de taille.

> **Les neuf index que personne ne lit multiplient par 2,7 le coût d'écriture de `companies`
> (+2,45 ms par fiche), et occupent 482 Mo.**
> Pour une passe de collecte de 300 000 fiches : **+12 min 15 s**. Pour le stock de 2,8 M :
> **+1 h 54**. Pour les 4,29 M de production : **+2 h 55**.

Preuve : `04_PREUVES/agent-41/91-cout-ecriture-index.txt`.

---

## 3. Verdict sur le critère 1 du §29 (« la recherche globale rend un résultat en moins de 5 secondes, à la frappe »)

**Le critère n'est pas rempli, et il ne peut pas l'être — mais pas pour une raison de performance.**

1. **La recherche globale ⌘K n'exécute aucune requête.** `GlobalSearchController::index`
   (`backend/app/Http/Controllers/Api/GlobalSearchController.php:18`) retourne
   `['companies' => [], 'contacts' => [], 'tags' => []]` **en dur**, sans jamais lire `$r->q`. Elle
   répond donc en bien moins de 5 secondes — et **elle ne trouve jamais rien**. Un critère de
   latence satisfait par une réponse vide est un critère contourné, pas rempli. (C'est le patron
   A-002 / B10-013, ici confirmé sur la recherche elle-même.)
2. **La route est déclarée deux fois** : une fermeture bouchon à `routes/api.php:99` et le
   contrôleur à `routes/api.php:207`, dans le même groupe. La seconde l'emporte. Les deux rendent le
   même corps vide : le doublon ne se voit pas, et **c'est ce qui le rend durable**.
3. **La seule recherche du produit qui interroge réellement la base** est celle du hub
   (`/crm/contacts-hub?q=…`). Mesurée : **61 841 ms à froid / 56 624 ms à chaud** au volume de
   production, **4 884 ms / 745 ms** au volume de référence. Le seuil de 5 secondes est déjà
   **atteint** au volume de référence à froid, et **dépassé d'un facteur 12** au volume de production.
4. **« À la frappe » aggrave d'un ordre de grandeur.** Ni la palette ⌘K
   (`frontend/src/components/ui/GlobalSearch.tsx:31-39`) ni le champ du hub
   (`frontend/src/features/crm-console/ContactsHubPage.tsx:68-72`) n'ont d'anti-rebond : la clé de
   requête change **à chaque caractère**. Taper « martin » = **5 requêtes** (à partir du 2ᵉ
   caractère pour ⌘K, dès le 1ᵉʳ pour le hub). Sur le hub, au volume de production, cela fait
   **5 × 61,8 s ≈ 5 minutes de gel de l'application entière** pour un mot de six lettres. Le
   `timeout: 30_000` d'axios coupera le client à 30 s — mais **PHP et PostgreSQL, eux, continuent**.

---

## 4. Les requêtes sans index — liste

**Aucun index ne peut les servir, au sens strict :**

1. `ORDER BY denomination` sur `companies` — `/companies?sort=denomination` (`allowedSorts`). **15,5 s** à 2,8 M.
2. `ORDER BY enriched_at` sur `companies` — `/companies?sort=enriched_at`. **93,5 s** à 2,8 M.
3. `LOWER(denomination) LIKE '%…%'` — `filter[denomination]`, liste **et** export. **43,1 s + 22,1 s** à 2,8 M.
4. `LOWER(postcode) LIKE '%…%'` — `filter[postcode]` (même patron, non mesuré séparément).
5. `lifecycle_stage != 'nouveau' OR EXISTS(company_tag…)` — **l'écran par défaut du hub**. **188,5 s** à 2,8 M.
6. `denomination ILIKE 'x%'` — recherche du hub. **61,8 s** à 2,8 M. ⚠️ Le commentaire de
   `ContactsHubController:220-224` affirme que « `denomination` porte un index B-tree
   (migration 2026_07_09_000004) ». **C'est faux** : cette migration crée
   `idx_companies_denom_btree (workspace_id, denomination_normalized)` — sur la colonne
   **normalisée**, jamais sur `denomination`. Le raisonnement qui justifie le choix du préfixe
   `'x%'` repose donc sur un index qui n'existe pas.
7. `ORDER BY last_name` sur `contacts` — `/contacts?sort=last_name`. **14,5 s à 50 000 contacts.**
   `idx_contacts_ws_lower_last_name` porte `lower(last_name)`, pas `last_name`.
8. `ORDER BY email_score` sur `contacts` — exposé par `allowedSorts`, aucun index.
9. `payload -> 'pending_match' IS NOT NULL AND payload -> 'arbitrage_dismissed' IS NULL` sur
   `activities` — `/console/arbitrage`, `count(*)` **et** page. **3,5 s à 500 000 activités**, et
   `activities` grandit à chaque événement du site.
10. `company_tag GROUP BY tag_id` — `/tags`. **0,9 à 1,6 s à 300 000 rattachements** ; `company_tag`
    n'a d'index que `(company_id, tag_id)` et `(workspace_id)`, **rien sur `tag_id` seul**.
11. `ORDER BY name` sur `media`, `ORDER BY last_name` sur `journalists`, `ORDER BY requested_at` sur
    `rgpd_requests`, `ORDER BY derniere_interaction_at` sur `candidates`, `ORDER BY CASE status …`
    sur `scraping_campaigns` — **aucun index**, tables vides dans les deux jeux perf.
12. `JOIN cities ci ON LEFT(cm.postcode, 2) = ci.department` — `/coverage?level=city` : jointure sur
    une **expression**, aucun index possible sans index d'expression.

---

## 5. Constats

### [G41-001] L'écran d'accueil de la console met 3 minutes au volume de production, et gèle l'application entière pendant ce temps
- Sévérité      : S0 bloquant
- Domaine       : performance
- Référence     : main e8924b8
- Emplacement   : `backend/app/Http/Controllers/Api/Crm/ContactsHubController.php:189-216` (`applyTemperature`)
- Constat       : la vue par défaut du hub (`temperature=actifs`, appliquée quand le paramètre est absent) produit un prédicat `lifecycle_stage <> 'nouveau' OR EXISTS(company_tag ⋈ tags)` qu'aucun index ne peut servir ; le plan parcourt `idx_companies_ws_updated_id` et rejette les 2 800 000 lignes une à une.
- Preuve        : `04_PREUVES/agent-41/axion_crm_perf4m/A07-hub-liste-actifs.txt` — `Rows Removed by Filter: 2800000`, `Buffers: shared hit=533421 read=2288631`, **Execution Time 188 518 ms** (froid) / **60 118 ms** (chaud). Au volume de référence avec de vrais tags : `04_PREUVES/agent-41/axion_crm_perf/B09-hub-liste-actifs.txt`, **4 158 ms / 2 315 ms**, dont un `SubPlan` qui hache **les 300 000 lignes de `company_tag` à chaque exécution**.
- Témoin négatif: la même table, la même base, le même instant : `/companies` avec son tri par défaut répond en **21 ms** (`A02`). Le contrôle sait donc distinguer une requête servie par un index d'une requête qui ne l'est pas — ce n'est pas la machine qui est lente.
- Impact        : `/console/contacts` est l'écran central de la console (conception §3a, « l'unique moteur de liste de l'univers business »). À 2,8 M il dépasse de six fois le `timeout: 30_000` du client axios : l'utilisateur voit une erreur réseau. Et par A-010 (un seul processus PHP), **les 3 minutes gèlent l'application pour tous les autres utilisateurs**, y compris ceux qui ne sont pas sur cet écran.
- Reproduction  : `docker exec axion-crm-postgres psql -U axion -d axion_crm_perf4m -c "explain (analyze,buffers) <SQL du fichier A07>"`.
- Correctif     : deux voies, à trancher. (a) **Matérialiser la température** : une colonne `is_actif` maintenue par déclencheur sur `company_tag` + `lifecycle_stage`, et un index `(workspace_id, is_actif, updated_at DESC, id DESC) WHERE deleted_at IS NULL` — la requête redevient un parcours d'index borné. Coût : 1 migration + 1 déclencheur + 1 remplissage (~1 h à 4,29 M), ~1 j. (b) **Renoncer au `OR`** : faire de « actifs » un filtre `lifecycle_stage <> 'nouveau'` seul (servi par `idx_companies_ws_stage_updated_id`, qui existe déjà et ne sert à rien aujourd'hui), et déplacer la provenance dans une vue préréglée distincte. Coût : ~0,5 j, mais c'est un changement de définition métier — **STOP & ASK Will**.
- Statut        : ouvert

### [G41-002] La recherche du hub coûte 61,8 s par caractère tapé, et le commentaire qui la justifie s'appuie sur un index qui n'existe pas
- Sévérité      : S0 bloquant
- Domaine       : performance
- Référence     : main e8924b8
- Emplacement   : `backend/app/Http/Controllers/Api/Crm/ContactsHubController.php:218-246` ; `frontend/src/features/crm-console/ContactsHubPage.tsx:55-72`
- Constat       : `applySearch` filtre par `denomination ILIKE 'terme%'` sur la colonne **brute**, alors que le seul index B-tree posé par la migration `2026_07_09_000004` porte sur `denomination_normalized` ; le champ de recherche du SPA n'a **aucun anti-rebond** et relance la requête à chaque frappe.
- Preuve        : `04_PREUVES/agent-41/axion_crm_perf4m/A08-hub-recherche-q.txt` — **61 841 ms** froid / **56 624 ms** chaud, `Rows Removed by Filter: 2800000`. Définition de l'index : `backend/database/migrations/2026_07_09_000004_add_companies_denomination_btree_index.php:31` → `CREATE INDEX … ON companies (workspace_id, denomination_normalized)`. Le commentaire contredit : `ContactsHubController.php:220-224` « `denomination` porte un index B-tree (migration 2026_07_09_000004) ».
- Témoin négatif: `/companies?filter[naf]=6201Z`, servi par `idx_companies_workspace_naf`, répond en **4,2 ms** sur la même table au même instant (`A05`).
- Impact        : taper « martin » (six lettres) déclenche six requêtes ; à 61,8 s chacune, **l'application entière est gelée ~5 minutes** pour un seul mot tapé par un seul utilisateur.
- Reproduction  : ouvrir `/console/contacts`, taper dans le champ de recherche ; ou rejouer le SQL du fichier A08.
- Correctif     : (a) poser un anti-rebond de 300 ms côté SPA (~1 h) ; (b) faire porter la recherche sur `denomination_normalized` (colonne **générée**, donc toujours cohérente) pour que `idx_companies_denom_btree` — 276 Mo déjà payés — serve enfin (~2 h) ; (c) corriger le commentaire, qui a servi de justification écrite à un choix technique faux.
- Statut        : ouvert

### [G41-003] Le filtre par dénomination de la liste entreprises coûte 65 s au volume de production, et l'index de 110 Mo censé le servir porte sur une autre colonne
- Sévérité      : S1 grave
- Domaine       : performance
- Référence     : main e8924b8
- Emplacement   : `backend/app/Support/CompanyQueryFilters.php:77` (`AllowedFilter::partial('denomination')`) → `vendor/spatie/laravel-query-builder/src/Filters/FiltersPartial.php:56` → `LOWER("companies"."denomination") LIKE '%…%'`
- Constat       : le filtre le plus utilisé de la liste entreprises produit un `LIKE` non ancré sur une expression `LOWER()` non indexée ; l'index GIN trigramme `idx_companies_denomination_trgm` (110 Mo) porte sur `denomination_normalized` et ne peut donc pas le servir.
- Preuve        : `04_PREUVES/agent-41/axion_crm_perf4m/A04-companies-filtre-denomination-partial.txt` (**43 144 ms**) et `A04b-…count…txt` (**22 101 ms**) — un seul affichage de la liste filtrée coûte **65 245 ms**. Définition de l'index : `\d companies` dans `04_PREUVES/agent-41/00-environnement.txt`.
- Témoin négatif: la même requête au volume de référence répond en **17 ms** (`B04`) — **par chance**, parce que la centaine de correspondances est trouvée après 1 585 lignes. Le plan est **le même** (`Index Scan` + filtre) : ce n'est pas la requête qui a changé, c'est la densité des correspondances. Une mesure faite au seul volume de référence aurait donc conclu « rapide » à tort — c'est exactement le piège que le §29 cherche à éviter.
- Impact        : chercher une entreprise par son nom depuis `/companies` gèle l'application une minute. Et le même filtre est appliqué à l'**export** (`CompaniesController::export:188`, requête partagée) : il s'y ajoute.
- Reproduction  : `GET /api/v1/companies?filter[denomination]=marti&per_page=100`.
- Correctif     : faire porter le filtre sur `denomination_normalized` (index B-tree existant pour le préfixe) **et** poser un index d'expression `gin (lower(denomination) gin_trgm_ops)` si la recherche « contient » doit rester (≈ 130 Mo à 2,8 M, 2 h de création `CONCURRENTLY`). ~0,5 j.
- Statut        : ouvert

### [G41-004] Deux des quatre tris exposés par la liste entreprises n'ont aucun index : 15,5 s et 93,5 s
- Sévérité      : S1 grave
- Domaine       : performance
- Référence     : main e8924b8
- Emplacement   : `backend/app/Http/Controllers/Api/CompaniesController.php:77`
- Constat       : `allowedSorts('quality_score', 'enriched_at', 'denomination', 'created_at')` expose quatre colonnes ; deux seulement (`quality_score`, `created_at`) ont un index. Les deux autres déclenchent un balayage séquentiel complet des 624 Mo de `companies` suivi d'un tri.
- Preuve        : `04_PREUVES/agent-41/axion_crm_perf4m/A03-companies-tri-denomination.txt` (**15 552 ms**, `Parallel Seq Scan`, `hit=4771 read=75127`, **JIT 1 551 ms**) et `A11-companies-tri-enriched-at.txt` (**93 496 ms**).
- Témoin négatif: le tri par défaut `-quality_score`, servi par `idx_companies_workspace_score`, répond en **21 ms** sur la même table (`A02`). Le contrôle voit donc bien la différence.
- Impact        : un tri est un geste banal. Un clic sur l'en-tête « Dénomination » gèle l'application 15 secondes ; sur « Enrichi le », 93 secondes. Atténuation mesurée : **le SPA n'envoie jamais `sort`** (`frontend/src/features/companies/CompaniesListPage.tsx` — aucun paramètre `sort` dans la requête). Le risque est donc aujourd'hui **atteignable par URL seulement** — mais l'API le publie, la documentation OpenAPI l'annonce (`@OA\Parameter(name="sort" … example="-quality_score")`), et le premier écran qui câblera un en-tête cliquable l'ouvrira sans le savoir.
- Reproduction  : `GET /api/v1/companies?sort=denomination&per_page=100`.
- Correctif     : soit retirer `denomination` et `enriched_at` de `allowedSorts` (10 min, et l'API cesse de promettre ce qu'elle ne peut pas tenir), soit poser `(workspace_id, denomination)` et `(workspace_id, enriched_at)` (+ ~300 Mo à 2,8 M, et voir G41-008 sur le coût d'écriture). **Le retrait est le bon choix par défaut.**
- Statut        : ouvert

### [G41-005] La liste contacts n'a pas de pagination, et son tri par nom déclenche un tri sur disque de 14,5 s dès 50 000 contacts
- Sévérité      : S1 grave
- Domaine       : performance / UX
- Référence     : main e8924b8
- Emplacement   : `backend/app/Http/Controllers/Api/ContactsController.php:54` ; `frontend/src/features/contacts/ContactsListPage.tsx:88`
- Constat       : le SPA demande `per_page=50` **sans jamais envoyer `page`** et n'affiche aucun contrôle de pagination : sur 1 319 567 contacts en production, l'écran ne peut montrer que les 50 premiers, définitivement. Par ailleurs `allowedSorts('last_name', 'email_score', …)` expose deux tris qu'aucun index ne sert.
- Preuve        : `04_PREUVES/agent-41/axion_crm_perf/B07-contacts-tri-last-name.txt` — `Seq Scan on contacts`, `Sort Method: top-N heapsort`, **14 545 ms** froid / 185 ms chaud, **à 50 000 lignes seulement**. Absence de pagination : `grep -n "page" frontend/src/features/contacts/ContactsListPage.tsx` ne rend qu'une occurrence, `per_page: '50'`.
- Témoin négatif: la même table, la même seconde, avec le tri par défaut `-id` servi par `contacts_pkey` : **17 ms** (`B06`). Et `/companies`, lui, **a** une pagination (`CompaniesListPage.tsx:751`) — le contrôle sait donc reconnaître une pagination quand il y en a une.
- Impact        : deux choses distinctes. (1) L'écran contacts est **inutilisable au-delà de 50 fiches** — c'est une fonctionnalité qui ment. (2) Le tri par nom, s'il est un jour câblé, gèlera l'application ~15 s à 50 000 contacts, et bien davantage à 1,3 M.
- Reproduction  : ouvrir `/contacts` sur une base peuplée ; il n'y a pas de page 2. Puis `GET /api/v1/contacts?sort=last_name&per_page=50`.
- Correctif     : câbler la pagination (le serveur la rend déjà : `meta.last_page`) — ~0,5 j ; retirer `last_name` et `email_score` de `allowedSorts`, ou poser `(workspace_id, last_name)` — 10 min / 1 h.
- Statut        : ouvert

### [G41-006] Le `count(*)` de la pagination coûte 2,2 s à chaque affichage de la liste entreprises
- Sévérité      : S1 grave
- Domaine       : performance
- Référence     : main e8924b8
- Emplacement   : `backend/app/Http/Controllers/Api/CompaniesController.php:81` (`->paginate($perPage)`)
- Constat       : `paginate()` émet toujours un `select count(*)` complet avant la page ; à 2,8 M il coûte **cent fois** le prix de la page elle-même.
- Preuve        : `04_PREUVES/agent-41/axion_crm_perf4m/A01-companies-count.txt` — **2 193 ms** froid / **830 ms** chaud, contre **21 ms / 0,46 ms** pour la page (`A02`). Au volume de référence : **1 453 ms / 88 ms** (`B01`).
- Témoin négatif: le hub, lui, utilise `cursorPaginate` (`ContactsHubController.php:70`) et **n'émet aucun `count(*)`** — la preuve que le dépôt sait faire, et que ce n'est pas une fatalité de Laravel.
- Impact        : 2,2 s de gel de l'application à **chaque** changement de page de `/companies`, pour afficher un nombre que personne ne lit à l'unité près. Le même patron vaut pour `/media`, `/journalists`, `/campaigns`, `/audit-logs`, `/rgpd/requests`, `/console/arbitrage`.
- Reproduction  : `GET /api/v1/companies?per_page=100`.
- Correctif     : `simplePaginate()` (pas de `count`, bouton « suivant » seulement) ou un total **approché** (`reltuples` / `Cache::flexible`, comme `CompteursHub` le fait déjà pour les pastilles). ~0,5 j pour l'ensemble des écrans.
- Statut        : ouvert

### [G41-007] L'export CSV n'a aucun plafond et gèle l'application au moins deux minutes au volume de production
- Sévérité      : S1 grave
- Domaine       : performance
- Référence     : main e8924b8
- Emplacement   : `backend/app/Http/Controllers/Api/CompaniesController.php:149-253` ; `backend/routes/api.php:118-121`
- Constat       : `export()` applique les filtres de la liste puis parcourt **tout** le résultat par `chunkById(1000)`, sans aucune borne de lignes ; la route ne porte qu'un `throttle:scraper-list` (cadence) et une permission (droit), jamais un plafond.
- Preuve        : `04_PREUVES/agent-41/92-cout-export-csv.txt`. Boucle `chunkById` complète, rejouée à l'identique en SQL : **113 547 ms (1 min 54 s) pour 2 800 lots / 2 800 000 fiches** à 2,8 M ; **30 034 ms pour 300 lots** à 300 000. S'y ajoutent, par lot, une requête de chargement des contacts dont la **planification seule** coûte **96,8 ms** (liste `IN` de 1 000 identifiants) — soit **~4 min 30 de planification** pour 2 800 lots — puis, en PHP, le formatage CSV de 2,8 M de lignes et un appel à `EmailConfidenceService` par ligne dépourvue de `best_email_confidence`.
- Témoin négatif: le contrôle sait mesurer un export borné : un **lot isolé** de 1 000 fiches coûte 13 ms (`A09`). C'est bien le **nombre** de lots, non borné, qui produit le temps.
- Impact        : un seul clic sur « Exporter » monopolise l'unique processus PHP (A-010) pendant **plus de dix minutes** au volume de production, base et PHP cumulés. Pendant ce temps l'application ne répond à personne. Le `throttle` à 60/min n'y change rien : **60 exports par minute sont autorisés**, et il suffit d'un.
- Reproduction  : `GET /api/v1/companies/export` sans filtre.
- Correctif     : passer l'export en tâche de fond (job Horizon + lien de téléchargement), ce que l'infrastructure permet déjà ; à défaut, un plafond explicite avec message (« au-delà de N fiches, l'export part par courriel »). ~1,5 j. **Complète B12-010 (absence totale de trace au journal) : la même route est à la fois lente et muette.**
- Statut        : ouvert

### [G41-008] Neuf index que rien ne lit multiplient par 2,7 le coût d'écriture de `companies`
- Sévérité      : S1 grave
- Domaine       : performance
- Référence     : main e8924b8
- Emplacement   : table `companies` — `idx_companies_ws_stage_updated_id`, `idx_companies_geo`, `idx_companies_denomination_trgm`, `idx_companies_workspace_dept`, `idx_companies_signals`, `idx_companies_archive_reason`, `idx_companies_best_email_confidence`, `idx_companies_revalidate`, `idx_companies_workspace_country_nature`
- Constat       : ces neuf index n'ont été parcourus par aucune des 32 requêtes d'écran jouées, et aucune requête du produit ne peut les utiliser (croisement `grep` sur `app/`, `routes/`, `database/`).
- Preuve        : `04_PREUVES/agent-41/90-idx-scan-apres-campagne.txt` (`idx_scan = 0` sur les deux bases après campagne complète) et `04_PREUVES/agent-41/91-cout-ecriture-index.txt` : 50 000 insertions en **195 041 ms** avec les 27 index, **79 195 ms** puis **66 143 ms** sans les neuf. Soit **+2,45 ms par fiche, ×2,7**. Taille : **482 Mo sur 1 491 Mo** à 2,8 M.
- Témoin négatif: le tour 4 est le contre-témoin exigé : la table y est **plus grosse** qu'au tour 3 et l'insertion y est pourtant **trois fois plus rapide**. L'écart n'est donc pas un effet de croissance de la table. Et le tour 3 (remise des index) reproduit bien le temps long : le protocole est réversible.
- Impact        : la collecte écrit par centaines de milliers. Sur une passe de 300 000 fiches, ces neuf index ajoutent **12 min 15 s** de pur travail d'écriture qui ne servira à aucune lecture. Sur le stock de 4,29 M : **~2 h 55**.
- Reproduction  : `04_PREUVES/agent-41/91-cout-ecriture-index.txt` contient le script complet, rejouable sur une copie jetable.
- Correctif     : `DROP INDEX CONCURRENTLY` sur les **cinq gros** (`ws_stage_updated_id`, `geo`, `denomination_trgm`, `workspace_dept`, `signals` — c'est là que sont les 482 Mo et l'essentiel du coût d'écriture ; ≈ 10 min, sans verrou). Les quatre petits sont **vides dans les jeux perf** : ne rien décider à leur sujet sans les avoir vus vides en production. À trancher aussi, deux cas particuliers : `idx_companies_ws_stage_updated_id` (221 Mo) redeviendrait utile si G41-001 est corrigé par la voie (b) — **le garder si ce correctif est retenu** ; `idx_companies_denomination_trgm` (110 Mo) doit être **remplacé**, pas seulement retiré, si la recherche « contient » de G41-003 est corrigée. ~0,5 j avec la décision.
- Statut        : ouvert

### [G41-009] La file d'arbitrage balaye toute la table `activities` deux fois par affichage
- Sévérité      : S2 défaut
- Domaine       : performance
- Référence     : main e8924b8
- Emplacement   : `backend/app/Http/Controllers/Api/Crm/ArbitrageController.php:51-59`
- Constat       : les prédicats `payload -> 'pending_match' IS NOT NULL` et `payload -> 'arbitrage_dismissed' IS NULL` ne sont couverts par aucun index ; `activities` n'en porte que deux, sur `(workspace_id, occurred_at)` et `(workspace_id, person_key)`. Le `count(*)` (l. 57) et la page (l. 59) balayent donc la table entière, chacun de son côté.
- Preuve        : `04_PREUVES/agent-41/axion_crm_perf/B11-arbitrage-count.txt` — `Parallel Seq Scan on activities`, `read=15484` (toute la table), **3 518 ms** froid / 215 ms chaud à 500 000 activités ; `B12-arbitrage-page.txt` — même balayage, 334 ms.
- Témoin négatif: la timeline de la fiche 360°, sur la **même table**, est servie par `idx_activities_workspace_person_key` et répond en **52 ms** froid (`B13`). `activities` n'est donc pas intrinsèquement lente : c'est ce prédicat-là qui n'a pas d'index.
- Impact        : `activities` grandit à chaque événement reçu du site, sans borne. À 500 000 lignes l'écran gèle déjà l'application 3,5 s ; le coût est linéaire.
- Reproduction  : `GET /api/v1/crm/arbitrage?per_page=50`.
- Correctif     : un index partiel `(workspace_id, occurred_at, id) WHERE subject_id IS NULL AND payload ? 'pending_match' AND NOT payload ? 'arbitrage_dismissed'` — il ne couvrira que la file réelle (quelques centaines de lignes), donc quelques kilo-octets, et il sert **le tri en même temps que le filtre**. ~2 h.
- Statut        : ouvert

### [G41-010] Quatre écrans de liste n'ont aucun prédicat `workspace_id` dans leur requête : aucun index composite ne peut les servir
- Sévérité      : S2 défaut
- Domaine       : performance
- Référence     : main e8924b8
- Emplacement   : `MediaController.php:34-37` · `JournalistsController.php:34-38` · `AuditLogsController.php:32` · `RgpdRequestsController.php:37-41`
- Constat       : ces quatre `index()` construisent leur requête **sans** `where('workspace_id', …)`, alors que leurs `export()` respectifs le posent (`MediaController.php:115`, `JournalistsController.php:105`). Toutes les tables concernées n'ont pourtant que des index **composites préfixés par `workspace_id`** (`media_workspace_type_idx`, `journalists_workspace_idx`, `audit_logs_default_workspace_id_created_at_idx`, `idx_rgpd_workspace`) : sans ce prédicat, **aucun n'est utilisable**.
- Preuve        : `grep -n "workspace" backend/app/Http/Controllers/Api/MediaController.php` → la seule occurrence de scoping est à la ligne 115, dans `export()`. Index disponibles : `04_PREUVES/agent-41/00-environnement.txt`. Les quatre tables étant vides dans les deux jeux perf, **le temps n'a pas pu être mesuré** — c'est un verdict de structure, et je ne le présente pas autrement.
- Témoin négatif: la même méthode appliquée à `/companies` (`CompaniesController.php:79`, `->where('workspace_id', $workspaceId)`) donne un `Index Scan` mesuré à 21 ms ; le contrôle montre donc bien la différence quand le prédicat est là.
- Impact        : à mesure que `media`, `journalists` et `audit_logs` se remplissent, ces quatre écrans passeront au balayage séquentiel + tri sur disque, sans qu'aucun index existant ne puisse les rattraper. Le volet **étanchéité** de ce même défaut est déjà couvert par B16-004 (journal d'audit rendu à tout compte) — je ne le rouvre pas.
- Reproduction  : ajouter des lignes à `media`, puis `GET /api/v1/media?per_page=100`.
- Correctif     : ajouter le scope explicite dans les quatre `index()`, comme `CompaniesController` et `ContactsController` le font déjà (~1 h), puis poser `(workspace_id, name)` sur `media`, `(workspace_id, last_name)` sur `journalists`, `(workspace_id, requested_at DESC)` sur `rgpd_requests` (~2 h).
- Statut        : ouvert

### [G41-011] La liste des tags recompte tous les rattachements à chaque affichage, par balayage séquentiel
- Sévérité      : S2 défaut
- Domaine       : performance
- Référence     : main e8924b8
- Emplacement   : `backend/app/Http/Controllers/Api/TagsController.php:44-51`
- Constat       : le comptage `company_tag GROUP BY tag_id` est correctement **groupé** (pas de N+1), mais `company_tag` ne porte d'index que sur `(company_id, tag_id)` et `(workspace_id)` — **rien sur `tag_id` seul** : le `whereIn('tag_id', …)` ne peut être servi et la table est balayée entièrement.
- Preuve        : `04_PREUVES/agent-41/axion_crm_perf/B18-tags-compteurs.txt` — `Seq Scan on company_tag`, `read=2206`, **863 ms** froid / **1 638 ms** chaud à 300 000 rattachements.
- Témoin négatif: la liste des tags elle-même (`B17`) répond en 0,23 ms sur la même base : c'est bien le comptage, non la liste, qui coûte.
- Impact        : `company_tag` grandit avec la collecte (un tag de provenance par fiche au minimum) ; à 4,29 M de rattachements l'écran `/tags` gèlera l'application plusieurs secondes à chaque ouverture.
- Reproduction  : `GET /api/v1/tags`.
- Correctif     : index `(tag_id)` sur `company_tag` (≈ 20 Mo à 2,8 M) — ou, mieux, un compteur mis en cache comme `CompteursHub`, puisque le nombre exact n'a pas besoin d'être à la seconde. ~2 h.
- Statut        : ouvert

### [G41-012] Deux écrans se rappellent toutes les dix secondes, sur un serveur qui ne sert qu'une requête à la fois
- Sévérité      : S2 défaut
- Domaine       : performance
- Référence     : main e8924b8
- Emplacement   : `frontend/src/features/campaigns/CampaignsListPage.tsx:59-66` · `frontend/src/features/scraping/ScraperRunsPage.tsx:212-216` · `frontend/src/features/audiences/AudiencesListPage.tsx:65-69` (30 s) · `frontend/src/features/coverage/CoveragePage.tsx:28-35` (60 s) · `frontend/src/features/dashboard/DashboardPage.tsx:80` (30 s)
- Constat       : cinq écrans posent un `refetchInterval`. Deux d'entre eux (`/campaigns`, `/scraper-runs`) se rafraîchissent **toutes les 10 secondes**, chacun avec son `count(*)` de pagination, et `/campaigns` avec un tri par expression `CASE` qu'aucun index ne peut servir.
- Preuve        : les cinq `refetchInterval` sont lisibles aux lignes citées. Le tri par `CASE` est à `backend/app/Http/Controllers/Api/ScrapingCampaignsController.php:55-64`. Tables vides dans les jeux perf : **temps non mesuré**, verdict de structure.
- Témoin négatif: les autres écrans de liste (`/companies`, `/contacts`, `/media`, …) n'ont **aucun** `refetchInterval` — le contrôle sait donc distinguer un écran qui sonde d'un écran qui ne sonde pas.
- Impact        : par A-010, chaque sondage prend l'unique processus PHP. Six onglets ouverts sur `/scraper-runs` = **36 requêtes par minute** consommées sans qu'un utilisateur ait rien demandé, et autant de créneaux volés aux requêtes réelles. Le §29 n°17 (dix sessions simultanées) devient d'autant plus inatteignable.
- Reproduction  : ouvrir `/campaigns` et observer le réseau.
- Correctif     : ne sonder que lorsqu'il y a quelque chose en cours (`status = running`), et via l'infrastructure temps réel déjà présente (`axion-crm-reverb`) plutôt que par sondage. ~1 j.
- Statut        : ouvert

### [G41-013] La carte de couverture repose sur une vue matérialisée non peuplée, et son niveau « ville » joint sur une expression non indexable
- Sévérité      : S2 défaut
- Domaine       : performance
- Référence     : main e8924b8
- Emplacement   : `backend/app/Http/Controllers/Api/CoverageController.php:105-130` · `backend/app/Console/Commands/CoverageRefreshMatrix.php:24-25` · `backend/routes/console.php:12`
- Constat       : `coverage_matrix_cells` est une vue matérialisée rafraîchie **toutes les heures**, en mode **non concurrent** par défaut ; dans les deux jeux perf elle n'a **jamais été peuplée** (`REFRESH MATERIALIZED VIEW` requis pour la lire). Le niveau `city` de l'écran joint `cities` sur `LEFT(cm.postcode, 2) = ci.department`, une expression qu'aucun index ne peut servir.
- Preuve        : `04_PREUVES/agent-41/93-coverage-matview.txt` — sur ma copie `axion_crm_a41` (450 000 fiches) le `REFRESH MATERIALIZED VIEW` coûte **7 800 ms** et produit 17 100 cellules ; extrapolé à 4,29 M, de l'ordre de **75 s**, toutes les heures. Sur `axion_crm_perf` et `axion_crm_perf4m`, toute lecture rend `ERROR: materialized view "coverage_matrix_cells" has not been populated`.
- Témoin négatif: une fois rafraîchie, la requête `level=department` est bien servie par `idx_coverage_matrix_cells_pk` et répond en **16 ms** — le contrôle sait donc voir que la structure est bonne quand la vue est peuplée. (Elle rend malgré tout 0 ligne, parce que les référentiels `departments` et `cities` sont **vides** dans le jeu perf : **je ne peux pas conclure sur le contenu de l'écran**, seulement sur ses temps.)
- Impact        : un rafraîchissement non concurrent prend un verrou `ACCESS EXCLUSIVE` sur la vue ; toute requête `/coverage` arrivant pendant ces ~75 s **attend le verrou**, et par A-010 gèle l'application entière pour la même durée. `/coverage` est appelé par la page `/coverage` **et** par le tableau de bord (`TopDeptsCard.tsx:19`, avec une clé de cache distincte, donc **sans mutualisation**).
- Reproduction  : `docker exec … psql -d <copie> -c "REFRESH MATERIALIZED VIEW coverage_matrix_cells;"`.
- Correctif     : `REFRESH MATERIALIZED VIEW CONCURRENTLY` (l'index unique requis existe déjà : `idx_coverage_matrix_cells_pk`) — le drapeau est déjà prévu dans la commande, il suffit qu'il soit posé par défaut : ~30 min. Pour le niveau `city`, poser un index d'expression `((left(postcode, 2)))` ou stocker `dept_code` dans la vue (il y est déjà !) et joindre dessus : ~1 h.
- Statut        : ouvert

### [G41-014] Le critère 1 est contourné, pas rempli : la recherche globale n'exécute aucune requête, et sa route est déclarée deux fois
- Sévérité      : S1 grave
- Domaine       : backend / UX
- Référence     : main e8924b8
- Emplacement   : `backend/app/Http/Controllers/Api/GlobalSearchController.php:18` · `backend/routes/api.php:99` et `:207` · `frontend/src/components/ui/GlobalSearch.tsx:31-39`
- Constat       : `GET /search` rend `{companies: [], contacts: [], tags: []}` en dur sans lire `q` ; la route est déclarée deux fois dans le même groupe (une fermeture bouchon et le contrôleur), les deux rendant le même corps vide.
- Preuve        : lecture du contrôleur (5 lignes de corps) et de `routes/api.php`. Aucune requête SQL n'est émise : **rien à mesurer**, et c'est le constat.
- Témoin négatif: la palette est bien câblée côté SPA (elle appelle `/search?q=…` dès 2 caractères, affiche trois groupes, navigue vers `/companies/$id`) : l'interface fonctionne, seul le serveur est vide. Le contrôle aurait donc vu une vraie recherche s'il y en avait eu une.
- Impact        : le critère 1 du §29 (« un résultat en moins de 5 secondes, à la frappe ») est **satisfait en apparence et faux en substance**. Pire : la seule recherche qui interroge réellement la base — celle du hub — met **61,8 s** au volume de production (G41-002). **Le critère 1 est donc doublement manqué : là où il répond vite il ne cherche rien, là où il cherche il ne répond pas.**
- Reproduction  : `⌘K`, taper n'importe quoi → « Aucun résultat ».
- Correctif     : implémenter la recherche sur `denomination_normalized` (préfixe, index existant) + `contacts.person_key`/`email` + `tags.slug`, avec un anti-rebond de 300 ms et un `LIMIT 10` par groupe. Retirer la déclaration en double. ~1,5 j. **Ce constat prolonge A-002 / B10-013 : la recherche fait partie des 9 routes qui rendent 200 avec un corps figé.**
- Statut        : ouvert

### [G41-015] La pagination profonde de la liste entreprises coûte 5,4 s à la page 1 000
- Sévérité      : S3 finition
- Domaine       : performance
- Référence     : main e8924b8
- Emplacement   : `backend/app/Http/Controllers/Api/CompaniesController.php:81` · `frontend/src/features/companies/CompaniesListPage.tsx:198, 751`
- Constat       : le SPA propose une pagination par numéro de page (`Pagination page={page} lastPage={lastPage}`), qui se traduit par un `OFFSET`. À `per_page=100` et 2,8 M de fiches, il y a **28 000 pages**, et l'`OFFSET` est payé ligne par ligne.
- Preuve        : `04_PREUVES/agent-41/axion_crm_perf4m/A10-companies-pagination-profonde.txt` — `OFFSET 99900`, `Index Scan`, `read=2785`, **5 383 ms** froid / 281 ms chaud.
- Témoin négatif: la page 1 de la même requête coûte 21 ms (`A02`) : le surcoût est bien celui de l'`OFFSET`, pas celui de la requête.
- Impact        : modéré — personne ne va à la page 1 000 à la main. Mais le composant `Pagination` affiche `lastPage`, donc **l'invite existe**, et le coût croît linéairement avec le numéro de page.
- Reproduction  : `GET /api/v1/companies?page=1000&per_page=100`.
- Correctif     : pagination par curseur (le hub le fait déjà, `cursorPaginate`) ou plafonnement visible du nombre de pages. ~0,5 j — à faire en même temps que G41-006, qui touche le même appel.
- Statut        : ouvert

---

## 6. Ce que je n'ai PAS pu vérifier, et pourquoi

1. **Aucune mesure en production.** Pas d'accès `psql` à `api.axion-crm-pro.com`, et le mandat
   l'interdit en écriture. Tous les chiffres viennent des bases perf locales. Le volume réel de
   production est **4,29 M** de fiches, soit **1,53 ×** le jeu `perf4m` : les temps mesurés sont à
   majorer d'autant, au minimum.
2. **`contacts`, `activities`, `company_tag` sont VIDES dans `axion_crm_perf4m`.** Les écrans qui en
   dépendent (hub avec tags, fiche 360°, arbitrage) ne sont donc mesurés au volume de production que
   pour leur part `companies`. La mesure A07 (188 s) est faite avec un `company_tag` vide : le
   sous-plan y est haché une fois pour rien. Avec de vrais rattachements, le sous-plan coûte **en
   plus** (visible en B09 : 2,6 s rien que pour hacher 300 000 lignes). **Le chiffre de 188 s est
   donc un plancher, pas un plafond.**
3. **`media`, `journalists`, `campaigns`, `scraper_runs`, `email_audiences`, `rgpd_requests`,
   `candidates` sont VIDES dans les deux jeux.** Sept écrans de liste n'ont donc **aucune mesure de
   temps** — seulement un verdict de structure (§1.3). Les combler demanderait d'écrire un jeu de
   données de référence pour chacun : c'est un chantier en soi, hors de mon périmètre.
4. **`audit_logs` ne contient que 80 lignes** dans les deux jeux : le `count(*)` de `paginate(50)`
   sur une table **partitionnée** n'a pas pu être mesuré à un volume qui signifie quelque chose.
5. **Aucune mesure HTTP.** L'API locale ne répondait pas (`curl http://localhost:58080/up` → 000 ;
   A-009). Je n'ai donc mesuré **que la base**, jamais le temps de bout en bout : l'hydratation
   Eloquent, la sérialisation JSON et le temps PHP s'ajoutent à tous les chiffres de ce rapport.
6. **Aucun comptage instrumenté des requêtes par écran.** `pg_stat_statements` n'est **pas préchargé**
   (`shared_preload_libraries` vide) et l'activer exige un redémarrage de PostgreSQL, que je n'ai pas
   fait pour ne pas casser les mesures des autres agents. `DB::listen` supposait une API joignable.
   Le tableau §1.4 est donc un **comptage sur le code**, pas une capture.
7. **La RLS n'est pas armée en local** (`CRM_DB_APP_ROLE_ENABLED=false`, B11-010) : mes plans
   n'incluent pas le prédicat de politique que la production ajoute. **Tous mes temps sont des
   planchers.** Je ne les présente pas comme équivalents à la production.
8. **`track_io_timing` est à `off`** : les tampons `BUFFERS` donnent le nombre de blocs, jamais le
   temps d'E/S. Impossible de séparer proprement le coût disque du coût CPU.
9. **Le « froid » n'est pas un cache vide.** Le cache de pages de l'hôte Docker ne se vide qu'au
   redémarrage du conteneur, que je n'ai pas fait. « Froid » = premier accès disque réel de cette
   requête. J'ai vérifié qu'une éviction de `shared_buffers` par balayage d'index **ne reproduit
   pas** le froid, et je le dis plutôt que de laisser croire à un protocole plus strict qu'il ne l'est.
10. **Les compteurs du hub n'ont pas été re-mesurés** (consigne du mandat). Je reprends les chiffres
    de l'agent 10 tels quels : 4 249 ms à froid / 878–1 229 ms à chaud à 2,8 M.
11. **Le contenu de `/coverage` reste inconnu** : les référentiels `departments` et `cities` sont
    vides dans les jeux perf, la jointure rend 0 ligne même après rafraîchissement. Je ne conclus
    que sur les temps, pas sur ce que l'écran affiche.
12. **`axion_crm_a41`**, la copie jetable créée pour la mesure d'écriture, a été supprimée après
    archivage des sorties. Le script est dans `04_PREUVES/agent-41/91-cout-ecriture-index.txt` et
    rejouable tel quel sur une nouvelle copie.
