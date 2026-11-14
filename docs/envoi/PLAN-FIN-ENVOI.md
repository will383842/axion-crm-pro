# Plan de fin du système d'envoi (palier 0 et suivants)

**Statut** : plan, aucun code. Rédigé depuis l'état de `main` au commit `954543d` (R19 fusionné).
**Pour qui** : les agents qui coderont chaque PR, et le relecteur. Le fondateur a demandé une réalisation de bout en bout, décisions prises selon les recommandations ; elles sont toutes listées en section 3.
**Règle absolue** : `ENVOI_REEL_ACTIF` reste `false` dans toutes les PR. Il n'y a ni domaine d'envoi acheté ni serveur d'envoi : aucune PR n'ouvre une connexion réseau vers un serveur de courrier, aucun test non plus. Le stop gagne toujours. L'anti-boucle site-sync n'est pas touchée (aucune PR n'écrit dans les tables ni dans les files de synchronisation du site).

---

## 1. Ce qui existe déjà (constaté dans le code, non replanifié)

| Sujet | Déjà sur `main` | Ce qui manque vraiment |
|---|---|---|
| Plafond du jour | `PasserelleLocale` : plafond par espace et global, bornés à 300 (`PLAFOND_MAX`), comptés sous le verrou consultatif du jour ; réservation comptée ; `envoi_compteur_global` ; `PlafondConcurrenceTest` (plusieurs processus réels). | Une **butée en base** par espace (aujourd'hui la seule garde est le code PHP) ; le plafond enregistré dans les réglages R14 n'est **lu par personne**. |
| Reprise après panne | `Sequenceur` en trois temps (réserver, injecter, conclure), reprise des lignes « reserve » après `RESERVATION_REPRISE_HEURES`, issue incertaine = arrêt + alerte, jamais de renvoi ; tests unitaires de reprise (`SequenceurTest`, l. 1192 à 1226). `envoi:perimer-reservations` existe. | Aucun **processus** n'appelle le séquenceur (aucun `new Sequenceur` hors tests) ; `envoi:perimer-reservations` n'est **pas planifiée** ; aucune preuve de reprise après **arrêt brutal d'un vrai processus**. |
| Listes de refus | Registre manuel R13 (adresse, domaine, SIREN), `email_suppressions`, contrôle `opposition_registre_manuel` en dernier dans `EnvoiAutorise` (temps A du séquenceur). `PasserelleLocale` relit `adresseOpposee` sous le verrou, mais cette méthode ignore le registre R13. | **Import** en masse ; **contrôle du registre au moment de l'injection** (une opposition ajoutée entre le temps A et l'injection doit gagner). |
| Chef d'orchestre | `Sequenceur`, `EnvoiAutorise`, `ComposeurMessage`, `PasserelleLocale`, `FauxServeurEnvoi` existent. `ReleveImap` est **déjà planifiée** chaque minute (L8-F12, derrière `ENVOI_REEL_ACTIF` et `ENVOI_RELEVE_ACTIF`). | La commande qui enchaîne le tout, sa planification, et l'**essai à blanc** de bout en bout. |
| Adaptateur d'envoi | `ServeurEnvoi` est lié à `PasserelleLocale(FauxServeurEnvoi)` pour tout le monde ; `ServeurEnvoiKumo` et `AdaptateurSmtp` existent mais ne sont branchés nulle part. | Le choix **par domaine** et ses verrous. |
| Feu vert | `GET /envoi/feu-vert`, `POST /envoi/feu-vert/{code}/fait`, catalogue v3 (44 points), `FeuVertPage`. | Le **geste final** du propriétaire, consigné ; des points automatiques sur l'essai à blanc, la butée et les adaptateurs. |
| K14 | `docs/envoi/fiche-inscriptions-reputation.md` (Postmaster, SNDS/JMRP, Yahoo, Orange, Signal Spam) ; points `manuel.postmaster_google`, `manuel.snds_jmrp`, `manuel.orange_cfl`, `manuel.signal_spam`. | **Erreur** : le catalogue appelle Orange « boucle de plaintes CFL » alors que la CFL est celle de **Yahoo** (la fiche le dit) ; Yahoo n'a **aucun** point. Pas d'écran de suivi. |
| CRM-G4 | Données d'opposition en base (`email_suppressions`, `envoi_oppositions`, registre R13) ; `ContenusAccordsCard` et `ContenusAccords::presenterOrigine` (n'exposent jamais `commentaire_ref`). | Le **badge « opposée »** dans les écrans du CRM, l'impossibilité de sélectionner une adresse opposée pour un envoi, un écran « accord donné » lisible. |
| Dettes R06 | `envoi_tentatives` (ajout seul) et `LecteurReportsDb`. | Durée de conservation, `code_smtp` toujours NULL, tests « attente » et « refusée », variables `$serveur` et `$p` inutilisées dans le test « annulation de la transaction de conclusion » de `LecteurReportsDbTest`. |

**Les 19 trous R19** (aucune route, un appel répond 404) : boîtes ×7, chauffe ×2, séquences ×4, socle ×4 (`GET /envoi/etat`, `GET /envoi/compteurs`, `POST /envoi/tout-arreter`, `POST /envoi/reprendre`), `POST /envoi/sante/test`, `POST /notifications/{id}/lue`.

**Conflit relevé** : deux trous ne peuvent PAS devenir des routes sans affaiblir un test. `AucuneRouteNeCoupeLesTemoinsTest` interdit `tout-arreter` dans toute URI ; `AucuneRouteTemoinSingulierTest` interdit `reprendre`, `armer` et `temoin` dans toute URI **et tout nom d'action**. `ToutArreter.php` et `runbook-serveur-perdu.md` disent : « la reprise est une commande locale, jamais une route ». Voir la décision D1.

---

## 2. Règles communes à toutes les PR

