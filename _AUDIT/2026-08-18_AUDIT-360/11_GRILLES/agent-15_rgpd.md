# AGENT 15 — Auditeur du moteur RGPD

> **Référence** : `main c0c453d`. ⚠️ `HEAD` du dépôt est **`1145473`** au moment de l'audit
> (`c0c453d..HEAD` = 4 commits, **tous `docs(cnil)`/`docs(rgpd)`** : `17ba4f1`, `b53338c`,
> `bb60473`, `1145473`). **Aucun fichier de code du périmètre n'a bougé** entre `c0c453d` et
> `HEAD` — vérifié par `git log --oneline c0c453d..HEAD`. Les mesures valent donc pour
> `c0c453d`.
>
> **Atelier** : base jetable `axion_crm_rgpd15` (clone du schéma de `axion_crm`, 101 tables),
> exécutée dans `axion-crm-api` via `docker exec -e DB_DATABASE=axion_crm_rgpd15`.
> **Aucune écriture en production, aucune purge, aucun effacement hors base jetable.**
> Le worktree `crmpro-wt-etape1a` n'a jamais été lu ni touché.
>
> **Écart d'inventaire** : le prompt annonce **104 tables**. La base en compte **101**
> (`information_schema.tables`, `table_type='BASE TABLE'`, schéma `public`).

---

## 1. Tableau de grille — tables ↔ effacement ↔ export ↔ rétention

**Périmètre du tableau** : les **31 tables** (sur 101) qui portent une donnée personnelle,
établies par balayage de `information_schema.columns` sur les motifs
`email|phone|first_name|last_name|nom|prenom|ip|address|person_key|…` **plus** les tables à
PII en texte libre ou JSON identifiées à la lecture (`activities.payload`,
`crm_notes.content`, `email_messages.body_text`, `linkedin_*`, `scraper_runs.response_payload`).
Les 70 autres tables sont des référentiels (`naf_*`, `cities`, `regions`, `legal_forms`…),
de la configuration ou de la métrique sans PII.

Légende — **E-console** = `GdprErasureService` (route `POST /rgpd/requests/{req}/process`) ·
**E-site** = `SiteGdprService::erase` (route `POST /internal/site-sync/gdpr`) ·
**Export** = `GdprPortabilityService::export` · **Rétention** = existe-t-il une purge qui
applique une durée de vie.

| # | Table | PII portée | E-console | E-site | Export art. 15/20 | Rétention |
|---|---|---|---|---|---|---|
| 1 | `contacts` | email, nom, prénom, tél, linkedin | ✅ supprime | ✅ supprime | ✅ | ⚠️ `purge-business-prospects` **inerte** (gate off) |
| 2 | `candidates` | email, nom, prénom, tél, CV, expériences | 🔴 **rien** | ✅ supprime | 🔴 **absent** | ⚠️ `purge-vivier` **inerte** (gate off) |
| 3 | `activities` | `content`, `payload` (tél, email), person_key | 🔴 **rien** (FK `SET NULL`) | ✅ supprime | 🔴 **absent** | ⚠️ via `purge-vivier` seul, inerte |
| 4 | `journalists` | email, nom, prénom, tél | ✅ anonymise + soft-delete | 🔴 **rien** | 🔴 **absent** | 🔴 **aucune** |
| 5 | `media` | email, tél, ville | ⚠️ **email seul** nullifié, **tél conservé** | 🔴 **rien** | 🔴 **absent** | 🔴 **aucune** |
| 6 | `health_practitioners` | **art. 9** : nom, prénom, spécialité, adresse, RPPS | ✅ supprime (email **ou** tél) | ⚠️ email seul | 🔴 **absent** | 🔴 **aucune** |
| 7 | `email_validations` | email | ✅ supprime | 🔴 rien | ✅ | ✅ `retention:purge` (expirées + 7 j) |
| 8 | `email_verification_logs` | email | 🔴 **rien** | 🔴 rien | 🔴 **absent** | 🔴 **aucune** |
| 9 | `rgpd_requests` | subject_email, metadata | ✅ supprime (sauf `erasure`) | ➕ **insère** | ✅ | 🔴 aucune |
| 10 | `notifications` | `body` (ILIKE email) | ✅ supprime | 🔴 rien | 🔴 absent | ✅ `retention:purge` (> 90 j) |
| 11 | `magic_links` | email, **ip** | ✅ supprime | 🔴 rien | ✅ (métadonnées) | 🔴 **aucune** |
| 12 | `unsubscribes` | email **en clair** | 🔴 **rien** | 🔴 rien | 🔴 absent | ⚪ indéfini (défendable) |
| 13 | `dnc_entries` | email, tél **en clair** | 🔴 **rien** | 🔴 rien | 🔴 absent | ⚪ indéfini (défendable) |
| 14 | `opt_out` | `email_hash`, **`phone` en clair** | ➕ **crée** (scope `business` seul) | ➕ crée (2 scopes) | 🔴 absent (E-site : oui) | ⚪ indéfini **par conception** ✅ |
| 15 | `email_suppressions` | `email_hash` (+ colonne `email` résiduelle) | 🔴 rien | 🔴 rien | 🔴 absent | ⚪ indéfini par conception ✅ |
| 16 | `email_messages` | `from_address`, `body_text` | ⚪ cascade FK (`thread_id`) | 🔴 rien | 🔴 absent | 🔴 **aucune** |
| 17 | `email_threads` | contact_id | ⚪ cascade FK | 🔴 rien | 🔴 absent | 🔴 aucune |
| 18 | `crm_notes` | `content` (tél) | ⚪ cascade FK | 🔴 rien | 🔴 absent | 🔴 aucune |
| 19 | `crm_tasks` | `description` | ⚪ cascade FK | 🔴 rien | 🔴 absent | 🔴 aucune |
| 20 | `deals` | `primary_contact_id` | ⚠️ FK **`SET NULL`** (ligne survit) | 🔴 rien | 🔴 absent | 🔴 aucune |
| 21 | `email_sends` | contact_id | ⚪ cascade FK | 🔴 rien | 🔴 absent | 🔴 aucune |
| 22 | `email_events` | cascade via `email_sends` | ⚪ cascade FK | 🔴 rien | 🔴 absent | 🔴 aucune |
| 23 | `linkedin_messages` | `content`, contact_id | ⚪ cascade FK | 🔴 rien | 🔴 absent | 🔴 aucune |
| 24 | `linkedin_invitations` | `note`, contact_id | ⚪ cascade FK | 🔴 rien | 🔴 absent | 🔴 aucune |
| 25 | `linkedin_profiles_cache` | `snapshot` (profil nominatif) | 🔴 **rien** | 🔴 rien | 🔴 absent | 🔴 **aucune** (`expires_at` non appliqué) |
| 26 | `audience_members` | contact_id | ⚪ cascade FK | 🔴 rien | 🔴 absent | 🔴 aucune |
| 27 | `companies` | adresse, tél, `email_generic` | 🔴 rien | 🔴 rien | 🔴 absent | 🔴 aucune (les 2 `prospection:purge-*` sont du nettoyage, pas de la rétention) |
| 28 | `scraper_runs` | `response_payload` (PII brute) | 🔴 rien | 🔴 rien | 🔴 absent | ✅ `retention:purge` + `prune-scraper-runs` (90 j) |
| 29 | `audit_logs` | `ip` | 🔴 rien (par conception ✅) | 🔴 rien | 🔴 absent | ⚠️ IP anonymisée à 30 j ; **la purge à 24 mois annoncée n'existe pas** |
| 30 | `sessions` | `ip_address` | 🔴 rien | 🔴 rien | 🔴 absent | ✅ IP nullifiée à 30 j |
| 31 | `users` | email, **`last_login_ip`** | 🔴 rien (cycle de vie propre) | 🔴 rien | 🔴 absent | 🔴 **`last_login_ip` jamais anonymisée** |

