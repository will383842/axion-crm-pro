# AGENT 45 — La VALEUR des tests

> Ce que l'agent 44 mesure : ce qui s'exécute. Ce que je mesure : ce que ça vaut.
> Sorties brutes : `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/agent-45/`.
>
> **Méthode, sans exception** : pour chaque garde retenue, je casse délibérément le code
> qu'elle prétend garder, je joue **la suite ENTIÈRE** (780 tests) pour compter le rayon
> d'explosion, j'archive la sortie, je restaure, et je prouve la restauration.

---

## 0. Référence réellement mesurée, et atelier

Le dossier commun donne `main = c0c453d`. **Relu moi-même** (règle §1) :

```
$ git rev-parse HEAD
e8924b81ad64c0b236acd99ac5cbac4cd68eada7
$ git log --oneline -3
e8924b8 fix(rgpd+acces): rectification du registre + acces CRM rendu (#189)
9d273cd fix(rgpd+acces): le registre affirmait une chose FAUSSE en sa faveur — rectifié le jour même
1145473 docs(rgpd): registre des violations, notification non retenue (#188)
```

**Tous mes constats sont référencés à `main = e8924b8`**, mesuré, 6 commits devant le SHA du dossier.

### L'atelier que j'ai monté, et pourquoi

Deux mesures m'ont forcé à ne PAS travailler dans le conteneur commun :

1. **Ma première ligne de base a été rouge — et c'était un voisin, pas un défaut.**
   `tests/Feature/Crm/ActivitesEtMotifsTest.php` joué dans `axion-crm-api` : **6 échecs sur 15**,
   dont `SQLSTATE[42P01] relation "crm_motifs" does not exist`, un test à **326,86 s**.
   `ps aux` dans le conteneur : **12 processus `pest` / `artisan test` / `migrate:fresh`
   concurrents**, appartenant à d'autres agents. `pg_stat_activity` : une base `axion_crm_agent14`,
   un `COPY` de 16 min sur `axion_crm_dr_a08`. Rejoué en isolation : **0 échec**.
   → **le rouge était de la contention**, pas un défaut du produit (preuve `00_baseline_activites.txt`).