- Branche créée depuis `origin/main` au moment de commencer (jamais d'empilement). 250 à 400 lignes, tests compris. La description liste les fichiers autorisés (la liste fermée ci-dessous) et rien d'autre n'est touché.
- **Tests rouges d'abord** : chaque test listé « rouge exigé » est écrit, lancé, vu rouge (sortie collée dans la PR), puis le code le rend vert.
- Aucun test affaibli, sauté ni supprimé. Un fichier de test **déplacé** (S0) garde ses assertions à l'identique, et un test vérifie que rien n'a été perdu.
- Migrations : **seulement dans S1** (section 5), en ajouts seuls, `down()` réel ou inerte déclaré dans `NR17_RETOUR_INERTE_VOULU`.
- Chaque PR écrit son compte rendu `docs/envoi/lots/<ID>.md` (Fait, Reste, Risques, Tests lancés) : fichier propre à la PR, compté dans sa liste fermée.
- `cd backend && vendor/bin/pint --dirty`, PHPStan niveau 8, tests ciblés ; côté frontend `pnpm test` ciblé et `pnpm lint`.
- Aucun test ne touche le réseau : `Http::preventStrayRequests()`, résolveurs en mémoire (`ResolveurEnMemoire`, `LecteurBoiteMemoire`, `FabriqueLecteurFixe`), `FauxServeurEnvoi`.
- Noms interdits dans les routes (URI **et** nom de méthode de contrôleur) : `temoin`, `temoins`, `temoin-off`, `tout-arreter`, `reprendre`, `armer`. Une méthode de contrôleur s'appelle donc `reprise`, `arretGeneral`, `reactiver`, jamais `reprendre` ni `armer…`.
- Aucune route `GET /envoi/*` ne résout `ServeurEnvoi` (`AucuneRouteEnvoiNeResoutServeurEnvoiTest`) : les lectures lisent la base.
- Rien de privé : pas d'adresse, d'IP, de domaine ni de nom réels ; les exemples sont en `*.example.test`.
- **Audit Opus sécurité + données obligatoire** pour les PR marquées « Audit : oui » (section 6). Un audit est périmé dès que la tête de la PR change.

### Fichiers communs et qui les tient

Deux PR d'une même vague ne touchent jamais le même fichier. Les fichiers communs sont soit **éclatés une fois pour toutes par le socle S0** (chaque PR touche ensuite seulement son morceau), soit **tenus par une seule PR par vague** :

| Fichier commun | Traitement |
|---|---|
| `backend/app/Providers/EnvoiServiceProvider.php` | S0 seul. Il y ajoute l'enregistrement d'une **liste fermée** de sous-fournisseurs `app/Providers/Envoi/*ServiceProvider.php`, créés vides par S0 ; chaque PR ne remplit que le sien. |
| `backend/routes/envoi.php` et `routes/envoi/*.php` | S0 ajoute le domaine `socle` (fichier `routes/envoi/socle.php` vide). Ensuite un fichier de domaine = une PR par vague. |
| `backend/routes/console.php` | S0 seul : il `require` `routes/console/envoi-orchestration.php` et `routes/console/envoi-retention.php` (vides). L8-ORCH remplit le premier (vague 3), R06-ARCH le second (vague 4). |
| `tests/Feature/NonRegression/instantanes/nr07-*.json`, `nr08-*.json` | L8-ORCH (vague 3), puis R06-ARCH (vague 4). Jamais deux dans la même vague. |
| `backend/openapi/envoi/commun.yaml` | S2-CONTRATS seul (vague 2). |
| Autres yaml d'envoi et `.d.ts` | `socle.yaml` + `socle.d.ts` : S2-SOCLE ; `conformite`, `pilotage`, `infrastructure` (+ `.d.ts`) : S2-CONTRATS. Aucune autre PR ne touche un yaml. |
| `backend/tests/Support/SemeurTablesScopees.php`, `Nr17MigrationsTest.php`, `nr17-migrations-sans-retour.json`, `RlsTest` | S1 seul (toutes les migrations du plan y sont). |
| `CouvertureContratEnvoiTest.php` (`TROUS_REELS`) | S0 l'éclate en `tests/Support/Couverture/trous/<domaine>.php` ; chaque PR retire ses trous de **son** fichier. |
| `tests/Support/NonServiAuPalier0.php` | S0 l'éclate en `tests/Support/Couverture/non-servis/<domaine>.php` (le fichier d'origine devient l'agrégateur). |
| `tests/Feature/Envoi/Routes/instantane-routes-envoi.json` | S0 l'éclate par premier segment d'URI : `instantane-routes-envoi/<segment>.json` (`boites.json`, `chauffe.json`, …). |
| `frontend/src/app/routeTree.tsx`, `OngletsSante.tsx`, `OngletsCampagnes.tsx`, `tests/components/routes-envoi-gardees.test.tsx` | S2-ROUTEUR seul (vague 2) : il crée les routes et des pages d'attente ; les PR suivantes ne remplissent que leur page. |
| `frontend/src/lib/api.ts` | Aucune PR ne le touche (aucun besoin constaté). |
| `frontend/tests/msw/handlers.ts` | S2-SOCLE seul ; les autres PR passent leurs gestionnaires dans chaque test (`getJson`, `recordPost`). |
| `tests/Feature/NonRegression/instantanes/nr20-routes.json` | Instantané « au moins » : ajouter une route ne le fait pas rougir. Le régénérer **seulement** si la garde frontend (`frontend/tests/non-regression/routes.test.ts`) rougit, et alors une seule PR par vague (déclaré dans sa description ; à défaut, la PR attend la vague suivante). |
| `backend/app/Envoi/FeuVert/CatalogueManuels.php` | K14-CAT (vague 2), puis K17-FV (vague 3). |
| `backend/app/Envoi/PasserelleLocale.php` | R06-D (vague 1), puis P1-PLAFOND (vague 2). |
| `backend/app/Envoi/Depot/DepotSocleDb.php` | P1-PLAFOND (vague 2), puis P1-LN (vague 3). |

---

## 3. Décisions (prises selon la recommandation)

- **D1 — « Tout arrêter » et « Reprendre »** : le contrat change, pas les tests. `POST /envoi/tout-arreter` devient `POST /envoi/arret-general` ; `POST /envoi/reprendre` **sort du contrat**. Raisons : deux tests de garde interdisent ces mots dans les routes ; le runbook et `ToutArreter` exigent que la reprise reste une commande locale journalisée (`envoi:reprendre <espace> --auteur=`). L'arrêt, lui, va dans le sens sûr : il doit être à un clic. La route arme **seulement le drapeau de l'espace** (`DepotSocle::armerToutArreter`), sans `ServeurEnvoi::pause()` (cette pause est globale et toucherait les autres espaces) ; permission `users.manage` (propriétaire, administrateur ; opérateur et lecteur : 403 avec le message « demandez à un administrateur »). Idempotente : un second appel répond 200 sans nouvelle écriture. À l'écran, le bouton « Reprendre les envois » est remplacé par un encart qui explique la commande locale et qui doit la lancer.
- **D2 — Notifications** : le contrat s'aligne sur la route réelle, `POST /notifications/{id}/read` (au lieu de `/lue`). Aucune route nouvelle.
- **D3 — Boîtes** : `GET /envoi/boites`, `GET /envoi/boites/{id}`, `POST /{id}/suspendre`, `POST /{id}/reactiver`, `PUT /{id}/reglages` sont servis. `POST /envoi/boites` et `POST /envoi/boites/test-connexion` répondent **501 `serveur_reel_requis`** : une boîte n'a de sens qu'avec un domaine acheté et un serveur, et ses secrets s'entreront par une commande locale masquée, comme `envoi:temoins-acces`. `reactiver` ne lève qu'une suspension **manuelle** ; une pause automatique répond 409 `pause_automatique` (seule `envoi:lever-pause` la lève). Aucun secret de boîte ne sort jamais d'une réponse. `PUT reglages` : bornes du contrat ; une limite peut descendre librement, monter seulement jusqu'au budget de chauffe du jour (422 au-delà).
- **D4 — `POST /envoi/sante/test`** : route servie, réponse **409 `envoi_ferme_palier_0`** (ce que dit déjà `pilotage.yaml` depuis R18), entrée dans `non-servis/sante.php`.
- **D5 — Chauffe** : `GET /envoi/chauffe` (lecture de `DepotChauffe`/`CourbeChauffe`) et `POST /envoi/chauffe/{id}/reculer` servis ; le recul va dans le sens sûr ; propriétaire seul (`workspaces.manage`), journalisé, idempotent pour la même journée.
- **D6 — Pause d'une séquence** : nouvelle table en ajout seul `envoi_sequences_gestes` (pause/reprise, auteur, motif, horodatage ; l'état est le dernier geste). Une séquence en pause est **sautée** par `Sequenceur::executer` (filtre dans la requête), elle ne consomme donc pas `REPORTS_MAX`. `reprise` répond 409 si l'arrêt général, une pause automatique ou un frein est actif ; une reprise ne lève jamais un frein. `pause` : `users.manage` ; `reprise` : `workspaces.manage` (contrat).
- **D7 — Plafond de 300 par jour et par espace** : butée **en base** en plus du code. Table `envoi_compteur_espace (workspace_id, jour, n)` et fonction `SECURITY DEFINER` `envoi_compteur_espace_reserver(ws, jour, plafond)` qui incrémente en une seule instruction (`INSERT … ON CONFLICT DO UPDATE … WHERE n < LEAST(plafond, 300) RETURNING n`) et rend faux si la place n'existe pas. Le rôle applicatif n'a aucun droit `UPDATE` sur la table : seule la fonction écrit. Plafond effectif = `min(réglage R14 de l'espace, ENVOI_PLAFOND_JOUR, 300)`. Pas de `CHECK (n <= 300)` figé dans la table : le relever un jour exigerait un `DROP CONSTRAINT`, interdit ; la borne vit dans la fonction (`CREATE OR REPLACE`). Le plafond global reste celui du code et de `envoi_compteur_global`.
- **D8 — Listes de refus** : pas de nouvelle liste ; on **réutilise le registre R13** (portées adresse, domaine, SIREN). Import réservé au propriétaire, CSV d'une colonne (adresse ou domaine), 5 000 lignes et 1 Mo au plus, en **deux temps** obligatoires : simulation (compte des lignes valides, déjà présentes et refusées, par numéro de ligne, sans jamais renvoyer une adresse), puis confirmation qui porte l'empreinte SHA-256 du fichier simulé (sinon 409). Les lignes importées gardent `source = 'manuel'` et portent `import_id` (colonne ajoutée). Adresses en empreinte + valeur chiffrée, comme R13. Domaine de messagerie grand public refusé (422, règle R13). **Contrôle avant chaque envoi** : `DepotSocleDb::adresseOpposee` lit aussi le registre (adresse, domaine et sous-domaines) ; `PasserelleLocale` l'appelle déjà sous le verrou du jour, juste avant l'injection.
- **D9 — Chef d'orchestre** : commande `envoi:cycle`, planifiée chaque minute, `withoutOverlapping(5)`, `onOneServer()`, `->when(reel_actif)`. La commande se garde elle-même : `ENVOI_REEL_ACTIF=false` ⇒ elle ne fait rien et le dit. Raison : lancer le séquenceur porte fermée reporterait chaque inscription et en arrêterait après `REPORTS_MAX` reports, ce qui détruirait le travail. Ordre : (1) `perimerToutesLesReservations` (ce que fait `envoi:perimer-reservations`) ; (2) pour chaque espace qui a le produit Envoi actif et pas d'arrêt général : `Sequenceur::executer($ws, $maintenant, 50)` sous `WorkspaceContext` ; (3) bilan journalisé (`envoi_journal_commandes`, sans adresse). `EnvoiAutorise`, `ComposeurMessage`, `PasserelleLocale` sont appelés **par** le séquenceur, pas par l'orchestrateur. `ReleveImap` reste sur sa planification actuelle (déjà en place, inchangée).
- **D10 — Essai à blanc** : commande locale `envoi:essai-a-blanc {espace} --auteur=`, **aucune route**. Elle construit elle-même sa chaîne avec `new FauxServeurEnvoi` (jamais le `ServeurEnvoi` du conteneur), un résolveur MX et un lecteur de boîte en mémoire, `Http::preventStrayRequests()`, et fait tout le parcours (inscription fictive `*.example.test`, composition, injection, rebond dur simulé, réponse STOP simulée relue par `ReleveImap` sur `LecteurBoiteMemoire`, arrêt de l'étape suivante, plafond, arrêt général) **dans une transaction annulée à la fin**. Seule la ligne de résultat (`envoi_essais_blanc`, étapes vert/rouge, sans adresse) est écrite après l'annulation. La porte « envoi réel » est ouverte **seulement** pour cette passerelle locale à la commande, par une fermeture injectée ; la configuration n'est jamais modifiée. Le résultat nourrit le point de feu vert `auto.essai_a_blanc_recent` (réussi depuis moins de 7 jours).
- **D11 — Adaptateur par domaine** : colonne `envoi_domaines.adaptateur` (`faux` par défaut, `kumo`, `smtp`), changée **seulement** par une commande locale journalisée `envoi:domaine-adaptateur {espace} {domaine} {faux|kumo|smtp} --auteur=`. Un `AiguilleurServeurEnvoi` remplace `FauxServeurEnvoi` comme serveur interne de `PasserelleLocale` et choisit selon le domaine de l'expéditeur. Un adaptateur réel n'est atteint que si **les trois** sont vrais : `ENVOI_REEL_ACTIF`, dernière décision de feu vert = « accordé », contrôle DNS du jour vert pour ce domaine. Sinon : refus (`ServeurEnvoiRefuse`, le message est reporté), **jamais de repli silencieux sur le faux serveur**, qui marquerait comme « envoyé » un message jamais parti. Pour la même raison, un domaine resté sur `faux` alors que `ENVOI_REEL_ACTIF=true` est refusé (`domaine_sur_faux_serveur`).
- **D12 — Feu vert final** : `POST /envoi/feu-vert/decision` `{decision: accorde|retire, motif}` ; propriétaire seul (`workspaces.manage`) ; en-tête `If-Match` = `empreinte_liste` lue à l'écran (la décision vaut pour l'état exact vu ; 412/409 sinon) ; « accorde » refusé 409 tant qu'un point bloquant est rouge ; « retire » toujours accepté (le stop gagne). Consigné en ajout seul avec la photo des points. Le feu vert est **nécessaire, pas suffisant** : il ne touche jamais `ENVOI_REEL_ACTIF`, qui reste une décision d'exploitation hors application. Nouveaux points automatiques : `auto.essai_a_blanc_recent`, `auto.plafond_butee_base`, `auto.adaptateurs_domaines`.
- **D13 — K14** : catalogue v4 : ajout de `manuel.yahoo_cfl` (« Inscription Yahoo, boucle de plaintes CFL ») ; le libellé de `manuel.orange_cfl` devient « Orange : prise de contact délivrabilité » ; **le code ne change pas** (les preuves déjà posées restent valides). Écran « Inscriptions » en **lecture seule** sous Santé, construit avec `GET /envoi/feu-vert` (points de la source « 30 A11 ») et `GET /envoi/reputation` : aucune route nouvelle, aucun bouton qui inscrit.
- **D14 — CRM-G4, adresse opposée** : une seule fonction serveur, `App\Crm\Opposition\StatutOpposition`, lue par lots (empreintes) : `email_suppressions`, `envoi_oppositions` (stop, page, un clic), registre R13 (adresse, domaine, SIREN), demandes d'opposition RGPD art. 21 (la PR constate dans le code la table qui les porte et la nomme dans sa description). Sortie : `opposition: {opposee: bool, motifs: ['stop'|'art21'|'liste_refus']}`, sans date ni contenu. Lecture seule : aucune écriture, donc rien vers le site. Écrans : badge « opposée » partout où une personne ou un contact s'affiche dans les écrans listés ; dans les écrans d'envoi, case non cochable et 422 `adresse_opposee` côté serveur si un identifiant opposé est forcé.
- **D15 — Écran « accord donné »** : sur la fiche personne, une section lit le bloc existant `contenus_accords` : origine (famille, détail, canal), date, preuve (`version_texte` du texte accepté, canal). Jamais `commentaire_ref`, et `post_ref`/`post_url` ne sont pas affichés dans cette section (inutiles à la preuve). Sans accord : « Aucun accord enregistré », jamais une valeur inventée.
- **D16 — Conservation de `envoi_tentatives`** : détail gardé **400 jours** (13 mois, de quoi répondre à une contestation), puis agrégé par jour dans `envoi_tentatives_jours` (espace, jour, fournisseur, issue, code SMTP, nombre ; ajout seul ; aucune donnée de personne). La fonction `SECURITY DEFINER` `envoi_tentatives_archiver(avant date)` agrège puis supprime le détail dans la même transaction ; le déclencheur d'ajout seul est remplacé (`CREATE OR REPLACE FUNCTION`) pour n'accepter un `DELETE` que si la variable de session `envoi.archivage_tentatives` vaut `on` (posée en `SET LOCAL` par la fonction) **et** si la ligne a plus de 400 jours. Aucune autre table n'est concernée ; jamais une personne, jamais une opposition. Tâche quotidienne à 04:50.
- **D17 — M3 (séparation par client, licence)** : **après** le premier envoi. Voir section 8.
- **D18 — Tests par l'interface** : Playwright est en place (`tests/e2e/demo/`, joué par `.github/workflows/demo.yml` sur le build de démo, données MSW, aucun serveur réel) ; les nouveaux scénarios s'y ajoutent sous `dm3-*.spec.ts` (aucune inscription dans `couverture-e2e.json` nécessaire). Le comportement serveur réel reste prouvé par Pest.
- **D19 — Taille des lots** : les PR « socle » S0, S1, S2-* ouvrent la voie sans comportement nouveau ; elles passent avant tout le reste pour qu'aucune vague ne partage un fichier.

