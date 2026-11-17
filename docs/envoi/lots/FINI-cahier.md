# Cahier FINI — ce qui reste du code de l'envoi avant les achats du fondateur

Conception seule : ce document ne code rien. Il découpe en lots courts (1 à 6 fichiers) tout ce qui manque pour que
l'envoi soit **complet côté code**, sauf ce qui attend les achats et gestes du fondateur : port 25, DNS réels,
serveur réel, chauffe réelle, feu vert écrit.

Base lue : `origin/main` au commit `0a2a426` (#674). Cahiers déjà écrits, **non refaits ici** : VERDICTS (#607),
C1-F2A (#608), X1-L4-P2 (#609), D1-D2 (#617), C2-C3 (#618), G1-G2 (#619), X1-L2-bis (#648), L5-L6 (#649),
B6-B8 (#671), G3-G5 (#672), D3-D6 (#673). Comptes rendus repris : `X5.md`, `X6.md`, `T-CHAUFFE.md`,
`PLAN-FIN-ENVOI.md` (D1, D3, D5, D11, section 9).

Règles communes à TOUS les lots (rappel, non négociables) :
- échec fermé : toute erreur de lecture, d'inventaire ou de configuration ⇒ rien ne part (report `ATTENTE` ou refus) ;
- aucune suppression ni purge de données de personnes ; migrations en **ajout seul** (aucun `DROP`, aucun `DELETE`) ;
- cloisonnement par espace : chaque lecture et écriture sous `WorkspaceContext::run($ws, …)` **et** filtrée par
  `workspace_id` ; un test par lot prouve qu'un espace B ne voit ni ne touche rien de A ;
- drapeaux **jamais touchés** : `ENVOI_REEL_ACTIF`, `ORIGINES_LECTURE_ENABLED`, `CRM_GUIDE_LIEN_ENABLED`,
  `USINE_AGREGATS_CRM_ENABLED` (ni valeur, ni défaut, ni lecture nouvelle hors des gardes existantes) ;
- tout reste inerte tant que `ENVOI_REEL_ACTIF=false` : aucun test ne doit ouvrir une connexion réseau réelle ;
- tests ROUGES d'abord, sortie rouge collée dans le compte rendu du lot (`docs/envoi/lots/<LOT>.md`) ;
- chaque test de base de données tourne **deux fois** : connexion `pgsql_app` (rôle applicatif, RLS armée, contexte
  d'espace posé) ET connexion propriétaire (`pgsql`, superutilisateur : prouve que le filtre `workspace_id` explicite
  tient même sans RLS) ; modèle : `EnvoiAutoriseOppositionArt21Test` (X7) ;
- mutations : chaque garde nouvelle est retirée ou forcée à `true` une fois, le test doit rougir, mutation annulée,
  sortie collée ;
- adresses d'exemple en `*.example.test`, IP d'exemple en `192.0.2.0/24` ou `198.51.100.0/24` (RFC 5737), jamais
  une vraie ;
- `vendor/bin/pint --dirty`, PHPStan niveau 8 sur les fichiers de `app/` touchés, tests ciblés `--filter`.

---

## 1. État vérifié dans `main` (étape 1)

| Point | État | Preuve (fichier:ligne sur `main`) |
|---|---|---|
| X11 — table étendue P1-P8 écrite | **fait** | `backend/app/Envoi/Chauffe/CourbeChauffe.php:68-77` (`PALIERS_ETENDUS`), `:89-108` |
| X11 — table étendue lue par l'écran et le budget | **fait** | `EnvoiChauffeController.php:132-155` ; `BudgetChauffe.php:82-84` |
| X11 — table étendue lue par la GARDE | **MANQUE** | `GardeChauffe.php:76` lit `quotaBoite()` (table vivante `PALIERS`, P3 sans fin, `CourbeChauffe.php:44-48`) ; `:258-268` `jourDeChauffe` sans reculs ; seuls les reculs de domaine passent par la table étendue (`:104-112`) |
| X11 — 2e boîte : quota par boîte et par domaine | **fait** (garde) | `GardeChauffe.php:83-93` (boîte, puis `DOMAINE_FACTEUR × quota` du domaine) |
| X11 — rotation entre boîtes | **MANQUE** | `SourceExpeditionDb.php:66-79` rend la PREMIÈRE boîte active (`orderBy('cree_le')`) ; `Sequenceur.php:90`, `:336`, `:347` : un seul `expediteur` fixé au constructeur |
| X5 — étiquette `flux` des domaines | **fait** | migration R07 (`envoi_domaines.flux`, `CHECK prospection|lettre`) ; `ListeFeuVert.php:416-428` |
| X5 — séparation lettre/prospection des IP | **MANQUE** | `ListeFeuVert.php:427` rend toujours `false` (« pas de source lisible ») ; aucune table d'IP (`grep envoi_ips` vide) ; `X5.md`, ESCALADE 2 |
| X5 — signal « serveur réel » des 22 points | **MANQUE** | `CatalogueManuels.php:49-…` : 22 points `'serveur_reel' => true` ; `ListeFeuVert.php:86-87` : `$attend = $c['serveur_reel']` ⇒ toujours `ETAT_ATTEND_SERVEUR`, jamais fait ; `X5.md`, ESCALADE 1 |
| X5 — fraîcheur de la batterie (15 min) | **MANQUE** | `ListeFeuVert.php:48-51` : `dnsOk` et `listesOk` lisent la DERNIÈRE ligne sans regarder son âge ; `DecisionFeuVert.php:43-66` n'exige aucune fraîcheur (seulement l'empreinte `If-Match`) |
| X6 — reculs de domaine dans la garde | **fait** | `GardeChauffe.php:80-112` ; `X6.md` |
| X6 — reculs d'IP | **MANQUE** | aucun code n'écrit `ip:<clé>` dans `envoi_chauffe_jours` (`GardeChauffe::noter`, `:173-178`, n'incrémente que la boîte) ; `DepotChauffe.php:30-36` sait l'écrire ; `T-CHAUFFE.md` « Hors lot X6 » |
| C4 — retrait CRM → passerelle (contrat v3) | **fait** | `ServeurEnvoiKumo.php:424-431` ; `infra/serveur-envoi/passerelle/src/Passerelle.php:248-315` |
| C4 — TR-KUMO-ADMIN (la passerelle parle à KumoMTA) | **MANQUE** | `infra/serveur-envoi/passerelle/public/index.php:37` câble `new KumoInactif` ; seuls `KumoInactif` et `FauxKumo` implémentent `Kumo` (`src/Kumo.php:18-29`) ; `file_age_s` et `tunnel_ok` omis (`C2-C3-cahier.md:103-104`) |
| X9 — variables d'environnement documentées | **MANQUE (18)** | `backend/config/envoi.php` lit 29 variables `ENVOI_*` ; 18 absentes de `.env.example` (l. 626-649 n'en portent que 11) : `ENVOI_CONTACT_DESINSCRIPTION`, `ENVOI_DESINSCRIPTION_VALIDITE_JOURS`, `ENVOI_DNS_DOMAINES`, `ENVOI_LISTES_IPS`, `ENVOI_LISTES_NON_FIABLE_BLOQUE_APRES_MINUTES`, `ENVOI_SANTE_ACTIF`, `ENVOI_SERVEUR_CA_PATH`, `ENVOI_SERVEUR_CLE_ID`, `ENVOI_SERVEUR_CLE_IDS`, `ENVOI_SERVEUR_CLIENT_CERT_PATH`, `ENVOI_SERVEUR_CLIENT_KEY_PATH`, `ENVOI_SERVEUR_CONNECT_TIMEOUT`, `ENVOI_SERVEUR_EMPREINTE_CLE`, `ENVOI_SERVEUR_SECRETS`, `ENVOI_SERVEUR_TIMEOUT`, `ENVOI_SERVEUR_URLS`, `ENVOI_SMTP_DELAI_BOITE_BLOQUEE_MINUTES`, `ENVOI_TEMOINS_RELEVE_ACTIF` |
| X9 — procédure du jour J | **MANQUE** | aucun document (`grep -ri "jour J" docs infra` vide) ; `infra/serveur-envoi/RUNBOOK.md` couvre l'installation, pas la bascule |
| F3 — reprise après « Tout arrêter » (commande locale) | **fait** | `EnvoiReprendre.php` ; `ToutArreter.php` `reprendre()` dans le contexte de l'espace ; verrou `X6.md` |
| F3 — reprise par l'écran | **MANQUE, et INTERDITE par D1** | `PLAN-FIN-ENVOI.md:82` ; gardes `Routes/BoitesRoutesTest.php:195`, `Routes/AucuneRouteTemoinSingulierTest.php:13`, `AucuneRouteNeCoupeLesTemoinsTest.php:13`, `FeuVertRoutesTest.php:283` ⇒ ESCALADE E3 |
| F3 — ajout d'une boîte par l'écran | **MANQUE** | `routes/envoi/boites.php:16-19` : `POST /envoi/boites` ⇒ 501 `serveur_reel_requis` ; `tests/Support/Couverture/non-servis/boites.php:7` |

Plage de migrations demandée `2026_12_07_000100` à `000290` : **libre** sur `main` et sur les 335 branches distantes
(vérifié par `git ls-tree` de chaque branche le 2026-11-17). Ce cahier en prend 5 : `000100`, `000110`, `000120`,
`000130`, `000140`. Les numéros `000150` à `000290` restent libres pour les reprises.

---

## 2. Vagues (parallélisme maximal)

Une vague = des lots **sans aucun fichier commun** entre eux : plusieurs routines les codent en même temps, chacune sur
sa branche depuis `main`. Une vague ne part qu'après la **fusion** des lots dont elle dépend (et le déploiement des
migrations quand c'est dit).

| Vague | Lots (en parallèle) | Attend |
|---|---|---|
| **0** | FINI-M0A, FINI-M0B, X11-A, C4-A, X9-A, X5-C, F3-B0 | rien |
| **1** | X5-A, X6-A, X11-B, C4-B, F3-B1 | M0A et M0B fusionnées ET déployées ; X5-C fusionnée (pour X5-A, même fichier) ; C4-A (pour C4-B) ; F3-B0 (pour F3-B1) |
| **2** | X5-B, X6-B, F3-B2, (F3-R seulement si E3 = oui) | X5-A et X6-A (pour X5-B) ; X6-A et X11-A (pour X6-B) ; F3-B1 (pour F3-B2) |
| **3** | X9-B | tous les autres fusionnés |

Fichiers chauds (un seul lot à la fois, d'où l'ordre) : `ListeFeuVert.php` (X5-C → X5-A → X5-B),
`GardeChauffe.php` (X11-A → X6-B), `passerelle/src/Kumo.php` et `public/index.php` (C4-A → C4-B),
`EnvoiBoitesController.php` + `routes/envoi/boites.php` (F3-B1 seul), `infrastructure.yaml` (F3-B0 seul),
`.env.example` et `docs/envoi/JOUR-J.md` (X9-A → X9-B), `Sequenceur.php` (X11-B seul ; **vérifier qu'aucun lot RR-*
de #673 ne le tient au même moment** : sinon X11-B attend sa fusion).

Vérification « aucun fichier commun » de la vague 0 : M0A (2 migrations + 1 test), M0B (3 migrations + 1 test),
X11-A (`GardeChauffe.php`, `Seuils.php`, 1 test), C4-A (`infra/…/passerelle/src/KumoHttp.php`, `src/Stockage.php`,
2 fichiers de test), X9-A (`.env.example`, `docs/envoi/JOUR-J.md`, 1 test), X5-C (`ListeFeuVert.php`,
`CatalogueManuels.php`, `DecisionFeuVert.php`, 2 tests), F3-B0 (`infrastructure.yaml`, `infrastructure.d.ts`,
`infrastructure.handlers.ts`, `trous/boites-ajout.php`) : disjoints. Idem vague 1 et 2 (listes par lot ci-dessous).

---

## 3. Lots de la vague 0

### FINI-M0A — migration seule : inventaire des IP d'envoi (X5, X6)

**Fichiers autorisés**
- C `backend/database/migrations/2026_12_07_000100_envoi_ips.php`
- C `backend/database/migrations/2026_12_07_000110_envoi_domaines_ip.php`
- C `backend/tests/Feature/Envoi/MigrationsIpEnvoiTest.php`
- C `docs/envoi/lots/FINI-M0A.md`
- M `backend/tests/Feature/NonRegression/Nr17MigrationsTest.php` : seulement les 2 migrations ajoutées à
  `NR17_RETOUR_INERTE_VOULU`, après la fusion de #675 (décision de pilotage, commentaire sur #680). Ordre imposé sur ce
  fichier : #675, puis #683 (M0A), puis #685 (M0B).

**Comportement**
- `000100` : table `envoi_ips` : `id UUID PK`, `workspace_id UUID NOT NULL REFERENCES workspaces(id)`,
  `ref TEXT NOT NULL` (IPv4 en notation pointée, `CHECK (ref ~ '^[0-9]{1,3}(\.[0-9]{1,3}){3}$')`),
  `flux TEXT NOT NULL CHECK (flux IN ('prospection','lettre'))`, `auteur TEXT NOT NULL`, `cree_le TIMESTAMPTZ NOT NULL
  DEFAULT now()`, `retiree_le TIMESTAMPTZ NULL` ; `UNIQUE (workspace_id, ref)` ; `UNIQUE (workspace_id, id)`
  (cible des clés composites) ; RLS `ENABLE` + `FORCE`, politique sur `app.current_workspace_id` (modèle
  `2026_12_05_000400_envoi_boites_acces_imap.php`) ; déclencheur « ajout seul » : `DELETE`/`TRUNCATE` refusés,
  `UPDATE` permis **seulement** de `retiree_le` NULL → non NULL (jamais l'inverse, jamais `ref` ni `flux`) ; message
  préfixé `envoi_ips :`. Une IP ne change jamais de flux : on la retire et on en inscrit une autre (sens sûr).
- `000110` : `ALTER TABLE envoi_domaines ADD COLUMN IF NOT EXISTS ip_id UUID NULL` + clé composite
  `(workspace_id, ip_id) REFERENCES envoi_ips (workspace_id, id)` `NOT VALID` puis `VALIDATE`. La table
  `envoi_domaines` a `REVOKE UPDATE` de table et `GRANT UPDATE` par colonnes (S1-B, D11) : **aucun `GRANT` sur
  `ip_id`**. Fonction `SECURITY DEFINER` `envoi_domaine_ip_poser(p_ws UUID, p_domaine TEXT, p_ip_ref TEXT, p_auteur TEXT)`
  (clause `SET search_path`, filtre `workspace_id = p_ws`, l'IP doit exister, être non retirée et du **même flux** que
  le domaine sinon `RAISE` ; journalise une ligne `envoi_journal_commandes` `domaine_ip`, détails sans adresse de
  personne) ; `GRANT EXECUTE` au rôle applicatif. `down()` inerte (ajout seul).

**Tests rouges d'abord** (`pgsql_app` ET propriétaire)
1. table et colonne absentes ⇒ rouge sur `main` ;
2. `UPDATE envoi_ips SET flux = …` ⇒ refus ; `DELETE`, `TRUNCATE` ⇒ refus ; `retiree_le` remis à NULL ⇒ refus ;
3. rôle applicatif : `UPDATE envoi_domaines SET ip_id = …` ⇒ `42501` ; la fonction le pose ;
4. fonction : IP d'un autre espace ⇒ refus ; IP retirée ⇒ refus ; flux différent du domaine ⇒ refus ; domaine `flux`
   NULL ⇒ refus ;
5. espace B sous contexte : 0 ligne de A.

**Mutations** : retirer la condition « même flux » de la fonction ⇒ test 4 rouge ; retirer `FORCE ROW LEVEL SECURITY`
⇒ test 5 rouge sur `pgsql_app`.

**Dépendances** : aucune. **À déployer** avant X6-A et X5-B.

### FINI-M0B — migration seule : attestation serveur réel, boîte d'une inscription, codes d'ajout de boîte

**Fichiers autorisés**
- C `backend/database/migrations/2026_12_07_000120_envoi_attestations_serveur_reel.php`
- C `backend/database/migrations/2026_12_07_000130_envoi_inscriptions_boite.php`
- C `backend/database/migrations/2026_12_07_000140_envoi_boites_codes_ajout.php`
- C `backend/tests/Feature/Envoi/MigrationsFiniTest.php`
- C `docs/envoi/lots/FINI-M0B.md`
- M `backend/tests/Feature/NonRegression/Nr17MigrationsTest.php` : seulement les 3 migrations ajoutées à
  `NR17_RETOUR_INERTE_VOULU`, **après la fusion de #675** (décision de pilotage, commentaire sur #680)
- M `backend/tests/Support/SemeurTablesScopees.php` : seulement `envoi_attestations_serveur_reel` et
  `envoi_boites_codes_ajout` semées (même décision). Rien d'autre hors liste.
- Même fichier `Nr17MigrationsTest.php` : une exception **nommée et motivée** pour le seul aller-retour de
  `2026_11_10_000040_envoi_boites.php` (son retour réel est bloqué par la clé étrangère composite de `000130` ; les
  inscriptions sont conservées), avec la ligne « Filet modifié : pourquoi » dans `FINI-M0B.md` (2e décision de
  pilotage, commentaire sur #680). Le retour réel reste exigé pour toutes les autres migrations ; ni `000130` ni
  `000040` ne sont modifiées. Relecture : l'exception ne couvre QUE ce fichier, aucune autre garde affaiblie, sinon
  BLOQUÉ.

**Comportement**
- `000120` : `envoi_attestations_serveur_reel` : `id`, `workspace_id`, `geste TEXT CHECK (geste IN ('atteste','retire'))`,
  `auteur TEXT NOT NULL`, `motif TEXT NOT NULL CHECK (length(motif) BETWEEN 10 AND 500 AND position('@' in motif) = 0)`,
  `le TIMESTAMPTZ NOT NULL DEFAULT now()` ; ajout seul (déclencheur refuse `UPDATE`, `DELETE`, `TRUNCATE`) ; RLS forcée.
  La dernière ligne de l'espace fait foi.
- `000130` : `envoi_inscriptions ADD COLUMN IF NOT EXISTS boite_id UUID NULL`, clé composite
  `(workspace_id, boite_id) REFERENCES envoi_boites (workspace_id, id)` (`envoi_boites_workspace_id_id_unique`
  existe : `2026_11_10_000042…:34`). NULL = « pas encore choisie » (toutes les inscriptions existantes). **Vérifier**
  que `envoi_inscriptions` porte `UNIQUE (workspace_id, id)` ; sinon ne rien ajouter d'autre et le signaler.
  Déclencheur : une fois non NULL, `boite_id` ne change plus (un fil garde son expéditeur).
- `000140` : `envoi_boites_codes_ajout` : `id`, `workspace_id`, `user_id`, `empreinte TEXT NOT NULL` (HMAC du code,
  jamais le code), `demande_empreinte TEXT NOT NULL` (SHA-256 du corps de la demande d'ajout SANS le secret),
  `tentatives SMALLINT NOT NULL DEFAULT 0 CHECK (tentatives BETWEEN 0 AND 5)`, `expire_le`, `consomme_le NULL`,
  `cree_le` ; index `(workspace_id, user_id, cree_le DESC)` ; `UNIQUE (id) WHERE consomme_le IS NOT NULL` inutile :
  la consommation se fait par `UPDATE … WHERE consomme_le IS NULL RETURNING` ; RLS forcée ; `DELETE` refusé (rétention :
  aucune donnée de personne ici, mais ajout seul par règle).

**Tests rouges d'abord** (`pgsql_app` ET propriétaire) : objets absents sur `main` ; attestation : `UPDATE`/`DELETE`
refusés, motif avec `@` refusé ; inscription : `boite_id` d'une boîte d'un autre espace ⇒ violation de clé ;
`boite_id` changé une fois posé ⇒ refus ; codes : `tentatives = 6` ⇒ refus ; cloisonnement B/A.

**Mutations** : retirer le déclencheur « boîte figée » ⇒ rouge ; retirer le `CHECK` du `@` ⇒ rouge.

**Dépendances** : aucune. **À déployer** avant X5-A, X11-B, F3-B1.

### X11-A — courbe étendue branchée dans la garde, plafonnée à P3 par défaut

**Fichiers autorisés** : M `backend/app/Envoi/Chauffe/GardeChauffe.php` ; M `backend/app/Envoi/Chauffe/Seuils.php` ;
C `backend/tests/Feature/Envoi/ChauffeCourbeEtendueGardeTest.php` ; M `docs/envoi/lots/T-CHAUFFE.md` ;
C `docs/envoi/lots/X11-A.md`.
**Fichiers chauds** : `GardeChauffe.php` (X6-B attend).

**Comportement**
- `Seuils::PALIER_MAX = 3` (constante, commentée « ESCALADE E1 : passer à 8 = décision écrite du fondateur »).
- `GardeChauffe::refus()` calcule le jour de chauffe de la boîte par la table étendue AVEC reculs du domaine
  (`CourbeChauffe::jourDeChauffeAvecReculs`) puis le **borne** : `min(jour, dernier jour du palier PALIER_MAX)` pour un
  palier sans fin (P3 = `jusqua` 15 dans `PALIERS_ETENDUS` : jour borné à 15 et au-delà quota 25 = valeur actuelle).
  Quota boîte = `CourbeChauffe::quotaBoiteEtendu(jour borné)`.
- La règle « palier tenu après plainte » (`:258-268`) est conservée telle quelle, appliquée au jour borné.
- Effet avec `PALIER_MAX = 3` : **aucune montée** par rapport à `main` (P1 10, P2 18, P3 25 sans fin) ; seul
  changement : la garde et l'écran lisent la même table (fin de l'écart « deux tables »).
- `plafondDomaineAvecReculs` inchangé. L'alignement 30/20 (`DOMAINE_FACTEUR`) n'est PAS touché (ESCALADE E2).

**Tests rouges d'abord** : (1) jour 40 sans recul ⇒ quota 25 (vert sur main, garde de non-régression) ; (2) la garde
appelle la table étendue : un test qui fixe `PALIER_MAX` à 8 par une sous-classe de test ou un paramètre de
constructeur `?int $palierMax = null` (défaut `Seuils::PALIER_MAX`) ⇒ jour 18 : quota 50 (rouge sur main) ;
(3) jour 18 avec un recul de domaine la veille ⇒ quota du palier inférieur ; (4) plainte récente ⇒ palier tenu ;
(5) cloisonnement : recul de l'espace B sans effet sur A ; `pgsql_app` ET propriétaire.
**Mutations** : retirer la borne ⇒ (1) rouge (quota 150 au jour 40 si le test fixe 8, sinon le test (1) prouve 25) ;
remplacer `quotaBoiteEtendu` par `quotaBoite` ⇒ (2) rouge.
**Dépendances** : aucune.

### C4-A — TR-KUMO-ADMIN : `KumoHttp`, l'adaptateur de la passerelle vers l'API KumoMTA (faux serveur seulement)

**Fichiers autorisés** (dossier `infra/serveur-envoi/passerelle/`) : C `src/KumoHttp.php` ; M `src/Stockage.php`
(table `suspensions`) ; C `tests/KumoHttpTest.php` ; C `tests/faux-kumomta/routeur.php` (faux KumoMTA HTTP lancé par
`php -S 127.0.0.1:<port libre>` dans le test) ; C `docs/envoi/lots/C4-A.md`.
**Fichiers chauds** : `src/Stockage.php`. **Non touchés** : `public/index.php` (câblage : C4-B), `src/Kumo.php`.

**Comportement** (`KumoHttp implements Kumo`, base `http://127.0.0.1:8000` passée au constructeur ; **refus** de tout
hôte autre que `127.0.0.1` ou `localhost` : `RUNBOOK.md` « API KumoMTA : 127.0.0.1:8000 ») :
- `injecter` ⇒ `POST /api/inject/v1` (`envelope_sender` = `mail_from`, `recipients` = `[rcpt_to]`, `content` =
  octets, en-têtes de méta `tenant` = espace, `campaign` = `id_injection`). 2xx ⇒ accepté ; 4xx ⇒ refus définitif
  (`RefusKumo`) ; 5xx, délai, connexion refusée ⇒ exception « injoignable » (la passerelle répond déjà 503 : pas de
  faux « envoyé »).
- `pause` ⇒ `POST /api/admin/suspend/v1` `{tenant: <ws>, reason: "tout-arreter", duration: "30d"}` ; l'`id` rendu est
  stocké (`Stockage::noterSuspension($ws, $id)`). Idempotent : une suspension déjà notée et non levée ⇒ aucun appel.
- `reprise` ⇒ `DELETE /api/admin/suspend/v1` `{id}` pour CHAQUE suspension notée de l'espace, puis marquée levée.
  Aucune suspension notée ⇒ aucun appel, succès (la reprise ne peut rien ouvrir d'autre).
- `retrait` ⇒ pour chaque cible : `POST /api/admin/bounce/v1` `{campaign: <id_injection>, tenant: <ws>,
  reason: "retrait-opposition", duration: "30d"}` (un message en file de CETTE injection est rebondi ; un rejeu
  arrivé dans les 30 jours aussi : cohérent avec la liste de retrait C3-1). Jamais de rebond sans `campaign` (un rebond
  sans critère viderait toute la file : refus local, exception).
- Délais : connexion 2 s, total 5 s ; aucune nouvelle tentative interne (le rejoueur du CRM rejoue).
- **Hypothèse à confirmer sur le vrai serveur** (ESCALADE E5, non bloquante) : noms exacts des champs de
  `/api/admin/bounce/v1` et `/api/admin/suspend/v1` de la version d'image épinglée dans `docker-compose.yml`. Le faux
  serveur reproduit les champs ci-dessus ; un écart réel se corrige dans `KumoHttp` seul.

**Tests rouges d'abord** (`phpunit` de la passerelle ; faux KumoMTA qui écrit chaque requête reçue dans un fichier du
dossier temporaire du test) : injection ⇒ 1 requête `inject/v1` avec `campaign` = id ; 5xx ⇒ exception, jamais
« accepté » ; pause deux fois ⇒ 1 seule requête `suspend` ; reprise ⇒ `DELETE` avec l'id noté ; reprise sans pause ⇒
0 requête ; retrait de 3 cibles ⇒ 3 `bounce` portant chacun sa `campaign` et le `tenant` ; retrait d'une liste vide ⇒
exception, 0 requête ; hôte `10.0.0.1` ⇒ refus à la construction ; espace B : `reprise` ne lève pas la suspension de A.
**Mutations** : retirer le filtre `tenant` du `DELETE` ⇒ test B/A rouge ; retirer la garde « cibles vides » ⇒ rouge.
**Dépendances** : aucune.

### X9-A — variables d'environnement de l'envoi documentées et procédure du jour J (première version)

**Fichiers autorisés** : M `.env.example` ; C `docs/envoi/JOUR-J.md` ;
C `backend/tests/Feature/Envoi/VariablesEnvoiDocumenteesTest.php` ; C `docs/envoi/lots/X9-A.md`.
**Fichiers chauds** : `.env.example` (X9-B après).

**Comportement**
- `.env.example` : les 18 variables manquantes (liste section 1) ajoutées dans le bloc envoi, **valeurs vides ou
  défauts sûrs** identiques à ceux de `config/envoi.php` (ex. `ENVOI_SANTE_ACTIF=false`,
  `ENVOI_TEMOINS_RELEVE_ACTIF=false`, `ENVOI_SERVEUR_URLS=`, `ENVOI_SERVEUR_SECRETS=`), chacune avec une ligne de
  commentaire : rôle, qui la pose, à quel moment du jour J. `ENVOI_REEL_ACTIF=false` **inchangé** (ligne 626 non
  touchée). Aucun secret, aucune IP, aucun domaine réel (marqueurs `<…>`).
- `docs/envoi/JOUR-J.md` : procédure ordonnée, chaque étape = geste, commande exacte, vérification, retour arrière :
  J-30 achats (fondateur) ; J-21 serveur installé (`RUNBOOK.md`) ; J-21 DNS publiés, `envoi:dns-controler` vert ;
  J-21 IP inscrites (`envoi:ip-inventaire`, X6-A) et domaines reliés (`envoi:domaine-ip`) ; J-20 attestation serveur
  réel (`envoi:serveur-reel-attester`, X5-A) puis preuves des 22 points ; J-20 témoins ; J-3 trois jours témoins verts ;
  J-1 essai à blanc ; J0 : batterie relancée (< 15 min, X5-C), feu vert accordé à l'écran, `ENVOI_REEL_ACTIF=true`
  posé **par le fondateur** sur le serveur du CRM, redémarrage, `envoi:domaine-adaptateur … kumo` ; coupure :
  « Tout arrêter » d'abord, drapeau ensuite (`PLAN-FIN-ENVOI.md:456`). Les commandes pas encore livrées sont marquées
  « (lot X…) ».

**Tests rouges d'abord** : chaque `env('ENVOI_…')` de `config/envoi.php` (lu par expression régulière) figure dans
`.env.example` ET dans `docs/envoi/JOUR-J.md` (rouge sur main : 18 manquantes) ; `.env.example` porte toujours
`ENVOI_REEL_ACTIF=false` ; aucune valeur de `.env.example` du bloc envoi ne ressemble à une IP publique, un domaine
hors `example.test`, ou une clé de plus de 16 caractères.
**Mutations** : retirer une variable du `.env.example` ⇒ rouge.
**Dépendances** : aucune.

### X5-C — fraîcheur de la batterie : contrôles DNS et listes de moins de 15 minutes pour accorder

**Fichiers autorisés** : M `backend/app/Envoi/FeuVert/ListeFeuVert.php` ; M `backend/app/Envoi/FeuVert/CatalogueManuels.php` ;
M `backend/app/Envoi/FeuVert/DecisionFeuVert.php` ; C `backend/tests/Feature/Envoi/FeuVertBatterieFraicheTest.php` ;
M `backend/tests/Feature/Envoi/FeuVertListeTest.php` (comptes seulement : voir « filet modifié ») ;
M `backend/tests/Feature/Envoi/FeuVertDecisionTest.php`, `FeuVertRoutesTest.php`, `FeuVertCatalogueV4Test.php`,
`FeuVertFreinsSignauxTest.php` : comptes seulement (48 → 49 points, version 5 → 6), aucune assertion retirée
(décision de pilotage, commentaire sur #680) ;
C `docs/envoi/lots/X5-C.md`.
**Fichiers chauds** : `ListeFeuVert.php`, `CatalogueManuels.php` (X5-A, X5-B attendent).

**Comportement**
- « Batterie » = les contrôles automatiques lus par le feu vert : dernière ligne `envoi_controles_dns` de chaque
  domaine et dernière ligne `envoi_controles_listes` de chaque ressource.
- Nouveau point `auto.batterie_fraiche` (groupe `dns`, bloquant, `serveur_reel` faux) : fait seulement si la PLUS
  ANCIENNE de ces « dernières lignes » a moins de 15 minutes (`BATTERIE_FRAICHEUR_SECONDES = 900`, horloge de la base
  `now()`, jamais celle de PHP) ET qu'il y a au moins une ligne de chaque table. Aucun contrôle ⇒ rouge. Détail :
  « Relancer les contrôles DNS et listes (moins de 15 min) avant d'accorder. » ; jamais d'appel réseau.
- `CatalogueManuels::VERSION` 5 → 6 (point ajouté, aucun libellé existant changé).
- `DecisionFeuVert::decider('accorde')` : le point est recalculé sous le verrou comme les autres (il est dans
  l'empreinte) ; il est bloquant ⇒ 409 s'il est rouge. « retire » : inchangé, toujours accepté.
- Effet : l'empreinte change quand la batterie vieillit au-delà de 15 min ⇒ un écran lu à 14 min puis accordé à 16 min
  reçoit 409 (bloquant rouge), jamais un accord sur une batterie périmée.

**Filet modifié : pourquoi** : `FeuVertListeTest` compte les points et les bloquants ; un point bloquant de plus
change ces nombres (comme X5 l'a fait). L'aide `toutVert()` pose deux contrôles frais. Aucune assertion retirée.
**Tests rouges d'abord** (`pgsql_app` ET propriétaire) : contrôles de 14 min ⇒ fait ; DNS de 16 min, listes fraîches ⇒
rouge ; listes absentes ⇒ rouge ; `accorde` avec batterie de 16 min et `If-Match` juste ⇒ 409, aucune ligne de
décision ; `retire` ⇒ 200 ; contrôles frais de l'espace B ne rendent pas A vert ; l'empreinte change au passage des
15 minutes (horloge de la base avancée par `SET LOCAL` d'un horodatage de ligne, pas par `sleep`).
**Mutations** : remplacer `900` par `PHP_INT_MAX` ⇒ rouge ; ne lire que la table DNS ⇒ rouge.
**Dépendances** : aucune.

### F3-B0 — contrat de l'ajout de boîte (code de confirmation)

**Fichiers autorisés** : M `backend/openapi/envoi/infrastructure.yaml` ; M `frontend/src/features/envoi/api/infrastructure.d.ts`
(régénéré par `pnpm api:envoi`) ; M `frontend/src/demo/handlers/envoi/infrastructure.handlers.ts` ;
C `docs/envoi/lots/F3-B0.md`.

**Comportement** : deux chemins (ESCALADE E4 pour le secret) :
- `POST /envoi/boites/ajout/code` : corps `{adresse, hote, port, chiffrement, utilisateur}` (SANS secret) ⇒ `202
  {expire_le}` ; codes `403` (non propriétaire), `422` (forme), `409 domaine_inconnu` (domaine de l'adresse absent de
  `envoi_domaines` de l'espace), `429` (plus de 3 codes en 15 min), `503 transport_courriel_non_sur` (règle R11).
- `POST /envoi/boites` (existant, aujourd'hui 501) : corps `{adresse, hote, port, chiffrement, utilisateur, secret,
  code}` ⇒ `201 {id, etat: "en_pause", pause_motif: "suspension_manuelle"}` ; `403`, `409 code_invalide`
  (faux, expiré, consommé, 5 essais, demande différente de celle du code), `409 boite_existante`, `422`.
- Le `501` de `POST /envoi/boites` reste déclaré (le serveur le rend encore jusqu'à F3-B1).
- Démo : les deux gestionnaires (code « 123456 » accepté, autre ⇒ 409).
Fichier autorisé en plus : C `backend/tests/Support/Couverture/trous/boites-ajout.php` (nouveau, lu par `glob` : aucun
agrégateur touché) qui déclare `POST /envoi/boites/ajout/code` comme trou jusqu'à F3-B1.
**Tests rouges d'abord** : `contrat-envoi-a-jour.test.ts` rouge tant que `.d.ts` n'est pas régénéré ;
`CouvertureContratEnvoiTest` rouge tant que le nouveau chemin n'est pas déclaré en trou ; `ContratOpenApiEnvoi`
valide toujours la réponse 501 actuelle de `POST /envoi/boites` ; gestionnaires de démo conformes aux schémas.
**Dépendances** : aucune.

---

## 4. Lots de la vague 1

### X5-A — attestation humaine « serveur réel » pour les 22 points

**Fichiers autorisés** : C `backend/app/Envoi/FeuVert/DepotAttestationServeurReel.php` ;
C `backend/app/Console/Commands/EnvoiServeurReelAttester.php` ; M `backend/app/Envoi/FeuVert/ListeFeuVert.php` ;
C `backend/tests/Feature/Envoi/FeuVertAttestationServeurReelTest.php` ; C `docs/envoi/lots/X5-A.md`.
**Fichiers chauds** : `ListeFeuVert.php`.

**Comportement**
- Commande LOCALE (jamais une route : elle libère, comme la reprise) `envoi:serveur-reel-attester {workspace}
  {geste : atteste|retire} --auteur= --motif=` ; `--auteur` et `--motif` obligatoires ; motif sans `@` ; écrit une ligne
  `envoi_attestations_serveur_reel` et une ligne `envoi_journal_commandes` `attestation_serveur_reel`, une alerte
  `critique` (comme `ToutArreter`).
- `ListeFeuVert` : `$attend = $c['serveur_reel'] && ! attestation_active` où `attestation_active` = dernière ligne de
  l'espace avec `geste = 'atteste'`. Avec attestation active, un point `serveur_reel` est **fait** seulement si sa
  preuve a été déposée **après** l'horodatage de l'attestation (une preuve d'avant reste « à revalider »). Sans
  attestation ou après `retire` : comportement de `main` (attend le serveur). Erreur de lecture ⇒ exception (échec
  fermé : `GET /envoi/feu-vert` 500, jamais « fait »).
- L'attestation est dans l'empreinte (elle change les points).
**Tests rouges d'abord** (`pgsql_app` ET propriétaire) : sans attestation ⇒ 22 points `attend_serveur` (vert sur main,
non-régression) ; attestation + preuve postérieure ⇒ point fait ; attestation + preuve antérieure ⇒ pas fait ;
`retire` après ⇒ tous `attend_serveur` ; attestation de l'espace B sans effet sur A ; commande sans `--motif` ⇒
échec, rien écrit ; motif avec `@` ⇒ échec ; aucune route ne contient `attest` (garde ajoutée au test) ; « accordé »
reste impossible tant que `auto.separation_lettre_prospection` est rouge (X5-B).
**Mutations** : ignorer la date de la preuve ⇒ rouge ; lire la première ligne au lieu de la dernière ⇒ rouge.
**Dépendances** : M0B déployée ; X5-C fusionnée.

### X6-A — commandes d'inventaire des IP et de liaison domaine → IP

**Fichiers autorisés** : C `backend/app/Envoi/Chauffe/DepotIps.php` ; C `backend/app/Console/Commands/EnvoiIpInventaire.php` ;
C `backend/app/Console/Commands/EnvoiDomaineIp.php` ; C `backend/tests/Feature/Envoi/InventaireIpTest.php` ;
C `docs/envoi/lots/X6-A.md`.

**Comportement** : `envoi:ip-inventaire {workspace} {action : ajouter|retirer} {ip} {--flux=} --auteur=` (commande
locale : une IP est une donnée d'infrastructure, pas un geste d'écran) ; `envoi:domaine-ip {workspace} {domaine} {ip}
--auteur=` (appelle `envoi_domaine_ip_poser`). `DepotIps` : `ipDuDomaine($ws, $domaine): ?string` (IP non retirée),
`ipsParFlux($ws): array{prospection: list, lettre: list}`, `domainesSansIp($ws): int`. Lecture sous contexte + filtre.
Une IP refusée par la forme ou par la base ⇒ code de sortie non nul, message sans valeur secrète.
**Tests rouges d'abord** (`pgsql_app` ET propriétaire) : ajouter puis lier ⇒ `ipDuDomaine` rend l'IP ; IP retirée ⇒
`null` ; flux différent ⇒ échec ; espace B ⇒ rien ; `ipsParFlux` exact ; IP `10.0.0.1` acceptée par la forme (les IP
privées servent aux tests), forme `1.2.3` ⇒ refus.
**Mutations** : `ipDuDomaine` sans filtre `retiree_le` ⇒ rouge.
**Dépendances** : M0A déployée.

### X11-B — rotation entre boîtes, boîte figée par inscription

**Fichiers autorisés** : C `backend/app/Envoi/Sequence/ChoixBoite.php` ; M `backend/app/Envoi/Sequence/Sequenceur.php` ;
M `backend/app/Envoi/Sequence/SourceExpeditionDb.php` ; C `backend/tests/Feature/Envoi/RotationBoitesTest.php` ;
C `docs/envoi/lots/X11-B.md`.
**Fichiers chauds** : `Sequenceur.php` (vérifier qu'aucun lot RR-* de #673 n'est ouvert dessus).

**Comportement**
- `ChoixBoite::pour($ws, $inscription, $flux, $jour)` : si `envoi_inscriptions.boite_id` est posé ⇒ cette boîte, et
  seulement elle ; si elle n'est plus `active` ⇒ `EnvoiRefuse` code `ATTENTE` (report sans compteur : le fil ne change
  jamais d'expéditeur ; ESCALADE E6). Sinon (première étape) : boîtes `active` des domaines du flux, du **même domaine
  que `ParametresExpedition::domaine`** (rotation de domaines : hors lot), restreintes aux `boites` de la campagne si
  la séquence vient d'une campagne qui en liste ; ordre : envoyés du jour (`DepotChauffe::envoyesBoite`) croissants,
  puis `cree_le`, puis `id` ; la première est posée dans `boite_id` par `UPDATE … WHERE boite_id IS NULL` (deux
  ouvriers : un seul gagne, l'autre relit). Aucune boîte ⇒ `ATTENTE`.
- `Sequenceur` : l'expéditeur du `MessageAEnvoyer` (`:347`) et l'en-tête From viennent de `ChoixBoite`, plus de
  `ParametresExpedition::expediteur` ; `domaine` (Message-ID, registre) inchangé. Le quota de chaque boîte reste
  appliqué par `GardeChauffe` (inchangé).
- `SourceExpeditionDb` : `domaineEtBoite` vérifie toujours qu'au moins une boîte active existe (sinon refus, comme
  aujourd'hui) ; nouvelle méthode `boitesActivesDuDomaine($ws, $domaine): list<array{id, adresse}>`.
**Tests rouges d'abord** (`pgsql_app` ET propriétaire, porte fermée, `FauxServeurEnvoi`) : 2 boîtes, 10 inscriptions
⇒ 5/5 ; étape 2 d'une inscription ⇒ même boîte que l'étape 1 ; boîte de l'étape 1 suspendue ⇒ étape 2 reportée
`ATTENTE`, jamais l'autre boîte, aucun compteur de reports ; campagne limitée à la boîte 2 ⇒ toutes sur 2 ; boîte
d'un autre espace jamais choisie ; deux processus réels sur la même inscription ⇒ une seule boîte posée (méthode
`PlafondConcurrenceTest`) ; aucune boîte active ⇒ rien injecté.
**Mutations** : retirer le `WHERE boite_id IS NULL` ⇒ test concurrent rouge ; choisir une autre boîte si la sienne est
en pause ⇒ rouge.
**Dépendances** : M0B déployée.

### C4-B — câblage de `KumoHttp` dans la passerelle (inerte par défaut) et mesure `file_age_s`

**Fichiers autorisés** (dossier `infra/serveur-envoi/passerelle/`) : M `public/index.php` ; M `src/Kumo.php` (docblock) ;
M `src/Sante/MesureurSysteme.php` ; C `tests/CablageKumoTest.php` ; C `docs/envoi/lots/C4-B.md`.

**Comportement** : `index.php` choisit `KumoHttp` **seulement si** `PASSERELLE_KUMO_URL` est posée ET
`getenv('ENVOI_REEL_ACTIF') === 'true'` (lecture, jamais écriture ; même lecture que `GardeTls` l. 20) ; sinon
`KumoInactif` (comportement de `main`). Extraire le choix dans une fonction pure `choisirKumo(array $env): Kumo` testable.
`MesureurSysteme` : `file_age_s` lu sur `GET /metrics.json` de KumoMTA (âge du plus vieux message en file, clé exacte =
ESCALADE E5) quand un `KumoHttp` est fourni ; toute erreur ⇒ clé omise (« mesure absente », jamais vert).
**Tests rouges d'abord** : sans variable ⇒ `KumoInactif` ; URL posée et `ENVOI_REEL_ACTIF` absent, `false`, `1`,
`TRUE` ⇒ `KumoInactif` ; les deux posés ⇒ `KumoHttp` ; URL `http://10.0.0.1:8000` ⇒ refus (exception au démarrage :
500 propre) ; faux KumoMTA (C4-A) qui rend `metrics.json` ⇒ `file_age_s` présent ; faux qui rend 500 ⇒ clé omise.
**Mutations** : accepter `ENVOI_REEL_ACTIF=1` ⇒ rouge.
**Dépendances** : C4-A fusionnée.

### F3-B1 — ajout d'une boîte par l'écran : serveur (propriétaire seul, code de confirmation)

**Fichiers autorisés** : C `backend/app/Envoi/Boites/AjoutBoite.php` ; M `backend/app/Http/Controllers/Api/EnvoiBoitesController.php` ;
M `backend/routes/envoi/boites.php` ; M `backend/tests/Support/Couverture/non-servis/boites.php` ;
C `backend/tests/Feature/Envoi/Routes/BoitesAjoutTest.php` ; C `docs/envoi/lots/F3-B1.md`.
(`trous/boites-ajout.php` de F3-B0 : retiré ici s'il existe, **7e fichier toléré** car c'est sa sortie de trou.)

**Comportement**
- Routes sous `permission:workspaces.manage` (propriétaire seul) : `POST /envoi/boites/ajout/code`,
  `POST /envoi/boites`. Aucun mot `reprendre`, `armer`, `temoin`, `tout-arreter` (gardes existantes).
- Le contrôleur pose `AuditHashChainLogger::ATTRIBUT_JOURNAL_PROPRE` dès l'entrée (le corps porte un secret : modèle
  `EnvoiOppositionImportController`, D8) et écrit sa ligne d'audit sans corps, sans secret, sans empreinte du corps.
- Code : 6 chiffres, HMAC (clé `ENVOI_CLES_CODES_CAMPAGNE`, même fonction que R11), 10 min, 5 essais, usage unique
  (`UPDATE … WHERE consomme_le IS NULL RETURNING`), 3 codes par 15 min et par utilisateur comptés sous verrou
  consultatif de l'espace, envoyé à l'adresse de connexion du demandeur par le mailer d'authentification (règles R11,
  503 si transport non sûr). Le code est lié à `demande_empreinte` (corps sans secret) : un code demandé pour
  l'adresse A ne crée pas l'adresse B.
- Création : domaine de l'adresse présent dans `envoi_domaines` de l'espace, sinon 409 ; `DepotBoites::creer` (hôte et
  port soumis aux CHECK SSRF existants) puis, **dans la même transaction**, `etat = 'en_pause'`,
  `pause_motif = 'suspension_manuelle'` : une boîte neuve ne part jamais seule, le propriétaire la réactive par le
  geste existant (deux gestes, sens sûr). Réponse sans secret ni identifiants.
- `non-servis/boites.php` : l'entrée `POST /envoi/boites` sort (le chemin est servi) ; `test-connexion` et
  `reglages` restent 501.
**Tests rouges d'abord** (`pgsql_app` ET propriétaire) : administrateur ⇒ 403 ; propriétaire sans code ⇒ 409 ; code
juste ⇒ 201, boîte `en_pause`/`suspension_manuelle` ; même code deux fois ⇒ second 409 ; 6e essai faux ⇒ 409 même
avec le bon code ; code pour A, création de B ⇒ 409 ; 4e demande en 15 min ⇒ 429 ; domaine d'un autre espace ⇒ 409 ;
aucune ligne `audit_logs` ne contient le secret ni `empreinteDuCorps($corps)` ; le secret n'apparaît dans aucun
journal (`Log::spy`) ; réponse sans `secret`/`identifiants` ; deux créations simultanées avec le même code (deux
processus réels) ⇒ une seule boîte ; `ENVOI_REEL_ACTIF=false` ⇒ aucune connexion SMTP tentée.
**Mutations** : retirer `ATTRIBUT_JOURNAL_PROPRE` ⇒ rouge ; créer la boîte `active` ⇒ rouge ; retirer le lien
`demande_empreinte` ⇒ rouge.
**Dépendances** : M0B déployée ; F3-B0 fusionnée.

---

## 5. Lots de la vague 2

### X5-B — séparation lettre/prospection lue dans l'inventaire des IP

**Fichiers autorisés** : M `backend/app/Envoi/FeuVert/ListeFeuVert.php` ; C `backend/tests/Feature/Envoi/FeuVertSeparationIpTest.php` ;
M `backend/tests/Feature/Envoi/FeuVertListeTest.php` (constante `FV_AUTO_SANS_SOURCE` vidée : filet modifié, le point a
désormais une source ; l'aide `toutVert()` pose IP et liaisons) ; C `docs/envoi/lots/X5-B.md`.

**Comportement** : `separation()` fait seulement si : (a) colonne `flux` présente et aucun domaine `flux` NULL (déjà
là) ; (b) **chaque** domaine a un `ip_id` non retiré ; (c) aucune IP n'est reliée à des domaines de deux flux (garanti
par la base, revérifié par une requête : défense en profondeur) ; (d) au moins une IP par flux **utilisé** (un espace
sans lettre n'a pas besoin d'IP lettre). Sinon rouge avec la raison. Lecture seule, aucun réseau.
**Tests rouges d'abord** (`pgsql_app` ET propriétaire) : tout relié ⇒ fait ; un domaine sans IP ⇒ rouge ; IP retirée ⇒
rouge ; ligne forcée (propriétaire, déclencheur désactivé dans le test) reliant une IP à deux flux ⇒ rouge ; espace B
complet n'éclaire pas A.
**Mutations** : retirer (b) ⇒ rouge ; retirer (c) ⇒ rouge.
**Dépendances** : X5-A fusionnée (fichier chaud), X6-A fusionnée.

### X6-B — reculs d'IP dans la garde, et comptage des envois par IP

**Fichiers autorisés** : M `backend/app/Envoi/Chauffe/GardeChauffe.php` ; M `backend/app/Envoi/Chauffe/DepotChauffe.php` ;
C `backend/tests/Feature/Envoi/ChauffeReculsIpGardeTest.php` ; M `docs/envoi/lots/T-CHAUFFE.md` ;
C `docs/envoi/lots/X6-B.md`.

**Comportement** : le lien message → IP vient de l'inventaire (domaine de l'expéditeur → `ip_id`, X6-A), pas du
transport : KumoMTA sort par la source configurée pour le domaine (D5 du runbook). `GardeChauffe::refus()` : domaine
sans IP ⇒ motif `ip_inconnue` (report, échec fermé) **seulement si** `envoi_domaines.adaptateur <> 'faux'` (un domaine
sur le faux serveur n'a pas d'IP : comportement de `main` conservé, tous les tests existants restent verts) ; sinon
plafond IP = `quotaRessourceEtendu(jourDeChauffeAvecReculs(volumes ip:<ref>, reculs ip, jour))` borné à
`Seuils::PALIER_MAX` (X11-A) ; envoyés de l'IP (+ en vol) ≥ plafond ⇒ `quota_ip_atteint`. `noter()` incrémente aussi
`ip:<ref>` (même transaction que la boîte). Le recul d'IP de l'écran (`POST /envoi/chauffe/{id}/reculer` avec
`id = ip:<ref>`) est alors **appliqué** ; jamais de montée (`min`).
**Tests rouges d'abord** (`pgsql_app` ET propriétaire) : 2 domaines sur la même IP, IP reculée en P1 ⇒ les deux
domaines bloqués à 20 cumulés ; sans recul ⇒ seuils inchangés ; domaine `kumo` sans IP ⇒ `ip_inconnue` ; domaine `faux`
sans IP ⇒ comportement de `main` ; `noter` incrémente boîte ET IP ; recul de l'IP de l'espace B sans effet sur A.
**Mutations** : supprimer l'incrément IP ⇒ rouge ; remplacer `min` par la valeur IP seule ⇒ rouge (montée).
**Dépendances** : X6-A et X11-A fusionnées.

### F3-B2 — écran « Ajouter une boîte »

**Fichiers autorisés** : C `frontend/src/features/envoi/infrastructure/composants/AjoutBoite.tsx` ;
M `frontend/src/features/envoi/infrastructure/BoitesPage.tsx` ; M `frontend/src/features/envoi/infrastructure/commandes.ts` ;
C `frontend/src/features/envoi/infrastructure/composants/AjoutBoite.test.tsx` ; C `docs/envoi/lots/F3-B2.md`.

**Comportement** : bouton visible pour le propriétaire seul ; formulaire en deux temps (« Recevoir un code » puis
code + mot de passe) ; le mot de passe n'est jamais gardé dans l'état global, ni dans `localStorage`, ni dans l'URL,
effacé après l'envoi ; message final : « Boîte créée en pause : réactivez-la quand le domaine est prêt. » ; erreurs
409/429/503 en clair.
**Tests rouges d'abord** (vitest + démo) : non-propriétaire ⇒ pas de bouton ; code faux ⇒ message, rien créé ; code
juste ⇒ boîte affichée « en pause » ; champ mot de passe vidé après succès et après échec ; aucun appel sans code.
**Dépendances** : F3-B1 fusionnée.

### F3-R — reprise par l'écran (SEULEMENT si ESCALADE E3 = « oui » écrit)

Défaut : **ne pas faire**. Si oui : route `POST /envoi/arret-general/levee` (propriétaire seul, code de confirmation
comme F3-B1, même table de codes avec une colonne `objet` ajoutée par une migration `2026_12_07_000150` « migration
seule » d'abord), appel de `ToutArreter::reprendre` ; les gardes `AucuneRouteTemoinSingulierTest` et
`BoitesRoutesTest:195` restent intactes (le mot `reprendre` n'apparaît pas) ; le test qui interdit une route de
reprise est **remplacé par** un test plus fort (propriétaire + code + journal), jamais supprimé ; `PLAN-FIN-ENVOI.md`
D1 amendé dans le même lot. Fichiers : contrôleur socle, `routes/envoi/socle.php`, contrat `socle.yaml` (+ `.d.ts`),
un test, compte rendu : 5 à 6 fichiers.

---

## 6. Vague 3

### X9-B — procédure du jour J complète

**Fichiers autorisés** : M `docs/envoi/JOUR-J.md` ; M `infra/serveur-envoi/RUNBOOK.md` (section « KumoMTA :
`PASSERELLE_KUMO_URL` », valeurs en marqueurs) ; M `.env.example` (si une variable nouvelle est apparue) ;
M `backend/tests/Feature/Envoi/VariablesEnvoiDocumenteesTest.php` (ajoute : chaque commande `envoi:*` citée dans
`JOUR-J.md` existe dans `Artisan::all()`) ; C `docs/envoi/lots/X9-B.md`.
**Comportement** : retire les marques « (lot X…) », ajoute la vérification de chaque étape par les commandes réelles,
la ligne `PASSERELLE_KUMO_URL` côté serveur d'envoi.
**Tests rouges d'abord** : commande citée inexistante ⇒ rouge (ex. une faute de frappe volontaire dans un test de
contrôle du lecteur).
**Dépendances** : tous les lots fusionnés.

---

## 7. Ordre conseillé (une routine par lot)

1. Vague 0, tout en même temps : FINI-M0A, FINI-M0B, X11-A, C4-A, X9-A, X5-C, F3-B0.
2. Déployer M0A et M0B (migrations seules) ; puis vague 1 : X5-A, X6-A, X11-B, C4-B, F3-B1.
3. Vague 2 : X5-B, X6-B, F3-B2 (et F3-R si E3 = oui).
4. Vague 3 : X9-B.

Après la vague 3, côté code, il ne reste que les gestes du fondateur : achats, publication DNS, installation du
serveur, chauffe réelle, attestation `envoi:serveur-reel-attester`, preuves des 22 points, feu vert écrit, et la pose de
`ENVOI_REEL_ACTIF=true` (qu'aucun lot ne touche).

---

## 8. ESCALADE — décisions du fondateur (défaut sans risque appliqué si rien n'est dit)

| # | Question | Défaut retenu (sans risque) | Autre choix |
|---|---|---|---|
| E1 | Monter la chauffe au-delà de P3 (P4-P8 : 50 → 150 par boîte) ? Valeurs « hypothèses à confirmer en banc ». | `Seuils::PALIER_MAX = 3` : plafond de 25 par boîte comme aujourd'hui. | Une PR d'une ligne passe `PALIER_MAX` à 8 après 15 jours de chauffe réelle saine. |
| E2 | Alignement 30/20 : la garde plafonne le domaine à 3 × quota (30 en P1), l'écran affiche 20. | Non touché ici (déjà ESCALADE de X6). Si décidé : `DOMAINE_FACTEUR` aligné sur `total` (20), sens qui baisse. | Garder 30. |
| E3 | Reprise après « Tout arrêter » par l'écran ? (contredit D1 et 4 tests de garde) | **Non** : commande locale `envoi:reprendre` seule. | Oui avec propriétaire + code (lot F3-R). |
| E4 | Mot de passe d'une boîte saisi à l'écran ? | Oui, au seul ajout, propriétaire + code, jamais journalisé, boîte née en pause. | Saisie par commande locale masquée seulement (F3-B1 sans `secret`, boîte créée sans accès : il faudrait alors une commande `envoi:boite-acces`). |
| E5 | Champs exacts de l'API d'administration KumoMTA (`bounce`, `suspend`, `metrics.json`) pour l'image épinglée. | Champs du cahier, vérifiés sur le faux serveur ; écart corrigé dans `KumoHttp` seul le jour de l'installation réelle. | — (constat à faire sur le vrai serveur) |
| E6 | Une étape suivante dont la boîte est en pause : attendre ou changer de boîte ? | **Attendre** (report `ATTENTE`, le fil garde son expéditeur). | Changer de boîte après N jours (lot à part). |
| E7 | Rotation entre DOMAINES (pas seulement entre boîtes d'un domaine) ? | Hors de ce cahier : rotation entre boîtes du même domaine seulement. | Lot suivant, après la chauffe réelle du 2e domaine. |

CAHIER PRÊT