2. **Le montage bind Windows coûte un facteur ~115.** `tests/Unit/SmokeTest.php` (2 tests triviaux) :
   **234,81 s** avec le code monté depuis `C:\` (la recette documentée `infra/scripts/worktree/pest-worktree.sh`),
   **2,14 s** avec le même code **copié dans le conteneur**. Même image, même Postgres.

J'ai donc monté : un **worktree jetable détaché** sur `e8924b8`, une base **`axion_crm_a45`**
(+ `axion_crm_a45b` pour la démonstration de purge), et un conteneur **`a45`** dont l'arbre est
**copié** (pas monté), avec un instantané intact en `/var/www/html.orig` qui sert de référence de
restauration. `axion_crm`, `axion_crm_test` et le worktree `crmpro-wt-etape1a` n'ont **jamais** été
touchés.

### Ligne de base, mesurée trois fois

| Où | Tests | Résultat | Durée |
|---|---|---|---|
| CI GitHub, run `32240894728` sur `9d273cd` | 780 | **780 passés** | **30,18 s** |
| Mon conteneur `a45` (code copié, base `axion_crm_a45`) | 780 | 778 passés, **1 échec**, 1 ignoré | **434,15 s** |
| Conteneur commun `axion-crm-api`, un seul fichier | 15 | **6 échecs** (contention) | 960,59 s |

L'échec local est **`CoverageControllerTest > POST /coverage/launch accepte body valide`** : il
n'existe pas en CI. Cause mesurée : `.env` local porte `MOCK_INSEE=false`, la CI pose
`MOCK_INSEE: 'true'` — et **ni `phpunit.xml` ni `phpunit-ci.xml` n'épinglent ces drapeaux**
(→ **H45-005**). Le test ignoré est `ACQUIS 2` (pg_dump absent du conteneur) ; **il tourne bien en
CI**, vérifié dans le journal du run : `✓ ACQUIS 2 — un dump produit avec la recette de production
se restaure… 2.44s`.

---

## 1. Grille de sabotage — une ligne par sabotage joué

Rayon d'explosion = nombre de tests rouges **hors** l'échec de ligne de base
(`CoverageControllerTest`, présent partout).
« Objet sur lequel la garde rougit » = colonne demandée par le chef de chantier (piège 19).

| # | Test / garde | Ce qu'il prétend garder | Sabotage joué | A-t-il rougi ? | Autres tests rouges | **Rougit sur l'objet qui casse ?** (piège 19) | Verdict |
|---|---|---|---|---|---|---|---|
| **S01** | `CompteursHubTest` › *les compteurs sont servis par un index couvrant* | que l'agrégat des pastilles soit un `Index Only Scan` sur `idx_companies_ws_counts` | `up()` de la migration `2026_08_19_000001` vidée : l'index n'est plus posé | **OUI** | **0** | **Oui** — le plan `EXPLAIN` de la requête réelle ; et la garde **nomme l'index**, ce qui la distingue d'un simple « pas de `Seq Scan` » qui serait passé vert | **GARDE RÉELLE** (mais voir S04-bis : elle est **instable**) |
| **S02** | `CompteursHubTest` › *deux affichages de suite ne recalculent pas* | que le calcul sorte du chemin d'affichage (cache court) | `Cache::flexible` court-circuité, `CompteursHub::pour()` recalcule à chaque appel | **OUI** | **0** | **Oui** — elle compte les requêtes `count(*) … from "companies"` réellement émises, pas la présence d'un appel à `Cache` | **GARDE RÉELLE** |
| **S03** | `CompteursHubTest` › *le cache ne fuit pas vers un autre workspace* | que la clé de cache porte l'identifiant de workspace | `cle()` rend `crm:hub:counts:v1` sans le workspace | **OUI** | **0** | **Oui** — elle lit les **chiffres servis** à deux utilisateurs de deux univers (1 contre 2), pas la forme de la clé | **GARDE RÉELLE** |
| **S04** | `CompteursHubTest` › *une action de masse fait bouger la pastille tout de suite* | l'oubli du cache après un `set_lifecycle` en masse | `CompteursHub::oublier()` retiré de `BulkController:222` | **OUI** | 35 — **mesure polluée** (H45-008, mot de passe du rôle `axion_app` reposé par un autre processus) | — | **REJOUÉ → S04-bis** |
| **S04-bis** | idem | idem | idem, dans un conteneur et une base neufs | **OUI** | **1**, et c'est un **faux rouge** : la garde de plan S01 a rougi **sans cause** (le planificateur a choisi `idx_companies_workspace_relation_type` au lieu de l'index couvrant, coûts 8,14 contre 8,15 sur une table de **2 lignes**) | **Oui** | **GARDE RÉELLE** — et **H45-011** sur l'instabilité de S01 |
| **S05** | `ActivitesEtMotifsTest` › *un libellé modifié SURVIT à un re-semis* + *un motif DÉSACTIVÉ ne se rallume pas* | que le seeder appelé par une migration n'écrase pas les personnalisations (piège 22) | `insertOrIgnore` → `upsert` sur les deux tables | **OUI, les deux** | **0** | **Oui** — elles modifient une ligne depuis « la console », rejouent le seeder, et relisent `label`/`ordre`/`actif` | **GARDE RÉELLE** |
| **S06** | `PhpstanBaselineNeGrossitPasTest` (3 cas) | que la baseline ne grossisse pas et ne vise jamais le socle CRM | une entrée ajoutée à `phpstan-baseline.neon`, visant `app/Crm/Console/CompteursHub.php` | **OUI, les 3** | **0** | **Oui** — plafond (`212 > 211`), en-tête (`212 entrées / 249 erreurs`) et chemin interdit, chacun nommé dans son message. Le 4ᵉ cas (*le parseur lit vraiment des entrées*) reste vert **à raison** : c'est le témoin anti-vacuité | **GARDE RÉELLE** — plafonds réglés **au ras** de l'état courant, donc toute croissance rougit |
| **S07** | `PhpstanBaselineNeGrossitPasTest` › *reportUnmatchedIgnoredErrors reste activé* | que le drapeau reste à `true` | `true` → `false` dans `phpstan.neon` | **OUI** | **0** | **Oui, pour ce sabotage-là** — mais voir **M2** | **GARDE RÉELLE sur la valeur, FAUSSE ASSURANCE sur la mise en commentaire** |
| **S08** | `MasquageCoordonneesTest` (4 cas) | que les coordonnées soient masquées pour un lecteur seul | `MasquageCoordonnees::email()` rend l'adresse en clair | **OUI, 4** (viewer, compte sans rôle, liste entreprises + hub, cas unitaire) | **0** | **Oui** — les trois surfaces HTTP réelles **et** la fonction | **GARDE RÉELLE** |
| **S09** | `MasquageCoordonneesTest` › *🔴 un propriétaire voit les coordonnées EN CLAIR* + *un opérateur aussi* | que le masquage ne soit **pas** appliqué à tout le monde | `requis()` rend toujours `true` | **OUI, les 2** | **0** | **Oui** — la garde anti-sur-masquage, celle que le fichier désigne lui-même comme la plus importante, fonctionne | **GARDE RÉELLE** |
| **S13** | famille « canal » (`SiteSyncIngestTest`, `SiteGdprTest`) | l'anti-rejeu par horodatage signé | défaut de `crm.ingest.max_clock_skew_seconds` mis à **0** (ce que produit une variable d'environnement vide) — l'anti-rejeu rend `true` sans rien vérifier | **NON** | 0 pertinent (11 pollués H45-008, 1 ligne de base, 1 aléa réseau) | **Non — la fenêtre n'est jamais mesurée à 0** : les deux seuls tests qui la touchent la **posent eux-mêmes à 300** | **FAUSSE ASSURANCE** → H45-007 |
| **S14** | `SiteSyncIngestTest` + `SiteGdprTest` › *signature invalide : 401* | la vérification HMAC du canal entrant | `HmacSignature::verify()` rend toujours `true` | **OUI, 2** | **0** | **Oui** — les deux tests posent une **mauvaise signature** sur le réseau et exigent 401 **et** zéro écriture | **GARDE RÉELLE** |
| **S15** | `EtancheiteUniversTest` › *le hub business est fermé à un membre du seul vivier* | la frontière vivier / commercial dans la console | `ConsoleAccess::businessWorkspaceId()` rend l'univers courant sans écarter le vivier | **OUI, 1** | **0** | **Oui** — mais **une seule** des cinq gardes d'étanchéité voit ce sabotage : le cas phare *🔴 un viewer VIVIER ne lit AUCUNE fiche business* interroge `/companies` et `/contacts`, pas la console | **GARDE RÉELLE, étroite** |
| **S16** | `MasquageCoordonneesTest` (téléphone) | le masque du numéro de téléphone | `telephone()` rend le numéro entier | **OUI, 3** | **0** | **Oui** | **GARDE RÉELLE** |
| **M1** | `NeDoitPasRegresserTest` › *ACQUIS 3 — le réglage qui rend le verrou capable de rougir est toujours en place* | que `DB_TIMEZONE` soit toujours posé dans les deux fichiers PHPUnit | `<env name="DB_TIMEZONE">` **retiré**, le mot laissé dans un commentaire XML | **NON — restée VERTE** | 6 autres tests rougissent (les tests **de comportement** du fuseau) | **Non — elle lit le fichier qui décrit l'objet, commentaires compris** | **FAUSSE ASSURANCE (locale)** — la famille rattrape, la garde elle-même ne garde rien → H45-004 |
| **M2** | `PhpstanBaselineNeGrossitPasTest` › *reportUnmatchedIgnoredErrors reste activé* | que le drapeau reste à `true` | la ligne **mise en commentaire** (`# reportUnmatchedIgnoredErrors: true`) — PHPStan revient donc à `false` | **NON — 5 tests verts sur 5** | **0** | **Non — la chaîne cherchée est trouvée dans le commentaire** | **FAUSSE ASSURANCE** → H45-004 |
| **M4** | `PasswordResetWithHibpTest` › *HIBP cache prefix unique par 5 chars du sha1* | que la clé de cache HIBP porte le préfixe SHA-1 | `$cacheKey = 'hibp:range'` (clé globale) | **NON — 12 tests verts sur 12** | **0** | **Non — le test affirme `true`** | **FAUSSE ASSURANCE** → H45-003 |
| **S10** | idem M4, à l'échelle de la suite | idem | idem | *(voir ci-dessous)* | | | |
| **S11** | `PasswordResetWithHibpTest` › *HIBP user-agent inclus dans la requête* | que la requête HIBP porte un `User-Agent` | l'en-tête `User-Agent` retiré du client Guzzle | *(voir ci-dessous)* | | | |
| **S12** | `RlsTest` › *la commande de backfill pose bien son contexte workspace* | que `ScrapingBackfillSrcTags` enveloppe son travail dans `WorkspaceContext::run` | le vrai appel retiré, la chaîne `WorkspaceContext::run(` laissée **dans un commentaire** | *(voir ci-dessous)* | | | |