---

## 4. Vagues d'exécution

| Vague | PR (en parallèle) | Attend |
|---|---|---|
| 1 | S0, S1, K14-DOC, R06-D, G4-API | rien |
| 2 | S2-SOCLE, S2-CONTRATS, S2-ROUTEUR, T-BOITES, T-CHAUFFE, T-SEQ, T-SANTE, P1-PLAFOND, K14-CAT, G4-ENVOI, G4-UI | vague 1 fusionnée |
| 3 | T-SOCLE, P1-LN, L8-ORCH, K17-FV, K14-ECRAN, F-SEQ, G4-ACCORD | vague 2 fusionnée |
| 4 | L8-ESSAI, K17-AIG, R06-ARCH, F-FV, F-LN | vague 3 fusionnée |
| 5 | P1-REPRISE, E2E-FIN | vague 4 fusionnée |

Contrôle « aucun fichier partagé dans une vague » : fait à la main sur les listes de la section 5 ; chaque PR le revérifie (`git diff --name-only origin/main` comparé aux PR ouvertes de sa vague) avant de pousser.

---

## 5. Les PR

Notation : **C** = créé, **M** = modifié, **D** = déplacé (contenu identique). Chemins relatifs à la racine du dépôt.

### Vague 1

#### S0 — Socle des fichiers communs (backend)
- **Objet** : éclater une fois pour toutes les fichiers que toutes les PR toucheraient, sans comportement nouveau.
- **Fichiers** :
  - M `backend/app/Providers/EnvoiServiceProvider.php` (fin de `register()` : enregistre dans cet ordre la liste fermée des cinq sous-fournisseurs ci-dessous)
  - C `backend/app/Providers/Envoi/OrchestrationServiceProvider.php`, `AiguillageServiceProvider.php`, `OppositionsImportServiceProvider.php`, `RetentionServiceProvider.php`, `FeuVertDecisionServiceProvider.php` (vides, commentaire « rempli par <ID> »)
  - M `backend/routes/envoi.php` (ajoute `socle` à la liste des domaines) ; C `backend/routes/envoi/socle.php` (vide)
  - M `backend/routes/console.php` (deux `require`) ; C `backend/routes/console/envoi-orchestration.php`, `backend/routes/console/envoi-retention.php` (vides)
  - M `backend/tests/Feature/Envoi/CouvertureContratEnvoiTest.php` (lit `TROUS_REELS` dans les fichiers ci-dessous ; assertions inchangées)
  - C `backend/tests/Support/Couverture/trous/{boites,chauffe,sequences,socle,sante,notifications}.php`
  - M `backend/tests/Support/NonServiAuPalier0.php` (agrégateur) ; C `backend/tests/Support/Couverture/non-servis/{conformite,creation,infrastructure,reponses}.php`
  - M `backend/tests/Feature/Envoi/Routes/RoutesEnvoiInstantaneTest.php` (instantané lu par segment ; `socle` dans la liste des domaines)
  - D `backend/tests/Feature/Envoi/Routes/instantane-routes-envoi.json` → `backend/tests/Feature/Envoi/Routes/instantane-routes-envoi/<segment>.json`
  - C `backend/tests/Feature/Envoi/SocleFichiersCommunsTest.php` ; C `docs/envoi/lots/S0.md`
- **Migrations** : aucune.
- **Tests rouges exigés** : `SocleFichiersCommunsTest` (sous-fournisseurs enregistrés dans l'ordre et vides ; `routes/console/*.php` requis ; union des fichiers de trous = les 19 trous de R19 ; union des instantanés par segment = l'ancien fichier, ligne pour ligne).
- **Acceptation** : `CouvertureContratEnvoiTest`, `NonServiAuPalier0Test`, `RoutesEnvoiInstantaneTest` verts sans changement d'assertion ; 19 `todo` toujours visibles ; aucune route ajoutée ; NR-07/NR-08 inchangés (fichiers vides).
- **Dépendances** : aucune. **Audit** : non (aucune donnée, aucune route).

#### S1 — Schéma (toutes les migrations du plan)
- **Objet** : poser en une fois tables et colonnes, chacune réservée à sa PR, pour qu'aucune autre PR ne touche les filets de migration.
- **Migrations réservées** (ajouts seuls, RLS forcée par espace, droits minimaux, `down()` inerte déclaré, `SET search_path = pg_catalog, public` sur toute fonction `SECURITY DEFINER`) :

