# 02 — REGISTRE UNIQUE DES CONSTATS

> Dédoublonné, identifiants stables, **référence : `main = c0c453d`** sauf mention contraire.
> Tenu par l'agent 4 (registrateur). Les constats des agents arrivent avec leur préfixe
> (`A05-*` bloc A, `B10-*` bloc B, `C18-*` bloc C, `D22-*` bloc D, `E31-*` bloc E,
> `F35-*` bloc F, `G41-*` bloc G, `H44-*` bloc H, `I48-*` bloc I) puis sont renumérotés
> `A-NNN` au dédoublonnage de P2.
>
> **Statuts** : ouvert · corrigé · refusé (avec raison) · reste Will.
> **Verdicts** : `Vérifié par` (rotation +17, P4) · `Réfuté par` (rotation +29, P5) · `Passe 3` (P6).

---

## Constats de P0 — trouvés pendant l'amorçage, par le chef de chantier

Le §8 P0.3 prévient : « rendre le terrain praticable — **chaque échec ici est lui-même un constat
de sévérité élevée** ». Trois sont tombés avant le premier agent.

---

### [A-001] Une route protégée répond 500 au lieu de 401 à tout client qui n'envoie pas `Accept: application/json` — en production
- **Sévérité**      : **S2** *(abaissée depuis S1 — voir la correction ci-dessous)*
- **Domaine**       : backend / exploitation
- **Référence**     : `e8924b8` — reproduit en **local**, en **préproduction** et en **PRODUCTION**
- **Emplacement**   : le mécanisme de redirection d'authentification de Laravel (`Authenticate::redirectTo`) cherche une route nommée `login`, qui n'existe pas dans une API sans interface serveur
- **Constat**       : une requête non authentifiée sur une route `auth:sanctum` produit `Route [login] not defined.` et **HTTP 500** — **mais seulement si le client n'annonce pas attendre du JSON**. Avec l'en-tête, la réponse est un **401 correct**.
- **Preuve**        :
  ```
  PRODUCTION, sans en-tete Accept :          PRODUCTION, avec Accept: application/json :
    /api/v1/crm/arbitrage    -> 500            /api/v1/crm/arbitrage    -> 401
    /api/v1/config/features  -> 500            /api/v1/config/features  -> 401
    /api/v1/contacts         -> 500            /api/v1/contacts         -> 401
  ```
  `04_PREUVES/P0/a001-recontrole.txt`, `04_PREUVES/P0/prod-401-vs-500.txt`
- **Témoin négatif** : `/up` répond **200** et `/api/v1/auth/login` répond **405** — le contrôle distingue donc « route absente », « route publique » et « route protégée ». Et la **même** requête, au **même** instant, sur la **même** route, bascule de 500 à 401 selon un seul en-tête : la variable est isolée.

- ⚠️ **CORRECTION DE MA PREMIÈRE VERSION — j'avais tort sur l'impact, et le classement était trop haut.**
  J'avais écrit que « le SPA ne peut pas distinguer *tu n'es pas connecté* de *le serveur est cassé* »
  et qu'« un visiteur déconnecté reçoit une erreur au lieu de l'écran de connexion ». **C'est faux.**
  `frontend/src/lib/api.ts:5-8` pose `headers: { Accept: 'application/json', 'X-Requested-With':
  'XMLHttpRequest' }`, et `:30-31` renvoie explicitement vers `/login` sur un 401.
  **Le SPA reçoit donc bien un 401 et sa redirection fonctionne.**
  L'écart a été levé par l'**agent 13**, qui obtenait un **401 propre** là où j'avais mesuré un 500 —
  et qui a eu la rigueur de ne pas crier à la réfutation : il a écrit *« mon appel passe par le noyau
  en processus, sans Caddy : écart de protocole, pas réfutation »*. C'était la bonne lecture, et
  c'est elle qui a fait trouver la vraie variable. Ce n'était ni Caddy, ni la route : c'était
  l'en-tête `Accept`.
  *Leçon de méthode, à retenir pour la passe adversariale : j'avais mesuré avec `curl` nu et
  généralisé à « tous les clients ». Un `curl` n'est pas un navigateur.*

- **Impact réel, après correction** : la console **n'est pas touchée**. Restent trois effets, réels mais bornés :
  1. **Tout appelant qui n'est pas le SPA** — sonde de supervision, intégration tierce, script, `curl`,
     futur usage par jeton d'API — reçoit **500** là où le contrat HTTP impose **401**. Une supervision
     branchée là-dessus signalerait une panne permanente du produit.
  2. **Chaque appel de ce type écrit une trace d'erreur complète en production**, ce qui alimente
     directement **A-007** (journal de 265 Mo, +133 Mo/jour).
  3. Le produit **ment sur son état** : un 500 annonce « le serveur est cassé » alors qu'il fonctionne.
- **Reproduction**  : `curl -o /dev/null -w "%{http_code}" https://api.axion-crm-pro.com/api/v1/contacts` (→ 500), puis la même avec `-H "Accept: application/json"` (→ 401).
- **Correctif**     : forcer la réponse JSON 401 pour **tout** ce qui est sous `/api`, indépendamment de l'en-tête — `->redirectGuestsTo(fn () => null)` dans `bootstrap/app.php`, ou l'interception de `AuthenticationException`. **Le test qui l'accompagne doit être vu rouge sur le cas sans en-tête `Accept`**, sans quoi il garderait exactement le cas qui marche déjà. Coût : ~1 h.
- **Statut**        : **ouvert**
- **Vérifié par**   : agent 13 — écart signalé, non surinterprété ; re-mesuré par le chef de chantier, qui a **corrigé son propre constat**.
- Réfuté par / Passe 3 : —

---

### [A-002] `GET /saved-views` répond 200 avec une liste vide au lieu de 501 : la route ment
- **Sévérité**      : **S2**
- **Domaine**       : backend / UX
- **Référence**     : `main c0c453d`
- **Emplacement**   : `backend/app/Http/Controllers/Api/SavedViewsController.php:10` ; route `backend/routes/api.php:195`
- **Constat**       : sur les cinq verbes de `apiResource('saved-views')`, quatre répondent 501 et **`index` répond 200 avec `{"data": []}`**.
  ```php
  public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }  // 200, liste VIDE
  public function store(...)   { return $this->notImplemented('10'); }                    // 501
  ```
- **Preuve**        : lecture du fichier + `php artisan route:list --path=saved-views`. `04_PREUVES/P0/`
- **Témoin négatif** : les quatre autres verbes du **même contrôleur** répondent bien 501 — le contrôle sait donc reconnaître un 501 quand il y en a un. L'anomalie porte sur `index` seul.
- **Impact**        : un appelant ne peut pas distinguer « tu n'as enregistré aucune vue » de « cette fonction n'existe pas ». Le CDC (§18) fait des vues enregistrées une fonction attendue : une façade qui répond « rien à afficher » retarde la découverte du trou. **Corrige aussi `_REPORTS/2026-08-19_INVENTAIRE-ETAPE-1A.md`**, qui classe `saved_views` parmi les tables « sans modèle, ni contrôleur, ni route » — c'est faux.
- **Reproduction**  : `GET /api/v1/saved-views` avec une session valide.
- **Correctif**     : deux options — (a) `index` répond 501 comme ses quatre voisines, cohérent et honnête ; (b) la fonction est réalisée. Le §12-10 de la consigne d'audit interdit qu'une route 501 subsiste **sous un nom que le CDC emploie** : « vues enregistrées » en est un. Décision à prendre en P2.
- **Statut**        : **ouvert**
- Vérifié par / Réfuté par / Passe 3 : —

---

### [A-003] `.gitattributes` a été posé mais la copie de travail n'a jamais été renormalisée : 8 scripts `.sh` sur 16 sont encore en CRLF
- **Sévérité**      : **S2**
- **Domaine**       : exploitation / tests
- **Référence**     : mesuré à `c0c453d`, **re-mesuré à `e8924b8`**
- **Emplacement**   : `.gitattributes` (posé le 2026-08-19) vs la copie de travail du dépôt principal
- **Constat**       : `.gitattributes` déclare `* text=auto eol=lf` et `*.sh text eol=lf`. Git **ne renormalise pas** une copie de travail existante : les fichiers extraits avant restent en CRLF jusqu'à une nouvelle extraction.
- **Preuve**        : comptage des **octets `0x0d`** (`od -An -tx1 | grep -c '^0d'`) sur les 16 `.sh` suivis par git :
  ```
  CRLF backend/database/perf/mesure_reference.sh         29 octets CR
  CRLF infra/docker/entrypoint-prod.sh                   51
  CRLF infra/scripts/backup-postgres.sh                 181
  CRLF infra/scripts/configure-prod-env.sh              103
  CRLF infra/scripts/dr-drill.sh                        205
  CRLF infra/scripts/setup-backup.sh                    116
  CRLF infra/scripts/setup-hetzner-cpx22.sh             149
  CRLF infra/scripts/verifier-sauvegarde.sh             155
  ---
  scripts .sh suivis : 16   dont porteurs de CR : 8
  ```
- **Témoin négatif** : la méthode a été validée **avant** usage, sur deux fichiers fabriqués — un pur LF rend **0** octet CR, un pur CRLF rend **2**. Et les 8 autres scripts rendent **0** : le contrôle n'accuse donc pas tout le monde en bloc.
- ⚠️ **Correction de ma propre mesure de 09:40Z.** La première version de ce constat citait `verifier-ports-publies.sh` (« 167 lignes CRLF ») et `fermer-ports-db-prod.sh`. **Ces deux fichiers sont en LF aujourd'hui**, corrigés par un commit postérieur à `c0c453d`. Le fond du constat tient, mais l'exemple était périmé et la liste exacte est celle ci-dessus. Écart levé par l'**agent 9** (contre-vérification), puis re-mesuré indépendamment par le chef de chantier avec témoin.
- **Impact**        : c'est la mécanique exacte qui a rendu un script inexécutable sur le serveur le 19/08 (`line 39: $'\r': command not found`). Les 8 fichiers restants sont **précisément ceux qu'on envoie sur un serveur le jour où ça va mal** : `dr-drill.sh` (exercice de reprise), `backup-postgres.sh` et `verifier-sauvegarde.sh` (sauvegardes), `entrypoint-prod.sh`, `setup-hetzner-cpx22.sh`, `configure-prod-env.sh`. Le commentaire de `.gitattributes` affirme pourtant « plus de divergence entre ce qu'on lit, ce qu'on commite et ce qu'on envoie » : c'est faux pour la moitié des scripts, et c'est le genre d'affirmation qu'on ne relit pas le jour de la panne.
- **Reproduction**  : `for f in $(git ls-files '*.sh'); do od -An -tx1 "$f" | tr ' ' '\n' | grep -c '^0d'; done`
- **Correctif**     : `git add --renormalize .` puis re-extraction. Ajouter une garde CI qui refuse un `.sh` porteur d'un octet CR, **vue rouge** sur un fichier fautif fabriqué pour l'occasion. Corriger le commentaire de `.gitattributes`, qui promet plus qu'il ne tient. Coût : ~45 min.
- ➕ **Extension mesurée par l'agent 46, et elle tranche définitivement le chiffre** : le CRLF ne touche pas que les `.sh`. Sur un arbre **exporté en LF** (`git archive HEAD backend`), `pint --test` rend **174 fichiers** non formatés et **0 `line_ending`** ; la **copie de travail** en rend **385**. **211 sont donc un pur artefact CRLF**, et un comptage indépendant confirme **210 fichiers `.php` en CRLF côté poste, 0 côté dépôt**.
  *Conséquence : le « 14 fichiers » que j'avais mesuré au départ et le « 276 fichiers » inscrit dans `ci.yml:20` sont **tous deux faux**. Le chiffre réel de la dette de format est **174**.*
- **Statut**        : **ouvert**
- **Vérifié par**   : agent 9 (exemple réfuté, fond confirmé) · agent 46 (périmètre étendu aux `.php` et chiffre tranché) · re-mesuré avec témoin par le chef de chantier.
- Réfuté par / Passe 3 : —

### [A-004] La pile locale demande des certificats Let's Encrypt/ZeroSSL pour les domaines de PRODUCTION
- **Sévérité**      : **S3** *(abaissée depuis S2 — voir la correction dans Impact)*
- **Domaine**       : exploitation / sécurité
- **Référence**     : `main c0c453d`
- **Emplacement**   : `infra/caddy/Caddyfile` — les blocs `app.axion-crm-pro.com` (l.91), `api.axion-crm-pro.com` (l.120), `staging.axion-crm-pro.com` (l.164), `staging-api.axion-crm-pro.com` (l.192) sont dans **le même fichier** que les blocs `app.localhost` / `api.localhost`, et le Caddy **local** les charge tous.
- **Constat**       : le Caddy du poste de développement tente en boucle d'obtenir des certificats pour les quatre noms de production et de préproduction, et échoue (`Redirect loop detected`, le nom résout vers Cloudflare).
- **Preuve**        :
  ```
  $ docker logs axion-crm-caddy
  {"logger":"http.acme_client","msg":"challenge failed","identifier":"api.axion-crm-pro.com",
   "problem":{"detail":"104.21.61.221: Fetching https://api.axion-crm-pro.com/.well-known/
   acme-challenge/…: Redirect loop detected"}}
  {"logger":"tls.obtain","msg":"could not get certificate from issuer",
   "identifier":"api.axion-crm-pro.com","issuer":"acme-v02.api.letsencrypt.org-directory"}
  … puis bascule sur acme.zerossl.com, compte williamsjullin@gmail.com
  ```
  `04_PREUVES/P0/etat-local.txt`
- **Témoin négatif** : les blocs `app.localhost` / `api.localhost` obtiennent bien, eux, un certificat interne et répondent **200**. Le mécanisme ACME de Caddy fonctionne donc : ce sont bien les **noms de production** qui échouent, et non le poste qui serait hors ligne.
- **Impact**        : chaque poste de développement qui lance la pile consomme un quota ACME **sur les noms réels de la production**, avec un compte **personnel**.
  ⚠️ **Correction, apportée par l'agent 40 — j'avais surévalué la gravité.** Ce qui est consommé est la
  **limite horaire de validations échouées**, **pas le quota d'émission de certificats**. Le
  renouvellement de la production **n'est donc pas menacé** : fenêtre ARI au **13/09**, expiration au
  **14/10**. J'avais écrit « le jour où la production doit renouveler, elle peut se le voir refuser » —
  c'était une extrapolation, pas une mesure. Le constat tient (une pile locale ne doit pas demander de
  certificat pour un nom de production, et le bruit ACME est réel), **sa gravité baisse de S2 à S3**.
- **Reproduction**  : `docker compose -f docker-compose.yml -f docker-compose.local.yml up -d` puis `docker logs axion-crm-caddy`.
- **Correctif**     : sortir les blocs de production et de préproduction dans un `Caddyfile.prod` chargé uniquement par `docker-compose.prod.yml`/`.staging.yml` (le fichier local ne portant que `*.localhost`) ; ou les encadrer d'un `import` conditionnel. Ajouter une garde qui refuse qu'un `Caddyfile` local déclare un nom public. Coût : ~1 h. ⚠️ **Contrainte de sécurité connue** : « une faute de frappe dans le Caddyfile casse la production » (journal 19/08 §6.4-3) — la garde CI `caddy validate` existe déjà (`ci.yml`, job `caddyfile-valide`), **s'appuyer dessus, ne pas la réinventer** (règle 8).
- **Statut**        : **ouvert**
- Vérifié par / Réfuté par / Passe 3 : —

---

## Constats des agents — P1

