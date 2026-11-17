# CAHIER — G3 « Double authentification par courriel et écrans de sécurité », G4 « Cloisonnement : rôle applicatif obligatoire », G5 « Documents du dépôt à jour »

**Pour le développeur :** dépôt public. Dans le code, les tests, les commits et les PR : écrire « le fondateur », jamais de nom ; données fictives en `*.example.test`. Les quatre drapeaux `ENVOI_REEL_ACTIF`, `ORIGINES_LECTURE_ENABLED`, `CRM_GUIDE_LIEN_ENABLED`, `USINE_AGREGATS_CRM_ENABLED` ne sont **jamais** touchés (ni valeur, ni défaut, ni fichier `.env*`) ; `config/envoi.php` est **lu** (G3-A), jamais modifié. **Aucun fichier `backend/app/Envoi/**` touché.** Aucune suppression ni purge : un appareil « retiré » ou un code « remplacé » reçoit une date, la ligne reste. Base relevée : `main` 454d333 (#668). Forme reprise du cahier G1/G2 (#619, `docs/crm/lots/G1-G2-cahier.md`). **Vérifier chaque numéro de ligne avant de s'y fier.**

Plan maître : il n'est **pas** dans `docs/` de `main` (aucun fichier ne décrit la vague G ; `docs/envoi/PLAN-FIN-ENVOI.md` parle d'autres « G4 », `G4-API`/`G4-UI`/`G4-ACCORD`, qui sont l'opposition dans le CRM, sans rapport avec ce cahier). Le périmètre suivi ici est celui de la commande : G3 double authentification par courriel + écrans de sécurité ; G4 refus de démarrer sans le rôle applicatif, **précédé** d'un diagnostic en lecture seule (G4-0) ; G5 TODO, CHANGELOG, README, R19.

---

## 0. Ordre de fusion (une fusion à la fois, chaque PR ≤ 400 lignes tests compris)

| Ordre | Lot | Contenu | Condition pour fusionner |
|---|---|---|---|
| 1 | **G4-0** | diagnostic en lecture seule du rôle applicatif (commande + workflow manuel) | rien |
| — | *geste du fondateur* | lancer `diag-role-applicatif.yml`, coller le résultat dans la PR G4-A | obligatoire avant G4-A |
| 2 | **G3-0** | migration seule `2026_12_08_000200` : colonne `users.double_auth_methode`, tables `codes_connexion_courriel` et `appareils_confiance` | rien |
| — | *déploiement* | G3-0 **déployé**, `migrate:status` sans `Pending` | obligatoire avant G3-A |
| 3 | **G3-A** | canal du code (vrai canal transactionnel / faux canal) + service des codes, sans route | G3-0 déployé |
| 4 | **G3-B** | connexion par code : `POST /auth/code-email/verifier`, `/renvoyer`, `/auth/me` annonce la méthode, gardes | G3-A fusionnée |
| 5 | **G3-C** | appareils de confiance : cookie 30 jours, `GET /compte/appareils`, `POST /compte/appareils/{id}/retirer`, `POST /compte/code` | G3-B fusionnée |
| 6 | **G3-D** | codes de secours : `GET /compte/codes-secours`, `POST /compte/codes-secours/regenerer`, code de secours accepté à la connexion | G3-C fusionnée |
| 7 | **G3-E** | écrans : bouton « Recevoir un code » dans la fenêtre de geste, saisie d'un code de secours | G3-D fusionnée |
| 8 | **G3-F** | commande d'activation par compte (refusée tant que le canal est faux) | G3-E fusionnée |
| 9 | **G4-A** | garde de démarrage « rôle applicatif obligatoire », derrière `CRM_GARDE_ROLE_APPLICATIF` (faux par défaut) | G4-0 **lu** par le fondateur |
| 10 | **G5-A** | TODO.md et `_AUDIT/DEPLOY-PIPELINE.md` (rôle applicatif tranché, compteurs re-mesurés, prénom retiré) | G4-0 lancé ; après G4-A si elle est fusionnée |
| 11 | **G5-B** | R19 re-mesuré + CHANGELOG + README | après G3-F (pour décrire l'état réel) |

G4-0 et G3-0 ne se touchent pas : ils peuvent être relus en parallèle, mais **fusionnés l'un après l'autre** (fichier commun : les instantanés NR, cf. § 13).

Pourquoi « migration seule » d'abord : le déploiement remplace le code **avant** de migrer (`.github/workflows/deploy-direct-ssh.yml:561`, `migrate --force --database=pgsql_owner`, après la recréation des conteneurs). Un code qui lit `users.double_auth_methode` arrivé avant la colonne ferait tomber `/auth/me` pour tout le monde.

**Horodatage réservé : `2026_12_08_000200` (un seul fichier).** Plage 000200-290 vérifiée libre : aucun `2026_12_08_0002*` sur `main` (seuls `000100`, `000110`, `000150` existent) ; aucune PR ouverte ne crée de migration (#670, #669, #354, #352 relues : aucun fichier sous `database/migrations/`) ; aucun cahier brouillon (#607 à #649) ne réserve `0002xx`. `000210` à `000290` restent libres pour une suite de G3.

---

## 1. Ce qui est DÉJÀ fait (preuves) — aucun lot ne le refait

| Élément | État | Preuve |
|---|---|---|
| Double authentification par **application (TOTP)** | fait et gardé | `backend/app/Services/Auth/TwoFactorService.php:57-133` (enrôlement, confirmation, vérification, 10 codes de secours hachés) ; routes `backend/routes/api.php:78-80` ; garde NR-22 `backend/tests/Feature/NonRegression/Nr22DeuxFacteursTest.php` |
| 2FA **exigée** côté serveur (pas seulement par l'écran) | fait | `backend/app/Http/Middleware/EnsureTwoFactorPassed.php:45-66` (403 `two_factor_required`), branché sur tout le groupe `api` : `backend/bootstrap/app.php:117-127` |
| Écran de connexion par **code reçu par courriel** | fait **en démonstration seulement** | `frontend/src/features/auth/CodeEmailPage.tsx:42` (`POST /auth/code-email/verifier {code, appareil_confiance}`), `:54` (`/renvoyer`), case « Faire confiance à cet appareil pendant 30 jours » ; montré si `/auth/me` annonce `two_factor_method: 'email'` (`TwoFactorPage.tsx:63`) ; faux serveur `frontend/src/demo/handlers/socle/ecrans-communs.handlers.ts:125-141` (PR #397, fusionnée) |
| Écran **Mon compte et sécurité** (appareils de confiance, codes de secours) | fait **en démonstration seulement** | `frontend/src/features/settings/ComptePage.tsx:20-30` (affichés seulement si `two_factor_method === 'email'`), `securite/AppareilsDeConfiance.tsx:9-17` (forme d'un appareil), `:26`, `:31` ; `securite/CodesDeSecours.tsx:13`, `:46` ; faux serveur `ecrans-communs.handlers.ts:162-195` |
| Canal **transactionnel** séparé du courrier commercial | fait | `backend/config/mail.php:26` (`auth_mailer` = `MAIL_MAILER_AUTH`), utilisé par le lien magique `backend/app/Services/Auth/MagicLinkService.php:63-73` ; gardes de démarrage du courrier d'authentification `backend/app/Socle/Demarrage/GardesDemarrage.php:42-83` (drapeau `GARDES_DEMARRAGE_AUTH`, faux par défaut) |
| Rôle applicatif non propriétaire | **créé**, bascule par drapeau **faux par défaut** | rôle et droits : `backend/database/migrations/2026_08_14_000001_harden_workspace_isolation.php:398-406` ; bascule `backend/config/database.php:37` ; `backend/config/crm.php:36` ; `.env.example:115` (`CRM_DB_APP_ROLE_ENABLED=false`) ; migrations sous le propriétaire : `deploy-direct-ssh.yml:561` |
| Contrôle « RLS posée ET forcée » | fait (commande d'audit) | `backend/app/Console/Commands/PentestSelfCheck.php:180-210` (`app:pentest-self-check`) — ne dit **pas** sous quel rôle l'application tourne |
| Modèle de diagnostic production en lecture seule | fait (gabarit) | `.github/workflows/diag-reception-guide.yml` (`workflow_dispatch`, `PGOPTIONS=-cdefault_transaction_read_only=on`, seulement des booléens et des noms) |
| Trous R19 comblés | fait | les 8 fichiers `backend/tests/Support/Couverture/trous/*.php` rendent tous `return [];` (19 trous en novembre, 0 aujourd'hui) |

**Ce qui MANQUE (et que ce cahier découpe) :**
1. Aucune des 6 routes serveur des écrans G3 n'existe : elles sont figées comme écarts dans `frontend/tests/non-regression/routes.test.ts:116-122` (`GET /compte/appareils`, `GET /compte/codes-secours`, `POST /auth/code-email/renvoyer`, `POST /auth/code-email/verifier`, `POST /compte/appareils/{p}/retirer`, `POST /compte/codes-secours/regenerer`). Le test exige l'égalité (`:216`, `toEqual`) : **chaque lot qui sert une route retire exactement sa ligne** et régénère `nr20-routes.json`.
2. `/auth/me` n'annonce jamais `two_factor_method` (`backend/app/Http/Controllers/Api/Auth/AuthController.php:101`) : les écrans G3 restent cachés en production, ce qui permet de livrer les routes une à une sans rien montrer avant G3-F.
3. Aucune colonne, aucune table pour la méthode, les codes courriel, les appareils de confiance (`users` : `totp_secret`, `totp_enabled_at`, `totp_recovery_codes`, `2026_05_16_000002_create_auth_tenant_audit_schema.php:52-54`).
4. La fenêtre de geste sensible (`frontend/src/features/settings/securite/DemandeCode.tsx`) demande « le code reçu par e-mail » mais **rien ne l'envoie** : aucun bouton, aucune route. G3-C crée `POST /compte/code`, G3-E le bouton.
5. L'état de `CRM_DB_APP_ROLE_ENABLED` en production est **inconnu** : le dépôt se contredit (`TODO.md:93-103`, `_AUDIT/DEPLOY-PIPELINE.md`, note finale ; `PartmanMaintenir.php:110` dit faux, `CoverageRefreshMatrix.php:25` dit vrai). Aucune garde de démarrage n'existe pour ce drapeau.
6. Documents périmés : `TODO.md:9` (« Dernière réécriture : 2026-08-20 »), compteurs `TODO.md:38-50` (65 migrations, 159 fichiers de test, 55 commandes, 18 workflows ; mesuré aujourd'hui : 236, 642, 134, 40) ; le prénom du responsable du projet y figure (`TODO.md:77`, `:80`, contraire à `CLAUDE.md`) ; `CHANGELOG.md` s'arrête au 2026-05-17 (`:5`) ; `README.md:54-60` ne parle ni de la console CRM, ni d'Envoi, ni de la sécurité du compte ; `docs/envoi/lots/R19.md` annonce encore « Trous réels : 19 ».

---

## 2. Constats vérifiés dans le code (ils changent la conception)

1. **Deux gardes s'appliquent à TOUTES les routes `api`, y compris celles de la connexion.** `EnforceFirstLoginSetup` (liste blanche `EnforceFirstLoginSetup.php:23-29`) et `EnsureTwoFactorPassed` (`ALLOW_LIST`, `EnsureTwoFactorPassed.php:37-43`). Une route de code courriel absente de ces listes répondrait 403 à la personne qui essaie précisément de franchir l'étape. Les deux listes reçoivent les routes de G3-B (et seulement celles-là).
2. **`EnsureTwoFactorPassed` ne connaît que le TOTP** : il laisse passer tout compte dont `totp_enabled_at` est nul (`:49`). Pour la méthode courriel, il faut exiger le franchissement aussi quand `double_auth_methode = 'email'` (et que G3 est allumé). Le franchissement se lit sur la même clé de session `2fa_passed_at` (`:76`), posée aujourd'hui par `TwoFactorController::verify` (`TwoFactorController.php:77`) : G3-B la pose de la même façon.
3. **`requires_2fa` est calculé à deux endroits** : `AuthService.php:181` (mot de passe) et `MagicLinkController.php:97` (lien magique). Les deux doivent dire vrai pour un compte « courriel ».
4. **Le premier passage est verrouillé sur l'enrôlement TOTP.** Seul `TwoFactorService::confirmEnrolment` pose `first_login_completed_at` (`TwoFactorService.php:93-97`). Un compte passé en « courriel » sans jamais avoir enrôlé de TOTP serait bloqué par `EnforceFirstLoginSetup`. G3-F (activation) pose `first_login_completed_at` s'il est nul, dans la même écriture.
5. **Le rôle applicatif reçoit `DELETE` sur toute table nouvelle** (privilèges par défaut, `2026_08_14_000001…:404`). Les deux tables de G3 retirent explicitement `DELETE` et `TRUNCATE` à ce rôle (rien ne s'efface : « retiré », « remplacé », « consommé » sont des dates).
6. **Garde RGPD par noms de colonnes.** `backend/tests/Feature/Rgpd/PortabiliteCompleteTest.php:191-194` (`COLONNES_IDENTIFIANTES` : `email`, `email_hash`, `person_key`, `phone`…) et compte figé des colonnes JSON/JSONB (`:829` et suivantes). Les tables de G3 ne portent **ni** colonne de ce nom **ni** JSONB (adresse lue sur `users.email` par jointure) : sinon `GdprErasureService` et `GdprPortabilityService` (fichiers chauds) devraient suivre.
7. **Pas d'espace dans l'authentification.** `users`, `magic_links` n'ont pas de `workspace_id` (`2026_05_16_000002…:146-155`) : l'authentification se joue avant tout contexte d'espace. Les tables de G3 suivent ce modèle (clé `user_id`, sans RLS d'espace). Le cloisonnement est **par compte** : toute lecture ou écriture filtre `user_id = $request->user()->id`, et un identifiant d'appareil d'un autre compte rend **404** (jamais 403 : pas d'oracle d'existence). `SemeurTablesScopees` n'est pas touché (aucune table à `workspace_id`).
8. **Le canal du lien magique ne regarde pas `ENVOI_REEL_ACTIF`** : il part dès que `crm.mock_mode` est faux, par `mail.auth_mailer` (`MagicLinkService.php:63-73`). La commande de ce cahier exige qu'**aucun courriel réel ne parte tant que `ENVOI_REEL_ACTIF` est éteint**. G3 applique donc une règle plus stricte que le lien magique : le vrai canal n'est choisi que si `config('envoi.reel_actif') === true` **et** `config('crm.mock_mode')` faux ; sinon le **faux canal**. Et tant que le canal est faux, **aucun compte ne peut être passé en méthode courriel** (G3-F refuse) : personne ne se retrouve enfermé dehors faute de code. → ESCALADE 1.
9. **Les écrans attendent des formes précises** : appareil `{id, nom, lieu, ip, ajoute_le, expire_le, actuel}` (`AppareilsDeConfiance.tsx:9-17`) ; codes de secours `{restants}` et `{recovery_codes: string[]}` (`CodesDeSecours.tsx:13`, `:46`) ; erreurs 422 `errors.code[0]` et 429 (`CodeEmailPage.tsx:20-25`) ; au plus 3 renvois (`CodeEmailPage.tsx:18`).
10. **Les codes de secours existent déjà pour le TOTP** (`users.totp_recovery_codes`, cast `encrypted:array`, colonne passée en `TEXT` par `2026_08_19_120000_reparer_socle_authentification.php:27-34`). G3-D **réutilise** cette colonne (un seul jeu de codes par compte, quelle que soit la méthode) ; aucune nouvelle colonne.
11. **Garde de démarrage : le modèle existe.** `GardesDemarrage::verifier` (`GardesDemarrage.php:42-83`), enregistrée en `backend/bootstrap/providers.php:25`, testée par `backend/tests/Unit/GardesDemarrageTest.php` ; production seulement, drapeau faux par défaut, message qui ne cite jamais une valeur. G4-A ajoute une garde **sœur**, dans un fichier séparé, sur le même patron.
12. **NR-16 photographie tout `env('CRM_…')` / `env('MOCK_…')` de `config/*.php`** (`tests/Feature/NonRegression/Nr16DrapeauxTest.php:25-62`) : les deux drapeaux nouveaux (`CRM_DOUBLE_AUTH_COURRIEL_ENABLED`, `CRM_GARDE_ROLE_APPLICATIF`) font bouger `nr16-drapeaux.json` (« Filet modifié : pourquoi »). **NR-18** photographie les workflows (`nr18-workflows-tous.json`, `nr18-workflows-serveur.json`) : le workflow de G4-0 les fait bouger.
13. **Personnes opposées.** G3 n'écrit qu'aux **titulaires de compte** (`users.email`), jamais à un contact ni à une personne de la lettre ; il n'écrit dans aucune table `envoi_*`, `opt_out`, `email_suppressions`. Règle d'échec fermé quand même : si l'adresse du compte est opposée (lecture seule `App\Crm\Opposition\StatutOpposition::pourLignes`, `StatutOpposition.php:35`, fichier chaud **appelé seulement**), le code **ne part pas** (journal `auth.code_courriel.adresse_opposee`, aucune donnée de l'adresse dans le journal) et la réponse est la même qu'un envoi normal (pas d'oracle). → ESCALADE 4.

---

## 3. LOT G4-0 — diagnostic en lecture seule du rôle applicatif

Objet : **savoir**, sans rien changer, sous quel rôle l'application parle à Postgres en production, avant d'écrire une garde qui refuserait de démarrer. Si la production tourne aujourd'hui en superutilisateur, G4-A allumée la ferait tomber.

### 3.1 Fichiers autorisés
- Créer `backend/app/Console/Commands/CrmRoleApplicatifDiagnostic.php`
- Créer `backend/tests/Feature/Commands/CrmRoleApplicatifDiagnosticTest.php`
- Créer `.github/workflows/diag-role-applicatif.yml`
- Créer `docs/crm/lots/G4-0-diagnostic.md` (comment lancer, comment lire, ce que chaque ligne veut dire)
- Modifier `backend/tests/Feature/NonRegression/instantanes/nr18-workflows-tous.json` et `nr18-workflows-serveur.json` (régénérés, « Filet modifié : pourquoi » : un workflow de diagnostic manuel de plus)

### 3.2 Fichiers chauds à ne pas toucher
`config/database.php`, `config/crm.php`, `.env.example`, `deploy-direct-ssh.yml`, `PentestSelfCheck.php`, toute migration.

### 3.3 Comportement exact
Commande `crm:role-applicatif:diagnostic` : **une transaction `READ ONLY`** (`SET TRANSACTION READ ONLY` en tête ; toute écriture lèverait `25006`), sortie en lignes `CLE=valeur`, **uniquement** des booléens, des nombres et des noms de rôles déjà publics (`axion`, `axion_app`) :
- `DRAPEAU_CONFIG=true|false` — `config('crm.db_app_role')` normalisé en booléen ;
- `CONNEXION_DEFAUT_ROLE=<current_user>` — sur la connexion `pgsql` ;
- `CONNEXION_DEFAUT_SUPERUSER=oui|non`, `CONNEXION_DEFAUT_BYPASSRLS=oui|non` — `pg_roles` de `current_user` ;
- `CONNEXION_DEFAUT_EST_PROPRIETAIRE=oui|non` — `current_user` possède-t-il la table `contacts` (`pg_tables.tableowner`) ;
- `ROLE_APP_EXISTE=oui|non`, `ROLE_APP_LOGIN=oui|non`, `ROLE_APP_SUPERUSER=oui|non`, `ROLE_APP_BYPASSRLS=oui|non` — pour `config('database.connections.pgsql_app.username')` ;
- `ROLE_APP_CONNEXION=ok|refusee|non_configuree` — `SELECT 1` sur `pgsql_app` (mot de passe vide ⇒ `non_configuree` sans tentative ; échec ⇒ `refusee`, **sans** le message d'erreur, qui peut contenir l'hôte) ;
- `TABLES_ESPACE_SANS_FORCE=<n>` — nombre de tables à `workspace_id` sans `relforcerowsecurity` (requête de `PentestSelfCheck.php:188-195`, recopiée, pas appelée) ;
- `VERDICT=role_applicatif_actif|superutilisateur_actif|incoherent` — `role_applicatif_actif` si `DRAPEAU_CONFIG=true` et rôle courant = rôle app non super ; `superutilisateur_actif` si `DRAPEAU_CONFIG=false` et rôle courant super ; sinon `incoherent`.
Code de sortie 0 dans tous les cas lisibles (c'est un constat, pas un test) ; 1 seulement si la base est injoignable.

Workflow `diag-role-applicatif.yml` : copie de la forme de `diag-reception-guide.yml` (`workflow_dispatch: {}`, `permissions: contents: read`, `concurrency`, SSH, `set -euo pipefail`). Il lance la commande **dans chacun des conteneurs `api`, `horizon`, `scheduler`** (chacun lit son propre environnement : un conteneur non recréé peut garder l'ancien) avec `docker compose exec -T <svc> php artisan crm:role-applicatif:diagnostic </dev/null`, préfixe chaque ligne par le service, puis :
- `ENV_FICHIER_DRAPEAU=true|false|absent|autre` — lu dans `.env` par `grep -E '^CRM_DB_APP_ROLE_ENABLED='`, normalisé en booléen, **jamais** la valeur brute ;
- `ENV_FICHIER_MOT_DE_PASSE_APP=present|absent` — présence de `DB_APP_PASSWORD=` non vide, **jamais** la valeur ;
- dates de démarrage des trois conteneurs et date de modification du `.env` (même bloc que `diag-reception-guide.yml`) ;
- côté Postgres, session `PGOPTIONS=-cdefault_transaction_read_only=on` : `SELECT usename, count(*) FROM pg_stat_activity WHERE datname = current_database() GROUP BY 1` (quels rôles sont réellement connectés à cet instant).
Aucune écriture, aucun redémarrage, aucune adresse, aucun identifiant d'espace.

### 3.4 Tests (rouges d'abord)
1. Sous la connexion par défaut (propriétaire en test) : la sortie contient `CONNEXION_DEFAUT_SUPERUSER=oui`, `DRAPEAU_CONFIG=false`, `VERDICT=superutilisateur_actif`.
2. Avec la connexion par défaut **basculée sur `pgsql_app`** (`config(['database.default' => 'pgsql_app'])` + `DB::purge`) et `crm.db_app_role=true` : `CONNEXION_DEFAUT_SUPERUSER=non`, `CONNEXION_DEFAUT_EST_PROPRIETAIRE=non`, `VERDICT=role_applicatif_actif`.
3. Drapeau vrai mais connexion propriétaire : `VERDICT=incoherent`.
4. Lecture seule prouvée : un faux contrôle qui tente `CREATE TEMP TABLE` dans la transaction de la commande lève `25006` (fonction de la commande exposée pour le test, ou test de source : la première instruction est `SET TRANSACTION READ ONLY`).
5. Mot de passe app vide : `ROLE_APP_CONNEXION=non_configuree` et aucune tentative de connexion (espion sur `DB::connection('pgsql_app')`).
6. Rien de secret : la sortie ne contient ni `config('database.connections.pgsql_app.password')`, ni l'hôte, ni `DB_APP_PASSWORD`.
7. Test de source du workflow : contient `workflow_dispatch`, `default_transaction_read_only=on`, les trois services, ne contient ni `migrate`, ni `restart`, ni `up -d`, ni `cat .env`, ni `printenv`.

**Mutations :** inverser le test `rolsuper` → tests 1 et 2 rouges ; afficher le message d'erreur de connexion → test 6 rouge ; retirer `SET TRANSACTION READ ONLY` → test 4 rouge.

### 3.5 Retour arrière
Rien à défaire en production : aucune écriture. Retirer le lot = annuler la PR.

### 3.6 Geste du fondateur (ESCALADE 6)
Lancer le workflow une fois (onglet Actions, « Diagnostic — rôle applicatif ») et coller la sortie dans la PR G4-A. **G4-A ne s'allume pas sans cette sortie.**

---

## 4. LOT G3-0 — migration seule (une colonne, deux tables)

### 4.1 Fichiers autorisés
- Créer `backend/database/migrations/2026_12_08_000200_double_auth_courriel.php`
- Créer `backend/tests/Feature/Auth/SchemaDoubleAuthCourrielTest.php`
- Modifier les instantanés de `backend/tests/Feature/NonRegression/instantanes/` que la commande de `LISEZ-MOI.md` fait bouger (NR-17 au moins), « Filet modifié : pourquoi » dans la PR.

### 4.2 Contenu (une transaction, `SET LOCAL lock_timeout = '5s'`, gardes `Schema::hasColumn` / `hasTable`)
1. `ALTER TABLE users ADD COLUMN double_auth_methode TEXT NULL CHECK (double_auth_methode IN ('totp','email'))`. NULL = comportement d'aujourd'hui (TOTP si `totp_enabled_at`, sinon rien). Aucune mise à jour de ligne existante.
2. `codes_connexion_courriel` : `id UUID PK DEFAULT gen_random_uuid()`, `user_id UUID NOT NULL REFERENCES users(id) ON DELETE RESTRICT`, `objet TEXT NOT NULL CHECK (objet IN ('connexion','geste'))`, `code_hash TEXT NOT NULL` (HMAC-SHA256 avec la clé de l'application, jamais le code), `essais SMALLINT NOT NULL DEFAULT 0`, `expire_le TIMESTAMPTZ NOT NULL`, `consomme_le TIMESTAMPTZ`, `remplace_le TIMESTAMPTZ`, `ip INET`, `cree_le TIMESTAMPTZ NOT NULL DEFAULT now()`. Index partiel `idx_codes_courriel_actifs ON codes_connexion_courriel (user_id, objet, cree_le DESC) WHERE consomme_le IS NULL AND remplace_le IS NULL`.
3. `appareils_confiance` : `id UUID PK DEFAULT gen_random_uuid()`, `user_id UUID NOT NULL REFERENCES users(id) ON DELETE RESTRICT`, `jeton_hash TEXT NOT NULL UNIQUE` (SHA-256 du jeton du cookie), `nom TEXT NOT NULL` (navigateur et système, ≤ 80 caractères), `ip INET`, `ajoute_le TIMESTAMPTZ NOT NULL DEFAULT now()`, `expire_le TIMESTAMPTZ NOT NULL`, `vu_le TIMESTAMPTZ`, `retire_le TIMESTAMPTZ`. Index partiel `idx_appareils_confiance_actifs ON appareils_confiance (user_id, ajoute_le DESC) WHERE retire_le IS NULL`.
4. `REVOKE DELETE, TRUNCATE ON codes_connexion_courriel, appareils_confiance FROM <rôle applicatif>` (nom lu comme dans `2026_08_14_000001…`, garde « rôle existe »). Aucune colonne `email`, `email_hash`, `person_key`, `phone`, aucune JSONB (constat 6).
5. `down()` réel (NR-17) : `DROP TABLE IF EXISTS` des deux tables **neuves** et `DROP COLUMN IF EXISTS double_auth_methode`. ⚠️ C'est le seul `DROP` admis : il ne porte que sur des objets créés par cette migration, vides tant que G3-A n'est pas déployé. Si le relecteur le refuse, mettre la migration dans `NR17_RETOUR_INERTE_VOULU` (`Nr17MigrationsTest.php`) avec un `down()` inerte — décision du relecteur, pas du développeur.

### 4.3 Tests (rouges d'abord)
1. La colonne existe, NULL partout après migration ; `CHECK` refuse `'sms'` (`23514`).
2. Les deux tables et leurs index partiels existent (`pg_get_indexdef` : prédicat exact).
3. **Sous `pgsql_app`** : `INSERT` et `UPDATE` permis ; `DELETE` et `TRUNCATE` refusés (`42501`). **Sous le propriétaire** : la structure est la même (le propriétaire, superutilisateur en test, n'est pas la barrière : c'est l'absence de droit qui l'est).
4. `ON DELETE RESTRICT` : effacer un `users` qui a une ligne d'appareil lève `23503` (propriétaire).
5. Aucune colonne de `COLONNES_IDENTIFIANTES` ni JSONB dans les deux tables (requête `information_schema`).
6. `down()` puis `up()` (NR-17).
**Mutation :** retirer le `REVOKE` → test 3 rouge ; `ON DELETE CASCADE` → test 4 rouge.

---

## 5. LOT G3-A — canal du code et service des codes (aucune route)

### 5.1 Fichiers autorisés
- Créer `backend/config/double_auth.php` — `actif` = `env('CRM_DOUBLE_AUTH_COURRIEL_ENABLED', false)` ; `duree_code_minutes` = 10 ; `essais_max` = 5 ; `renvois_max` = 3 par 10 min ; `duree_appareil_jours` = 30.
- Créer `backend/app/Services/Auth/CodeCourriel/CanalCodeConnexion.php` (interface : `envoyer(User $u, string $code, string $objet): void`, `estReel(): bool`)
- Créer `backend/app/Services/Auth/CodeCourriel/CanalCodeConnexionCourriel.php`
- Créer `backend/app/Services/Auth/CodeCourriel/FauxCanalCodeConnexion.php`
- Créer `backend/app/Services/Auth/CodeCourriel/CodesConnexionCourriel.php`
- Créer `backend/tests/Feature/Auth/CodesConnexionCourrielTest.php`
- Modifier `backend/tests/Feature/NonRegression/instantanes/nr16-drapeaux.json` (+ `CRM_DOUBLE_AUTH_COURRIEL_ENABLED: false`, « Filet modifié : pourquoi »)

### 5.2 Fichiers chauds à ne pas toucher
`config/envoi.php` (lu seulement), `config/mail.php`, `MagicLinkService.php`, `AppServiceProvider.php` (le choix du canal se fait dans `CodesConnexionCourriel::canal()`, pas par une liaison de conteneur), `StatutOpposition.php` (appelé seulement).

### 5.3 Comportement exact
- **Choix du canal** (`CodesConnexionCourriel::canal()`) : `CanalCodeConnexionCourriel` **si et seulement si** `config('envoi.reel_actif') === true` **et** `config('crm.mock_mode')` est faux ; sinon `FauxCanalCodeConnexion`. Les tests peuvent lier un faux par `app()->instance(CanalCodeConnexion::class, …)` (lu en premier s'il est lié).
- `CanalCodeConnexionCourriel::envoyer` : `Mail::mailer(config('mail.auth_mailer'))->raw(...)` (même canal transactionnel que le lien magique, `MagicLinkService.php:70`), objet « Votre code de connexion », corps sans lien, code à 6 chiffres, durée. `estReel()` = vrai.
- `FauxCanalCodeConnexion::envoyer` : n'envoie rien ; garde le **dernier** code en mémoire du processus (`dernierCode(string $userId): ?string`, pour les tests) ; journalise `auth.code_courriel.faux_canal` **sans le code** (un code dans un journal vaut un mot de passe pendant 10 minutes). `estReel()` = faux.
- `emettre(User $u, string $objet, ?string $ip): void` : refuse (exception `CanalFermeException`) si `config('double_auth.actif')` est faux ; si l'adresse du compte est opposée (constat 13) → n'envoie rien, journal, retour normal ; sinon pose `remplace_le = now()` sur les codes actifs du même `(user_id, objet)`, insère le nouveau (`random_int(0, 999999)`, 6 chiffres complétés par des zéros, `code_hash = hash_hmac('sha256', code, config('app.key'))`, `expire_le = now() + 10 min`), puis appelle le canal **après** l'insertion (si le canal lève, la ligne reste : rien n'est effacé ; l'appelant répond 503 `canal_indisponible`).
- `renvoyer` = `emettre` avec un plafond : plus de 3 émissions `objet='connexion'` en 10 min pour ce compte ⇒ exception `TropDeRenvois` (429 côté route).
- `verifier(User $u, string $objet, string $code): bool` : prend le code actif le plus récent ; expiré ⇒ faux ; `essais >= 5` ⇒ faux sans comparer ; sinon `essais + 1`, comparaison `hash_equals` ; juste ⇒ `consomme_le = now()`, vrai. Un code consommé ne sert jamais deux fois. Tout se fait par `user_id` : jamais un code d'un autre compte.

### 5.4 Tests (rouges d'abord ; `Carbon::setTestNow` en UTC)
1. Drapeau faux ⇒ `emettre` lève `CanalFermeException`, aucune ligne.
2. `ENVOI_REEL_ACTIF` faux (défaut des tests) ⇒ le canal choisi est `FauxCanalCodeConnexion`, `Mail::fake()` n'a **rien** reçu ; `envoi.reel_actif=true` et `mock_mode=false` ⇒ `CanalCodeConnexionCourriel` (sans envoyer : on vérifie le choix, puis `Mail::fake()` reçoit un `raw` sur le mailer d'authentification).
3. Le code n'est jamais stocké en clair (la colonne ne contient pas les 6 chiffres) ni journalisé (`Log::spy`).
4. Bon code ⇒ vrai, puis faux au second usage ; mauvais code 5 fois ⇒ le bon code est refusé ensuite ; après 10 min ⇒ refusé.
5. Une seconde émission remplace la première (l'ancienne a `remplace_le`, est refusée) ; **aucune ligne effacée** (compte avant/après).
6. 4ᵉ émission en 10 min ⇒ `TropDeRenvois`.
7. Cloisonnement : le code de A ne valide pas B.
8. Adresse de compte opposée (ligne `email_suppressions` factice) ⇒ aucun appel au canal, aucune erreur visible.
9. Tests 4, 5, 7 joués **deux fois** : propriétaire et `pgsql_app` (`SET LOCAL ROLE`, modèle `slcSous`, `tests/Feature/Envoi/SchemaLienCampagneSequenceTest.php:77-89`).
**Mutations :** `===` remplacé par `==` sur `reel_actif` (chaîne `"false"`) → test 2 rouge ; supprimer `essais + 1` → test 4 rouge ; `delete()` des anciens codes au lieu de `remplace_le` → test 5 rouge (et `42501` sous `pgsql_app`).

---

## 6. LOT G3-B — connexion par code courriel

### 6.1 Fichiers autorisés
- Créer `backend/app/Http/Controllers/Api/Auth/CodeEmailController.php` (`verifier`, `renvoyer`)
- Modifier `backend/routes/api.php` (2 lignes, à côté de `:78-80`, `auth:sanctum` + `throttle:login`)
- Modifier `backend/app/Http/Middleware/EnsureTwoFactorPassed.php` (liste blanche + exigence pour la méthode courriel)
- Modifier `backend/app/Http/Middleware/EnforceFirstLoginSetup.php` (liste blanche seulement)
- Modifier `backend/app/Services/Auth/AuthService.php` (`requires_2fa`, `:181`), `backend/app/Http/Controllers/Api/Auth/MagicLinkController.php` (`requires_2fa`, `:97`, une ligne) et `backend/app/Http/Controllers/Api/Auth/AuthController.php` (`login` : émission ; `me` : `two_factor_method`)
- Créer `backend/tests/Feature/Auth/ConnexionCodeCourrielTest.php`
- Filet : `nr20-routes.json` (+2), `frontend/tests/non-regression/routes.test.ts` (−2 : `POST /api/v1/auth/code-email/renvoyer`, `POST /api/v1/auth/code-email/verifier`)

### 6.2 Comportement exact
- **Méthode effective** (une seule fonction, `User::methodeDoubleAuth(): ?string` n'est PAS ajoutée au modèle, fichier chaud ; elle vit en statique dans `CodesConnexionCourriel::methode(User)`) : `'email'` si `double_auth_methode = 'email'` **et** `config('double_auth.actif')` ; sinon `'totp'` si `totp_enabled_at` non nul ; sinon `null`.
- `AuthService` (`:181`) et `MagicLinkController` (`:97`, une ligne) : `requires_2fa = methode !== null`. G3-B compte donc 8 fichiers de code et 2 de filet : c'est le seul lot au-dessus de 6, parce que les deux gardes, les deux calculs de `requires_2fa` et les routes doivent changer **ensemble** (séparés, une connexion « courriel » serait ouverte sans code). Le relecteur vérifie la taille (≤ 400 lignes).
- `AuthController::login` : après succès, si méthode `'email'` **et** pas d'appareil de confiance valide (G3-C ; en G3-B : toujours) ⇒ `emettre(objet='connexion')`. Échec du canal ⇒ la connexion répond quand même 200 `requires_2fa: true` (la page propose « Renvoyer »).
- `AuthController::me` : ajoute `two_factor_method` (la méthode effective). Rien d'autre ne change dans la réponse.
- `POST /auth/code-email/verifier {code: 6 chiffres, appareil_confiance?: bool}` : compte non « courriel » ⇒ 409 `methode_non_courriel` ; mauvais code ⇒ 422 `errors.code = ["Code incorrect. Vérifiez-le et réessayez."]` ; bon code ⇒ `session.2fa_passed_at = now()`, `session()->regenerate()`, 200 `{ok: true}`. `appareil_confiance` est **ignoré** en G3-B (servi en G3-C).
- `POST /auth/code-email/renvoyer` : 200 `{ok: true, renvois_restants}` ; plafond ⇒ 429 `trop_de_renvois`.
- `EnsureTwoFactorPassed` : `ALLOW_LIST` + `api/v1/auth/code-email/verifier`, `api/v1/auth/code-email/renvoyer` ; la sortie anticipée (`:49`) devient « méthode effective `null` ». `next_step` reste celui d'aujourd'hui pour le TOTP ; pour le courriel `'/2fa'` (la page choisit l'écran d'après `/auth/me`).
- `EnforceFirstLoginSetup` : mêmes deux chemins ajoutés à la liste blanche, rien d'autre.
- Journal : `AuditLogger::log('auth.code_courriel.verifie' | 'auth.code_courriel.refuse', ['user_id' => …])` (`app/Support/AuditLogger.php:39`), jamais le code.

### 6.3 Tests (rouges d'abord ; HTTP réel avec cookie de session, modèle `Nr22DeuxFacteursTest.php:26-60`)
1. Compte « courriel », drapeau allumé, faux canal lié : `login` ⇒ `requires_2fa: true` et un code émis ; `GET /dashboard/stats` ⇒ 403 `two_factor_required` ; `verifier` avec le code du faux canal ⇒ 200 ; `GET /dashboard/stats` ⇒ 200.
2. Mauvais code ⇒ 422 et la session n'ouvre rien (403 ensuite).
3. Drapeau éteint : le même compte se connecte comme aujourd'hui (méthode `null` ⇒ pas de 2FA ; ou TOTP s'il en a un) ; `/auth/me` ne dit jamais `'email'` ; `verifier` ⇒ 409.
4. Compte TOTP : inchangé (NR-22 reste vert, non modifié).
5. `/auth/me` : `two_factor_method` ∈ {`email`, `totp`, `null`} selon les 3 cas.
6. 4 renvois ⇒ le 4ᵉ rend 429.
7. Cloisonnement : connecté comme A, le code de B ⇒ 422.
8. Les deux routes atteignables par un compte dont `first_login_completed_at` est nul (liste blanche `EnforceFirstLoginSetup`).
9. Test 1 rejoué avec l'application sur `pgsql_app` (connexion par défaut basculée) : aucun `42501`.
**Mutations :** retirer la ligne de `ALLOW_LIST` → test 1 rouge (403 sur `verifier`) ; laisser la sortie anticipée `totp_enabled_at === null` → test 1 rouge (dashboard 200 avant le code).

---

## 7. LOT G3-C — appareils de confiance et code pour un geste

### 7.1 Fichiers autorisés
- Créer `backend/app/Services/Auth/CodeCourriel/AppareilsConfiance.php` (`ajouter`, `reconnaitre`, `lister`, `retirer`)
- Créer `backend/app/Http/Controllers/Api/Compte/SecuriteCompteController.php` (`appareils`, `retirerAppareil`, `envoyerCode`)
- Modifier `backend/app/Http/Controllers/Api/Auth/CodeEmailController.php` (cookie si `appareil_confiance`) et `AuthController.php` (`login` : pas de code si l'appareil est reconnu)
- Modifier `backend/routes/api.php` (3 lignes, dans le groupe protégé, `throttle:password-change`)
- Créer `backend/tests/Feature/Auth/AppareilsConfianceTest.php`
- Filet : `nr20-routes.json` (+3 ; `POST /compte/code` est nouvelle **et** appelée par G3-E seulement), `routes.test.ts` (−2 : `GET /api/v1/compte/appareils`, `POST /api/v1/compte/appareils/{p}/retirer`)

### 7.2 Comportement exact
- Cookie `axion_appareil` : jeton aléatoire 48 caractères, `HttpOnly`, `Secure`, `SameSite=Lax`, 30 jours ; en base seulement `jeton_hash`. Posé par `verifier` si `appareil_confiance === true`.
- `login` d'un compte « courriel » : cookie présent, ligne du **même `user_id`**, non retirée, non expirée ⇒ `2fa_passed_at` posé, aucun code émis, `vu_le = now()`, `requires_2fa: false`. Cookie d'un autre compte ⇒ ignoré (code émis).
- `GET /compte/appareils` : `{data: [{id, nom, lieu, ip, ajoute_le, expire_le, actuel}]}` des appareils **du compte connecté**, non retirés, non expirés ; `lieu` = `"—"` (aucune géolocalisation : pas de service tiers, ESCALADE 5) ; `ip` masquée au dernier octet (`192.0.2.x`) ; `actuel` = jeton du cookie courant.
- `POST /compte/code` : émet un code `objet='geste'` au compte connecté (même plafond que les renvois) ; 200 `{ok: true}`.
- `POST /compte/appareils/{id}/retirer {code}` : code `geste` faux ⇒ 422 ; `id` d'un autre compte ou inconnu ⇒ **404** ; sinon `retire_le = now()` (jamais `DELETE`), journal `compte.appareil.retire`, 200 `{ok: true}`.

### 7.3 Tests (rouges d'abord)
1. `verifier` avec confiance ⇒ cookie posé, une ligne ; reconnexion avec le cookie ⇒ aucun code, accès direct.
2. Après 30 jours (`setTestNow`) ⇒ code redemandé.
3. Retirer avec le bon code ⇒ ligne présente avec `retire_le` (compte de lignes inchangé) ; reconnexion ⇒ code redemandé.
4. Retirer l'appareil de B connecté comme A ⇒ 404, ligne de B intacte.
5. Cookie de B présenté par A au `login` ⇒ code émis pour A.
6. Liste : ni appareils retirés, ni ceux d'un autre compte ; `ip` masquée.
7. Tests 3 et 4 sous propriétaire **et** `pgsql_app`.
**Mutations :** filtre `user_id` retiré de `retirer` → test 4 rouge ; `delete()` → test 3 rouge (et `42501` sous `pgsql_app`).

---

## 8. LOT G3-D — codes de secours

### 8.1 Fichiers autorisés
- Modifier `backend/app/Http/Controllers/Api/Compte/SecuriteCompteController.php` (`codesSecours`, `regenererCodesSecours`)
- Modifier `backend/app/Http/Controllers/Api/Auth/CodeEmailController.php` (`verifier` accepte un code de secours)
- Modifier `backend/routes/api.php` (2 lignes)
- Créer `backend/tests/Feature/Auth/CodesSecoursCourrielTest.php`
- Filet : `nr20-routes.json` (+2), `routes.test.ts` (−2 : `GET /api/v1/compte/codes-secours`, `POST /api/v1/compte/codes-secours/regenerer`)

### 8.2 Comportement exact
- `GET /compte/codes-secours` : `{restants: count(totp_recovery_codes)}`.
- `POST /compte/codes-secours/regenerer {code}` : code `geste` faux ⇒ 422 ; sinon 10 nouveaux codes (`Str::random(10)`, même règle que `TwoFactorService.php:81-91`, sans `strtoupper`), hachés `Hash::make`, **remplacent** le jeu dans `totp_recovery_codes` (les anciens deviennent inutilisables : c'est une mise à jour de colonne, pas une suppression de données de personne) ; 200 `{recovery_codes: [...]}` montrés une seule fois ; journal `compte.codes_secours.regeneres`.
- `verifier` : `code` de 6 chiffres ⇒ code courriel ; `code` de 10 caractères ⇒ code de secours (consommé au premier usage, même logique que `TwoFactorService::verify`, `:115-130`, recopiée dans le service courriel, **sans** toucher `TwoFactorService`). La règle de validation devient `required|string|between:6,10`.

### 8.3 Tests (rouges d'abord)
1. Régénérer avec le bon code ⇒ 10 codes, `restants = 10`, l'ancien jeu refusé.
2. Code de secours à la connexion ⇒ 200, `restants = 9`, le même code refusé ensuite.
3. Mauvais code de geste ⇒ 422, jeu inchangé.
4. Compte A ne voit ni ne régénère les codes de B (chaque route ne lit que `$request->user()`).
5. Un compte TOTP qui régénère par cette route garde un TOTP valide (NR-22 vert).
**Mutation :** oublier de retirer le code consommé → test 2 rouge.

---

## 9. LOT G3-E — écrans (fenêtre de geste, code de secours)

### 9.1 Fichiers autorisés
- Modifier `frontend/src/features/settings/securite/DemandeCode.tsx` (bouton « Recevoir un code par e-mail » ⇒ `POST /compte/code`, message « Code envoyé », 429 ⇒ « Patientez quelques minutes »)
- Modifier `frontend/src/features/auth/CodeEmailPage.tsx` (lien « Utiliser un code de secours » : champ texte 10 caractères, même route `verifier`)
- Modifier `frontend/src/demo/handlers/socle/ecrans-communs.handlers.ts` (servir `POST /compte/code` et le code de secours en démo)
- Créer `frontend/tests/screens/SecuriteCodeCourriel.test.tsx`

### 9.2 Comportement et tests
Les écrans restent cachés en production tant que `/auth/me` ne dit pas `'email'` (`ComptePage.tsx:20-30`, inchangé). Tests : le bouton appelle `POST /compte/code` avant la saisie ; la saisie d'un code de secours envoie 10 caractères ; un 429 affiche le message ; la démo (`VITE_DEMO`) reste fonctionnelle. `routes.test.ts` ne change pas (la route existe depuis G3-C). **Mutation :** retirer l'appel ⇒ test 1 rouge.

---

## 10. LOT G3-F — activation par compte (dernier lot G3)

### 10.1 Fichiers autorisés
- Créer `backend/app/Console/Commands/CrmCompteDoubleAuth.php` (`crm:compte:double-auth {email} {--methode=email|totp}`)
- Créer `backend/tests/Feature/Commands/CrmCompteDoubleAuthTest.php`
- Créer `docs/crm/lots/G3-activation.md` (geste du fondateur, retour arrière)

### 10.2 Comportement exact
- `--methode=email` : refusé (code 1, « canal faux : aucun courriel ne partirait ») si `CodesConnexionCourriel::canal()->estReel()` est faux **ou** `double_auth.actif` faux. Sinon `double_auth_methode = 'email'` et `first_login_completed_at = coalesce(first_login_completed_at, now())` (constat 4), journal `compte.double_auth.methode`.
- `--methode=totp` : `double_auth_methode = 'totp'` (retour à l'application). **Aucune option « aucune »** : la commande ne peut pas désactiver la double authentification.
- Adresse inconnue : code 1, message neutre. Aucune autre colonne touchée.
- **Retour arrière** (écrit dans le document) : `crm:compte:double-auth <adresse> --methode=totp`, ou `CRM_DOUBLE_AUTH_COURRIEL_ENABLED=false` (tous les comptes « courriel » retombent sur la méthode effective du constat G3-B : TOTP s'il existe, sinon aucune 2FA — c'est l'état d'aujourd'hui, ESCALADE 2).

### 10.3 Tests (rouges d'abord)
1. Faux canal (défaut des tests) ⇒ refus, colonne inchangée.
2. Canal réel simulé (`envoi.reel_actif=true`, `mock_mode=false`, `Mail::fake()`) et drapeau allumé ⇒ `email` posé, `first_login_completed_at` posé s'il était nul, conservé sinon.
3. `--methode=aucune` ⇒ refus de l'option.
4. Après activation : parcours complet de G3-B test 1 vert.
**Mutation :** inverser `estReel()` → test 1 rouge.

---

## 11. LOT G4-A — refus de démarrer sans le rôle applicatif

### 11.1 Fichiers autorisés
- Créer `backend/app/Socle/Demarrage/GardeRoleApplicatif.php` (fournisseur de service, patron exact de `GardesDemarrage.php`)
- Modifier `backend/bootstrap/providers.php` (une ligne, après `GardesDemarrage::class`, `:25`)
- Modifier `backend/config/crm.php` (une clé `garde_role_applicatif` = `env('CRM_GARDE_ROLE_APPLICATIF', false)`, à côté de `db_app_role`, `:36`)
- Créer `backend/tests/Unit/GardeRoleApplicatifTest.php`
- Modifier `backend/tests/Feature/NonRegression/instantanes/nr16-drapeaux.json` (+ `CRM_GARDE_ROLE_APPLICATIF: false`)
- Modifier `.env.example` (deux lignes de commentaire + `CRM_GARDE_ROLE_APPLICATIF=false`, sous `:115`)

### 11.2 Comportement exact
`GardeRoleApplicatif::verifier(Application $app)` : rien si l'environnement n'est pas `production` **ou** si `crm.garde_role_applicatif` est faux. Sinon, **sans aucune requête SQL** (une requête au démarrage ferait tomber `artisan` entier si la base est lente) :
1. `crm.db_app_role` faux ⇒ `RuntimeException("Démarrage refusé : le rôle applicatif n'est pas activé (CRM_DB_APP_ROLE_ENABLED).")` ;
2. `database.connections.pgsql.username` égal à `database.connections.pgsql_owner.username` ⇒ refus (« la connexion de l'application est celle du propriétaire ») ;
3. `database.connections.pgsql_app.password` vide ⇒ refus (« mot de passe du rôle applicatif absent (DB_APP_PASSWORD) »).
Les messages ne citent **jamais** une valeur. **Exception unique** : la commande `migrate` lancée avec `--database=pgsql_owner` doit pouvoir tourner même si la garde refuserait (sinon un mauvais `.env` bloque aussi la réparation) : la garde ne s'applique pas quand `$app->runningInConsole()` et que l'argument `--database=pgsql_owner` est présent (`$_SERVER['argv']`). Toute autre commande est soumise.

### 11.3 Tests (rouges d'abord ; patron `tests/Unit/GardesDemarrageTest.php`)
1. Hors production ⇒ jamais de refus.
2. Production, drapeau garde éteint ⇒ jamais de refus, quel que soit `db_app_role`.
3. Production, garde allumée : `db_app_role=false` ⇒ refus 1 ; utilisateur `pgsql` = propriétaire ⇒ refus 2 ; mot de passe vide ⇒ refus 3 ; tout bon ⇒ accepté.
4. Les messages ne contiennent ni le nom d'utilisateur ni le mot de passe configurés.
5. `migrate --database=pgsql_owner` passe ; `migrate` sans option ne passe pas.
6. Test de source : le fichier ne contient ni `DB::`, ni `select`, ni `PDO`.
**Mutation :** `||` au lieu de `&&` dans la condition d'entrée → test 2 rouge.

### 11.4 Activation (geste du fondateur, hors dépôt) et retour arrière
Ordre **imposé**, à écrire dans la PR :
1. Lire la sortie de G4-0 : `VERDICT=role_applicatif_actif` dans `api`, `horizon` **et** `scheduler`. Sinon **ne rien allumer** (ESCALADE 6).
2. Poser `CRM_GARDE_ROLE_APPLICATIF=true` dans le `.env` du serveur, `docker compose up -d --force-recreate --no-deps api horizon scheduler` (`restart` ne relit pas `env_file`, cf. `config/database.php`, commentaire `DB_TIMEZONE`).
3. Relancer G4-0 : même verdict, conteneurs `running`.
**Retour arrière (prêt, une ligne)** : `CRM_GARDE_ROLE_APPLICATIF=false` puis la même recréation. `CRM_DB_APP_ROLE_ENABLED` n'est **jamais** modifié par ce lot ni par ce geste.

---

## 12. LOTS G5 — documents du dépôt

### 12.1 G5-A — TODO et pipeline (après G4-0 lancé)
**Fichiers autorisés :** `TODO.md`, `_AUDIT/DEPLOY-PIPELINE.md`.
- `TODO.md:9` : date de réécriture du jour ; tableau `:38-50` re-mesuré **par commande collée dans la PR** (`ls backend/database/migrations | wc -l`, `find backend/tests -name '*Test.php' | wc -l`, `ls backend/app/Console/Commands | wc -l`, `ls .github/workflows | wc -l`, `ls infra/runbooks | wc -l`, et les deux lignes Modèles / `.tsx`).
- `TODO.md:77`, `:80` : remplacer le prénom par « le fondateur » (règle de `CLAUDE.md`), sens inchangé, citation conservée.
- `TODO.md` § 1.3 et la note finale de `_AUDIT/DEPLOY-PIPELINE.md` : remplacer « contredit » par le **verdict de G4-0**, daté, avec le nom du workflow et le numéro du lancement ; dire si G4-A est allumée. Si G4-0 n'a pas encore été lancé : ne pas fusionner G5-A.
- Ajouter une ligne en § 4 : « double authentification par courriel : livrée, éteinte tant que le canal est faux » avec renvoi à `docs/crm/lots/G3-activation.md` (n'existe qu'après G3-F : ordre 10 > 8).
- **Tests :** `backend/tests/Feature/Infra/DocsDeDeploiementDisentLeVraiTest.php` reste vert (tout chemin cité entre accents graves existe ; un chemin mort s'écrit sans accents graves). Aucun test modifié.

### 12.2 G5-B — R19, CHANGELOG, README (après G3-F)
**Fichiers autorisés :** `docs/envoi/lots/R19.md`, `CHANGELOG.md`, `README.md`.
- `R19.md` : ajouter une section « Re-mesure du <date> » **sans réécrire** la mesure d'origine : couples des contrats, servis, non servis au palier 0, trous (0, les 8 fichiers `trous/*.php` vides), sortie de `vendor/bin/pest tests/Feature/Envoi/CouvertureContratEnvoiTest.php` collée dans la PR.
- `CHANGELOG.md` : section `## [Non publié]` au-dessus de `:5`, rubriques « Ajouté / Modifié / Sécurité », une ligne par vague fusionnée depuis le 2026-05-17 avec le numéro de PR (G0-G2 : #619 et suivantes ; Envoi : plan `docs/envoi/PLAN-FIN-ENVOI.md` ; G3 ; G4). Aucun drapeau présenté comme allumé.
- `README.md` : après « Sprints livrés » (`:54`), une section courte « État actuel » : console CRM, module Envoi **éteint** (`ENVOI_REEL_ACTIF=false`), double authentification (application ; courriel livrée mais éteinte), rôle applicatif (verdict G4-0), renvois vers `TODO.md` et `CHANGELOG.md`.
- **Tests :** `DocsNePrescriventPasDeConteneurAbsentTest.php` reste vert (aucun `docker exec` vers un conteneur absent). Aucun test modifié.

---

## 13. Fichiers chauds (une seule PR à la fois)

| Fichier | G4-0 | G3-0 | G3-A | G3-B | G3-C | G3-D | G3-E | G3-F | G4-A | G5-A | G5-B |
|---|---|---|---|---|---|---|---|---|---|---|---|
| `backend/routes/api.php` | non | non | non | +2 | +3 | +2 | non | non | non | non | non |
| `nr20-routes.json` | non | non | non | +2 | +3 | +2 | non | non | non | non | non |
| `frontend/tests/non-regression/routes.test.ts` | non | non | non | −2 | −2 | −2 | non | non | non | non | non |
| `nr16-drapeaux.json` | non | non | +1 | non | non | non | non | non | +1 | non | non |
| `nr18-workflows-*.json` | oui | non | non | non | non | non | non | non | non | non | non |
| instantanés NR-17 (migrations) | non | oui | non | non | non | non | non | non | non | non | non |
| `EnsureTwoFactorPassed.php`, `EnforceFirstLoginSetup.php` | non | non | non | oui | non | non | non | non | non | non | non |
| `AuthController.php` | non | non | non | oui | oui | non | non | non | non | non | non |
| `CodeEmailController.php` | non | non | non | créé | oui | oui | non | non | non | non | non |
| `bootstrap/providers.php`, `config/crm.php`, `.env.example` | non | non | non | non | non | non | non | non | oui | non | non |
| `TwoFactorService.php`, `MagicLinkService.php`, `User.php`, `GdprErasureService.php`, `GdprPortabilityService.php`, `SemeurTablesScopees.php` | **jamais** |
| `StatutOpposition.php` | appelé seulement (G3-A), jamais modifié |
| `backend/app/Envoi/**`, `config/envoi.php`, `config/database.php`, les quatre drapeaux | **jamais** |

## 14. Rôles : chaque test d'écriture deux fois
Rôle applicatif `pgsql_app` (`SET LOCAL ROLE`, modèle `slcSous`, `tests/Feature/Envoi/SchemaLienCampagneSequenceTest.php:77-89`) ET propriétaire. En test le propriétaire est superutilisateur (`backend/AGENTS.md`, « Nouvelle table ») : le mode propriétaire prouve le filtre `user_id` explicite ; le mode `pgsql_app` prouve que les droits suffisent et que `DELETE` est refusé. Comptes factices `@example.test`, deux comptes A et B dans deux espaces.

## 15. Rien ne part (rappel)
Tant que `ENVOI_REEL_ACTIF` est éteint, G3 choisit le faux canal : aucun courriel. Les tests utilisent `Mail::fake()` et le faux canal ; aucun envoi réel, aucun serveur touché. Aucun lot n'écrit dans une table `envoi_*`, `opt_out`, `email_suppressions`, `contacts`, `personnes`. Une adresse opposée ne reçoit rien (constat 13).

## 16. ESCALADE (décisions du fondateur ; aucune ne bloque le code)
1. **Canal du code de connexion.** Le lien magique part déjà par `MAIL_MAILER_AUTH` sans regarder `ENVOI_REEL_ACTIF`. Le code courriel, lui, est attaché ici à `ENVOI_REEL_ACTIF` (consigne reçue). Conséquence : la double authentification par courriel reste **inactivable** tant que l'envoi commercial est coupé. **Recommandation :** l'aligner sur le lien magique (le code est un courriel d'authentification, pas de prospection) — changement d'une ligne dans `CodesConnexionCourriel::canal()`, à décider par le fondateur. **Par défaut (sans risque) :** la règle stricte du § 5.3 ; le code est prêt, rien n'est activé.
2. **Drapeau `CRM_DOUBLE_AUTH_COURRIEL_ENABLED` éteint alors que des comptes sont en « courriel ».** Ils retombent sur le TOTP s'ils en ont un, sinon sur **aucune** double authentification (comme aujourd'hui). **Recommandation :** n'activer la méthode courriel que pour des comptes qui gardent aussi un TOTP, le temps de la période d'essai. **Par défaut :** comportement décrit, documenté dans `G3-activation.md`.
3. **Lien magique + méthode courriel.** Le lien prouve déjà la boîte aux lettres ; un code par courriel derrière prouve la même chose. **Recommandation :** considérer la 2FA franchie après un lien magique pour un compte « courriel ». **Par défaut (échec fermé) :** le code est demandé aussi après un lien magique.
4. **Adresse de compte opposée** (constat 13). **Par défaut :** aucun code ne part (échec fermé) ; le compte se connecte par TOTP ou par code de secours. Le fondateur dit si un courriel d'authentification doit ignorer l'opposition commerciale.
5. **Lieu de l'appareil.** L'écran affiche une colonne « lieu » ; la remplir demande une base de géolocalisation (achat ou service tiers). **Par défaut :** « — ».
6. **Production.** Lancer `diag-role-applicatif.yml` (G4-0) puis, seulement si les trois conteneurs disent `role_applicatif_actif`, allumer `CRM_GARDE_ROLE_APPLICATIF`. Si le verdict est `superutilisateur_actif`, la bascule du rôle applicatif lui-même (`CRM_DB_APP_ROLE_ENABLED=true`) est une **décision de production** hors de ce cahier : G4-A reste fusionnée et éteinte.
7. **Conservation des codes et appareils.** Aucune purge n'est prévue (règle : rien ne s'efface). Les lignes sont petites (une par connexion). Le fondateur dit s'il veut un jour une durée de conservation ; elle passerait par un lot séparé et son accord écrit.

## 17. Hors périmètre (écrit pour éviter les malentendus)
- Code par SMS, clés de sécurité matérielles, passkeys.
- Écran d'administration qui change la méthode d'un autre compte (seule la commande de G3-F le fait).
- Désactivation de la double authentification par l'utilisateur (aucune route ne le permet).
- Toute modification de `CRM_DB_APP_ROLE_ENABLED`, de `config/database.php` ou du `.env` du serveur par un agent.
- Purge des tables `codes_connexion_courriel` et `appareils_confiance` : **n'existe pas**.