---

## 2. Les quatre pathologies, recherchées nommément

Balayage outillé sur **100 fichiers / 729 blocs `test()`/`it()`**
(`04_PREUVES/agent-45/07_balayage-pathologies.txt`, script `scan_pathologies.php`), puis **triage à la
main** de chaque candidat. Le balayage seul ne conclut rien : il ne fait que réduire la lecture.

| Pathologie | Candidats bruts | Après triage | Verdict |
|---|---|---|---|
| **1. Le test pré-insère ce qu'il doit faire produire** | 22 | **0 avéré** | Les 22 candidats insèrent une **fixture** puis relisent ce que la **base** en a fait (colonne générée, valeur par défaut, refus d'un `CHECK`, policy RLS) — ce n'est pas la même chose. Les deux seuls de forme suspecte (`un motif/une activité maison s'ajoute SANS migration`) rougissent bien sur l'objet : fermer la table par un `CHECK` fait échouer leur `insert` — **mesuré** (`08_temoin-motif-maison-check-ferme.txt`). |
| **2. Assertion vide ou tautologique** | 1 backend + 3 frontend | **4 avérés** | `PasswordResetWithHibpTest` en porte **deux** (`expect(true)->toBeTrue()` et `expect($captured)->not->toBeNull()`), prouvés inutiles par sabotage → **H45-003**. Frontend : `tests/lib/api.test.ts:23` et `tests/lib/echo.test.ts:52` (`toBeDefined()` sur un export toujours défini) ; `tests/e2e/onboarding.spec.ts:50` (`expect(true).toBe(true)` dans un test nommé « cleanup leave channel after unmount ») — ce dernier n'est de toute façon **jamais joué** (cf. H44-001). |
| **3. Un mock qui teste le mock** | 4 fichiers utilisent une doublure | **0 avéré côté backend** | Les quatre (`GenerateMediaRedactionEmailsTest`, `JournalistsScrapeLlmTest`, `FranceTravailDiscoveryClientTest`) affirment sur l'**effet en base** de la commande, pas sur la valeur rendue par la doublure. Le canal sortant est vérifié par recalcul **indépendant** du HMAC dans le test, et le site (`Axion-IA/axionia/src/server/crm-sync/emit.ts:44`) signe la **même** chaîne `${timestamp}.${body}` — relu, les deux dépôts concordent. |
| **4. Un test statique qui trouve ses propres commentaires** | 5 | **2 avérés + 1 fragile** | `RlsTest:380` cherche `WorkspaceContext::run(` dans le **source brut** : un sabotage qui retire le vrai appel en laissant la chaîne dans un commentaire le laisse **vert** → **H45-004**. `PhpstanBaselineNeGrossitPasTest` cherche `reportUnmatchedIgnoredErrors: true` dans `phpstan.neon` : la mettre en commentaire laisse le test **vert** → **H45-004**. Fragile : `NeDoitPasRegresserTest` cherche `count(*)` et `sha256sum` dans `dr-drill.sh` — il n'en existe qu'une occurrence de code aujourd'hui, mais rien n'empêche un commentaire de la porter demain. **Contre-exemple exemplaire** : `AutorisationCanauxTest:57` retire les commentaires par `token_get_all` **et l'explique** — c'est la seule garde statique du dépôt qui le fasse. |

⚠️ **Piège 1 (CRLF) — non déclenché ici** : aucune garde statique du dépôt ne cherche un `\n` littéral.
`PhpstanBaselineNeGrossitPasTest` normalise explicitement `\r\n` avant d'analyser, et le commente.
Les autres cherchent des sous-chaînes sans fin de ligne. Le piège existe, il n'est pas armé.

---

## 3. Constats

### [H45-001] La garde « sans auth → 401, jamais 500 » n'interroge le produit qu'en JSON : le défaut A-001, vivant en production, lui est invisible
- Sévérité      : S1 grave
- Domaine       : tests
- Référence     : main e8924b8
- Emplacement   : `backend/tests/Feature/Controllers/Sprint189NoFiveHundredTest.php:91` (24 adresses en jeu de données) ; `backend/tests/Feature/Controllers/Phase2StubsExtendedTest.php:42`
- Constat       : les 25 cas qui affirment « sans auth, jamais 500 » appellent tous `getJson()`, qui pose `Accept: application/json` ; **aucun** des 780 tests de la suite n'émet une requête non authentifiée **sans** cet en-tête, et c'est exactement le chemin où le produit rend 500.
- Preuve        : `04_PREUVES/agent-45/04_temoin_401_vs_500_prod.txt` — cinq adresses de PRODUCTION, en lecture seule :
  `GET /api/v1/tags` `Accept: application/json` → **401** ; le même avec `Accept: text/html` → **500**. Idem pour `/dashboard/stats`, `/notifications`, `/audit-logs`, `/saved-views` (5/5).
  Recensement : `grep -rn "\$this->get('" tests/ | grep -v getJson` → **10 appels**, tous **authentifiés** (ce sont les tests d'export).
- Témoin négatif: la même paire de commandes **distingue** bien les deux cas — elle rend 401 sur le chemin JSON et 500 sur l'autre. La sonde sait donc voir la différence ; c'est la garde qui ne la regarde pas.
- Impact        : le défaut A-001 (`Route [login] not defined`) est en production et la suite le certifie absent. Tout appelant qui n'annonce pas JSON — navigateur, `curl` nu, sonde de supervision, moteur d'indexation, client d'intégration — reçoit un **500** au lieu d'un **401**, donc une alerte d'indisponibilité au lieu d'un refus d'authentification.
- Reproduction  : `curl -s -o /dev/null -w "%{http_code}\n" -H "Accept: text/html" https://api.axion-crm-pro.com/api/v1/tags`
- Correctif     : dans `Sprint189NoFiveHundredTest`, doubler le cas « sans auth » avec `$this->get($url, ['Accept' => 'text/html'])`. **~15 min** — et le test rougira aussitôt, ce qui est le but : il nommera A-001.
- Statut        : ouvert

### [H45-002] `retention:purge --dry-run` efface des données et annonce « 0 ligne » — et aucun test ne couvre cette commande planifiée
- Sévérité      : S1 grave
- Domaine       : tests
- Référence     : main e8924b8
- Emplacement   : `backend/app/Console/Commands/RetentionPurge.php:39-41` ; planifiée dans `backend/routes/console.php:15` (`dailyAt('04:00')`)
- Constat       : en `--dry-run`, la réécriture de la requête en compteur ne s'applique pas à l'ordre `UPDATE` (le motif `/^UPDATE (\w+) SET .* WHERE/` n'a pas le drapeau `s` et l'ordre est sur deux lignes) ; l'`UPDATE` est alors passé tel quel à `DB::selectOne()`, qui l'**exécute**.
- Preuve        : `04_PREUVES/agent-45/05_retention-purge-dry-run-mute.txt`, base jetable `axion_crm_a45b` :
  avant → `charge_presente = t`, `payload_path = /tmp/zz-a45.json` ;
  `php artisan retention:purge --dry-run` → « scraper_runs payload (>90j) : **0 lignes seraient affectées** » ;
  après → `charge_presente = f`, `payload_path` **vide**.
  La règle d'expression rationnelle isolée : `04_PREUVES/agent-45/…` (rejouée, `str_starts_with(trim($e),'UPDATE') === true`).
- Témoin négatif: la même mesure sur les deux autres tâches (`DELETE`) montre le comportement CORRECT — le motif `DELETE` matche, la requête devient un `SELECT COUNT(*)` et rien n'est modifié. La sonde distingue donc bien « sec » de « mouillé » ; seul l'`UPDATE` passe au travers.
- Impact        : quiconque lance `--dry-run` pour savoir ce que la purge ferait **détruit** les charges utiles de `scraper_runs` de plus de 90 jours, et lit « 0 ligne » — la destruction est silencieuse et irréversible. `retention:purge` n'est cité par **aucun** fichier de test (`grep -rl 'retention:purge' tests/` → 0).
- Reproduction  : voir la preuve ; trois commandes.
- Correctif     : ajouter le drapeau `s` et ancrer le motif (`/^UPDATE\s+(\w+)\s+SET\s.*?\sWHERE/s`), ou — mieux — écrire les deux requêtes (compteur / mutation) au lieu de dériver l'une de l'autre. Puis un test qui sème une ligne, joue `--dry-run` et vérifie qu'elle est **intacte**. **~45 min**.
- Statut        : ouvert

### [H45-003] Deux des quatre tests HIBP n'affirment rien de ce que leur nom promet : la suite entière reste verte quand on casse ce qu'ils prétendent garder
- Sévérité      : S2 défaut
- Domaine       : tests
- Référence     : main e8924b8
- Emplacement   : `backend/tests/Unit/Rules/PasswordResetWithHibpTest.php:45` (`expect(true)->toBeTrue()`) et `:62` (`expect($captured)->not->toBeNull()`)
- Constat       : le test « HIBP cache prefix unique par 5 chars du sha1 » n'observe pas la clé de cache — il affirme `true` ; le test « HIBP user-agent inclus dans la requête » n'observe pas l'en-tête — il affirme que la requête a bien été construite. Le premier porte d'ailleurs le commentaire « on valide juste qu'on n'a pas crashé ».
- Preuve        : sabotages **S10** et **S11**, suite complète (780 tests) jouée à chaque fois — voir la grille §1 et `04_PREUVES/agent-45/S10-*.txt`, `S11-*.txt`.
- Témoin négatif: les deux autres tests du même fichier (`password "password" est compromis`, `password long custom est sain`) rougissent bien quand on casse la lecture de la réponse HIBP — le fichier n'est donc pas inerte dans son ensemble, ce sont ces deux cas-là qui ne mesurent rien.
- Impact        : la clé de cache HIBP est ce qui empêche la réponse d'un mot de passe de servir pour un autre ; si elle devenait globale, un mot de passe compromis passerait pour sain pendant 24 h et la règle `NotPwnedPassword` laisserait entrer un mot de passe éventé. Rien ne le dirait.
- Reproduction  : voir la grille §1, colonnes « sabotage joué » de S10 et S11.
- Correctif     : remplacer `expect(true)->toBeTrue()` par une lecture de `Cache::has('hibp:range:' . substr(strtoupper(sha1($mdp)),0,5))` pour deux mots de passe de préfixes différents ; et affirmer l'en-tête réellement émis (`$captured->getHeaderLine('User-Agent')`). **~20 min.** ⚠️ Le second test lève par ailleurs un avertissement Guzzle silencieux (`PrepareBodyMiddleware::__invoke(): Return value must be of type PromiseInterface`) : sa doublure ne fonctionne pas, l'appel part dans le `catch (\Throwable)` — le corriger fait partie du même geste.
- Statut        : ouvert

### [H45-004] Deux gardes statiques trouvent leurs propres commentaires : elles restent vertes quand le code qu'elles nomment disparaît
- Sévérité      : S2 défaut
- Domaine       : tests
- Référence     : main e8924b8
- Emplacement   : `backend/tests/Feature/RlsTest.php:380-386` ; `backend/tests/Unit/PhpstanBaselineNeGrossitPasTest.php:221-235`
- Constat       : les deux gardes cherchent une sous-chaîne dans le **texte brut** du fichier (`file_get_contents` + `toContain` / `assertStringContainsString`), commentaires compris. Retirer le vrai réglage en laissant la chaîne dans un commentaire les laisse vertes.
- Preuve        : sabotage **S12** (le vrai `WorkspaceContext::run(` retiré de `ScrapingBackfillSrcTags.php`, la chaîne conservée dans un commentaire) et micro-sabotage **M2** (`reportUnmatchedIgnoredErrors: true` mis en commentaire dans `phpstan.neon`). Voir §1.
- Témoin négatif: le sabotage **S07** (`true` → `false`, sans commentaire) fait **bien** rougir `reportUnmatchedIgnoredErrors reste activé`, seul, sans rien emporter d'autre. Les gardes savent donc rougir — elles ne savent pas distinguer le code du commentaire.
- Impact        : `scraping:backfill-src-tags` écrit sur `tags` et `company_tag` ; sans `WorkspaceContext::run`, une fois `CRM_DB_APP_ROLE_ENABLED` passé à `true`, la commande ne voit plus rien et n'écrit plus rien, **en silence** — la panne exacte du 2026-08-15, que cette garde est censée empêcher de revenir. Côté PHPStan, la baseline redevient muette (une entrée obsolète cesse de rougir) sans que rien ne le signale.
- Reproduction  : voir §1.
- Correctif     : le dépôt possède déjà le bon idiome, écrit et expliqué — `AutorisationCanauxTest:57` retire les commentaires par `token_get_all` avant d'analyser. L'appliquer aux deux gardes (une fonction utilitaire partagée dans `tests/Support/`). **~30 min.** Pour `phpstan.neon`, mieux vaut lire la valeur **effective** (`Nette\Neon\Neon::decode`) que la chaîne.
- Statut        : ouvert

### [H45-005] Les drapeaux `MOCK_*` ne sont épinglés dans aucun des deux fichiers PHPUnit : la suite locale n'est pas hermétique et rougit là où la CI est verte
- Sévérité      : S2 défaut
- Domaine       : tests
- Référence     : main e8924b8
- Emplacement   : `backend/phpunit.xml:29` et `backend/phpunit-ci.xml:50` (seul `MOCK_MODE` est déclaré, **sans** `force="true"`) ; `.github/workflows/ci.yml:415-425` (la CI pose 10 drapeaux `MOCK_*` en variables de job)
- Constat       : l'hermétisme de la suite ne vient pas de sa configuration mais du fichier de workflow. En local, `env_file: .env` du conteneur porte `MOCK_INSEE=false`, `MOCK_ANNUAIRE_ENTREPRISES=false`, `MOCK_BODACC=false`, `MOCK_BAN=false` — et c'est cette valeur qui gagne, par le même chemin `$_SERVER` que `tests/bootstrap.php` documente longuement pour `DB_DATABASE` et corrige pour lui seul.
- Preuve        : ligne de base `04_PREUVES/agent-45/03_baseline_suite_complete.txt` — **1 échec local** (`CoverageControllerTest > POST /coverage/launch accepte body valide`, 500 au lieu de 200) avec, dans le journal :
  `local.ERROR: INSEE auth requires either INSEE_API_KEY … at app/Services/Insee/HttpInseeClient.php:291`.
  Le même test, même commit : `gh run view 32240894728 --log` → `Tests: 780 passed`, **0 échec**.
- Témoin négatif: le reste de la suite est identique dans les deux environnements (778 autres tests, mêmes verdicts) : ce n'est donc pas « la CI est différente en tout », c'est **ce drapeau-là** qui manque.
- Impact        : (1) un développeur qui joue la suite en local voit un rouge qui n'est pas un défaut — c'est ainsi qu'on apprend à ignorer les rouges ; (2) avec des identifiants INSEE présents dans le `.env`, la suite **appelle vraiment l'INSEE** — une suite de tests qui sort sur Internet n'est plus reproductible, et consomme un quota tiers.
- Reproduction  : `docker exec <conteneur> ./vendor/bin/pest tests/Feature/Controllers/CoverageControllerTest.php` avec `MOCK_INSEE=false` dans l'environnement.
- Correctif     : déclarer les 10 drapeaux `MOCK_*` dans `phpunit.xml` **et** `phpunit-ci.xml` avec `force="true"`, comme `DB_DATABASE`. Le workflow peut alors les retirer. **~20 min.**
- Statut        : ouvert

### [H45-006] `app:pentest-self-check` n'a aucun test, annonce deux contrôles qu'il ne fait pas, et mesure la mauvaise colonne pour la RLS
- Sévérité      : S2 défaut
- Domaine       : tests
- Référence     : main e8924b8
- Emplacement   : `backend/app/Console/Commands/PentestSelfCheck.php:9-17` (le docbloc) et `:75-89` (`checkRls`)
- Constat       : (a) `grep -rl "PentestSelfCheck\|pentest-self-check" backend/tests/` → **0 fichier** ; (b) le docbloc annonce « CORS strict (pas de * wildcard) » et « Rate limiting actif sur login/magic-link » — `handle()` n'appelle que quatre contrôles, aucun des deux ; (c) il annonce « RLS Postgres effective sur 30 tables workspace-scoped » et interroge **8** tables, sur la colonne `relrowsecurity` — pas `relforcerowsecurity`.
- Preuve        : lecture du fichier + comptages joués sur `axion_crm` :
  `SELECT count(*) … column_name='workspace_id'` → **72 tables** portent la colonne ;
  `count(*) FILTER (WHERE relrowsecurity)` → **55**, `FILTER (WHERE relforcerowsecurity)` → **55**.
- Témoin négatif: la même requête, restreinte aux 8 tables citées par la commande, rend bien 8 — le comptage n'est pas cassé, c'est la liste de la commande qui est courte.
- Impact        : `FORCE` est précisément ce qui fait mordre la RLS **pour le propriétaire**, et le rôle applicatif réel est le propriétaire tant que `CRM_DB_APP_ROLE_ENABLED` vaut `false` (c'est le cas partout : `.env`, `pest-worktree.sh`, `artisan-worktree.sh`). Un auto-contrôle qui lit `relrowsecurity` dirait « ✓ passed » sur une base où la barrière ne s'applique à personne. Et comme aucun test ne le couvre, la dérive entre le docbloc et le code ne peut être signalée par rien.
- Reproduction  : `docker exec axion-crm-postgres psql -U axion -d axion_crm -c "SELECT count(*) FILTER (WHERE relforcerowsecurity) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind='r';"`
- Correctif     : lire `relforcerowsecurity`, calculer la liste des tables scopées au lieu de la coder en dur (`EtancheiteParTableTest` sait déjà le faire), retirer du docbloc les deux contrôles inexistants ou les écrire, et poser un test qui joue la commande sur une base où une table a été dé-forcée. **~2 h.**
- Statut        : ouvert

### [H45-007] L'anti-rejeu du canal se désarme par configuration, et aucun des 780 tests ne le voit
- Sévérité      : S2 défaut
- Domaine       : tests
- Référence     : main e8924b8
- Emplacement   : `backend/app/Support/HmacSignature.php:49-51` (`if ($maxSkewSeconds <= 0) { return true; }`) ; `backend/config/crm.php:85` (`(int) env('CRM_INGEST_MAX_CLOCK_SKEW', 300)`)
- Constat       : une valeur `<= 0` fait rendre `true` sans rien vérifier — la signature redevient valable pour toujours. `(int) env(...)` produit `0` pour une variable vide ou non numérique. Les deux seuls tests qui touchent ce réglage (`SiteSyncIngestTest:40`, `SiteGdprTest:179`) le **posent eux-mêmes à 300** : la valeur 0 n'est jouée nulle part.
- Preuve        : sabotage **S13** — défaut de `config/crm.php` mis à `0`, suite complète jouée : **aucun** test du canal ne rougit (les 11 échecs observés sont l'effet de bord H45-008, plus l'échec de ligne de base). `grep -rn "max_clock_skew" backend/tests/` → 2 occurrences, toutes deux `=> 300`.
- Témoin négatif: le sabotage **S14** (vérification HMAC rendue toujours vraie) fait rougir **exactement 2** tests (`signature invalide : 401 et aucune écriture`, `signature invalide : 401, et rien n'est appris de l'état du système`). La famille de gardes du canal sait donc rougir sur la signature ; c'est la **fenêtre** qu'elle ne regarde pas quand elle est ouverte.
- Impact        : `CRM_INGEST_MAX_CLOCK_SKEW=` (vide) dans un `.env` de production désarme le rejeu du canal site→CRM sans aucun signal ; une requête légitime interceptée reste rejouable indéfiniment. L'idempotence par `event_id` protège de la duplication, pas du rejeu d'un `consent_optout` ou d'une candidature.
- Reproduction  : poser `CRM_INGEST_MAX_CLOCK_SKEW=` puis rejouer un message signé daté d'une heure.
- Correctif     : deux tests — l'un qui affirme que la valeur par défaut est 300, l'autre qui affirme qu'une valeur `<= 0` est **refusée** (et faire lever `HmacSignature` plutôt que passer). **~30 min.**
- Statut        : ouvert

### [H45-008] Le rôle Postgres `axion_app` est global au cluster et reposé par chaque `migrate` : deux exécutions concurrentes de la suite se détruisent même sur des bases distinctes
- Sévérité      : S2 défaut
- Domaine       : tests
- Référence     : main e8924b8
- Emplacement   : `backend/database/migrations/2026_08_14_000001_*.php:257` (`ALTER ROLE {$role} LOGIN PASSWORD …`) ; `backend/phpunit.xml:57` et `phpunit-ci.xml:65` (`DB_APP_PASSWORD` déclaré **sans** `force="true"`)
- Constat       : `RefreshDatabase` rejoue les migrations à chaque exécution, donc repose le mot de passe d'un rôle **partagé par tout le cluster** ; et la valeur employée est celle de l'environnement du conteneur, pas celle de `phpunit.xml`. Isoler par base de données ne suffit donc pas.
- Preuve        : `04_PREUVES/agent-45/06_role-axion_app-global-au-cluster.txt`. Pendant le sabotage **S04** (base `axion_crm_a45`) : **35** tests emportés par `FATAL: password authentication failed for user "axion_app"`, dont l'intégralité de `RlsTest`, `EtancheiteParTableTest` et `NeDoitPasRegresserTest`. Rejoué pendant **S13** (base `axion_crm_a45c`, autre conteneur) : **11** tests emportés, même cause.
- Témoin négatif: les sabotages S01, S02, S03, S05, S06, S07, S14, S15 — mêmes bases, même code — comptent **0** échec d'authentification : la panne n'est donc pas structurelle, elle survient quand un **autre** processus migre entre-temps. Et la ligne de base initiale, jouée en isolation complète, en compte 0 elle aussi.
- Impact        : tout audit, toute session de développement, toute exécution parallèle rend des rouges qui ne sont pas des défauts — et un rouge qu'on apprend à ignorer est une garde perdue. C'est la même cause que H44-004 / B11-005, mais **au niveau du cluster**, donc non résolue par la seule création d'une base par agent.
- Reproduction  : deux conteneurs, deux bases, deux `pest` simultanés, `DB_APP_PASSWORD` différents.
- Correctif     : poser `force="true"` sur `DB_APP_PASSWORD` dans les deux fichiers PHPUnit (une valeur unique, partout), **et** dériver le nom du rôle de la base (`axion_app_<base>`) pour que deux exécutions ne se marchent plus dessus. **~1 h.** Le nom de base lui-même est codé en dur dans `tests/bootstrap.php:27` : le rendre paramétrable par `AXION_TEST_DB` (avec le garde-fou « le nom commence par `axion_crm_` ») coûterait 15 min de plus.
- Statut        : ouvert

### [H45-009] La recette locale documentée pour jouer la suite est ~115 fois plus lente que la même suite ailleurs — c'est ce qui fait qu'on ne la joue pas
- Sévérité      : S2 défaut
- Domaine       : tests
- Référence     : main e8924b8
- Emplacement   : `infra/scripts/worktree/pest-worktree.sh:38-52` (montage bind du code depuis `C:\`) ; `Makefile` (`test-backend`)
- Constat       : la recette monte le code et `vendor` depuis le système de fichiers Windows. Chaque autoload traverse la frontière ; le coût est mesurable et énorme.
- Preuve        : mêmes image, même Postgres, même commit —
  `tests/Unit/SmokeTest.php` (2 tests triviaux) : **234,81 s** monté / **2,14 s** copié dans le conteneur ;
  `tests/Feature/Crm/ActivitesEtMotifsTest.php` (15 tests) : **960,59 s** monté ;
  suite complète (780 tests) : **434,15 s** copiée, **30,18 s** en CI.
  Soit ~115× entre « monté » et « copié », et ~14× entre « copié » et la CI.
- Témoin négatif: la seule variable changée entre les deux mesures de `SmokeTest` est l'emplacement de l'arbre (`-v C:\…:/var/www/html` contre `docker cp`) — même conteneur de base de données, même image, même `vendor`, même `.env`. Le facteur ne vient donc pas de la charge de la machine.
- Impact        : une suite qu'on ne peut pas jouer en moins de dix minutes n'est jouée qu'en CI, donc **après** la poussée. Combiné à H45-005 (un rouge local qui n'existe pas en CI) et H45-008 (des rouges de contention), le harnais local décourage précisément le geste qu'il devrait rendre facile.
- Reproduction  : les deux commandes de la preuve.
- Correctif     : dans `pest-worktree.sh`, remplacer les montages par un `docker cp` de l'arbre dans un conteneur de longue durée (le `vendor` ne bouge qu'au `composer install`, la vérification `cmp composer.lock` déjà présente sait quand recopier). **~1 h**, et la suite locale passe sous la minute.
- Statut        : ouvert

### [H45-011] La garde de plan des compteurs rougit au hasard : sur une table de deux lignes, le planificateur hésite entre deux index à 0,01 de coût près
- Sévérité      : S3 finition
- Domaine       : tests
- Référence     : main e8924b8
- Emplacement   : `backend/tests/Feature/Crm/CompteursHubTest.php:118-143`
- Constat       : la garde pose `SET enable_seqscan = off` puis exige que le plan contienne `Index Only Scan using idx_companies_ws_counts`. Sur les **deux** lignes que le test sème, PostgreSQL choisit parfois `Index Scan using idx_companies_workspace_relation_type` — un autre index, préexistant — dont le coût estimé est **8,14** contre **8,15** pour le bon.
- Preuve        : `04_PREUVES/agent-45/sabotages/S04bis-oubli-cache-apres-bulk.txt:5548` —
  `Expected: HashAggregate … -> Index Scan using idx_companies_workspace_relation_type on companies (cost=0.12..8.14 rows=1 width=64)` / `To contain: Index Only Scan using idx_companies_ws_counts`, alors que le sabotage de ce tirage ne touchait que `BulkController`.
  Fréquence observée : **1 rouge sans cause sur 15 exécutions** de la suite complète (les 14 autres sabotages + la ligne de base) — les 14 autres tirages rendent ce test vert, et S01 le rend rouge **pour la bonne raison**.
- Témoin négatif: S01 (index réellement retiré) fait rougir ce test et **lui seul** : la garde sait donc voir l'absence de l'index. C'est sa stabilité qui est en cause, pas son intention — laquelle est **excellente** : nommer l'index attendu est exactement ce qui la distingue d'un « pas de `Seq Scan` » qui serait passé vert sans l'index.
- Impact        : un rouge sur quinze, sur une garde de performance, c'est le taux à partir duquel on relance au lieu de lire. Et la garde est **récente** (2026-08-19) : c'est maintenant qu'elle se stabilise, pas après la troisième relance.
- Reproduction  : jouer `tests/Feature/Crm/CompteursHubTest.php` en boucle sur une base fraîche ; le plan bascule selon l'état des statistiques.
- Correctif     : semer une centaine de lignes plutôt que deux (le planificateur cesse alors d'hésiter), **ou** — plus sûr et plus rapide — `ANALYZE companies` avant l'`EXPLAIN`, **ou** interroger `pg_stat_user_indexes` après avoir exécuté la vraie requête pour affirmer que c'est bien `idx_companies_ws_counts` dont `idx_scan` a bougé. **~30 min.**
- Statut        : ouvert

### [H45-010] 25 des 35 tâches planifiées ne sont citées par aucun test — dont quatre destructives — et 8 des 19 `--dry-run` non plus
- Sévérité      : S2 défaut
- Domaine       : tests
- Référence     : main e8924b8
- Emplacement   : `backend/routes/console.php` ; `backend/app/Console/Commands/`
- Constat       : croisement nom de commande × fichiers de test. **10** des 35 commandes planifiées sont citées par au moins un test ; **25** ne le sont par aucun. Parmi ces 25 : `retention:purge` (DELETE quotidien à 04:00), `retention:prune-scraper-runs` (04:20), `rgpd:anonymize-ips`, `media:clean-emails`. Côté option `--dry-run` : **19** commandes l'offrent, **8** ne sont citées par aucun test — la promesse « je ne toucherai à rien » n'est donc vérifiée nulle part pour elles, et H45-002 montre ce que ça coûte.
- Preuve        : `04_PREUVES/agent-45/09_planifiees-vs-tests.txt` (boucle de croisement, sortie brute).
- Témoin négatif: le même croisement **trouve** bien les 10 commandes couvertes (`rgpd:purge-vivier`, `rgpd:purge-business-prospects`, `crm:flush-outbound`, `companies:rescrape-archives`, `media:*` ×6) : le contrôle sait distinguer « cité » de « non cité ».
- Impact        : les deux purges RGPD sont, elles, gardées finement (bornes des deux côtés : 2 ans / J+90 / 3 ans, et « jamais une personne qui a interagi » — vérifié, ces tests sont bons). Les quatre autres commandes destructives tournent chaque nuit sans qu'aucune garde ne dise ce qu'elles font.
- Reproduction  : `grep -n "Schedule::command" backend/routes/console.php | sed "s/.*command('//; s/'.*//" | sort -u` puis `grep -rl <commande> backend/tests/`
- Correctif     : un test par commande destructive, sur le modèle exact de `SiteGdprTest` (semer les deux côtés de la borne, jouer, vérifier qui reste). **~2 h pour les quatre.**
- Statut        : ouvert

---

## 4. Ce que je n'ai PAS pu vérifier, et pourquoi

1. **Les 21 fichiers Vitest et les 16 specs Playwright du frontend, par sabotage.** Je les ai lus
   (pathologie 2 trouvée en trois endroits, §2) mais je n'ai pas monté d'atelier Node isolé : le
   temps est passé dans la campagne backend, et l'agent 44 a déjà établi ce qui s'exécute
   (H44-001 : 267 des 285 tests Playwright ne tournent nulle part). **Un sabotage frontend reste à
   faire** — notamment sur `ConsoleGate` et `ContactsHubPage`.
2. **Les 6 tests des `workers/`.** Même raison. Le seul candidat repéré est
   `workers/tests/registre-sources.test.ts:148` (`toBeDefined()`), qui porte un message d'échec
   explicite — pas une tautologie franche.
3. **`ACQUIS 2` (dump → restauration) joué sous MA main.** Le conteneur d'audit n'embarque pas le
   client PostgreSQL ; le test s'ignore proprement en le disant. J'ai vérifié **dans le journal CI**
   qu'il s'exécute et passe (`gh run view 32240894728 --log` → `✓ ACQUIS 2 … 2.44s`), mais je ne l'ai
   pas **saboté** : casser `restore-postgres.sh` pour voir le rouge demandait un runner avec
   `pg_dump`, hors de portée ici. **C'est la garde la plus chère du dépôt et c'est la seule que je
   n'ai pas éprouvée.**
4. **Le rayon d'explosion de S04 (oubli du cache après action de masse).** La mesure a été polluée
   par H45-008 (35 tests emportés par le mot de passe du rôle `axion_app`, reposé par un autre
   processus pendant l'exécution). Le test **cible** a bien rougi ; le comptage des autres est
   inutilisable dans ce tirage. Rejoué → voir la ligne S04-bis de la grille §1.
5. **Le comportement des gardes sous locale `C` contre `en_US.utf8`** (piège 10). `EmpreinteSqlEtPhpTest`
   le mesure déjà et le fixe explicitement ; je n'ai pas ouvert un second cluster pour rejouer la
   divergence. Rien ne le contredit, rien ne le confirme de ma main.
6. **La signature HMAC de bout en bout entre les deux dépôts.** J'ai relu les deux implémentations
   (`HmacSignature::signedPayload` et `axionia/src/server/crm-sync/emit.ts:44`) : elles signent la
   même chaîne `<timestamp>.<corps>`. Je n'ai **pas** monté les deux applications pour émettre un
   vrai message de l'une à l'autre — c'est le périmètre des agents 13/14.
7. **Le nombre exact de tests emportés par H45-008 dans le cas général.** Je ne l'ai observé que
   dans mes deux tirages contaminés (35 et 11) ; il dépend de l'instant où l'autre migration passe.