### Décompte

| Mesure | Couvertes | Trouées |
|---|---|---|
| **Effacement art. 17** — atteintes par au moins un des deux services (directement ou par cascade FK) | **22 / 31** | **9** : `email_verification_logs`, `unsubscribes`, `dnc_entries`, `linkedin_profiles_cache`, `companies`, `users`, `media` (tél), `deals` (ligne survit), `activities` (par la console) |
| **Effacement par la console seule** (le seul chemin réellement joignable — `SiteGdprService` est derrière `CRM_INGEST_ENABLED`) | **19 / 31** | **12** (les 9 ci-dessus + `candidates`, `activities`, `opt_out`-vivier) |
| **Export art. 15/20** | **4 / 31** (`contacts`, `email_validations`, `rgpd_requests`, `magic_links`) | **27** |
| **Rétention appliquée par une purge active** | **5 / 31** (`email_validations`, `notifications`, `scraper_runs`, `sessions`, `audit_logs`-IP) | **26** (dont 2 gérées par des purges **inertes** et 2 défendables en indéfini) |

**Preuve** : `04_PREUVES/agent-15/01_effacement-recensement.txt` (effacement réel joué,
comptage table par table avant/après), `02_portabilite-jeton.txt`, `05_purges-et-retention.txt`.

---

## 2. Grille par objet du périmètre

| Objet | Vérifié ? | Mesure |
|---|---|---|
| `GdprErasureService` | ✅ effacement **réel joué** | Touche **8 tables** : `contacts`, `email_validations`, `rgpd_requests`, `notifications`, `magic_links`, `journalists`, `media.email`, `health_practitioners`. Laisse **6 résidus** mesurés. → B15-001, B15-002, B15-006 |
| `GdprPortabilityService` | ✅ export réel joué | Exporte **4 tables** sur 31 porteuses de PII. → B15-003 |
| `SiteGdprService` | ✅ lu + couverture comparée | Couvre `contacts`, `candidates`, `activities`, `health_practitioners`, `opt_out` (2 scopes). Ne couvre pas `journalists`, `media`, `email_validations`, `magic_links`, `notifications`. Atteignable **uniquement** par `POST /internal/site-sync/gdpr`, gaté `CRM_INGEST_ENABLED`. |
| `ListeSuppression` | ✅ lu + consultation tracée | 4 points d'écriture (webhook ZeptoMail), 1 point de lecture (`EligibiliteCampagne`). Empreinte `mb_strtolower` alignée avec `SiteSyncEvent::emailHash()`. **Correct.** → B15-009 (couverture des chemins) |
| `MasquageCoordonnees` | ✅ appelants recensés (grep exhaustif) | 3 contrôleurs seulement. 5 routes rendent des PII **non masquées**. → B15-005 |
| `email_suppressions` + migration | ✅ migration lue, contraintes vérifiées **en base** | Table, index d'unicité par scope, vocabulaire fermé : **conformes**. La contrainte `email_suppressions_empreinte_obligatoire_check` promise par le code **existe réellement** (`axion_crm` et `axion_crm_test`) — *pas un constat*. |
| Permission `contacts.view_pii` + migration | ✅ migration lue, rôles vérifiés | Migration correcte (véhicule migration et non seeder, purge du cache Spatie). Accordée à owner/admin/operator, pas à viewer. **Conforme.** Mais non appliquée sur 5 routes → B15-005 |
| `retention:purge` | ✅ lu, planification vérifiée | Quotidien 04:00. **Docbloc menteur** (annonce `audit_logs` 24 mois et `llm_usage` 12 mois : absents du code). Aucun test. → B15-007, B15-008 |
| `rgpd:anonymize-ips` | ✅ lu, planification vérifiée | Quotidien 04:30. Couvre `audit_logs.ip` + `sessions.ip_address`. **Ne couvre pas `users.last_login_ip`.** Aucun test. → B15-008, B15-011 |
| `rgpd:purge-vivier` | ✅ lu, planification vérifiée | **Planifiée** mensuel le 2 à 03:30 (le prompt ne le dit pas). Gate `CRM_PURGE_ENABLED` (défaut `false`) + double verrou dans la commande. Audit écrit. **Aucun test de ce qu'elle supprime.** → B15-008 |
| `rgpd:purge-business-prospects` | ✅ lu, planification vérifiée | **Planifiée** mensuel le 2 à 04:15. Même gate, même audit. **Aucun test.** → B15-008 |
| `retention:prune-scraper-runs` | ✅ lu, planification vérifiée | **Planifiée** quotidien 04:20 (le prompt ne le dit pas). **Aucun gate, aucun dry-run, aucun audit, aucun test.** → B15-008 |
| `prospection:purge-non-commercial` | ✅ lu + rayon de souffle mesuré | Non planifiée. `WHERE legal_form IS NULL OR left(legal_form,1) <> '5'`. Aucun gate, aucun dry-run, aucune confirmation, aucun audit. Sur `axion_crm_perf4m` : **2 800 000 / 2 800 000 lignes**. → B15-004 |
| `prospection:purge-non-diffusible` | ✅ lu | Non planifiée. `WHERE denomination = '[ND]'`. Mêmes absences de garde. → B15-004 |
| `GET\|POST /rgpd/requests`, `POST /{req}/process` | ✅ middleware joué (`route:list`) | **Aucun `PermissionMiddleware`.** `rgpd.handle` défini, jamais exigé. → B15-010 |
| `GET /rgpd/export/{token}` (publique) | ✅ 3 cas joués (faux / expiré / rejoué) | 48 car. base62 (~285 bits), non énumérable, stocké haché, 404 sur faux et sur expiré, throttle `magic-link`. **Mais rejouable** (pas one-shot) et **jeton en clair persisté dans `metadata`**. → B15-012, B15-013 |
| `_REPORTS/DPIA_2026-05-17.md` | ✅ lu | **Marqué obsolète en tête**, remplacé par `AIPD_2026-08-18.md`. Son §1.4 (« aucune donnée de santé ») est faux depuis le 2026-07-04, et le document le dit lui-même. **Pas un constat nouveau** — traitement documentaire correct. |
| §21 du cahier des charges | ✅ comparé point par point | 6 exigences de §21.2 : 1 servie, 3 partielles, 2 non servies. → B15-014 |
| Anti-réinsertion bout-en-bout | ✅ **joué, avec témoin négatif** | Univers business : garde tient. Univers **vivier : la personne revient**. → **B15-001** |