*(remplis au fur et à mesure des retours d'agents, puis dédoublonnés en P2)*

---

### [A-005] Deux écrans factices restent joignables par URL, sans que rien ne les annonce
- **Sévérité**      : **S3**
- **Domaine**       : navigation / UX
- **Référence**     : `main c0c453d`
- **Emplacement**   : `frontend/src/app/routeTree.tsx:102-103` ; `frontend/src/features/phase2-scaffold/{ColdEmailStub,LinkedInStub}.tsx` ; `backend/routes/api.php:297-300`
- **Constat**       : l'étape 0 a retiré les **six entrées verrouillées** de la barre latérale et supprimé les fourre-tout `/crm{any?}` et `/analytics{any?}` (F7). Mais les routes `/cold-email` et `/linkedin` — écran factice côté SPA, réponse 501 côté API — **sont conservées à dessein** et restent joignables en tapant l'URL.
- **Preuve**        : `routeTree.tsx:102` `path: '/cold-email', component: ColdEmailStub` ; `api.php:299` `Route::any('/cold-email{any?}', ColdEmailController::class)`. Le commentaire du dépôt assume le choix : « ces noms n'entrent en collision avec aucun nom du cahier des charges, et le lot campagnes est hors périmètre ».
- **Témoin négatif** : `/crm{any?}` et `/analytics{any?}` **ont bien été retirés**, et la garde `tests/Feature/PasDeStub501SousCrmEtAnalyticsTest.php` existe pour l'empêcher de revenir. Le contrôle voit donc la différence entre « retiré » et « conservé » : les deux routes restantes le sont réellement.
- **Impact**        : faible et borné — plus aucune navigation n'y mène, et les noms ne collident avec aucun mot du CDC. Mais l'exigence de sortie de **F8 (§A.1 du CDC)** est « **retirées ou réalisées** », pas « masquées du menu ». En l'état, F8 est **partiellement** close, et un signet ou un lien externe mène encore à un écran qui promet une fonction inexistante.
- **Reproduction**  : ouvrir `https://app.localhost/cold-email`.
- **Correctif**     : retirer les deux routes SPA et les deux contrôleurs, et rediriger `/cold-email` et `/linkedin` vers `/` (le §6.3-9 du mandat exige que ce qui disparaît devienne une redirection, pas un 404). Coût : ~30 min. **Ou** arbitrage écrit assumant la conservation — auquel cas il doit être inscrit dans un ADR, pas seulement dans un commentaire de `routes/api.php`.
- **Statut**        : **ouvert** — arbitrage `D-008`
- Vérifié par / Réfuté par / Passe 3 : —

---

### [A-006] Le §4.8 et le §6.2 du mandat d'audit décrivent une barre latérale qui n'existe plus
- **Sévérité**      : **S2** *(constat de méthode, dirigé contre le document qui pilote l'audit)*
- **Domaine**       : navigation
- **Référence**     : `main c0c453d`
- **Emplacement**   : `frontend/src/components/layout/Sidebar.tsx:58-172` vs `_PROMPTS/PROMPT_AUDIT_360_CRM_PRO_2026-08-18.md` §4.8 et §6.2
- **Constat**       : la barre a été **refondue pendant l'étape 0** (ligne 3 bis, F15/F17). Le mandat d'audit, même dans sa révision 2.1 du 19/08, décrit l'état d'avant.
- **Preuve**        : lecture de `Sidebar.tsx`. Ce que le mandat annonce comme « défauts déjà identifiés », et ce que le code dit :

  | §6.2 du mandat | Mesuré sur `main = c0c453d` |
  |---|---|
  | 1. Deux entrées « Contacts » | **corrigé** — `sectionContacts()` en rend **une seule** : `/console/contacts` si la console v2 est ouverte, `/contacts` sinon, **jamais les deux** |
  | 2. « Campagnes » = collecte, collision à venir | **corrigé** — renommé **« Collectes »**, le mot « campagne » est réservé aux e-mails (L7) |
  | 3. Six entrées verrouillées vers quatre routes 501 | **corrigé** — plus **aucune** entrée `locked` dans les sections |
  | 4. Une section « Phase 2 » entière | **corrigée** — la section n'existe plus |
  | 5. Outillage de collecte au premier niveau | **partiellement corrigé** — LLM Router / Proxies / Rotations ont quitté le premier niveau… pour atterrir dans **« Réglages »**, où ils cohabitent avec Utilisateurs, Paramètres et Tags |
  | 6. Un groupe nommé « Data » | **corrigé** — devenu « Contacts » |
  | 7. Dix sections | **corrigé** — **six** : Aujourd'hui · Contacts · Collecte · Pilotage · Conformité · Réglages |
  | 8. Visite guidée câblée sur l'ancienne barre | **à vérifier** — `OnboardingTour.tsx` vise `sidebar`, `global-search`, `nav-companies`, `nav-dashboard`, `dark-mode`, `nav-settings` ; le `data-tour="nav-campaigns"` est **conservé dans la barre mais n'est plus utilisé par la visite** |
  | 9. « Runs de scraping », anglicisme | **corrigé** — « Journaux de collecte » |
  | 10. Hub derrière un drapeau runtime | **encore vrai** — et c'est un choix, pas un défaut : sans le drapeau, la barre retombe proprement sur `/contacts` |

- **Témoin négatif** : le point 10 est **confirmé encore vrai** et le point 5 **partiellement**. Le contrôle ne dit donc pas « tout est corrigé » par complaisance : il distingue.
- **Impact**        : un audit qui aurait recopié le §6.2 aurait produit **huit constats faux** et fait rouvrir un chantier déjà fait — exactement le gaspillage que la révision 2.1 disait vouloir éviter. **Ce qui reste réellement à faire vis-à-vis de la cible §23.3 du CDC est autre chose** : il manque le groupe **ÉCHANGES** en entier, les entrées **Boîte de réception / Mes rendez-vous / Mes tâches**, les **vues épinglées par type**, **Organisations**, **Prospection**, **Canal avec la console**, **Coûts**, le lien **↗ Console axionia**, **Fiches récentes**, et **tous les compteurs** (le CDC en exige, la barre n'en porte aucun). C'est le vrai périmètre de l'agent 23.
- **Correctif**     : corriger le §4.8 et le §6.2 du mandat d'audit ; recentrer le chapitre §6 sur l'**écart à la cible §23.3**, et non sur des défauts résolus.
- **Statut**        : **ouvert**
- Vérifié par / Réfuté par / Passe 3 : —

---

### [A-007] Telescope tourne en production sans ses tables : le journal pèse 270 Mo, grossit d'environ 90 Mo par jour, et 100 % de ce qu'il écrit est le même défaut
- **Sévérité**      : **S1**
- **Domaine**       : exploitation / sécurité
- **Référence**     : PRODUCTION `46.62.248.239`, mesuré en **lecture seule** le 2026-08-19 (11:05Z pour la mesure corrigée)
- **Emplacement**   : conteneur `axion-crm-api` ; `laravel/telescope` est une dépendance **dure** de `composer.json`, dont le défaut est `enabled = true`, et **ses migrations ne sont jamais publiées** dans ce dépôt
- **Constat**       : `TELESCOPE_ENABLED` **n'existe pas dans le `.env` de production**. Telescope est donc actif alors qu'**aucune table `telescope_*` n'existe**. Chaque requête échoue à la terminaison sur un `insert into telescope_entries` et écrit sa trace complète.
- **Preuve**        :
  ```
  $ docker exec axion-crm-postgres psql -U axion -d axion_crm -tAc
      "select count(*) from information_schema.tables where table_name like 'telescope%'"
  0
  $ docker exec axion-crm-api sh -c 'env | grep -i telescope'
    (aucune variable TELESCOPE_*)
  $ LOG_LEVEL=debug   LOG_CHANNEL=stack        # aucune rotation

  # fenetre chronometree de 120 s, decoupee sur la croissance reelle du fichier
  croissance                                   : 124 514 octets
  entrees de journal (lignes horodatees)       : 11        -> 5,5 par minute
  dont production.ERROR                        : 11        -> 100 %
  occurrences de la chaine 'telescope_entries' : 77        -> 7 par entree
  debit projete                                : ~89 Mo/jour
  taille du fichier                            : 270 394 142 octets (270 Mo)
  $ df -h /   ->  75G total, 25G disponibles
  ```
- **Témoin négatif** : la fenêtre est **découpée sur la croissance réelle du fichier** (`tail -c $DELTA`), donc les 11 entrées sont exactement celles écrites pendant les 120 s mesurées. Et **11 entrées sur 11 sont des `ERROR`** : le compte ne mélange pas le bruit normal avec le défaut.

- ⚠️ **CORRECTION DE MA PREMIÈRE MESURE — je m'étais trompé, et j'avais accusé le journal de construction à tort.**
  J'avais écrit : *« le journal du 19/08 chiffre ce défaut à 6 erreurs par minute ; mesuré : 56 par minute — 9× plus »*.
  **C'est faux.** J'avais compté les **occurrences de la chaîne** `telescope_entries`, or elle apparaît
  **7 fois par entrée de journal** (message, requête SQL, trace d'appels). 56 occurrences ≈ 8 entrées.
  La mesure propre, sur 120 s et en comptant les **entrées horodatées**, donne **5,5 par minute** —
  et l'agent 40, qui a compté 2 824 `ERROR` sur 484 minutes, trouve **5,8**. Les deux concordent.
  **Le « 6 par minute » du journal de construction était juste. C'est moi qui avais tort.**
  Le débit se corrige de la même façon : **~90 Mo/jour** (89 mesuré ici, 94 par l'agent 40 en moyenne),
  et non 133.
  *Leçon, et elle vaut pour toute la suite de l'audit : compter des occurrences de chaîne n'est pas
  compter des événements. Le facteur d'erreur était de 7 — soit exactement le nombre de fois où le
  défaut se cite lui-même.*

- **Impact**        : trois effets, et le troisième reste le grave.
  1. **Disque** : ~90 Mo/jour, soit ~2,7 Go/mois, sur une machine à 25 Go libres qui a déjà frôlé la
     saturation le 19/08. Aucune rotation (`LOG_CHANNEL=stack`, pas `daily`) ; l'agent 40 a mesuré
     qu'**aucun `daemon.json` ne borne non plus les journaux de conteneurs**.
  2. **Lisibilité** : un fichier de 270 Mo n'est plus consultable au moment où l'on en a besoin.
  3. 🔴 **Aveuglement** : `LOG_LEVEL=debug` en production, dans un fichier dont **100 % des erreurs
     sont le même défaut**. Une vraie erreur y passe inaperçue. C'est la condition qui a permis à la
     faille du 19/08 de rester invisible — et le volume reste largement suffisant pour la rejouer.
- **Cause racine, mesurée par l'agent 40** : `TELESCOPE_ENABLED` est présent dans `.env.example`,
  `.env.local` et `.env.test`, et **absent du `.env` de production** — avec **20 autres clés** de
  `.env.example` absentes du serveur. Ce n'est donc pas un oubli isolé mais une **dérive** entre le
  modèle et le réel. → constat **F40-003**.
- **Correctif**     : poser `TELESCOPE_ENABLED=false` en production. **La parade existe déjà et est
  motivée** dans `docker-compose.local.yml` — elle n'a simplement jamais été portée en production
  (règle 8 : on étend, on ne réinvente pas). Puis `LOG_CHANNEL=daily` avec rétention, `LOG_LEVEL=warning`,
  un `daemon.json` qui borne les journaux de conteneurs, et la purge du fichier de 270 Mo.
  ⚠️ **Piège 18** : `api` est l'un des quatre services **que le déploiement recrée** — la variable
  passera. **Piège 8** : `docker compose restart` ne relit pas `env_file`, il faut `up -d`.
  Et la garde doit **rougir sur le conteneur**, pas sur `.env.example` (piège 19, constat A-011).
- **Statut**        : **ouvert**
- **Vérifié par**   : agent 40 — chiffres corrigés, cause racine trouvée ; re-mesuré par le chef de chantier, qui **corrige son propre constat et rend raison au journal de construction**.
- Réfuté par / Passe 3 : —

---

### [A-008] Une session de construction a poussé trois PR sur `main` pendant l'audit
- **Sévérité**      : **S3** *(constat de méthode — aucun dégât, mais il change la référence)*
- **Domaine**       : exploitation
- **Référence**     : `c0c453d` → `b53338c` → `1145473` → **`e8924b8`**
- **Constat**       : le §3 ter du mandat interdit de lancer l'audit en parallèle d'une session de construction. La consigne a été respectée côté audit (le worktree `crmpro-wt-etape1a` n'a pas été touché), mais **une autre session a fusionné trois PR sur `main` pendant que les 20 agents mesuraient**.
- **Preuve**        :
  ```
  $ git log --format="%h | %an | %ad | %s" --date=iso c0c453d..origin/main
  e8924b8 | will383842 | 2026-08-19 12:07:34 +0200 | fix(rgpd+acces): rectification du registre + acces CRM rendu (#189)
  1145473 | will383842 | 2026-08-19 11:44:24 +0200 | docs(rgpd): registre des violations, notification non retenue (#188)
  b53338c | will383842 | 2026-08-19 11:28:58 +0200 | docs(cnil): pas de telephone (#187)

  $ git diff --stat c0c453d origin/main
   _REPORTS/2026-08-19_BROUILLON-NOTIFICATION-CNIL-ART33.md | 109 +++----
   _REPORTS/REGISTRE-DES-VIOLATIONS-DE-DONNEES.md           | 247 +++++++++++
   infra/scripts/definir-mot-de-passe-crm.sh                | 131 +++++++
   3 files changed, 442 insertions(+), 45 deletions(-)
  ```
- **Témoin négatif** : le `git diff --stat` porte sur **l'intégralité** de l'arborescence, pas sur un sous-ensemble choisi — s'il avait touché `backend/`, `frontend/` ou `workers/`, il l'aurait montré. **Aucun fichier de code produit n'a bougé** : deux documents et un script neuf.
- **Impact**        : **nul sur la validité de l'audit** — le code mesuré à `c0c453d` est identique à celui de `e8924b8`. Mais trois agents ont rapporté trois références différentes (`1145473`, `b53338c`, `e8924b8`), ce qui aurait pu passer pour une incohérence de mesure. Deux conséquences à retenir : (a) la référence de l'audit devient **`e8924b8`, code identique à `c0c453d`** ; (b) le nouveau script `infra/scripts/definir-mot-de-passe-crm.sh` (« accès CRM rendu ») **entre au périmètre** — il n'a été audité par personne, et il touche à l'accès du produit, ce qui croise directement **A-001**.
- **Correctif**     : mettre `_DOSSIER-AGENT.md` à jour ; auditer le script neuf ; re-comparer `main` en fin de P7 pour vérifier qu'aucun code n'a bougé sous l'audit.
- **Statut**        : **ouvert**
- Vérifié par / Réfuté par / Passe 3 : —

---

### [A-009] L'atelier local sert la console par le serveur de développement mono-processus de PHP
- **Sévérité**      : **S2**
- **Domaine**       : exploitation / tests / performance
- **Référence**     : `e8924b8`, atelier local
- **Emplacement**   : conteneur `axion-crm-api` — `php -S 0.0.0.0:80 -t public`
- **Constat**       : la pile locale ne sert **qu'une requête à la fois**. Toute commande `artisan`, `pest` ou `tinker` lancée dans le même conteneur passe devant les requêtes HTTP.
- **Preuve**        :
  ```
  $ docker exec axion-crm-api ps
  PID  COMMAND
    1  php -S 0.0.0.0:80 -t public              <- serveur de developpement, mono-processus
    7  php artisan crm:flush-outbound
   13  php /tmp/seed.php
   33  php artisan tinker --execute=...
   45  php artisan db:seed --class=PermissionsAndRolesSeeder --force
   61  php ./vendor/bin/pest --filter=Etancheite
   67  sh -c php artisan test --filter=EtancheiteParTable

  $ curl https://api.localhost/up      -> 000 (expiration a 45 s puis a 60 s)
  ```
- **Témoin négatif** : le même `/up` répondait **200 en 2,7 s** à 09:32Z, avant que les agents ne chargent l'atelier — et `https://app.localhost` (servi par le conteneur `app`, indépendant) **répond toujours**. Le blocage est donc bien la sérialisation de l'API, pas une panne de la pile ni du réseau.
- **Impact**        : deux conséquences distinctes.
  1. **Sur l'audit** : le §8 P0.3 exige que « la console CRM tourne en local ». Elle tourne — **pour un seul utilisateur à la fois**. Le bloc D (37 écrans à ouvrir à la main) et le bloc G (charge) ne peuvent pas travailler en parallèle du reste. C'est une contrainte de méthode, consignée plutôt que subie.
  2. **Sur le produit** : le **critère 17 du §29** du CDC exige « dix sessions actives sans dégradation de plus de 20 % ». Il est **inmesurable sur cet atelier** : dix sessions y seraient sérialisées par construction. Toute mesure de concurrence faite ici serait fausse — et une mesure fausse présentée comme vraie est exactement ce que la passe adversariale doit trouver.
- **À vérifier séparément** : la **production** utilise-t-elle php-fpm, ou le même serveur de développement ? Si c'est le second, ce constat passe **S0**. *(Confié à l'agent 40 — infrastructure.)*
- **Correctif**     : pour l'atelier, servir l'API par php-fpm + Caddy comme en production, ou au minimum documenter que les mesures de concurrence n'y sont pas valables. Pour l'audit : sérialiser les travaux qui passent par HTTP.
- **Statut**        : **ouvert**
- Vérifié par / Réfuté par / Passe 3 : —


---

### [A-010] 🔴 La PRODUCTION sert toute l'API par le serveur de développement mono-processus de PHP : les requêtes sont sérialisées
- **Sévérité**      : **S0** — *blocage du chantier cible*
- **Domaine**       : infrastructure / performance / sécurité
- **Référence**     : PRODUCTION `46.62.248.239`, conteneur `axion-crm-api`, mesuré en **lecture seule** le 2026-08-19 vers 10:45Z. Même constat en **préproduction**.
- **Emplacement**   : `Dockerfile.laravel` / `infra/docker/entrypoint-prod` — `Config.Cmd = ["php","-S","0.0.0.0:80","-t","public"]`
- **Constat**       : l'API de production est servie par le **serveur web intégré de PHP en ligne de commande**, en **un seul processus**, sans `PHP_CLI_SERVER_WORKERS`. Il traite **une requête à la fois** : la Nᵉ requête simultanée attend que les N−1 précédentes soient terminées.

- **Preuve**        :
  ```
  $ docker inspect axion-crm-api --format '{{json .Config.Cmd}} {{json .Config.Entrypoint}}'
  ["php","-S","0.0.0.0:80","-t","public"]   ["/usr/local/bin/entrypoint-prod"]

  $ docker exec axion-crm-api sh -c 'ps -o pid,args' | grep -F 'php -S' | grep -v grep
      1 php -S 0.0.0.0:80 -t public
    -> nombre de processus : 1

  $ docker exec axion-crm-api sh -c 'env | grep -i CLI_SERVER'
    (non pose)          # PHP_CLI_SERVER_WORKERS absent : pas de worker supplementaire
  ```

  **12 requêtes SIMULTANÉES sur `/up`** (depuis le conteneur, sans surcoût réseau), temps triés :
  ```
   1  0,025 s     7  0,103 s
   2  0,041 s     8  0,121 s
   3  0,055 s     9  0,140 s
   4  0,072 s    10  0,156 s
   5  0,086 s    11  0,177 s
   6  0,091 s    12  0,192 s
  ```
  **Escalier parfait, de pas ≈ 15 ms** — soit exactement la durée d'une requête isolée. La Nᵉ termine à N × 15 ms.

- **Témoin positif** : **12 requêtes SÉQUENTIELLES** sur le même point, mêmes conditions :
  ```
   1  0,0145 s    …    12  0,0184 s      (plat, aucune croissance)
  ```
  Le serveur traite donc bien une requête en **15 ms**. L'escalier observé en concurrence n'est **pas**
  un ralentissement du serveur ni un artefact de mesure : c'est une **mise en file d'attente**.
  Les deux séries ont été jouées **dans le même conteneur, à la même minute, sur le même point**.
- **Témoin manqué, et je le dis** : j'ai tenté de déposer un point lent (`sleep 3`) pour rendre
  l'escalier spectaculaire. **Refusé — `Permission denied` : le système de fichiers du conteneur de
  production est en lecture seule.** C'est une bonne nouvelle de sécurité, et cela signifie que ce
  témoin-là n'a pas été joué. La démonstration repose donc sur l'escalier de 12 points et son témoin
  séquentiel, qui suffisent.

- **Impact**        : c'est le constat d'infrastructure le plus lourd de l'audit, et il a trois faces.
  1. 🔴 **Une requête lente bloque TOUS les utilisateurs.** Ce n'est pas théorique : le journal du
     19/08 (§2.11) a mesuré les compteurs du hub à **17,5 s cache froid sur 2,8 M de fiches** — et la
     production en porte **4,29 M**. Une seule ouverture de la console après une purge de cache gèle
     **l'application entière** pendant tout ce temps. Le correctif de la pièce 1 (index couvrant +
     `Cache::flexible`) prend d'ailleurs tout son sens ici : il ne réglait pas seulement une lenteur,
     il retirait un **point de blocage global** — sans que personne ait vu que c'en était un.
  2. 🔴 **Le principe directeur 8 du CDC est structurellement violé** : « Conçu pour **dix
     utilisateurs** et plusieurs sociétés **dès le premier jour** ». Et le **critère 17 du §29**
     (« dix sessions actives, aucune dégradation de plus de 20 % ») n'est pas « non mesuré » : il est
     **inatteignable par construction**. Aucun réglage applicatif ne le rendra vrai.
  3. **Sécurité** : la documentation de PHP est explicite sur ce serveur — *« il n'est pas destiné à
     être un serveur web complet ; il ne devrait pas être utilisé sur un réseau public »*. Il est ici
     sur un réseau public, derrière Caddy et Cloudflare, mais exposé.

- **Pourquoi personne ne l'a vu** : avec **un seul utilisateur** (mesuré : 1 compte, 0 session,
  0 jeton — personne ne s'est jamais connecté), la sérialisation est **rigoureusement invisible**.
  Tous les contrôles verts du produit ont été joués à un utilisateur. C'est le piège 19 dans sa forme
  la plus pure : **les gardes mesurent le bon objet, mais dans des conditions où le défaut ne peut pas
  se manifester.**

- **Reproduction**  : les trois commandes ci-dessus, en lecture seule, depuis le serveur.
- **Correctif**     : servir l'API par **php-fpm** (l'image le contient déjà : les conteneurs
  `horizon` et `scheduler` exposent `9000/tcp`, le port de php-fpm — **la brique existe, il n'y a rien
  à inventer**, règle 8), avec Caddy en `fastcgi`. Repli immédiat et à coût quasi nul si php-fpm
  demande trop de travail : poser **`PHP_CLI_SERVER_WORKERS=8`**, que PHP lit au démarrage du serveur
  intégré et qui le fait forker — ce n'est pas une solution de production, mais cela supprime la
  sérialisation en une variable d'environnement.
  ⚠️ **Piège 18** : `api` fait partie des quatre services **que le déploiement recrée** — un changement
  passera donc. Mais **piège 8** : `docker compose restart` ne relit pas `env_file`, il faut `up -d`.
  **Et la garde à écrire** (piège 19) doit rougir **sur le conteneur qui tourne**, pas sur le
  `Dockerfile` : « aucun conteneur de la pile ne sert HTTP par `php -S` ».
- **Coût**          : ~4 h pour php-fpm + Caddy `fastcgi` + garde ; ~15 min pour le repli par variable.
- **Statut**        : **ouvert** — **le premier lot de P3**
- Vérifié par / Réfuté par / Passe 3 : —

*(Ce constat **absorbe et remplace** le volet « production » de A-009. A-009 reste ouvert pour son
volet atelier : la même sérialisation y rend le critère 17 inmesurable en local, et a saturé la pile
sous vingt agents.)*

---

### [A-011] Défaut systémique : les gardes de ce dépôt mesurent souvent le mauvais objet — douze cas indépendants
- **Sévérité**      : **S1** *(constat de synthèse du chef de chantier — il ne remplace aucun constat d'agent, il les relie)*
- **Domaine**       : tests / méthode
- **Référence**     : `e8924b8`
- **Constat**       : le §3 bis du mandat signalait **un** cas (« la garde `config-prod` est irréprochable et mesure le mauvais objet ») et demandait d'en chercher d'autres. **Six ont été trouvés, par six agents qui ne se parlaient pas.** Ce n'est plus un incident, c'est un **patron de conception** du harnais de ce dépôt.

- **Preuve**        : les six cas, chacun mesuré indépendamment, chacun avec son rapport :

  | # | La garde | Ce qu'elle prétend garder | Ce qu'elle mesure **réellement** | Trouvé par |
  |---|---|---|---|---|
  | 1 | `config-prod` (CI) | que la production ne publie que 80 et 443 | **le fichier Compose**, pas les conteneurs — un correctif fusionné et déployé « avec succès » n'avait **rien fermé** | journal 19/08 §7.3 |
  | 2 | `CrmOutboundTest` — « un 503 fait ATTENDRE sans consommer de tentative » | la temporisation du canal sortant | une réponse 503 **que le site n'émet jamais** (`Http::fake`). **15 tests verts, 52 assertions** | agent 14 |
  | 3 | `SsrfGuard` (PHP **et** TS) | que l'IPv6 privée est refusée | rien : les 6 cas IPv6 sont bloqués par **`dns_no_records`**, un accident de parsing. `DENY_CIDR` **ne contient aucune plage IPv6** — corriger le parsing **ouvrirait** la faille | agent 19 |
  | 4 | La garde du décalage horaire | que les dates traversent en UTC | **sa propre fixture**, pendant que le canal décale de **+7 200 s** `occurred_at` **et** `consent_at` | agent 13 |
  | ~~5~~ | ~~rapport pare-feu~~ | — | **RETIRÉ de cette liste : ce n’en est pas un.** Le document **refusait de conclure** et **prédisait la faille exacte** ; c’est une **case ✅ posée par-dessus** qui a failli. Patron distinct → **A-013** | agent 6 |
  | 6 | `POST /proxy-providers/{p}/test`, documenté « health check live » | la santé réelle d'un fournisseur | rien : il renvoie **`healthy: true` en dur** | agent 19 |

  À quoi s'ajoutent trois gardes qui ne mesurent **rien du tout**, ce qui est le cas dégénéré du même
  défaut : `composer-audit` sort `No installed packages found` puis rend `success` (**H47-001**) ; les
  deux `pnpm-audit` affichent 31 et 33 vulnérabilités puis rendent `success`, et le job d'alerte dépend
  de leur `failure()` — il **ne peut jamais** se déclencher (**H47-002**) ; et le « BLOQUANT » écrit
  dans `a11y.yml` **n'est pas une vérification requise** (**H44-002**).

- **Témoin négatif** : le crible **discrimine** — toutes les gardes n'y tombent pas. L'agent 13 a
  trouvé le canal entrant **exemplaire** (1 témoin positif et 4 témoins négatifs joués sur la
  signature HMAC, idempotence adossée à un index UNIQUE réel, cloisonnement en 503 sans écriture) ;
  l'agent 11 a semé **2 lignes dans 2 espaces sur 57/57 tables** pour qu'aucun contrôle ne soit vrai
  par vacuité ; les gardes de la pièce 1 du 19/08 ont été vues rouges **dans cinq modes de
  défaillance distincts**, et l'une d'elles **nomme l'index attendu** précisément parce que « pas de
  `Seq Scan` » serait passé vert sans l'index. **Le dépôt sait écrire de bonnes gardes.** Le défaut
  n'est donc pas une incapacité, c'est une **inattention répétée au même endroit** : personne ne se
  demande *sur quel objet* la garde rougit.

- **Impact**        : c'est le constat qui **explique les autres**. Le produit affiche des indicateurs
  verts et a laissé passer : une base de données ouverte sur internet, une chaîne d'audit sans secret,
  26 tâches planifiées sans contexte d'espace, un canal sortant qui n'efface rien, et une production
  qui sérialise toutes ses requêtes. **Aucun de ces défauts n'a fait rougir quoi que ce soit.**
  Tant que le patron n'est pas nommé et corrigé, chaque nouvelle garde a de bonnes chances de le
  rejouer — y compris les gardes que **cet audit** va écrire en P3.

- **Correctif**     : trois gestes, dans cet ordre.
  1. **Étendre la règle 2 de la doctrine par écrit** dans `CONTRIBUTING.md` : *une garde ne vaut que
     si elle rougit **sur l'objet qui casse**.* Toute nouvelle garde doit déclarer, en une phrase,
     **quel objet** elle mesure et **pourquoi c'est celui qui casse**.
  2. **Passer les gardes existantes au crible**, une par une — c'est le périmètre de l'agent 45, qui
     reçoit ces six cas comme modèles.
  3. **Pour les six cas ci-dessus** : réparer la garde **avant** de réparer ce qu'elle garde. Une garde
     fausse réparée après le défaut qu'elle a laissé passer ne prouve rien. Cas 3 en priorité :
     **corriger le parsing IPv6 sans ajouter les plages à `DENY_CIDR` ouvrirait une faille** — les deux
     gestes sont indissociables.

- **Statut**        : **ouvert**
- Vérifié par / Réfuté par / Passe 3 : —

---

### [A-012] Le propriétaire du CRM ne peut pas entrer dans son propre produit — trois défauts qui se referment l'un sur l'autre
- **Sévérité**      : **S0** — *le produit n'a jamais été utilisable par qui que ce soit*
- **Domaine**       : sécurité / exploitation / UX
- **Référence**     : PRODUCTION, mesuré en lecture seule le 2026-08-19 à 11:07Z
- **Constat**       : le compte propriétaire existe depuis le **2026-05-17**. **Personne ne s'est jamais connecté au CRM en production** — et ce n'est pas un manque d'intérêt, c'est une **impossibilité mécanique**, produite par trois défauts indépendants qui se renforcent.

- **Preuve**        :
  ```
  users    = 1        williamsjullin@gmail.com | 2026-05-17 | a_un_hash = t
  sessions = 0
  tokens   = 0
  ```
  Les trois maillons, chacun mesuré séparément :

  | # | Le maillon | Mesure | Constat |
  |---|---|---|---|
  | 1 | `OWNER_INITIAL_PASSWORD` était **vide** à l'installation → le seeder a **généré** un mot de passe aléatoire de 32 caractères et l'a **annoncé une seule fois**, à la console de déploiement | `OwnerUserSeeder.php:20` et `:164` | personne n'a jamais reçu ce mot de passe |
  | 2 | **`MAIL_MAILER` n'est défini nulle part** — ni `.env` de production, ni `.env.example`, ni conteneur. `config/mail.php:4` retombe donc sur `'log'` | `env('MAIL_MAILER', 'log')` ; 7 clés `MAIL_*` ZeptoMail **complètes et valides** en production | **aucun courriel ne quitte le CRM** — ni **lien magique**, ni **réinitialisation de mot de passe** (les 3 seuls émetteurs du produit : `MagicLinkMail`, `MagicLinkService`, `PasswordResetController`) — **F40-002** |
  | 3 | Les deux voies de secours passent **toutes deux** par le courriel | `routes/api.php` : `POST /auth/magic-link`, `POST /auth/password/forgot` | **aucune porte de sortie** |

  Autrement dit : le mot de passe initial n'a été vu par personne, et **les deux mécanismes conçus
  pour en sortir sont muets**. La console est inaccessible à son propriétaire depuis trois mois.

- **Témoin négatif** : la configuration SMTP n'est **pas** absente — les sept clés ZeptoMail sont
  posées, complètes et valides en production. Ce n'est donc pas « l'e-mail n'a pas été branché », c'est
  **une seule clé manquante qui annule les sept autres, en silence**. Et le contrôle distingue : le
  compte **existe** et **porte un hachage** (`a_un_hash = t`) — ce n'est pas un compte absent ou cassé.

- ⚠️ **Un détail de ce diagnostic ne survit pas à la mesure, et je le corrige.** Le script
  `infra/scripts/definir-mot-de-passe-crm.sh`, arrivé sur `main` **pendant** cet audit, affirme que le
  mot de passe généré « a été écrit **dans `storage/logs`**, un fichier qui pèse aujourd'hui 263 Mo ».
  **Je ne l'y trouve pas** : `grep -aioE 'owner_initial_password|mot de passe (genere|initial)|initial
  password'` sur le `laravel.log` de production rend **zéro occurrence**. La raison est dans le code :
  `OwnerUserSeeder.php:164` emploie **`$this->command?->warn(...)`**, qui écrit sur la **sortie console
  d'Artisan**, pas dans `laravel.log`.
  **Conséquence pratique, et elle est meilleure que ce qui était craint** : le mot de passe n'est
  **pas** dans un fichier de 270 Mo lisible par tout ce qui accède au conteneur. Il est dans les
  **journaux de déploiement / la sortie du conteneur** de mai 2026 — un périmètre plus étroit, plus
  ancien, et probablement déjà tourné.
  *Cela ne change rien au verrouillage ; cela change l'évaluation de l'exposition, et donc la suite à
  donner.*

- **Impact**        : c'est le constat qui **relie** plusieurs autres et leur donne leur sens.
  - Il **explique** pourquoi tous les contrôles verts du produit ont été joués à un seul utilisateur —
    et donc pourquoi **A-010** (la sérialisation de la production) est resté invisible : *on ne
    découvre pas un problème de dix utilisateurs quand on n'en a jamais eu un seul*.
  - Il **redonne leur poids** aux constats d'interface : les 37 écrans n'ont jamais été ouverts en
    production par personne. « Ça marche » n'a jamais été vérifié par un usage.
  - Il **bloque le mandat lui-même** : le §11 exige d'ouvrir les 37 écrans à la main dans un vrai
    navigateur. Sans identifiants, un tiers de l'audit était impossible — c'est d'ailleurs ce que la
    session parallèle a écrit noir sur blanc en justifiant son script.
  - Et il **relativise** l'urgence d'autres constats : un produit où personne n'entre n'a pas fait de
    dégât. Mais il en fera dès la première connexion, et tous les défauts trouvés l'attendent.

- **Ce qui n'est PAS un défaut, et qu'il ne faut pas confondre** : `MAIL_MAILER = log` **est une
  décision explicite du dirigeant** (« reste `log` tant que le cahier des charges ne demande pas
  d'envoyer », journal du 19/08 §3.3 et §D). Cette décision est respectée et n'est pas rouverte
  (D-005). **Le défaut est ailleurs** : personne n'avait vu que cette décision, prise pour les envois
  *transactionnels métier*, coupait aussi les courriels **d'authentification**. Une décision dont on
  n'a pas nommé toutes les conséquences n'est pas une décision fautive — c'est une conséquence non
  nommée, et c'est le travail d'un audit de la nommer.

- **Correctif**     : trois gestes indépendants, et le premier est déjà fait par d'autres.
  1. **L'accès immédiat** : le script `definir-mot-de-passe-crm.sh` existe désormais, et il fait
     l'essentiel correctement — longueur minimale de 12, vérification par `Hash::check` après
     écriture, fichier temporaire retiré.
     ⚠️ **MAIS J'AVAIS TROP LOUÉ, ET L'AGENT 35 M'A REPRIS (`F35-007`).** J'écrivais que le mot de
     passe « traverse un tube, jamais un argument, donc absent de `ps` » — **c'est ce que l'en-tête du
     script promet, et ce n'est vrai qu'à moitié**. Il est bien **lu** sur l'entrée standard
     (`MDP="$(cat)"`, l.47), puis **repassé en clair à `docker exec -e CRM_MDP="$MDP"`** (l.106) —
     donc **dans l'`argv` du client Docker, visible dans `ps` par tout utilisateur de la machine**
     le temps de la commande. *J'avais lu l'en-tête et vérifié la lecture ; je n'avais pas suivi la
     valeur jusqu'à son usage.* **Correctif : passer par l'entrée standard de `docker exec` aussi.**
     **Rien à refaire par ailleurs, il faut le jouer — en connaissant cette réserve.** ⚠️ Il documente au passage un piège réel : la colonne s'appelle
     **`password_hash`**, pas `password`, et elle **n'est pas castée `hashed`** — écrire `$u->password`
     ne lève **aucune erreur** et ne fait **rien**.
  2. **La cause de fond** : poser **`MAIL_MAILER` explicitement** — à `log` si c'est bien la décision,
     mais **écrit**, pas subi par un défaut de framework. Une valeur implicite est une décision que
     personne ne peut relire.
  3. **La séparation qui manque** : distinguer le courrier **d'authentification** (qui doit partir, en
     toutes circonstances) du courrier **métier** (qui attend le CDC). Un `MAIL_MAILER` unique ne peut
     pas porter les deux politiques — Laravel permet de désigner un mailer par envoi.
  4. **La garde** : un contrôle de démarrage qui **rougit** si un produit dont la seule voie de
     récupération est le courriel a `MAIL_MAILER = log` en production. Elle doit mesurer **la
     configuration résolue de l'application qui tourne**, pas `.env.example` (piège 19, A-011).

- 🔑 **MESURE DÉCISIVE, ajoutée après coup (agent 22) — la chaîne est fermée de bout en bout, et elle est pire que ce que j'avais écrit.** Le mot de passe n'était pas le seul verrou :
  ```
  POST /api/v1/auth/login       -> 200   {"first_login_completed_at": null}
  GET  /api/v1/auth/me          -> 200   {"roles":["owner"]}
  GET  /api/v1/dashboard/stats  -> 403   first_login_required  ->  /auth/2fa/setup
  POST /api/v1/auth/2fa/setup   -> 500   column "two_factor_secret" does not exist
  ```
  **Témoin négatif** : un mauvais mot de passe rend **422**. L'authentification fonctionne donc pour
  de vrai.
  **On entre, et le premier écran réel est refusé — définitivement.** Le serveur **exige** l'enrôlement
  2FA avant tout usage ; l'enrôlement **écrit trois colonnes qui n'existent pas** (`A07-001`) ; et
  **aucun écran n'expose cet enrôlement** (`D22-001`, S0).
  **Conséquence pratique** : le script `definir-mot-de-passe-crm.sh`, posé pendant l'audit, **ne
  suffira pas** — il rend le mot de passe, **pas l'accès**. Les trois maillons doivent être corrigés
  ensemble.
- **Statut**        : **ouvert** — mot de passe rendu par la session parallèle ; **l'accès, lui, reste fermé**
- **Vérifié par**   : agent 22 — flux SPA complet rejoué, avec témoin négatif
- Réfuté par / Passe 3 : —

---

### [A-013] 🔴 La faille du 19 août était écrite, en toutes lettres, la veille — et une case ✅ a été posée par-dessus
- **Sévérité**      : **S1** *(constat de méthode — c'est le plus important de l'audit)*
- **Domaine**       : méthode / exploitation
- **Référence**     : `e8924b8` · `_REPORTS/2026-08-18_ETAT-PARE-FEU.md` vs le §4 du journal d'étape 0
- **Constat**       : le mandat d'audit (§3 bis, §10 piège 19) présente ce rapport comme l'exemple fondateur d'une **garde qui mesure le mauvais objet** : « il concluait que le pare-feu était en ordre. Il l'était — au niveau d'`ufw`. Le trou est en dessous. » **Ce n'est pas ce qui s'est passé.** Le rapport ne conclut rien du tout : il **refuse** de conclure, **prédit la faille exacte**, **donne le correctif**, **donne la commande qui la prouverait** — et **quelqu'un a coché la ligne ✅ par-dessus**.

- **Preuve**        : lecture du document, joué le 19/08. Il porte, dès sa ligne 11 :
  ```
  ## 🔴 AVERTISSEMENT — CE DOCUMENT NE CONSTATE RIEN SUR LE SERVEUR
     L'état réel du pare-feu de production n'a PAS été mesuré, faute d'accès.
  ```
  puis, plus bas — **la veille de la faille** :
  ```
  l.153  | postgres | 22-23   | "55432:5432" | **0.0.0.0** (toutes) |
  l.154  | redis    | 43-44   | "56379:6379" | **0.0.0.0** (toutes) |
  l.174  → En production, Postgres ecoute sur le port hote 55432 et Redis sur 56379
  l.188  Un `ufw deny` sur un port publie par Docker **ne bloque rien**.
  l.198       - "127.0.0.1:55432:5432"                    <- le correctif, ecrit
  l.212  POSTGRES_PASSWORD: axion_dev_only
  l.247  | 7 | POSTGRES_PASSWORD=axion_dev_only en production | rôle SUPERUSER+BYPASSRLS |
  l.335  Sortie saine : aucune ligne 0.0.0.0:55432 … Seuls 22, 80 et 443
  ```
  Il anticipe jusqu'à la notification CNIL, et se clôt par : *« tant que ce n'est pas fait, **F12 n'est PAS soldé** »*.

- **Témoin négatif** : le contrôle **discrimine**. Sur les 16 lignes de l'étape 0, l'agent 6 en trouve **7 réellement closes** au sens de leur propre critère de sortie — il ne conclut donc pas « tout est faux ». Et le document lui-même **ne prétend rien** : c'est bien l'écart entre lui et son résumé qui est mesuré, pas une faiblesse du document.

- **Impact**        : la conséquence est connue et chiffrée — **une base de 4 295 349 fiches, dont 1 319 567 personnes, joignable en superutilisateur depuis internet**, découverte le lendemain par hasard, en préparant autre chose. Le coût réel n'a pas été la difficulté technique : **le diagnostic était déjà écrit**. Le coût a été qu'un **tableau de synthèse a transformé un « je n'ai pas pu mesurer, et voici précisément ce qui va casser » en un ✅.**

- 🔑 **Et le motif se généralise — c'est là que ce constat devient structurel.** L'agent 6 a mesuré que **les artefacts de ce dépôt sont d'une honnêteté inhabituelle**, et que **ce sont les couches de résumé qui mentent** :
  - le README du harnais écrit noir sur blanc « **31 écrans sur 37 restent** » ;
  - `vitest.config.ts` documente lui-même que ses seuils de couverture sont « **DÉCORATIFS** » ;
  - `EtancheiteWorkspace` **avoue** la cécité de son scan (il écarte `audit_logs` parce que `relkind='p'`) ;
  - `deploy-staging.yml` écrit lui-même « **Coolify RETIRÉ, le CRM ne se déploie pas par Coolify** ».

  **Dans chacun de ces cas, l'information exacte existait, écrite, au bon endroit.** Ce qui a échoué,
  c'est la lecture — et le document qui résume. *Ce dépôt n'a pas un problème de mesure : il a un
  problème de clôture.*

- **Ce que cela corrige dans mon propre travail** : le constat **A-011** listait ce rapport comme le
  cas n° 5 de « gardes qui mesurent le mauvais objet ». **C'est retiré** : ce n'en est pas un.
  A-011 garde ses **sept** autres cas, tous vérifiés, et ils restent un défaut systémique réel. Mais
  le cas fondateur qu'invoquait le mandat relève d'un **second** patron, distinct et plus grave,
  parce qu'aucune garde technique ne l'attrape : **une case cochée sur un refus de conclure**.

- **Correctif**     : trois gestes, et aucun n'est technique.
  1. **Une ligne ne se coche pas sur un livrable qui dit ne pas avoir mesuré.** Règle à écrire : tout
     rapport portant « non mesuré », « à valider », « n'est PAS soldé » **bloque** la clôture de sa
     ligne — la clôture cite la mesure, ou n'a pas lieu.
  2. **Rejouer la clôture de l'étape 0** ligne à ligne contre le critère de sortie écrit : l'agent 6
     l'a fait, et trouve **7 CLOS · 7 PARTIELS · 2 OUVERTS** là où le journal annonce « 15 sur 16 ».
     **L'écart n'est pas un écart de travail — c'est un écart de clôture.** Le travail a été fait.
  3. **Appliquer la même règle à cet audit**, sans exception : aucun constat de ce registre n'est
     déclaré corrigé en P3 sans une mesure jouée en P4 par un **autre** agent. C'est déjà la règle 7 ;
     ce constat montre ce qu'il en coûte de l'oublier une fois.

- **Statut**        : **ouvert**
- **Vérifié par**   : agent 6 (mesure d'origine) ; document relu **ligne à ligne** par le chef de chantier avant publication.
- Réfuté par / Passe 3 : —
---
---

# P1 — CONSTATS DES AGENTS

> Un tableau par agent rendu. Le détail complet, les tableaux de grille et les preuves brutes sont
> dans `11_GRILLES/<rapport>.md` et `04_PREUVES/agent-NN/`. Le dédoublonnage et la renumérotation
> `A-NNN` se font en P2. **Aucun de ces constats n'est encore vérifié ni réfuté** : c'est le travail
> de P4 (rotation +17) et P5 (rotation +29).

---

## Agent 16 — journal d'audit, chaîne de hachage, registre AI Act
**Rapport** : `11_GRILLES/agent-16_audit-ai-act.md` · **Preuves** : `04_PREUVES/agent-16/` (7 fichiers)

À ce stade, **la récolte la plus grave de l'audit** : quatre S0, mesurés sur base jetable, avec
témoin positif ET négatif joués dans le même amorçage.

| Id | Sév. | Titre |
|---|---|---|
| **B16-001** | **S0** | La chaîne d'audit est hachée **sans secret** : `AUDIT_HASH_CHAIN_SECRET` est la chaîne vide (longueur = 0) — qui écrit en base peut recalculer toute la chaîne |
| **B16-002** | **S0** | **Supprimer la dernière ligne ne rompt pas la chaîne** : le journal n'est pas append-only, il est tronquable par la queue — le contrôle rend `code 0, « OK »` |
| **B16-003** | **S0** | `created_at` n'entre pas dans le hachage : un horodatage passé de 2026 à **2019** laisse la chaîne « OK », alors que le §20 exige un journal *horodaté* |
| **B16-004** | **S0** | `GET /audit-logs` rend le journal de **tous les espaces** à **tout compte authentifié** : 0 `where workspace_id`, 0 scope global, 0 politique RLS sur `audit_logs` ni ses 14 partitions, et `AuditLogPolicy::viewAny` **n'est jamais appelée** |
| **B16-005** | **S1** | L'empreinte de corps d'une connexion **contient le mot de passe**, et elle est servie à tout compte authentifié |
| **B16-006** | **S1** | Le contrôle d'intégrité planifié à 03:00 **n'avertit personne** : `output = /dev/null`, 0 `afterCallbacks`, aucune destination Sentry configurée |
| **B16-007** | **S1** | Le registre AI Act est **vide (0 ligne)** et `AiActRegisterController::index()` rend `['data' => []]` **en dur**, sans aucune requête SQL — alors que le §21.4 du CDC affirme qu'il « existe déjà » |
| **B16-008** | **S1** | Les **quatre exports de données nominatives** ne laissent aucune trace : le middleware ne capte que POST/PUT/PATCH/DELETE, donc **50 routes `GET` sur 111 sont muettes** |
| **B16-009** | **S1** | Suivre le runbook « disque plein » (`infra/runbooks/02-disk-full.md` §3) rend le contrôle d'intégrité **définitivement rouge** |
| B16-010 | S2 | `user_agent` n'entre pas dans le hachage |
| B16-011 | S2 | L'écran « Journaux d'audit » affiche **cinq colonnes qui n'existent ni en base ni dans l'API** |
| B16-012 | S2 | Les commandes destructives planifiées n'écrivent rien au journal, sauf deux |
| B16-013 | S3 | Une ligne insérée sans `prev_hash` fait crier « falsification » sans qu'il y en ait eu |
| B16-014 | S3 | Deux commentaires décrivent un état révolu ; un runbook renvoie à `audit:checkpoint`, commande inexistante |

**Chiffres retenus** : **13 écritures sensibles journalisées sur 25**. Sept gestes exigés par le §20
(cycle de vie des comptes, rôles, permissions, sessions) **n'existent pas du tout** —
`UsersController::store/update/destroy` rendent **501**, et le fichier ne contient aucune occurrence
de `role` ni `permission`. Journal réel : **80 lignes**, toutes `POST`, **72/80 sans espace de
travail**, **52/80 sans acteur**. `ai_act_register` = 0 · `llm_usage` = 0 · `business_events` = 0.

⚠️ **RÉSERVE LEVÉE — et B16-001 est RÉFUTÉ pour la production.** L'agent 16 avait honnêtement borné
son constat à l'atelier, faute d'accès. L'agent 40, qui avait l'accès, a mesuré **deux fois** et
**sans jamais afficher la valeur** : `AUDIT_HASH_CHAIN_SECRET` fait **64 caractères** en production
(`wc -c` sur l'environnement du processus, puis `env()` dans l'application démarrée ; `=== ''` → non ;
`=== 'dev-only-secret-change-me'` → non).

> **B16-001 ne s'applique donc PAS à la production. Il reste vrai en local**, où le secret est vide —
> ce qui signifie que **les tests de la chaîne d'audit tournent, en local et en CI, sur une
> configuration qui n'est pas celle de la production**. C'est le même motif que **B11-010**
> (cloisonnement) et **A05-008** (fuseau horaire) : *l'atelier et la production ne se comportent pas
> pareil, et c'est l'atelier qui sert de référence aux gardes.*

**Ce qui reste entier**, en revanche : **B16-002** (journal tronquable par la queue), **B16-003**
(horodatage hors hachage) et **B16-004** (fuite inter-espaces) **ne dépendent d'aucun secret** — ils
tiennent en production comme ailleurs. Trois S0 sur quatre survivent.

---

## Agent 5 — le plan du 13 août, lot par lot
**Rapport** : `11_GRILLES/agent-05_plan-13-aout.md` · **Preuves** : `04_PREUVES/agent-05/` (10 fichiers)

**65 livrables re-prouvés** sur 12 blocs : **46 LIVRÉ · 7 PARTIEL · 9 NON LIVRÉ · 2 DÉCLARÉ À TORT ·
1 non vérifié** (la suite Pest, que l'agent a **refusé** de mesurer : le conteneur portait plus de
vingt processus concurrents d'autres agents, dont un `migrate:fresh` — refus honnête, et juste).

| Id | Sév. | Titre |
|---|---|---|
| **A05-001** | **S1** | La fiche 360° et le rapprochement par `person_key` sont **inatteignables** : **0 contact sur 1 319 567** porte une clé — dont **410 481 ont pourtant un e-mail** — et aucun backfill n'existe |
| **A05-003** | **S1** | Cinq jours après activation, la synchro site → CRM n'a créé **aucune** fiche : **3 événements sur 3** tombés en arbitrage manuel faute de SIREN |
| A05-002 | S2 | `first_info_at` (article 14 RGPD) : deux colonnes livrées et commentées, **aucun écrivain ni lecteur** dans tout le dépôt, 0 ligne renseignée en production |
| A05-004 | S2 | Le cycle de vie business n'a **jamais** changé d'état : **4 295 349 fiches à `nouveau`** ; la règle « client → dormant » n'est implémentée nulle part |
| A05-005 | S2 | La conception console UX v2, référentiel n°4 de l'ordre de mission, décrit une navigation qui **n'a jamais existé** |
| A05-006 | S2 | Le vivier candidats — objet central du plan — est **vide** (`candidates` = 0) cinq jours après ouverture du flux, et **rien ne signale ce silence** |
| **A05-009** | **S2** | **14 des 16 spécifications Playwright ne sont exécutées par aucun pipeline**, dont `console-locale.spec.ts`, seule couverture automatisée des 4 écrans de la console v2 |
| A05-007 | S3 | Deux numérotations de lots coexistent dans les documents de référence, sans table de correspondance |
| A05-008 | S3 | Le correctif du décalage de 2 h est **actif en production** alors que le plan affirme « RIEN N'EST APPLIQUÉ », et **inerte en local** |

**Au crédit du chantier, re-prouvé et non cru** : Gate 0 est réel (CI bloquante, `needs: [ci]`,
`migrate --force` sans `|| true`) ; **780 tests / 6 503 assertions** et PHPStan `[OK] No errors` sur un
run CI du 19/08 ; PR #53→#65 toutes fusionnées ; durcissement RLS P0 livré ; **les 9 drapeaux CRM sont
bien à `true` en production** (vérifié par `docker inspect`, pas cru sur parole) ; backfill des tags
`src:` = **4 294 895 lignes**, au chiffre près de ce qui était déclaré.

---

## Agent 9 — écarts document ↔ code
**Rapport** : `11_GRILLES/agent-09_ecarts-documents.md` · **Preuves** : `04_PREUVES/agent-09/` (6 fichiers)

**70 documents** au périmètre, **22 mesurés par commande jouée**, 48 inventoriés sans mesure (nommés
au §4 de son rapport ; ses délégations ont échoué sur le plafond de 20 agents simultanés).
**51 affirmations fausses**, dont **12 qui feraient prendre une mauvaise décision**.

| Id | Sév. | Titre |
|---|---|---|
| **A09-001** | **S1** | `_AUDIT/DEPLOY-PIPELINE.md` décrit une commande de déploiement qui n'est pas celle qui tourne, et **omet `--no-deps`** : il laisse croire qu'un changement de compose sur `postgres`/`redis`/`reverb` est déployé. **C'est exactement ce qui a laissé la faille du 19/08 ouverte sous un déploiement vert.** |
| **A09-002** | **S1** | `_REPORTS/PROGRESS.md`, **lié depuis `README.md`**, annonce S3→S12 « pending » alors que `README.md` annonce 12/12 livrés |
| **A09-005** | **S1** | Le commentaire de `.gitattributes` affirme « plus de divergence » ; **8 scripts `.sh` sur 16 sont encore en CRLF** *(recoupe A-003)* |
| A09-003 | S2 | L'inventaire de l'étape 1a déclare absentes des activités et motifs qui **existent en base depuis le même jour** — et c'est ce document qui **fixe l'ordre des pièces** |
| A09-004 | S2 | L'inventaire de l'étape 1a déclare `saved_views` sans contrôleur ni route ; **les deux existent** *(confirme A-002)* |
| A09-006 | S2 | `CONTRIBUTING.md` présente comme « quality gates » deux seuils de couverture que la CI **ne mesure jamais** (`ci.yml:245` pose `coverage: none`) |
| A09-007 | S2 | Le runbook de la console locale fait démarrer la pile depuis **le worktree résiduel `crmpro-wt-etape0`**, alors que le fichier est versionné à la racine |
| **A09-008** | **S2** | Trois documents annoncent « RLS sur 30 tables » ; mesuré : **55 tables avec RLS sur 72 portant `workspace_id`** — et **`audit_logs`, `audit_logs_default`, `sessions`, `user_workspaces` n'en ont pas** *(recoupe B16-004)* |
| A09-009 | S2 | `TODO.md` se déclare « source de vérité » et décrit un dépôt **d'avant la première ligne de code** |
| A09-010 → A09-013 | S3 | Commandes de test prescrites inexécutables · `spec/00_INDEX.md` faux jusqu'à un facteur 2,4 · commentaires de `routes/console.php` déclarant inexistantes des commandes qui existent · journal d'étape 1a à SHA périmé |

**Deux avertissements de méthode adoptés par le chef de chantier** :
- `grep -c $'\r'` **n'est pas fiable selon les shells de ce poste** — il a « prouvé » 15/15 à tort.
  Méthode retenue : comptage des **octets `0x0d`**, **validée sur un témoin pur LF et un témoin pur
  CRLF** avant usage. C'est ainsi que A-003 a été re-mesuré (8/16).
- **La base locale `axion_crm` est mutée en concurrence** : toute mesure en base doit être horodatée
  et recroisée avec `select count(*) from migrations` = 58.

---

## Agent 47 — dépendances et vulnérabilités
**Rapport** : `11_GRILLES/agent-47_dependances.md` · **Preuves** : `04_PREUVES/agent-47/` (16 fichiers)

**Le chiffre du mandat est exact au chiffre près : 57 alertes ouvertes (4 critiques, 18 hautes,
31 moyennes, 4 basses).** Deux faits qu'il ne dit pas : **57/57 sont npm** (zéro composer, zéro
docker, zéro github-actions), et **57/57 ont été créées le 2026-08-19**, les alertes n'ayant été
activées que la veille.

🔑 **Combien sont réellement atteignables en production : ZÉRO**, par trois mesures indépendantes.
- **32 alertes (56 %, dont 2 critiques et 8 hautes) viennent de `workers/`, qui n'est déployé par aucun compose hors tests** — et les 8 workflows `prospection-*.yml` passent tous par `php artisan`, **0 par Node**.
- **Les 4 critiques sont la même CVE** (`vitest`, CVE-2026-47429), qui exige le serveur d'interface Vitest : `@vitest/ui` **n'est installé nulle part** et `--ui` n'apparaît dans aucun fichier.
- **11 alertes seulement** touchent le seul artefact livré. `vite build` rejoué, marqueurs comptés : `follow-redirects`, `httpAdapter`, `getBoundary`, `formDataToJSON` = **0** (témoin positif : `XMLHttpRequest` = 16). Toutes se corrigent par **une seule** montée mineure, `axios 1.16.1 → 1.18.0`, dans l'intervalle `^1.7.0` déjà déclaré.

🔴 **L'hypothèse du mandat sur les 20 PR est RÉFUTÉE.** Elles n'ont pas été « fermées sans
traitement » : elles ont été fermées **par Dependabot lui-même**, le 18/08 entre 18:44:47Z et
18:44:49Z, après le gel des 5 écosystèmes (`fccc9d1`), sous une **politique écrite, datée et
chiffrée** de 441 lignes (`_REPORTS/2026-08-18_POLITIQUE-DEPENDANCES-ETAPE-0.md`) qui donne le sort
de chaque PR. **16 des 20 étaient des majeures ; aucune n'était corrective de sécurité.** Et **0
branche `dependabot/*`** ne subsiste sur `origin` — ma propre mesure de 09:26Z en comptait 20 :
elles ont été supprimées entre-temps.

| Id | Sév. | Titre |
|---|---|---|
| **H47-001** | **S1** | Le job `composer-audit` de `security.yml` **n'a jamais audité un seul paquet PHP** — il sort `No installed packages found` et rend `success`. Témoin négatif fourni : la même commande en `--locked` trouve **18 avis** sur un guzzle volontairement ancien |
| **H47-002** | **S2** | Les deux jobs `pnpm-audit` affichent 31 et 33 vulnérabilités **puis rendent `success`** ; et le job `alerte` dépend de leur `failure()` — il **ne peut donc jamais** se déclencher sur une vulnérabilité |
| **H47-005** | **S2** | `workers/` — **32 des 57 alertes** — n'est déployé par aucun compose hors tests, alors que son image est construite à chaque déploiement de préproduction et scannée par Trivy. **Trancher son sort éteint 32 alertes sans monter une seule dépendance** |
| H47-003 | S3 | La prémisse écrite du gel (« les alertes Dependabot sont DÉSACTIVÉES », « précaution INERTE ») est fausse depuis le 19/08 : le critère d'entrée du dégel est **déjà rempli** |
| H47-004 | S3 | `poc/05_dedup_performance/pnpm-lock.yaml` porte 1 alerte, hors des 5 `directory:` de `dependabot.yml` et hors de la matrice `pnpm-audit` : **aucune PR ne sera jamais proposée** |

**Le piège Stripe du mandat ne s'applique pas à ce dépôt** : il n'y a aucun SDK Stripe dans le CRM
(il vise le dépôt Axion-IA). **7 autres contrats de version figée** ont été trouvés, dont le gel des
5 écosystèmes, `postgis/postgis` majeure interdite à titre permanent, et `ARG PLAYWRIGHT_VERSION`
sous garde CI bloquante.

**À re-mesurer le lundi suivant, après 06:00 UTC** : `automated-security-fixes` est actif et n'a
produit **0 PR en 24 h**. Si le compte reste 0, c'est un **S1** — le gel aurait alors coupé en
silence le canal de sécurité qu'il prétendait préserver.

---

## Apport hors périmètre — inventaire du canal, côté site
*(Produit par un agent auxiliaire du bloc B, remonté directement au chef de chantier.
À contre-vérifier côté CRM par l'agent 13 — il n'est pas repris tel quel.)*

**Le point le plus grave, et il est de nature RGPD.** L'opposition « vivier » émise par le site
(`Axion-IA/axionia/src/server/vivier/opposition.ts:66`) envoie `consent.version` **lu en base sur la
ligne `JobApplication`**. Pour les **71 candidatures du stock historique**, cette valeur est
`careers-v1-2026-06-09` — qui n'est **pas** dans la liste fermée
`Taxonomy::CANDIDATE_CONSENT_VERSIONS_V2` du CRM.

> **Si le CRM valide `consent.version` pour tout événement d'univers vivier, et pas seulement pour
> `application_submitted`, une opposition part en 422 puis en `gave_up`.**
> **Refuser un opt-out pour cause de version de consentement périmée est l'inverse de ce qu'il faut.**

🔴 **RÉFUTÉ PAR L'AGENT 13 — et c'est moi qui avais mal lu.** J'avais étayé cette hypothèse sur une
ligne trouvée dans le journal applicatif **local** :
`Fiche candidat refusée : consentement v2 requis (…), reçu : careers-v1-2026-06-09.`
L'agent 13 a établi que cette ligne **ne vient pas de trafic réel** : elle n'apparaît que dans
`_REPORTS/e2e2-preuves/pest-*.txt` et les journaux de CI — ce sont les **témoins négatifs de la suite
de tests**. Et la suite Pest s'exécutant **dans le même conteneur**, elle écrit dans **le même
`laravel.log`** que j'ai lu. *J'ai pris la preuve qu'une garde fonctionne pour la preuve qu'un défaut
existe.*
Il a par ailleurs montré que la seconde ligne que j'avais versée au dossier — `Version de schéma pivot
non supportée` — **n'appartient pas à ce canal** : elle vit dans `app/Crm/Scraping/ScrapedRecord.php:123`,
canal **collecte**, sous le message `scraper-result refusé`.
**Mesure de l'agent 13, point 9 : zéro événement légitime rejeté** — les 3 émetteurs du site couvrent
exactement les 3 versions de la liste fermée. **Hypothèse close, pas de constat.**
*(L'angle mort côté site — la valeur **dynamique** lue sur `JobApplication.consentVersion` pour les
71 candidatures du stock — reste à trancher par l'**agent 31**, qui travaille sur le dépôt du site :
c'est un chemin distinct de ceux qu'a mesurés l'agent 13.)*

Autres éléments versés au dossier du bloc E :
- Le site émet **9 valeurs de `consent.version`** (8 constantes + 1 dynamique) ; le CRM n'en accepte
  que **3** pour le vivier. Et `v1-2026-05-24` avec `form_type = recrutement` **bascule en univers
  vivier** tout en portant une version non-v2.
- **5 valeurs de `source_slug`** seulement (`calendly`, `site-candidature-offre`,
  `site-candidature-commerciale`, `site-formulaire-podcast`, `chatbot`). Le formulaire unifié
  (12 finalités), le simulateur ROI, la newsletter, les avis et l'opposition vivier **n'en envoient
  aucun** : le CRM ne saura pas d'où vient un lead du formulaire principal.
- Le champ `tags[]` du contrat existe côté site mais **aucun émetteur ne le renseigne** : code mort.
- Les 4 événements `calendly_*`, le chatbot et `newsletter_optout` **n'envoient aucun bloc `consent`**.
- Côté visibilité, en revanche, le canal est honnête : un 422 **est** visible (compteur « Abandons
  définitifs » en rouge, ligne détaillée avec le payload complet, alerte Telegram). Mais la
  déduplication est **horaire** : 40 abandons dans la même heure ne produisent **qu'un seul** message.

---

## Agent 11 — cloisonnement par espace de travail, table par table
**Rapport** : `11_GRILLES/agent-11_cloisonnement.md` (538 l.) · **Preuves** : `04_PREUVES/agent-11/` (11 fichiers, dont 3 scripts SQL rejouables)

**Méthode retenue, et elle est exemplaire** : 2 lignes semées dans 2 espaces différents sur **57/57**
tables — donc **aucune table vide**, donc **aucun contrôle vrai par vacuité**. C'est le témoin négatif
que la règle 3 exige, et il est joué avant les conclusions, pas après.

**Chiffres** : **101 tables auditées** (114 en fin de session — 13 partitions d'`audit_logs` sont
apparues pendant la mesure) · **59** portent une colonne d'espace · **55** portent `ENABLE` **et**
`FORCE ROW LEVEL SECURITY` · **54/57** rendent 0 ligne sans contexte · **55/57** ne rendent que
l'espace demandé.

| Id | Sév. | Titre |
|---|---|---|
| **B11-001** | **S0** | **26 des 33 tâches planifiées** touchant une table cloisonnée s'exécutent **sans aucun contexte d'espace** — seules `rgpd:purge-vivier` et `rgpd:purge-business-prospects` en posent un |
| **B11-002** | **S0** | **5 jobs sur 6** s'exécutent sans contexte, alors que `Queue::looping` l'efface avant chacun |
| **B11-003** | **S1** | `retention:purge` purge **tous les espaces ou aucun** |
| **B11-006** | **S1** | `audit_logs` et ses **14 partitions** n'ont **aucune** RLS *(recoupe B16-004 et A09-008)* |
| B11-004 | S2 | `email_verification_logs` : une policy permissive `COALESCE` a survécu — **2 lignes visibles sans contexte** |
| B11-005 | S2 | Base de test **unique codée en dur** : trois agents lançant des tests dessus se détruisent mutuellement |
| B11-007 | S2 | **11 tables portant des données personnelles n'ont aucune colonne d'espace** : `crm_outbound_events`, `deal_history`, `dnc_entries`, `email_messages`, `email_sequences`, `email_suppressions`, `email_validations`, `linkedin_health_checks`, `linkedin_sequence_runs`, `opt_out`, `analytics_funnel_snapshots` |
| B11-009 | S2 | L'échappatoire explicite `runWithoutScope()` n'a **aucun appelant** |
| **B11-010** | **S2** | 🔑 **L'atelier local n'arme aucun des deux dispositifs de cloisonnement** : `CRM_DB_APP_ROLE_ENABLED` = `false` en local, **`true` en production** |
| B11-008 | S3 | Le commentaire « permissive si non défini » de `health_practitioners` est **faux** et sert de modèle dans la migration |
| B11-011 | S3 | Quatre événements diffusés re-résolvent leurs modèles dans le worker, sans contexte |

🔑 **Le fait qui commande tout le reste : local et production n'exécutent pas le même cloisonnement**
(B11-010). Toute mesure d'étanchéité faite en local est donc, par construction, une mesure **d'autre
chose**. C'est une variante exacte du piège 19 — *une garde peut mesurer le mauvais objet* — appliquée
cette fois à l'atelier lui-même. **Conséquence pour l'audit** : les verdicts d'étanchéité doivent être
rejoués sur une pile configurée comme la production, ou déclarés non concluants. Porté en P2.

**Deux points d'honnêteté de l'agent, retenus tels quels** :
- Le point chaud `health_practitioners` du mandat (« permissive si non défini ») est un **faux positif** :
  le commentaire décrit une policy remplacée le 14/08 — mesuré, 0 ligne sans contexte, 1 avec.
- **Il n'a pas obtenu de rouge provoqué propre.** `EtancheiteParTableTest` sortait déjà 4 échecs sur 11
  sur `main` **sans qu'on ait rien cassé** — mais parce que trois agents partageaient la même base de
  test (B11-005). L'agent le déclare comme un manque plutôt que de présenter ce rouge comme le sien.
  **À rejouer en P4, sur une base isolée.**

**Recensement complet du patron « contexte perdu après la réponse »** (ce que le chef de chantier avait
demandé de chercher au-delà du cas déjà corrigé) : `Cache::flexible` **1** occurrence, corrigée ·
`dispatchAfterResponse` / `defer()` / `App::terminating` / `register_shutdown_function` : **0** ·
middleware `terminate()` après `SetCurrentWorkspace` : **0**. Le trou n'est donc pas là où on le
cherchait : il est dans les **tâches planifiées** et les **jobs**.

---

## Agent 17 — les 35 tâches planifiées, 6 jobs, 49 commandes
**Rapport** : `11_GRILLES/automatismes.md` (490 l.) · **Preuves** : `04_PREUVES/agent-17/` (13 fichiers)

**91 objets, 91 audités.** Décompte refait par l'agent lui-même : **35** `Schedule::command` (le mandat
en nommait **10**), **49** commandes, **6** jobs.

**Classement des 49 commandes** : **44 utilisées** · **1 morte** (`media:import-press-kit`, 449 lignes,
zéro référence dans tout le dépôt) · **4 candidates mortes** · **14 dangereuses, dont 11 sans aucun test**.

| Id | Sév. | Titre |
|---|---|---|
| **B17-001** | **S1** | `retention:purge --dry-run` **exécute réellement** l'`UPDATE` qu'il prétend seulement compter — et rapporte « 0 » |
| **B17-012** | **S1** | Deux purges de `companies` sans contexte d'espace, sans `--dry-run`, sans journal, sans test — **déjà jouées 5 fois en production** le 2026-07-04 ; `WHERE legal_form IS NULL` supprime **sur une absence de donnée** |
| **B17-009** | **S1** | Les deux seules purges RGPD correctement construites **ne s'exécutent jamais** : l'échéance CNIL 2 ans / 3 ans n'est tenue par **aucun** automatisme |
| **B17-010** | **S1** | Le trait `RunsInWorkspace` n'est **jamais emprunté** : les 5 points de dispatch d'`EnrichCompanyJob` omettent le `workspaceId`, les 5 autres jobs ne l'utilisent pas *(recoupe B11-002)* |
| **B17-008** | **S1** | Les 3 imports médias hebdomadaires font `DELETE` + réinsertion : **l'enrichissement de la semaine est détruit** et les journalistes détachés (`confdeltype='n'` mesuré) |
| **B17-003** | **S1** | Les 35 tâches n'ont **aucun** canal d'échec : sortie dans `/dev/null`, 0 `onFailure` ; l'alerte de `audit:verify-chain` et celle d'`anomaly:detect` sont **des commentaires** *(recoupe B16-006)* |
| **B17-002** | **S1** | Mutex `withoutOverlapping()` à **TTL 24 h** mesuré, et le déploiement tue le planificateur en pleine tâche : **10 s de grâce contre 1 min 43 s mesurées** |
| B17-004 | S2 | Trois tâches s'auto-sautent **depuis l'intérieur de la commande** — invisibles dans `schedule:list` |
| B17-005 | S2 | `rgpd:anonymize-ips` réécrit **chaque nuit tout l'historique** `audit_logs` (clause non convergente) |
| B17-006 | S2 | Sept tâches sans aucun verrou, dont un `REFRESH MATERIALIZED VIEW` **non concurrent** chaque heure |
| B17-011 | S2 | `MonitorCampaignProgressJob` a `tries=1` : une exception fige la campagne en `running` **pour toujours**, sans alerte |
| B17-013 | S2 | 24 des 35 tâches sans test ; 11 des 14 destructives sans test ; le trait `RunsInWorkspace` sans test |
| B17-014 | S2 | `prospection:reclassify-size/--sector --all` réécrivent **toute** la table `companies` en une requête (`WHERE 1=1`), sans bornage ni contexte, **depuis un bouton GitHub** |
| B17-007 | S3 | Le commentaire de `console.php` affirme un auto-skip qui n'a plus lieu |

🔑 **« Les tâches qui s'auto-sautent » : il y en a 9, pas 1.** Le mandat n'en nommait qu'une
(`companies:rescrape-archives`, censée se sauter « si la commande n'existe pas »). Mesuré :
**la commande existe** (`array_key_exists(...)` → `true`), donc **le commentaire de `console.php:29-32`
ment**. Et trois autres se sautent **depuis l'intérieur de leur propre corps**, invisibles à la fois
depuis `console.php` et depuis `schedule:list` : `blacklists:check` (horaire) et `signals:nightly-scan`
(nocturne) ont pour corps entier `if (env('MOCK_MODE', true)) return SUCCESS`, et
`journalists:scrape-ours` (quotidienne) dépend de `MEDIA_JOURNALISTS_ENABLED`, défaut `false`.
**Ce sont les pires : elles s'exécutent, rendent `SUCCESS`, et ne font rien.** C'est la définition
même du « vert qui ne témoigne de rien ».

**Deux points d'honnêteté de l'agent, retenus** :
1. **Le planificateur de production n'a pas pu être vérifié** — le SSH dont il disposait mène au VPS
   du site, pas à celui du CRM. Il l'écrit plutôt que de conclure. *(Confié à l'agent 40, qui a
   l'accès.)* Fait ajouté au passage : `docker-compose.prod.yml:80-88` pose `healthcheck: disable: true`
   sur `scheduler` — **rien ne dirait qu'il est mort.**
2. La base locale étant vide et **aucune table de suivi d'exécution n'existant** dans le schéma, les
   colonnes « durée » et « coût » sont marquées **non vérifiées pour 33 des 35 tâches** plutôt que
   remplies au jugé. C'est exactement ce que le §5 du mandat demande : une case « non vérifié — raison »
   est honnête, une case inventée ne l'est pas.

---

## Agent 14 — le canal sortant CRM → site
**Rapport** : `11_GRILLES/agent-14_canal-sortant.md` (579 l.) · **Preuves** : `04_PREUVES/agent-14/`

**Le mandat annonce « 3 seuls événements ». Le 3 est le vocabulaire déclaré, pas le nombre émis :**

| Compte | Valeur |
|---|---|
| Types **déclarés** (constante PHP + `CHECK` Postgres) | **3** |
| Types ayant un **producteur** dans le code | **2** |
| Types **atteignables par un humain** depuis la console | **1** (`erasure`) |
| Types ayant un **effet réel** côté site | **1** (`consent_optout`) |
| Événements **exigés par le §22.2**, sens CRM → console | **19** |

| Id | Sév. | Titre |
|---|---|---|
| **B14-002** | **S0** | 🔴 **`erasure` traverse, le site répond « 200 applied », et rien n'est effacé** — même branche que l'opt-out ; seul effet réel : désabonner d'une newsletter |
| **B14-013** | **S1** | En production, le canal est **ouvert dans le sens site → CRM et fermé dans le sens CRM → site** |
| **B14-005** | **S1** | Le site **ne renvoie jamais 503** : la garde de temporisation est morte, et **3 h 02 de panne réseau suffisent à perdre un événement définitivement** |
| **B14-003** | **S1** | La file morte (`gave_up`) n'est visible **sur aucun écran du CRM**, et n'est **jamais reprise** — même remise « due » de force |
| **B14-004** | **S1** | Le sens CRM → site n'a **aucune alerte, dans aucun des deux dépôts**. `CRM_SYNC_ALERT` **n'existe pas** dans le CRM (5 occurrences, toutes documentaires) |
| **B14-009** | **S1** | Le « batch de réconciliation quotidien » que le code **promet en commentaire** n'existe pas |
| **B14-010** | **S1** | L'effacement d'un journaliste (`DELETE`, documenté « droit à l'effacement ») **n'émet rien** — dans le contrôleur même qui émet pour l'opposition |
| B14-001 | S1 | 3 événements déclarés, 2 produits, 1 atteignable par un humain |
| B14-011 | S2 | **5 des 6 familles du §22.2 n'ont aucun émetteur** — 2 événements sur 19 |
| B14-006 | S2 | Rien ne détecte l'arrêt de `crm:flush-outbound` ; `withoutOverlapping()` sans argument = **verrou 24 h**, soit **288 passages sautés en silence** |
| B14-007 | S2 | Canal **mono-destination** pour un CRM multi-espaces : table sans `workspace_id`, **RLS absente** (mesuré `f\|f`), `scope` codé en dur `'business'`, une seule URL |
| B14-008 | S2 | Contrat versionné **à l'entrée, pas à la sortie** ; aucun test croisé entre les deux dépôts |
| B14-012 | S2 | `person_key` prévu, **jamais renseigné**, et **jeté par le site** *(recoupe A05-001)* |
| B14-014 | S3 | L'anti-doublon peut écraser une décision plus récente |

**Ce qui devrait traverser et ne traverse pas** — la liste est longue et c'est le livrable central :
effacement d'un journaliste · réinscription (inexistante) · opposition depuis un écran (**aucun
bouton n'appelle la route**) · opposition et effacement d'un candidat du vivier · correction de
coordonnée (**501**) · suppression d'un contact (**501**) · rapprochement de fiche · fusion de fiches ·
actions de masse · suppression d'entreprise · purges RGPD mensuelles · et **15 événements métier**
(opportunité, devis, facture, réclamation, rendez-vous interne, compte rendu, décision d'embauche…)
qui n'ont **aucun modèle**.

**Et ce qui traverse sans avoir l'effet annoncé** : `erasure` (rien) · `consent_optout` sur un
non-abonné → `no_match` **et 200** · tout `scope: vivier` → **ignoré** · `person_key` reçu **puis jeté**.

**Symétrique Calendly** : le site émet bien `calendly_canceled` / `no_show` / `completed` ; **le CRM
ne renvoie rien**, alors que le §22.6 du CDC promet « le statut redescend vers la console tout seul ».

🔑 **Un cas de piège 19 attrapé en flagrant délit.** L'agent a rejoué `CrmOutboundTest` : **15 passés,
52 assertions**, dont une garde nommée « *un 503 fait ATTENDRE sans consommer de tentative* ».
Cette garde est **verte et mesure le mauvais objet** : elle exerce, via `Http::fake`, une réponse
**que le site n'émet jamais**. C'est exactement le motif de la garde `config-prod` du 19/08, retrouvé
indépendamment sur un autre sujet. **Il faut le chercher partout** — porté au périmètre de l'agent 45.

---

## Agent 44 — le harnais de tests : ce qui existe, ce qui s'exécute
**Rapport** : `11_GRILLES/agent-44_harnais-tests.md` (318 l.) · **Preuves** : `04_PREUVES/agent-44/` (15 fichiers, journaux CI complets)

| Suite | Existe | S'exécute | Résultat mesuré |
|---|---|---|---|
| **Pest** | 95 fichiers | ✅ CI bloquante **et requise** | **780 tests, 6 503 assertions, 39,31 s**, 0 échec, 0 ignoré |
| **Vitest frontend** | 21 fichiers | ✅ CI requise | **118 tests, 118 passés** |
| **Vitest workers** | 6 fichiers | ✅ CI requise | **61 tests, 61 passés**, 7,32 s |
| **Playwright** | 16 specs / **285 tests** | ⚠️ **2 specs sur 16**, chromium seul | **18 tests joués** sur 285 |

| Id | Sév. | Titre |
|---|---|---|
| **H44-003** | **S1** | `deploy-staging.yml` **déploie la préproduction à chaque poussée sur `main` sans aucun test** — pas de `needs: ci` |
| **H44-004** | **S2** | Le harnais n'a **aucune isolation** : `tests/bootstrap.php` épingle **toute** exécution sur l'unique `axion_crm_test`, où `RefreshDatabase` fait `DROP TABLE … CASCADE`. **Deux personnes ne peuvent pas lancer les tests en même temps** *(recoupe B11-005)* |
| **H44-001** | **S2** | 14 des 16 specs Playwright ne sont exécutées par aucun workflow : **267 des 285 tests ne tournent nulle part** *(confirme A05-009 et le chiffre)* |
| **H44-002** | **S2** | Le seul job qui exécute Playwright **n'est pas une vérification requise** : le « BLOQUANT » écrit dans `a11y.yml` **ne bloque rien** |
| **H44-006** | **S2** | **6 écrans sur 37** sont montés par un test ; **10 sur 37** touchés par un test qui s'exécute en CI ; **27 sur 37 par rien** |
| **H44-011** | **S2** | La CI est **épinglée sur l'ordre qui passe** (`executionOrder` fixe) : le couplage entre tests n'est plus mesuré par aucune porte |
| H44-005 | S3 | `memory_limit=128M` non déclaré dans le dépôt, pour une suite de 780 tests |
| H44-007 | S3 | **Aucune couverture produite ni évaluée** : seuils Vitest décoratifs, `coverage: none` côté PHP *(confirme A09-006)* |
| H44-008 | S3 | `a11y.yml` : `pnpm install --frozen-lockfile \|\| pnpm install` **rattrape en silence** une dérive du lockfile |
| H44-009 | S3 | `MOCKS-STRATEGY.md` décrit `DnsManager`/`EmailSender` qui n'existent pas |
| H44-010 | S3 | `workers/vitest.config.ts` ne collecte que `tests/` : un test placé sous `src/` serait ignoré **sans un mot** |

**Le résultat le plus utile de cet agent est ce qu'il a RÉFUTÉ**, et il faut le retenir :
- **Zéro exclusion silencieuse dans les configurations** : 0 `<exclude>`, 0 `@group`, 0 `->skip()`,
  0 `it.skip`, 0 `test.todo`, 0 `.only`, 0 `testIgnore`. `phpunit.xml` et `phpunit-ci.xml` ne diffèrent
  que par `executionOrder` : **la quarantaine est réellement levée**. Les exclusions de ce dépôt sont
  toutes **hors configuration** — ligne de commande, protection de branche, absence de porte.
- **`navigation.spec.ts` n'est plus rouge en silence** (14 verts depuis `da97826`) : le piège 13 du
  mandat est **périmé** sur ce point.
- **Le piège 9 (`localhost` vs `127.0.0.1`) ne s'applique pas** : `127.0.0.1` ne sert que `vite preview`.
- **Le piège 7 ne s'applique pas à `ci.yml`** : aucune installation en `continue-on-error`.
- **« 1 écran de route couvert sur ~37 » est faux** : c'est **6 montés, 10 touchés**.

**Deux points d'honnêteté, et ils valent mieux qu'un chiffre de plus** :
1. **L'agent a RETIRÉ une mesure déjà écrite.** Il avait chronométré `php artisan --version` à
   **3 min 12 s** (contre 0,22 s pour `php -r`) et en tirait un constat sur le montage Windows. En
   cherchant la cause, il a découvert par `ps aux` qu'**une quinzaine de processus PHP d'autres agents
   de cet audit tournaient dans le même conteneur**. *La mesure est réelle, l'attribution ne l'est pas.*
   Retirée.
2. **Il a arrêté sa propre exécution** en constatant qu'elle détruisait les bases des autres agents
   autant qu'ils détruisaient la sienne — et il en a fait le constat **H44-004** plutôt qu'une plainte.
   Corollaire assumé : il **ne peut pas dire** si ses 3 échecs locaux (verts en CI) sont des défauts ou
   des collisions. *C'est précisément ce qu'un harnais ne devrait jamais rendre indécidable.*

---

## Agent 13 — le canal entrant site → CRM
**Rapport** : `11_GRILLES/agent-13_canal-entrant.md` · **Preuves** : `04_PREUVES/agent-13/`
Base jetable `axion_crm_audit13`, créée puis détruite.

**C'est le rapport le plus rigoureux rendu à ce stade** : il réfute deux hypothèses qu'on lui avait
données, dont **une de moi**, et il abandonne deux de ses propres pistes après vérification.

| # | Point de grille | Verdict |
|---|---|---|
| 1 | **Signature HMAC** | ✅ SHA-256 sur `"<timestamp>.<corps brut>"`, `hash_equals`, vérifiée **avant** le drapeau. **1 témoin positif** (200 `created`) et **4 témoins négatifs** joués : signature falsifiée, en-tête absent, corps altéré après signature, horodatage altéré après signature → **401** à chaque fois, **0 écriture** |
| 2 | **Rejeu** | ✅ Pas de nonce, **et il n'en faut pas** : fenêtre ±300 s (mesurée à −400 s et +400 s → 401 `stale_signature`) + idempotence par `event_id` **adossée à un index UNIQUE réel**. Rejeu octet pour octet → `noop_idempotent`, 1 seule activité |
| 3 | **Déduplication** | ⚠️ correcte, sauf un angle mort de locale |
| 4 | **Classement** | ✅ 10/10 événements classés, inconnu → **422 bruyant**. ⚠️ mais un **tag** inconnu est perdu **en silence, avec un 200** |
| 5 | **Horodatage UTC** | ⚠️ **+7 200 s mesurés** sur `occurred_at` **et** `consent_at` |
| 6 | **Rejets** | ❌ **aucune file morte** : 26 refus joués → **0 ligne persistée** |
| 7 | **Journal** | ⚠️ trace oui (200\|15, 401\|7, 422\|18, 503\|1), exploitable non : ni `event_id`, ni code, ni contenu |
| 8 | **Cloisonnement** | ✅ **le meilleur point du produit** : le workspace n'est **jamais** dans le contrat, `WorkspaceContext::run` + RLS forcé, workspace absent → **503 et 0 écriture** |
| 9 | **Consentement** | ✅ **zéro événement légitime rejeté** |
| 10 | **Ne traverse pas** | ⚠️ un gisement entier |

| Id | Sév. | Titre |
|---|---|---|
| **B13-001** | **S1** | 🔑 **Aucun émetteur du site ne transmet de SIREN** — et **aucun formulaire n'en collecte**. 6 leads calqués sur le contrat réel → **6 `pending_match`, 0 entreprise, 0 personne** |
| **B13-002** | **S1** | Un lead **avec** SIREN mais **sans nom de famille** est accepté **200**, et **son adresse électronique est détruite** |
| B13-003 | S2 | La déduplication par adresse **échoue en production** (locale `C`) et **réussit en CI** (`en_US.utf8`) — **piège 10 confirmé en vivo** |
| B13-004 | S2 | Un événement refusé ne laisse **aucune trace exploitable** : ni file morte, ni motif persisté, ni alerte |
| B13-005 | S2 | Un tag de provenance hors référentiel est **perdu en silence, avec une réponse 200** |
| B13-006 | S2 | Le point d'entrée décale de **2 h la date qui prouve le consentement** — et **la garde ne mesure que sa propre fixture** *(approfondit A05-008, ne pas compter deux fois)* |
| B13-007 | S3 | `/site-sync/gdpr` n'a ni `schema_version` ni clé d'idempotence : un `export` est **rejouable 300 s** |
| B13-008 | S3 | `relation_type = 'fournisseur'` est **inatteignable** alors que le site détient les fiches |

🔑 **B13-001 corrige et durcit A05-003.** L'agent 5 avait attribué l'échec du canal à un SIREN
« rarement rempli ». Mesuré : **ce n'est pas « rarement », c'est 100 %** — aucun point d'appel du site
n'en transmet, et aucun formulaire n'en collecte. Le canal ne peut donc **structurellement pas**
créer une fiche : les 3 événements de production tombés en arbitrage manuel n'étaient pas de la
malchance, c'était le fonctionnement nominal. **C'est la cause racine du critère 18 du §29.**

**Ce qui ne traverse pas et devrait**, par ordre d'impact mesuré : **inscription à une session /
stagiaire** (confirme le §10.3 du journal — `Trainee` porte **déjà** le consentement horodaté et
versionné) · **promotion en client payant** (`Client` Qualiopi, **qui détient un `siren`**) ·
**paiements Stripe** (`payerSiret`) · **signatures DocuSeal** · **lead chatbot escaladé** (asymétrie
nette : `capturerLead` émet, `escaladerQuestion` non) · bénéficiaires de coaching · sous-traitants ·
liste espace-ressources · réclamations.

⚠️ **Deux réfutations de l'agent 13, dont une contre le chef de chantier — retenues telles quelles :**
1. **L'hypothèse « une opposition RGPD part en 422 » est fausse**, et c'est **ma** faute : j'avais lu
   la ligne `consentement v2 requis … reçu : careers-v1-2026-06-09` dans le `laravel.log` **local**.
   Elle vient de la **suite de tests**, qui s'exécute dans le même conteneur et écrit dans le même
   fichier. **J'ai pris la preuve qu'une garde fonctionne pour la preuve qu'un défaut existe.**
   Mesure de l'agent : **zéro événement légitime rejeté**.
2. **A-001 n'était pas ce que j'en disais** : il obtenait un **401 propre**, et a refusé de crier à la
   réfutation — « écart de protocole, pas réfutation ». C'est cette prudence qui a fait trouver la
   vraie variable : **l'en-tête `Accept`**. A-001 est corrigé et **abaissé de S1 à S2**.

**Et deux pistes qu'il a lui-même abandonnées après vérification** : les familles de candidats *sont*
correctement dérivées ; `simulateur_roi` *est* présent des deux côtés. Un agent qui referme ses
propres pistes vaut mieux qu'un agent qui les laisse ouvertes pour gonfler son rapport.

---

## Agent 19 — sécurité et légalité de la collecte
**Rapport** : `11_GRILLES/agent-19_collecte-securite.md` · **Preuves** : `04_PREUVES/agent-19/`
Base jetable `axion_crm_a19` (116 tables). Aucune requête vers un site tiers réel.

### La matrice SSRF — 31 cas joués sur **chacune** des deux gardes

| Cas | PHP | TypeScript |
|---|---|---|
| **Témoins positifs** (IP publique, DNS public, `api.insee.fr`) | **PASSENT ×3** | **PASSENT ×3** |
| Boucle locale, formes décimale / octale / courte | BLOQUÉ | BLOQUÉ |
| Privées 10/8, 192.168/16, 172.16/12, lien local | BLOQUÉ `deny_cidr` | BLOQUÉ `deny_cidr` |
| **IMDS** (`169.254.169.254`, GCP, Alibaba, Azure) | **BLOQUÉ ×4** | **BLOQUÉ ×4** |
| IPv6 `[::1]`, `[::ffff:169.254.169.254]`, `[fd00::1]`, `[fe80::1]` | BLOQUÉ **`dns_no_records`** | BLOQUÉ **`dns_no_records`** |
| **IPv6 publique légitime** `[2606:4700:4700::1111]` | 🔴 **BLOQUÉ** (faux positif) | 🔴 **BLOQUÉ** |
| `file:` / `gopher:` / `dict:` / `ftp:` | BLOQUÉ | BLOQUÉ |
| **Rebinding DNS** (`127.0.0.1.nip.io`) | **BLOQUÉ** | **BLOQUÉ** |
| **Redirection 302 → hôte interne** | 🔴 **NON RE-VÉRIFIÉE — cible atteinte** | idem par construction |

**Aucun « PASSE » sur un cas d'attaque, et les témoins positifs passent.** Mais — et c'est le piège 19
retrouvé une troisième fois — **les 6 cas IPv6 sont bloqués par `dns_no_records`, jamais par la règle** :
les crochets ne sont pas retirés, la résolution échoue, **la garde ferme par accident**. Sur l'hôte
dé-crocheté, c'est-à-dire si quelqu'un « corrigeait » le parsing, **PHP accepterait 6 cas sur 6**
(`::1`, `::ffff:169.254.169.254`, `::ffff:127.0.0.1`, `fd00::1`, `fe80::1`, `fc00::1`) — `DENY_CIDR`
côté PHP **ne contient aucune plage IPv6**, prouvé par réflexion. Le commentaire `ssrf-guard.ts:5`
affirme pourtant « équivalent fonctionnel ». *Corriger un défaut ferait donc apparaître une faille :
c'est exactement le genre de dette qu'un audit doit nommer avant qu'un développeur bien intentionné ne
la déclenche.*

| Id | Sév. | Titre |
|---|---|---|
| **C19-007** | **S0** | 🔴 Base légale « intérêt légitime » (art. 6.1.f) **sans mise en balance écrite** et **sans information de l'article 14**, pour **1 319 567 personnes physiques**. L'AIPD en vigueur le dit elle-même : « *il n'est écrit nulle part* », « *rien dans le code ne la déclenche* ». La table `data_processing_log` du registre art. 30 **n'est créée par aucune migration** |
| **C19-001** | **S1** | Côté PHP, la garde SSRF **n'est jamais appliquée à une URL issue de la donnée** : ses 5 appels portent **tous** sur `self::BASE_URL`, une **constante**. Les 3 services qui consomment une URL de la base (`MentionsLegales`, `DomainFinder`, `ProxiedHttpClient`) **ne l'appellent pas**. Côté TS, 2 appels sur 3 portent sur une URL réellement contrôlée par la donnée |
| **C19-003** | **S1** | La **redirection n'est pas re-vérifiée** : cible interne atteinte (mesuré, 302 local) |
| **C19-010** | **S1** | Le « non diffusible » INSEE — une **opposition légale** — n'est filtré que sur **1 voie de collecte sur 3**, et la purge ne reconnaît **qu'une variante sur 5** (mesuré : 1 supprimée, **4 survivantes**) |
| **C19-008** | **S1** | `POST /proxy-providers/{p}/test`, documenté « health check live », renvoie **`healthy: true` en dur** |
| C19-002 | S2 | Les deux gardes ne bloquent **aucun** IPv6 par la règle ; elles ferment par accident de parsing et **divergent sur 6 entrées sur 13** *(piège 15 : la constante dupliquée a divergé sans le dire)* |
| C19-004 | S2 | **Aucun collecteur ne lit le `robots.txt`** des sites moissonnés — **zéro occurrence** dans `backend/app`, `workers/src`, `composer.json`, `package.json` (témoin négatif : la recherche trouve bien `frontend/public/robots.txt`) |
| C19-005 | S2 | **Aucune limite par domaine cible** : 8 requêtes **concurrentes** par site, 400 par salve × 20 shards CI ; 0 occurrence de `Redis::throttle` ou `WithoutOverlapping`. Le seul `usleep(100–300 ms)` « pour ne pas marteler le serveur » est dans une **méthode sans aucun appelant** — et la docblock promet toujours des « délais polis » |
| C19-006 | S2 | **Tous les collecteurs de masse sont déguisés** (4 UA navigateurs tournants, Chrome 131, `STEALTH_INIT` masquant `navigator.webdriver`) — **aucun ADR n'existe** : le déguisement est **subi, pas assumé** |
| C19-009 | S3 | `SSRF_GUARD_DENY_PRIVATE` a **deux défauts opposés** : l'auto-contrôle déclare la garde **inactive** alors qu'elle est **active** |
| C19-011 | S3 | Les deux fournisseurs de proxy **désactivent la vérification TLS** de leur sonde de santé |
| C19-012 | S3 | Le contournement de captcha n'est pas actif (`TwoCaptchaSolver::solve()` ne fait que `throw`, jamais bindé), mais **rien ne l'interdit par construction et rien ne l'a arbitré** |

**Ce qui va bien, et qu'il ne faut pas casser** (7 points au rapport). Notamment : **aucun identifiant
de proxy n'est stocké en base** — `proxy_providers_config` n'a **pas une seule colonne de secret**,
et le témoin négatif est solide : la même requête trouve bien `users.password_hash`, `totp_secret` et
`invitations.token_hash`. **Rien ne peut donc fuir par `GET /proxy-providers`**, contrairement à ce que
le mandat faisait craindre.

⚠️ **Correction de référentiel à propager** : le mandat et mon dossier commun renvoyaient à
`_REPORTS/DPIA_2026-05-17.md`. Ce document porte **depuis le 18/08 un bandeau « DOCUMENT OBSOLÈTE —
NE PAS UTILISER »**. La référence en vigueur est **`AIPD_2026-08-18.md`**. L'agent 19 l'a vu et a
mesuré sur le bon document — **les agents 15 et 33 doivent en faire autant**.

---

## Agent 10 — les 18 modèles, les 58 migrations, les 115 relations
**Rapport** : `11_GRILLES/tables.md` (642 l.) · **Preuves** : `04_PREUVES/agent-10/` (34 fichiers)

**Recompte, encore** : **115 relations** dans `public` (113 ordinaires dont **14 partitions**
`audit_logs`, 1 partitionnée, 1 vue matérialisée) — et non 104 comme je l'avais compté ni 54 migrations
comme le mandat l'annonce. **58 migrations**, confirmé.

🔑 **Le double `migrate:fresh` — le résultat le plus important, et il est double lui aussi.**
`make` **n'est pas installé sur ce poste** (`EXIT=127`), l'agent a donc rejoué la recette de
`Makefile:95-108` ligne pour ligne. **Premier passage : RC1=1 et RC2=1** — la reconstruction échoue
**dès la première**, sur `cannot drop table part_config because extension pg_partman requires it`.
La base locale avait **4 migrations de retard, dont le correctif lui-même** : *le correctif était
inatteignable par le chemin qu'il est censé réparer.* Après `php artisan migrate --force`
(relocalisation en 425 ms) : **RC1=0, RC2=0**, `pg_partman` dans son schéma, 14 partitions, 58/58.
**Verdict : la fragilité F10 est levée — mais seulement pour une base déjà à jour.** Une base en
retard reste bloquée, ce qui est exactement le cas d'un poste qu'on rallume après une semaine.

| Id | Sév. | Titre |
|---|---|---|
| **B10-004** | **S0** | 🔴 **Export RGPD = 4 tables ; effacement = 8 ; `candidates` n'est dans NI L'UN NI L'AUTRE** |
| **B10-001** | **S1** | Une base dont `pg_partman` est resté dans `public` : `migrate:fresh` meurt au premier coup, et **le correctif est inatteignable par ce chemin** |
| **B10-002** | **S1** | Le rôle `axion_app` a `DELETE` **et** `UPDATE` sur `audit_logs`, **sans aucune RLS** sur la table ni ses 14 partitions *(recoupe B16-002/004, B11-006)* |
| **B10-003** | **S1** | `run_maintenance()` n'est appelé **par personne** : la rétention de 24 mois n'est **jamais** appliquée, et les partitions **s'arrêtent en 02/2027** |
| **B10-016** | **S1** | `companies`, `contacts`, `users`, `workspaces` portent `deleted_at` **sans le trait `SoftDeletes`** : **44 filtres lisent en suppression douce, Eloquent écrit en suppression dure** |
| B10-011 | S2 | `ScrapingSourcesSeeder` fait un **`upsert` depuis DEUX migrations** : `name`, `kind`, `ttl_days`, `legal_note`, `dedup_key_pattern`, `quota_per_day` **réécrits à chaque déploiement** *(piège 22)* |
| B10-005 | S2 | Le masquage `contacts.view_pii` ne couvre que **3 écrans** : journalistes et candidats **en clair** |
| B10-006 | S2 | `BelongsToWorkspace` sur **4 modèles sur 15**, et **aucun test ne l'exige** |
| B10-007 | S2 | **15 tables à `workspace_id` sans clé étrangère** : orphelins invisibles à la suppression d'un espace |
| B10-008 | S2 | `email_verification_logs` fuit sans contexte — la policy a **survécu au durcissement** grâce à un nom raccourci *(confirme B11-004)* |
| B10-009 | S2 | `permissions` : `UNIQUE(name)` seul là où le code suppose `(name, guard_name)` ; **`EtancheiteUniversTest` est rouge sur `main`** |
| B10-010 | S2 | Le correctif `search_path` — écrit parce qu'**une sauvegarde de production n'était pas restaurable** — repose sur une **liste en dur, sans aucune garde** : une fonction ajoutée demain rejoue la panne |
| B10-013 | S2 | **`/ai-act/register` et la recherche globale mentent exactement comme `/saved-views`** : 200 + liste vide |
| B10-015 | S2 | `db-rebuild-check` n'est dans **aucun workflow CI** |
| B10-012 | S3 | **42 tables sur 102** ne sont nommées par aucune ligne de `app/`, `routes/`, `config/` ni du frontend |
| B10-014 | S3 | `companies` : **1 491 Mo d'index pour 624 Mo de tas**, et **20 index à `idx_scan = 0`** |

🔑 **« Six tables mortes » sous-compte d'un facteur sept.** Le §3 bis en nommait six ; l'agent en
mesure **42 sur 102**, dont **six qui n'existent nulle part dans le dépôt** (`analytics_funnel_snapshots`,
`deal_history`, `email_messages`, `email_sequences`, `linkedin_health_checks`, `linkedin_sequence_runs`).
Et le patron de `saved_views` (**A-002**) se répète **deux fois de plus** : `/ai-act/register` — un
**registre réglementaire** — et `GlobalSearchController` répondent « 200, liste vide ».

**Points solides mesurés, à ne pas re-rapporter comme défauts** : `EtancheiteParTableTest` **11/11
verts**, sème les 55 tables, **deux témoins négatifs** · **203/203 colonnes temporelles en
`timestamptz`, zéro naïve** · 68 `CHECK`, 139 clés étrangères, 36 `UNIQUE` réellement en base ·
`search_path` fixé sur 7/7 fonctions · **aucun index invalide ni doublon** · **58/58 migrations
déclarent un `down()`** · et l'index `idx_companies_ws_counts` rejoué sur 2,8 M confirme l'ordre de
grandeur annoncé par la pièce 1 (`Index Only Scan`, `Heap Fetches: 0`).

---

## Agent 38 — les 17 workflows, 36 jobs
**Rapport** : `11_GRILLES/agent-38_cicd.md` (500 l.) · **Preuves** : `04_PREUVES/agent-38/` (24 fichiers, 2,3 Mo)

**36 jobs déclarés. 9 réellement bloquants. 27 décoratifs** — dont **5 déclarés honnêtement comme
tels et 22 qui ne le disent pas**. Et surtout : **seuls 4 bloquent une fusion** (`backend`,
`frontend`, `workers`, `gitleaks`) — et **`enforce_admins: false`** les rend inapplicables au seul
contributeur du dépôt.

| Id | Sév. | Titre |
|---|---|---|
| **F38-007** | **S1** | 🔴 `diag-website-status.yml` recréerait la production **sans l'overlay** : un simple `workflow_dispatch` **rouvre la faille du 19/08** (ports 55432/56379) |
| **F38-002** | **S1** | 4 contextes requis sur 36 jobs, **aucune des trois gardes nées d'un incident**, `enforce_admins: false` — et **la PR #186 a été fusionnée AVANT la fin de son run Security rouge** |
| **F38-001** | **S1** | La vérification post-déploiement de la préproduction interroge **`127.0.0.1`** quand la pile se lie à **`172.17.0.1`** depuis la PR #181 → **10 rouges sur 10**, sur une préprod qui répond **200** depuis internet |
| **F38-003** | **S1** | La seule garde qui mesure **les ports réels** ne tourne **que sur la préproduction**, et son code de retour y est **avalé par `\|\| echo`** : **la production n'est mesurée par aucun job** |
| F38-005 | S2 | **64 vulnérabilités affichées, deux jobs verts** ; et l'alerte ne peut jamais naître d'un audit |
| F38-006 | S2 | Lighthouse rend `success` **sans avoir jamais produit un score** — et la cible n'est plus l'excuse |
| F38-008 | S2 | **Aucun `timeout-minutes`** dans les 8 workflows qualité — **6 exécutions figées de 1 h 28 à 2 h 25** |
| F38-013 | S2 | `continue-on-error` **non commenté** sur le seul SAST, dans le fichier même qui explique le retrait des autres |
| F38-004, F38-009, F38-011 | S2 | confirment H47-001, A09-006 et A05-009 **sur `e8924b8`** |
| F38-010 | S3 | Le commentaire de `ci.yml` annonce **276** fichiers Pint non formatés ; mesuré **386** (311 hors artefact CRLF) |
| F38-012, F38-014 | S3 | `release-tracking.yml` : **0 exécution depuis toujours**, et sans effet s'il tournait ; `zap-baseline` idem |

**Deux réfutations, et elles honorent l'agent** (règle 7 appliquée à ses propres hypothèses) : le job
`alerte` de `security.yml` **a bien tourné** — `if: failure()` est vrai dès qu'**un** job du workflow
échoue, pas seulement ses `needs:` ; et celui de `surveillance-sauvegarde.yml` **a tourné deux fois le
17/08**. Les vestiges `trigger-coolify` et `smoke` ont **réellement été supprimés** le 19/08.

**Un 18ᵉ workflow fantôme** existe côté GitHub — `TMP — génération baseline PHPStan` (id 333858977,
**`active`**) — **sans aucun fichier correspondant dans le dépôt**.

---

## Agent 40 — infrastructure et exposition
**Rapport** : `11_GRILLES/agent-40_infrastructure.md` · **Preuves** : `04_PREUVES/agent-40/` (28 fichiers)

**Trois réponses aux questions que je lui avais confiées en priorité :**
1. **`AUDIT_HASH_CHAIN_SECRET` en production : longueur 64. Il n'est PAS vide.** → **B16-001 réfuté pour la production**, vrai en local seulement.
2. **Le serveur HTTP de production est bien `php -S`** → confirme **A-010**. Apport décisif : **php-fpm EST dans l'image** (`/usr/local/sbin/php-fpm`, PHP 8.3.33 fpm-fcgi, configuration complète) et **n'est jamais lancé** ; le `9000/tcp` visible dans `docker ps` est un simple `EXPOSE`, **rien n'écoute dessus**. ⚠️ **Piège du correctif** : `pm.max_children = 5` par défaut — basculer en fastcgi sans y toucher donnerait **cinq** requêtes simultanées, pas dix.
3. **Le planificateur tourne vraiment** : `schedule:work` en PID 1, `RestartCount = 0`, trace d'exécution réelle à 12:35:00. → **lève la crainte de l'agent 17.** Le `healthcheck: disable: true` est bien appliqué : rien ne le surveillerait, mais il n'est pas mort.

🔑 **Et il a écrit la garde qui manquait** — `infra/scripts/verifier-serveur-http.sh`, qui mesure
**`/proc/1/cmdline` des conteneurs**, jamais le `Dockerfile`. **Vue rouge sur la production et sur la
préproduction** (exit 1, un seul conteneur signalé sur sept), avec **témoin négatif** (projet
inexistant → exit 2) **et témoin positif réel** (pile `bookforge` sans serveur HTTP → exit 2, pas 0).
Exécutée par `ssh 'bash -s' < script` : **aucun fichier déposé sur le serveur**. C'est exactement ce
que **A-011** réclame — une garde qui rougit **sur l'objet qui casse**.

| Id | Sév. | Titre |
|---|---|---|
| **F40-002** | **S0** | `MAIL_MAILER` n'est défini **nulle part** : 7 clés SMTP complètes et valides, et **aucun courriel n'est envoyé** → **A-012** |
| **F40-003** | **S1** | `TELESCOPE_ENABLED` absent du `.env` de production — **cause racine de A-007** — avec **20 autres clés** de `.env.example` absentes du serveur |
| **F40-004** | **S1** | `verifier-ports-publies.sh` branchée **sur la préproduction seulement**, et son échec y est **avalé** |
| **F40-005** | **S1** | Le correctif du piège 18 (`up -d --no-deps postgres redis`) a été écrit pour la préproduction et **jamais rétroporté** en production |
| **F40-006** | **S1** | **La production exécute une migration absente de `main`** : 59 contre 58. `2026_05_17_195529_create_failed_jobs_table.php` est **non suivi par git**, entré dans l'image parce que le contexte de build est le répertoire de travail du serveur — que `git reset --hard` ne nettoie pas — et **appliqué en base** (batch 7) |
| **F40-007** | **S1** | Le mot de passe Postgres de production est **celui du dépôt public**, et `environment:` **empêche le `.env` de le corriger** |
| F40-008 | S2 | `docker-compose.observability.yml` : **9 ports sur `0.0.0.0`**, sans aucun `!override` — le piège fermé pour `reverb` **reste ouvert ici** |
| F40-009 | S2 | **`ufw` n'est pas installé**, alors qu'un script d'installation et un runbook le supposent |
| F40-010 | S2 | **Aucun `daemon.json`** : les journaux de conteneurs n'ont **aucune limite de taille** |
| F40-013 | S2 | Le conteneur n'est **pas** en lecture seule (`ReadonlyRootfs=false`) : la protection vient des droits, et `/var/www/html` est en **1777** |
| F40-011 | S3 | **961 Mo de journaux morts + 3,8 Go de cache de build** : 4,8 Go récupérables |
| F40-012 | S3 | `Dockerfile.worker` et la cible `prod-nginx` : **construits, jamais exécutés** |

⚠️ **Correction que je dois à cet agent, sur mon propre constat A-004 (exposition ACME)** : **A-004
consomme la limite horaire de validations échouées, PAS le quota d'émission** — le renouvellement de
la production (fenêtre ARI au 13/09, expiration au 14/10) **n'est pas menacé**. Mon impact était
surévalué ; le constat reste, sa gravité baisse.

**Et le patron du piège 18 se répète cinq fois ailleurs** — c'est ce que le mandat demandait de
chercher : `TELESCOPE_ENABLED`, `MAIL_MAILER`, le correctif `--no-deps` non rétroporté, la garde des
ports branchée au mauvais endroit, et `docker-compose.observability.yml`. **Plus l'inverse, qui est
nouveau** : une migration qui existe **en production et pas dans `main`**.

---

## Agent 23 — architecte de la navigation (§6, « le chapitre qui empêche le bordel »)
**Livrable** : `10_NAVIGATION-CIBLE.md` · **Preuves** : `04_PREUVES/agent-23/` (dont `COMMANDES-JOUEES.md`)

### 🔴 D23-001 — le fait qui invalide une partie du bloc D, et que j'ai vérifié moi-même

**L'atelier local sert une barre latérale vieille de 32 heures.** L'image `axion-crm-app` date du
**17 août 07:12** ; le commit qui refond la barre (`da97826`) du **18 août 17:39**. `https://app.localhost`
affiche donc encore **10 sections / 28 entrées** — « Campagnes », « Runs de scraping », « Data »,
« Phase 2 », et des entrées vers `/crm` et `/analytics` **qui n'existent plus dans le routeur**.

**Vérification du chef de chantier — et la question urgente de l'agent est LEVÉE :**

```
PRODUCTION   /assets/index-D3nU2tuG.js (1 046 364 o)
  Journaux de collecte : 2      <- barre NEUVE   (témoin positif)
  Collectes            : 2      <- barre NEUVE
  Audiences (segments) : 1      <- barre NEUVE
  Conformité           : 3      <- barre NEUVE
  Runs de scraping     : 0      <- barre PÉRIMÉE absente (témoin négatif)

LOCAL        /assets/index-DPQz8SpC.js (975 382 o)
  Journaux de collecte : 0      <- barre neuve ABSENTE
  Runs de scraping     : 2      <- barre PÉRIMÉE présente
  Phase 2              : 2      <- barre PÉRIMÉE présente
```

> **La production est à jour. C'est l'atelier local qui est périmé.**
> La crainte finale de l'agent — « si le bundle de production porte la même barre, les utilisateurs
> cliquent sur 4 entrées menant à un 404 sans navigation » — **est écartée par la mesure**.

**Mais la conséquence sur l'audit est lourde** : tout agent du bloc D qui a « ouvert les écrans pour
de vrai » sur `app.localhost` a mesuré **une interface morte**. → décision **D-011**.
*Et A-006, mon propre constat, doit être nuancé : il a raison sur le **code**, tort sur **l'atelier**.
J'avais lu `Sidebar.tsx` et conclu sur ce que voyait l'utilisateur. Le code n'est pas l'écran servi.*

### Les écarts réels à la cible §23.3

Le **nombre** de groupes est bon (6, dont « Collecte » explicitement autorisé par le §A.1 n°15) —
**ce sont les noms et le contenu qui divergent** : **1 conforme · 4 partiels · 13 absents**.
Manquent le groupe **ÉCHANGES en entier** (0/4), **Boîte de réception**, **Mes rendez-vous**,
**Mes tâches**, **Organisations**, **Prospection**, les **6 vues épinglées par type**,
**Tableaux de bord / Canal avec la console / Coûts**, les **8 sous-groupes de Réglages**, et les
**3 éléments du pied de barre** — pied de barre réellement mesuré : **`["Réduire"]`**.

**Test des intentions** (substitution D-007) : sur 20 intentions instruites — **4 trouvables ·
4 avec effort · 5 introuvables · 7 impossibles**. Et sur les **deux exemples littéraux du critère 24**
(« voir les visios de la semaine », « régler le rappel avant rendez-vous ») : **0 sur 2**.

### Le plan de correspondance — le livrable du §6.5

**5 conservées · 8 renommées · 9 déplacées · 4 fusionnées · 2 écrans + 1 groupe supprimés ·
8 redirections à écrire · 1 orpheline réintégrée · 14 créées.** Les **37 écrans sont tranchés un par
un** (§6.4) : 12 gardés tels quels, 9 renommés, 8 rangés ailleurs, 5 fusionnés, 2 retirés, 1 éclaté,
1 refait. Principe retenu, et il est juste : **l'URL d'un écran qui survit ne change pas** — on
n'écrit une redirection que là où un écran fusionne ou disparaît.

### Le glossaire — « un seul mot par notion », et la règle est violée partout

- 🔴 **Une personne = 4 mots** : `Contact`, `Candidate`, `Personne`, `person_key`. Et une mesure qui
  fait mal : **`contacts.company_id` est `NOT NULL`** — *dans ce produit, une personne ne peut pas
  exister sans société.* Le CDC veut l'inverse (§1.1).
- **« Campagne » = 3 objets** : collecte (CRM), génération de contenu (axionia), e-mail L7 à venir.
  Le renommage de l'étape 0 **s'est arrêté à la barre** : **27 chaînes « campagne »** subsistent
  derrière l'entrée « Collectes ».
- **« Boîte de réception », « Clients » (3 objets), « Couverture », « Prospection »** : collisions
  **entre les deux consoles**, que le §23.2 interdit explicitement.
- **Accueil = 4 mots** : « Aujourd'hui » / « Tableau de bord » / « Accueil » / « dashboard ».
- Le fil d'Ariane est **en anglais sur 10 routes** et porte encore « E-mails à froid » et
  « Prospection LinkedIn », libellés pourtant retirés de la barre. `locales/fr.json` est **mort** et
  dit encore « Scraper runs ».

| Id | Sév. | Titre |
|---|---|---|
| **D23-001** | **S1** | L'atelier local sert une barre vieille de 32 h — **toute mesure d'écran y est fausse** |
| D23-005 | S2 | La recherche ⌘K trouve une personne et ouvre **la liste** au lieu de sa fiche |
| D23-003 | S2 | L'entrée « Contacts » **liste des entreprises**, et deux entrées voisines listent la même chose |
| D23-002 | S2 | L'écran d'accueil montre **4 totaux décoratifs** et ne dit rien de la journée |
| D23-007 | S2 | **Aucun compteur** dans la barre : 6 manquent, dont un sur une entrée **déjà livrée** |
| D23-004 | S2 | Le renommage de l'étape 0 s'est arrêté à la barre : « campagne » et « scraping » vivent dans les écrans |
| D23-008 | S2 | « Réglages » ne peut pas devenir les 8 sous-groupes du §19 **sans dépasser la règle des sept** |
| D23-009 | S2 | `/crm` et `/analytics` rendent un **404 sans barre latérale**, sans redirection |
| D23-010 | S2 | La visite guidée n'affiche que **5 de ses 7 étapes** et **se marque « faite » quand même** |
| D23-011 | S2 | Le lien CRM → console axionia **n'existe pas** ; celui de l'autre sens s'appelle « Prospection » |
| D23-012 | S2 | **Trois notions portent le même mot** dans les deux consoles |
| D23-006 | S3 | Le fil d'Ariane parle **anglais sur 10 routes** et porte des libellés retirés |
| D23-013 | S3 | Le retrait par paliers des 12 écrans de la console n'a franchi **aucun** palier |

**Deux mesures signalées comme honnêtes plutôt que spectaculaires**, et c'est exactement le
comportement attendu : la visite guidée **ne bloque pas** l'interface après son arrêt (l'overlay se
retire, vérifié) et elle appelle bien `POST /auth/onboarding/complete` (vérifié) — l'agent **n'a donc
pas** écrit qu'elle rejoue indéfiniment, alors que c'eût été un constat plus vendeur. Et le témoin
négatif de D23-010 est net : les mêmes sondes, sections forcées dépliées, affichent **7 étapes sur 7**.

---

## Agent 7 — les fragilités du §A.1 et les défauts D-01 → D-13
**Rapport** : `11_GRILLES/agent-07_fragilites.md` · **Preuves** : `04_PREUVES/agent-07/` (50 sorties)

**Le recompte tranche la contradiction du mandat** : le §A.1 compte **15 fragilités**, numérotées 1→15.
Le mandat d'audit écrit « 15 » à sa ligne 78 et « **F1 → F19** » à ses lignes 469 et 716 — **il se
contredit lui-même**. Et le **code** emploie une **troisième** numérotation (`F5` = perf, `F7` = routes
501, `F11` = AIPD, `F12` = pare-feu, `F17` = navigation). **Trois numérotations concurrentes** pour la
même liste : c'est le constat A07-008, et il explique pourquoi personne n'arrive à dire ce qui est clos.

| | LEVÉE | PARTIELLE | ENCORE VRAIE |
|---|---|---|---|
| **Fragilités F1→F15** | **5** (F4, F5, F6, F11, F15) | **7** (F1, F2, F3, F7, F8, F12, F14) | **3** (F9, F10, F13) |
| **Défauts D-01→D-13** | **8** | **2** | **3** (D-05, D-06, D-13) |

🔴 **Le rapport de clôture du 17/08 déclare D-05 et D-13 corrigés. Mesuré : c'est faux.**
Et symétriquement, il classait **D-11 « restant »** alors qu'il est **levé** — l'erreur va dans les
deux sens, ce qui est le signe d'un document qu'on n'a pas rejoué.

| Id | Sév. | Titre |
|---|---|---|
| **A07-001** | **S0** | 🔴 **L'enrôlement 2FA écrit trois colonnes qui n'existent pas** : **aucun utilisateur nouvellement créé ne peut terminer sa première connexion** |
| **A07-003** | **S0** | Le **runbook de rotation des secrets** prescrit `docker compose restart` — **un secret réputé tourné ne l'est pas** *(piège 8, transformé en fausse assurance opérationnelle)* |
| A07-002 | S1 | `email_verification_logs` : la policy permissive survivante rend visibles les lignes **des deux espaces** sans contexte *(3ᵉ mesure indépendante — confirme B11-004 et B10-008)* |
| A07-005 | S2 | **L'adresse en clair n'a pas disparu** de la liste d'opposition : colonne **et** index subsistent *(F7 non close)* |
| A07-006 | S2 | **Rien n'interdit d'ajouter une ligne à la baseline PHPStan** : l'exigence F3 n'a **aucune garde** |
| A07-007 | S2 | `GovernedTagsSeeder` **n'est appelé par aucun seeder ni migration** : une base neuve ne porte **aucun tag gouverné** |
| A07-010 | S2 | La garde d'étanchéité par table **ne peut pas être rejouée sur l'atelier** : 4 tests sur 11 échouent |
| A07-011 | S2 | Le statut Calendly « **honoré** » reste **manuel** : F14 n'est remplie que pour 2 statuts sur 3 |
| A07-008 | S2 | **Trois numérotations concurrentes** des fragilités coexistent dans les documents et le code |
| A07-009 | S3 | Le runbook console locale renvoie au worktree `crmpro-wt-etape0` *(confirme A09-007)* |

🔑 **A07-001 est le maillon qui manquait à A-012.** Même une fois un mot de passe posé, `EnforceFirstLoginSetup`
conduit à un enrôlement 2FA **qui écrit trois colonnes inexistantes**. Le verrouillage du propriétaire
n'était donc pas seulement « il n'a pas reçu son mot de passe » : **la première connexion ne peut pas
aboutir**. Le script `definir-mot-de-passe-crm.sh` posé pendant l'audit ne suffira **pas** à ouvrir la
console — à vérifier en P4, et à corriger avant d'espérer ouvrir les 37 écrans.

### 🔴 A07-004 — RÉFUTÉ par le chef de chantier, et la façon dont il est faux est instructive

L'agent conclut que `_PLANS/2026-08-18_PREALABLES-AVANT-CHANTIER-CRM-CIBLE.md` — le plan que le CDC
déclare « faisant foi pour l'étape 0 » — **n'a jamais existé**, sur la foi d'un `git log --all` vide,
présenté comme un **témoin négatif**.

**Mesuré :** le fichier **existe**, `28 200 octets`, daté du 18/08 19:35, et porte bien les 16 lignes.

```
$ ls -la Axion-IA/_PLANS/2026-08-18_PREALABLES-AVANT-CHANTIER-CRM-CIBLE.md
-rw-r--r-- 28200 Aug 18 19:35                              <- il existe

$ cd Axion-IA && git log --all -- "_PLANS/2026-08-18_PREALABLES*"
fatal: not a git repository (or any of the parent directories): .git   <- LA COMMANDE A ECHOUE

$ cd Axion-IA/axionia && git rev-parse --show-toplevel
C:/Users/willi/Documents/Projets/Axion-IA/axionia        <- la racine git est UN NIVEAU PLUS BAS
$ git log --oneline -2                                    <- TEMOIN POSITIF : ce depot repond bien
eb754332 fix(qualiopi): …
```

**`Axion-IA` n'est pas un dépôt git** — la racine est `Axion-IA/axionia`, et `_PLANS` est **au-dessus**.
Le `git log` a donc **échoué**, et son message d'erreur a été lu comme « aucun résultat ».
C'est **exactement le piège 21 du dossier commun** : *une commande de diagnostic qui échoue n'a rien
mesuré — ne lis pas son silence comme un résultat.* Et c'est la règle 3 retournée contre elle-même :
un « témoin négatif » qui n'a pas d'abord été prouvé capable de rendre un résultat **n'est pas un
témoin**.

**Ce qui reste vrai, et devient le constat** : ce plan **n'est pas versionné**. Il vit **hors des deux
dépôts**, exactement comme le prompt d'audit — que le journal du 19/08 signale lui-même « à committer ».
**Un document qui « fait foi » et qu'aucun historique ne protège** est un constat de gouvernance :
une mauvaise manipulation l'efface, et personne ne peut dire après coup quelle version a été jouée.
→ **A07-004 reclassé S3, énoncé corrigé.**

---

## Agent 32 — les écrans « Contacts » de la console axionia
**Rapport** : `11_GRILLES/agent-32_console-axionia.md` · **Preuves** : `04_PREUVES/agent-32/`

**Recompte, avec une méthode qui mérite d'être signalée** : l'agent a **exécuté** `buildAdminNav()`
au lieu de la grepper — le grep rendait 150, il manquait 4 catégories QR et 3 imprimés **dépliés
dynamiquement**. Mesure réelle : **153 entrées** + 1 lien codé en dur hors référentiel = **154
destinations**. Le « ~150 » du §A du CDC est donc **juste**.

**Sur les « 12 écrans » du mandat : le nombre est bon, mais 2 items sur 12 sont faux.**
« Rendez-vous + calendrier » n'est **plus** dans la navigation depuis le 2026-07-29 (devenu 4
redirections), et l'entrée « **Tout** » (`/contacts`, boîte de réception unifiée des 4 canaux) **n'est
dans aucune liste**. Périmètre réel : **17 écrans vivants**, 8 redirections de compatibilité, 2 routes
de fichier, 5 redirections du module Booking mort. **Sur les 27 pages purement redirectrices de toute
la console, 0 ne pointe vers le CRM.**

🔑 **Et une découverte de conception qui change le sujet** : **12 écrans ≠ 12 objets — ils lisent
4 tables**, et **8 des 12 entrées rendent le même composant sur la même table `Submission`** avec un
filtre figé.

| Id | Sév. | Titre |
|---|---|---|
| **E32-002** | **S1** | 🔴 **Le canal ne transporte aucun contenu** : retirer les écrans du site perdrait **13 catégories d'information** — corps du message, réponses envoyées et leur remise, notes internes, `assignedTo`, statuts, archive, marqueurs de lecture, heure du rendez-vous, lieu et lien Meet, CV et photo, réponses au questionnaire, champs du tournage podcast, empreinte anti-spam |
| **E32-003** | **S1** | **L'instrument de parité mesure le mauvais objet** : il compte les lignes d'outbox **sans filtre de statut** — un `gave_up` compte comme émis *(7ᵉ occurrence du patron A-011)* |
| E32-010 | S2 | `GET /config/features` répond **500 en production** : la route est dans `auth:sanctum` **alors que son propre commentaire dit l'inverse** — l'état du drapeau de destination est **inaudible de l'extérieur** *(recoupe A-001)* |
| E32-005 | S2 | **Critère 23 : l'écart est de 17 sur 17.** Aucun écran de relation ne redirige vers le CRM. Quatre chemins d'accès, dont la palette ⌘K qui expose **les 153 entrées** sans filtrer ni `parent` ni `tier` |
| E32-009 | S2 | **8 entrées pour une seule table**, là où le CRM a explicitement tranché l'inverse |
| E32-007 | S2 | Une candidature commerciale s'affiche dans **4 écrans, 2 fiches de détail, 2 vocabulaires de statut** |
| E32-004 | S2 | Le drapeau du critère 25 **n'existe pas** — cherché **avec témoin négatif** (le même grep trouve bien `CRM_SYNC_ENABLED`, `CRM_SYNC_CANDIDATES_ENABLED`, `EN_LOCALE_ENABLED`) — et **la forme évidente ne peut pas tenir la minute** exigée : une variable Coolify impose un redémarrage de conteneur |
| E32-006 | S2 | L'unique lien vers le CRM le nomme « **Prospection** », pointe la **racine**, et vit **hors du référentiel** |
| E32-001 | S3 | La liste des « 12 écrans » du mandat se trompe sur 2 items |
| E32-008 | S3 | « Recrutement » en navigation, `commercial` en URL, « Commercial » dans la page |

🔑 **E32-002 est le constat le plus profond du bloc E, et ce n'est pas un bug.** Le CRM déclare
lui-même, dans son code : *« la timeline est un **INDEX** des touchpoints, jamais une copie de leur
contenu »* — le contrôleur ne projette que `kind, title, occurred_at, external_ref`. Autrement dit,
**« le système qui a produit le détail » — la formule du CRM — désigne exactement la console qu'on
veut retirer.** C'est un **parti pris de conception assumé**, et il **contredit frontalement le
principe 10** du CDC (« une seule porte pour la journée : le CRM »). *Ce n'est pas un défaut à
corriger, c'est un arbitrage à trancher* — et il conditionne toute l'étape 1c. → **porté à l'agent 48
et au rapport final.**

**Les paliers de retrait (§25.1)** sont produits, et l'agent est honnête sur ce qui les bloque :
**palier 0 non tenu** (0 fiche sur 1 319 567 porte une `person_key`) ; **palier 2 seul ouvrable
aujourd'hui** — un bandeau et un **lien profond** vers `/console/personnes/{person_key}`, la clé étant
**déjà calculée par le site** (`hashEmailForLookup` = `contactEmailHash` = `person_key`) — et c'est un
**ajout, qui ne perd rien**. Contrainte transverse relevée : **7 alertes Telegram pointent dans
`contacts/*`** ; les 8 redirections de compatibilité les couvrent, **ne pas les supprimer**.

---

## Agent 12 — les routes API : grille complète, 117 lignes × 18 colonnes, **zéro case vide**
**Rapport** : `11_GRILLES/routes.md` · **Preuves** : `04_PREUVES/agent-12/`

**Le recensement corrige tout le monde, moi compris** : **114 déclarations** `Route::verb(` dans
`api.php` (j'en avais compté 112, le mandat annonce « ~110 ») → **117 routes enregistrées**
(113 `/api/v1` + 4 `/api/internal`), **117 auditées**. Et **1 déclaration perdue** en route :
110 v1 − 1 (`apiResource`) + 5 − **1 collision** = 113.

| Verdict | Nombre |
|---|---|
| **Vivante** | **86** |
| **Factice — 501** | **19** |
| **Factice mais 200, corps codé en dur** | **9** |
| **Inerte par drapeau** | **3** |
| Déclaration morte (hors total) | 1 |

Transversal : **106/117** sous `auth:sanctum` · **4 seulement** avec une autorisation réelle ·
**21 sans aucun filtre applicatif** (RLS seule) · **88 sans limitation de débit** · **42 citées par
aucun test**.

| Id | Sév. | Titre |
|---|---|---|
| **B12-001** | **S0** | 🔴 `GET /v1/companies/{company}` **rend la fiche d'un autre espace** — 200 mesuré. 21 routes sans filtre applicatif, et le rôle de base est **`BYPASSRLS`** *(étend B11-010 : la RLS ne rattrape rien pour ce rôle)* |
| **B12-003** | **S0** | 🔴 **Aucune policy n'est jamais appelée** : un compte `viewer` a **supprimé définitivement une entreprise** — `Company` n'a **pas** `SoftDeletes` alors que la documentation annonce une suppression douce *(recoupe B10-016)* |
| **B12-004** | **S0** | 🔴 `POST /internal/scraper-result` **accepte une signature FORGÉE** : la vérification HMAC y est **réimplémentée** sans garde de secret vide, et le secret est **vide dans `.env.example`** |
| **B12-012** | **S1** | `BasePolicy::sameWorkspace()` compare deux **UUID castés `(int)`** → **`0 === 0`, toujours vrai**. Le défaut identique de `channels.php`, corrigé le 16/08, **n'a pas été propagé**. Dormant aujourd'hui (aucune policy n'est appelée), **il s'arme au premier `authorize()`** — c'est-à-dire dès qu'on corrigera B12-003 |
| **B12-002** | **S1** | Le masquage des coordonnées couvre **3 listes** ; la **fiche détaillée** livre e-mail et téléphone **en clair** au même `viewer` *(recoupe B10-005)* |
| **B12-010** | **S1** | Les **3 exports CSV nominatifs ne laissent aucune trace** — le journal n'audite que les écritures — et **aucun plafond de lignes** *(confirme B16-008)* |
| **B12-016** | **S1** | `POST /rgpd/requests/{req}/process` déclenche un **effacement définitif sans permission ni test** |
| **B12-007** | **S1** | **Dix routes répondent 200 avec un corps figé**, dont un **contrôle de santé qui dit toujours « en bonne santé »** |
| B12-005 | S2 | `scraper-result` : aucun `throttle`, aucun horodatage — **rejeu à l'identique accepté 3/3** |
| B12-008 | S2 | **88/117 sans limitation de débit** ; le limiteur `api` **déclaré n'est attaché à rien** |
| B12-011 | S2 | **42/117 routes citées par aucun test** — dont **mot de passe, lien magique, 2FA, les 8 `/audiences`, et l'effacement RGPD** |
| B12-013 | S2 | `POST /companies/bulk-enrich` met en file **500 identifiants sans contrôle d'appartenance** |
| B12-014 | S2 | Trois gardes d'espace **« ouvertes en cas de doute »** (`ScraperRuns`, `Audiences`, `Tags`) |
| B12-015 | S2 | **16 routes lisent une entrée sans la valider** ; **7 formes de réponse d'erreur** coexistent |
| B12-018 | S2 | `GET /users` liste par `current_workspace_id` — un **pointeur d'affichage**, pas l'appartenance |
| B12-009 | S2 | `POST /auth/login` sans en-tête JSON rend **302 vers la racine de l'API** au lieu de 422 |
| B12-006 | S2 | `GET /search` **déclaré deux fois** : la ligne 99 est **morte en silence** |
| B12-017, B12-019 | S3 | `Api/Phase2/CampaignsController` **mort** ; `channels.php` : 2 canaux **jamais enregistrés**, donc la correction UUID du 16/08 **n'a jamais tourné** |

🔑 **La validation, chiffrée** — le mandat demandait « 44 contrôleurs pour 4 FormRequest : où est la
validation du reste ? ». Réponse mesurée : **4 FormRequest · 23 `validate()` inline · 3 contrats
internes · 16 routes sans rien · 19 stubs · 52 sans entrée**. Les **16 sans rien** sont nommées, et
elles incluent `GET /rgpd/export/{token}` (route **publique**) et `POST /internal/scraper-result`.

🔑 **`GET /search` déclaré deux fois : le mandat avait raison, et le détail est pire.** Le routeur ne
retient que `GlobalSearchController@index` ; la fermeture de `api.php:99` est **morte**. Mais **les
deux rendent le même corps vide** — donc **la collision est invisible**, et le serait restée.

**Sur A-001, l'agent confirme ma correction et la chiffre** : la condition n'est pas « sans
authentification » mais « **sans `Accept: application/json`** » → **500 sur 106 routes**. Les
**11 routes publiques sont épargnées** (`/up` 200, `/` 200, magic-link 200, `rgpd/export` 404,
`scraper-result` 401). *Le SPA n'est pas touché ; le sont les navigateurs en accès direct, les sondes
et les clients machine.*

**Réfutation d'une ligne de mon propre dossier commun** : le « plafond d'export **5 000**, silencieux »
que j'avais repris du mandat est **introuvable dans les trois contrôleurs d'export**. Il n'y a
**aucun plafond** — ce qui est pire, et rejoint B12-010.

**Non vérifié, déclaré comme livrable** (et les raisons sont bonnes) : le point 10 (`EXPLAIN`) sur les
117 routes — la base `axion_crm` a été **vidée par le `migrate:fresh` d'un autre agent**, conteneur à
charge 26-28 ; le point 17 « vu rouge » — suite Pest non rejouée pour la même raison ; le point 4 joué
sur **une seule** route ; **production et préproduction jamais interrogées** — l'agent précise que
**B12-001 et B12-004 reposent sur des valeurs livrées dans `.env.example`, et qu'une surcharge en
production changerait leur gravité**. *C'est exactement la réserve qu'il fallait poser : à lever en P4.*

---

### Complément à A-011 — les deux cas trouvés après sa rédaction

| # | La garde | Ce qu'elle prétend garder | Ce qu'elle mesure **réellement** | Trouvé par |
|---|---|---|---|---|
| 7 | L'instrument de **parité de capture** (`reconcile.ts:311`) | que rien ne se perd entre le site et le CRM | les lignes d'outbox **sans filtre de statut** : un `gave_up` **compte comme émis** | agents 32 et 6 |
| 8 | La garde e2e de `ErrorBoundary` | que l'écran d'erreur fonctionne | **un objet absent** — `ErrorBoundary` **n'est monté nulle part** | agent 27 |

Et un cas de la même famille, du côté des **assertions** plutôt que des gardes : la mesure de
performance de l'étape 0 conclut sur une production « **php-fpm + opcache** » (l.19 du rapport) alors
que la production tourne sous **`php -S`** (`Dockerfile.laravel:121`) — elle déclare même le critère 17
« impossible sur un serveur mono-thread » **sans voir que la production en est un** (A06-003).
*Le bon raisonnement, appliqué au mauvais objet, produit la bonne conclusion sur le mauvais système.*

---

## Agent 8 — les non-régressions du §A.1 : **une sauvegarde a été restaurée pour de vrai**
**Rapport** : `11_GRILLES/agent-08_non-regressions.md` · **Preuves** : `04_PREUVES/agent-08/` (36 fichiers)

**Le §A.1 compte cinq points, pas quatre**, et le résumé du mandat (« horodatage UTC ») est **inexact** :
le correctif retenu est `DB_TIMEZONE = APP_TIMEZONE = Europe/Paris`, **pas de l'UTC**. L'agent a mesuré
**le comportement, pas le mot**.

| # | Point « ne doit pas régresser » | Verdict |
|---|---|---|
| 1 | Sauvegardes de production restaurables | ✅ **TENU** (réserve : copie locale, pas hors-site) |
| 2 | Décalage horaire des dates du site corrigé | ✅ **TENU** |
| 3 | Intégration continue réelle | ⚠️ **TENU sur l'exécution, DÉFAILLANT sur le câblage** |
| 4 | Isolation par espace durcie | ⚠️ **NON RÉGRESSÉ sur la barrière, RÉGRESSÉ sur ce qui tourne derrière** |
| 5 | Formulaire du site réparé | ✅ **TENU** |

🔑 **Le critère 11 du §29 et le point 13 de la définition de fini sont TENUS — pour de vrai.**
`axion_crm_20260819T030001Z.sql.gz` (**724 926 343 octets**), streamée par SSH dans une base jetable
locale. **Les cinq tables de référence sont revenues au nombre exact : 20 726 338 lignes, écart nul** —
`companies` 4 295 349 · `contacts` 1 319 567 · `company_tag` 7 501 969 · `scraper_runs` 7 608 196 ·
`journalists` 1 257. Zéro erreur `psql`.
**Témoin négatif** : les mêmes comptages joués **pendant** le flux rendaient **0 partout** — la mesure
sait donc distinguer une restauration finie d'une restauration en cours.

| Id | Sév. | Titre |
|---|---|---|
| **A08-006** | **S1** | 🔴 **La tâche RGPD d'anonymisation des IP n'a JAMAIS fonctionné** : `ip::cidr / 24` n'est pas un opérateur valide |
| **A08-001** | **S1** | `coverage:refresh-matrix` échoue **71 fois sur 71** depuis l'armement du rôle applicatif (16/08 14:00) — vue matérialisée **figée**, **aucune alerte** |
| **A08-003** | **S1** | La suite de 780 tests **ne tourne jamais dans la configuration de production** (`CRM_DB_APP_ROLE_ENABLED=true` en prod, **absent partout en CI**) — **et deux tests verts affirment le contraire** |
| **A08-008** | **S1** | La sauvegarde restaure les données **mais pas les droits** (`--no-acl`) : une restauration de secours livre **une application incapable de lire**, et `restore-postgres.sh` annonce « Restore complet » |
| A08-002 | S2 | L'import de médias **refusé par la RLS en production**, même cause, même silence |
| A08-004 | S2 | **Schéma prod ≠ schéma CI** : `audit_logs` est en prod une table **ordinaire** à `workspace_id` **sans RLS**, que la garde exclut au motif — **faux en prod** — qu'elle serait partitionnée |
| A08-005 | S2 | **3 des 6 jobs de CI ne bloquent aucune fusion**, dont les gardes nées des **deux incidents les plus graves** du produit |
| A08-007 | S2 | Journal de production à **99,2 % de bruit** (23 658 erreurs Telescope sur 23 858) — **la raison mécanique** pour laquelle A08-001 et A08-006 sont passés inaperçus |

🔑 **Deux renversements qui comptent.**
1. **Le durcissement de l'isolation EST armé en production** — la documentation du dépôt affirme le
   contraire — et **c'est son armement, non son absence, qui a cassé des tâches planifiées pendant
   71 heures sans témoin**. On cherchait une régression de sécurité ; c'est une régression
   d'exploitation causée par la sécurité, que rien ne surveillait.
2. **A08-008 était DÉJÀ ÉCRIT dans le dépôt**, sous forme d'attente inversée, avec la prédiction :
   *« le jour où le drapeau passe à `true`, une restauration livre une application qui ne peut plus
   rien lire »*. **Ce jour est arrivé le 2026-08-16. L'attente est toujours verte.**
   *C'est A-013 sous une autre forme : l'information exacte était écrite, au bon endroit, et personne
   ne l'a relue quand la condition s'est réalisée.*

---

## Agent 6 — l'étape 0, ligne par ligne
**Rapport** : `11_GRILLES/agent-06_etape-0.md` · **Preuves** : `04_PREUVES/agent-06/`

**Le mandat se trompait : l'étape 0 EST sur `main`** — PR #174 fusionnée le 18/08 à 18:44 UTC
(`e577828`), **16/16 commits ancêtres**, 41 PR depuis, et la CI a réellement tourné sur la référence
(**780 tests / 6 503 assertions, 0 ignoré**).

| Verdict | Lignes | N |
|---|---|---|
| **CLOS** | 2, 4, 5, 6, 7, 9, 13 | **7** |
| **PARTIEL** | 1, 3, 3 bis, 8, 10, 11, 12 | **7** |
| **OUVERT** | 3 ter, 14 | **2** |

**Le journal annonce « 15 closes sur 16 ». Sept le sont au sens de leur propre critère de sortie.**
Et l'agent précise ce qui compte : **aucune ligne n'est vide** — les 16 ont produit un livrable réel.
**L'écart n'est pas un écart de travail, c'est un écart de clôture.** → c'est le cœur de **A-013**.

| Id | Sév. | Titre |
|---|---|---|
| **A06-001** | **S1** | Le rapport pare-feu **désignait la faille du 19/08** ; la ligne 14 a été déclarée close par-dessus → **A-013** |
| A06-003 | S2 | La mesure de performance de l'étape 0 conclut sur une production **qui n'existe pas** : elle écrit « php-fpm + opcache » quand `Dockerfile.laravel:121` pose `CMD ["php","-S",…]`. **Elle déclare même le critère 17 « impossible sur un serveur mono-thread » sans voir que la production en est un** |
| A06-004 | S2 | « Chaque table à `workspace_id` » **exclut le journal d'audit RGPD**, par **cécité de scan promue en décision** (`relkind='p'`) |
| A06-008 | S2 | Le journal annonce 15 closes sur 16 ; **sept** le sont |
| A06-002 | S2 | Ligne 3 ter déclarée « CLOSE en production » : `MAIL_MAILER: log` en préproduction (×3) **et** en production → **A-012** |
| A06-007 | S2 | La **seule preuve** de la ligne 1 (`console-locale.spec.ts`) **ne tourne dans aucun workflow** |
| A06-012 | S2 | La parité de capture s'arrête à l'outbox : **une livraison abandonnée compte comme reçue** |
| A06-010 | S2 | Le plan qui « fait foi » pour l'étape 0 **n'est versionné dans aucun dépôt** — et il a été **mis à jour sur disque en cours de session (v2.3 → v2.7)** : *personne ne peut dire quelle version de quel critère a été jouée* |
| A06-005, A06-006, A06-009, A06-011, A06-013 | S3 | garde de base reconstructible qui ne joue jamais le geste · baseline omettant `AudienceBuilderService`, **que l'étape 0 a elle-même modifié** · deux fichiers de test affirmant `CRM_DB_APP_ROLE_ENABLED=false` en production quand B11-010 le mesure `true` · trois numérotations · diagnostic Calendly faux |

*(A07-004 « le plan n'a jamais existé » avait été **réfuté** par le chef de chantier ; A06-010 en donne
la formulation juste : il **existe**, il n'est **pas versionné**, et il a **changé sous les pieds**.)*

---

## Agent 31 — le canal, côté site
**Rapport** : `11_GRILLES/agent-31_canal-cote-site.md` · **Preuves** : `04_PREUVES/agent-31/` (7 fichiers)

**16 points de capture, pas 14.** Le « 14 » du mandat correspond aux **14 `FORM_TYPES` du contrat**,
pas aux points de capture. **Trois surfaces portent des identités et n'émettent rien** :
`ChatEscalation`, `Client`, `Devis`.

🔑 **L'hypothèse RGPD que j'avais versée au dossier est DÉFINITIVEMENT RÉFUTÉE — et bien réfutée.**
Le CRM ne classe **pas** un `opt_out` en univers vivier : `assertCandidateConsentV2()` n'est appelée
que `if ($universe === 'vivier')`, et `SiteSyncClassifier::universe()` ne rend `vivier` que pour
`application_submitted` ou `form_submission`+`recrutement`. **Et l'agent a joué le témoin positif** :
en forçant la garde sur ce même événement, elle **rougit** (`REJET : consentement v2 requis … reçu :
careers-v1-2026-06-09`). *Le contrôle est capable de rougir ; il n'est jamais atteint sur ce chemin.*

**Mais une variante voisine est CONFIRMÉE, et elle est réelle** : `v1-2026-05-24` +
`form_type=recrutement` — **l'un des 12 choix visibles du formulaire unifié** — bascule en univers
vivier et part en **422 garanti**.

| Id | Sév. | Titre |
|---|---|---|
| **E31-001** | **S0** | `erasure` traité comme une désinscription : « 200 applied », **rien n'est effacé**. Le mot apparaît **3 fois** dans tout le module, **toutes déclaratives**, jamais dans un `if`, **jamais dans un test**. C'est **moins** que l'opt-out local, qui supprime l'abonné et touche 5 ensembles. **Les 3 suites du canal sont vertes 59/59 avec le défaut en place** *(confirme B14-002 ; 9ᵉ cas de A-011)* |
| **E31-002** | **S1** | Une demande « **Recrutement** » du formulaire unifié **ne peut jamais arriver** : 422 garanti, ou aucune ligne d'outbox |
| **E31-003** | **S1** | L'opposition vivier **n'est pas transmise** si le flux candidats est fermé — **sans ligne d'outbox, sans alerte, réponse « ok »** |
| **E31-004** | **S1** | La réconciliation compte comme « émis » un événement `gave_up` : **parité verte sur des leads perdus** |
| E31-005 → E31-007 | S2 | Réconciliation : **6ᵉ famille ignorée** (podcast) · **aveugle aux 6 changements d'état** · **faux positifs garantis** sur `submission` |
| E31-009 | S2 | L'opposition d'un candidat crée une activité `pending_match` **business** portant **son e-mail en clair** |
| E31-010 | S2 | Opposition **perdue en silence** si le déchiffrement de l'adresse échoue — réponse « ok » |
| E31-008, E31-011 → E31-013 | S2/S3 | Le contrat entrant n'accepte que 3 types : **le §22.6 est inapplicable par construction** · rafale d'abandons = 1 message/heure · 11 des 16 émetteurs sans `source_slug` · `tags[]` code mort **des deux côtés** |

**Critère 18 : non mesurable aujourd'hui**, et l'agent le démontre plutôt que de l'affirmer
(`CRM_SYNC_ENABLED` absent en local, `axion-ia-postgres` **arrêté depuis 3 semaines**, CRM local à
0 activité, prod interdite en écriture) — **avec témoin négatif** : le même `psql` sur une base qui
existe rend bien `58 migrations`.

---

## Agent 18 — le pipeline de collecte
**Rapport** : `11_GRILLES/agent-18_collecte.md` (847 l.) · **Preuves** : `04_PREUVES/agent-18/` (11 fichiers)

| Id | Sév. | Titre |
|---|---|---|
| **C18-016** | **S1→S0 (reclassé, D-012)** | 🔴 `MockServicesProvider` **sans aucune garde d'environnement**, et **son défaut est le mock** : `env('MOCK_MODE', true)`. Une variable **absente** suffit. `APP_ENV=production` **ne change rien**. Et l'effet n'est pas l'inertie : `MockLLMClient` alimente `step10_llm_classify`, **qui écrit dans `companies.signals` puis `save()`** — des **classifications fabriquées en base de production, sans marqueur** |
| **C18-011** | **S1** | 🔴 **Le pont Laravel → Node est rompu par le préfixe Redis.** Laravel écrit `axion_crm_pro_database_axion:scrape:google-maps` ; le `BRPOP axion:scrape:google-maps` **exact** de `base.ts:159` rend **vide** ; le même `BRPOP` voit **immédiatement** un job poussé sur la clef nue. **Le pont n'a, mesure faite, jamais pu fonctionner** — et le défaut est **masqué** par le retrait des workers du compose : il se déclenchera **au moment exact où l'on croira réactiver la collecte** |
| **C18-001** | **S1** | `CRM_SCRAPE_FUNNEL_ENABLED=false` par défaut → l'endpoint répond **`{"ingested": true}` sans rien ingérer** |
| **C18-007** | **S1** | Le quota `max_companies` **ne freine rien** : le moniteur remet 400 → 0 **toutes les 60 s** |
| **C18-008** | **S1** | **L'arrêt d'urgence n'arrête rien** : file Redis **non purgée** (4 jobs avant / 4 après), drapeau `cancelled:*` **lu par personne** |
| C18-006 | S2 | **Piège 10, cause corrigée** : dédup e-mail — `lower()` **SQL** ≠ `mb_strtolower` **PHP** sous `lc_ctype=C` |
| C18-014 | S2 | `WaterfallSentry` couvre **11 pannes silencieuses** et **ne peut rien émettre** (`SENTRY_LARAVEL_DSN` n'existe nulle part) |
| C18-018 | S2 | **Aucun des 13 scrapers n'est testé, aucun n'est déployé** — 11 branchés au registre, **0 déployé**, `social-light` pointe sur `https://api.example-social.com/`, **un domaine d'exemple** ; `_stub.ts` **code mort absolu** |
| C18-003, C18-015 | S3 | Essai à blanc : **0 ligne sur 114 tables** (témoin négatif : le même appel réel en modifie 4) — mais **3 séquences consommées** et de **vraies requêtes DNS** · **piège 22** : le seeder protège `enabled` mais **écrase `ttl_days`**, la valeur même que la migration promet éditable |

**Deux réfutations de l'agent, contre ses propres hypothèses** : il avait supposé le piège
`config:cache` → `.env` non chargé ; `Dockerfile.laravel:86` l'interdit et `configurationIsCached()`
rend **NON** — **ce chemin-là est fermé**. Et côté Node, **le mock ne peut pas fuir** (seule la chaîne
minuscule exacte `'false'` arme le réel) — mais **les deux moitiés divergent** : `MOCK_SCRAPERS=FALSE`
met le **backend en réel** et les **workers en mock**.

---

## Agent 27 — le design system
**Rapport** : `11_GRILLES/agent-27_design-system.md` (584 l.) · **Preuves** : `04_PREUVES/agent-27/`

**Emploi réel** : `Card` 29 écrans · `PageHeader` 27 · `Button` 23 · `EmptyState` 22 · `StatusPill` 17 ·
`KpiCard` 15 · … · **`PageShell` 3** · **`IconButton` 2** · **`FormField` 1**.
**3 composants morts** (0 consommateur) : `Stat`, `ErrorBoundary`, `CardFooter`.
**2 écrans à 0 composant du système** : `/coverage` et `404`.
**23 écrans sur 37 recopient du balisage**, 61 occurrences.

| Id | Sév. | Titre |
|---|---|---|
| **D27-002** | **S1** | 🔴 **4 règles `!important` de `index.css:88-91` neutralisent 174 déclarations `dark:`** — mesuré **en navigateur**, avec témoin négatif. *Le thème sombre réel n'est pas celui que les composants décrivent, et toute correction de contraste portée sur ces 4 propriétés est **silencieusement sans effet**.* |
| D27-010 | S2 | `ErrorBoundary` **jamais monté** — **et la garde e2e qui prétend le surveiller mesure un objet absent** *(8ᵉ cas de A-011)* |
| D27-003 | S2 | `/coverage` : **0 composant importé**, 4 noms du système **redéfinis localement** — son `SegmentedControl` local perd `role=tablist`, `aria-selected` **et tout le mode sombre** |
| D27-004 | S2 | 23 écrans sur 37 recopient : les copies **perdent l'anneau de focus clavier, les rôles ARIA et le mode sombre** |
| D27-005 | S2 | **Aucun composant `Table`** ; 3 idiomes sur 16 écrans ; **le même en-tête de 210 caractères copié à l'identique dans 8 fichiers** |
| D27-006 | S2 | **92 couleurs claires sans `dark:`** ; `SizeCategoryBadge` et `QualityBadge` **n'ont aucun mode sombre** |
| D27-007 | S2 | Jeton d'ombre **dupliqué en littéral et déjà divergé** — **piège 15 en situation** |
| D27-008 | S2 | **30 champs bruts en 19 variantes** de classes alors qu'`Input` existe ; `FormField` employé par **1** écran |
| D27-001, D27-013 | S2/S3 | `Stat` **recopié à la main dans les 2 écrans qui en avaient besoin, et les 2 copies ont déjà divergé** · `PageShell` : son commentaire annonce **18 pages**, la mesure en donne **3, dont 2 stubs morts** |

**Et une honnêteté de méthode à porter au crédit** : le détecteur de couleurs a été **validé par témoin
planté positif et négatif**, et **ses deux premières versions étaient fausses** (97 avec faux positifs,
puis 75 avec faux négatifs) — **les trois sont archivées**. L'agent déclare aussi n'avoir **ouvert aucun
écran pour de vrai** : ses constats portent sur le code. *(Et c'était la bonne prudence : l'atelier
servait alors un bundle périmé — D23-001.)*

---

## Agent 15 — le moteur RGPD
**Rapport** : `11_GRILLES/agent-15_rgpd.md` · **Preuves** : `04_PREUVES/agent-15/` (5 fichiers)

**Le tableau que le mandat demandait, et il est accablant.** **101 tables** en base ; **31 portent une
donnée personnelle**.

| Mesure | Couvertes | **Trouées** |
|---|---|---|
| Effacement art. 17 (les deux services, cascades FK comprises) | 22 / 31 | **9** |
| Effacement **par la console seule** — le seul chemin joignable par un humain | 19 / 31 | **12** |
| **Export art. 15/20** | **4 / 31** | **27** |
| Rétention appliquée par une purge **active** | 5 / 31 | **26** |

| Id | Sév. | Titre |
|---|---|---|
| **B15-001** | **S0** | 🔴 **Une personne effacée par la console REVIENT au vivier à la candidature suivante** |
| **B15-003** | **S0** | L'export des articles 15 et 20 couvre **4 tables sur 31** |
| **B15-006** | **S0** | L'effacement **laisse l'adresse et le téléphone en clair dans six tables** — dont `activities`, qui garde `{"tel":"+33…","email":"jean.dupont@…"}` **en clair dans son payload** (la FK est en `SET NULL`, **la ligne survit**) |
| **B15-010** | **S0** | **Les routes RGPD n'exigent aucune permission** : un compte `viewer` peut **effacer et exporter n'importe qui** |
| **B15-002** | **S1** | `AntiReinsertionTest` est **vert** et mesure le mauvais objet *(10ᵉ cas de A-011 — et le plus retors)* |
| B15-004 | S1 | Deux purges d'entreprises **sans aucune garde** ; l'une supprimerait **100 % de la base de volume** |
| B15-008 | S1 | Sept commandes destructives, **trois planifiées**, **zéro test de ce qu'elles suppriment** |
| B15-013 | S1 | Le **jeton d'export est persisté en clair** dans `rgpd_requests.metadata` |
| B15-014 | S1 | §21.2 du CDC : **deux exigences non servies, trois partielles** |
| B15-005 | S2 | Le masquage des coordonnées est **contournable par la route de détail** *(confirme B12-002, B10-005)* |
| B15-007 | S2 | `RetentionPurge` **annonce deux purges qu'il n'exécute pas** |
| B15-012 | S2 | Le jeton d'export **n'est pas à usage unique**, contrairement à son propre docbloc |
| B15-009, B15-011 | S3 | La liste de suppression n'a qu'un lecteur · `users.last_login_ip` **n'est jamais anonymisée** |

🔑 **B15-001, et la démonstration est exemplaire.** Effacement par la console, puis ré-ingestion par
le canal du site :
```
univers business  ->  opted_out, contacts = 0        <- la garde tient
univers vivier    ->  CREATED, candidates = 1        <- identite complete : email + nom + prenom + telephone
```
**Témoin négatif joué** : une personne **jamais effacée** passe par les deux mêmes événements et
atterrit elle aussi (`created`) — *le canal fonctionne, et le contrôle distingue bien les deux cas.*
**Cause** : `addOptOut()` n'écrit pas de `scope`, donc `DEFAULT 'business'` ; or la garde d'ingestion
interroge `scope='vivier'`. `SiteGdprService::erase(scope:'both')` écrit bien les deux. **Deux chemins
d'effacement, deux comportements.** Armé, pas encore déclenché (`candidates = 0`, ingestion candidats
fermée) — *il se déclenchera au moment exact où l'on ouvrira le vivier.*

🔑 **B15-002 est le piège 19 dans sa forme la plus retorse.** Le test **nommé** « anti-réinsertion »
reproduit la requête du funnel de **scraping** avec `scope='business'` **en dur**, et affirme
`expect($ligne->scope)->toBe('business')`. **Il consacre comme correct le réglage exact qui produit
B15-001**, et il reste **vert pendant que la personne revient**. *Une garde qui non seulement rate le
défaut, mais le certifie.*

**Deux points d'honnêteté à porter au crédit de l'agent** :
- **L'adresse en clair dans `opt_out`** — le point 7 du mandat — **est refermée pour les nouvelles
  lignes** (mesuré : `email = null`). Mais `phone` y reste en clair, et six autres tables gardent
  l'adresse. *Il ne déclare ni « corrigé » ni « toujours vrai » : il mesure les deux moitiés.*
- **Un faux positif écarté** : la contrainte `*_empreinte_obligatoire_check` manquait dans sa base
  jetable (7 `CHECK` perdues au clonage) ; il a vérifié — **elle existe** dans `axion_crm` et
  `axion_crm_test`. **Non rapportée.** *C'est exactement le réflexe que la règle 3 demande.*

---

## Agent 22 — cartographe des écrans : **le verdict de connexion, mesuré de bout en bout**
**Livrables** : `11_GRILLES/ecrans.md` · `11_GRILLES/intentions.md` · **Preuves** : `04_PREUVES/agent-22/`

### 🔴 « La console est-elle utilisable ? » — **NON. On peut se connecter, et on ne peut rien faire ensuite.**

C'est la mesure qui **ferme la chaîne de A-012**, et elle est jouée en entier :

```
POST /api/v1/auth/login       -> 200   {"first_login_completed_at": null}
GET  /api/v1/auth/me          -> 200   {"roles":["owner"]}
GET  /api/v1/dashboard/stats  -> 403   first_login_required
                                       next_step: /auth/2fa/setup
POST /api/v1/auth/2fa/setup   -> 500   column "two_factor_secret" does not exist
```
**Témoin négatif** : un mauvais mot de passe rend **422** — l'authentification fonctionne donc
réellement, et le 200 n'est pas un artefact.

> **On entre, et le premier écran réel est refusé — définitivement.** Le serveur exige l'enrôlement
> 2FA avant tout usage, l'enrôlement écrit **trois colonnes qui n'existent pas** (A07-001), et
> **aucun écran n'expose cet enrôlement** (D22-001, **S0**, la moitié « interface » qui manquait).
> Le script `definir-mot-de-passe-crm.sh` posé pendant l'audit **ne suffira pas** : il rend le mot de
> passe, pas l'accès.

**37 écrans sur 37 ouverts** dans un vrai navigateur — et l'agent dit exactement **ce que cela mesure**
et ce que cela ne mesure pas : les écrans ont été ouverts **non connecté, API injoignable**, donc la
mesure porte sur les colonnes **« état vide » et « état erreur »**, **pas** sur l'état nominal.

🔑 **Et il a refusé de modifier la sécurité du poste.** Chrome refuse `https://app.localhost`
(l'autorité locale de Caddy n'est pas dans le magasin Windows). **Il n'a pas installé d'autorité
racine** — geste de sécurité qui appartient au dirigeant — et a contourné par un conteneur Caddy
temporaire en lecture seule, **retiré depuis**. *Le bon réflexe : il aurait été plus simple d'installer
le certificat, et c'eût été une modification permanente de la machine de quelqu'un d'autre.*

🔑 **Conformité D-011 prouvée, pas affirmée** : son balayage ne portait **pas** sur le bundle périmé.
Équivalence établie **témoin par témoin** entre le bundle balayé et le bundle officiel reconstruit
(`Journaux de collecte` 3/3, `Runs de scraping` 0/0, `console/personnes` 4/4), puis re-confirmée en
direct sur `index-BVK1vh1a.js`. Migrations = **58 avant et après**.

### L'inventaire des intentions — **72 listées**, le code en a rendu plus que les 30-50 demandées

| trouvable | avec effort | introuvable | **impossible** |
|---|---|---|---|
| **30** (42 %) | **15** (21 %) | **5** (7 %) | **22 (30 %)** |

> **Le produit sait collecter ; il ne sait pas travailler.**
> Groupe « collecte » : **7 trouvables sur 9, aucune impasse**.
> Groupe « ouvrir sa journée » : **6 impasses sur 7**.
> *C'est, en une ligne, l'écart entre ce qu'est le produit aujourd'hui et ce que le CDC lui demande
> de devenir.*

| Id | Sév. | Titre |
|---|---|---|
| **D22-001** | **S0** | **Aucun écran n'expose l'enrôlement 2FA**, que le serveur **exige avant tout usage** |
| **D22-002** | **S1** | Données non obtenues → **les écrans affirment « 0 » et « aucun »** sur **12 écrans**. `/users` annonce « Aucun utilisateur » **alors qu'il en existe un** |
| D22-003 | S2 | Trois écrans de détail rendent une **page entièrement blanche** (`/media/$id`, `/campaigns/$id`, `/audiences/$id`) |
| D22-004 | S2 | « Console non activée » affiché **alors que `CRM_CONSOLE_V2_ENABLED=true`** — et **le fichier décrit lui-même la faute qu'il commet** : le correctif a été posé sur `isPending`, jamais sur `isError` |
| D22-006 | S2 | **33 écrans sur 37 n'interrogent jamais rôle ni permission** — `/users`, `/settings`, `/audit-logs`, `/rgpd/requests` compris *(la moitié « interface » de B12-003)* |
| D22-005 | S2 | `/contacts` devient **orphelin** dès la console v2 ouverte, tout en restant vivant |
| D22-007 | S2 | L'écran « Page introuvable » est **livré dans le bundle et ne s'affiche jamais** (`path: '/*'` inopérant en TanStack Router v1) |
| D22-008 | S3 | « Profil » et « Paramètres » mènent à la **même page, sans onglet personnel** — alors que `definir-mot-de-passe-crm.sh` prescrit de changer son mot de passe « depuis l'interface » |

**D22-002 mérite d'être lu deux fois** : c'est le même patron que `/saved-views`, `/ai-act/register` et
la recherche globale (**A-002**, **B10-013**), mais **à l'écran** et **sur 12 écrans** : *quand la
donnée n'arrive pas, le produit n'affiche pas une erreur — il affirme qu'il n'y a rien.* Un opérateur
ne peut pas distinguer « la base est vide » de « la requête a échoué ».

**Déblocage à faire une fois, par le dirigeant** — porté au `06_RESTE-WILL.md` :
`docker cp axion-crm-caddy:/data/caddy/pki/authorities/local/root.crt` puis import dans les autorités
racines de confiance. **Sans lui, aucun agent ne peut exécuter le §11 sur `https://app.localhost`.**

---

### Complément à A-011 — les cas 9 et 10, et le plus retors de tous

| # | La garde | Ce qu'elle prétend garder | Ce qu'elle mesure **réellement** | Trouvé par |
|---|---|---|---|---|
| 9 | Les **3 suites du canal**, côté site | que l'effacement traverse | elles sont **vertes 59/59 avec `erasure` cassé** — le mot n'apparaît dans **aucun `if`, aucun test** | agent 31 |
| **10** | **`AntiReinsertionTest`** | qu'une personne effacée **ne revient pas** | elle reproduit la requête du funnel de **scraping** avec `scope='business'` **en dur**, et affirme `expect($ligne->scope)->toBe('business')` — **elle consacre comme correct le réglage exact qui produit B15-001**, et reste verte **pendant que la personne revient** | agent 15 |

**Le cas 10 est le plus grave des dix**, et il mérite d'être nommé pour ce qu'il est : une garde qui
ne se contente pas de **rater** le défaut — elle le **certifie**. Elle porte le nom du défaut qu'elle
laisse passer. *C'est la limite de la règle 2 : on peut voir un test rougir, le voir verdir, et n'avoir
rien gardé du tout — parce qu'on a figé dans l'assertion la valeur même qui casse.*

---

## Agent 33 — les entrées de contacts, côté site
**Rapport** : `11_GRILLES/agent-33_entrees-contacts.md` · **Preuves** : `04_PREUVES/agent-33/`

**Les 14 finalités de formulaire du CDC sont exactes** — 12 dans le formulaire unifié + `podcast` +
`simulateur_roi`. **L'écart n'est pas dans le compte, il est dans la distribution** : ces 14 finalités
ne passent que par **4 chemins de code**, et le chatbot en écrase une (il émet `form_type: "autre"`,
**indiscernable** sauf par `source_slug`).

| Id | Sév. | Titre |
|---|---|---|
| **E33-006** | **S1** | 🔴 **`escalader_question` écrit l'adresse EN CLAIR** (`escalader-question.ts:46`), dans une colonne `@db.Citext`, **sans aucun import de `pii-crypto`** — avec la question (2 000 car.) et le contexte (4 000 car.) en texte libre. **Et `chat_escalations` ne porte aucun hachage de recherche** : ces personnes sont **introuvables pour un article 15 ou 17** — même mode de panne que B10-004 |
| **E33-001** | **S1** | Le tunnel `booking` (**20 fichiers**) et **2 outils du chatbot** n'émettent **rien** vers le CRM |
| **E33-002** | **S1** | Le chatbot **exige un consentement RGPD explicite** — et **ne le transmet pas** |
| E33-003 | S1 | La réconciliation compte un `gave_up` comme émis — **E32-003 contre-vérifié, il tient** |
| E33-007 | S2 | Le lead du chatbot part **en clair vers Telegram**, **après** avoir été chiffré en base |
| E33-008 | S2 | **17 finalités sur 22** n'émettent aucun `source_slug` |
| E33-004, E33-005 | S2 | 5 familles comparées, 6 émises : les demandes podcast **ne sont jamais vérifiées** · **faux « manquant » permanent** sur chaque candidature commerciale |
| E33-009 | S3 | **Deux affirmations du mandat réfutées par la mesure** |

🔑 **Deux réfutations du mandat, et elles corrigent aussi une de mes propres corrections :**

1. **« `calendly_canceled` n'est pas émis » est FAUX.** Mesuré, statut par statut : `réservé`,
   `annulé` et `absent` sont **automatiques** (3 émetteurs, sondage 1×/min et 1×/10 min). **Seul
   `honoré` reste manuel** — *par conception assumée et documentée : rien dans l'API de Calendly ne
   dit qu'un rendez-vous a eu lieu.* Le commentaire du code note même que l'asymétrie **avait déjà été
   corrigée**. Seul A07-011 (« honoré manuel ») tient.
2. **Le plafond d'export existe — et j'avais tort de dire qu'il n'existait pas.** `PLAFOND_EXPORT =
   50 000`, appliqué, et **bruyant** (avertissement + Sentry + journal RGPD). Le « 5 000 » **était
   vrai** et a été remplacé le **2026-08-18**, *la veille de l'audit*. **L'agent 12 cherchait dans les
   contrôleurs du CRM ; la fragilité F14 porte sur l'export du SITE.** Les deux mesures étaient justes,
   sur deux objets différents — et j'ai généralisé la mauvaise. **Côté site : corrigé. Côté CRM : ni
   plafond ni journal, et ça reste un défaut (B12-010).**

**B13-001 confirmé avec témoin négatif, et chiffré** : le contrat **sait** porter un SIREN, le
normalisateur **existe et est appliqué** — et **aucun appelant n'en passe, aucun schéma n'en collecte**.
Remède chiffré par l'agent : **~6 h** pour couvrir les 4 points qui portent la valeur commerciale,
**sans toucher au contrat ni au CRM**.

⚠️ **Deux réserves honnêtes de l'agent, qui deviennent des questions ouvertes** :
`encryptPii` est un **no-op silencieux** sans `PII_ENCRYPTION_KEY`, dont la présence en production
n'a pas pu être vérifiée — *« le chatbot chiffre » reste donc conditionnel*. Et l'état réel de
`CRM_SYNC_ENABLED` en production est inconnu : **si le drapeau est à OFF, rien n'a jamais transité, et
l'ordre des priorités change entièrement.** → confié à l'agent 37, qui a l'accès.

---

## Agent 49 — le contrat d'échange §22, les deux sens confrontés
**Rapport** : `11_GRILLES/agent-49_contrat-22.md` · **Preuves** : `04_PREUVES/agent-49/` (5 fichiers)

⚠️ **Correction au mandat** : le §22.2 compte **14 familles**, pas « 6 × 2 » — **8** entrantes, **6** sortantes.

### Le chiffre qui résume le canal : **67 événements exigés, 13 émis (19 %), UN SEUL avec un effet conforme**

**Sens site → CRM — 48 exigés, 11 émis**

| Famille | Exigés | Émis | L'écart |
|---|---|---|---|
| Capture | 6 | **6** | 0 sur le vocabulaire, **total sur l'effet** : 100 % `pending_match`, **0 fiche créée** |
| Rendez-vous | 5 | 4 | « reporté » sans valeur ; **ni l'heure, ni le lieu ne traversent** |
| Devis | 6 | **0** | 6/6 — *et la donnée existe côté site* |
| Facturation | 5 | **0** | 5/5, **y compris « numéro client attribué », annoncé existant** |
| **Livraison** | **22** | **0** | **22/22 — la plus grosse famille du §22, et la plus vide** |
| Réclamation · Messages sortants | 3 | **0** | 3/3 |
| Chatbot | 1 | 1 | émis **par une branche sur deux** (`escalader-question` muette) |

**Sens CRM → console — 19 exigés, 2 émis.** Chiffre obtenu **indépendamment** de l'agent 14, et
**identique** : Consentement 3→2 · Identité 3→**0** · Commercial 4→**0** · Réclamation 1→**0** ·
Rendez-vous 5→**0** · Interaction 3→**0**.

> **Le seul événement sortant qu'un humain peut déclencher est `erasure` — celui qui n'a aucun effet.
> L'autre, `consent_optout`, fonctionne, et n'est appelé par aucun écran.**

| Id | Sév. | Titre |
|---|---|---|
| **I49-001** | **S1** | 13 des 67 événements du §22.2 sont émis ; le seul sortant atteignable par un humain est celui qui **n'a aucun effet** |
| **I49-007** | **S1** | 🔑 **Critère 18 : l'instrument n'interroge JAMAIS le CRM** — et **même parfait, il rendrait « écart zéro » sur un CRM qui n'a créé aucune fiche.** Le critère est rédigé en événements **reçus** : **il ne peut pas faire rougir B13-001** |
| **I49-004** | **S1** | §22.5 « une seule identité » : **deux magasins disjoints**, aucun OIDC/SAML/SSO. Témoin négatif idéal : les seules occurrences de « SSO » côté site sont du **texte commercial**. Croisé avec A-012, **le critère 23 n'est pas à 0 % — il est *injouable*** : son protocole exige d'ouvrir les deux consoles, et l'une ne s'ouvre pas |
| **I49-006** | **S1** | §22.7 : le seul tableau de bord existant (côté site, **6/7**) **n'annonce pas le silence de l'autre sens**. `inbound.lastAt` est calculé et affiché, **jamais comparé**. Témoin négatif : `CRM_SYNC_BACKLOG_THRESHOLD = 50` existe pour l'autre sens |
| **I49-003** | **S2** | §22.4 : **0 lien croisé sur 12**. `grep -rniE "axionia\|axion-ia\.(fr\|com)" frontend/src` → **aucun résultat** — le CRM ne porte **aucun** lien vers la console, pas même le lien permanent du §22.5. Témoin négatif : le même grep trouve les 9 `target="_blank"` existants |
| I49-002 | S2 | §22.3 : **2 interdits sur 5 sont une décision écrite et appliquée** (l'en-tête de `types.ts` les interdit mot pour mot, clé inconnue = **422**), **2 sont sans objet** (ni enregistrement, ni transcription, ni ressenti n'existent), et **le 5ᵉ n'est appliqué que dans un sens** |
| I49-005 | S2 | §22.6 : **la carte n'existe que dans le CDC**. Ses 5 règles : **0/5** |
| I49-008 | S2 | Critère 5 : **hors budget par construction** dans le sens sortant (`everyFiveMinutes()`, et `skip()` → latence **infinie**) ; instrument de latence **faussé de +7 200 s** en entrant |
| I49-009 | S2 | Le §22.2 **annonce en gras 3 éléments qui n'existent pas** |

🔑 **Le retournement du §22.3, et il est fin** : ce paragraphe interdit au canal de transporter certaines
choses. Mesuré : **2 interdits sur 5 sont réellement appliqués — mais dans le sens qui n'en a pas
besoin.** `parseInboundPayload` du site **ne refuse aucune clé inconnue**. *Le §22.3 a été écrit pour
un CRM riche ; c'est la console qui détient le contenu.* **Cela recoupe E32-002** et renforce
l'arbitrage B1 remonté au dirigeant.

🔑 **Et le constat le plus utile pour la suite** : **personne, dans cet audit, n'a joué l'aller-retour
signé de bout en bout** entre les deux dépôts — **alors que le harnais existe** :
`axionia/scripts/e2e-crm-sync/mock-crm.ts`. *C'est le contrôle qui manque le plus à ce canal, et il
est à portée de main.* → **porté au périmètre de P4.**

---

## Agent 37 — secrets et échanges : **la mesure en production que personne n'avait pu faire**
**Rapport** : `11_GRILLES/agent-37_secrets-echanges.md` · **Preuves** : `04_PREUVES/agent-37/`

| Id | Sév. | Titre |
|---|---|---|
| **F37-001** | **S0** | 🔴 `POST /api/internal/scraper-result` **accepte une signature HMAC forgeable par quiconque, EN PRODUCTION** : `WORKER_INTERNAL_HMAC_SECRET` est **vide** sur le serveur, et le contrôle **inline** est **fail-open** — il n'a **pas** la garde `$secret === ''` que porte la classe durcie `HmacSignature`. Et le funnel est **à `true`** : l'ingestion atterrit **en base de production** |
| **F37-002** | **S1** | `MockServicesProvider` sans garde d'environnement — **et 6 services sont MOCKÉS EN PRODUCTION** |
| **F37-003** | **S1** | La **préproduction sert des pages de débogage Laravel de 880 Ko en public** (`APP_DEBUG=true`) : trace d'appels, chemins, IP clientes |
| F37-006 | S2 | `config('telescope.enabled')` = **`true` en production** (défaut vendor) **malgré** le garde du provider applicatif — **cause racine de A-007**, avec **169 653 erreurs** dans le journal |
| F37-008 | S2 | **PII dans le `laravel.log`** : **14 adresses distinctes**, **129 valeurs distinctes de cookie de session en clair**, **24 055 lignes avec IP client + user-agent**. Fichier hôte : **1,0 Go**, en **`-rwxrwxrwx`**, non tourné |
| F37-004 | S2 | **Ni CSP, ni Permissions-Policy, ni COOP, ni CORP** en production **ni** en préproduction |
| F37-007 | S2 | L'origine `46.62.248.239:443` **sert directement l'application** — contournement de Cloudflare et du WAF |
| F37-009 | S2 | Webroot en **`1777`**, `storage` et `bootstrap/cache` en **`777`** *(suite de F40-013)* |
| F37-011 | S2 | Le **mécanisme qui rendrait une rotation Postgres impossible**, décrit — avec les 3 gestes qui la rendraient seulement *possible* le jour venu *(la rotation elle-même reste refusée, D-005)* |
| F37-005, F37-010 | S3 | La production accepte l'origine CORS **`https://app.localhost` avec `credentials`** · mot de passe Redis de production = **4 caractères minuscules** |

🔑 **F37-001 fait passer B12-004 d'une déduction à une mesure.** L'agent 12 avait trouvé le défaut
**dans le code**, en précisant honnêtement que sa gravité dépendait de valeurs prises dans
`.env.example` et qu'« une surcharge en production changerait la donne ». **Elle ne change pas la
donne : elle l'aggrave.** Le secret est **vide sur le serveur**, le contrôle est **fail-open**, et le
funnel est **ouvert**. La forgeabilité a été **prouvée en local**, l'atteignabilité par un **401 sur
requête non signée** — et l'agent **n'a envoyé aucune requête mutante** vers la production. *C'est la
bonne façon de prouver une faille sans l'exploiter.*

🔑 **Et l'origine du défaut est nommée** : la migration vers la classe durcie `HmacSignature` **a été
faite pour SiteSync et jamais rétroportée** sur le contrôleur dont elle s'inspirait. Contre-vérification
de la règle 7 menée jusqu'au bout : `HmacSignature` est **confirmée solide** sur SiteSync/Gdpr (secret
64 caractères, `verify` **fail-closed**) et sur ZeptoMail (**503** si le jeton est vide). **Le seul autre
usage du patron est resté fail-open.**

🔑 **Une inversion que personne n'attendait** — les en-têtes de sécurité :

| En-tête | Production | Préproduction | **Local** |
|---|---|---|---|
| HSTS · X-Frame-Options · X-Content-Type · Referrer-Policy | ✅ | ✅ | ✅ |
| **CSP** | ❌ | ❌ | **✅ strict** |
| **Permissions-Policy · COOP · CORP** | ❌ | ❌ | **✅** |

**L'environnement le mieux durci est l'atelier ; le moins protégé est la production.** *Sixième
divergence atelier ↔ production — et la première qui va dans ce sens-là.*

**Sur les 21 clés absentes du `.env` de production** : **une seule a un défaut franchement nuisible**
(`TELESCOPE_ENABLED`). Mais **6 des 20 autres ne sont acceptables que par coïncidence, pas par
contrat** — `DB_APP_USERNAME` (le nom du rôle RLS « tombe juste »), `HUNTER_API_KEY`,
`BRAVE_SEARCH_API_KEY`, `WEBSHARE_*` (sans effet **seulement parce que le service est mocké** — ce qui
renvoie à F37-002), et les deux seuils d'alerte de coût Google Places. **Le motif est le jumeau de
S-3** : *l'atelier n'a pas la configuration de la production — et la production n'a pas la sienne non
plus, elle emprunte des défauts de framework.* **Une valeur implicite est une décision que personne ne
peut relire.**

⚠️ **Nuance que j'ajoute sur F37-008, pour ne pas surinterpréter** : les 129 cookies de session en
clair ne contredisent **pas** la mesure « 0 session, 0 jeton » des agents 16 et 40. Laravel émet un
cookie de session **à tout visiteur, même non authentifié** ; ces valeurs sont donc très probablement
des sessions **anonymes**. Cela reste une fuite (IP + user-agent + identifiant réutilisable pendant
7 200 s dans un fichier de 1 Go lisible par tous), **mais ce ne sont pas des sessions d'utilisateurs
connectés** — il n'y en a jamais eu. **À trancher en P4 par un contrôle du contenu associé.**

---

## Agent 21 — la qualité des données, mesurée **sur la production**
**Rapport** : `11_GRILLES/agent-21_qualite-donnees.md` · **Preuves** : `04_PREUVES/agent-21/`

**La provenance d'abord, parce que c'est ce qui donne sa valeur au reste** : **tous** les chiffres
viennent de la **production**, en sessions `TRANSACTION READ ONLY`, `SELECT`/`EXPLAIN` uniquement.
**Aucun chiffre synthétique** — les bases `perf` n'ont pas été touchées. *C'était l'avertissement
principal de sa mission, et il a été tenu à la lettre.*

### Les doublons

| | groupes | surnuméraires | % |
|---|---:|---:|---|
| `companies` même SIREN | 0 | **0** | l'unicité tient |
| `companies` nom normalisé + ville | 38 451 | **64 523** | 1,50 % |
| `companies` même téléphone | 31 885 | **162 025** | 3,77 % (borne haute) |
| **`contacts` même e-mail** | 49 492 | **176 218** | 🔴 **42,93 % des 410 481 contacts joignables** |
| `contacts` nom + entreprise | 0 | **0** | `UNIQUE(normalized_hash)` tient |

`contacts` même téléphone = 0 — **et pour cause : 0 contact sur 1 319 567 porte un téléphone.**

| Id | Sév. | Titre |
|---|---|---|
| **C21-003** | **S1** | **Aucune unicité sur `contacts.email`** : **176 218 doublons, 42,93 %** des contacts joignables |
| **C21-004** | **S1** | **82,58 % des `quality_score` contredisent la formule qui les produit** (3 546 986 sur 4 295 349, recalculés en `SELECT` pur ; 3 484 663 **sous-évalués**). Cause : **le trigger n'écoute pas l'`INSERT`** |
| **C21-006** | **S1** | **909 086 personnes (68,89 %) n'ont aucun moyen de contact**, et `legal_basis` est **NULL sur les 1 319 567** — *matière directe de C19-007* |
| **C21-001** | **S1** | Les recherches d'e-mail — **dont 5 sur le chemin RGPD art. 15/17** — n'utilisent **aucun index** : **Seq Scan 1 070 ms** contre **1,4 ms** en témoin positif via citext indexé, soit **776×** |
| **C21-008** | **S1** | Le type de relation **n'est porté par aucune personne** : **1 319 567 contacts sans colonne de type** |
| C21-007 | S2 | Doublons d'entreprise : **64 523** par nom+ville, **162 025** par téléphone |
| C21-005 | S2 | Le palier « complete » (≥ 90) est atteint par **0 fiche sur 4 295 349** ; **80,80 %** du stock est à **zéro** ; le maximum observé est **85** |
| C21-002 | S2 | Divergence de repli de casse SQL ↔ PHP : **mécanisme prouvé, exposition mesurée nulle** |
| C21-009 | S3 | **1** seul nom doublement encodé sur toute la base ; 59 tags orphelins sur 217 |

🔑 **C21-002 est un modèle de retenue, et je le signale parce que c'est rare.** L'agent avait toute
latitude pour transformer le piège 10 en constat spectaculaire. Il a mesuré, et il écrit :
*« le mécanisme est prouvé ; son exposition actuelle est nulle. Je ne présente pas l'un pour l'autre. »*
**Doublons qui échappent aujourd'hui à cause de cet écart : exactement 0** — 0 e-mail non-ASCII,
0 majuscule sur 410 481, et les groupes comptés par `citext` et par un repli PHP sont **identiques**.
La garde tient **par convention applicative**, pas par contrainte. Et il ajoute la nuance qui compte :
*« un index unique sur `lower(email)` protège-t-il encore ? » — **il n'en a jamais existé**. La
protection n'est pas dégradée, elle est absente.*

**Ce qui est CONFORME, mesuré et à ne pas redécouvrir** : SIREN **99,99 %** renseigné et **100 %
passent Luhn** (témoin valide/invalide validé) · **base propre à l'encodage** — 0 caractère de
remplacement, 0 entité HTML, **un seul** double encodage sur toute la base, les trois motifs validés
**positivement et négativement** · le backfill des tags vérifié **au caractère près**
(`src:scraping-insee` = **4 294 895**) · `companies_entites_sans_siren` **cohérente** (446 fiches,
446 avec ancre) · et « Dupont/DUPONT/dupont » **sont bien attrapés**, parce que le PHP **fait calculer
le hachage par Postgres** au lieu de le réimplémenter.

**Sur le reclassement de type** : **1 319 567 fiches concernées, mais 0 par requalification de
valeur.** Les trois contradictions sont confirmées dans le code, et **deux sont latentes** :
`relation_type = prospect` sur **100,0000 %** des entreprises, **0** en `conference`/`newsletter`,
**0** portant deux tags de type. *La seule contradiction à portée totale est structurelle : il n'y a
pas de colonne de type sur `contacts`.*

⚠️ **Deux faits d'exploitation remontés au passage, à verser au bloc F** : la production porte
**59 migrations** quand le dépôt en compte **58** — *confirmation indépendante de F40-006* — et une
requête a échoué en production sur **`No space left on device`** (mémoire partagée sous parallélisme),
rejouée avec `max_parallel_workers_per_gather = 0`. **À croiser avec les 25 Go libres et l'absence
d'alerte de trajectoire disque.**

---

### [F36-011] 🔴 Un compte en lecture seule peut exporter les 4 295 349 fiches nominatives — **garde vue rouge**
- **Sévérité**      : **S0**
- **Domaine**       : sécurité / conformité
- **Référence**     : `e8924b8`
- **Emplacement**   : `backend/routes/api.php` — `GET /companies/export`, `/media/export`, `/journalists/export`
- **Constat**       : les trois routes d'export ne sont protégées **que par un `throttle`**. Le commentaire du dépôt le dit lui-même : *« le throttle limitait la **CADENCE**, pas le **DROIT** »*. Un compte de rôle `viewer` — un lecteur — obtient **200** et emporte **4 295 349 fiches nominatives** hors du système.
- **Preuve**        : c'est **la seule garde de permission vue rouge de tout l'audit**, et elle l'a été proprement :
  ```
  FAILED  Tests\Feature\ExportPermissionTest > un viewer NE PEUT PAS exporter les entreprises
  Expected response status code [403] but received 200.
  Failed asserting that 200 is identical to 403.
    at tests/Feature/ExportPermissionTest.php:80

  Tests: 3 failed, 3 passed (6 assertions)
  ```
  `04_PREUVES/agent-36/08-test-qui-rougit.txt`
- **Témoin négatif** : **3 tests passent** dans la même exécution — dont « un `viewer` garde l'accès en **LECTURE** : on ferme l'export, pas la consultation » et « la garde couvre AUSSI les médias et les journalistes ». *Le contrôle sait donc distinguer ce qu'il ferme de ce qu'il laisse ouvert : ce n'est pas une garde qui refuse tout.*
- **Impact**        : un compte **explicitement conçu pour lire** exfiltre l'intégralité de la base nominative en une requête. Croisé avec **B12-010** (les 3 exports **ne laissent aucune trace** au journal) et **B15-010** (les routes RGPD n'exigent aucune permission), cela donne : **un lecteur peut emporter 4,29 M de fiches sans que rien ne l'en empêche ni ne l'enregistre.** C'est la conjonction la plus lourde de l'audit.
- **Reproduction**  : `tests/Feature/ExportPermissionTest.php`, joué contre `main`.
- **Correctif**     : l'agent 36 **a écrit la garde et l'a vue rouge avant de la voir verte** — c'est exactement ce que la règle 2 demande. Le correctif est un `middleware` de permission sur les trois routes.
- **Statut**        : **ouvert** — **le correctif et son test existent déjà**, ils n'attendent que P3
- ⚠️ **Ce constat a failli être perdu.** Il était **archivé dans les preuves et publié nulle part** — trouvé par l'**agent 50** (critique de complétude) en auditant l'audit. *Sans lui, la seule garde de permission vue rouge de tout ce travail serait restée dans un fichier que personne n'aurait rouvert.* C'est **A-013 appliqué à l'audit lui-même** : la mesure existait, écrite, au bon endroit ; c'est la clôture qui manquait.
- Vérifié par / Réfuté par / Passe 3 : —

---

## Agent 45 — la VALEUR des tests : dix gardes passées au crible
**Rapport** : `11_GRILLES/agent-45_valeur-tests.md` (244 l.) · **Preuves** : `04_PREUVES/agent-45/` (10 fichiers)

⚠️ **Note de séquence, et elle corrige l'agent 50** : le critique de complétude a relevé, à son
instantané de **12:06Z**, que cet agent n'avait rendu « qu'un squelette à balises non remplies,
0 constat ». **C'était vrai à 12:06Z et c'est faux depuis 14:10Z** — le rapport porte **dix constats
étayés**. L'agent 50 avait explicitement **daté son instantané** et signalé que le dossier était en
écriture pendant sa mesure : *il a fait ce qu'il fallait, et c'est le décalage qui parle, pas l'erreur.*

**Méthode** : pour chaque garde, casser délibérément le code qu'elle prétend garder, **jouer la suite
ENTIÈRE (780 tests)** pour compter le **rayon d'explosion**, archiver, restaurer, prouver la
restauration. *Compter le rayon est ce qui distingue une garde précise d'une garde qui rougit à tout.*

| Id | Sév. | Titre |
|---|---|---|
| **H45-002** | **S1** | `retention:purge --dry-run` **efface des données et annonce « 0 ligne »** — et **aucun test ne couvre cette commande planifiée** *(confirme B17-001 par une seconde mesure indépendante)* |
| **H45-008** | **S1** | 🔑 **Le rôle Postgres `axion_app` est GLOBAL au cluster et reposé par chaque `migrate`** : deux exécutions concurrentes se détruisent **même sur des bases distinctes**. **Isoler par base ne suffit donc pas** — ce que j'avais prescrit en `D-009` était **insuffisant** |
| **H45-009** | **S2** | 🔑 **La recette locale documentée est ~115× plus lente** que la même suite ailleurs : `SmokeTest` (2 tests triviaux) = **234,81 s** monté contre **2,14 s** copié ; suite complète = **434 s** copiée contre **30 s en CI**. *Cause : le bind-mount depuis Windows — chaque autoload traverse la frontière* |
| **H45-001** | S2 | La garde « sans auth → 401, jamais 500 » **n'interroge le produit qu'en JSON** : **le défaut A-001, vivant en production, lui est invisible** |
| **H45-003** | S2 | Deux des quatre tests HIBP **n'affirment rien de ce que leur nom promet** : la suite entière **reste verte** quand on casse ce qu'ils prétendent garder |
| **H45-004** | S2 | Deux gardes statiques **trouvent leurs propres commentaires** : elles restent vertes **quand le code qu'elles nomment disparaît** |
| **H45-006** | S2 | `app:pentest-self-check` n'a **aucun test**, **annonce deux contrôles qu'il ne fait pas**, et **mesure la mauvaise colonne** pour la RLS |
| **H45-007** | S2 | **L'anti-rejeu du canal se désarme par configuration**, et **aucun des 780 tests ne le voit** |
| H45-005 | S2 | Les drapeaux `MOCK_*` **ne sont épinglés dans aucun des deux fichiers PHPUnit** : la suite locale **n'est pas hermétique** et rougit là où la CI est verte |
| H45-010 | S2 | **25 des 35 tâches planifiées ne sont citées par aucun test — dont quatre destructives** ; et **8 des 19 `--dry-run`** non plus : *la promesse « je ne toucherai à rien » n'est vérifiée nulle part pour elles* |

🔑 **H45-009 explique une bonne part de la friction de cet audit — et un défaut du produit.**
La recette locale monte le code et `vendor` depuis le système de fichiers Windows ; **chaque autoload
traverse la frontière**. Témoin négatif exemplaire : *la seule variable changée entre les deux mesures
de `SmokeTest` est l'emplacement de l'arbre* — même conteneur, même image, même `vendor`, même `.env`.
**Le facteur ne vient donc pas de la charge de la machine**, contrairement à ce que trois agents
avaient supposé. *Une suite qu'on ne peut pas jouer en moins de dix minutes n'est jouée qu'en CI, donc
**après** la poussée : le harnais local décourage précisément le geste qu'il devrait rendre facile.*

🔑 **H45-008 corrige ma propre décision `D-009`.** J'avais prescrit « une base jetable par agent ».
**C'est insuffisant** : le rôle `axion_app` est **global au cluster**, et chaque `migrate` en repose le
mot de passe pour tout le monde. Témoin négatif solide : **8 sabotages sur 15 comptent 0 échec
d'authentification** — la panne n'est donc **pas structurelle**, elle survient **quand un autre
processus migre entre-temps**. C'est la même cause que `H44-004` et `B11-005`, **mais un cran plus
haut**, et donc non résolue par ce que j'avais prescrit. **Correctif chiffré : ~1 h** (`force="true"`
sur `DB_APP_PASSWORD`, et nom de rôle dérivé de la base).

**Et deux gardes bien faites, signalées comme telles** : les deux purges RGPD **sont finement
gardées** — bornes des deux côtés (2 ans / J+90 / 3 ans) et « jamais une personne qui a interagi ».
*Ces tests-là sont bons, et c'est le modèle à copier pour les quatre commandes destructives nues.*

---

### [A-014] Mesure de clôture : la RLS **en production**, et ce que la restauration préserve vraiment
- **Sévérité**      : **information de cadrage** — elle **confirme** un S0 et en **borne** deux autres
- **Domaine**       : sécurité / exploitation
- **Référence**     : PRODUCTION, lecture seule, 2026-08-19 · base restaurée `axion_crm_dr_a08`
- **Contexte**      : l'agent 8 avait laissé **deux points ouverts** et les avait écrits comme tels : la **survie des 39 policies RLS** à une restauration, et l'écart apparent entre les **55 tables en FORCE RLS** mesurées par l'agent 11 (sur une base bâtie par migrations) et ce que porte la production. J'ai attendu la fin de la restauration — **48 minutes** — plutôt que de conclure sur une base en cours d'index.

- **Preuve**        :
  ```
  # base restauree depuis la sauvegarde de production (apres fin complete)
  Restauration TERMINEE a 2026-08-19T12:24:40Z (0 session active)
    policies RLS      : 39
    tables FORCE RLS  : 38
    roles presents    : 2
    TEMOIN companies  : 4 295 349

  # PRODUCTION, en lecture seule, au meme moment
    policies                  = 39
    rls_enabled               = 38
    force_rls                 = 38
    tables avec workspace_id  = 41
    -> tables a workspace_id SANS force RLS : 3
         audit_logs
         sessions
         user_workspaces
  ```
- **Témoin** : la base restaurée rend **exactement les mêmes chiffres** que la production (39 / 38), avec les **4 295 349 fiches** en témoin de complétude.

- **Ce que cela établit, et il y a du bon et du mauvais** :
  1. ✅ **Les policies RLS survivent à une restauration.** Le point ouvert de l'agent 8 est **clos, dans le bon sens**. `A08-008` reste entier, mais **il porte sur les droits (`--no-acl`), pas sur les policies** — la distinction compte, et elle n'était pas faite.
  2. ✅ **L'écart 55 ↔ 38 n'est pas un trou de sécurité** : il vient de ce que l'agent 11 mesurait une base **bâtie par migrations** (qui porte plus de tables), pas la production. **Aucune alarme à tirer là-dessus.**
  3. 🔴 **Mais le trou réel est confirmé, et il est nommément localisé** : sur **41 tables portant `workspace_id`**, **3** n'ont pas de FORCE RLS — et `sessions` et `user_workspaces` sont des **exclusions motivées par écrit**. **Il reste `audit_logs`.**
     **C'est exactement `B11-006`, `B16-004`, `B10-002`, `A09-008` et `A06-004` — cinq agents, cinq chemins, un seul et même trou — désormais mesuré EN PRODUCTION.** La table qui doit **prouver** ce qui s'est passé est la seule table cloisonnée qui ne l'est pas.
- **Ce que cela change pour P3** : le groupe **G1** n'est plus « probablement vrai en production » — **il l'est**. Et son périmètre est **borné et petit** : une table et ses 14 partitions. *Un défaut S0 dont le correctif tient en une migration est un défaut qu'on ne laisse pas passer l'hiver.*
- **Statut**        : mesure de cadrage — **confirme G1 en production**
- Vérifié par / Réfuté par / Passe 3 : —

---

## Agent 29 — i18n et fuseaux horaires : **les deux bascules d'heure jouées pour la première fois**
**Rapport** : `11_GRILLES/agent-29_i18n-temps.md` · **Preuves** : `04_PREUVES/agent-29/` (21 pièces, dont 3 scripts rejouables)

🔑 **Le test que personne n'avait jamais joué dans ce dépôt.** Le **critère 16 du §29** l'exige
explicitement — « test joué sur les **deux bascules de l'année** ». Fait, sur le **chemin réel**
(`SiteSyncEvent::fromArray` → `insertGetId`), **table réelle**, 6 cas :

| Configuration | Résultat |
|---|---|
| **Sans `DB_TIMEZONE`** (l'atelier) | **6/6 décalés** — **+7 200 s l'été, +3 600 s l'hiver** |
| **Avec `DB_TIMEZONE=Europe/Paris`** (la production) | ✅ **6/6 à 0 s** — y compris l'**heure inexistante** du 29/03 02:30 et l'**heure ambiguë** du 25/10 02:30 |

**PHP et Postgres les résolvent identiquement : le correctif n'ajoute aucune indétermination.**
*Cela précise `B13-006` — le décalage est réel, mais **dans l'atelier**, pas en production — et cela
confirme `A05-008` par une mesure directe.* **Le volet « stockage » du critère 16 est TENU.**
**Son volet « affichage » ne l'est pas** : la fiche 360° montre le fuseau du **serveur**.

| Id | Sév. | Titre |
|---|---|---|
| **D29-001** | **S1** | **Le CRM n'est pas bilingue** : **27 clés de dictionnaire pour 1 417 chaînes en dur** (987 sur les 37 écrans), **5 fichiers sur 84** appellent `useTranslation`, complétude réelle **~1,3 %**. Et `en.json` **n'est pas inerte** : le détecteur de langue est **actif**, donc un navigateur anglais obtient une console **panachée** — connexion et 2FA en anglais, les 33 autres écrans en français |
| **D29-008** | **S2** | La timeline de la fiche 360° **trie par `strcmp` sur la chaîne** : l'ordre est **inversé dans l'heure rejouée du 25/10**. Témoin négatif joué : **correct hors bascule** |
| **D29-009** | **S2** | `signals:nightly-scan` à 02:00 tourne **0 fois le 29/03** et **2 fois le 25/10**, **sans `withoutOverlapping()`**. *Les purges RGPD, elles, sont hors de danger — vérifié* |
| **D29-010** | **S2** | `DB_TIMEZONE=` (**vide**) **met l'API à terre** — et c'est **la façon naturelle de « désactiver » le correctif** que la documentation décrit |
| D29-007 | S2 | Fiche 360° et « À arbitrer » affichent **la chaîne brute de Postgres** — le fuseau du serveur, pas celui du lecteur |
| D29-004, D29-006 | S2 | **63 formatages figés sur `fr-FR`**, **zéro `Intl.`**, zéro option `timeZone` · **8 pluriels par concaténation de morphèmes** : traduction **impossible par construction** |
| D29-002, D29-003, D29-005, D29-011 | S3 | Aucun moyen de choisir sa langue · **15 des 27 clés sont mortes**, dont les 7 libellés de navigation · montants en `toFixed(2)` avec **point décimal anglais** · 3 colonnes **tronquées à la seconde**, dont `email_suppressions` — *où vit précisément le discriminant microseconde dont dépend `horodatages:corriger`* |

**Deux points de méthode à porter au crédit de l'agent** : il a mesuré les chaînes en dur par **deux
passes d'AST TypeScript**, pas par `grep`, avec **témoin positif** sur un écran qui utilise vraiment
`t()`. Et sur le **piège 17** (l'espace fine insécable U+202F), il a **validé sa méthode dans les deux
sens avant de conclure**. *Le stockage en `timestamptz` ne vient d'ailleurs pas de Laravel* : la
grammaire de Laravel 12 produirait `without time zone` — **les 3 seules colonnes créées par le
constructeur Laravel sont les 3 seules tronquées**.

⚠️ **Et un fait qui explique pourquoi le panachage FR/EN n'a jamais été vu** : **les 37 tests frontend
forcent `fr`** (`tests/helpers/renderScreen.tsx:222`). **Aucun ne peut voir le défaut.**
*Onzième cas du patron `A-011`.*

---

### [NON-CONSTAT] Les liens de connexion ne sont PAS dans le journal de production — hypothèse tuée par la mesure
- **Statut**        : **réfuté avant publication.** Consigné parce qu'un audit doit dire ce qu'il a cherché et **pas** trouvé.
- **L'hypothèse**   : l'agent 46 a mesuré que `MagicLinkService:44` et `PasswordResetController:46` portent `env('MOCK_MODE', true)` → *jeton de connexion et jeton de réinitialisation **écrits dans le journal**, aucun courriel*. Et `MAIL_MAILER` retombe sur **`log`** (A-012 / F40-002), ce qui, dans Laravel, écrit **le courriel entier** dans `laravel.log`. Or ce journal fait **1 Go**, il est en **`-rwxrwxrwx`** et il contient déjà des cookies de session (F37-008). **Si des liens magiques y étaient, quiconque lit ce fichier pourrait se connecter en tant que propriétaire.** C'était une hypothèse sérieuse, et elle méritait d'être vérifiée avant d'être écrite.
- **La mesure**, en production, en lecture seule, **sans jamais afficher de contenu** :
  ```
  Message-ID:            ->      0     <- aucun courriel rendu dans le journal
  en-tetes "To:"         ->      0
  "magic-link/verify"    ->      0     <- aucun lien de connexion
  "magic.link"           ->      2     <- noms de route dans des traces, pas des jetons
  "password.reset"       ->      4     <- idem
  ```
- **Témoin positif** — et c'est lui qui donne sa valeur au zéro : le **même** contrôle, sur le **même** fichier, trouve **`telescope_entries` = 172 131** et **`axion_crm_session` = 264**. *La sonde voit donc parfaitement ce qui est là. Les zéros sont des zéros, pas un angle mort.*
- **Ce que cela établit** : **aucun courriel n'a jamais été rendu dans le journal de production.** Cohérent avec **A-012** — personne n'a jamais pu déclencher un lien magique, puisque personne n'a jamais franchi la première connexion. **Le journal ne contient donc pas de jeton d'authentification**, et `F37-008` reste borné à ce qu'il dit : adresses, cookies de session **anonymes**, IP et user-agents.
- **Ce que cela ne dit pas** : le mécanisme **existe**. Le jour où quelqu'un demandera un lien magique avec `MAIL_MAILER = log`, **le lien ira dans un fichier de 1 Go lisible par tous**. **C'est un risque armé, pas un incident.** → à traiter dans le lot **G4**, et c'est un argument de plus pour la recommandation **B3** de `06_RESTE-WILL.md` (séparer le courrier d'authentification du courrier métier).

---

## Agent 46 — analyse statique : ce que la baseline PHPStan cache réellement
**Rapport** : `11_GRILLES/agent-46_analyse-statique.md` (445 l.) · **Preuves** : `04_PREUVES/agent-46/` (10 fichiers)

**La baseline, décomposée** : **211 entrées / 248 erreurs / 1 321 lignes / 23 identifiants**.
**66 entrées (31 %) sont cosmétiques. Les 145 autres décrivent un comportement possible du programme.**
Par module : `app/Services` 85 · `app/Http` 59 — dont **`CompaniesController` 22, `MediaController` 21,
`JournalistsController` 16** · `app/Console` 27 · `app/Models` 17.

🔑 **L'invariant « rien sur le socle CRM » TIENT — vérifié en le faisant rougir.** Mais l'agent pointe
ce que personne n'avait vu : **les trois contrôleurs qui EXPORTENT ce socle en CSV concentrent 59 des
211 entrées.** *Le socle est propre ; la porte par laquelle il sort ne l'est pas.*

**Les plus dangereux, et ils tombent en deux familles** :
- **Secrets et fail-open** : `AuditHashChain:33` (défaut **`'dev-only-secret-change-me'`**) · `ScraperResultController:37` (HMAC, défaut `''`, **sans garde de non-vacuité** — *c'est `F37-001`, retrouvé indépendamment par l'analyse statique*) · `MockServicesProvider:56` (**interrupteur maître de 17 contrats, défaut = simulacre**) · les **3 limiteurs de débit** de `RouteServiceProvider` · `SsrfGuard:41` défaut **`true`** contre `PentestSelfCheck:71` défaut **`false`**, *sur la même variable*.
- **Conformité et exécution** : `GdprPortabilityService:31` → **`TypeError` sur la portabilité de l'article 20** · `ScrapingCampaignsController:413` → **si le workspace n'est pas résolu, aucun filtre de locataire** · `ProspectionCollect:68` `deadCode.unreachable` ⇒ **l'upsert par lots des sociétés n'est pas analysé du tout** · et **39 `Model::$colonne`** dans les exports CSV : *une colonne renommée produit une colonne vide, en silence*.

| Id | Sév. | Titre |
|---|---|---|
| H46-001 | S2 | La baseline **gèle 145 messages de comportement**, dont **20 sur des chemins de sécurité, de conformité ou d'export** |
| H46-002 | S2 | L'entrypoint de production exécute **`config:cache`** alors que **31 fichiers lisent secrets et drapeaux par `env()`** |
| H46-004 | S2 | Trois exports CSV traversent un type perdu : **une colonne renommée produit une colonne vide, silencieusement** |
| H46-008 | S2 | Les écrans **RGPD, Utilisateurs, Réglages et Société** reçoivent des réponses d'API **entièrement non typées** |
| H46-009 | S2 | **14 promesses non attendues** gelées, dont **5 invalidations de cache juste après un message de succès** — *l'utilisateur lit « enregistré » avant que la donnée ne soit relue* |
| H46-003 | S2 | `SSRF_GUARD_DENY_PRIVATE` : **deux défauts contradictoires** |
| H46-010, H46-011 | S3 | La garde « la baseline ne grossit pas » **garde le fichier, jamais le code** · `missingType.generics` **neutralisé globalement, hors du champ de la garde** |
| H46-005, H46-006, H46-007, H46-012 | S3 | **174** fichiers réellement non formatés · le commentaire de `ci.yml` en annonce **276** · le pathspec Pint **ne couvre pas** les `.php` à la racine de `backend/` · `workers` est linté **sans les règles typées** |

🔑 **Trois chiffres du mandat corrigés, et le CRLF enfin tranché** : `pint --test` sur un arbre
**exporté en LF** (`git archive`) rend **174 fichiers**, **0 `line_ending`**. La copie de travail en
rend **385**. **211 sont donc un artefact CRLF**, et un comptage indépendant confirme **210 fichiers
`.php` en CRLF côté poste, 0 côté dépôt**. *Le « 14 fichiers » que j'avais mesuré et le « 276 » de
`ci.yml` sont tous deux faux — c'est **174**.*

🔑 **Et une inquiétude du mandat qui tombe** : **0 type `any` dans `frontend/src` et 0 dans
`workers/src`** — l'unique occurrence est un **nom de champ**, pas un type. `tsc --noEmit` : **0 erreur**
des deux côtés.

**Gardes vues rougir, avec une précision exemplaire** : plafond dépassé → « 212 entrées pour un plafond
de 211 », 2 tests sur 5 en échec ; puis **à compte constant**, un seul `path:` basculé → **seule
l'assertion de chemin rougit**. *L'invariant mesure bien le bon objet.* Restauration prouvée par
**empreinte md5 identique** et `git status` vide.

⚠️ **Deux aveux de méthode que je retiens** : l'agent a **modifié `phpstan-baseline.neon` pendant
qu'un autre agent de l'audit analysait le même dépôt dans le même conteneur** — il l'a vu par `ps aux`
et restauré aussitôt, mais il écrit que *« un `[OK] No errors` mesuré dans ces conditions ne vaut rien,
et personne ne peut le savoir après coup »*. Et sa **première sonde CRLF, écrite en `sh`, rendait 0 des
deux côtés** : **le piège 1 du dossier, attrapé en flagrant délit sur lui-même**. Sonde refaite en PHP.

---

## Agent 36 — les permissions : **la couche d'autorisation est du code mort**
**Rapport** : `11_GRILLES/agent-36_permissions.md` (695 l., tableau de 118 routes) · **Preuves** : `04_PREUVES/agent-36/` (12 fichiers)

*(Note d'identifiants : j'avais publié en avance la garde rouge trouvée dans ses preuves, sous le
numéro `F36-001` que l'agent a ensuite employé pour autre chose. **Ma publication est renumérotée
`F36-011`** ; les numéros de l'agent font foi.)*

### 🔴 Le constat central, et sa démonstration est décisive

**Aucune des 11 policies n'est jamais appelée.** Trois mesures convergentes, dont la dernière est
imparable :

1. **0 intergiciel `can:`** et **0 `authorize()`** dans tout `app/` ;
2. **instrumentation** → **0 appel sur 117 requêtes** réelles ;
3. 🔑 **les 11 policies réécrites en REFUS TOTAL** → **l'API répond strictement à l'identique**
   (12 codes 200 inchangés) **et les 15 tests restent verts**.

*Interdire tout, à tout le monde, ne change rien.* C'est la preuve que la couche entière est
**inerte**. **`B16-004` n'était pas un cas isolé — c'était la règle.**

Et le verrou est plus profond qu'un oubli : **`$this->authorize()` est FATAL dans les 35 contrôleurs
d'API** (`BadMethodCallException` mesuré) — `ApiController` étend `Illuminate\Routing\Controller`
**sans le trait `AuthorizesRequests`**. *Quelqu'un qui voudrait bien faire, aujourd'hui, casserait la
route qu'il essaie de protéger.*

| Id | Sév. | Titre |
|---|---|---|
| **F36-001** | **S0** | **Aucune des 11 policies n'est jamais invoquée** : la couche d'autorisation est **du code mort** |
| **F36-002** | **S0** | **`$this->authorize()` est fatal dans les 35 contrôleurs d'API** |
| **F36-003** | **S0** | Un compte **lecture seule** crée, modifie et **supprime définitivement** entreprises et étiquettes (`DELETE` → **204**, ligne disparue) |
| **F36-004** | **S0** | `/audit-logs` : ni garde ni cloisonnement. `owner2` de l'espace **BETA** reçoit **200** avec **49 entrées appartenant toutes à ALPHA**, et `total: 68` = **toute la table**. `viewer` aussi *(confirme et étend `B16-004`)* |
| **F36-005** | **S0** | `GET /contacts/{id}` et `/companies/{id}` **rendent la fiche d'un autre locataire** *(confirme `B12-001`, cette fois **par une session HTTP réelle**)* |
| **F36-008** | **S1** | `/media/export` et `/journalists/export` rendent **500 pour tout ayant droit** (`TypeError`) : **les portes d'opposition RGPD ne s'exécutent jamais** |
| **F36-010** | **S1** | **Aucune interface pour créer un compte, poser un rôle, ou même le voir** |
| **F36-006** | **S1** | `contacts.view_pii` **protège la liste, pas la fiche** : `p***@…` en liste, **adresse complète** en fiche *(confirme `B12-002`, `B10-005`, `B15-005` — quatrième mesure)* |
| **F36-007** | **S1** | **RLS inerte en local** : le rôle `axion` est **superutilisateur `rolbypassrls`** — *septième divergence atelier ↔ production* |
| F36-009 | S2 | **Zéro couverture de test des policies** |

**Les rôles, eux, existent vraiment** : `owner`, `admin`, `operator`, `viewer`, **16 permissions**,
répartition cohérente, confirmés **sur six sessions HTTP réelles**. **Mais on ne peut pas en créer un
par le produit** : `POST/PUT/DELETE /users` → **501**, `GET /users` ne projette même pas le rôle, et
**aucune des 118 routes n'attribue ni ne retire un rôle**. L'agent a dû créer ses six comptes **par
insertion en base** — seul chemin possible.

**Recompte** : le noyau publie **118** routes d'API, et non 112 — *c'est le compte des lignes de
déclaration ; `apiResource` en produit 5*. **118/118 sans policy** ; **114/118 sans aucune garde
d'autorisation** ; **102 des 106 routes authentifiées** n'en ont aucune.

🔑 **Règles 2 et 3 honorées de bout en bout** : retrait de `permission:data.export` → **3 failed** ;
restauration → **6 passed**. Et le **témoin négatif** est exemplaire : une policy **branchée
volontairement** produit **5 appels tracés et 3 codes 403** — le code réel en produit **0 sur 117
requêtes**. *L'instrument voit donc parfaitement les appels quand il y en a.*

⚠️ **Et une fausse piste levée, que je porte au crédit de l'agent** : un premier passage rendait **403
à tout le monde** sur les exports. Il a failli l'écrire. C'était **un cache Spatie périmé dans son
propre atelier**, pas un défaut produit. **Non rapporté.**

⚠️ **`A-009` aggravé, avec la cause enfin isolée** : `require vendor/autoload.php` prend **82,69 s**
sur le montage Windows. En copiant le dépôt **dans** le conteneur, `/up` répond en **5,4 s**.
*C'est la même cause que `H45-009` (~115× plus lent), mesurée par un autre chemin.*

---

### Précision apportée par l'agent 49 sur `I49-008` — et elle durcit le constat

L'agent est **allé mesurer lui-même** une cadence qu'il n'avait reprise que de l'agent 14 — *plutôt
que de la laisser reposer sur un pair*. `src/server/queue/queues.ts:1029-1045` →
`repeat: { pattern: "*/10 * * * *" }`, et **le commentaire du produit tranche lui-même** :

> « l'émission immédiate est un **confort**, ce passage est la **garantie de livraison** »

**Le constat en devient plus dur, pas moins** :

> **Aucun des deux sens ne tient les 60 secondes sur son chemin garanti** — balayage à **10 minutes**
> côté site, `everyFiveMinutes()` côté CRM. **Le seul chemin qui tient le budget est celui que le
> produit qualifie lui-même de « confort »**, par opposition au balayage qui est « la garantie de
> livraison ».
> **Un critère d'acceptation ne peut pas s'appuyer sur le chemin que le produit désigne comme non
> garanti.**

**Témoin négatif** : le même contrôle **trouve bien** les autres cadences déclarées (`"30 4 * * *"`
pour la réconciliation, 05:00 UTC pour le vivier) — *il sait lire une cadence, et il n'en trouve
aucune sous les 10 minutes*.

**Et deux mots du critère 18 que l'agent laisse explicitement ouverts plutôt que de trancher à la
place du dirigeant** — ils sont à porter au cahier des charges, pas au produit :
- « **inscriptions** » : newsletter, ou inscription à une **session** (qui n'a **aucun émetteur**) ?
- « **reçus** » : **acquitté**, ou **ayant produit une fiche** ? *Tant que ce mot n'est pas tranché,
  le critère 18 serait **vert sur un CRM qui n'a créé aucune fiche** — c'est-à-dire exactement l'état
  que `B13-001` a mesuré.*

---

### [A-015] 🔴 Le quatrième verrou : même une fois entré, le propriétaire tomberait sur un écran **entièrement blanc**
- **Sévérité**      : **S0**
- **Domaine**       : interface
- **Référence**     : `e8924b8` — production mesurée en lecture seule
- **Emplacement**   : `frontend/src/features/dashboard/ActivityFeed.tsx:19` — lit `log.action` · table `audit_logs`, colonne réelle **`event_type`**
- **Constat**       : l'écran d'accueil **s'efface entièrement — barre latérale comprise — dès que `audit_logs` contient une seule ligne**. Le composant lit un champ **qui n'existe pas** ; l'exception remonte, et **aucun `errorComponent` n'est posé** (`D24-008` : `ErrorBoundary` est écrit, exporté, **monté nulle part**).
- **Preuve — la mesure que l'agent 24 demandait, jouée en production** :
  ```
  audit_logs = 64                                          <- 64 lignes, DEJA
  colonnes   = id, workspace_id, user_id, event_type, path, status_code,
               ip, user_agent, payload_hash, prev_hash, current_hash, created_at
               -> il n'y a AUCUNE colonne "action"
  ```
- **Témoin positif** : la même session rend `companies = 4 295 349` — la base répond, la mesure n'est pas un artefact.
- **Témoin décisif de l'agent 24** : la **même ligne**, mais avec un champ `action`, **et l'écran se monte**. La variable est donc isolée : c'est bien le nom du champ, pas la présence de données.

- 🔑 **Ce que cela change au récit, et c'est le quatrième verrou de `A-012`.** On croyait trois
  serrures : mot de passe jamais reçu, courriel de récupération muet, enrôlement 2FA sur colonnes
  inexistantes. **Il y en a une quatrième, et elle est derrière les trois autres** :
  > **le jour où quelqu'un franchira les trois premières, il tombera sur une page blanche.**

  Et la boucle est parfaite : **le middleware audite `POST /auth/login`**. *La connexion écrit
  elle-même une ligne de plus dans la table qui casse l'écran où elle dépose l'utilisateur.*
  Avec **64 lignes déjà en production**, l'écran est **cassé aujourd'hui**, avant même que quiconque
  essaie.

- **Impact**        : c'est le **seul** des quatre verrous qui aurait été **invisible jusqu'à la première connexion réussie** — et donc celui qui serait apparu au pire moment : après avoir cru le problème réglé. Il explique aussi pourquoi corriger `A07-001` seul **ne suffira pas** : les trois correctifs doivent partir ensemble, et celui-ci avec eux.
- **Correctif**     : lire `event_type` au lieu de `action` (**~15 min**), **et** monter un `errorComponent` sur les routes — sans quoi le prochain champ renommé effacera de nouveau l'application entière. Le test qui l'accompagne doit **rougir sur une ligne d'`audit_logs` réelle**, pas sur une fixture fabriquée avec un champ `action` (ce serait le piège 19 une onzième fois).
- **Statut**        : **ouvert** — **rejoint le lot G4, en tête de P3**
- **Vérifié par**   : agent 24 (mesure d'origine et témoin) ; **compte de production mesuré par le chef de chantier**
- Réfuté par / Passe 3 : —

---

## Agent 24 — les 13 parcours du §23.4 : **0 existe · 2 partiels · 11 absents**
**Rapport** : `11_GRILLES/parcours.md` · **Preuves** : `04_PREUVES/agent-24/` (5 sondes, 6 JSON, 8 captures, `COMMANDES-JOUEES.md`)

**Trois lectures transverses, et elles valent mieux que le tableau** :
- **6 des 13 parcours partent « de la fiche » — et la fiche a 0 bouton et 0 lien.**
- **4 butent sur une table qui existe déjà** : il manque le modèle, la route, le contrôleur, l'écran — **jamais la colonne**.
- **4 butent sur une table qui n'existe pas du tout.** Et **le 13ᵉ ne demande qu'un lien**.

| Id | Sév. | Titre |
|---|---|---|
| **D24-001** | **S0** | → devenu **`A-015`** : l'écran d'accueil **s'efface entièrement** dès qu'`audit_logs` porte une ligne. **64 en production** |
| **D24-007** | **S1** | `crm_activites` et `crm_motifs` **n'ont aucune clé étrangère** : **rien ne peut porter un motif**. *Plus profond que `I48-004`, et préalable à l'étape 1a* |
| **D24-004** | **S1** | Rattacher un lead exige de **taper à la main l'identifiant numérique interne** de l'entreprise (placeholder « ex. 1842 »), **boutons désactivés** — **sur l'écran où stationnent 100 % des leads** |
| **D24-003** | **S1** | La fiche 360°, **départ de 6 des 13 parcours**, offre **0 bouton et 0 lien** |
| **D24-002** | **S1** | La cloche « Notifications » **n'a aucun gestionnaire de clic** ; `/notifications` rend une liste vide **en dur** et **n'est appelée par aucun écran** |
| D24-006 | S2 | **18 écrans sur 26 sans aucun lien sortant**, dont 4 sans lien **ni** bouton — `/contacts` (320 personnes) et la fiche 360° en font partie |
| D24-005 | S2 | **Filtres et page absents de l'URL** : le retour arrière renvoie **page 1, sans filtre**, à chaque fiche ouverte |
| D24-008 | S2 | `ErrorBoundary` **écrit, exporté, monté nulle part** ; **aucun `errorComponent`** : 8 écrans mesurés **effacent l'application entière** |

**Sur les parcours réels de l'outil — 5 sur 8 mesurables aboutissent**, et la répartition est parlante :
lancer une collecte **4 clics**, assistant **5**, fiche entreprise **3** (**au budget**), export **3**,
fiche média **3**. **S'arrêtent** : pose de tag en masse (**boutons désactivés**), arbitrage (**boutons
désactivés**), fiche candidat (aucun lien).

> **Les 5 qui aboutissent sont tous des parcours de collecte ou de lecture ; les 3 qui s'arrêtent sont
> les 3 seuls où l'on agissait.**

*Le verdict de l'agent 22 est confirmé **par une mesure d'une autre nature** : 4 parcours de collecte
sur 4 aboutissent ; **0 geste du matin sur 4** ; **0 action sur 4 depuis la fiche**.*

---

## Agent 26 — formulaires et saisie : **14 mécanismes de refus silencieux**
**Rapport** : `11_GRILLES/agent-26_formulaires.md` · **Preuves** : `04_PREUVES/agent-26/`

🔑 **`D26-001` (S1) ne porte pas sur un champ, mais sur le service qui décide à qui part un courriel.**
Un critère mal formé **n'est pas rejeté : il est RETIRÉ du SQL**. Mesuré sur 300 000 fiches, le
prédicat produit devient `"workspace_id" = ?` — *le critère a disparu, et l'aperçu affiche le compte
du **workspace entier***. **Deux des quatre entrées franchissent la validation et sont persistées**
(`criteria.*.value` **n'est validée nulle part** — vérifié : 0 occurrence ; `POST /audiences/preview`
ne valide que `criteria => required|array`). *C'est le « 3 chemins aveugles » du mandat, mesuré — et
la conséquence est un envoi à tout un espace de travail.*

**Les 14 refus silencieux**, chacun avec son mécanisme — les plus nets :
bornage **à chaque frappe** (taper « 12 » dans un champ de minimum 5 **donne 52**) · `Number('')` vaut
`0` **et non `NaN`**, donc vider un champ le remet au plancher · **aucun `<form>` dans l'assistant**,
donc `min`/`max`/`required` **jamais évalués** sur 8 champs · une limite de source **désélectionnée**
est **quand même envoyée et persistée** · le champ « DSN Sentry » **sans `name`, sans `onChange`,
sans bouton** : la saisie est **jetée** · **14 boutons sans `onClick`** · `MaskedSecret` dont
« Afficher » révèle **une constante littérale** · `status:'configured'` **codé en dur** : l'écran
affirme **sans avoir rien lu** · et **12 `catch {}`** qui jettent le message du serveur, **dont les
4 écrans d'authentification**.

**Critère 3 du §29 — « aucune saisie n'est perdue » : il tombe, 0 sur 6.**
`beforeunload`, `useBlocker`, `isDirty`, `validateSearch`, `useSearch(` → **zéro ligne**.
**Témoin négatif** : le même contrôle retrouve **les 6 usages réels de `localStorage`** (barre latérale,
thème, langue — **aucun formulaire**). Aggravé par `api.ts:30`, qui fait `window.location.assign('/login')`
sur un 401 : *une session expirée pendant la saisie efface tout, sans avertissement.*

✅ **Et l'arbitrage `neq`/`not_in` sur NULL (fragilité F7) TIENT — vérifié, rien à rouvrir.**
Vrai service, 300 000 fiches toutes à `sector_main` NULL : `neq btp` → **300 000 gardées** ; `not_in`
idem ; `not` symétrique. **Témoin négatif décisif** : *le SQL naïf sur la même table rend **0***.
**L'écart vaut 300 000 fiches.**

**L'assistant de campagne** : **4 étapes**, le retour arrière **préserve tout** et **on ne peut pas
sauter d'étape** — *les deux seuls points sains*. Mais un rechargement perd tout, **le bouton Précédent
du navigateur quitte l'assistant**, il n'y a **aucun `<form>`**, et **aucun test** ne couvre ses
869 lignes.

---

## Agent 34 — non-régression de la console du site
**Rapport** : `11_GRILLES/agent-34_non-regression-console.md` · **Preuves** : `04_PREUVES/agent-34/` (24 fichiers)

**7 commits CRM** sur 150 depuis le 13/08 ont touché **72 fichiers hors `crm-sync`**, dont **55 de
code** : **29 testés, 26 non testés**. Mais **seuls 7 fichiers sont réellement partagés** avec le
périmètre de la console — **tous additifs, tous testés, tous verts**… **sauf un** :
`api/calendly/client-event/route.ts`, **le seul où le canal a modifié le corps d'une fonction
existante**. *C'est exactement celui qui a cassé.*

| Id | Sév. | Titre |
|---|---|---|
| **E34-003** | **S1** | La production **affirme la certification Qualiopi** le jour même où un commit du dépôt écrit qu'elle **n'est pas délivrée** → **porté en `06_RESTE-WILL.md` §C bis, comme une question, pas comme une accusation** |
| **E34-007** | **S1** | **Les deux contrôles d'isolation du périmètre sont ROUGES — 47 et 24 violations, dont 11 fichiers du module planning — et aucun n'est câblé**, l'un affirmant pourtant dans son en-tête l'être. *Douzième cas du patron `A-011`*<br>✅ **Et l'agent refuse d'additionner les deux chiffres** : le **24** vient d'un contrôle **par marqueur textuel**, pas par symbole — il le rapporte comme **alerte à qualifier**, pas comme compte de fautes, et **ne l'ajoute pas au 47**. *Deux nombres de natures différentes ne se somment pas, même quand la somme serait plus impressionnante.* |
| **E34-001** | **S1** | Le canal CRM a rendu **la suite du site rouge pendant 3 jours** et le hook de pré-envoi **infranchissable pour tout le monde** (la PR ajoute l'appel, le mock arrive **3 jours plus tard**) |
| E34-005 | S2 | Suite **verte**, mais **54 % des tests ne s'exécutent pas en CI** — **12 710 sautés sur 23 435** |
| E34-006 | S2 | Le verrou LF du 18/08 **n'a pas été appliqué à la copie de travail** : **4 877 fichiers portent encore des CR**, et **le test qu'il devait sauver rougit toujours** *(même défaut que `A-003`, à l'échelle de l'autre dépôt)* |
| E34-002, E34-004 | S2 | **18 des 21 fichiers appelant le canal n'ont aucun test qui le connaisse** · 5 entrées de navigation mènent à un **404 selon une variable d'environnement**, et le contrôle de routes **ne peut pas le voir** |
| **E34-008** | **S2** | 🔑 **Le module « Booking » de la console n'est PAS le « booking » du CDC** : *viser le premier détruirait les inscriptions aux sessions de formation.* **À lire avant tout palier de retrait** |

✅ **Le correctif Qualiopi tient, et la garde est bonne** : le `where` distingue 4 types collectifs de
2 nominatifs, **le filtre est dans la requête**, **la garde rejoue le `where` contre des fixtures**
(*elle mesure le bon objet*), et les 4 écrans passent par un point d'entrée unique. **Les 8 lectures
accessibles par jeton stagiaire ou formateur ont été passées au crible : toutes cloisonnées.**

⚠️ **Un témoin qui a ÉCHOUÉ, et l'agent le dit plutôt que de conclure** : l'oracle de `E34-004` ne
discrimine pas — **le témoin non gaté rend le même 404 que la page gatée**. L'état réel de
`FACTURATION_HUB_ENABLED` en production reste donc **non vérifié**, et c'est écrit comme tel.
*C'est exactement ce que la règle 3 demande : un contrôle qui ne sait pas distinguer ne prouve rien.*

⚠️ **Et une mesure de charge qui recoupe `H45-009`** : le poste est **≈40× plus lent** que le runner
de CI (36 s par fichier de test). Les 4 rouges initiaux de l'agent étaient **des expirations à
5 000 ms** — rejoués à 120 000 ms : **24/24 verts**. *Encore un rouge d'atelier qui n'est pas un
défaut de produit.*

---

## Agent 43 — charge et concurrence : **la mesure de référence du §29 a été jouée dans la mauvaise configuration**
**Rapport** : `11_GRILLES/agent-43_charge-concurrence.md` · **Preuves** : `04_PREUVES/agent-43/` (13 fichiers + 4 scripts de scénario)
**Aucune charge envoyée vers la production ni la préproduction.**

### 🔑 `G43-001` (S1) — et elle est née de la consigne §5 bis du dossier

L'agent a **doublé chaque mesure sous les deux rôles**, parce que le dossier l'avertissait que
l'atelier et la production n'exécutent pas le même cloisonnement. **C'est ce doublement qui a tout
révélé** : **la mesure de référence du §29 a été jouée sous `BYPASSRLS`** — c'est-à-dire dans une
configuration que la production n'a pas.

Sous la RLS **réelle**, le prédicat `(workspace_id)::text = current_setting(...)` **détruit
l'estimation de sélectivité** (`rows=1` au lieu de 3 125) → le planificateur **abandonne l'index** →
le coût estimé franchit `jit_above_cost` → **+391 ms de compilation JIT par requête**.

```
recherche globale :   39 ms (axion, BYPASSRLS)
                   3 589 ms (axion_app, RLS de production)     <- x92
                     632 ms (axion_app, jit=off)               <- correctif : UNE ligne, gain x5,7
```

### `G43-002` (S1) — **corriger `A-010` ne suffira pas**

`pgbench`, 300 000 fiches, 40 s par passe :

| rôle | p95 à c=1 | p95 à c=10 | dégradation | débit |
|---|---|---|---|---|
| `axion_app` (**RLS armée = production**) | 1 413 ms | **5 794 ms** | **+310 %** | 1,40 → 4,55 tps |
| `axion` (BYPASSRLS = atelier) | 337 ms | 658 ms | +95 % | 5,59 → 36,4 tps |

**Postgres n'est PAS sérialisé** (débit ×3,2 à ×6,5 — à l'opposé de l'escalier plat d'`A-010`).
**Mais le critère 17 échouerait sur la base seule** : le budget est **+20 %**, la mesure donne **+310 %**.

| Id | Sév. | Titre |
|---|---|---|
| **G43-001** | **S1** | La mesure de référence du §29 a été jouée **hors configuration de production** — écart **×92** sur la recherche globale |
| **G43-002** | **S1** | **Le critère 17 échoue sur la couche base seule** : +310 % à 10 sessions |
| **G43-004** | **S1** | `POST /companies/tags/bulk` **insère sans `workspace_id`** → **cassé en production** (refus RLS mesuré, **témoin positif joué**) |
| **G43-005** | **S1** *(relevable S0)* | **Aucun mécanisme d'édition concurrente** : 0 `ETag`, 0 `If-Match`, 0 colonne de version, backend **et** frontend. Deux sessions réellement simultanées rendent **toutes deux `UPDATE 1`** : **une saisie disparaît en silence**. **Témoin positif** : la même séquence avec `AND updated_at = <lu>` rend **`UPDATE 0`** |
| G43-006 | S2 | **28 `withoutOverlapping()` sur 28 sans argument** → **24 h de verrou chacun**, dont `crm:flush-outbound` (cadence 5 min) : un processus tué **gèle 288 passages d'oppositions RGPD sous un ordonnanceur vert**. *La brique correcte existe déjà dans le dépôt* (`CompteursHub.php:85`, `lock: ['seconds' => 30]`, **avec le raisonnement écrit**) |
| G43-003, G43-007, G43-008 | S2 | Compteurs à **6,2 s** à 10 sessions · `load-tests/` **n'a jamais tourné** · **aucune garde de concurrence** |

🔑 **Une conséquence d'`A-010` que personne n'avait vue, pas même moi** : `Cache::flexible` s'appuie
sur `defer()`, **qui s'exécute dans la phase de terminaison du même processus**. Sur un serveur
**mono-processus**, le recalcul « différé, qui ne coûte rien » **coûte le même gel une seconde fois**,
au visiteur suivant. *Le correctif de la pièce 1 est bon, mais son bénéfice est partiellement mangé
par `A-010` — et il le sera jusqu'à ce que `A-010` soit corrigé.*

**Projection du gel, déclarée comme projection** (arithmétique sur `A-010` + les compteurs
chronométrés, **aucune requête parallèle envoyée**) : sans index, cache froid, 2,8 M → **17,5 s**
mesurés ; extrapolé à 4,29 M → **≈ 26,8 s** ; avec index → **≈ 3,4 s**. *Dix ouvertures simultanées :
le dixième attend entre **5,3 s** et **157 s** avant que sa propre requête ne démarre.*

⚠️ **Et `load-tests/` mérite d'être signalé pour ce qu'il prescrit** : son runbook demande de jouer
une charge **contre la production**, **avec le mot de passe du dirigeant**, décrit un serveur
**php-fpm/CPX42 qui n'existe pas**, et son seuil `ensure.p95: 800` s'applique au p95 **global** alors
qu'il se commente « list companies ». *Onzième instance du patron `A-011`, et la seule qui pourrait
faire tomber la production si quelqu'un suivait le mode d'emploi.*

**Deux points de méthode à porter au crédit de l'agent** : il a **rejeté sa propre première mesure**
d'édition concurrente (jouée sur `id=1`, inexistant — elle rendait `UPDATE 0` **pour la mauvaise
raison**). Et son protocole du critère 17, écrit **en extension** des deux mesures de référence
existantes, impose une **passe B de témoin séquentiel obligatoire** : *sans elle, un p95 dégradé ne
prouve pas que la concurrence en est la cause.*

---

## Agent 39 — sauvegardes et observabilité : **une restauration réelle, et une date de saturation**
**Rapport** : `11_GRILLES/agent-39_sauvegardes-observabilite.md` · **Preuves** : `04_PREUVES/agent-39/`
**Aucune suppression, aucune restauration sur la production.**

**Deuxième restauration réelle de l'audit, par un autre chemin que celle de l'agent 8** : l'archive
`20260819T030001Z` **est restaurable et complète**, `companies` et `contacts` restaurées, **aucune
erreur `function unaccent(text) does not exist`**. Et l'agent dit **ce que sa mesure ne prouve pas** :
la copie **hors-site** n'a pas été vérifiée (la comparaison d'empreinte exigerait d'écrire en production).

| Id | Sév. | Titre |
|---|---|---|
| **F39-011** | **S1** | 🔴 **Le disque de production se remplit de 511 Mio/jour et sature vers le 6 octobre 2026** — **aucune garde ne regarde cette trajectoire** |
| **F39-009** | **S1** | **Le RPO annoncé est faux d'un facteur 24** : « ≤ 1 h » au `Makefile` et au runbook, **24 h en réalité** (la sauvegarde est quotidienne — `dr-drill.sh` lui-même tolère 36 h). Et **la profondeur réelle est de 3 jours, pas 30** |
| **F39-010** | **S1** | **Le script de restauration a pour cible par défaut la BASE DE PRODUCTION** |
| **F39-006** | **S1** | Le DSN Sentry **est configuré en production**, mais **aucune exception non rattrapée ne lui parvient** : le branchement obligatoire du paquet **n'a pas été fait**. *Croisé avec `B16-006` : le contrôle d'intégrité du journal d'audit n'avertit personne, et voilà pourquoi* |
| **F39-002** | **S2** | La surveillance des sauvegardes vérifie qu'un fichier **existe**, qu'il est **récent** et qu'il est **gros** — **jamais qu'il est restaurable**. *Treizième cas du patron `A-011`* |
| **F39-007** | **S2** | **7 des 8 rubriques** du résumé d'observabilité **renvoient zéro en avalant l'exception** : *« rien à signaler » et « je n'ai pas pu regarder » y ont la même apparence* |
| **F39-012** | **S2** | L'exercice de restauration compare un dump de 03:00 aux comptages **VIVANTS** de la production : **il rougira à tort le premier jour où la prospection tourne** |
| F39-001, F39-003, F39-008 | S2 | Les 8 services d'observabilité et les 12 règles d'alerte **n'existent que dans le dépôt** · l'exercice de restauration **n'est déclenché par rien** · le runbook décrit **un dispositif qui n'existe pas** (S3, WAL streaming, Backblaze B2, PITR) |
| F39-005 | S2 | Le correctif qui a rendu la sauvegarde restaurable repose sur **7 noms écrits en dur, sans garde** : **la huitième fonction rejouera la panne** *(confirme `B10-010`)* |

⚠️ **Une nuance qui corrige la portée de `A-003`, et je la prends** : les trois scripts de sauvegarde
de la **copie de travail** sont syntaxiquement invalides sous Linux — **mais ceux qui tournent en
production ne le sont pas** (`F39-004`). *Le défaut est donc bien réel côté poste, et il ne menace pas
la sauvegarde qui tourne.* C'est exactement la distinction que `A-003` devait porter et qu'il ne
portait qu'à moitié.

---

## Agent 48 — aptitude au cahier des charges
**Rapport** : `11_GRILLES/agent-48_aptitude-cdc.md` (604 l., les 26 chapitres en 3 colonnes)

*(Son verdict par domaine est repris tel quel dans `07_RAPPORT-FINAL.md` — il en constitue l'ossature.)*

| Id | Sév. | Titre |
|---|---|---|
| **I48-001** | **S0** | **Le CRM n'a aucune route pour créer une fiche personne** ; la modifier ou la supprimer rend **501** |
| **I48-002** | **S1** | La recherche globale rend **une charge codée en dur** : le **critère 1** n'est pas « non mesuré », il est **NON TENU** — et `/search` est **déclaré deux fois** |
| **I48-003** | **S1** | Une personne ne peut exister **ni sans organisation ni sans nom de famille** : deux `NOT NULL` **contre le principe 5** — *cause mécanique de `B13-002`* |
| **I48-005** | **S1** | **Quinze objets du code contredisent la cible : ils devront être DÉFAITS, pas complétés** — et **six d'entre eux sont une seule question posée six fois** |
| I48-004 | S1 | La **pièce 2 de l'étape 1a** est en base **sans route, sans contrôleur, ni écran** — et **se justifie par un critère (§29-2) qu'elle ne rend pas atteignable** |
| I48-006 | S2 | `notifications` : **zéro écrivain, zéro lecteur, trois suppresseurs** — *et l'inventaire qui fixe l'ordre des pièces la déclare « vivante, 10 fichiers applicatifs »* |
| I48-007 | S2 | **Le mot « console » désigne déjà autre chose que le §19** : le **critère 24 est perdu avant le premier écran de réglage** |
| I48-008 | S2 | Le seul endroit où le produit **DÉPASSE** son périmètre → porté au dirigeant (`06_RESTE-WILL` §B2) |

🔑 **Sa contribution la plus utile est la liste de ce qui va casser dans les mains du chantier**, dans
l'ordre, chaque élément avec *la pièce de l'étape 1a qui s'y appuie*. Et son pendant, qu'un audit
oublie toujours : **ce qui peut continuer sans attendre** — dont **cinq chapitres entiers en terrain
vierge** (§4 questionnaires, §5 notation, §6 écran d'entretien, §14 documents, §16 après-vente),
**0 contradiction à payer**, où l'on écrit directement la cible.

---

## Agent 35 — authentification : **la cause exacte de A-001, et le correctif prouvé à quatre états**
**Rapport** : `11_GRILLES/agent-35_authentification.md` · **Preuves** : `04_PREUVES/agent-35/`

🔑 **A-001 a DEUX causes, pas une — et c'est ce qui avait échappé à tout le monde, moi compris.**
1. **Laravel 12 pose lui-même le rappel fautif** : `ApplicationBuilder::withMiddleware()` (vendor, l.278) appelle **inconditionnellement** `redirectGuestsTo(route('login'))` **avant** d'exécuter le rappel de l'application. `bootstrap/app.php` ne le remplace jamais, et **aucune route nommée `login` n'existe** (`Route::has('login')` rend **false**).
2. **Le gestionnaire d'exceptions rappelle `route('login')`** à son tour.

**Correctif : 2 lignes, et les deux sont nécessaires** — prouvé **à quatre états** :

```
(0) tel quel                     -> 500
(1) shouldRenderJsonWhen seul    -> 500      <- le corps devient {"message":"Server Error"}
(2) redirectGuestsTo seul        -> 500
(3) LES DEUX                     -> 401      <- correct
```

*L'état (1) est le plus instructif : **une demi-correction produit une erreur mieux habillée, pas une
erreur en moins**.*
**Coût caché que personne n'avait relevé : 8 475 octets de journal par requête refusée** —
contributeur direct de `A-007`.

| Id | Sév. | Titre |
|---|---|---|
| **F35-002** | **S0** | Confirmation **indépendante** de `A07-001` — **et un SECOND chemin** : `UsersController:33` sélectionne `two_factor_enabled`, **colonne inexistante** → **`GET /api/v1/users` est cassé**. *C'est l'écran par lequel le propriétaire inviterait quelqu'un.* A-001 n'y est pour rien : le SPA reçoit un 401 propre |
| **F35-003** | **S1** | 🔴 **La 2FA n'est JAMAIS exigée par le serveur** : `2fa_passed_at` est **écrit une fois et relu nulle part**. *Contournable par construction* |
| **F35-004** | **S1** | **`HibpChecker` est fail-open, joué** : réseau coupé → `getBreachCount("password")` rend **0** **et la règle ACCEPTE**. Témoin : réseau rétabli → **9 999 999**. Et la règle n'est branchée **que sur `password/reset`** |
| **F35-005** | **S1** | **Le jeton de réinitialisation n'expire jamais** : la différence de dates rend une valeur **négative** en Carbon 3 — joué : **−179,99** et **−43 199,98** |
| F35-006, F35-010 | S2 | La réinitialisation **ne révoque aucun jeton d'API** · **les jetons d'API n'expirent jamais** |
| F35-012, F35-009 | S2 | **Le verrou de compte est testé APRÈS le hachage** : il n'arrête pas l'attaque — *et le dépôt le démontre lui-même dans `LoginTest.php:99-127`* · **énumération de comptes par le temps** |
| **F35-007** | **S2** | `definir-mot-de-passe-crm.sh` **met le mot de passe dans l'`argv`**, **contre son propre en-tête** → *j'avais loué ce script à tort ; correction portée dans `A-012`* |
| F35-008, F35-011, F35-013, F35-014 | S2/S3 | `OwnerUserSeeder` écrit le mot de passe **en clair, sans le `chmod` annoncé**, et **sur la sortie standard** · la règle de longueur minimale est appliquée **à la connexion** : impasse pour un mot de passe court · un lien magique émis **avant** la création du compte ouvre une session ensuite · le script peut annoncer « OK » sur une sortie qui n'en est pas une |

⚠️ **Et trois pièges de mesure qui lui ont menti, consignés dans un fichier dédié** — c'est un
livrable, pas un aveu : la méthode de test du framework **n'envoie pas les en-têtes** (*il a failli
conclure que le SPA était cassé* — **sortie fautive conservée**) · l'outil de rendu **remplace le
gestionnaire d'exceptions** · et un cache d'opcode a servi **un fichier d'amorçage périmé**, d'où des
**419 CSRF qui sont un défaut de son banc, et non des constats produit**.

---

## Agent 42 — performance d'interface : **ce qui est mesurable sans serveur, et il y en a beaucoup**
**Rapport** : `11_GRILLES/agent-42_performance-interface.md` · **Preuves** : `04_PREUVES/agent-42/` (15 fichiers, dont 2 bancs)

**Il commence par borner ce qu'il ne mesure pas** : **aucun temps de réponse d'API publié** — *ce
serait mesurer la file d'attente d'`A-010`*. Les octets qu'il donne viennent de **fichiers statiques**
servis par le Caddy du conteneur `app`, que `A-010` ne concerne pas.

**Le chiffre du mandat est exact** — le bundle principal fait **1 046 364 o** — **mais il ne dit que la
moitié** : la page déclare **les 5 morceaux en préchargement**, donc le premier écran, **sur toutes les
routes**, pèse **2 178 093 o bruts / 627 369 o compressés**.
Dedans : **68,2 % de dépendances, 31,4 % de code maison** · une grappe de **97 529 o pour un seul
composant** · **78 261 o pour un `useEffect`** · **61 103 o pour 27 clés de traduction** · **53 613 o
pour un seul écran** · et **272 330 o d'écrans de route, tous chargés d'emblée**.

**Découpage par route : NON.** Zéro chargement différé. Et **le découpage manuel déclaré ment** : le
morceau `react` sort **vide (44 o)**.

**Les listes, aux cinq volumes** (sans réseau) :

| `ContactsListPage` *(non virtualisé)* | 0 | 1 | 100 | 500 | 10 000 | 100 000 |
|---|---:|---:|---:|---:|---:|---:|
| ms | 731 | 210 | 1 579 | 5 428 | **84 722** | **n'aboutit pas** |
| nœuds DOM | 44 | 70 | 2 545 | 12 545 | **250 045** | — |

`CompaniesListPage` *(virtualisé)* : **155 nœuds à 1 ligne comme à 10 000 — plat.**
**1 fichier sur 31 virtualise.** À 100 000 : deux tentatives, deux arrêts, **8 117 Mo engagés après
11,4 min**. *« Ne converge pas » est le résultat — il ne l'extrapole pas.*

**L'anti-rebond : il n'y en a aucun.** Mot « boulangerie », 11 touches :
`ContactsListPage` **11 requêtes** · `ContactsHubPage` **11** · `CompaniesListPage` **11** ·
recherche globale **10** · **`AudienceBuilderPage`, témoin négatif : 1** (anti-rebond de 500 ms).
*Le témoin négatif rend la mesure inattaquable.* **Croisé avec `A-010` : taper vite dans un champ de
recherche occupe l'unique processus PHP et fait attendre tout le monde.**

| Id | Sév. | Titre |
|---|---|---|
| **G42-001** | **S1** | **Aucun découpage par route** : les 37 écrans sont importés statiquement |
| **G42-003** | **S1** | La carte de couverture : **31 244 points** depuis un fichier de **1 079 714 o sans aucun `Cache-Control`**, **téléchargé deux fois par montage** — *le second appel n'existe que pour journaliser* — moteur **802 715 o préchargés sur les 37 routes** |
| **G42-004** | **S1** | **1 fichier sur 31 virtualise** ; le seul garde-fou est le plafond **serveur** |
| **G42-010** | **S1** | **Aucun anti-rebond** sur 4 champs de recherche sur 5 |
| **G42-007** | **S1** | **9 scrutations, dont deux à 5 s** |
| **G42-006** | **S2** | 🔴 **Les cartes de source sont servies en 200 : 4 174 052 octets de TypeScript public** |
| G42-002, G42-005, G42-008, G42-011 | S2 | Composition du bundle · **morceau `react` vide** · **0 mémoïsation de composant, 2 rappels mémoïsés** · transport de la carte |
| **G42-013** | **S2** | **La seule garde de performance est inerte trois fois** : tolérante à l'erreur, **sans aucun fichier de configuration** (donc elle n'assure rien), et pointée sur **la préproduction** — *donc sur le code d'AVANT la PR*. **Quatorzième cas du patron `A-011`** |

✅ **Et deux griefs classiques qu'il a écartés contre ses propres brouillons** : les contextes trop
larges (**aucun n'existe**) et les clés de liste par index (**10 des 12 portent sur des squelettes
constants**). *Il corrige ses propres hypothèses avant de les publier.*

---

### Agent 45 — rendu final : **20 sabotages, et la contre-vérification de la règle 7**

*(Complète la section « Agent 45 » plus haut, écrite sur son rendu intermédiaire.)*

**20 sabotages joués — 17 suivis de la suite ENTIÈRE (780 tests chacun), 3 ciblés.**
**13 ont fait rougir la garde visée. 7 ne l'ont pas fait.**

✅ **Et un résultat positif qu'il faut dire aussi fort que les défauts** :
**aucun sabotage n'a fait rougir plus de 4 tests par sa propre cause. La suite est PRÉCISE.**
*C'est la qualité qu'on ne mesure jamais : une suite qui rougit à tout n'apprend rien, et celle-ci ne
le fait pas.*

**Les 7 fausses assurances, chacune nommée** :
1. 🔴 **La famille « sans auth → 401, jamais 500 » (25 cas) n'interroge que le chemin JSON.** Mesuré
   **sur la PRODUCTION** : `Accept: application/json` → **401**, `Accept: text/html` → **500**,
   **5 adresses sur 5**. → ***A-001 est vivant en production, et la suite le certifie absent.***
2. Clé de cache HIBP rendue **globale** → **les 780 tests restent verts** (le test affirme `expect(true)->toBeTrue()`).
3. En-tête de la requête HIBP **supprimé** → **780 tests verts**.
4. Le vrai `WorkspaceContext::run(` **retiré** → la garde **le trouve dans un commentaire** et reste verte.
5. `reportUnmatchedIgnoredErrors` **mis en commentaire** → verte (*elle rougit si on écrit `false`, pas si on commente*).
6. `DB_TIMEZONE` **retiré** → la garde reste verte, alors que **ses 6 sœurs de comportement rougissent**.
7. L'**anti-rejeu du canal** désarmé par configuration → **aucun** des 780 tests ne rougit.

### 🔑 La contre-vérification de la règle 7 sur le travail des 18 et 19 août — **elle est POSITIVE**

C'est ce que le dirigeant avait explicitement demandé : *« une partie de ce que tu vas auditer a été
écrite aujourd'hui même par un agent — contre-vérifie ces constats plutôt que de les reprendre. »*
**Fait, et le résultat honore le travail contrôlé** :

- **`CompteursHubTest`** : **4 sabotages, 4 rouges, rayon 0 à chaque fois** — et **chacun sur le bon
  objet** (plan `EXPLAIN` réel, requêtes SQL comptées, chiffres servis à deux univers).
  *Les rouges annoncés le 19/08 sont reproductibles.*
- **`ActivitesEtMotifsTest`** : `insertOrIgnore → upsert` fait rougir **exactement les deux gardes de
  re-semis**, ni plus ni moins.
- **Une seule réserve, et elle est mineure** : la garde de plan **rougit au hasard 1 fois sur 15** —
  le planificateur hésite entre deux index à **0,01 de coût près** sur une table de 2 lignes.
  → `H45-011`, S3.

✅ **Et deux soupçons du mandat levés côté backend** : les pathologies « le test pré-insère ce qu'il
doit produire » et « le mock teste le mock » **n'ont aucun cas avéré** — *les tests qui semblent
pré-insérer relisent en fait ce que la **base** a fait, et les doublures sont affirmées sur l'effet en
base.* **C'était le soupçon le plus lourd du §10 du mandat, et il ne tient pas ici.**

**Restauration** : **20 restaurations vérifiées une à une**, puis `diff -rq` **intégral** sur les deux
conteneurs → identiques. `git status --porcelain -- backend frontend workers infra .github` → **vide**.

---

## Agent 25 — les cinq états d'écran
**Rapport** : `11_GRILLES/agent-25_etats-ecran.md` · **Preuves** : `04_PREUVES/agent-25/` (dont 2 bancs de mesure)

| Id | Sév. | Titre |
|---|---|---|
| **D25-003** | **S1** | 🔴 **`/console/arbitrage` n'affiche pas seulement un zéro : il énonce une CONCLUSION MÉTIER FAUSSE** — « **Tous les événements entrants ont trouvé leur entreprise** ». *Or `B13-001` mesure que **100 %** restent en `pending_match`.* **L'écran dit à l'opérateur l'exact contraire de la vérité** |
| **D25-001** | **S1** | **37 écrans sur 37 rendent un texte strictement identique sous 403 et sous 500** : **aucun** ne distingue un refus de droits d'une panne serveur |
| **D25-002** | **S1** | Sur les 30 écrans qui lisent une donnée, **23 rendent un texte identique** selon que la base est **vide** ou que la requête a **échoué** — et **19 affirment « 0 » ou « aucun »** *(chiffre affiné de `D22-002`)* |
| **D25-009** | **S1** | **9 écrans de liste ne demandent aucune limite au serveur** et rendent tout ce qu'il envoie : `/users` à 10 000 lignes construit **160 025 nœuds et 18 Mo de HTML**, et **n'aboutit plus à 100 000** |
| **D25-004** | **S2** | Trois écrans **ne sont pas « blancs » : ils sont bloqués en chargement pour toujours** — la condition de sortie teste **la donnée**, pas **l'état de la requête** |
| **D25-005** | **S2** | **Un 500 et un 403 sont présentés comme une fiche supprimée** : trois écrans de détail affichent « **introuvable · 404** » sur une panne |
| **D25-008** | **S2** | Le tableau de bord **n'affiche jamais son squelette** : `placeholderData` est un **objet de zéros** — *le premier écran du CRM est une grille de zéros avant toute réponse* |
| D25-006, D25-007, D25-010, D25-011 | S2 | La frontière d'erreur **est écrite, traduite, livrée dans le bundle — et montée nulle part** : toute exception affiche « **Something went wrong!** » **en anglais, hors coquille** · **aucun état d'écran n'est dans l'URL** · le vocabulaire de l'erreur **est traduit et n'est presque jamais appelé** · 3 écrans lisent un champ imbriqué **sans garde** : *une seule clef absente emporte l'écran entier* |

🔑 **`D25-003` est le pire défaut d'interface de l'audit**, et il est d'une autre nature que les autres :
les écrans qui affirment « 0 » sur une erreur **taisent** une information ; celui-ci **en invente une**.
*« Tous les événements entrants ont trouvé leur entreprise » est exactement la phrase qui empêcherait
quelqu'un d'aller voir pourquoi le canal ne crée aucune fiche.*

---

## Agent 28 — accessibilité
**Rapport** : `11_GRILLES/agent-28_accessibilite.md` · **Preuves** : `04_PREUVES/agent-28/`

| Id | Sév. | Titre |
|---|---|---|
| **D28-002** | **S1** | 🔴 **La porte `a11y.yml` mesure quatre écrans VIDES et n'assert que sur `critical`** : elle **ne peut rougir sur aucun des 88 défauts sérieux** du produit, **et les 14 défauts critiques qui existent sont hors de sa portée**. *Quinzième cas du patron `A-011`* |
| **D28-001** | **S1** | **9 écrans construisent leurs tableaux avec `role="row"` sans conteneur ni cellule** : **14 violations critiques** apparaissent **dès que la liste a des lignes** — *donc jamais visibles sur les écrans vides que la porte mesure* |
| **D28-011** | **S1** | **Le mode sombre est le mode le plus contrasté… à l'envers** : **76 défauts de contraste sur 31 écrans**, dont le raccourci de recherche de l'en-tête à **1,36:1** |
| **D28-005** | **S1** | **Le lien d'évitement — seul dispositif de saut de navigation du produit — est illisible en mode clair : 1,19:1 mesuré au pixel** |
| **D28-003** | **S2** | `Modal`, `Drawer` et `GlobalSearch` déclarent `aria-modal="true"` **sans piéger le focus, sans le déplacer à l'ouverture, sans le restituer, et sans neutraliser l'arrière-plan** |
| D28-007, D28-004, D28-008 | S2 | **15 emplacements de champ sans libellé associé** — dont `SearchInput`, **composant du système employé par 8 écrans, qui n'expose aucun moyen d'en poser un** · **5 `<button>` imbriqués** dans un `<button>` · `role="menu"` et `role="tab"` **annoncés sans le clavier qu'ils promettent** |
| D28-009, D28-010, D28-006, D28-012, D28-013, D28-014 | S2/S3 | **113 tailles de police en pixels absolus** : le réglage du navigateur **ne les atteint pas** · le mode sombre **n'est pas appliqué sur 5 écrans hors coquille, dont la connexion** · **les 37 écrans partagent le même titre de document** · la barre émet **6 `<h3>` avant le `<h1>`** · **le `404` n'a aucun élément atteignable au clavier** · **3 régions d'annonce dans tout le produit** |

🔑 **`D28-002` est le cas le plus pur du patron `A-011` de tout l'audit** : la porte d'accessibilité
**mesure des écrans vides** — c'est-à-dire précisément l'état dans lequel **les 14 violations critiques
n'apparaissent pas**, puisqu'elles naissent **des lignes de tableau**. *Une garde qui regarde au bon
endroit, au mauvais moment.*

---

## Agent 30 — mobile et responsive, à 375 px
**Rapport** : `11_GRILLES/agent-30_mobile-responsive.md` · **Preuves** : `04_PREUVES/agent-30/` (captures 375 px)

| Id | Sév. | Titre |
|---|---|---|
| **D30-003** | **S1** | 🔴 **461 cibles tactiles sur 473 mesurent moins de 44 × 44 px**, dont **82 moins de 24 × 24** — **et la coquille de navigation elle-même n'en a aucune conforme** |
| **D30-002** | **S1** | Les **9 tableaux « grille » exigent 718 à 1 088 px** et **ne sont enfermés dans aucun conteneur défilable** : **entre 52 % et 68 % de chaque ligne est inatteignable sur téléphone** |
| **D30-001** | **S1** | **Le conteneur principal rogne au lieu de laisser défiler** : ce qui dépasse 375 px est **perdu, sans barre ni geste pour y accéder** |
| **D30-004** | **S1** | **La barre basse à cinq entrées exigée par le §23.3 n'existe pas**, et **rien n'en tient lieu** : la seule navigation sur téléphone est **un hamburger de 28 × 28 px** |
| **D30-005** | **S2** | Le tiroir **ne se referme pas après une navigation**, couvre tout l'écran **sans voile atteignable**, et laisse **115 px de bande morte** : *chaque parcours coûte **4 appuis au lieu de 2 clics*** — **le §23.4 exige le même budget sur téléphone** |
| **D30-008** | **S2** | `PageHeader` pose `shrink-0` sur son bloc d'actions, **ce qui annule le `flex-wrap` qu'il porte lui-même** : **le repli est écrit et ne peut pas se produire, sur 27 écrans** |
| D30-006, D30-007, D30-009, D30-010 | S2 | La barre repliée **ne se replie pas « aux mêmes positions »** (66 à 78 px d'écart, **jusqu'à 19 des 20 entrées absentes** dans l'autre mode) · le fil d'Ariane, **seul repère de position sur téléphone**, est **écrasé à 94 px** sur 32 écrans · la recherche demande **deux appuis** et **s'annonce en raccourcis clavier** · **23 des 49 fichiers d'écran ne portent aucune règle de mise en page pour petit écran** |

🔑 **`D30-008` mérite d'être lu deux fois** : le composant **déclare** le repli responsive **et
l'annule dans la même classe**. *Ce n'est pas un oubli de responsive — c'est un responsive écrit, puis
neutralisé, sur 27 écrans.* **Personne ne pouvait le voir sans mesurer le rendu.**
