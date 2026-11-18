# CAHIER — G1 « Contacts » et G2 « Mes tâches » (+ Affaires côté serveur)

**Pour le développeur :** dépôt public. Dans le code, les tests, les commits et les PR : écrire « le fondateur », jamais de nom ; données fictives en `*.example.test`. Aucun drapeau touché : `ENVOI_REEL_ACTIF`, `ORIGINES_LECTURE_ENABLED`, `CRM_GUIDE_LIEN_ENABLED`, `USINE_AGREGATS_CRM_ENABLED` restent tels quels, `config/envoi.php` inchangé. **Aucun fichier `backend/app/Envoi/**` touché.** Aucune suppression définitive : corbeille DOUCE seulement (`deleted_at`), toujours restaurable. Base relevée : `main` 7906511 (VERDICTS-CODE-2 #615). Modèle de forme : `docs/envoi/lots/X1-L4-P2-cahier.md` (branche `conception/materialisation`). **Vérifier chaque numéro de ligne avant de s'y fier.**

Plan maître : vague G, lignes G1 et G2, et « CORRECTIONS APRÈS VÉRIFICATION OPUS » (elles priment) : « D5 utilise les tâches existantes (activities, CompanyTachesController) : G2 = écran « Mes tâches » seulement ». Les Affaires sont donc livrées **après** « Mes tâches », en deux petits lots séparés (G2-C, G2-D), qu'on peut retarder sans rien bloquer.

## 0. Ordre de fusion (une fusion à la fois, chaque PR ≤ 400 lignes tests compris)

| Ordre | Lot | Contenu | Condition pour fusionner |
|---|---|---|---|
| 1 | **G0** | migration seule : 2 index partiels (`000100` contacts en corbeille, `000110` tâches) | rien |
| — | *déploiement* | G0 **déployé**, `migrate:status` sans `Pending` | obligatoire avant G1-B et G2-A |
| 2 | **G1-A** | modifier un contact (`PATCH /crm/contacts/{id}`, et l'ancien `PUT /contacts/{id}` qui rendait 501) | rien (aucun nouveau schéma) |
| 3 | **G1-B** | retirer (corbeille douce), restaurer, lister la corbeille (+ l'ancien `DELETE /contacts/{id}`) | G0 déployé, G1-A fusionnée |
| 4 | **G1-C** | ajout rapide (`GET /crm/contacts/doublons`, `POST /crm/contacts`) | G1-B fusionnée |
| 5 | **G1-D** | import (`controle`, `import`, suivi) | G1-C fusionnée |
| 6 | **G1-E** | fiche sur la page personne (`fiche`), appel, rappel, annonce `gestes_fiches`, export | G1-D fusionnée |
| 7 | **G2-A** | « Mes tâches » : `GET/POST /crm/taches`, `PATCH /crm/taches/{id}`, `PUT /crm/taches/rappel-matin` (réglage refusé proprement) | G0 déployé |
| 8 | **G2-B** | écran « Mes tâches » ouvert hors démo + entrée de menu | G2-A fusionnée |
| 9 | **G2-C** | migration seule `000150` : colonnes `etape`, `raison_perte`, `titre` sur `deals` | G2-B fusionnée (ou plus tard) |
| — | *déploiement* | G2-C **déployé** | obligatoire avant G2-D |
| 10 | **G2-D** | Affaires : `GET /crm/affaires`, `POST /crm/affaires`, `PATCH /crm/affaires/{id}` + écran ouvert hors démo | G2-C déployé |

Pourquoi « migration seule » d'abord : **le déploiement remplace le code AVANT de migrer** (`.github/workflows/deploy-direct-ssh.yml:443` puis `:561`, constat du plan maître). Un code qui lit un index ou une colonne nouvelle doit arriver après le schéma.

Numéros de migration réservés : `2026_12_08_000100` à `000190` (aucun fichier `2026_12_08_*` sur `main`, vérifié ; G3 partage le préfixe du jour : G3 prend `000200+`).

---

## 1. Constats vérifiés dans le code (ils changent la conception)

1. **Les deux routes « contacts » d'écriture rendent 501.** `ContactsController::update` (`backend/app/Http/Controllers/Api/ContactsController.php:177-185`) et `::destroy` (`:195-203`) appellent `notImplemented('5')` (`ApiController.php:190-197`, code 501). Routes : `routes/api.php:261-264`, déjà sous `permission:companies.update` / `companies.delete`. Aucun écran ne les appelle (les écrans appellent `/crm/contacts/*`, constat 2).
2. **Les écrans de démonstration appellent des routes qui n'existent pas.** La garde frontend `frontend/tests/non-regression/routes.test.ts:68-111` les fige dans `ECARTS_CONNUS` ; le test exige l'ÉGALITÉ (`:231`, `toEqual`). Donc **chaque lot serveur doit (a) régénérer `nr20-routes.json` et (b) retirer de `ECARTS_CONNUS` exactement les lignes qu'il sert**, sinon la CI rougit. Lignes concernées :
   - contacts (`:70-80`) : `GET /crm/contacts/corbeille`, `GET /crm/contacts/doublons`, `PATCH /crm/contacts/{p}`, `POST /crm/contacts`, `POST /crm/contacts/export`, `POST /crm/contacts/import`, `POST /crm/contacts/import/controle`, `POST /crm/contacts/{p}/restaurer`, `POST /crm/contacts/{p}/retirer`, `POST /crm/persons/{p}/appels`, `POST /crm/persons/{p}/rappel` ;
   - tâches et affaires (`:106-111`) : `GET /crm/affaires`, `GET /crm/taches`, `PATCH /crm/affaires/{p}`, `PATCH /crm/taches/{p}`, `POST /crm/taches`, `PUT /crm/taches/rappel-matin`.
   - Remarque : `GET /crm/contacts/import/{id}` (`ImportContacts.tsx:104`) n'est pas dans la liste car l'appel est écrit sur deux lignes ; G1-D le crée quand même.
3. **Les gestes « Contacts » ne s'affichent que si le serveur les annonce.** `ContactsHubPage.tsx:192` lit `gestes_fiches === true` dans les compteurs ; seul le faux serveur l'envoie (`frontend/src/demo/handlers/crm/contacts.handlers.ts:455-458`). Le vrai `ContactsHubController::counts` (`backend/app/Http/Controllers/Api/Crm/ContactsHubController.php:121-128`) ne l'envoie pas. De même, les actions de la page personne ne s'affichent que si la réponse d'historique porte `fiche` (`PersonTimelinePage.tsx:33`, `:99`) : `PersonTimelineController::show` (`:75-98`) ne la rend pas. **Conséquence utile : on peut livrer les routes une à une, l'écran réel ne les montre qu'au dernier lot (G1-E) qui pose `gestes_fiches: true`.**
4. **« Mes tâches » et « Affaires » ne vivent qu'en démo.** `frontend/src/features/crm/taches/disponible.ts:11-13` (`VITE_DEMO === '1'`) ; hors démo, page « en attente ». Routes front : `routeTree.tsx:397-398`. Appels : `taches/api.ts:18` (GET), `:47` (PATCH `{faite}`), `:73` (POST `{titre, contexte?, echeance?, heure?}`), `:81` (PUT `rappel-matin {actif}`) ; `affaires/api.ts:10` (GET), `:18` (PATCH `{etape, raison_perte?}`).
5. **Le contact est déjà en corbeille douce côté modèle.** `App\Models\Contact` porte `SoftDeletes` (`backend/app/Models/Contact.php:72`) avec le commentaire « posé AVANT que le premier appelant n'existe » (`:66-70`). Mais le rôle applicatif a `DELETE` sur toutes les tables (`2026_08_14_000001_harden_workspace_isolation.php:398`) : **un `forceDelete()` ou un `DB::table('contacts')->delete()` effacerait vraiment**. Test de source obligatoire (§ 9).
6. **Le registre des retraits n'écoute que la suppression définitive.** `contacts_memoriser_retrait` est `AFTER DELETE` (`2026_09_29_000001_federations.php:482-484`, corps refait en `2026_09_30_000001…:85-120`). Une corbeille douce ne l'alimente pas : c'est voulu (rien n'est perdu, tout est restaurable).
7. **Il y a déjà des contacts en corbeille qui NE doivent JAMAIS être restaurés par la console** : les mises à l'écart RGPD (`App\Crm\Rgpd\MiseAEcart::contacts`, `MiseAEcart.php:53-66`, et `App\Crm\Presse\LienJournalisteContact.php:116-120`) posent `deleted_at` ET `metadata.mis_a_l_ecart_le`. Restaurer l'un d'eux reprendrait contact avec une personne qui s'est opposée. **Règle : la console ne liste et ne restaure QUE ce qu'elle a elle-même retiré** (marque `metadata.retire_console_le`), et refuse tout contact portant `mis_a_l_ecart_le`.
8. **Unicité qui inclut la corbeille.** `contacts` : `UNIQUE (workspace_id, normalized_hash)` (`2026_05_16_000003_create_companies_contacts_scraping_schema.php:96`), `normalized_hash` = nom + prénom + `company_id` (`:77-79`), sans condition sur `deleted_at`. Un ajout (ou une modification du nom) identique à un contact en corbeille lèverait `23505`. Il faut le prévoir (409 « une fiche identique est dans la corbeille : la restaurer »). `company_id` est `NOT NULL` (`:74`) : **un contact a toujours une entreprise** ; l'ajout rapide ne crée jamais d'entreprise.
9. **L'ingestion retrouve aussi les contacts en corbeille et ne les ressuscite pas.** `ContactUpserter::upsert` (`backend/app/Crm/Ingest/ContactUpserter.php:55-92`) cherche sans filtre `deleted_at` et met à jour sans toucher `deleted_at`. Un contact retiré reste retiré même si le site le renvoie. Rien à changer ; un test le fige (G1-B, test 7).
10. **L'envoi ignore déjà un contact en corbeille.** `LecteurFicheDb::lire` filtre `c.deleted_at IS NULL` (`backend/app/Envoi/Sequence/LecteurFicheDb.php:42`, `:98`) ; `SelectionDestinataires.php:378` et `:413` aussi. Retirer un contact suffit donc à l'écarter de l'envoi, **sans toucher `app/Envoi`**.
11. **Le modèle de droits.** Rôles semés (`database/seeders/PermissionsAndRolesSeeder.php:64-69`) : `viewer` = `companies.view` ; `operator` = + `create`, `update`, `data.export`, `contacts.view_pii` (« CRUD sans destruction ») ; `admin`/`owner` = + `companies.delete`. Il n'existe pas de permission `contacts.*` d'écriture (`routes/api.php:255-258`, commentaire). Modèle de la correction #600 : **permission sur la route ET `Gate::forUser(...)->authorize(...)` dans le contrôleur** (`Api/Crm/BulkController.php:48-50`), politique par permission (`CompanyPolicy::actionDeMasse`, `app/Policies/CompanyPolicy.php:14-17`). ⚠️ `BasePolicy` raisonne par RÔLES (`BasePolicy.php:19-42`), pas par permissions : ne pas s'en servir, ajouter des méthodes explicites par permission.
12. **La corbeille par la console a un précédent exact** : listes manuelles, `DELETE` + `POST …/restaurer` avec `->withTrashed()` et `permission:companies.delete` (`routes/api.php:422-426`), `ListesManuellesController::restaurer` (`:174-186`), journal `AuditLogger::log` (`:432-440`). Suivre ce modèle.
13. **Les tâches existent déjà, dans `activities`.** `kind = 'task'`, `due_at`, `done_at` (`2026_05_16_000007_create_phase2_scaffold_schema.php:128-139` ; colonnes `kind`, `subject_type`, `subject_id`, `title`, `payload`, `external_ref` : `2026_08_14_000004…:106-113`). Écrivains : `CompanyTachesController::store` (`:49-92`, `subject_type='company'`, `user_id = $request->user()`) et `PersonnesController::storeTache` (`:277-325`, `subject_type='personne'`). Clôture : `terminer` (`CompanyTachesController.php:94-112`), `terminerTache` (`PersonnesController.php:327-351`). `task`, `call` et `stage_changed` sont déjà dans `Taxonomy::ACTIVITY_KINDS` (`app/Crm/Taxonomy.php:298-345`) : **aucune migration de vocabulaire**. La table `crm_tasks` existe (`2026_05_17_000010…:197`) mais n'est utilisée par aucun code : **ne pas l'utiliser**.
14. **Aucun index ne sert « mes tâches ».** Index de `activities` : `external_ref` unique, `person_key`, `(workspace_id, occurred_at DESC)` (`2026_08_14_000004…:121-123`), `(workspace_id, created_at)` partiel (`2026_09_24_000001…:283`), « à rattacher » (`2026_10_03_000040…`). `activities` est la timeline de tout l'espace : sans index, la liste relirait toute la timeline. D'où G0.
15. **Il n'y a pas d'« assigné ».** `activities.user_id` est le créateur (`ON DELETE SET NULL`). « Mes tâches » = celles que j'ai créées **+ celles créées par le système** (`user_id IS NULL`, par ex. D5 « pas maintenant »). L'attribution à un autre utilisateur est une décision (ESCALADE 3).
16. **La table `deals` existe, sous RLS, sans aucun code.** `2026_05_16_000007…:114-126` (`company_id NOT NULL`, `value_eur`, `probability`, `expected_close`, `status IN ('open','won','lost','dormant')`) ; `pipeline_id`, `lost_reason_id` (`2026_05_17_000010…:174-175`) ; RLS (`2026_05_16_000008_enable_rls_policies.php:29`) ; seul usage applicatif : `FusionFiches` déplace `deals.company_id` (`app/Crm/Doublons/FusionFiches.php:104`, `:133`). ⚠️ `activities.deal_id` est `ON DELETE CASCADE` (`2026_05_16_000007…:131`) : **effacer une affaire effacerait ses activités**. Donc AUCUNE route de suppression d'affaire (« perdu » est l'état final). ⚠️ `deal_history` n'a pas de `workspace_id` (`2026_05_17_000010…:177-185`) : ne pas l'utiliser (fuite entre espaces) ; tracer dans `activities` (`kind='stage_changed'`, `subject_type='deal'`).
17. **Les 7 étapes de l'écran** (`a_contacter, contacte, repondu, rendez_vous, devis, gagne, perdu`, `frontend/src/features/crm/affaires/etapes.ts`) n'existent pas en base (`status` n'a que 4 valeurs). D'où G2-C (colonne `etape`, `status` recalculé).
18. **Les routes `/crm/*`** sont sous `crm-console` (`routes/api.php:556`, `EnsureCrmConsoleV2`, `bootstrap/app.php:137`) et travaillent dans l'espace BUSINESS : `ConsoleController::businessWorkspace` (`app/Http/Controllers/Api/Crm/ConsoleController.php:41-50`, 403 sinon), puis `WorkspaceContext::run` (modèle : `PersonnesController.php:291`). Chercher les lignes par `{id}` numérique **avec `workspace_id = espace` explicite**, 404 sinon (jamais 403 : pas d'oracle d'existence). Ne PAS utiliser la résolution automatique `Contact $contact` : `refuserHorsEspace` laisse passer une ligne sans espace (`ApiController.php:111-113`).
19. **Outils à réutiliser (lecture seule, pas de modification) :** `CleDePersonne::pour($email)` (`app/Crm/Identite/CleDePersonne.php:108`) pour `person_key` ; `StatutOpposition::pourLignes` (`app/Crm/Opposition/StatutOpposition.php:35`, fichier chaud, on l'APPELLE seulement) ; `contacts_retires_contient(uuid, siren, prenom, nom)` (`2026_09_29_000001…:419`, EXECUTE donné au rôle applicatif) ; recherche d'entreprise par nom : `denomination_normalized` + index `idx_companies_denom_btree (workspace_id, denomination_normalized)` (`2026_07_09_000004…:31`) ; `MasquageCoordonnees` pour masquer adresse et téléphone (`ContactsController.php:124`, `:130`) ; `AuditLogger::log` (`app/Support/AuditLogger.php:39`).

---

## 2. LOT G0 — migration seule (deux index partiels)

### 2.1 Fichiers autorisés
- Créer `backend/database/migrations/2026_12_08_000100_contacts_index_corbeille_console.php`
- Créer `backend/database/migrations/2026_12_08_000110_activities_index_taches.php`
- Créer `backend/tests/Feature/Crm/IndexContactsTachesTest.php`
- Modifier les instantanés `tests/Feature/NonRegression/instantanes/` que la commande de `LISEZ-MOI.md` fait bouger (NR-17 au moins), avec « Filet modifié : pourquoi » dans la PR.

### 2.2 Contenu
Modèle exact : `2026_10_03_000040_activities_index_a_rattacher.php` (`$withinTransaction = false`, `CONCURRENTLY`, index INVALIDE de CE nom retiré puis reconstruit, `lock_timeout`, garde `Schema::hasTable`). `down()` : `DROP INDEX CONCURRENTLY IF EXISTS` de l'index seul (aucune donnée).
- `000100` : `idx_contacts_corbeille_console ON contacts (workspace_id, deleted_at DESC, id DESC) WHERE deleted_at IS NOT NULL AND metadata ? 'retire_console_le'`.
- `000110` : `idx_activities_taches ON activities (workspace_id, due_at, id) WHERE kind = 'task'`.
⚠️ Le prédicat de chaque index doit être recopié MOT POUR MOT dans les requêtes de G1-B et G2-A (sinon l'index ne sert pas, sans erreur ; leçon écrite dans `2026_10_03_000040`).

### 2.3 Tests (rouges d'abord)
1. Les deux index existent, sont valides (`pg_index.indisvalid`), avec le bon prédicat (`pg_get_indexdef`).
2. Rejouer `up()` ne casse rien (idempotent) ; `down()` puis `up()` (NR-17).
3. `EXPLAIN` (sans `ANALYZE`) des deux requêtes de lecture (§ 4.3 et § 8.3, écrites telles quelles dans le test) **sous `pgsql_app`** avec `WorkspaceContext` : le plan cite l'index (avec `SET LOCAL enable_seqscan = off` pour un petit jeu de test).

**Mutation :** prédicat changé (`deleted_at IS NOT NULL` seul) → test 1 rouge.

---

## 3. LOT G1-A — modifier un contact

### 3.1 Fichiers autorisés
- Créer `backend/app/Crm/Contacts/GestesContact.php` (méthode `modifier` dans ce lot)
- Créer `backend/app/Http/Controllers/Api/Crm/ContactsGestesController.php` (méthode `modifier`)
- Modifier `backend/app/Http/Controllers/Api/ContactsController.php` : `update` (`:177-185`) délègue à `GestesContact::modifier` (fin du 501)
- Modifier `backend/app/Policies/ContactPolicy.php` : ajouter `modifier(User): bool` = `hasPermissionTo('companies.update')`
- Modifier `backend/routes/api.php` : UNE ligne dans le groupe `crm` (`:556+`) : `Route::patch('/contacts/{contactId}', [ContactsGestesController::class, 'modifier'])->whereNumber('contactId')->middleware('permission:companies.update');`
- Modifier `tests/Feature/NonRegression/instantanes/nr20-routes.json` (une ligne) et `frontend/tests/non-regression/routes.test.ts` (retirer `PATCH /api/v1/crm/contacts/{p}`)
- Créer `backend/tests/Feature/Crm/ContactsModifierTest.php`

### 3.2 Comportement
Champs acceptés (tous facultatifs, au moins un) : `first_name` (≤ 120), `last_name` (1-120, jamais vide : colonne `NOT NULL`), `email` (adresse valide ≤ 254 ou `null`), `fonction` (≤ 200, écrit dans `role`), `phone` (≤ 40), `lifecycle_stage` → **refusé dans ce lot** (422 `champ_non_modifiable` : l'étape vit sur l'entreprise, `companies.lifecycle_stage` ; l'écran l'envoie, `PersonTimelinePage.tsx:354` : on l'ignore s'il est égal à l'étape actuelle de l'entreprise, sinon 422 avec le message « l'étape se change sur l'entreprise »). Jamais modifiables : `company_id`, `workspace_id`, `person_key`, `legal_basis`, `consent_*`, `sources`, `metadata`, `deleted_at`.
- Espace : `businessWorkspace`, `WorkspaceContext::run`, `SELECT … FOR UPDATE` du contact `WHERE workspace_id = ? AND id = ? AND deleted_at IS NULL` ; absent → 404.
- Changement d'adresse : recalculer `person_key = CleDePersonne::pour($email)` (null si pas de secret : laisser l'ancienne) ; poser `field_origins` du champ à « déclaré » comme `ContactUpserter` le fait (`declaredOrigins`). Si la nouvelle adresse est en opposition (`StatutOpposition::pourLignes`), la modification passe mais la réponse porte `opposition: {opposee: true, motifs}` (l'opposition reste appliquée à l'envoi ; on n'efface rien).
- Changement de nom qui heurte l'unicité (constat 8) : intercepter `23505` → 409 `{error: 'doublon', message: 'Une autre fiche porte déjà ce nom dans cette entreprise (peut-être dans la corbeille).'}`. Jamais d'exception SQL brute.
- Journal : `AuditLogger::log('contact.modifie', ['workspace_id', 'resource_type' => 'contact', 'resource_id', 'champs' => [noms des champs]])` — **noms de champs seulement, jamais les valeurs**.
- Réponse : la projection `present()` de `ContactsController` (adresse et téléphone masqués si requis), plus `opposition`.
- Contrôleur : `Gate::forUser($this->currentUser($request))->authorize('modifier', Contact::class)` en première ligne (modèle `BulkController.php:48-50`). L'ancien `PUT /contacts/{contact}` fait de même puis délègue.

### 3.3 Tests (rouges d'abord ; HTTP réel, modèle `tests/Feature/Droits/ActionDeMasseRouteTest.php`)
1. `viewer` → 403 sur `PATCH /api/v1/crm/contacts/{id}` ET sur `PUT /api/v1/contacts/{id}` ; la ligne est **identique** avant/après (relue en base), aucune ligne `audit_logs`.
2. `operator` → 200, champs écrits, `updated_at` bougé, journal sans valeur (chercher l'adresse factice dans `audit_logs` : 0 occurrence).
3. Contact d'un AUTRE espace (`admin` de A sur un contact de B) → 404, contact de B intact.
4. Contact en corbeille → 404.
5. `last_name` vide → 422 ; `company_id` envoyé → ignoré (relu inchangé).
6. Nom identique à un contact en corbeille de la même entreprise → 409, aucune exception, aucune ligne modifiée.
7. `PUT /api/v1/contacts/{id}` ne rend plus 501 (`PasDeStub501SousCrmEtAnalyticsTest` reste vert).
8. **Deux rôles** : le service `GestesContact::modifier` joué sous `pgsql_app` (`SET LOCAL ROLE` + `WorkspaceContext`, modèle `tests/Feature/Envoi/SchemaLienCampagneSequenceTest.php:77-89`) et sous le propriétaire : même résultat pour l'espace A ; contact de B → introuvable dans les deux modes (sous `pgsql_app` la RLS seule suffirait : le test prouve que le filtre explicite suffit aussi sous le propriétaire).

**Mutations :** `Gate::authorize` retiré ET middleware retiré → 1 ; filtre `workspace_id` retiré → 3 (mode propriétaire) ; filtre `deleted_at` retiré → 4 ; interception `23505` retirée → 6.

---

## 4. LOT G1-B — retirer (corbeille douce), restaurer, corbeille

### 4.1 Fichiers autorisés
- Modifier `backend/app/Crm/Contacts/GestesContact.php` (`retirer`, `restaurer`, `corbeille`)
- Modifier `backend/app/Http/Controllers/Api/Crm/ContactsGestesController.php`
- Modifier `backend/app/Http/Controllers/Api/ContactsController.php` : `destroy` (`:195-203`) délègue à `retirer`, rend 204 (fin du 501)
- Modifier `backend/app/Policies/ContactPolicy.php` : `retirer(User)` = `hasPermissionTo('companies.delete')`
- Modifier `backend/routes/api.php` (groupe `crm`, AVANT `/contacts/{contactId}` pour que `corbeille` ne soit pas pris pour un identifiant) :
  `GET /contacts/corbeille` (`permission:companies.view`), `POST /contacts/{contactId}/retirer` et `POST /contacts/{contactId}/restaurer` (`permission:companies.delete`, `whereNumber`)
- `nr20-routes.json` (3 lignes), `routes.test.ts` (retirer les 3 lignes correspondantes)
- Créer `backend/tests/Feature/Crm/ContactsCorbeilleTest.php`

### 4.2 Comportement
- **Retirer** : dans une transaction, contact de l'espace, non retiré, `FOR UPDATE` ; puis UNE requête :
  `UPDATE contacts SET deleted_at = now(), metadata = COALESCE(metadata,'{}'::jsonb) || jsonb_build_object('retire_console_le', to_char(now() AT TIME ZONE 'Europe/Paris','YYYY-MM-DD"T"HH24:MI'), 'retire_console_par', :user) WHERE workspace_id = :ws AND id = :id AND deleted_at IS NULL`.
  **Jamais** `->delete()` sur le modèle (SoftDeletes ferait la même chose mais sans la marque), **jamais** `forceDelete`, **jamais** `DB::table('contacts')->delete()`. Déjà retiré → 200 idempotent `{retire: true}`. Journal `contact.retire` (identifiant seulement). `TotalListe`/compteurs : si `CompteursHub` met les totaux en cache, l'oublier comme `CompaniesController.php:904-907`.
- **Restaurer** : refus 409 si `metadata ? 'mis_a_l_ecart_le'` (mise à l'écart RGPD, constat 7) ; refus 404 si la ligne n'a pas `retire_console_le` (la console ne restaure que ce qu'elle a retiré) ; refus 409 si l'entreprise est en corbeille (« restaurer d'abord l'entreprise »). Sinon `deleted_at = NULL`, retirer les deux clés de marque, ajouter `restaure_console_le`. Journal `contact.restaure`.
- **Corbeille** : `WHERE workspace_id = :ws AND deleted_at IS NOT NULL AND metadata ? 'retire_console_le'` (prédicat de l'index G0, mot pour mot), `ORDER BY deleted_at DESC, id DESC LIMIT 200`. Réponse `{data: [{id, person_key, first_name, last_name, email (masquée si requis), organisation, retire_le}]}` (forme de `CorbeilleContacts.tsx:18`).
- Rien d'autre ne change : le contact retiré sort de la liste (`ContactsController.php:51`), de la fiche 360° (`PersonTimelineController.php:238`), de l'envoi (constat 10).

### 4.3 Tests (rouges d'abord)
1. `viewer` et `operator` → 403 sur retirer et restaurer ; contact intact (`deleted_at` NULL relu) ; témoin : `admin` → 200.
2. Retirer → la ligne EXISTE toujours (`count` sans filtre = 1), `deleted_at` posé, marque présente ; `contacts_retires` inchangé (aucune ligne ajoutée).
3. Restaurer → `deleted_at` NULL, la ligne revient dans `GET /api/v1/contacts`.
4. Contact mis à l'écart (`MiseAEcart::contacts([...], 'opposition')`) → absent de la corbeille, restaurer → 409, `deleted_at` inchangé.
5. Contact en corbeille SANS marque console (posé à la main par le test) → absent de la liste, restaurer → 404.
6. Autre espace : retirer/restaurer → 404 ; liste de A ne montre jamais un contact de B (sous `pgsql_app` ET propriétaire).
7. Ingestion après retrait : `ContactUpserter::upsert` avec la même adresse → la ligne reste en corbeille (constat 9).
8. `DELETE /api/v1/contacts/{id}` (admin) → 204, ligne présente, `deleted_at` posé, marque console posée.
9. **Test de source** : aucun fichier de `app/Crm/Contacts/` ni `ContactsGestesController.php` ne contient `forceDelete`, `->delete(`, `DELETE FROM` (insensible à la casse).
10. Entreprise en corbeille → restaurer 409.

**Mutations :** test `mis_a_l_ecart_le` retiré → 4 ; filtre marque console retiré → 5 ; `->delete()` à la place de l'UPDATE → 2 (marque absente) et 9 ; `forceDelete` → 2 et 9.

---

## 5. LOT G1-C — ajout rapide

### 5.1 Fichiers autorisés
- Créer `backend/app/Crm/Contacts/AjoutContact.php`
- Modifier `ContactsGestesController.php` (`doublons`, `ajouter`), `ContactPolicy.php` (`creer` = `companies.create`)
- `routes/api.php` (groupe `crm`, avant `{contactId}`) : `GET /contacts/doublons` (`permission:companies.view`, `throttle:scraper-list`), `POST /contacts` (`permission:companies.create`)
- `nr20-routes.json`, `routes.test.ts` (2 lignes)
- Petit ajustement frontend autorisé : `frontend/src/features/contacts/ajout-rapide/AjoutRapideContact.tsx` et `types.ts` pour envoyer `company_id` quand l'utilisateur a choisi l'entreprise par la recherche existante (`GET /crm/entreprises/choix`, `routes/api.php:617`) ; le champ texte `organisation` reste accepté.
- Créer `backend/tests/Feature/Crm/ContactsAjoutTest.php`

### 5.2 Comportement
Entrée (forme de `AjoutRapideContact.tsx:62`) : `first_name?`, `last_name` (requis), `email?`, `organisation` OU `company_id`, `fonction?`, `provenance` (requis, liste fermée `salon, rencontre, site, recommandation, fichier-client, autre` = `ajout-rapide/types.ts:10-17`), `doublon?` (`fusionner|garder`), `fusionner_avec?`.
1. **Entreprise** : `company_id` de l'espace, non en corbeille ; sinon `organisation` = 9 chiffres → SIREN ; sinon nom exact sur `denomination_normalized = normalize_name(:saisie)` (index `idx_companies_denom_btree`). 0 → 422 `organisation_introuvable` ; > 1 → 422 `organisation_ambigue` (l'écran propose alors la recherche). **Jamais de création d'entreprise.**
2. **Opposition** : si `email` est en opposition (`StatutOpposition::pourLignes`) → 422 `opposition` (« cette adresse a demandé à ne plus être contactée ») ; rien n'est écrit (ESCALADE 2).
3. **Doublons** (`GET /contacts/doublons` et avant chaque création) : même adresse dans l'espace (`email` citext, contacts non retirés) → `meme_email` ; même `normalized_hash` → `meme_nom`. Max 5. Forme `DoublonPropose` (`types.ts:47-58`), adresse masquée si requis. Si des doublons existent et que `doublon` est absent → 409 `{doublons: [...]}`.
   - `doublon = fusionner` + `fusionner_avec` (contact de l'espace, non retiré) : **aucune fusion de données** ; on complète seulement les champs VIDES de la fiche existante (`first_name`, `email`, `role`, `phone`), on ajoute la provenance à `sources`, réponse `{resultat: 'fusionne', fiche}`.
   - `doublon = garder` : création même si même adresse ; même `normalized_hash` reste refusé (409, constat 8).
4. **Création** : `INSERT` avec `workspace_id`, `company_id`, noms, `email`, `role`, `discovery_source = 'console'`, `sources = ['console:<provenance>']`, `legal_basis = 'legitimate_interest_b2b'`, `person_key = CleDePersonne::pour(email)`, `field_origins` « déclaré ». Collision `23505` → 409 (jamais 500). Journal `contact.ajoute` (identifiant + provenance).
5. Réponse `{resultat: 'cree'|'fusionne', fiche: FicheContact}` (`types.ts:26-45`) : champs absents du serveur (`ne_plus_ecrire`, `verifie_il_y_a_jours`, `rappel_*`) rendus avec une valeur neutre explicite (`ne_plus_ecrire` = verdict d'opposition, les autres `null`).

### 5.3 Tests (rouges d'abord)
1. `viewer` → 403 (aucune ligne créée, `count` relu) ; `operator` → 201.
2. Entreprise d'un autre espace (`company_id` de B) → 422 `organisation_introuvable`, rien créé ; idem sous `pgsql_app` et propriétaire (service).
3. Deux entreprises de même nom → 422 `organisation_ambigue`.
4. Adresse en opposition (ligne `opt_out` factice) → 422, aucune ligne.
5. Même adresse existante, sans `doublon` → 409 avec la liste ; avec `fusionner` → aucun nouveau contact, champs vides complétés, champs remplis INCHANGÉS.
6. Même nom + entreprise qu'un contact en corbeille → 409, aucune exception.
7. `provenance` hors liste → 422.
8. `GET /contacts/doublons` d'un viewer : adresses masquées ; jamais un contact de B.

**Mutations :** contrôle d'opposition retiré → 4 ; filtre d'espace sur l'entreprise retiré → 2 ; « fusionner » qui écrase un champ rempli → 5.

---

## 6. LOT G1-D — import

### 6.1 Fichiers autorisés
- Créer `backend/app/Crm/Contacts/ImportContacts.php` (réutilise `AjoutContact`, pas de copie de logique)
- Modifier `ContactsGestesController.php` (`importControle`, `importer`, `importSuivi`), `ContactPolicy.php` (`importer` = `companies.create`)
- `routes/api.php` : `POST /contacts/import/controle`, `POST /contacts/import`, `GET /contacts/import/{id}` (`whereUuid`), tous `permission:companies.create` + `delai-sql:300`, `throttle:6,1` sur `import`
- `nr20-routes.json`, `routes.test.ts` (2 lignes)
- Créer `backend/tests/Feature/Crm/ContactsImportTest.php`

### 6.2 Comportement
- Entrée `{lignes: LigneImport[]}` (`ImportContacts.tsx:78`, `:88`), **1 000 lignes au plus** (422 au-delà), chaque champ ≤ 300 caractères.
- `controle` : AUCUNE écriture. Pour chaque ligne, mêmes règles que l'ajout rapide (entreprise, opposition, doublon, provenance) + **registre des retraits** : `contacts_retires_contient(ws, siren, prenom, nom)` vrai → refus `retire_auparavant`. Bilan `{total, a_creer, refusees[{ligne, raison}], doublons[{ligne, existant}], sans_provenance}` : **numéros de ligne et codes de raison seulement, jamais de valeur** (même principe que `ImportListe`, `app/Crm/Listes/ImportListe.php:22-24`). `existant` = identifiant du contact, pas son nom.
- `import` : rejoue le contrôle DANS la transaction, crée seulement les lignes `a_creer` (doublons et refus jamais créés : pas de fusion en masse), par paquets de 100, chaque paquet dans sa transaction. Traitement **synchrone** (1 000 lignes au plus) ; réponse `TravailImport` déjà `termine` (`{id: uuid, statut: 'termine', total, traitees, rapport}`), rapport gardé en cache 1 h sous la clé `contacts-import:<ws>:<user>:<id>` (compteurs et numéros seulement). `GET /contacts/import/{id}` relit ce cache (autre utilisateur ou autre espace → 404).
- Journal `contacts.import` : compteurs seulement.

### 6.3 Tests (rouges d'abord)
1. `viewer` → 403 sur les trois routes ; aucune ligne créée.
2. `controle` n'écrit rien (compte de `contacts` identique, sous `pgsql_app` et propriétaire).
3. Bilan exact sur 6 lignes : 1 créable, 1 entreprise introuvable, 1 opposition, 1 doublon, 1 sans provenance, 1 au registre des retraits.
4. `import` crée seulement la ligne créable ; relancer le même import → 0 création (doublon).
5. Le bilan et le journal ne contiennent aucune adresse ni aucun nom (recherche des valeurs factices dans la réponse JSON et `audit_logs`).
6. 1 001 lignes → 422.
7. Suivi par un autre utilisateur → 404.

**Mutations :** registre des retraits non consulté → 3 ; écriture pendant `controle` → 2 ; valeurs dans le bilan → 5.

---

## 7. LOT G1-E — fiche personne, appel, rappel, annonce, export

### 7.1 Fichiers autorisés
- Modifier `backend/app/Http/Controllers/Api/Crm/PersonTimelineController.php` : ajouter `fiche` (contact business de cette `person_key`, **y compris retiré par la console**, pour pouvoir le restaurer)
- Modifier `backend/app/Http/Controllers/Api/Crm/ContactsHubController.php:125` : ajouter `'gestes_fiches' => true`
- Modifier `ContactsGestesController.php` (`appel`, `rappel`, `exporter`), `ContactPolicy.php` (`exporter` = `data.export` ET `contacts.view_pii`)
- `routes/api.php` : `POST /persons/{personKey}/appels`, `POST /persons/{personKey}/rappel` (`permission:companies.update`), `POST /contacts/export` (`permission:data.export`, `permission:contacts.view_pii`, `throttle:origines-export`, `delai-sql:300` ; modèle `routes/api.php:594-595`)
- `nr20-routes.json`, `routes.test.ts` (3 lignes)
- Créer `backend/tests/Feature/Crm/ContactsFicheGestesTest.php`

### 7.2 Comportement
- `fiche` : `{contact_id, first_name, last_name, email, fonction, organisation, etape (de l'entreprise), provenance, retire_le, droit_ecrire, envois: [], rappel_le, rappel_note}` (`FicheDetail`, `types.ts:156-170`). `droit_ecrire` = `{verdict: 'non', raison: 'opposition'}` si `StatutOpposition` le dit, sinon `{verdict: 'prudence', raison: 'verifier_avant_envoi'}` — **on ne lit aucune table `envoi_*`** (`envois` reste vide ; à brancher par un lot Envoi). Masquage `MasquageCoordonnees` comme le reste de la réponse. Fiche mise à l'écart RGPD : `fiche` absente (aucun geste proposé).
- `appels` `{compte_rendu ≤ 5000}` : une ligne `activities` `kind='call'`, `subject_type='contact'`, `contact_id`, `person_key`, `user_id`, `external_ref` à la minute (anti double clic, modèle `CompanyTachesController.php:61-80`).
- `rappel` `{le (date), note?}` : **une tâche** (`kind='task'`, `due_at`, `title` = la note ou « Rappel », jamais l'adresse ni le téléphone) rattachée au contact (`subject_type='contact'`). Elle apparaît dans « Mes tâches » (G2-A).
- `export` `{company_ids?, contact_ids?}` ≤ 500 identifiants, de l'espace, non retirés ; CSV (`prenom;nom;email;fonction;organisation`) ; journal `contacts.export` (nombre de lignes seulement). Refusé (422) si un identifiant est hors espace : jamais d'export partiel silencieux.

### 7.3 Tests (rouges d'abord)
1. `viewer` : `appels`/`rappel` → 403 (aucune activité) ; `export` → 403 (`operator` sans `contacts.view_pii`… le seeder la lui donne : témoin `operator` → 200, `viewer` → 403).
2. `fiche` présente pour un contact actif et pour un contact retiré par la console ; absente pour un contact mis à l'écart ; jamais la fiche d'un autre espace (même `person_key` dans B).
3. `gestes_fiches: true` dans `GET /api/v1/crm/contacts-hub/counts`.
4. Double clic sur `appels` → une seule activité.
5. `rappel` crée `kind='task'` visible par `GET /crm/taches` (si G2-A fusionné ; sinon, relu en base).
6. Export : identifiant de B → 422, aucun fichier ; viewer → 403 ; journal sans valeur.

---

## 8. LOT G2-A — « Mes tâches » côté serveur

### 8.1 Fichiers autorisés
- Créer `backend/app/Crm/Taches/MesTaches.php`
- Créer `backend/app/Http/Controllers/Api/Crm/TachesController.php`
- Modifier `backend/app/Policies/CompanyPolicy.php` : `gererTaches(User)` = `hasPermissionTo('companies.update')`
- `routes/api.php` (groupe `crm`) : `GET /taches` (`permission:companies.view`), `POST /taches`, `PATCH /taches/{id}` (`whereNumber`), `PUT /taches/rappel-matin` (tous trois `permission:companies.update`)
- `nr20-routes.json`, `routes.test.ts` (4 lignes : `GET/POST /crm/taches`, `PATCH /crm/taches/{p}`, `PUT /crm/taches/rappel-matin`)
- Créer `backend/tests/Feature/Crm/MesTachesTest.php`
- **Ne pas toucher** `CompanyTachesController.php` ni `PersonnesController.php` (ils restent les écrivains par fiche).

### 8.2 Contrat de données (D5 s'y conformera, sans que G2 touche `app/Envoi`)
Une tâche = `activities` `kind='task'`. `payload` peut porter : `origine` (`manuelle` | `reponse`), `genre` (`tache` | `rendez_vous` | `relance`), `contexte` (texte libre court), `avec_heure` (booléen). Absent = `manuelle`, `tache`, contexte calculé, sans heure.

### 8.3 Comportement
- Lecture (espace business, `WorkspaceContext::run`) :
  ```sql
  SELECT a.id, a.title, a.due_at, a.done_at, a.payload, a.subject_type, a.subject_id
  FROM activities a
  WHERE a.workspace_id = :ws AND a.kind = 'task'
    AND (a.user_id = :moi OR a.user_id IS NULL)
    AND (a.done_at IS NULL OR a.done_at >= now() - interval '7 days')
  ORDER BY a.due_at NULLS LAST, a.id
  LIMIT 500
  ```
  (`kind = 'task'` = prédicat de l'index G0.) `contexte` : `payload.contexte`, sinon dénomination de l'entreprise (`subject_type='company'`), sinon `organisation` du contact, sinon « — » (une requête par lot d'identifiants, jamais une par ligne ; entreprise ou contact d'un AUTRE espace jamais joint : filtre `workspace_id` dans chaque jointure). Projection `Tache` (`taches/types.ts:9-22`) : `id` en chaîne, `echeance` = date de `due_at` à Paris (`AAAA-MM-JJ`, aujourd'hui si NULL), `heure` = `HH:MM` à Paris si `avec_heure`, sinon `null`, `faite`, `faite_le`. Réponse `{aujourdhui (Paris), data, rappel_matin: false}`.
- `POST /taches` `{titre 3-300, contexte? ≤ 200, echeance? date, heure? HH:MM}` : `INSERT` comme `CompanyTachesController::store` (`:66-80`) avec `subject_type = NULL`, `user_id = moi`, `external_ref = 'console:moi:<user>:task:<minute>:<sha1(titre)[0..12]>'`, `insertOrIgnore` ; `due_at` = date + heure à Paris converties en UTC (horloge figée dans les tests, `backend/AGENTS.md` « Horloge »). Réponse : la `Tache` créée (201) ou relue (200).
- `PATCH /taches/{id}` `{faite: bool}` : seulement une tâche de l'espace, `kind='task'`, et `user_id = moi OR user_id IS NULL` ; sinon 404. `faite=true` → `done_at = now()` si NULL ; `faite=false` → `done_at = NULL` (rouvrir n'efface rien). Jamais de suppression de tâche.
- `PUT /taches/rappel-matin` : **aucun courriel n'est envoyé par ce lot** (règle « aucun envoi réel »). La route rend 409 `{error: 'rappel_indisponible', actif: false, message: 'Le rappel du matin par courriel n'est pas encore disponible.'}` et n'écrit rien. Voir ESCALADE 4.
- Contrôleur : `Gate::forUser(...)->authorize('gererTaches', Company::class)` sur les trois écritures.

### 8.4 Tests (rouges d'abord)
1. `viewer` → 403 sur `POST`, `PATCH`, `PUT` ; aucune ligne créée ni modifiée ; `GET` → 200.
2. Je vois : mes tâches (entreprise, personne, sans sujet), celles du système (`user_id NULL`) ; je ne vois PAS celles d'un collègue ni celles d'un autre espace (sous `pgsql_app` ET propriétaire).
3. Tâche faite il y a 8 jours : absente ; il y a 2 jours : présente, `faite: true`.
4. `PATCH` sur la tâche d'un collègue ou d'un autre espace → 404, `done_at` inchangé ; sur une activité qui n'est pas une tâche (`kind='call'`) → 404.
5. `faite=false` rouvre ; la ligne existe toujours (aucune suppression).
6. Double `POST` dans la même minute → une seule ligne.
7. Horloge figée à 23 h 30 UTC : `echeance` = date de Paris (lendemain), `aujourdhui` idem.
8. `PUT rappel-matin` → 409, aucune écriture, aucun courriel (`Mail::fake()` : rien envoyé).
9. Les tâches de `CompanyTachesController` et `PersonnesController` (créées par leurs routes) apparaissent dans « Mes tâches » ; une tâche terminée ici l'est aussi sur la fiche (`GET /companies/{id}/taches`).

**Mutations :** `OR user_id IS NULL` retiré → 2 ; filtre `user_id` retiré → 2 (le collègue apparaît) ; filtre `kind` retiré du `PATCH` → 4 ; `Gate` et middleware retirés → 1.

---

## 9. LOT G2-B — écran « Mes tâches » hors démo

### 9.1 Fichiers autorisés
- `frontend/src/features/crm/taches/disponible.ts` : séparer en `tachesDisponibles()` (toujours vrai) et `affairesDisponibles()` (démo seulement, jusqu'à G2-D)
- `frontend/src/features/crm/taches/MesTachesPage.tsx` : masquer l'interrupteur « rappel du matin » quand le serveur rend `rappel_matin: false` ET 409 au premier essai (message clair, aucune boucle)
- `frontend/src/components/layout/Sidebar.tsx` : une entrée « Mes tâches » (`/taches`)
- `frontend/src/features/dashboard/OngletsTableauDeBord.tsx`, `frontend/src/features/crm/taches/commandes.ts` : suivre `tachesDisponibles()`
- Tests frontend associés (Vitest) ; **ne pas toucher** `routeTree.tsx` (fichier chaud : la route `/taches` existe déjà, `:397`).

### 9.2 Tests
Hors démo : `/taches` montre la liste (serveur simulé par MSW du test), plus la page « en attente » ; `/affaires` garde la page « en attente » ; Ctrl K propose « Mes tâches », pas « Affaires ».

---

## 10. LOT G2-C — Affaires : migration seule

### 10.1 Fichiers autorisés
- Créer `backend/database/migrations/2026_12_08_000150_deals_etape_affaires.php`
- Créer `backend/tests/Feature/Crm/SchemaAffairesTest.php`
- Instantanés NR touchés (« Filet modifié : pourquoi »)

### 10.2 Contenu (dans une transaction, `SET LOCAL lock_timeout = '5s'`)
```sql
ALTER TABLE deals ADD COLUMN IF NOT EXISTS etape TEXT NOT NULL DEFAULT 'a_contacter';
ALTER TABLE deals ADD COLUMN IF NOT EXISTS raison_perte TEXT;
ALTER TABLE deals ADD COLUMN IF NOT EXISTS titre TEXT;
ALTER TABLE deals DROP CONSTRAINT IF EXISTS deals_etape_valeur;   -- seulement SA contrainte, pour rejouer up()
ALTER TABLE deals ADD CONSTRAINT deals_etape_valeur
  CHECK (etape IN ('a_contacter','contacte','repondu','rendez_vous','devis','gagne','perdu')) NOT VALID;
ALTER TABLE deals VALIDATE CONSTRAINT deals_etape_valeur;
ALTER TABLE deals DROP CONSTRAINT IF EXISTS deals_raison_perte_longueur;
ALTER TABLE deals ADD CONSTRAINT deals_raison_perte_longueur CHECK (raison_perte IS NULL OR length(raison_perte) <= 500) NOT VALID;
ALTER TABLE deals VALIDATE CONSTRAINT deals_raison_perte_longueur;
```
Défaut constant : pas de réécriture de table. Aucune donnée touchée, aucune ligne créée. `down()` : retirer les deux contraintes et les trois colonnes **seulement si la table n'a aucune ligne avec `etape <> 'a_contacter'` ou `raison_perte`/`titre` non nuls** ; sinon `down()` inerte (aucune perte ; l'ajouter à `NR17_RETOUR_INERTE_VOULU` si NR-17 l'exige, avec la ligne « Filet modifié : pourquoi »).
Ne PAS ajouter de déclencheur de refus de `DELETE` (il casserait la cascade de suppression d'un espace) : la garde est l'absence de route + le test de source de G2-D.

### 10.3 Tests
Colonnes et contraintes présentes ; `etape = 'inconnue'` refusé (`23514`) sous `pgsql_app` et propriétaire ; une ligne existante garde ses valeurs ; `up()` rejouable.

---

## 11. LOT G2-D — Affaires côté serveur + écran

### 11.1 Fichiers autorisés
- Créer `backend/app/Crm/Affaires/Affaires.php`, `backend/app/Http/Controllers/Api/Crm/AffairesController.php`
- `CompanyPolicy.php` : `gererAffaires(User)` = `hasPermissionTo('companies.update')`
- `routes/api.php` : `GET /affaires` (`companies.view`), `POST /affaires`, `PATCH /affaires/{id}` (`companies.update`, `whereNumber`)
- `nr20-routes.json`, `routes.test.ts` (2 lignes : `GET /crm/affaires`, `PATCH /crm/affaires/{p}`)
- `frontend/src/features/crm/taches/disponible.ts` (`affairesDisponibles()` toujours vrai), `OngletsTableauDeBord.tsx`
- Créer `backend/tests/Feature/Crm/AffairesTest.php`

### 11.2 Comportement
- `GET` : affaires de l'espace dont l'entreprise n'est pas en corbeille, 500 au plus, projection `Affaire` (`id` chaîne, `entreprise` = dénomination, `entreprise_id`, `montant` = `value_eur`, `etape`, `chance` = `probability`, `signature_prevue` = date de `expected_close` à Paris, `raison_perte`), `aujourdhui` (Paris).
- `POST` `{entreprise_id, titre?, montant? ≥ 0, chance? 0-100, signature_prevue? date}` : entreprise de l'espace, non en corbeille (sinon 404) ; `etape = 'a_contacter'`, `status = 'open'`. (Pas d'écran de création dans ce lot : la route sert la fiche entreprise et D5 plus tard.)
- `PATCH` `{etape, raison_perte?}` : `raison_perte` exigée si `etape = 'perdu'`, interdite sinon ; `status` recalculé dans la même requête (`gagne → won`, `perdu → lost`, sinon `open`) ; trace `activities` `kind='stage_changed'`, `subject_type='deal'`, `subject_id`, `deal_id`, `payload {de, vers}`. **Jamais `deal_history`** (constat 16).
- **Aucune route de suppression.** Test de source : `Affaires.php` et `AffairesController.php` ne contiennent ni `->delete(` ni `DELETE FROM`.

### 11.3 Tests (rouges d'abord)
1. `viewer` → 403 sur `POST` et `PATCH`, rien modifié ; `operator` → 200/201.
2. Affaire d'un autre espace → 404 (sous `pgsql_app` ET propriétaire), intacte.
3. `perdu` sans raison → 422 ; `gagne` → `status = 'won'`, une activité `stage_changed`.
4. Entreprise en corbeille → affaire absente de la liste ; `POST` sur elle → 404.
5. Fusion de fiches (`FusionFiches`) : l'affaire suit l'entreprise conservée (test existant `DoublonsFusionTest` reste vert).
6. Test de source (aucune suppression).

**Mutations :** recalcul de `status` retiré → 3 ; filtre d'espace retiré → 2 ; `Gate` + middleware retirés → 1.

---

## 12. Fichiers chauds (une seule PR à la fois)

| Fichier | G0 | G1-A | G1-B | G1-C | G1-D | G1-E | G2-A | G2-B | G2-C | G2-D |
|---|---|---|---|---|---|---|---|---|---|---|
| `backend/routes/api.php` | non | 1 ligne | 3 | 2 | 3 | 3 | 4 | non | non | 3 |
| `nr20-routes.json` | non | 1 | 3 | 2 | 3 | 3 | 4 | non | non | 3 |
| `frontend/tests/non-regression/routes.test.ts` | non | −1 | −3 | −2 | −2 | −3 | −4 | non | non | −2 |
| `ContactPolicy.php` / `CompanyPolicy.php` | non | +1 méth. | +1 | +1 | +1 | +1 | +1 (Company) | non | non | +1 (Company) |
| instantanés NR-17 (migrations) | oui | non | non | non | non | non | non | non | oui | non |
| `SemeurTablesScopees.php` | non | non | non | non | non | non | non | non | non | non (aucune table nouvelle) |
| `routeTree.tsx`, `navigationEnvoi.tsx` | non | non | non | non | non | non | non | non | non | non |
| `StatutOpposition.php` | appelé seulement, jamais modifié |
| `backend/app/Envoi/**`, `config/envoi.php`, `.env*`, drapeaux | **jamais** |

Ces lots ne partent pas dans la même vague qu'un autre lot qui touche `routes/api.php` (règle du plan maître § 0).

## 13. Rôles : chaque test de cloisonnement deux fois
Rôle applicatif `pgsql_app` (`SET LOCAL ROLE` + `WorkspaceContext`) ET propriétaire, comme `SchemaLienCampagneSequenceTest.php:77-89`. Rappel : en test le propriétaire est superutilisateur et ignore la RLS (`backend/AGENTS.md`, « Nouvelle table ») : c'est le mode propriétaire qui prouve le filtre `workspace_id` explicite ; le mode `pgsql_app` prouve que les droits SQL suffisent (pas de `permission denied`). Tests HTTP : deux espaces factices, comptes `viewer`, `operator`, `admin` (fonction d'aide sur le modèle `masseActeur`, `ActionDeMasseRouteTest.php:33-50`), adresses `@example.test`.

## 14. Rien ne part (rappel)
Aucun de ces lots n'écrit dans une table `envoi_*`, n'appelle `app/Envoi`, ni n'envoie de courriel (`Mail::fake()` dans les tests G1-E et G2-A). `ENVOI_REEL_ACTIF` reste faux (`config/envoi.php:19`). Retirer un contact l'écarte de l'envoi par les filtres existants (constat 10) ; restaurer ne le réinscrit nulle part.

## 15. ESCALADE (décisions du fondateur ; aucune ne bloque le code)
1. **Qui peut retirer un contact ?** Par défaut ce cahier suit l'existant : `companies.delete` (admin et propriétaire), comme l'entreprise et la liste manuelle. Si le fondateur veut que l'opérateur puisse aussi retirer (la corbeille étant restaurable), il suffira de changer la permission des deux routes : une ligne chacune.
2. **Ajout ou import d'une adresse en opposition.** Par défaut : refus, rien n'est écrit. Alternative à trancher : créer la fiche marquée « ne plus écrire » (utile si la personne a donné sa carte à un salon après s'être désabonnée). Juridique : le fondateur décide.
3. **Attribuer une tâche à un collègue.** Aujourd'hui « Mes tâches » = celles que j'ai créées + celles du système. Une vraie attribution demanderait une colonne (`assigne_a`) : migration additive `000160` réservée, non écrite tant que ce n'est pas décidé.
4. **Rappel du matin par courriel (08:00).** C'est un envoi : il reste refusé (409) tant que le fondateur n'a pas dit si ces courriels internes passent par le même circuit que la lettre ou par un circuit séparé. Aucun réglage n'est stocké d'ici là.
5. **Affaires.** Le plan corrigé dit « G2 = Mes tâches seulement ». G2-C et G2-D sont prêts mais peuvent attendre ; le fondateur dit s'il veut les 7 étapes de l'écran ou les étapes `pipeline_stages` par espace (les tables `crm_pipelines`/`pipeline_stages` existent, vides).
6. **Export de contacts** (G1-E) : réservé à qui a `data.export` ET `contacts.view_pii` (opérateur compris, comme l'export des origines). Le fondateur peut le réserver au propriétaire.

## 16. Hors périmètre (écrit pour éviter les malentendus)
- Fusion réelle de deux contacts (le seul outil de fusion reste `FusionFiches`, pour les entreprises).
- Effacement RGPD d'un contact (circuit existant `GdprErasureService` / `MiseAEcart`, inchangé).
- `envois` de la fiche personne (lit les tables `envoi_*` : lot Envoi).
- Création de tâches par les réponses (D5, vague D) : il écrira selon le contrat § 8.2.
- Vidage de la corbeille : **n'existe pas et ne doit pas exister**.