---

## 3. Constats

### [B15-001] Une personne effacée par la console revient au vivier à la candidature suivante
- Sévérité      : **S0** bloquant
- Domaine       : conformité
- Référence     : main c0c453d
- Emplacement   : `backend/app/Services/Rgpd/GdprErasureService.php:91` · `backend/app/Services/Dedup/DeduplicationService.php:265-275` · `backend/app/Crm/Ingest/SiteSyncIngestService.php:112-116,495-503`
- Constat       : l'effacement console inscrit l'opposition avec le seul `scope = 'business'` (valeur `DEFAULT` de la colonne, jamais passée), alors que la garde d'ingestion interroge `scope = 'vivier'` pour un `application_submitted` — la personne effacée est ré-insérée avec son identité complète.
- Preuve        : `04_PREUVES/agent-15/03_anti-reinsertion.txt`. Après `GdprErasureService::erase()`, une seule ligne `opt_out` : `{"scope":"business","email_hash":"7bb3a4c3…","source":"gdpr_erasure"}`. Ingestion `form_submission` → `opted_out`, `contacts = 0`. Ingestion `application_submitted` → **`created`**, `candidates = 1`, `{"email":"jean.dupont@exemple-test.fr","first_name":"Jean","last_name":"Dupont","phone":"+33612345678"}`.
- Témoin négatif: même canal, mêmes deux événements, sur une personne **jamais effacée** → `pending_match` puis `created`, `candidates = 1`. Le canal fonctionne et le contrôle distingue les deux cas : le sujet effacé passe `opted_out` en business et `created` en vivier, ce que seule l'absence de ligne `scope='vivier'` explique.
- Impact        : toute personne ayant obtenu son effacement art. 17 par la console réapparaît au vivier candidats dès qu'elle redépose une candidature sur le site. Le droit à l'effacement est annulé en silence, sans trace visible. Aujourd'hui `candidates = 0` en production et `CRM_INGEST_CANDIDATES_ENABLED=false` : le défaut est **armé mais non encore déclenché**. Il se déclenchera le jour de l'ouverture du vivier.
- Reproduction  : cloner le schéma en base jetable ; semer un contact + un candidat ; `app(GdprErasureService::class)->erase($email, $phone)` ; puis `app(SiteSyncIngestService::class)->ingest(SiteSyncEvent::fromArray([...'event_type'=>'application_submitted','subject_ref'=>'site:job_application:1'...]))` ; compter `candidates`.
- Correctif     : dans `DeduplicationService::addOptOut()`, écrire **une ligne par scope** (`business` et `vivier`) — ou donner à `GdprErasureService` le même contrat que `SiteGdprService::erase(scope: 'both')`, qui écrit déjà les deux (`SiteGdprService.php:147,176`). ~1 h + test. **Le test existant ne suffira pas** : cf. B15-002.
- Statut        : ouvert

### [B15-002] `AntiReinsertionTest` est vert et mesure le mauvais objet
- Sévérité      : **S1** grave
- Domaine       : tests
- Référence     : main c0c453d
- Emplacement   : `backend/tests/Feature/Rgpd/AntiReinsertionTest.php:29-56`
- Constat       : le test qui porte le nom de la garde reproduit la requête du funnel de **scraping** (`scope = 'business'` en dur) et affirme `expect($ligne->scope)->toBe('business')` — il consacre comme correct l'exact réglage qui produit B15-001, et n'exerce jamais le canal du site.
- Preuve        : lecture du fichier ; `$vueParLaGardeDuScraping = DB::table('opt_out')->where('scope','business')->where('email_hash', …)->exists();` puis `expect(…)->toBeTrue()`. Aucun des 4 tests n'appelle `SiteSyncIngestService`.
- Témoin négatif: B15-001 prouve qu'un défaut de réinsertion existe **pendant que ce test est vert** — la démonstration directe que le contrôle ne voit pas l'objet qu'il prétend garder.
- Impact        : la garde anti-réinsertion est réputée testée. Toute revue qui s'appuie sur ce test conclut à tort. C'est le piège n°19 du dossier commun, en exemplaire.
- Reproduction  : lire le test, puis jouer B15-001.
- Correctif     : ajouter un cas qui, après `GdprErasureService::erase()`, ingère un `application_submitted` par `SiteSyncIngestService` et attend `IngestOutcome::OPTED_OUT`. ~1 h.
- Statut        : ouvert

