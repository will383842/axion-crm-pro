# Cahier PONT-LINKEDIN — opposition sortante, pont des origines, file LinkedIn « prêt à cliquer »

Conception seule : ce document ne code rien. Trois fiches demandées par la session du parcours viral :
1. **STOP-T4-CRM** — le CRM émet vers le site chaque opposition née chez lui ;
2. **CRM-PONT-ORIGINES** (suite de X1-L2-bis) — sélection en lecture seule par réseau / mot / guide, colonne et filtre
   « réseau » dans la liste des personnes ;
3. **LI-VOIE-S-CODE** — file LinkedIn « prêt à cliquer » : le fondateur envoie lui-même, depuis son navigateur ; le
   CRM prépare, contrôle et journalise. **Aucun outil tiers, aucune automatisation d'envoi, aucun appel vers LinkedIn.**

Base lue : `origin/main` au commit `4b7902b` (#676). PR ouvertes relues : #675 à #685, cahiers #648, #671, #672, #673,
#680 (liste des fichiers de chaque PR ouverte comparée aux fichiers de ce cahier : **aucun fichier commun**).
Spécification de la fiche 3 : plan privé du fondateur (voie S retenue) et ses corrections d'audit, **reformulés** ici
sans nom, sans prix, sans chiffre commercial.

Règles communes à TOUS les lots (non négociables, reprises de `FINI-cahier.md`) :
- échec fermé : toute erreur de lecture (registre d'opposition, origines, plafonds, arrêt) ⇒ rien n'est proposé, rien
  n'est sélectionné ; une personne opposée n'est **jamais** contactée ni réinscrite ;
- aucune suppression ni purge de données de personnes ; migrations en **ajout seul** (aucun `DROP`, aucun `DELETE`),
  `down()` inerte et ligne ajoutée dans `Nr17MigrationsTest::NR17_RETOUR_INERTE_VOULU` ;
- cloisonnement par espace : chaque lecture et écriture sous `WorkspaceContext::run($ws, …)` **et** filtrée par
  `workspace_id` ; un test par lot prouve qu'un espace B ne voit ni ne touche rien de A ; test IDOR pour toute route ;
- drapeaux **jamais touchés** : `ENVOI_REEL_ACTIF`, `ORIGINES_LECTURE_ENABLED`, `CRM_GUIDE_LIEN_ENABLED`,
  `USINE_AGREGATS_CRM_ENABLED` (ni valeur, ni défaut, ni lecture nouvelle hors des gardes existantes :
  `FiltreOrigine::actif()` est LA garde de lecture des origines) ;
- nouveau drapeau de la fiche 3 : `PROSPECTION_LINKEDIN_ACTIVE`, défaut `false`, lu seulement par
  `config/prospection_linkedin.php` ; éteint ⇒ toutes les routes `/prospection-linkedin/*` répondent 404 ;
- tests ROUGES d'abord, sortie rouge collée dans le compte rendu `docs/envoi/lots/<LOT>.md` ;
- chaque test de base de données tourne **deux fois** : `pgsql_app` (rôle applicatif, RLS armée) ET propriétaire
  (filtre `workspace_id` explicite prouvé sans RLS) ; modèles : `EnvoiAutoriseOppositionArt21Test`, `slcSous()` de
  `SchemaLienCampagneSequenceTest.php:78` ;
- mutations : chaque garde nouvelle est retirée une fois, le test doit rougir, mutation annulée, sortie collée ;
- adresses d'exemple en `*.example.test`, profils d'exemple `exemple-prenom-nom-0a1b2c` ; aucune donnée réelle ;
- toute colonne JSON nouvelle : fragment NOUVEAU dans `backend/tests/Feature/Rgpd/colonnes-json/` (sinon
  `PortabiliteCompleteTest` B10-004 rougit) ;
- `vendor/bin/pint --dirty`, PHPStan niveau 8 sur les fichiers de `app/` touchés, tests ciblés `--filter`.

---

## 1. État vérifié (étape 1)

### 1.1 Fiche 1 — STOP-T4-CRM : **DÉJÀ FAIT** (aucun lot écrit)

| Élément demandé | Preuve sur `main` |
|---|---|
| Point unique d'émission d'une opposition née au CRM vers le site, ajout seul dans l'outbox, même transaction | `backend/app/Crm/Outbound/OppositionSortante.php:14-28`, `:77-120` (événement `consent_optout`, table `crm_outbound_events` créée par `2026_08_14_000007_crm_outbound_events.php:56`) |
| Anti-boucle (une opposition venue du site ne repart pas) en liste blanche | `OppositionSortante.php:32-39` (`SOURCES_CRM`), `:60-70`, `:104-106` |
| Rejeu = une seule ligne ; verrou contre deux gestes simultanés | `OppositionSortante.php:92-95`, `:108-116` |
| Désinscription par le lien | `backend/app/Envoi/Desinscription/TerminerEffetsOpposition.php:31` |
| Opposition saisie à la main (registre R13) et import de liste de refus | `backend/app/Envoi/Conformite/RegistreOppositionManuel.php:151-170` ; `ImportOppositions.php:84-85` |
| Art. 21 / art. 17 en console, presse, retours de campagne, essai à blanc | `RgpdRequestsController.php:372` ; `GdprErasureService.php:210` ; `JournalistsController.php:698-744` ; `CrmCampagneRetours.php:150-157` ; `EssaiABlanc.php:324` |
| Envoi au site (webhook signé, 401 = réglage, report sans perte) | `backend/app/Console/Commands/CrmFlushOutbound.php:135`, `:206-287` ; planifié `backend/routes/console.php:390-394` (inerte tant que `CRM_OUTBOUND_ENABLED` est faux) |
| Rattrapage des oppositions antérieures | `CrmOutboundRattraper.php:16-18`, `:77-88` (livré, non planifié : décision écrite du fondateur) |
| Tests | `backend/tests/Feature/Crm/OppositionsSortantesT4Test.php:126` (C1 lien), `:142` (C2), `:168` (art. 21), `:178` (art. 17), `:193` (retours), `:211` (relève IMAP), `:246-315` (anti-boucle), `:394` (rejeu), `:430` (concurrence), `:521` (aucune suppression), `:633` (aucune adresse dans le corps) ; `backend/tests/Feature/Envoi/Conformite/OppositionManuelleTest.php:171` (saisie à la main) |

Tous les chemins qui écrivent `opt_out` côté CRM passent par `OppositionSortante` (vérifié : `DeduplicationService::addOptOut*`
n'est appelé que par ces gestes ; `SiteGdprService`, `SiteSyncIngestService`, `PersonnesIngestService` sont des
ingestions venues du site, exclues par l'anti-boucle). Restent **hors code** : l'allumage `CRM_OUTBOUND_ENABLED` et le
secret partagé (geste de production du fondateur), et le relais côté site vers le parcours (guide et messages privés),
qui appartient au site. Limite connue : une opposition LinkedIn **sans adresse connue** ne peut pas partir vers le site
(l'événement est keyé par empreinte d'adresse) — voir fiche 3, lot LI-C, et ESCALADE E6.

### 1.2 Fiche 2 — CRM-PONT-ORIGINES

| Élément | État | Preuve |
|---|---|---|
| Origines sur la fiche personne (historique, entrées masquées exclues) | **fait** | `PersonnesController.php:263`, `:849-862` ; `frontend/src/features/origines/HistoriqueOrigines.tsx` |
| Écran Origines (par réseau, par mot, personnes, export) derrière les trois portes | **fait** | `routes/api.php:617-627` ; `OriginesController.php:59-150` ; `RequetesOrigines.php:67-71` (filtres famille, détail, réseau, mot) |
| Sélection de campagne par **réseau** (colonne, forme, lecture, branchement, écriture, écran) | **fait** (X1-L2-bis A à E) | `2026_12_10_000100_envoi_campagnes_filtre_origine.php` ; `FiltreOrigine.php:16-86` ; `RequetesOrigines.php:246-285` (`reseauxDesUnites`) ; `SelectionDestinataires.php:38-39` (`hors_reseau`) ; `AssistantCreation.php` ; `frontend/src/features/envoi/creation/FiltreReseau.tsx` ; comptes rendus `X1-L2-bis-A.md` à `-D.md` |
| Sélection par **mot** ou par **guide** | **MANQUE** | `FiltreOrigine::depuisColonne` n'accepte que la clé `reseaux` (`:36`) ; CHECK de la colonne : `(filtre_origine - 'reseaux') = '{}'` (compte rendu `X1-L2-bis-A.md`) ; le guide n'est filtrable nulle part (`RequetesOrigines.php:71` : `famille, detail, reseau, mot`) |
| Liste des réseaux, des mots, des **guides** proposables | réseau, mot : **fait** ; guide : **MANQUE** | `OriginesController.php:59` (`par-reseau`), `:70` (`par-mot`) ; aucune route `par-guide` |
| Liste des personnes : **colonne** « réseau » | **MANQUE** | `PersonnesController.php:840`, `:867-880` rend `origine` (la plus récente) derrière `origines.v3_enabled` seul, pas la liste des réseaux ; `frontend/src/features/crm-console/PersonnesPage.tsx` n'affiche aucune origine |
| Liste des personnes : **filtre** « réseau » | **MANQUE** | `PersonnesController.php:638-693` : filtres `origine_famille` / `origine_detail` seulement, gardés par `v3_enabled` (pas par `ORIGINES_LECTURE_ENABLED`) |

### 1.3 Fiche 3 — LI-VOIE-S-CODE : **rien n'existe** (tout est à faire)

| Constat | Preuve |
|---|---|
| Aucun module : ni `backend/app/ProspectionLinkedIn/`, ni `frontend/src/features/prospection-linkedin/` | `ls` vide |
| Anciennes tables `linkedin_*` d'un échafaudage de mai (dont `linkedin_accounts.cookie_blob`, `proxy_id`) : **déclarées MORTES** — non réutilisées, non lues, **non supprimées** (aucun `DROP`) ; un test d'architecture interdit toute lecture (lot LI-G) | `2026_05_16_000007_create_phase2_scaffold_schema.php:77-100` ; `2026_05_17_000010_phase2_full_scaffold_schema.php:103-150` ; route bouchon 501 `Api/Phase2/LinkedInController.php:12-17` |
| Normalisation du profil : à réutiliser telle quelle | `backend/app/Support/LinkedinSlug.php:40` (`normalize()`, refuse `/company/`, `/school/`) |
| Champ des fiches : `linkedin_url` (contacts, sociétés), aucun slug indexé ; seuls les journalistes ont `linkedin_slug` unique | `2026_05_16_000003_…:40`, `:87` ; `2026_08_25_000001_journalists_base_presse.php:88-91` |
| Registre d'opposition : portées `adresse`, `organisation`, `domaine` ; **pas de clé LinkedIn** ; la lecture `StatutOpposition::pourLignes` ne prend que `{email, siren}` | `RegistreOppositionManuel.php:27` ; `2026_12_02_000130_envoi_oppositions_registre.php:22`, `:32-33` ; `backend/app/Crm/Opposition/StatutOpposition.php:35` |
| Alerte Telegram existante (inerte sans réglage) | `backend/app/Services/Alertes/AlerteTelegram.php` |

### 1.4 Horodatages de migration

Plage demandée `2026_12_09_000100` à `000290` : **`000100` est pris** (`2026_12_09_000100_envoi_evenements_index_injection.php`,
sur `main` et 37 branches). `000110` à `000290` : **libres** sur `main` et sur les 342 branches distantes (vérifié par
`git ls-tree` de chaque branche le 2026-11-17) et absents des cahiers ouverts. Ce cahier prend **`000110`, `000120`,
`000130`, `000140`** ; `000150` à `000290` restent libres pour les reprises.

---

## 2. Vagues (parallélisme maximal)

Une vague = des lots **sans aucun fichier commun** ; chacun sur sa branche depuis `main`. Une vague ne part qu'après la
**fusion** des lots dont elle dépend (et le **déploiement** des migrations quand c'est dit).

| Vague | Lots (en parallèle) | Attend |
|---|---|---|
| **0** | PL-M0A, PO-A, PO-L, LI-A, LI-G | rien |
| **1** | PL-M0B, PO-B, PO-G, PO-LE, LI-L3 | PL-M0A fusionnée ET déployée (PO-B, LI-L3) ; PO-A (PO-B, PO-G) ; PO-L (PO-LE) |
| **2** | PO-C, LI-B1, LI-B2 | PO-B (PO-C) ; PL-M0B déployée, LI-A, LI-L3 (LI-B1, LI-B2) |
| **3** | PO-D, LI-C | PO-C et PO-G (PO-D) ; LI-B1 et LI-B2 (LI-C) |
| **4** | LI-D1, LI-E | LI-C |
| **5** | LI-D2 | LI-D1 |

Fichiers chauds (un seul lot à la fois, d'où l'ordre) :
`Nr17MigrationsTest.php` (PL-M0A → PL-M0B) ; `RequetesOrigines.php` (PO-A seul) ; `PersonnesController.php` (PO-L
seul) ; `SelectionDestinataires.php` (PO-B seul) ; `DepotCampagnes.php`, `AssistantCreation.php`, `creation.yaml`,
`creation.d.ts` (PO-C seul) ; `routes/api.php` (PO-G → LI-C) ; `StatutOpposition.php` (LI-L3 seul) ;
`routes/console.php` (LI-E seul) ; `frontend/src/app/routeTree.tsx`, `Sidebar.tsx` (LI-D2 seul) ;
`frontend/src/features/envoi/creation/*` (PO-D seul). **Avant de pousser**, chaque routine vérifie qu'aucune PR ouverte
(#677, #679, B6-B8, D3-D6, FINI…) ne tient le même fichier ; sinon ESCALADE et attente.

Vérification « aucun fichier commun » de la vague 0 : PL-M0A (2 migrations, `Nr17…`, 1 fragment JSON, 1 test) ;
PO-A (`FiltreOrigineContenu.php`, `RequetesOrigines.php`, 1 test) ; PO-L (`PersonnesController.php`, 1 test) ; LI-A
(`config/prospection_linkedin.php`, 3 classes `ProspectionLinkedIn/`, 1 test) ; LI-G (1 test d'architecture) : disjoints.
Vague 1 : PL-M0B (2 migrations, `Nr17…`, 1 fragment, 1 test) ; PO-B (`SelectionDestinataires.php`, 1 test) ; PO-G
(`OriginesController.php`, `routes/api.php`, 1 test) ; PO-LE (`PersonnesPage.tsx`, 1 test d'écran, 1 gestionnaire de
démo) ; LI-L3 (`RegistreOppositionLinkedin.php`, `StatutOpposition.php`, 1 test) : disjoints. Idem vagues 2 à 5.

---

## 3. Migrations seules

### PL-M0A — migration seule : filtre de campagne par mot / guide, registre d'opposition LinkedIn

**Fichiers autorisés**
- C `backend/database/migrations/2026_12_09_000110_envoi_campagnes_filtre_origine_contenu.php`
- C `backend/database/migrations/2026_12_09_000120_envoi_oppositions_linkedin.php`
- M `backend/tests/Feature/NonRegression/Nr17MigrationsTest.php` (deux lignes dans `NR17_RETOUR_INERTE_VOULU`, chacune
  précédée de `// Filet modifié : pourquoi — PONT-LINKEDIN PL-M0A : …`)
- C `backend/tests/Feature/Rgpd/colonnes-json/2026-12-09-pl-m0a-filtre-origine-contenu.json`
- C `backend/tests/Feature/Envoi/SchemaPontOriginesLinkedinTest.php`
- C `docs/envoi/lots/PL-M0A.md`

**Fichiers chauds interdits** : la migration `2026_12_10_000100_…filtre_origine.php` (on n'élargit PAS son CHECK : ce
serait un `DROP CONSTRAINT`), `envoi_campagnes_garde()`, `envoi_oppositions_registre` (ESCALADE E1).

**Comportement**
- `000110` : `ALTER TABLE envoi_campagnes ADD COLUMN IF NOT EXISTS filtre_origine_contenu JSONB NULL`. CHECK
  `envoi_campagnes_filtre_origine_contenu_forme` (`NOT VALID` puis `VALIDATE`) : `NULL` OU objet dont les clés sont un
  sous-ensemble NON VIDE de `{mots, guides}` (`(filtre_origine_contenu - 'mots' - 'guides') = '{}'`, au moins une des
  deux présente par `jsonb_exists`, jamais `?` : PDO le prend pour un paramètre) ; chaque clé présente = tableau de 1 à
  10 chaînes conformes à `^[a-z0-9][a-z0-9._-]{0,63}$` (`jsonb_path` + `like_regex`, modèle L2b-A) ; taille
  ≤ 2 048 octets. Déclencheur `envoi_campagnes_filtre_origine_contenu_fige` sur la seule colonne (gel dès que
  `sequence_id` est posé ; `search_path = pg_catalog, public, pg_temp` ; sans `SECURITY DEFINER`), copie exacte du gel
  de L2b-A. `GRANT INSERT (filtre_origine_contenu), UPDATE (filtre_origine_contenu)` au rôle applicatif. Aucune ligne
  touchée. `down()` inerte.
- `000120` : table `envoi_oppositions_linkedin` : `id UUID PK`, `workspace_id UUID NOT NULL REFERENCES workspaces(id)`,
  `empreinte TEXT NOT NULL CHECK (empreinte ~ '^[0-9a-f]{64}$')` (HMAC du slug normalisé, même clé que
  `ListeSuppression::empreinte`), `valeur_chiffree TEXT NOT NULL` (slug chiffré, jamais en clair : un profil est une
  donnée de personne), `source TEXT NOT NULL CHECK (source IN ('prospection_linkedin','manuel'))`,
  `motif TEXT NOT NULL CHECK (length(motif) BETWEEN 3 AND 200 AND position('@' in motif) = 0)`, `auteur TEXT NOT NULL`,
  `cree_le TIMESTAMPTZ NOT NULL DEFAULT now()` ; `UNIQUE (workspace_id, empreinte)` ; RLS `ENABLE` + `FORCE`, politique
  sur `app.current_workspace_id` (modèle `2026_12_02_000130_envoi_oppositions_registre.php`) ; déclencheur « ajout
  seul » : `UPDATE`, `DELETE`, `TRUNCATE` refusés, message préfixé `envoi_oppositions_linkedin :`. Rôle applicatif :
  `SELECT, INSERT` seulement. `down()` inerte.

**Tests rouges d'abord** (`pgsql_app` ET propriétaire) : (1) colonne, contrainte VALIDE, déclencheur, table absents ⇒
rouge sur `main` ; (2) formes acceptées : `{"mots":["a"]}`, `{"guides":["g-1"]}`, les deux ; refusées (23514) : `{}`,
clé inconnue, `{"mots":[]}`, 11 mots, majuscule, jeton de 65 caractères, `{"reseaux":[…]}` ; (3) gel : campagne reliée
⇒ `UPDATE` de la colonne refusé, non reliée ⇒ permis ; (4) registre LinkedIn : `UPDATE`, `DELETE`, `TRUNCATE` refusés ;
motif avec `@` refusé ; doublon `(workspace_id, empreinte)` refusé ; (5) cloisonnement : sous le contexte de B, 0 ligne
de A, et un `INSERT` portant le `workspace_id` de A refusé par la politique ; (6) additif : `migrate`, `rollback --step=2`,
`migrate` : mêmes comptes, rien de perdu.

**Mutations** : retirer `like_regex` ⇒ (2) rouge ; retirer `NEW.sequence_id IS NOT NULL` du gel ⇒ (3) rouge ; retirer
`FORCE ROW LEVEL SECURITY` ⇒ (5) rouge en `pgsql_app` ; retirer le refus `UPDATE` du registre ⇒ (4) rouge.

**Dépendances** : aucune. **À déployer** avant PO-B et LI-L3.

### PL-M0B — migration seule : tables du module de prospection LinkedIn (`pl_*`)

**Fichiers autorisés**
- C `backend/database/migrations/2026_12_09_000130_pl_prospects_journal.php`
- C `backend/database/migrations/2026_12_09_000140_pl_plafonds_arret.php`
- M `backend/tests/Feature/NonRegression/Nr17MigrationsTest.php` (deux lignes commentées)
- C `backend/tests/Feature/Rgpd/colonnes-json/2026-12-09-pl-m0b-journal.json`
- C `backend/tests/Feature/ProspectionLinkedIn/SchemaProspectionLinkedinTest.php`
- C `docs/envoi/lots/PL-M0B.md`

**Fichiers chauds interdits** : toute table `linkedin_*` (mortes, intouchées), `contacts`, `companies` (aucune colonne
ajoutée : rapprochement par lecture, LI-B1).

**Comportement**
- `000130`, table `pl_prospects` : `id UUID PK`, `workspace_id` (FK), `expediteur_user_id BIGINT NOT NULL` (l'utilisateur
  qui clique ; FK `users`), `linkedin_slug TEXT NOT NULL CHECK (linkedin_slug ~ '^[a-z0-9%][a-z0-9%._-]{1,99}$')`,
  `contact_id BIGINT NULL` (fiche CRM rattachée), `entreprise TEXT NULL`, `siren CHAR(9) NULL CHECK (siren ~ '^[0-9]{9}$')`,
  `source TEXT NOT NULL CHECK (source IN ('recherche','commentaire_post','evenement','apporteur','base_crm'))`,
  `source_ref TEXT NULL` (référence de post ou d'événement, ≤ 120, sans `@`), `source_le DATE NOT NULL`,
  `score SMALLINT NOT NULL DEFAULT 0 CHECK (score BETWEEN 0 AND 100)`, `etat TEXT NOT NULL DEFAULT 'candidat'` (CHECK
  liste fermée § 4.1), `variante TEXT NULL`, `relance_le DATE NULL`, `cree_le`, `maj_le` ; `UNIQUE (workspace_id,
  expediteur_user_id, linkedin_slug)` ; `UNIQUE (workspace_id, id)` ; index `(workspace_id, expediteur_user_id, etat,
  score DESC)`. Déclencheurs : `DELETE`/`TRUNCATE` refusés ; **`etat = 'ecarte'` est définitif** (tout `UPDATE` qui
  change `etat` d'une ligne `ecarte` est refusé ; les autres colonnes d'une ligne écartée aussi, sauf `maj_le`) ;
  `linkedin_slug`, `workspace_id`, `expediteur_user_id` jamais modifiés.
- `000130`, table `pl_journal` (ajout seul) : `id BIGSERIAL`, `workspace_id`, `prospect_id UUID NULL` (FK composite
  `(workspace_id, prospect_id)`), `evenement TEXT NOT NULL` (liste fermée : gestes § 4.1 + `ajout`, `controle_refuse`,
  `arret`, `reprise`, `plafonds`, `alerte`), `origine TEXT NOT NULL CHECK (origine IN ('humain','systeme'))`,
  `auteur TEXT NOT NULL`, `cle_idempotence TEXT NOT NULL CHECK (length BETWEEN 16 AND 128)`, `details JSONB NOT NULL
  DEFAULT '{}'` (≤ 2 048 octets, aucune adresse : `position('@' in details::text) = 0`), `etat_avant`, `etat_apres`,
  `sans_effet BOOLEAN NOT NULL DEFAULT false`, `recu_le` ; `UNIQUE (workspace_id, cle_idempotence)`. `UPDATE`, `DELETE`,
  `TRUNCATE` refusés.
- `000140`, tables `pl_plafonds` et `pl_arret`, **ajout seul, la dernière ligne fait foi** : `pl_plafonds`
  (`workspace_id`, `expediteur_user_id`, `invitations_jour SMALLINT CHECK (0..25)`, `invitations_semaine SMALLINT CHECK
  (0..100)`, `messages_jour SMALLINT CHECK (0..40)`, `notes_mois SMALLINT CHECK (0..5)`, `jours_ouvres_seulement BOOLEAN
  NOT NULL DEFAULT true`, `auteur`, `le`) ; `pl_arret` (`workspace_id`, `expediteur_user_id NULL` = tous,
  `geste TEXT CHECK (geste IN ('arret','reprise'))`, `motif TEXT NOT NULL` (10..300, sans `@`), `auteur`,
  `declencheur TEXT CHECK (declencheur IN ('humain','acceptation_basse','oppose_dans_file','avertissement'))`, `le`).
  Aucune ligne de plafond ⇒ le code applique les défauts sûrs (ESCALADE E5) ; aucune ligne d'arrêt ⇒ non arrêté.
- Les quatre tables : `workspace_id NOT NULL`, RLS `ENABLE` + `FORCE`, rôle applicatif `SELECT, INSERT` (+ `UPDATE` de
  colonnes nommées sur `pl_prospects` seulement : `etat`, `score`, `variante`, `relance_le`, `contact_id`, `maj_le`).
  `down()` inerte.

**Tests rouges d'abord** (`pgsql_app` ET propriétaire) : tables absentes ⇒ rouge ; `DELETE` et `TRUNCATE` refusés sur les
quatre tables ; ligne `ecarte` : `UPDATE etat = 'invite'` refusé ; slug modifié refusé ; deux lignes de journal de même
clé ⇒ violation d'unicité ; `details` avec `@` refusé ; plafond `invitations_jour = 26` refusé ; rôle applicatif :
`UPDATE pl_prospects SET linkedin_slug` ⇒ `42501` ; cloisonnement B/A ; additif.

**Mutations** : retirer le gel de `ecarte` ⇒ rouge ; retirer l'unicité de la clé d'idempotence ⇒ rouge ; retirer
`FORCE` ⇒ rouge en `pgsql_app`.

**Dépendances** : PL-M0A fusionnée (fichier `Nr17…`). **À déployer** avant LI-B1 et LI-B2.

---

## 4. Fiche 2 — CRM-PONT-ORIGINES (lots PO-*)

Règle de combinaison (ESCALADE E3, défaut le plus étroit) : une personne « correspond » si **une même** entrée
d'origine non masquée satisfait **tous** les critères posés (réseau ∈ R si R posé, mot ∈ M si M posé, guide ∈ G si G
posé). Une unité (entreprise ou contact ciblé) entre si une personne rattachée correspond, avec les règles R1, R2, O1, O2
de X1-L2-bis inchangées (une personne opposée ne fait jamais entrer une unité ; un contact rattaché à une personne
opposée sort en `opposition`). Un filtre n'est **jamais ignoré** : porte fermée ⇒ refus explicite.

### PO-A — `FiltreOrigineContenu` et lecture des unités par réseau / mot / guide (lecture seule)

**Fichiers autorisés** : C `backend/app/Envoi/Campagnes/FiltreOrigineContenu.php` ; M `backend/app/Crm/Origines/RequetesOrigines.php` ;
C `backend/tests/Feature/Envoi/FiltreOrigineContenuTest.php` ; C `docs/envoi/lots/PO-A.md`.
**Fichiers chauds interdits** : `FiltreOrigine.php` (intouché : sa forme reste `{"reseaux":…}`), `SelectionDestinataires.php`,
`OriginesController.php`.

**Comportement**
- `FiltreOrigineContenu` : `depuisColonne(mixed): ?self` et `depuisSaisie(array): self`, forme exacte du CHECK de
  PL-M0A (clés `mots` et/ou `guides`, 1 à 10 jetons chacune, `trim`, minuscules refusées si différentes — on ne
  « corrige » pas un mot, dédoublonnage, tri) ; toute forme douteuse ⇒ `\InvalidArgumentException`. `enColonne()`.
  La porte est **celle de `FiltreOrigine::actif($ws)`** (appelée, jamais recopiée).
- `RequetesOrigines::unitesParOrigine(string $ws, array $entreprises, array $contacts, ?array $reseaux, ?array $mots,
  ?array $guides): array` : même forme de retour que `reseauxDesUnites` (`entreprises`, `contacts`, `contacts_opposes`),
  même source (`entrees()` : origines + archive, masquées exclues), condition « même entrée » ; bindings seulement,
  3 requêtes de données au plus ; tous critères `null` ⇒ `\LogicException` (l'appelant ne doit pas demander « tout »).
- `conditions()` accepte aussi la clé `guide` (même règle que `mot`) ; `filtresVides()` gagne `guide => null` ;
  `parGuide(string $ws, array $f, int $page, int $parPage)` (calque de `parMot`, groupé par `guide`).

**Tests rouges d'abord** (`pgsql_app` ET propriétaire ; jeux `OriginesJeu`) : (1) formes valides et invalides de
`FiltreOrigineContenu` ; (2) mot seul : seule l'unité dont une personne porte ce mot entre ; (3) réseau + mot posés sur
DEUX entrées différentes d'une même personne ⇒ n'entre pas (règle « même entrée ») ; (4) entrée masquée ⇒ ne compte
pas ; (5) entrée seulement dans l'archive ⇒ compte ; (6) personne opposée portant le mot ⇒ l'unité n'entre pas, contact
rattaché rendu dans `contacts_opposes` ; (7) `parGuide` : comptes exacts, masquées exclues ; (8) espace B : aucune
personne de A rendue ; (9) critères tous `null` ⇒ exception.
**Mutations** : condition « même entrée » remplacée par « une entrée par critère » ⇒ (3) rouge ; filtre `masque_le`
retiré ⇒ (4) rouge ; O1 retirée ⇒ (6) rouge.
**Dépendances** : aucune (n'utilise pas la colonne de PL-M0A).

### PO-B — branchement du filtre mot / guide dans `SelectionDestinataires`

**Fichiers autorisés** : M `backend/app/Envoi/Campagnes/SelectionDestinataires.php` ;
C `backend/tests/Feature/Envoi/SelectionFiltreContenuTest.php` ; C `docs/envoi/lots/PO-B.md`.
**Fichiers chauds interdits** : `MaterialisationCampagne.php` (déjà : `filtre_origine_inactif` non réessayable),
`SelectionRefusee.php` (motifs existants suffisent), `RequetesOrigines.php`.

**Comportement**
- Lit `NULLIF(to_jsonb(k) -> 'filtre_origine_contenu', 'null'::jsonb)` à côté de `filtre_origine` (colonne absente ou
  NULL = pas de filtre de contenu).
- Un des deux filtres présent : forme invalide ou `FiltreOrigine::actif` faux ⇒ `SelectionRefusee('filtre_origine_inactif')`
  **avant** toute lecture des membres (inchangé pour le réseau seul).
- Filtre de contenu présent (avec ou sans réseau) : UNE lecture `unitesParOrigine` avec les trois critères ; une unité
  sans motif direct non retenue est comptée `hors_reseau` (motif existant, sens élargi à « hors origine demandée » : pas
  de nouveau motif, l'écran des compteurs reste valable ; libellé revu en PO-D) ; O2 ⇒ `opposition`.
- Réseau seul : chemin `reseauxDesUnites` de L2b-C **inchangé** (aucune régression). Aucun filtre : aucune lecture d'origine.
- Erreur de lecture : `lecture_origine_impossible` (réessayable), aucun destinataire.

**Tests rouges d'abord** (`pgsql_app` ET propriétaire ; filtre posé en `version + 1` par le propriétaire) : (1) sans
filtre : mêmes destinataires qu'avant, 0 lecture d'origine ; (2) mot posé, porte fermée (défaut) ⇒ refus
`filtre_origine_inactif`, 0 lecture des membres ; (3) mot posé, porte ouverte ⇒ seules les unités du mot ; (4) réseau +
guide ⇒ intersection « même entrée » ; (5) contact rattaché à une personne opposée ⇒ `opposition`, jamais destinataire ;
(6) forme corrompue écrite en propriétaire (CHECK désactivé dans le test) ⇒ refus, jamais ignorée ; (7) exception de
lecture ⇒ `lecture_origine_impossible` ; (8) réseau seul : résultat identique à L2b-C (test croisé) ; (9) espace B.
**Mutations** : filtre de contenu ignoré quand inactif ⇒ (2) rouge ; refus après lecture des membres ⇒ (2) rouge ; O2
retirée ⇒ (5) rouge ; exception avalée ⇒ (7) rouge.
**Dépendances** : PL-M0A déployée, PO-A fusionnée.

### PO-C — écriture du filtre mot / guide (API de l'assistant)

**Fichiers autorisés** : M `backend/app/Envoi/Campagnes/DepotCampagnes.php` ; M `backend/app/Envoi/Creation/AssistantCreation.php` ;
M `backend/openapi/envoi/creation.yaml` ; M `frontend/src/features/envoi/api/creation.d.ts` (régénéré, jamais à la main) ;
C `backend/tests/Feature/Envoi/AssistantFiltreContenuTest.php` ; C `docs/envoi/lots/PO-C.md`.

**Comportement** (calque exact de L2b-D) : `cible.origine` accepte en plus `mots` et `guides` ; la partie `reseaux` va
dans `filtre_origine`, la partie `mots`/`guides` dans `filtre_origine_contenu`, **dans la même écriture** (`version + 1`
unique). Porte fermée et l'une des parties présente ⇒ 422 `filtre_origine_inactif`, rien écrit ; forme invalide ⇒ 422
`validation` ; `origine: null` ⇒ les deux colonnes à NULL (permis porte fermée) ; clé absente ⇒ inchangé. `presenter()`
rend `cible.origine` depuis les COLONNES. `controles()` : `filtre_origine` (`ok`/`bloquant`) couvre les deux colonnes ;
`filtre_origine_estimation` (`attention`) inchangé. Refus P0001 (campagne reliée) ⇒ `CampagneRefusee`.
**Tests rouges d'abord** (HTTP réel, propriétaire de l'espace ; écritures aussi sous `SET LOCAL ROLE` applicatif) :
porte ouverte ⇒ 200, deux colonnes posées, une seule version ; porte fermée ⇒ 422, rien écrit ; `null` porte fermée ⇒
200, colonnes NULL ; saisie seule sans colonne ⇒ `presenter` rend `null` ; campagne d'un autre espace ⇒ 404 (IDOR) ;
contrat : le `.d.ts` suit le YAML (garde `contrat-envoi-a-jour`).
**Mutations** : écriture en deux versions ⇒ rouge ; `presenter` depuis la saisie ⇒ rouge ; porte non vérifiée ⇒ rouge.
**Dépendances** : PO-B fusionnée.

### PO-G — liste des guides proposables (`GET /crm/origines/par-guide`)

**Fichiers autorisés** : M `backend/app/Http/Controllers/Api/Crm/OriginesController.php` ; M `backend/routes/api.php`
(UNE ligne, après `par-mot`, mêmes intergiciels) ; C `backend/tests/Feature/Crm/OriginesParGuideTest.php` ;
C `docs/envoi/lots/PO-G.md`.
**Comportement** : calque de `parMot` (mêmes trois portes : drapeau éteint ⇒ 404, fonction d'espace fermée ⇒ 404,
v3 absent ⇒ `{"actif": false}`) ; filtre `guide` accepté par `filtres()` partout où `mot` l'est. Lecture seule.
**Tests rouges d'abord** : drapeau éteint (défaut) ⇒ 404 ; ouvert ⇒ comptes par guide ; masquées exclues ; IDOR espace
B ; `throttle` présent sur la route (garde de couverture des routes du dépôt satisfaite).
**Mutations** : porte `lecture_enabled` retirée ⇒ rouge.
**Dépendances** : PO-A fusionnée.

### PO-D — écran de campagne : mots et guides

**Fichiers autorisés** : M `frontend/src/features/envoi/creation/FiltreReseau.tsx` ; M `…/creation/Recapitulatif.tsx` ;
M `…/creation/api.ts` ; M `…/creation/saisie.ts` ; M `…/creation/FiltreReseau.test.tsx` ; C `docs/envoi/lots/PO-D.md`.
**Fichiers chauds interdits** : `frontend/src/features/origines/**`, `routeTree.tsx`, `Sidebar.tsx`, tout le backend.
**Comportement** : le bloc « Restreindre par origine » gagne deux listes (mots depuis `par-mot`, guides depuis
`par-guide`), 10 cases au plus chacune ; 404 ou `{"actif": false}` ⇒ bloc absent, bandeau de retrait si le brouillon
porte un filtre (inchangé) ; corps envoyé trié ; récapitulatif « Uniquement les personnes venues de : … par le mot …
pour le guide … » ; compteur `hors_reseau` libellé « hors origine demandée ».
**Tests rouges d'abord** (vitest) : 404 ⇒ bloc absent ; deux mots cochés ⇒ corps exact `{"mots":["a","b"]}` ; onzième
case désactivée ; 422 `filtre_origine_inactif` affiché, aucun réessai ; récapitulatif exact.
**Mutations** : bloc affiché malgré 404 ⇒ rouge ; tri retiré ⇒ rouge ; borne 10 retirée ⇒ rouge.
**Dépendances** : PO-C et PO-G fusionnées.

### PO-L — liste des personnes : réseaux de chaque personne et filtre « réseau » (serveur)

**Fichiers autorisés** : M `backend/app/Http/Controllers/Api/Crm/PersonnesController.php` ;
C `backend/tests/Feature/Crm/PersonnesFiltreReseauTest.php` ; C `docs/envoi/lots/PO-L.md`.
**Fichiers chauds interdits** : `RequetesOrigines.php`, `OriginesController.php`, `config/origines.php`.

**Comportement**
- Paramètre `origine_reseau` (jeton `RequetesOrigines::JETON`, un seul) sur `GET /crm/personnes` et
  `GET /crm/personnes/export`. **Porte : `FiltreOrigine::actif($ws)`** (donc `ORIGINES_LECTURE_ENABLED`, lu par la garde
  existante, jamais directement). Porte fermée et paramètre présent ⇒ **422 `origine_lecture_inactive`** (jamais une
  liste non filtrée présentée comme filtrée ; ESCALADE E2) ; jeton invalide ⇒ 422 `validation`.
- Filtre : `personnes.origines @> '[{"reseau": …}]'` (préfiltre GIN) puis `EXISTS` sur une entrée non masquée (calque
  du filtre famille, `:744-759`). L'archive n'est pas lue ici (liste d'écran, comme le filtre famille actuel).
- Champ rendu `reseaux` (liste distincte, triée, des réseaux des entrées non masquées) **seulement porte ouverte** ;
  porte fermée ⇒ clé absente. Rien ne change pour `origine` / `origines_nombre` (garde v3 existante).
- `OriginesController::personnes` réutilise `requete()` : vérifier qu'il ne reçoit jamais `origine_reseau` sans porte.

**Tests rouges d'abord** (`pgsql_app` ET propriétaire) : porte fermée (défaut) : sans paramètre ⇒ 200, pas de clé
`reseaux` ; avec paramètre ⇒ 422 ; porte ouverte : filtre exact, entrée masquée exclue, `reseaux` trié et dédoublonné ;
export filtré identique à la liste ; espace B ; masquage des coordonnées inchangé.
**Mutations** : porte retirée ⇒ rouge ; `masque_le` retiré ⇒ rouge ; paramètre ignoré porte fermée ⇒ rouge.
**Dépendances** : aucune.

### PO-LE — liste des personnes : colonne et filtre « Réseau » (écran)

**Fichiers autorisés** : M `frontend/src/features/crm-console/PersonnesPage.tsx` ;
C `frontend/tests/screens/PersonnesReseau.test.tsx` ; M `frontend/src/demo/handlers/crm/alimenter.handlers.ts` (clé
`reseaux` dans la réponse de démo seulement) ; C `docs/envoi/lots/PO-LE.md`.
**Comportement** : la colonne « Réseau » (libellés de `features/origines/libelles.ts`, importés en lecture) et le filtre
(liste issue de `GET /crm/origines/par-reseau`) n'apparaissent **que** si cette lecture répond `actif` ; 404 ⇒ ni
colonne ni filtre, et le paramètre n'est jamais envoyé. 422 affiché tel quel.
**Tests rouges d'abord** (vitest) : 404 ⇒ ni colonne ni filtre ; actif ⇒ colonne et filtre, paramètre `origine_reseau`
envoyé ; 422 ⇒ message ; personne sans origine ⇒ cellule « — ».
**Mutations** : colonne affichée malgré 404 ⇒ rouge.
**Dépendances** : PO-L fusionnée.

---

## 5. Fiche 3 — LI-VOIE-S-CODE (lots LI-*)

### 5.0 Principes (lus dans la spécification et ses corrections)

- **Voie S seulement.** Le CRM ne parle **jamais** à LinkedIn ni à aucun outil d'automatisation : pas d'appel HTTP, pas
  d'import ni d'export vers un outil tiers, pas de récepteur d'événements tiers. Le fondateur ouvre le profil, colle le
  texte et clique lui-même ; le CRM ne fait que préparer, contrôler, compter et journaliser. Rien n'est prévu pour un
  outil tiers (ni colonne, ni drapeau, ni lot).
- Module à part : `backend/app/ProspectionLinkedIn/**`, routes `/api/v1/prospection-linkedin/*`, écran
  `frontend/src/features/prospection-linkedin/**`. Jamais dans `backend/app/Envoi/**`, **sauf** le registre
  d'opposition (LI-L3), qui appartient à l'envoi et que tout le CRM lit.
- Drapeau `PROSPECTION_LINKEDIN_ACTIVE=false` par défaut ; `ENVOI_REEL_ACTIF=false` ⇒ chaque texte préparé porte la
  mention « ESSAI » en tête (le fondateur voit qu'on est en essai ; le lot ne lit le drapeau que via `config('envoi…')`
  existant).
- Les tables `linkedin_*` de l'échafaudage sont **mortes** : ni lues, ni écrites, ni supprimées.
- Rapprochement d'un profil avec une fiche : `LinkedinSlug::normalize()` réutilisé ; lecture des `contacts.linkedin_url`
  (préfiltre `lower(linkedin_url) LIKE '%/in/' || slug || '%'`, confirmation par `normalize`) ; aucune colonne ajoutée
  aux contacts (volume quotidien faible ; ESCALADE E7).

### 5.1 Machine à états (monotone ; `ecarte` l'emporte toujours et est définitif)

`candidat → qualifie → controle → en_file → invite → connecte → message_envoye → repondu → humain → rdv → clos`.
Branches : `ecarte` (opposition, hors cible, demande de la personne) ; `en_pause` (arrêt) ; `sans_suite` (pas de
réponse après la dernière relance) ; `invitation_retiree` (le fondateur a retiré l'invitation ; nouvelle invitation
permise après 21 jours seulement) ; `plus_tard` (réponse « plus tard » : sort de la file jusqu'à `relance_le`).
Règle d'ordre : un geste qui ferait reculer l'état est journalisé `sans_effet = true` et ne change rien. Double clic
(même clé d'idempotence) ⇒ une seule ligne de journal, un seul changement.

Gestes humains (voie S, rien ne remonte automatiquement) : `invitation_faite`, `invitation_acceptee`, `message_fait`,
`a_repondu`, `invitation_retiree`, `plus_tard`, `interesse`, `rdv_pris`, `non`, `hors_cible`, `opposition`.

### LI-A — socle pur : configuration, états, textes et lien suivi

**Fichiers autorisés** : C `backend/config/prospection_linkedin.php` ; C `backend/app/ProspectionLinkedIn/EtatsProspect.php` ;
C `backend/app/ProspectionLinkedIn/TextePret.php` ; C `backend/app/ProspectionLinkedIn/LienSuivi.php` ;
C `backend/tests/Unit/ProspectionLinkedIn/SocleProspectionTest.php` ; C `docs/envoi/lots/LI-A.md`.
**Fichiers chauds interdits** : `.env.example` (tenu par X9-A/X9-B ; la variable y entre en LI-E), `config/envoi.php`.

**Comportement**
- Config : `active` = `env('PROSPECTION_LINKEDIN_ACTIVE', false)` ; défauts sûrs des plafonds (ESCALADE E5) ;
  `seuil_score` = 60 ; `delai_reinvitation_jours` = 21 ; `jours_contact_courriel` = 90.
- `EtatsProspect::appliquer(string $etat, string $geste): array{etat: string, sans_effet: bool}` : table de transitions
  exhaustive ; `ecarte` absorbant ; `opposition` depuis n'importe quel état ⇒ `ecarte` ; geste inconnu ⇒ exception.
- `TextePret::composer(string $gabarit, array $variables, bool $essai): string` : variables en liste blanche
  (`prenom`, `entreprise`, `accroche`, `lien`) ; le premier message DOIT contenir la phrase d'opposition (« répondez
  STOP ») et le lien de la politique de confidentialité (information art. 14), sinon refus ; mots refusés (liste
  fermée, insensible à la casse et aux accents : « certifié », « certification », « Qualiopi ») ⇒ refus ; longueur
  bornée (invitation ≤ 300 caractères) ; `essai` ⇒ préfixe « [ESSAI] ».
- `LienSuivi::pour(string $sequence, string $page): string` : format du lien suivi unique du parcours
  (`utm_source=linkedin`, `utm_medium=message`, `utm_campaign=seq-<code>`, `depuis=message-linkedin`) ; la variante
  n'est **pas** dans l'URL (elle est mesurée dans `pl_journal`) ; domaine lu de la configuration existante du site
  (exemple en test : `https://site.example.test`).

**Tests rouges d'abord** (unitaires, sans base) : table complète des transitions (chaque couple état × geste) ; recul
⇒ `sans_effet` ; `ecarte` puis `invitation_acceptee` ⇒ reste `ecarte` ; texte sans STOP ⇒ refus ; « Certifié » ⇒ refus ;
« QUALIOPI » ⇒ refus ; 301 caractères ⇒ refus ; essai ⇒ préfixe ; lien exact ; drapeau absent ⇒ `false`.
**Mutations** : `ecarte` non absorbant ⇒ rouge ; contrôle STOP retiré ⇒ rouge ; liste de mots vidée ⇒ rouge.
**Dépendances** : aucune.

### LI-G — garde d'architecture du module

**Fichiers autorisés** : C `backend/tests/Feature/ProspectionLinkedIn/ArchitectureProspectionTest.php` ; C `docs/envoi/lots/LI-G.md`.
**Comportement** (test qui lit les sources) : sous `backend/app/ProspectionLinkedIn/**`, `routes/prospection-linkedin.php`
et `frontend/src/features/prospection-linkedin/**` : aucun `Http::`, `curl_`, `fsockopen`, `file_get_contents('http`,
`fetch(` vers un domaine autre que l'API du CRM ; aucune mention des tables `linkedin_*`, de `cookie_blob`, `proxy_id` ;
aucun `->delete(`, `DELETE FROM`, `TRUNCATE` ; aucune lecture directe de `ENVOI_REEL_ACTIF` par `env(`. Le test passe
vert à vide (dossiers absents) et protège tous les lots suivants.
**Tests rouges d'abord** : un fichier témoin placé dans le dossier temporaire du test, contenant `Http::get`, est
détecté (preuve que la garde voit) ; un témoin contenant `cookie_blob` aussi.
**Mutations** : motif `Http::` retiré de la garde ⇒ témoin non détecté ⇒ rouge.
**Dépendances** : aucune.

### LI-L3 — opposition par profil LinkedIn (zone de l'envoi)

**Fichiers autorisés** : C `backend/app/Envoi/Conformite/RegistreOppositionLinkedin.php` ;
M `backend/app/Crm/Opposition/StatutOpposition.php` ; C `backend/tests/Feature/Envoi/Conformite/OppositionLinkedinTest.php` ;
C `docs/envoi/lots/LI-L3.md`.
**Fichiers chauds interdits** : `RegistreOppositionManuel.php` (réutilisé tel quel, jamais modifié),
`OppositionSortante.php`, `DepotSocleDb.php`.
Ce lot est dans la zone de l'envoi : la routine qui le code prévient sur #680 avant de pousser.

**Comportement**
- `RegistreOppositionLinkedin::inscrire(string $ws, string $profil, string $motif, string $auteur, string $source): bool` :
  `LinkedinSlug::normalize` (null ⇒ refus explicite, jamais une inscription vide) ; empreinte + valeur chiffrée ;
  `INSERT … ON CONFLICT DO NOTHING` (rejeu idempotent) ; rend `true` si nouvelle. Aucune suppression possible.
- `StatutOpposition::profilsOpposes(string $ws, list<string> $slugs): array<string, bool>` : UNE lecture pour la liste
  (empreintes) ; toute erreur ⇒ **exception** (l'appelant refuse : échec fermé) ; slug non normalisable ⇒ compté opposé
  (on ne contacte pas ce qu'on ne sait pas vérifier). `pourLignes()` **inchangée**.
- Une opposition LinkedIn d'une personne dont l'adresse ou le SIREN est connu (fiche rattachée) est AUSSI inscrite par
  `RegistreOppositionManuel::inscrire` (portée `adresse` ; `organisation` seulement si le fondateur le demande) :
  c'est LI-C qui l'appelle, ce qui déclenche l'émission vers le site par la fiche 1 (`OppositionSortante`, source
  `registre_manuel`).

**Tests rouges d'abord** (`pgsql_app` ET propriétaire) : trois formes d'URL d'un même profil ⇒ une seule ligne ; rejeu ⇒
`false`, 1 ligne ; `/company/x` ⇒ refus ; `profilsOpposes` : opposé, non opposé, non normalisable ⇒ opposé ; table
illisible (rôle sans `SELECT`, simulé) ⇒ exception ; espace B ne voit pas l'opposition de A ; aucune colonne en clair
(le slug n'apparaît nulle part en base hors `valeur_chiffree`).
**Mutations** : `ON CONFLICT` retiré ⇒ rouge ; non normalisable ⇒ « non opposé » ⇒ rouge ; filtre `workspace_id`
retiré ⇒ rouge en propriétaire.
**Dépendances** : PL-M0A déployée.

### LI-B1 — dépôt des prospects : ajout, rapprochement, gestes journalisés

**Fichiers autorisés** : C `backend/app/ProspectionLinkedIn/DepotProspects.php` ; C `backend/app/ProspectionLinkedIn/Rapprochement.php` ;
C `backend/app/ProspectionLinkedIn/JournalProspection.php` ; C `backend/tests/Feature/ProspectionLinkedIn/DepotProspectsTest.php` ;
C `docs/envoi/lots/LI-B1.md`.

**Comportement**
- `ajouter(ws, expediteur, profil, source, source_ref, source_le, entreprise?, siren?)` : slug normalisé (null ⇒ refus
  `profil_invalide`) ; source obligatoire ; profil **déjà opposé** (`profilsOpposes`) ⇒ refus `oppose`, **aucune ligne
  créée** (jamais réinscrit) ; doublon `(expediteur, slug)` ⇒ rend l'existant, rien créé ; `Rapprochement` : un contact
  dont `linkedin_url` se normalise au même slug ⇒ `contact_id` posé (premier par `id`, plusieurs ⇒ aucun, journal
  `rapprochement_ambigu`).
- `appliquerGeste(ws, prospect_id, geste, cle_idempotence, auteur, details)` : dans une transaction, `SELECT … FOR
  UPDATE` du prospect (filtré `workspace_id`), `EtatsProspect::appliquer`, ligne `pl_journal` (clé unique : rejeu ⇒
  rend le résultat précédent, rien de plus), puis `UPDATE` de l'état seulement si changement.
- `JournalProspection` : seul écrivain de `pl_journal` ; `details` filtrés (liste blanche de clés ; jamais d'adresse).

**Tests rouges d'abord** (`pgsql_app` ET propriétaire) : trois formes d'URL ⇒ un prospect ; profil opposé ⇒ refus, 0 ligne ;
slug d'un contact ⇒ rattaché ; deux contacts ⇒ aucun rattachement + journal ; double clic « invitation_faite » ⇒ 1 ligne
de journal, 1 changement ; `a_repondu` avant `message_fait` ⇒ état `repondu`, le tardif `sans_effet` ; `ecarte` puis
`invitation_acceptee` ⇒ reste `ecarte` ; prospect de l'espace A par l'espace B ⇒ introuvable ; aucune suppression.
**Mutations** : contrôle d'opposition à l'ajout retiré ⇒ rouge ; `FOR UPDATE` retiré ⇒ test de concurrence (deux
connexions) rouge ; unicité de clé ignorée ⇒ rouge.
**Dépendances** : PL-M0B déployée ; LI-A ; LI-L3.

### LI-B2 — contrôles, plafonds, arrêt et file du jour

**Fichiers autorisés** : C `backend/app/ProspectionLinkedIn/ControlesProspect.php` ; C `backend/app/ProspectionLinkedIn/FileDuJour.php` ;
C `backend/app/ProspectionLinkedIn/Plafonds.php` ; C `backend/app/ProspectionLinkedIn/Arret.php` ;
C `backend/tests/Feature/ProspectionLinkedIn/FileDuJourTest.php` ; C `docs/envoi/lots/LI-B2.md`.

**Comportement**
- `ControlesProspect::verifier(ws, list<prospect>)` en UN passage, échec fermé (toute exception ⇒ aucun prospect
  rendu, alerte) : opposition par profil (`profilsOpposes`) ; si fiche rattachée, opposition par adresse et SIREN
  (`StatutOpposition::pourLignes`) ; demande RGPD en cours ; société cliente ; fiche presse ; contacté par courriel il y a
  moins de 90 jours (`envoi_inscriptions` / journal d'envoi, lecture seule) ; arrêt actif. Un échec ⇒ geste système
  `controle_refuse` avec le motif ; un opposé ⇒ `ecarte`.
- `Plafonds::restants(ws, expediteur, maintenant)` : dernière ligne `pl_plafonds` ou défauts ; compte les gestes
  `invitation_faite` du jour et de la semaine ISO dans `pl_journal` ; samedi et dimanche ⇒ 0 si `jours_ouvres_seulement`.
- `Arret::actif(ws, expediteur)` : dernière ligne `pl_arret` (de l'expéditeur ou « tous ») ; erreur ⇒ considéré actif.
- `FileDuJour::construire(ws, expediteur)` : prospects `qualifie`/`controle` (et `plus_tard` échus), `score ≥ seuil`,
  tri score puis ancienneté, `min(restant jour, restant semaine)` premiers ; **relecture de l'opposition au moment de
  construire** et **de nouveau au geste « fait »** (LI-C) ; état ⇒ `en_file`. Arrêt actif ⇒ file vide + raison.
  Ne réinvite jamais un `invitation_retiree` de moins de 21 jours.

**Tests rouges d'abord** (`pgsql_app` ET propriétaire) : registre illisible ⇒ file vide, alerte, aucun état changé ;
opposition par adresse d'une fiche rattachée ⇒ `ecarte` ; contacté par courriel il y a 30 jours ⇒ exclu ; 40 demandés,
12 restants dans la semaine ⇒ 12 ; samedi ⇒ 0 ; arrêt actif (même posé pendant la construction, entre lecture et
écriture : relu juste avant l'`UPDATE`) ⇒ 0 ; arrêt de l'expéditeur A sans effet sur B ; retrait de moins de 21 jours ⇒
exclu, après ⇒ inclus ; espace B.
**Mutations** : relecture finale de l'arrêt retirée ⇒ rouge ; exception du registre avalée ⇒ rouge ; filtre samedi
retiré ⇒ rouge ; plafond semaine ignoré ⇒ rouge.
**Dépendances** : PL-M0B déployée ; LI-A ; LI-L3.

### LI-C — API de la file (lecture, ajout, gestes, opposition, arrêt, plafonds)

**Fichiers autorisés** : C `backend/routes/prospection-linkedin.php` ; M `backend/routes/api.php` (UNE ligne `require`,
dans le groupe `v1` authentifié) ; C `backend/app/Http/Controllers/Api/ProspectionLinkedInController.php` ;
C `backend/app/ProspectionLinkedIn/OpposerProspect.php` ; C `backend/tests/Feature/ProspectionLinkedIn/ProspectionRoutesTest.php` ;
C `docs/envoi/lots/LI-C.md`.

**Comportement**
- Drapeau éteint ⇒ toutes les routes 404 (avant tout accès base). Réservé au **propriétaire** de l'espace (modèle
  `EnvoiOppositionManuelleController`) ; journal d'audit propre, sans profil ni adresse dans l'empreinte du corps.
- `GET /prospection-linkedin/file` : file du jour de l'utilisateur courant (profil en lien `https://www.linkedin.com/in/<slug>/`,
  texte prêt de `TextePret`, restants, arrêt) ; `POST /prospects` (ajout) ; `POST /prospects/{id}/gestes`
  (`geste`, en-tête `Idempotency-Key` obligatoire, 16 à 128 caractères) ; `POST /arret` ; `POST /reprise` (motif
  obligatoire) ; `POST /plafonds` (bornes du CHECK ; jamais au-dessus des défauts sans confirmation `confirme: true`).
- Geste `invitation_faite` / `message_fait` : **relecture de l'opposition et de l'arrêt juste avant d'écrire** ; opposé
  ⇒ 409 `oppose`, prospect `ecarte`, alerte « opposé présent dans la file » + arrêt automatique (déclencheur
  `oppose_dans_file`) ; arrêté ⇒ 409 `arret`.
- `OpposerProspect` (geste `opposition`) : dans l'ordre et dans une transaction : registre LinkedIn
  (`RegistreOppositionLinkedin::inscrire`), si fiche rattachée avec adresse : `RegistreOppositionManuel::inscrire`
  (portée `adresse` ; ce qui émet vers le site par la fiche 1), puis état `ecarte`. Une erreur à n'importe quelle étape ⇒
  tout est annulé, 503, **et** le prospect est exclu des files par la relecture (le registre fait foi, échec fermé).
  Aucune suppression ; la fiche et le journal restent.
- Route `{id}` d'un autre espace ⇒ 404 (IDOR) ; garde de couverture des routes du dépôt (`tests/Support/Couverture/**`)
  satisfaite : si elle exige un contrat OpenAPI, l'ajouter dans ce lot et le dire dans le compte rendu.

**Tests rouges d'abord** (HTTP réel, `pgsql_app` ET propriétaire pour les écritures) : drapeau éteint ⇒ 404 partout ;
non propriétaire ⇒ 403 ; double clic même clé ⇒ 1 changement ; geste sans clé ⇒ 422 ; opposé entre la file et le clic ⇒
409 et arrêt posé ; opposition avec fiche rattachée ⇒ 1 ligne registre LinkedIn + 1 ligne registre adresse + 1 événement
`consent_optout` en file (fiche 1) ; opposition sans adresse ⇒ registre LinkedIn seul, 0 événement ; panne simulée du
registre adresse ⇒ rien écrit, 503 ; IDOR ; aucune route ne supprime ; aucune requête sortante (faux client HTTP qui
échoue s'il est appelé).
**Mutations** : relecture avant clic retirée ⇒ rouge ; transaction de `OpposerProspect` retirée ⇒ rouge ; contrôle du
propriétaire retiré ⇒ rouge.
**Dépendances** : LI-B1, LI-B2 ; PO-G fusionnée (fichier `routes/api.php`).

### LI-D1 — écran de la file « prêt à cliquer »

**Fichiers autorisés** : C `frontend/src/features/prospection-linkedin/FileLinkedinPage.tsx` ;
C `frontend/src/features/prospection-linkedin/api.ts` ; C `frontend/src/features/prospection-linkedin/CarteProspect.tsx` ;
C `frontend/src/features/prospection-linkedin/BoutonArret.tsx` ;
C `frontend/src/features/prospection-linkedin/FileLinkedinPage.test.tsx` ; C `docs/envoi/lots/LI-D1.md`.
**Comportement** : chaque carte : bouton « Ouvrir le profil » (lien `target="_blank" rel="noopener noreferrer"`, aucune
action automatique), bouton « Copier le texte », boutons de gestes (une clé d'idempotence générée par carte et par geste,
réutilisée en cas de nouvel essai) ; « Opposition » demande une confirmation ; bouton d'arrêt toujours visible (rouge
quand actif) ; restants du jour et de la semaine ; mention « ESSAI » visible si le texte la porte ; 404 ⇒ page « module
éteint ». Accessibilité : boutons nommés, focus visible, statut annoncé (`aria-live`).
**Tests rouges d'abord** (vitest) : 404 ⇒ page éteinte ; double clic ⇒ même clé envoyée deux fois ; 409 `oppose` ⇒ carte
retirée et bandeau d'arrêt ; aucune navigation automatique vers le profil (seul un clic l'ouvre) ; opposition sans
confirmation ⇒ aucun appel.
**Mutations** : clé régénérée à chaque clic ⇒ rouge ; confirmation retirée ⇒ rouge.
**Dépendances** : LI-C.

### LI-D2 — entrée de menu et route de l'écran

**Fichiers autorisés** : M `frontend/src/app/routeTree.tsx` ; M `frontend/src/components/layout/Sidebar.tsx` ; M `frontend/tests/non-regression/routes.test.ts` ; C `docs/envoi/lots/LI-D2.md`.
**Comportement** : route `/prospection-linkedin`, entrée de menu visible seulement si `GET /prospection-linkedin/file`
ne répond pas 404. **Tests** : menu absent si 404 ; présent sinon ; la garde des routes connaît la nouvelle route.
**Dépendances** : LI-D1.

### LI-E — alertes, rappels de relance et relevé hebdomadaire

**Fichiers autorisés** : C `backend/app/ProspectionLinkedIn/Alertes.php` ; C `backend/app/Console/Commands/ProspectionLinkedinReleve.php` ;
M `backend/routes/console.php` (planification, `skip` si drapeau éteint) ; M `.env.example`
(`PROSPECTION_LINKEDIN_ACTIVE=false` + commentaire ; **après** fusion de X9-B, sinon attendre) ;
C `backend/tests/Feature/ProspectionLinkedIn/AlertesReleveTest.php` ; C `docs/envoi/lots/LI-E.md`.
**Comportement** : `prospection-linkedin:releve` (quotidien, sans effet drapeau éteint) : rappels de relance échus ;
**arrêt automatique** si acceptation < 20 % sur les 50 dernières invitations ; relevé hebdomadaire (comptes par état et
par variante, aucune donnée de personne dans le message) ; alertes par `AlerteTelegram` existante (inerte sans
réglage) ; un avertissement de LinkedIn saisi par le fondateur (`POST /arret` déclencheur `avertissement`) ⇒ arrêt.
Aucun envoi à une personne : les alertes vont au seul fondateur.
**Tests rouges d'abord** : 50 invitations dont 9 acceptées ⇒ arrêt posé, alerte ; 10 acceptées ⇒ rien ; drapeau éteint ⇒
la commande sort sans lecture ; relevé sans profil ni adresse ; espace B ; Telegram non réglé ⇒ aucune requête.
**Mutations** : seuil 20 % inversé ⇒ rouge ; `skip` retiré ⇒ rouge.
**Dépendances** : LI-C.

---

## 6. Ordre conseillé (une routine par lot)

1. Vague 0, en même temps : PL-M0A, PO-A, PO-L, LI-A, LI-G.
2. Déployer PL-M0A ; vague 1 : PL-M0B, PO-B, PO-G, PO-LE, LI-L3.
3. Déployer PL-M0B ; vague 2 : PO-C, LI-B1, LI-B2.
4. Vague 3 : PO-D, LI-C. 5. Vague 4 : LI-D1, LI-E. 6. Vague 5 : LI-D2.

Branches `feat/pont-<lot en minuscules>` ; consignes de `CONSIGNES-ROUTINE.md` (#680) applicables, renvoi à ce cahier.
Après la vague 5, il ne reste que des gestes du fondateur : avis juridique et pièces RGPD (E4), allumages
(`ORIGINES_LECTURE_ENABLED`, fonction d'espace `origines.lecture`, `PROSPECTION_LINKEDIN_ACTIVE`, `CRM_OUTBOUND_ENABLED`),
gabarits de textes, et la décision de passer de l'essai au réel.

---

## 7. ESCALADE — décisions du fondateur (le défaut sans risque s'applique si rien n'est dit)

| # | Question | Défaut retenu (sans risque) | Autre choix |
|---|---|---|---|
| E1 | Opposition par profil : élargir le registre existant (`envoi_oppositions_registre`, portée `linkedin`) ou table dédiée ? | **Table dédiée** `envoi_oppositions_linkedin` : ajout pur (aucun `DROP CONSTRAINT`), profil chiffré + empreinte comme une adresse (le registre existant garde ses valeurs non-adresse en clair, ce qui ne convient pas à un profil de personne). Lue par `StatutOpposition::profilsOpposes`. | Élargir le registre : deux CHECK à remplacer (retrait de contrainte) et `RegistreOppositionManuel` à modifier ; à faire par la routine de l'envoi. |
| E2 | Filtre « réseau » de la liste des personnes demandé porte fermée : ignorer ou refuser ? | **Refuser (422)** : une liste ne doit jamais paraître filtrée alors qu'elle ne l'est pas. | Ignorer comme le filtre famille actuel. |
| E3 | Combinaison réseau + mot + guide : sur la même entrée d'origine (ET) ou sur des entrées différentes ? | **Même entrée** (le plus étroit). | Entrées différentes (plus large) ; ou OU entre mots. |
| E4 | Juridique : cibler une prospection (courriel ou LinkedIn) d'après le mot ou le guide par lequel une personne est venue, et prospecter sur LinkedIn (fiche de registre art. 30, mise en balance de l'intérêt légitime, information art. 14, droit d'accès avec la source par prospect, durée de conservation). | Tout reste inerte : drapeaux éteints ; aucun lot ne les allume. Avis juridique avant tout allumage. | — |
| E5 | Plafonds par défaut de la voie S (le fondateur clique) | Prudents : 10 invitations/jour, 50/semaine, 20 messages/jour, 5 notes/mois, jours ouvrés seulement ; modifiables par le propriétaire, journalisés. | Autres valeurs, dans les bornes du CHECK. |
| E6 | Opposition LinkedIn sans adresse connue : le site ne peut pas la recevoir (événement keyé par adresse). | Registre LinkedIn seul ; le CRM ne prépare plus rien pour ce profil. | Étendre le contrat d'événements CRM → site à une empreinte de profil (lot des deux dépôts). |
| E7 | Rapprochement profil ↔ fiche : lecture de `contacts.linkedin_url` à la volée, ou colonne indexée `linkedin_slug` sur les contacts ? | **Lecture à la volée** (volume quotidien faible, aucune colonne sur une table chaude). | Colonne + index + remplissage par commande (migration seule + lot). |
| E8 | Inviter d'abord les personnes qui ont déjà réagi à un post (source `commentaire_post`, via le contrat d'échange existant) ? | Le score donne +30 au signal « a réagi » ; aucune saisie à part. | Restreindre la file à ces seules personnes au début. |
| E9 | Allumages (production) : `ORIGINES_LECTURE_ENABLED`, `origines.lecture`, `PROSPECTION_LINKEDIN_ACTIVE`, `CRM_OUTBOUND_ENABLED` + secret partagé (fiche 1). | Éteints ; gestes du fondateur, hors lots. | — |

CAHIER PRÊT