| Numéro | Fichier | Pour | Contenu |
|---|---|---|---|
| `2026_12_03_000010` | `envoi_compteur_espace.php` | P1-PLAFOND | table `envoi_compteur_espace` (PK espace+jour, `n >= 0`), fonction `envoi_compteur_espace_reserver` ; aucun `UPDATE` accordé au rôle applicatif |
| `2026_12_03_000020` | `envoi_oppositions_imports.php` | P1-LN | table `envoi_oppositions_imports` (auteur, empreinte du fichier, compteurs, horodatage ; ajout seul ; CHECK : aucun `@` dans les textes) ; colonne `envoi_oppositions_registre.import_id` (nullable, FK) |
| `2026_12_03_000030` | `envoi_essais_blanc.php` | L8-ESSAI | table `envoi_essais_blanc` (auteur, début, fin, réussi, étapes en jsonb ; ajout seul ; CHECK : aucun `@`) |
| `2026_12_03_000040` | `envoi_domaines_adaptateur.php` | K17-AIG | colonne `envoi_domaines.adaptateur TEXT NOT NULL DEFAULT 'faux' CHECK (adaptateur IN ('faux','kumo','smtp'))` |
| `2026_12_03_000050` | `envoi_feu_vert_decisions.php` | K17-FV | table `envoi_feu_vert_decisions` (décision accorde/retire, auteur, motif, empreinte de la liste, points en jsonb ; ajout seul) |
| `2026_12_03_000060` | `envoi_tentatives_retention.php` | R06-ARCH | table `envoi_tentatives_jours` (ajout seul) ; fonction `envoi_tentatives_archiver` ; déclencheur d'ajout seul de `envoi_tentatives` remplacé selon D16 |
| `2026_12_03_000070` | `envoi_sequences_gestes.php` | T-SEQ | table `envoi_sequences_gestes` (séquence, geste pause/reprise, auteur, motif ; ajout seul) |

  Numéros `2026_12_03_000080` à `000099` laissés libres pour une correction de S1 ; aucune autre PR du plan ne crée de migration.
- **Fichiers** : C les 7 migrations ci-dessus sous `backend/database/migrations/` ; M `backend/tests/Support/SemeurTablesScopees.php` ; M `backend/tests/Feature/NonRegression/Nr17MigrationsTest.php` (`NR17_RETOUR_INERTE_VOULU`) ; M `backend/tests/Feature/NonRegression/instantanes/nr17-migrations-sans-retour.json` ; C `backend/tests/Feature/Envoi/SchemaFinEnvoiTest.php` ; C `docs/envoi/lots/S1.md`.
- **Tests rouges exigés** (`SchemaFinEnvoiTest`) : chaque table refuse `UPDATE`/`DELETE`/`TRUNCATE` (sauf D16 : `DELETE` accepté seulement par la fonction et pour une ligne de plus de 400 jours ; refusé sinon, même par la fonction pour une ligne récente) ; RLS : un espace ne voit pas l'autre (connexion applicative) ; `envoi_compteur_espace_reserver` rend faux à la 301e place même avec un plafond demandé de 1 000 ; deux connexions concurrentes au même instant n'obtiennent jamais plus de places que le plafond ; le rôle applicatif ne peut pas écrire `envoi_compteur_espace` directement ; `adaptateur` vaut `faux` pour tout domaine existant ; une ligne d'essai ou d'import contenant `@` est refusée.
- **Acceptation** : filet NR vert après régénération voulue (« Filet modifié : pourquoi » dans la PR) ; aucune donnée existante modifiée.
- **Dépendances** : aucune. **Audit** : **oui** (RLS, `SECURITY DEFINER`, suppression encadrée des tentatives).

#### K14-DOC — Fiche des inscriptions corrigée
- **Objet** : fiche pas à pas complète (Postmaster, SNDS/JMRP, Yahoo CFL, Orange, Signal Spam) : préciser que la CFL est **Yahoo**, qu'Orange n'a qu'un contact délivrabilité, l'ordre (Yahoo et JMRP avant le premier envoi, Postmaster et SNDS avant le palier 2), et quel point du feu vert chaque inscription coche (codes de D13).
- **Fichiers** : M `docs/envoi/fiche-inscriptions-reputation.md` ; C `docs/envoi/lots/K14-DOC.md`.
- **Tests** : aucun (documentation) ; relecture : aucun lien, domaine, IP ni compte réels.
- **Dépendances** : aucune. **Audit** : non.

#### R06-D — Dettes R06 : code SMTP, tests « attente » et « refusée »
- **Objet** : `ResultatInjection` porte un `?int $codeSmtp` (facultatif, 200 à 599) ; `RefusDefinitifServeur` porte le code ; `PasserelleLocale` le transmet à `DepotTentatives::noter` ; `FauxServeurEnvoi` peut rendre un code (scénarios de test).
- **Fichiers** : M `backend/app/Envoi/Contrat/ResultatInjection.php`, `backend/app/Envoi/Exceptions/RefusDefinitifServeur.php`, `backend/app/Envoi/PasserelleLocale.php`, `backend/app/Envoi/Depot/DepotTentatives.php`, `backend/app/Envoi/Simulateur/FauxServeurEnvoi.php` ; M `backend/tests/Feature/Envoi/LecteurReportsDbTest.php` (retrait de `$serveur` et `$p`, inutilisés dans le test « annulation… » ; assertion inchangée) ; C `backend/tests/Feature/Envoi/TentativesCodeSmtpTest.php` ; C `docs/envoi/lots/R06-D.md`.
- **Tests rouges exigés** : un report **d'attente** local (`ResultatInjection::reporte`, plafond ou pause) n'écrit aucune tentative ; un refus définitif 550 écrit une ligne `refusee` avec `code_smtp = 550` ; un report d'échec 421 écrit `reportee` + 421 ; un code hors 200 à 599 est refusé à la construction.
- **Acceptation** : les 8 tests existants de `LecteurReportsDbTest` restent verts et inchangés dans leurs assertions ; PHPStan sans nouvelle erreur.
- **Dépendances** : aucune. **Audit** : non (aucune donnée de personne, aucune route).