### [B15-003] L'export art. 15/20 couvre 4 tables sur 31 porteuses de données personnelles
- Sévérité      : **S0** bloquant
- Domaine       : conformité
- Référence     : main c0c453d
- Emplacement   : `backend/app/Services/Rgpd/GdprPortabilityService.php:21-28`
- Constat       : l'export interroge `contacts`, `email_validations`, `rgpd_requests`, `magic_links` et rien d'autre — ni `candidates`, ni `activities`, ni `journalists`, ni `media`, ni `health_practitioners` (art. 9), ni `opt_out`, ni `email_messages`, ni `linkedin_*`.
- Preuve        : `04_PREUVES/agent-15/02_portabilite-jeton.txt`, section D1 — export réel joué, clés de premier niveau : `subject, exported, contacts, email_validations, rgpd_requests, magic_links_history`.
- Témoin négatif: le même recensement montre 31 tables porteuses de PII et l'effacement en atteint 8 — le comptage sait donc distinguer une table servie d'une table absente.
- Impact        : une personne qui exerce son droit d'accès reçoit un dossier qui omet sa candidature, sa timeline, ses échanges et — si elle est praticienne de santé — la donnée de l'article 9 la concernant. Manquement art. 15.1 et 20.1.
- Reproduction  : `app(GdprPortabilityService::class)->export($email)` puis `retrieve()` et lire les clés.
- Correctif     : aligner `export()` sur la couverture de `erase()` **plus** `candidates`/`activities` (que `SiteGdprService::export` sait déjà produire) ; extraire une carte « tables ↔ colonnes exportables » partagée par les deux services, pour que l'oubli devienne impossible. ~1 j + test de non-régression qui compare les deux couvertures.
- Note          : déjà relevé par lecture dans `_REPORTS/AIPD_2026-08-18.md` (« Écart 1 »). Ici **mesuré**, et le décompte 4/31 est nouveau.
- Statut        : ouvert