#### G4-API — Statut d'opposition lisible par le CRM
- **Objet** : `StatutOpposition` (D14), lecture par lots (une requête par source, jamais N+1), exposé dans la liste et la fiche des personnes et dans la liste des contacts.
- **Fichiers** : C `backend/app/Crm/Opposition/StatutOpposition.php` ; M `backend/app/Http/Controllers/Api/Crm/PersonnesController.php` ; M `backend/app/Http/Controllers/Api/ContactsController.php` ; C `backend/tests/Feature/Crm/StatutOppositionTest.php` ; C `docs/envoi/lots/G4-API.md`.
- **Tests rouges exigés** : une adresse STOP, une opposition art. 21, une adresse du registre, un domaine du registre (et un sous-domaine) ⇒ `opposee: true` avec le bon motif ; une adresse propre ⇒ `false` ; un autre espace ne voit rien ; nombre de requêtes identique pour 1 et 50 lignes ; la réponse ne contient ni empreinte, ni date, ni contenu de registre ; aucune écriture (compteur de requêtes d'écriture = 0) ; aucun événement site-sync émis.
- **Dépendances** : aucune. **Audit** : **oui** (données de personnes).

### Vague 2

#### S2-SOCLE — Contrat du socle (arrêt général, reprise locale, notifications)
- **Objet** : D1 et D2 côté contrat, démo et écran.
- **Fichiers** : M `backend/openapi/envoi/socle.yaml` ; M `frontend/src/features/envoi/api/socle.d.ts` (régénéré par `pnpm api:envoi`) ; M `backend/tests/Support/Couverture/trous/socle.php` (`tout-arreter` et `reprendre` retirés, `arret-general` ajouté) ; M `backend/tests/Support/Couverture/trous/notifications.php` (vidé : le chemin est servi) ; M `frontend/src/features/socle/ToutArreter.tsx` ; M `frontend/src/demo/handlers/socle/envoi.handlers.ts`, `frontend/src/demo/handlers/socle/notifications.handlers.ts` ; M `frontend/tests/components/tout-arreter.test.tsx`, `frontend/tests/lib/demo-handlers-charges.test.ts`, `frontend/tests/msw/handlers.ts`, `frontend/tests/e2e/demo/socle-demo.spec.ts` ; C `docs/envoi/lots/S2-SOCLE.md`.
- **Tests rouges exigés** : `tout-arreter.test.tsx` : confirmation ⇒ `POST /api/v1/envoi/arret-general` ; le bandeau n'a **aucun** bouton de reprise et affiche la commande `envoi:reprendre` ; aucun appel à `/envoi/reprendre`. Contrat : plus aucun chemin `tout-arreter` ni `reprendre` dans `socle.yaml`.
- **Acceptation** : `CouvertureContratEnvoiTest` vert (trous : `arret-general`, `etat`, `compteurs`) ; garde de démo verte.
- **Dépendances** : S0. **Audit** : **oui** (sécurité : geste d'arrêt).

#### S2-CONTRATS — Contrats conformité, pilotage, infrastructure
- **Objet** : `POST /envoi/opposition/import` (simulation puis confirmation, D8) ; `POST /envoi/feu-vert/decision` et champ facultatif `decision` dans la réponse de `GET /envoi/feu-vert` (D12) ; champ `adaptateur` en lecture dans le domaine (D11) ; schémas communs ; description du feu vert sans nombre de points figé (« lu dans `version` »).
- **Fichiers** : M `backend/openapi/envoi/conformite.yaml`, `pilotage.yaml`, `infrastructure.yaml`, `commun.yaml` ; M `frontend/src/features/envoi/api/conformite.d.ts`, `pilotage.d.ts`, `infrastructure.d.ts`, `commun.d.ts` ; C `backend/tests/Support/Couverture/trous/conformite.php`, `backend/tests/Support/Couverture/trous/pilotage.php` ; M `frontend/src/demo/handlers/envoi/conformite.handlers.ts`, `pilotage.handlers.ts`, `infrastructure.handlers.ts` ; C `docs/envoi/lots/S2-CONTRATS.md`.
- **Tests rouges exigés** : les deux nouveaux chemins apparaissent comme trous déclarés (sinon `CouvertureContratEnvoiTest` rougit) ; `ContratOpenApiEnvoi` valide toujours les réponses actuelles de `/envoi/feu-vert` et `/envoi/domaines` (champs ajoutés **facultatifs**) ; handlers de démo conformes aux nouveaux schémas.
- **Dépendances** : S0. **Audit** : non (contrat seul ; les PR qui servent ces chemins sont auditées).

#### S2-ROUTEUR — Routes d'écran et pages d'attente
- **Objet** : routes `/envoi/sequences`, `/envoi/sequences/$sequenceId`, `/envoi/sante/inscriptions` (garde `exigerProduit('envoi')`), onglets « Séquences » (campagnes) et « Inscriptions » (santé), pages d'attente (`PageEnAttente`).
- **Fichiers** : M `frontend/src/app/routeTree.tsx`, `frontend/src/features/envoi/pilotage/OngletsSante.tsx`, `frontend/src/features/envoi/campagnes/OngletsCampagnes.tsx`, `frontend/tests/components/routes-envoi-gardees.test.tsx` ; C `frontend/src/features/envoi/sequences/SequencesPage.tsx`, `frontend/src/features/envoi/sequences/SequencePage.tsx`, `frontend/src/features/envoi/pilotage/InscriptionsPage.tsx` ; C `docs/envoi/lots/S2-ROUTEUR.md`.
- **Tests rouges exigés** : les trois routes sont gardées par le produit (404 d'écran sans produit Envoi) ; les onglets mènent aux bonnes routes.
- **Dépendances** : aucune (vague 2 pour ne pas croiser S2-SOCLE). **Audit** : non.

#### T-BOITES — Trous des boîtes (7)
- **Objet** : D3.
- **Fichiers** : M `backend/routes/envoi/boites.php` ; C `backend/app/Http/Controllers/Api/EnvoiBoitesController.php` ; M `backend/app/Envoi/Smtp/DepotBoites.php` (lecture paginée, suspension manuelle distinguée de la pause automatique) ; M `backend/tests/Support/Couverture/trous/boites.php` (vidé) ; C `backend/tests/Support/Couverture/non-servis/boites.php` ; C `backend/tests/Feature/Envoi/Routes/instantane-routes-envoi/boites.json` ; C `backend/tests/Feature/Envoi/Routes/BoitesRoutesTest.php` ; C `frontend/tests/screens/envoi/BoitesPalier0.test.tsx` (scénarios C1 à C3 de la section 7, sans toucher l'écran) ; C `docs/envoi/lots/T-BOITES.md`.
- **Tests rouges exigés** : liste et fiche conformes au contrat, **sans** secret, mot de passe ni utilisateur SMTP ; `POST /envoi/boites` et `test-connexion` ⇒ 501 `serveur_reel_requis`, aucune ligne écrite ; `reactiver` d'une boîte en pause automatique ⇒ 409 ; `suspendre` idempotent ; `PUT reglages` au-dessus du budget de chauffe ⇒ 422 ; permissions (lecture `users.manage`, écriture `workspaces.manage`) ; autre espace ⇒ 404 ; aucun nom d'action ne contient `reprendre`.
- **Dépendances** : S0. **Audit** : **oui** (sécurité : secrets de boîtes).

#### T-CHAUFFE — Trous de la chauffe (2)
- **Fichiers** : M `backend/routes/envoi/chauffe.php` ; C `backend/app/Http/Controllers/Api/EnvoiChauffeController.php` ; M `backend/tests/Support/Couverture/trous/chauffe.php` (vidé) ; C `backend/tests/Feature/Envoi/Routes/instantane-routes-envoi/chauffe.json` ; C `backend/tests/Feature/Envoi/Routes/ChauffeRoutesTest.php` ; C `docs/envoi/lots/T-CHAUFFE.md`.
- **Tests rouges exigés** : `GET` conforme au contrat (budget du jour, goulot, paliers) et sans résolution de `ServeurEnvoi` ; `reculer` écrit un recul (table existante `envoi_chauffe_reculs`), deuxième appel le même jour sans nouvel effet ; propriétaire seul ; ressource d'un autre espace ⇒ 404 ; aucun geste ne fait **monter** un palier.
- **Dépendances** : S0. **Audit** : non (lecture de compteurs, geste dans le sens sûr).

#### T-SEQ — Trous des séquences (4)
- **Objet** : D6.
- **Fichiers** : M `backend/routes/envoi/sequences.php` ; C `backend/app/Http/Controllers/Api/EnvoiSequencesController.php` ; C `backend/app/Envoi/Sequence/DepotSequences.php` ; M `backend/app/Envoi/Sequence/Sequenceur.php` (filtre des séquences en pause dans `executer`) ; M `backend/tests/Support/Couverture/trous/sequences.php` (vidé) ; C `backend/tests/Feature/Envoi/Routes/instantane-routes-envoi/sequences.json` ; C `backend/tests/Feature/Envoi/Routes/SequencesRoutesTest.php` ; C `backend/tests/Feature/Envoi/SequencePauseSequenceurTest.php` ; C `docs/envoi/lots/T-SEQ.md`.
- **Tests rouges exigés** : liste et fiche conformes (compteurs seulement, aucune personne) ; une séquence en pause n'est pas traitée et ses inscriptions ne voient **pas** leur compteur de reports augmenter ; `reprise` ⇒ 409 sous arrêt général, sous pause automatique, sous frein ; reprise permise sinon ; permissions du contrat ; aucune action nommée `reprendre`.
- **Dépendances** : S0, S1. **Audit** : **oui** (sécurité : reprise).

#### T-SANTE — `POST /envoi/sante/test`
- **Fichiers** : M `backend/routes/envoi/sante.php` ; M `backend/app/Http/Controllers/Api/EnvoiSanteController.php` ; M `backend/tests/Support/Couverture/trous/sante.php` (vidé) ; C `backend/tests/Support/Couverture/non-servis/sante.php` ; M `backend/tests/Feature/Envoi/Routes/instantane-routes-envoi/sante.json` ; C `docs/envoi/lots/T-SANTE.md`.
- **Tests rouges exigés** : `NonServiAuPalier0Test` appelle la route et obtient 409 `envoi_ferme_palier_0` ; rien n'est écrit ; `ServeurEnvoi` jamais résolu.
- **Dépendances** : S0. **Audit** : non.

#### P1-PLAFOND — Butée en base de 300 par jour et par espace
- **Objet** : D7. `DepotSocleDb::reserverInjection` et `noterInjection` appellent `envoi_compteur_espace_reserver` dans la même transaction que la réservation ; refus ⇒ report au lendemain (même chemin que le plafond PHP) ; `DepotSocleDb::plafondEspace` lit le réglage R14 ; `PasserelleLocale` prend le minimum.
- **Fichiers** : M `backend/app/Envoi/Depot/DepotSocle.php`, `backend/app/Envoi/Depot/DepotSocleDb.php`, `backend/app/Envoi/PasserelleLocale.php` ; C `backend/tests/Feature/Envoi/PlafondButeeBaseTest.php`, `backend/tests/Feature/Envoi/PlafondButeeConcurrenceTest.php` ; C `docs/envoi/lots/P1-PLAFOND.md`.
- **Tests rouges exigés** : avec le contrôle PHP volontairement contourné (dépôt espion qui répond « 0 injecté »), la base refuse la 301e réservation ; réglage d'espace à 12 ⇒ 13e message reporté ; 8 processus réels en parallèle sur 300 places pré-remplies à 295 ⇒ exactement 5 succès ; une réservation annulée (transaction défaite) ne consomme pas de place ; deux espaces ne se partagent pas leur compteur.
- **Acceptation** : `PlafondConcurrenceTest` existant vert et inchangé.
- **Dépendances** : S1, R06-D (même fichier, vague précédente). **Audit** : **oui** (sécurité).

#### K14-CAT — Catalogue v4 (Yahoo, libellé Orange)
- **Fichiers** : M `backend/app/Envoi/FeuVert/CatalogueManuels.php` ; M `backend/tests/Feature/Envoi/FeuVertListeTest.php` (attendu 45 points, v4) ; C `backend/tests/Feature/Envoi/FeuVertCatalogueV4Test.php` ; C `docs/envoi/lots/K14-CAT.md`.
- **Tests rouges exigés** : `manuel.yahoo_cfl` existe (source « 30 A11 », `serveur_reel` vrai) ; libellé Orange sans « CFL » ; une preuve déjà posée sur `manuel.orange_cfl` reste lue « fait ».
- **Dépendances** : aucune. **Audit** : non.

#### G4-ENVOI — Une adresse opposée n'est jamais sélectionnable pour un envoi
- **Fichiers** : M `backend/app/Envoi/Creation/AssistantCreation.php` (`estimer` compte les opposées en `exclus`, `destinatairesExemples` les écarte, tout identifiant opposé passé explicitement ⇒ 422 `adresse_opposee`) ; C `backend/tests/Feature/Envoi/CreationOpposeesTest.php` ; C `docs/envoi/lots/G4-ENVOI.md`.
- **Tests rouges exigés** : estimation avec 3 opposées sur 10 ⇒ `exclus = 3`, `exclusions_evaluees = true` pour ce motif ; exemples sans opposée ; 422 si forcée ; la réponse ne dit pas **pourquoi** (aucun motif détaillé par personne).
- **Dépendances** : G4-API. **Audit** : **oui** (données de personnes).

#### G4-UI — Badge « opposée » dans le CRM et les écrans d'envoi
- **Fichiers** : C `frontend/src/features/crm-console/BadgeOpposee.tsx` ; M `frontend/src/features/contacts/ContactsListPage.tsx`, `frontend/src/features/crm-console/PersonnesPage.tsx`, `frontend/src/features/crm-console/PersonneDetailPage.tsx`, `frontend/src/features/crm-console/types.ts`, `frontend/src/features/envoi/conformite/DestinatairesPage.tsx`, `frontend/src/features/envoi/campagnes/composants/TableauDestinataires.tsx` ; M `frontend/src/demo/handlers/crm/contacts.handlers.ts` ; C `frontend/tests/screens/BadgeOpposee.test.tsx` ; C `frontend/tests/e2e/demo/dm3-crm-opposee.spec.ts` ; C `docs/envoi/lots/G4-UI.md`.
- **Tests rouges exigés** : badge visible (texte « opposée », pas seulement une couleur) dans chaque écran listé ; infobulle avec le motif regroupé ; dans les écrans d'envoi, la case de sélection est désactivée et annoncée (`aria-disabled`, raison lue par le lecteur d'écran).
- **Dépendances** : G4-API. **Audit** : **oui** (données de personnes).

### Vague 3

#### T-SOCLE — `GET /envoi/etat`, `GET /envoi/compteurs`, `POST /envoi/arret-general`
- **Fichiers** : M `backend/routes/envoi/socle.php` ; C `backend/app/Http/Controllers/Api/EnvoiSocleController.php` (méthodes `etat`, `compteurs`, `arretGeneral`) ; M `backend/tests/Support/Couverture/trous/socle.php` (vidé) ; C `backend/tests/Feature/Envoi/Routes/instantane-routes-envoi/etat.json`, `compteurs.json`, `arret-general.json` ; C `backend/tests/Feature/Envoi/Routes/SocleRoutesTest.php` ; C `docs/envoi/lots/T-SOCLE.md`.
- **Tests rouges exigés** : `etat` et `compteurs` conformes et lus en base (aucune résolution de `ServeurEnvoi`) ; `arret-general` arme le drapeau de **cet** espace seulement (un autre espace reste ouvert), est journalisé, idempotent ; une injection suivante est refusée (`EnvoiRefuse::toutArreter`) ; opérateur et lecteur ⇒ 403 ; aucune route ne permet de lever l'arrêt ; les gardes `AucuneRoute…` restent vertes.
- **Dépendances** : S2-SOCLE. **Audit** : **oui** (sécurité).

#### P1-LN — Listes de refus : import et contrôle avant chaque envoi
- **Objet** : D8.
- **Fichiers** : M `backend/routes/envoi/conformite.php` ; C `backend/app/Http/Controllers/Api/EnvoiOppositionImportController.php` ; C `backend/app/Envoi/Conformite/ImportOppositions.php` ; M `backend/app/Envoi/Conformite/RegistreOppositionManuel.php` (écriture d'un lot, `import_id`) ; M `backend/app/Envoi/Depot/DepotSocleDb.php` (`adresseOpposee` lit le registre : adresse, domaine, sous-domaines) ; M `backend/app/Providers/Envoi/OppositionsImportServiceProvider.php` ; M `backend/tests/Support/Couverture/trous/conformite.php` (vidé) ; M `backend/tests/Feature/Envoi/Routes/instantane-routes-envoi/opposition.json` ; C `backend/tests/Feature/Envoi/OppositionImportTest.php`, `backend/tests/Feature/Envoi/OppositionAuMomentDeLInjectionTest.php` ; C `docs/envoi/lots/P1-LN.md`.
- **Tests rouges exigés** : simulation sans écriture ; confirmation avec une autre empreinte ⇒ 409 ; 5 001 lignes ou plus de 1 Mo ⇒ 422 ; domaine grand public ⇒ ligne refusée ; doublons ⇒ aucune ligne en double ; la réponse ne contient aucune adresse ; une opposition ajoutée **après** le temps A du séquenceur et avant l'injection ⇒ le message ne part pas (refus sous le verrou) ; un domaine du registre bloque ses sous-domaines ; propriétaire seul ; journal d'import sans `@`.
- **Dépendances** : S1, S2-CONTRATS, P1-PLAFOND (même fichier `DepotSocleDb.php`, vague précédente). **Audit** : **oui** (données de personnes et sécurité).

#### L8-ORCH — Chef d'orchestre
- **Objet** : D9.
- **Fichiers** : C `backend/app/Envoi/Orchestration/ChefOrchestre.php` ; C `backend/app/Console/Commands/EnvoiCycle.php` ; M `backend/app/Providers/Envoi/OrchestrationServiceProvider.php` (liaison de `Sequenceur` et de ses dépendances) ; M `backend/routes/console/envoi-orchestration.php` (`envoi:cycle` chaque minute, `envoi:perimer-reservations` incluse dans le cycle) ; M `backend/tests/Feature/NonRegression/instantanes/nr07-taches-planifiees.json`, `nr08-taches-sous-rls.json` ; C `backend/tests/Feature/Envoi/ChefOrchestreTest.php`, `backend/tests/Feature/Envoi/ChefOrchestrePlanifieTest.php` ; C `docs/envoi/lots/L8-ORCH.md`.
- **Tests rouges exigés** : `ENVOI_REEL_ACTIF=false` ⇒ la commande sort sans appeler le séquenceur ni écrire une échéance ; la tâche planifiée n'est pas due quand le drapeau est faux ; avec la porte ouverte **dans le test** et `FauxServeurEnvoi` : deux espaces traités chacun sous son contexte, un espace sans produit Envoi ou sous arrêt général sauté ; une exception dans un espace n'arrête pas les autres ; bilan journalisé sans adresse ; deux exécutions simultanées ⇒ une seule travaille (`withoutOverlapping`).
- **Dépendances** : S0, T-SEQ. **Audit** : **oui** (sécurité).

#### K17-FV — Feu vert final (serveur)
- **Objet** : D12.
- **Fichiers** : C `backend/app/Envoi/FeuVert/DecisionFeuVert.php` ; M `backend/app/Envoi/FeuVert/CatalogueManuels.php` (v5 : trois points `auto.*`) ; M `backend/app/Envoi/FeuVert/ListeFeuVert.php` (calcul des trois points, `empreinte_liste`, `decision`) ; M `backend/app/Http/Controllers/Api/EnvoiFeuVertController.php` ; M `backend/routes/envoi/feu-vert.php` ; M `backend/app/Providers/Envoi/FeuVertDecisionServiceProvider.php` ; M `backend/tests/Support/Couverture/trous/pilotage.php` (vidé) ; M `backend/tests/Feature/Envoi/FeuVertListeTest.php` (48 points, v5) ; M `backend/tests/Feature/Envoi/Routes/instantane-routes-envoi/feu-vert.json` ; C `backend/tests/Feature/Envoi/FeuVertDecisionTest.php` ; C `docs/envoi/lots/K17-FV.md`.
- **Tests rouges exigés** : « accorde » avec un bloquant rouge ⇒ 409 ; avec une empreinte périmée ⇒ 412 ; « retire » toujours accepté ; décision consignée en ajout seul avec la photo des points ; administrateur ⇒ 403 ; la décision ne modifie **aucune** configuration (`config('envoi.reel_actif')` reste faux) ; points `auto.*` calculés : essai à blanc réussi de moins de 7 jours (sinon rouge), butée présente, chaque domaine non `faux` a un DNS vert du jour.
- **Dépendances** : S1, S2-CONTRATS, K14-CAT. **Audit** : **oui** (sécurité).

#### K14-ECRAN — Écran « Inscriptions » (lecture seule)
- **Fichiers** : M `frontend/src/features/envoi/pilotage/InscriptionsPage.tsx` ; C `frontend/src/features/envoi/pilotage/inscriptionsLibelles.ts` ; C `frontend/tests/screens/envoi/InscriptionsPage.test.tsx` ; C `frontend/tests/e2e/demo/dm3-inscriptions.spec.ts` ; C `docs/envoi/lots/K14-ECRAN.md`.
- **Tests rouges exigés** : une ligne par service (Postmaster, SNDS/JMRP, Yahoo, Orange, Signal Spam) avec état fait/à faire/non évalué tiré du feu vert, dernier constat de réputation ; aucun bouton d'écriture ; états chargement, vide, panne ; renvoi vers la fiche K14 par son titre (pas de lien externe).
- **Dépendances** : S2-ROUTEUR, K14-CAT. **Audit** : non.

#### F-SEQ — Écrans des séquences
- **Fichiers** : M `frontend/src/features/envoi/sequences/SequencesPage.tsx`, `SequencePage.tsx` ; C `frontend/src/features/envoi/sequences/api.ts` ; M `frontend/src/demo/handlers/envoi/sequences.handlers.ts` ; C `frontend/tests/screens/envoi/SequencesPage.test.tsx` ; C `frontend/tests/e2e/demo/dm3-sequences.spec.ts` ; C `docs/envoi/lots/F-SEQ.md`.
- **Tests rouges exigés** : liste, fiche (étapes, compteurs), « Mettre en pause » (confirmation), « Reprendre » refusé avec la raison en clair sur 409 ; boutons masqués selon la permission.
- **Dépendances** : S2-ROUTEUR, T-SEQ. **Audit** : non (aucune personne affichée).

#### G4-ACCORD — Écran « accord donné »
- **Fichiers** : M `frontend/src/features/crm-console/ContenusAccordsCard.tsx`, `frontend/src/features/crm-console/libelleAccord.ts` ; C `frontend/tests/screens/AccordDonne.test.tsx` ; C `frontend/tests/e2e/demo/dm3-accord.spec.ts` ; C `docs/envoi/lots/G4-ACCORD.md`.
- **Tests rouges exigés** : origine, date et preuve affichées ; une réponse de test contenant `commentaire_ref`, `post_ref` ou `post_url` ne les fait **jamais** apparaître dans le DOM ; sans accord : « Aucun accord enregistré ».
- **Dépendances** : G4-UI (même écran de fiche, vague précédente). **Audit** : **oui** (données de personnes).

### Vague 4

#### L8-ESSAI — Essai à blanc de bout en bout
- **Objet** : D10.
- **Fichiers** : C `backend/app/Envoi/Orchestration/EssaiABlanc.php`, `backend/app/Envoi/Orchestration/RapportEssai.php` ; C `backend/app/Console/Commands/EnvoiEssaiABlanc.php` ; M `backend/app/Providers/Envoi/OrchestrationServiceProvider.php` ; C `backend/tests/Feature/Envoi/EssaiABlancTest.php`, `backend/tests/Feature/Envoi/EssaiABlancSansReseauTest.php` ; C `docs/envoi/lots/L8-ESSAI.md`.
- **Tests rouges exigés** : le parcours complet passe (inscription, composition conforme, injection sur le faux serveur, rebond dur ⇒ adresse arrêtée, STOP relu par la relève ⇒ étape 2 jamais injectée, plafond respecté, arrêt général respecté) ; après l'essai, **aucune** ligne nouvelle hors `envoi_essais_blanc` (comptage de toutes les tables `envoi_*`, `contacts`, `personnes`, `email_suppressions`) ; aucune requête HTTP, aucun socket ouvert (garde de flux remplacée par une espionne qui rougit au premier appel) ; `ServeurEnvoi` du conteneur jamais résolu ; `config('envoi.reel_actif')` toujours faux avant et après ; une étape rouge ⇒ rapport rouge et code de sortie non nul.
- **Dépendances** : L8-ORCH, P1-LN, P1-PLAFOND. **Audit** : **oui** (sécurité : porte ouverte localement).

#### K17-AIG — Adaptateur choisi par domaine
- **Objet** : D11.
- **Fichiers** : C `backend/app/Envoi/Serveur/AiguilleurServeurEnvoi.php` ; C `backend/app/Console/Commands/EnvoiDomaineAdaptateur.php` ; M `backend/app/Providers/Envoi/AiguillageServiceProvider.php` (relie `ServeurEnvoi` à `PasserelleLocale(AiguilleurServeurEnvoi)` ; `FauxServeurEnvoi` reste le seul adaptateur construit tant que la porte est fermée) ; M `backend/app/Envoi/Infrastructure/LectureInfrastructure.php` (champ `adaptateur` en lecture) ; C `backend/tests/Feature/Envoi/AiguilleurServeurEnvoiTest.php` ; C `docs/envoi/lots/K17-AIG.md`.
- **Tests rouges exigés** : domaine `faux` + porte fermée (tests) ⇒ faux serveur ; domaine `kumo` + porte fermée ⇒ refus, **aucun** constructeur Kumo ou SMTP appelé ; porte ouverte mais feu vert non accordé ⇒ refus ; porte ouverte, feu vert accordé, DNS non vert ⇒ refus ; porte ouverte et domaine `faux` ⇒ refus `domaine_sur_faux_serveur` ; aucun repli silencieux ; la commande refuse une valeur hors liste et journalise ; aucune route ne change l'adaptateur.
- **Dépendances** : S1, K17-FV. **Audit** : **oui** (sécurité).

#### R06-ARCH — Conservation des tentatives
- **Objet** : D16.
- **Fichiers** : C `backend/app/Envoi/Depot/RetentionTentatives.php` ; C `backend/app/Console/Commands/EnvoiTentativesArchiver.php` ; M `backend/app/Providers/Envoi/RetentionServiceProvider.php` ; M `backend/routes/console/envoi-retention.php` (quotidien 04:50, `onOneServer`) ; M `backend/tests/Feature/NonRegression/instantanes/nr07-taches-planifiees.json`, `nr08-taches-sous-rls.json` ; C `backend/tests/Feature/Envoi/RetentionTentativesTest.php` ; C `docs/envoi/lots/R06-ARCH.md`.
- **Tests rouges exigés** : une ligne de 401 jours est agrégée puis retirée, une de 399 jours reste ; totaux agrégés = totaux détaillés d'avant ; rejeu sans double comptage ; un `DELETE` direct, même du propriétaire de la base hors fonction, est refusé ; aucune autre table touchée (comptage avant/après de `envoi_oppositions*`, `email_suppressions`, `contacts`, `personnes`) ; `LecteurReportsDb` inchangé sur l'heure glissante.
- **Dépendances** : S1, L8-ORCH (fichiers NR, vague précédente). **Audit** : **oui** (données et sécurité).

#### F-FV — Écran du feu vert final
- **Fichiers** : M `frontend/src/features/envoi/pilotage/FeuVertPage.tsx`, `frontend/src/features/envoi/pilotage/feuVertLibelles.ts` ; C `frontend/src/features/envoi/pilotage/composants/DecisionFeuVert.tsx` ; M `frontend/tests/screens/envoi/FeuVertPage.test.tsx` (cas ajoutés, aucun retiré) ; C `frontend/tests/e2e/demo/dm3-feu-vert.spec.ts` ; C `docs/envoi/lots/F-FV.md`.
- **Tests rouges exigés** : chaque point en vert ou rouge avec sa raison ; « Accorder le feu vert » désactivé tant qu'un bloquant est rouge, motif obligatoire, confirmation explicite, `If-Match` envoyé ; « Retirer » toujours possible ; l'écran dit que l'accord n'active pas l'envoi réel ; historique des décisions en lecture.
- **Dépendances** : K17-FV. **Audit** : non (écran ; la sécurité est au serveur, auditée en K17-FV).

#### F-LN — Écran d'import des listes de refus
- **Fichiers** : C `frontend/src/features/envoi/conformite/composants/ImportListeRefus.tsx` ; M `frontend/src/features/envoi/conformite/DesinscriptionsPage.tsx` ; C `frontend/tests/screens/envoi/ImportListeRefus.test.tsx` ; C `frontend/tests/e2e/demo/dm3-listes-refus.spec.ts` ; C `docs/envoi/lots/F-LN.md`.
- **Tests rouges exigés** : simulation puis confirmation (deux étapes visibles) ; compte rendu chiffré et numéros de lignes refusées, aucune adresse réaffichée ; fichier trop gros refusé avant envoi ; propriétaire seul voit le bouton.
- **Dépendances** : P1-LN, S2-CONTRATS. **Audit** : **oui** (données de personnes).

### Vague 5

#### P1-REPRISE — Reprise après panne, preuve par arrêt brutal
- **Objet** : prouver qu'un `envoi:cycle` tué à n'importe quel moment reprend sans doublon ni perte.
- **Fichiers** : C `backend/tests/Feature/Envoi/RepriseApresPanneTest.php` ; M (seulement si le test rouge révèle un défaut, et alors nommé dans la PR) `backend/app/Envoi/Sequence/Sequenceur.php`, `backend/app/Envoi/Orchestration/ChefOrchestre.php` ; C `docs/envoi/lots/P1-REPRISE.md`.
- **Tests rouges exigés** : processus réels (même méthode que `PlafondConcurrenceTest`) tués par `SIGKILL` à quatre points injectés (après le temps A, pendant l'appel, après l'acceptation du faux serveur, pendant le temps C), puis relance : chaque message accepté est conclu **une** fois, aucun n'est injecté deux fois (registre du faux serveur persistant en base de test), un message jamais réservé part au cycle suivant, une issue incertaine arrête l'inscription avec une alerte ; aucun dépassement du plafond.
- **Dépendances** : L8-ORCH, K17-AIG, P1-PLAFOND. **Audit** : **oui** (sécurité).

#### E2E-FIN — Parcours complet par l'interface (démo)
- **Fichiers** : C `frontend/tests/e2e/demo/dm3-parcours-envoi.spec.ts` ; M `frontend/src/demo/handlers/envoi/campagnes.handlers.ts` (états de parcours uniquement) ; C `docs/envoi/lots/E2E-FIN.md`.
- **Tests rouges exigés** : voir la section 7, scénario P.
- **Dépendances** : toutes les PR d'écran. **Audit** : non.

---

## 6. Tableau de synthèse

| ID | Vague | Lignes estimées | Migration | Personnes | Sécurité | Audit Opus sécurité + données |
|---|---|---|---|---|---|---|
| S0 | 1 | 300 | — | non | non | non |
| S1 | 1 | 400 | 000010 à 000070 | non | oui | **oui** |
| K14-DOC | 1 | 80 | — | non | non | non |
| R06-D | 1 | 200 | — | non | non | non |
| G4-API | 1 | 300 | — | **oui** | oui | **oui** |
| S2-SOCLE | 2 | 350 | — | non | oui | **oui** |
| S2-CONTRATS | 2 | 400 | — | non | non | non |
| S2-ROUTEUR | 2 | 150 | — | non | non | non |
| T-BOITES | 2 | 400 | — | non | oui | **oui** |
| T-CHAUFFE | 2 | 250 | — | non | non | non |
| T-SEQ | 2 | 400 | — | non | oui | **oui** |
| T-SANTE | 2 | 80 | — | non | non | non |
| P1-PLAFOND | 2 | 350 | — | non | oui | **oui** |
| K14-CAT | 2 | 100 | — | non | non | non |
| G4-ENVOI | 2 | 200 | — | **oui** | oui | **oui** |
| G4-UI | 2 | 350 | — | **oui** | non | **oui** |
| T-SOCLE | 3 | 300 | — | non | oui | **oui** |
| P1-LN | 3 | 400 | — | **oui** | oui | **oui** |
| L8-ORCH | 3 | 350 | — | non | oui | **oui** |
| K17-FV | 3 | 400 | — | non | oui | **oui** |
| K14-ECRAN | 3 | 250 | — | non | non | non |
| F-SEQ | 3 | 350 | — | non | non | non |
| G4-ACCORD | 3 | 200 | — | **oui** | non | **oui** |
| L8-ESSAI | 4 | 400 | — | non (données fictives) | oui | **oui** |
| K17-AIG | 4 | 350 | — | non | oui | **oui** |
| R06-ARCH | 4 | 300 | — | non | oui | **oui** |
| F-FV | 4 | 300 | — | non | non | non |
| F-LN | 4 | 250 | — | **oui** | non | **oui** |
| P1-REPRISE | 5 | 300 | — | non | oui | **oui** |
| E2E-FIN | 5 | 200 | — | non | non | non |

Une PR qui dépasse 400 lignes se coupe en deux **dans la même vague** seulement si les deux moitiés n'ont aucun fichier commun ; sinon la seconde moitié passe à la vague suivante.

---

## 7. Tests par l'interface

Playwright est en place : les suites `frontend/tests/e2e/demo/*.spec.ts` sont toutes jouées par `.github/workflows/demo.yml` sur le build de démo (Chromium, données MSW, aucun serveur réel, aucun réseau sortant). Chaque scénario finit par `controleAxe(page)` (0 violation critique) et `passage360(page)` (360 px, pas de défilement horizontal), comme `dm2-inf.spec.ts`. En plus, chaque écran a son test de composant Vitest (`frontend/tests/screens/envoi/`) qui couvre chargement, vide, panne, 403, 409 et 501.

| Fichier (PR) | Scénarios |
|---|---|
| `socle-demo.spec.ts` (S2-SOCLE) | A1 « Tout arrêter » en 2 clics (bouton, confirmation) ⇒ bandeau rouge ; A2 le bandeau n'a pas de bouton « Reprendre » et montre la commande locale ; A3 le bandeau survit au rechargement. |
| `dm3-sequences.spec.ts` (F-SEQ) | B1 liste puis fiche d'une séquence (étapes, compteurs) ; B2 pause avec confirmation ⇒ statut « en pause » ; B3 reprise sous frein ⇒ message de refus en clair, statut inchangé ; B4 compte lecteur : aucun bouton de geste. |
| `BoitesPalier0.test.tsx` (T-BOITES, test de composant ; l'écran n'est pas modifié) | C1 « Ajouter une boîte » et « Tester la connexion » ⇒ message 501 « disponible quand le serveur d'envoi existera » ; C2 suspendre puis réactiver une boîte suspendue à la main ; C3 réactiver une boîte en pause automatique ⇒ refus expliqué. Ces trois cas sont écrits par T-BOITES dans le nouveau fichier `frontend/tests/screens/envoi/BoitesPalier0.test.tsx`. |
| `dm2-inf.spec.ts` (inchangé) | D1 chauffe : reculer d'un palier avec confirmation ; aucun bouton pour monter. |
| `SanteR18.test.tsx` (inchangé) | E1 « Lancer un test » ⇒ « Envois fermés (palier 0) ». |
| `dm3-feu-vert.spec.ts` (F-FV) | F1 tous les points affichés vert ou rouge avec leur raison ; F2 « Accorder » désactivé tant qu'un bloquant est rouge ; F3 démo sans bloquant : accorder avec motif ⇒ décision visible dans l'historique, mention « n'active pas l'envoi réel » ; F4 retirer ⇒ toujours possible ; F5 rechargement : la décision reste. |
| `dm3-inscriptions.spec.ts` (K14-ECRAN) | G1 cinq services, état lu du feu vert ; G2 aucun bouton d'écriture ; G3 onglet « Inscriptions » atteignable depuis Santé. |
| `dm3-listes-refus.spec.ts` (F-LN) | H1 choisir un fichier, simuler ⇒ compte rendu (valides, déjà présentes, refusées par numéro de ligne) ; H2 confirmer ⇒ total du registre augmenté ; H3 aucune adresse du fichier réaffichée à l'écran ; H4 fichier trop gros refusé. |
| `dm3-crm-opposee.spec.ts` (G4-UI) | I1 badge « opposée » dans la liste des contacts, la liste des personnes et la fiche ; I2 dans l'écran des destinataires d'envoi, la case d'une opposée est désactivée et annoncée ; I3 tri et filtre de la liste ne font pas disparaître le badge. |
| `dm3-accord.spec.ts` (G4-ACCORD) | J1 fiche avec accord : origine, date, preuve ; J2 aucune référence de commentaire dans le DOM ; J3 fiche sans accord : « Aucun accord enregistré ». |
| `dm3-parcours-envoi.spec.ts` (E2E-FIN) | P parcours : créer une campagne (assistant), voir les opposées exclues dans l'estimation, valider, voir la séquence apparaître, mettre en pause, tenter de reprendre sous frein, consulter le feu vert (non accordé), arrêt général, constater qu'aucun écran n'annonce un message parti en réel. |

Les flux sans écran (chef d'orchestre, essai à blanc, butée, conservation, aiguilleur) sont prouvés par Pest (section 5) ; leur effet visible passe par les points du feu vert (scénario F1).

---

## 8. M3 — Séparation par client et licence : après le premier envoi

**Décision D17 : M3 n'est pas nécessaire avant le premier envoi.** Raisons :
1. Le premier envoi est celui d'**un seul client** (Axion). Les données sont déjà cloisonnées par espace : RLS forcée sur chaque table `envoi_*`, `WorkspaceContext`, produit `envoi` activé par espace (`produit-envoi`, 404 sinon), plafond par espace, arrêt général par espace.
2. Les parties **partagées** (pause globale du serveur, compteur global, réputation commune des IP et des domaines, relève IMAP) ne posent problème que lorsqu'**au moins deux** clients envoient : un client pourrait alors user le plafond global ou la réputation de l'autre.
3. La licence est une question commerciale et contractuelle (facturation, droit d'usage), sans effet sur la sûreté de l'envoi.

**M3 devient obligatoire avant que le deuxième client envoie son premier message.** Découpage proposé (même règles : branches depuis `main`, petites PR, migrations en ajouts seuls réservées le moment venu) :
- **M3-1 Client** : table `clients` (ajout seul), lien espace → client, aucun changement de comportement.
- **M3-2 Ressources par client** : domaines, IP et boîtes rattachés à un client ; refus d'envoyer depuis la ressource d'un autre client (garde dans l'aiguilleur K17).
- **M3-3 Plafond par client** : butée en base par client, à côté de celle par espace ; la somme des plafonds clients ne dépasse jamais le plafond global.
- **M3-4 Pause par client** : « Tout arrêter » et les freins s'appliquent au pool du client, plus jamais au serveur entier.
- **M3-5 Licence** : droits d'usage du produit Envoi par client (période, volume), lus par `produit-envoi` ; expiration = arrêt, jamais suppression de données.
- **M3-6 Écrans** : vue « clients » de la console, en lecture d'abord.

---

## 9. Ce que ce plan ne fait pas (et ne doit jamais faire)

- Il n'active pas l'envoi réel : `ENVOI_REEL_ACTIF` reste `false` partout ; aucune PR n'ajoute une route, une commande ou un réglage qui le change.
- Il n'achète rien (domaines, serveur, comptes des témoins) : ces achats et le premier envoi réel restent des gestes humains du fondateur, après le feu vert accordé.
- Il ne supprime aucune personne ni aucune opposition. La seule suppression prévue porte sur des lignes **techniques** de `envoi_tentatives` de plus de 400 jours, après agrégation (D16).
- Il ne touche pas l'anti-boucle site-sync.