### [B15-004] Deux purges d'entreprises sans aucune garde ; l'une supprimerait 100 % de la base de volume
- Sévérité      : **S1** grave
- Domaine       : backend
- Référence     : main c0c453d
- Emplacement   : `backend/app/Console/Commands/ProspectionPurgeNonCommercial.php:21-25` · `ProspectionPurgeNonDiffusible.php:19-23`
- Constat       : `prospection:purge-non-commercial` exécute `DELETE FROM companies WHERE (legal_form IS NULL OR left(legal_form,1) <> '5')` sans gate, sans `--dry-run`, sans confirmation, sans entrée d'audit et sans archive préalable ; `purge-non-diffusible` fait de même sur `denomination = '[ND]'`.
- Preuve        : `04_PREUVES/agent-15/05_purges-et-retention.txt`. Mesure du rayon de souffle sur la base de volume de référence : `SELECT count(*) total, count(*) FILTER (WHERE legal_form IS NULL) nulls, count(*) FILTER (WHERE legal_form IS NULL OR left(legal_form,1)<>'5') supprimees FROM companies;` → `2800000 | 2800000 | 2800000`.
- Témoin négatif: la même requête distingue bien les trois colonnes (elle rendrait `nulls < total` sur une base où `legal_form` est renseigné) ; et les cinq autres commandes du même dossier portent, elles, `--dry-run` et/ou un gate — le contrôle sait donc voir une garde quand il y en a une.
- Impact        : une frappe au clavier (`artisan prospection:purge-non-commercial`) détruit sans retour toutes les fiches dont la forme juridique n'a pas été renseignée. Sur la base de volume, c'est la totalité. La seule réversibilité est la sauvegarde de production, que l'AIPD du 2026-08-18 décrit comme ayant échoué 91 fois sur 91 jusqu'au 2026-08-16.
- Reproduction  : lire les deux commandes ; jouer le `SELECT count(*)` ci-dessus sur `axion_crm_perf4m`.
- Correctif     : `--dry-run` par défaut, `--force` explicite pour exécuter, comptage affiché puis `confirm()`, entrée `AuditHashChain`, et retrait de la clause `legal_form IS NULL` (une forme juridique inconnue n'est pas une non-société). ~3 h.
- Réserve       : la distribution de `legal_form` **en production** (4 295 349 fiches) n'a pas pu être mesurée — production en lecture seule et non joignable depuis ce poste. Le 100 % vaut pour la base de volume synthétique, pas pour la production.
- Statut        : ouvert

### [B15-005] Le masquage des coordonnées est contournable par la route de détail
- Sévérité      : **S2** défaut
- Domaine       : sécurité
- Référence     : main c0c453d
- Emplacement   : `backend/app/Http/Controllers/Api/ContactsController.php:125-128` · `JournalistsController.php:22-47,133-136` · `MediaController.php:149-153` · `Api/Crm/CandidatesController.php:203-204`
- Constat       : `GET /contacts` masque (`MasquageCoordonnees::requis()`), mais `GET /contacts/{contact}` renvoie le modèle Eloquent brut ; `GET /journalists`, `GET /journalists/{j}`, `GET /media/{m}` et `GET /crm/candidates` ne masquent jamais.
- Preuve        : `04_PREUVES/agent-15/04_routes-permissions.txt`. Grep exhaustif de `MasquageCoordonnees` sur `app/` : 3 contrôleurs appelants seulement (`CompaniesController`, `ContactsController`, `Crm/ContactsHubController`). `ContactsController::show()` : `return $this->ok($contact);`.
- Témoin négatif: le même grep trouve bien les 3 contrôleurs qui masquent, et `ContactsController::index()` masque à la ligne 103 du même fichier — le contrôle sait distinguer une route masquée d'une route qui ne l'est pas.
- Impact        : un compte `viewer`, à qui `contacts.view_pii` est délibérément refusée, récupère l'adresse et le téléphone complets d'une personne en une requête supplémentaire, et la liste complète des journalistes sans aucun masquage. La garde documentée « les viewers ne voient pas les coordonnées complètes en liste » ne tient qu'en liste, et seulement pour deux listes sur six.
- Reproduction  : `GET /api/v1/contacts` avec un jeton `viewer` → `p***@domaine` ; `GET /api/v1/contacts/{id}` avec le même jeton → adresse complète.
- Correctif     : appliquer `MasquageCoordonnees` dans `show()` et dans les index `journalists`/`media`/`candidates` ; mieux, poser le masquage dans une ressource/`JsonResource` partagée plutôt que par contrôleur, pour que l'oubli redevienne impossible. ~4 h.
- Statut        : ouvert

### [B15-006] L'effacement laisse l'adresse et le téléphone en clair dans six tables
- Sévérité      : **S0** bloquant
- Domaine       : conformité
- Référence     : main c0c453d
- Emplacement   : `backend/app/Services/Rgpd/GdprErasureService.php:29-76`
- Constat       : après un effacement console, la personne reste identifiable en clair dans `activities` (`content` et `payload`), `media` (`phone`), `email_verification_logs` (`email`), `unsubscribes` (`email`), `dnc_entries` (`email`, `phone`) et `opt_out` (`phone`).
- Preuve        : `04_PREUVES/agent-15/01_effacement-recensement.txt`, section C — effacement réel joué, comptage avant/après. Résidus mesurés : `activities` 1→1 avec `{"content":"appele au +33612345678","payload":"{\"tel\": \"+33612345678\", \"email\": \"jean.dupont@exemple-test.fr\"}"}` ; `media` 1→1 (`email` nullifié, `phone` conservé) ; `email_verification_logs` 1→1 ; `unsubscribes` 1→1 ; `dnc_entries` 1→1 ; `opt_out` : `{"email":null,"phone":"+33612345678"}`.
- Témoin négatif: dans le même recensement, `contacts`, `journalists`, `health_practitioners`, `email_validations`, `magic_links`, `notifications`, `email_messages`, `email_threads` et `crm_notes` passent bien de 1 à 0 — le comptage sait donc constater une suppression effective.
- Impact        : `activities.contact_id` passe à `NULL` (FK `ON DELETE SET NULL`, vérifié dans `pg_constraint`) mais la ligne survit avec l'email et le téléphone lisibles dans son texte libre et son JSON. Le §21.2 du cahier des charges exige que « seule l'empreinte d'exclusion survit » : six tables la contredisent. `deals.primary_contact_id` est également `SET NULL` — l'opportunité survit à la personne.
- Reproduction  : semer le sujet dans les 16 tables, `erase()`, recompter.
- Correctif     : ajouter `activities`, `email_verification_logs` et `media.phone` à `erase()` (les trois premiers sont sans ambiguïté) ; trancher explicitement le sort de `unsubscribes` / `dnc_entries` (leur conservation peut être fondée, mais alors sous forme d'empreinte comme `opt_out` et `email_suppressions`, décision déjà prise le 2026-08-18 et non propagée à ces deux tables) ; ajouter une colonne `phone_hash` à `opt_out` — la question est déjà consignée dans `DeduplicationService` (« `phone` reste en clair … Le corriger demande une migration — consigné, non traité ici »). ~1 j.
- Statut        : ouvert

### [B15-007] `RetentionPurge` annonce deux purges qu'il n'exécute pas
- Sévérité      : **S2** défaut
- Domaine       : conformité
- Référence     : main c0c453d
- Emplacement   : `backend/app/Console/Commands/RetentionPurge.php:11-17` vs `:28-38`
- Constat       : le docbloc annonce « `audit_logs` > 24 mois → archivage S3 + suppression » et « `llm_usage` > 12 mois → archivage + suppression » ; le tableau `$tasks` ne contient que `email_validations`, `notifications` et `scraper_runs.payload`.
- Preuve        : `04_PREUVES/agent-15/05_purges-et-retention.txt`, clauses `WHERE` exactes relevées ligne à ligne. Aucune requête sur `audit_logs` ni `llm_usage` dans le fichier (`grep`).
- Témoin négatif: le même relevé trouve bien les trois requêtes qui existent — le contrôle sait lire une tâche présente.
- Impact        : la durée de conservation de 24 mois des journaux d'audit, annoncée dans la DPIA (§1.5) et dans le docbloc, n'est appliquée par aucun code. Quiconque lit le fichier pour vérifier la rétention conclut à tort qu'elle est en place.
- Reproduction  : lire les deux blocs du même fichier.
- Correctif     : soit implémenter les deux tâches, soit retirer les deux lignes du docbloc et consigner la rétention réelle. ~2 h (retrait) / ~1 j (implémentation avec archivage).
- Statut        : ouvert

### [B15-008] Sept commandes destructives, trois planifiées, zéro test de ce qu'elles suppriment
- Sévérité      : **S1** grave
- Domaine       : tests
- Référence     : main c0c453d
- Emplacement   : `backend/app/Console/Commands/{RetentionPurge,AnonymizeOldIps,PruneScraperRuns,RgpdPurgeVivier,RgpdPurgeBusinessProspects,ProspectionPurgeNonCommercial,ProspectionPurgeNonDiffusible}.php` · `backend/routes/console.php:15,16,145,148,154`
- Constat       : `retention:purge` (quotidien 04:00), `rgpd:anonymize-ips` (quotidien 04:30) et `retention:prune-scraper-runs --days=90` (quotidien 04:20) sont **planifiées et sans gate** ; `rgpd:purge-vivier` et `rgpd:purge-business-prospects` sont planifiées mensuellement derrière `CRM_PURGE_ENABLED` ; aucune des sept n'a de test vérifiant ce qu'elle supprime.
- Preuve        : `04_PREUVES/agent-15/05_purges-et-retention.txt`, tableau complet + `grep -rln` sur `tests/` avec les sept noms de commande et les sept noms de classe → une seule occurrence, `tests/Feature/Crm/SiteGdprTest.php:296`, qui vérifie uniquement que les purges **refusent** quand `CRM_PURGE_ENABLED=false`.
- Témoin négatif: ce même `grep` trouve bien `SiteGdprTest.php` — il est capable de trouver un test quand il en existe un ; et ce test prouve qu'on sait écrire un test de purge dans ce dépôt.
- Impact        : trois suppressions irréversibles tournent chaque nuit en production sans qu'aucun test ne décrive ce qu'elles doivent et ne doivent pas emporter. `retention:prune-scraper-runs` n'a même pas de `--dry-run`. Une régression de clause `WHERE` passerait toutes les gardes du dépôt sans rougir — d'autant qu'il n'existe aucun hook Git et que la CI est le seul filet.
- Reproduction  : `grep -rln "…" tests/` ; lire `routes/console.php`.
- Correctif     : un test par commande sur base de test, avec un jeu de trois lignes (à purger / à garder / limite exacte) ; ajouter `--dry-run` à `PruneScraperRuns` ; ajouter une entrée `AuditHashChain` aux trois purges quotidiennes, comme le font déjà les deux purges mensuelles. ~2 j.
- Statut        : ouvert

### [B15-009] La liste de suppression n'a qu'un seul lecteur, et aucun moteur d'envoi ne la consulte
- Sévérité      : **S3** finition
- Domaine       : canal
- Référence     : main c0c453d
- Emplacement   : `backend/app/Support/ListeSuppression.php:145-162` · `backend/app/Support/EligibiliteCampagne.php:139-166,247-262`
- Constat       : `email_suppressions` est consultée en un seul point applicatif — `EligibiliteCampagne` (`peutRecevoir()` et `appliquerPortes()`) — appelé par `CompaniesController`, `JournalistsController`, `MediaController`, `CompanyQueryFilters` et `ContactQueryFilters` ; il n'existe **aucun chemin d'envoi d'e-mail de campagne** dans le dépôt qui pourrait l'omettre.
- Preuve        : `grep -rn "ListeSuppression::"` et `grep -rn "EligibiliteCampagne::"` sur `app/` (5 appelants, tous des lectures ou des filtres de liste/export). `grep -rln "Mail::\|Mailable\|Notification"` sur `app/` → 3 fichiers seulement : `PasswordResetController`, `MagicLinkMail`, `MagicLinkService` — trois envois **transactionnels aux utilisateurs internes**, pour lesquels la liste de suppression commerciale n'a pas à s'appliquer. Aucun écrivain de `email_sends`.
- Témoin négatif: le même grep trouve bien les trois chemins d'envoi transactionnels existants — il n'est pas aveugle aux envois.
- Impact        : **la question posée n'a pas d'objet aujourd'hui** : le moteur d'envoi (lot L7) n'est pas construit, comme le docbloc d'`EligibiliteCampagne` le dit lui-même. Le constat est prospectif : `peutRecevoir()` est désigné comme « le point de passage obligé du futur moteur d'envoi » mais rien, aujourd'hui, ne peut le rendre obligatoire — pas de test de contrat, pas d'interface, pas de garde architecturale.
- Reproduction  : les deux `grep` ci-dessus.
- Correctif     : au moment de construire L7, poser une garde qui empêche d'écrire dans `email_sends` sans passer par `peutRecevoir()` (test architectural ou façade unique d'envoi). Coût nul aujourd'hui, à inscrire dans la définition de fini de L7.
- Statut        : ouvert

### [B15-010] Les routes RGPD n'exigent aucune permission : un `viewer` peut effacer et exporter n'importe qui
- Sévérité      : **S0** bloquant
- Domaine       : sécurité
- Référence     : main c0c453d
- Emplacement   : `backend/routes/api.php:213-215` · `backend/app/Http/Controllers/Api/RgpdRequestsController.php:94-123`
- Constat       : `GET /rgpd/requests`, `POST /rgpd/requests` et `POST /rgpd/requests/{req}/process` ne portent que `auth:sanctum`, `workspace` et `first-login` ; la permission `rgpd.handle`, définie et réservée à `owner`/`admin`, n'est exigée nulle part.
- Preuve        : `04_PREUVES/agent-15/04_routes-permissions.txt` — `php artisan route:list --json` joué sur le routeur réel :
  `POST api/v1/rgpd/requests/{req}/process | api,Authenticate:sanctum,SetCurrentWorkspace,EnforceFirstLoginSetup`.
  Et `grep -o "permission:[a-z._]*" routes/api.php | sort | uniq -c` → `1 permission:companies.update`, `3 permission:data.export`, **rien d'autre**.
- Témoin négatif: la même sortie `route:list` montre `PermissionMiddleware:data.export` sur `GET api/v1/journalists/export` et `GET api/v1/media/export` — le contrôle affiche bien un `PermissionMiddleware` là où il y en a un.
- Impact        : tout compte authentifié, y compris un `viewer` dont les seuls droits sont `companies.view`, `llm.view_usage` et `rgpd.view`, peut (a) déclencher un effacement sur une adresse arbitraire — suppression irréversible dans 8 tables, sans confirmation ; (b) déclencher une portabilité sur une adresse arbitraire et en récupérer l'export, contournant à la fois `data.export` et `contacts.view_pii`. La séparation des rôles construite par la migration `2026_08_15_000005` et le seeder est inopérante sur le seul écran qui manipule des droits des personnes.
- Reproduction  : `php artisan route:list --json` et lire la colonne `middleware` des trois routes ; comparer à `journalists/export`.
- Correctif     : `->middleware('permission:rgpd.view')` sur `index`, `->middleware('permission:rgpd.handle')` sur `store` et `process`. ~1 h + un test par route qui attend `403` pour un `viewer`.
- Statut        : ouvert

### [B15-011] `users.last_login_ip` n'est jamais anonymisée
- Sévérité      : **S3** finition
- Domaine       : conformité
- Référence     : main c0c453d
- Emplacement   : `backend/app/Console/Commands/AnonymizeOldIps.php:22-40` · `backend/app/Services/Auth/AuthService.php:63`
- Constat       : `rgpd:anonymize-ips` traite `audit_logs.ip` et `sessions.ip_address` ; `users.last_login_ip`, écrite à chaque connexion, n'est ni tronquée ni effacée.
- Preuve        : lecture de la commande (deux requêtes, deux tables) ; `grep -rn "last_login_ip" app/` → `User.php:25,53` et `AuthService.php:63`, aucune occurrence dans `app/Console/`.
- Témoin négatif: le même grep trouve bien `audit_logs` et `sessions` dans `AnonymizeOldIps.php` — il voit les tables réellement traitées.
- Impact        : l'adresse IP de dernière connexion de chaque utilisateur interne est conservée indéfiniment, alors que la DPIA annonce « IPs (audit + sessions) : 30 jours puis anonymisées ». Le nombre d'utilisateurs internes est faible ; l'écart est réel mais borné.
- Reproduction  : les deux lectures ci-dessus.
- Correctif     : ajouter la troisième requête à la commande. ~30 min.
- Statut        : ouvert

### [B15-012] Le jeton d'export n'est pas à usage unique, contrairement à ce que le service annonce
- Sévérité      : **S2** défaut
- Domaine       : sécurité
- Référence     : main c0c453d
- Emplacement   : `backend/app/Services/Rgpd/GdprPortabilityService.php:12-13,51-67`
- Constat       : le docbloc annonce « fournit un token téléchargement **one-shot** » ; `retrieve()` ne marque jamais le jeton comme consommé — il reste utilisable pendant les 7 jours d'expiration, et le fichier `.enc` n'est jamais supprimé après expiration.
- Preuve        : `04_PREUVES/agent-15/02_portabilite-jeton.txt`, sections D2 et D4 — trois `retrieve()` consécutifs avec le même jeton : `OK / OK / OK`. Après passage de `export_expires_at` dans le passé : `retrieve()` rend `NULL` (correct) mais `Storage::disk('local')->files('gdpr-exports')` liste toujours `gdpr-exports/LOdKlepN….enc`.
- Témoin négatif: le même essai avec un jeton faux (`str_repeat('A',48)`) rend `NULL` — la méthode sait donc refuser, et l'acceptation répétée n'est pas un artefact du montage. Aucune commande du dépôt ne nettoie `gdpr-exports/` (`grep` sur `app/Console/Commands/`).
- Impact        : un lien de portabilité intercepté (historique de navigateur, journal de proxy, transfert du courriel à un tiers) reste rejouable une semaine, et l'archive chiffrée s'accumule indéfiniment sur le disque du conteneur — contredisant la durée de conservation de 7 jours annoncée par le docbloc. Le chiffrement dépend d'`APP_KEY` : qui lit le disque et connaît la clé lit tous les exports jamais produits.
- Reproduction  : `export()` puis trois `retrieve()` avec le même jeton ; forcer `export_expires_at` dans le passé et lister `gdpr-exports/`.
- Correctif     : marquer `export_token = null` (ou poser un `export_consumed_at`) dans `retrieve()` après lecture réussie, et supprimer le fichier ; ajouter une tâche planifiée qui supprime les `.enc` dont la demande est expirée. ~3 h.
- Statut        : ouvert

### [B15-013] Le jeton d'export est persisté **en clair** dans `rgpd_requests.metadata`, que toute session peut lire
- Sévérité      : **S1** grave
- Domaine       : sécurité
- Référence     : main c0c453d
- Emplacement   : `backend/app/Http/Controllers/Api/RgpdRequestsController.php:115-122` · `backend/app/Models/RgpdRequest.php:26-33` · `backend/app/Services/Rgpd/GdprPortabilityService.php:44,48`
- Constat       : le service range délibérément `hash('sha256', $token)` dans `export_token` ; le contrôleur écrit ensuite `'metadata' => array_merge((array) $req->metadata, ['result' => $result])`, où `$result` **contient le jeton en clair** — dans la colonne voisine de la même ligne. `RgpdRequest` ne déclare aucun `$hidden`, donc `GET /rgpd/requests` sérialise `metadata`.
- Preuve        : lecture croisée des trois fichiers. `GdprPortabilityService::export()` rend `['token' => $token, 'expires_at' => …, 'size' => …]` (`:48`) ; `process()` place ce tableau sous la clé `result` de `metadata` (`:119`) et rend `$req->fresh()` (`:122`). `RgpdRequest::$fillable` inclut `metadata`, `$hidden` est absent. Preuve complémentaire : en appelant le **service seul** (sans le contrôleur), `metadata` reste `{}` — `04_PREUVES/agent-15/02_portabilite-jeton.txt` — ce qui isole le contrôleur comme l'écrivain du jeton en clair.
- Témoin négatif: le même contrôle montre que `export_token` contient bien le **haché** (`hash(clair) === export_token` vérifié) — la lecture sait distinguer la forme hachée de la forme claire.
- Impact        : le hachage du jeton, qui protège contre la lecture de la base, est annulé par la colonne d'à côté. Combiné à B15-010 (aucune permission sur `GET /rgpd/requests`), n'importe quel compte authentifié liste les demandes, y lit les jetons en clair, et télécharge les exports par la route **publique** `GET /rgpd/export/{token}` — sans jamais posséder `data.export` ni `contacts.view_pii`.
- Reproduction  : `POST /rgpd/requests` type `portability`, `POST /rgpd/requests/{id}/process`, puis `GET /rgpd/requests` et lire `metadata.result.token`.
- Correctif     : ne jamais ranger le jeton dans `metadata` — n'y mettre que `expires_at` et `size` ; rendre le jeton une seule fois dans la réponse de `process()`. Ajouter `protected $hidden = ['export_token']`. ~2 h.
- Statut        : ouvert

### [B15-014] §21.2 du cahier des charges : deux exigences non servies, trois partielles
- Sévérité      : **S1** grave
- Domaine       : conformité
- Référence     : main c0c453d
- Emplacement   : `C:\Users\willi\Downloads\axion-ia-crm-cahier-des-charges-fonctionnel-v2.md:640-647`
- Constat       : sur les six exigences du §21.2, une seule est servie sans réserve.

  | Exigence §21.2 | État mesuré |
  |---|---|
  | « Durées de conservation par catégorie, **purge automatique avec alerte préalable** » | 🔴 **non servie** — aucune alerte n'existe ; 26 tables sur 31 sans purge active (§1) |
  | « Le vivier porte une horloge propre (deux ans) **à compter de l'entrée en vivier** » | ⚠️ **partielle** — `RgpdPurgeVivier` compte depuis `COALESCE(derniere_interaction_at, created_at)` : l'horloge **repart** à chaque interaction, elle ne court pas depuis l'entrée. Et « avec information du candidat » : rien |
  | « **Export complet** des données d'une personne **en un clic**, un seul point d'entrée » | 🔴 **non servie** — 4 tables sur 31 (B15-003), et **deux** points d'entrée (console + site), pas un |
  | « Suppression définitive **avec compte rendu de ce qui a été effacé** » | ⚠️ **partielle** — le compte rendu existe (`{"deleted":{…}}`) mais l'effacement est incomplet (B15-006) et non propagé au vivier (B15-001) |
  | « L'effacement prime sur toute conservation ; **seule l'empreinte d'exclusion survit** » | 🔴 **contredite** — six tables gardent l'adresse ou le téléphone en clair (B15-006) |
  | « **Oppositions bidirectionnelles** : celui des deux outils qui reçoit l'opposition la propage à l'autre » | ⚠️ **partielle** — le sens site→CRM fonctionne ; le sens CRM→site passe par `crm:flush-outbound`, **inerte** (`CRM_OUTBOUND_ENABLED` défaut `false`, `config/crm.php:145`, double verrou commande + `skip()` du planificateur) |

- Preuve        : `04_PREUVES/agent-15/01`, `02`, `05` ; `backend/config/crm.php:121,145` ; `backend/routes/console.php:148-163` ; `backend/app/Console/Commands/RgpdPurgeVivier.php:56-62`.
- Témoin négatif: la sixième ligne montre que le contrôle sait constater un mécanisme **présent mais inerte** (il distingue « absent » de « construit et gaté ») ; la quatrième, qu'il sait reconnaître une exigence partiellement servie.
- Impact        : le produit ne peut pas être présenté comme conforme au §21.2 de son propre cahier des charges. Deux exigences (export complet, primauté de l'effacement) touchent directement des droits des personnes.
- Reproduction  : lire §21.2 ; jouer les mesures de §1 du présent rapport.
- Correctif     : traiter B15-001, B15-003 et B15-006 sert quatre des six lignes. Restent l'alerte préalable de purge (~1 j) et la décision sur l'horloge du vivier (entrée vs dernière interaction) — **question de fond pour le dirigeant, pas un correctif technique** : le code et le cahier des charges disent deux choses différentes, et c'est le cahier des charges qui est plus protecteur.
- Statut        : ouvert

---

## 4. Ce que je n'ai PAS pu vérifier, et pourquoi

1. **La distribution de `legal_form` en production** (4 295 349 entreprises) — production en lecture seule et non joignable depuis ce poste depuis la fermeture du 19/08. Le rayon de souffle de B15-004 est donc mesuré sur la base de volume synthétique `axion_crm_perf4m`, pas sur le stock réel. **C'est la mesure la plus utile qui manque à ce rapport.**
2. **Si les purges sont déjà passées en production.** Question explicite du prompt. Je n'ai pas pu l'établir : les deux purges mensuelles écrivent leur bilan dans `audit_logs` (`AuditHashChain`), mais je ne peux pas interroger `audit_logs` de production. Ce qui est établi : leur gate `CRM_PURGE_ENABLED` vaut `false` par défaut (`config/crm.php:121`) et le `skip()` du planificateur les saute tant qu'il vaut `false`. Les trois purges quotidiennes (`retention:purge`, `rgpd:anonymize-ips`, `retention:prune-scraper-runs`) n'ont **aucun** gate — elles tournent donc si le conteneur `scheduler` tourne, mais je ne peux pas le prouver côté production. **À faire vérifier par qui a l'accès** : `SELECT method, count(*), max(created_at) FROM audit_logs WHERE method LIKE '%PURGE%' GROUP BY 1;` et `docker logs axion-crm-scheduler`.
3. **La requête HTTP réelle avec et sans `contacts.view_pii`** (point 6 du prompt). Le montage était écrit et copié dans le conteneur, mais chaque amorçage de Laravel dans `axion-crm-api` prenait 5 à 10 minutes pendant la session (`axion-crm-postgres` à 473 % et `axion-crm-scheduler` à 140 % de CPU — le planificateur local traitait ses tâches `media:*`), et trois exécutions ont dépassé le délai. **B15-005 repose donc sur le grep exhaustif des appelants et la lecture des méthodes `show()`/`index()`, pas sur deux réponses HTTP comparées.** Le constat est solide (le code de `show()` est une ligne) mais il n'a pas le rang « geste réel » exigé par la doctrine. À rejouer quand la machine est au repos.
4. **La chaîne complète de B15-013 jouée de bout en bout.** Établie par lecture croisée de trois fichiers et par un témoin partiel (le service seul laisse `metadata` à `{}`). L'essai qui aurait produit la ligne `metadata` contenant le jeton en clair est celui qui n'a pas pu finir (même cause qu'au point 3).
5. **Fidélité de ma base jetable.** `axion_crm_rgpd15` porte **75 des 82** contraintes `CHECK` de `axion_crm` — sept ont été perdues au clonage (dont `opt_out_empreinte_obligatoire_check`, que j'ai d'abord prise pour un défaut avant de vérifier qu'elle **existe bien** dans `axion_crm` et `axion_crm_test`). Aucune des sept ne porte sur les chemins de suppression ou d'ingestion que j'ai mesurés, mais je le signale plutôt que de le taire.
6. **`SiteGdprService` par sa vraie route.** Mesuré par appel direct au service ; la route `POST /internal/site-sync/gdpr` (signature HMAC + `CRM_INGEST_ENABLED`) n'a pas été exercée. De même, l'ingestion de B15-001 passe par `SiteSyncIngestService::ingest()` et non par `POST /internal/site-sync` — la couche d'authentification HMAC n'est donc pas dans le périmètre de la preuve. Le chemin de décision mesuré est identique.
7. **Le contenu réel des `payload` d'`activities` en production.** J'ai prouvé que la ligne survit à l'effacement avec son texte libre ; je n'ai pas pu mesurer **combien** de lignes `activities` de production portent une adresse ou un téléphone en clair.
8. **§21.1 et §21.3 du cahier des charges** (enregistrement d'entretiens, notation automatisée). Hors périmètre : `AIPD_2026-08-18.md` établit que ce traitement **n'existe pas dans le dépôt** (aucune table, aucun service). Je n'ai pas re-cherché.

---

## 5. Note de méthode — ce que je n'ai pas re-rapporté

- La faille Postgres/Redis exposés, la rotation des secrets et la notification CNIL : hors périmètre, déjà tranchés (dossier commun §6).
- `_REPORTS/AIPD_2026-08-18.md` avait déjà relevé **par lecture** trois de mes constats : l'export incomplet (son « Écart 1 » → B15-003), la divergence des deux effacements (« Écart 2 » → B15-006), et l'impossibilité d'effacer un praticien RPPS sans e-mail (« Écart 3 »). Je les ai **joués** plutôt que de les recopier, et le décompte 4/31 comme les six tables à résidus en clair sont nouveaux.
- En revanche l'AIPD conclut, sur le droit d'opposition : « `opt_out` par univers + `email_hash` anti-réinsertion — ✅ **Mécanique correcte et bien pensée** ». **C'est la seule de ses conclusions que ma mesure contredit** (B15-001) : la mécanique est correcte pour le chemin site, et trouée pour le chemin console. C'est aussi l'apport principal de cette grille.
