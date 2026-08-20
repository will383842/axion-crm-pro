# P6 — REGARD NEUF : AUTOMATISMES ET SCHÉMA

> Périmètre : `backend/routes/console.php`, `backend/app/Jobs/`,
> `backend/app/Console/Commands/`, `backend/database/migrations/`.
> Grilles §5.4 (automatismes) et §5.3 (modèles et migrations) du mandat.
> Auditeur neuf : aucun accès aux constats des passes 1 et 2, ni aux grilles,
> ni aux preuves, ni au rapport final. Analyse **statique et lecture seule** ;
> aucune exécution sur la production, aucune requête vers l'hôte Hetzner,
> aucune écriture dans un dépôt.

---

## 0. Référence réelle sur laquelle j'ai mesuré

Relue moi-même, comme l'exige la règle 6 du mandat — je n'ai repris aucun
identifiant de commit écrit dans un document.

```
$ git -C C:/Users/willi/Documents/Projets/crmpro-wt-a35-auth log --oneline -3
23a0e5f fix(infra): la faille du 19 aout se rouvrait en un clic, et par deux autres chemins
a0a6310 test(garde): trois defauts de la garde HMAC, dont un qui polluait toute la suite
3c2c0cf docs(rectificatif): trois affirmations sur la production que ce lot ne pouvait pas mesurer

$ git -C ... rev-parse --abbrev-ref HEAD
fix/a35-authentification

$ git -C ... rev-parse HEAD
23a0e5fbb4af40a1a046e0388135d5cd7710a229

$ git -C ... status --porcelain
(vide — arbre propre)
```

**Tout ce qui suit porte sur `fix/a35-authentification` @ `23a0e5f`, arbre
propre.** Cette branche n'est pas fusionnée : un lecteur de `main` ne verra pas
le même code.

⚠️ **La référence a bougé sous moi pendant la mesure, et je le dis.** En fin
d'audit, `HEAD` valait `b6fa07f` : un autre agent a commité sur la même branche
et le même arbre de travail entre le début et la fin de mon analyse. J'ai
vérifié que l'écart ne touche **aucun** fichier de mon périmètre :

```
$ git log --oneline 23a0e5f..b6fa07f
b6fa07f fix(runbooks): sept invocations nues de plus — et c'est moi qui ai commis le patron

$ git diff --name-only 23a0e5f..b6fa07f -- backend/routes/console.php backend/app/Jobs \
      backend/app/Console backend/database/migrations
(aucun fichier)

$ git diff --name-only 23a0e5f..b6fa07f
backend/tests/Feature/Infra/PileDeProductionSansOverlayTest.php
infra/runbooks/01-restart-workers.md
infra/runbooks/03-site-down.md
infra/runbooks/04-restore-dr.md
infra/runbooks/05-rotate-secrets.md
```

Mes constats restent donc valides sur `b6fa07f` — mais un travail concurrent
sur l'arbre de travail que l'on m'a désigné pour mesurer est en soi un défaut
de dispositif, pas seulement une gêne : un auditeur ne peut pas garantir la
reproductibilité de ce qu'il mesure sur une référence mouvante.

Environnement d'appoint pour les contrôles SQL : le conteneur local
`bookforge-postgres` (`PostgreSQL 16.13`), **choisi exprès** parce qu'il
n'appartient pas à ce projet et que mes requêtes n'y créent ni ne modifient
rien — le conteneur `a35r` et `axion-crm-postgres` n'ont **pas** été touchés,
un autre agent y mesure.

**Note de méthode (règle 3).** Aucun de mes contrôles textuels ne porte sur une
sous-chaîne accentuée. Les motifs joués sont `dry`, `purge`, `workspace`,
`Schedule::command`, `CONCURRENTLY`, `env(`, `run_maintenance`,
`website_method` — tous sans lettre accentuée.

---

## 1. Ce que j'ai inventorié avant de chercher

```
$ wc -l routes/console.php                      → 170
$ find app/Jobs -name '*.php' | wc -l           → 7   (dont 1 trait)
$ find app/Console -name '*.php' | wc -l        → 50  (49 commandes + 1 trait)
$ ls database/migrations/ | wc -l               → 59
$ grep -ohE "Schedule::command\('[^' ]+" routes/console.php | sort -u | wc -l → 33
```

33 commandes distinctes tournent sans qu'on le demande, plus 6 jobs de file.

---

## 2. CONSTATS

### [P6-AUTO-001] La tâche RGPD d'anonymisation des IP exécute un SQL qui n'existe pas en PostgreSQL

- **Sévérité** : **S0** — non-conformité RGPD. L'anonymisation est la seule
  mesure de minimisation prévue sur `audit_logs.ip` et `sessions.ip_address` ;
  elle est planifiée, elle est comptée comme faite, et elle n'a **jamais** pu
  s'exécuter une seule fois. Ce n'est pas « un cron en échec » : c'est une
  obligation légale qui n'a aucun exécutant.
- **Domaine** : conformité / backend
- **Référence** : `fix/a35-authentification` @ `23a0e5f`
- **Emplacement** : `backend/app/Console/Commands/AnonymizeOldIps.php:25-29`,
  planifiée par `backend/routes/console.php:16`
  (`Schedule::command('rgpd:anonymize-ips')->dailyAt('04:30')`)
- **Constat** : la requête écrit
  `ip::cidr / CASE WHEN family(ip) = 4 THEN 24 ELSE 48 END`. PostgreSQL n'a
  aucun opérateur `cidr / integer` ; l'opérateur attendu ici est
  `set_masklen(inet, int)`.
- **Preuve** :

```
$ docker exec bookforge-postgres psql -U bookforge -d bookforge -tAc "SELECT version();"
PostgreSQL 16.13 on x86_64-pc-linux-musl, compiled by gcc (Alpine 15.2.0) 15.2.0, 64-bit

$ docker exec bookforge-postgres psql -U bookforge -d bookforge -tAc \
  "SELECT (host(network('192.168.42.123'::inet::cidr / CASE WHEN family('192.168.42.123'::inet) = 4 THEN 24 ELSE 48 END)))::inet;"
ERROR:  operator does not exist: cidr / integer
LINE 1: SELECT (host(network('192.168.42.123'::inet::cidr / CASE WHE...
                                                          ^
HINT:  No operator matches the given name and argument types. You might need to add explicit type casts.
```

  Le type de colonne est bien `INET`, donc l'échec ne vient pas d'un cast :

```
$ sed -n '194,207p' database/migrations/2026_05_16_000002_create_auth_tenant_audit_schema.php
CREATE TABLE audit_logs ( … ip INET, … );
```

- **Témoin négatif** : la même commande, sur le même serveur, avec la fonction
  qui existe réellement, rend le résultat attendu — mon contrôle sait donc
  distinguer un SQL valide d'un SQL invalide, il ne rougit pas par principe :

```
$ docker exec bookforge-postgres psql -U bookforge -d bookforge -tAc \
  "SELECT host(network(set_masklen('192.168.42.123'::inet::cidr, 24)))::inet;"
192.168.42.0
```

- **Impact** : la branche `audit_logs` lève une `QueryException` **avant** la
  branche `sessions` (lignes 25 puis 31) : ni les IP du journal d'audit ni
  celles des sessions ne sont anonymisées, à aucune date. Les IP y sont donc
  conservées sans limite. Aucun test ne couvre la commande (cf.
  [P6-AUTO-012]), et aucune tâche planifiée n'a de traitement d'échec (cf.
  [P6-AUTO-011]) : rien ne l'a signalé.
- **Reproduction** : `php artisan rgpd:anonymize-ips` sur une base PostgreSQL.
  ⚠️ Ne pas jouer en production. La branche `--dry-run` ne reproduit **pas** le
  défaut : elle passe par `->count()` et ne touche pas au SQL fautif — c'est ce
  qui a permis au défaut de survivre à toute vérification manuelle « à blanc ».
- **Correctif proposé** : `set_masklen(ip::cidr, CASE …)` ; et un test qui joue
  la commande sur une ligne d'audit réelle. Coût : ~1 h avec le test.
- **Statut** : ouvert (je ne répare pas).

---

### [P6-AUTO-002] `retention:purge --dry-run` exécute réellement l'écriture qu'il prétend simuler

- **Sévérité** : **S0** — perte de données. L'essai à blanc est *précisément*
  le geste que fait un opérateur prudent avant d'agir ; ici il détruit, sans
  retour possible, les charges utiles `scraper_runs` de plus de 90 jours.
- **Domaine** : backend / conformité
- **Référence** : `fix/a35-authentification` @ `23a0e5f`
- **Emplacement** : `backend/app/Console/Commands/RetentionPurge.php:32-42`
- **Constat** : en mode `--dry-run`, la commande réécrit le SQL par deux
  `preg_replace` pour le transformer en `SELECT COUNT(*)`. Le motif
  `/^UPDATE (\w+) SET .* WHERE/` ne comporte **pas** le modificateur `s` ;
  or l'instruction `UPDATE scraper_runs` est écrite sur deux lignes. `.*` ne
  franchit pas le saut de ligne, la substitution échoue silencieusement
  (`preg_replace` rend le sujet inchangé), et l'`UPDATE` intact est passé à
  `DB::selectOne()` — qui fait `prepare()` + `execute()`, donc **écrit**.
- **Preuve** — les octets du fichier (le saut de ligne est visible en `$`) :

```
$ sed -n '27,42p' app/Console/Commands/RetentionPurge.php | cat -A
            'scraper_runs payload (>90j)' =>$
                "UPDATE scraper_runs SET response_payload = NULL, payload_path = NULL$
                 WHERE created_at < now() - INTERVAL '90 days' AND response_payload IS NOT NULL",$
        ];$
…
                $explainSql = preg_replace('/^DELETE FROM (\w+)/', 'SELECT COUNT(*) AS c FROM $1', $sql);$
                $explainSql = preg_replace('/^UPDATE (\w+) SET .* WHERE/', 'SELECT COUNT(*) AS c FROM $1 WHERE', $explainSql);$
                $count = DB::selectOne($explainSql)->c ?? 0;$
```

  La transformation rejouée telle quelle :

```
$ php -r '…les deux preg_replace du fichier, sur le SQL du fichier…'
RESULTAT DRY-RUN =>
UPDATE scraper_runs SET response_payload = NULL, payload_path = NULL
                 WHERE created_at < now() - INTERVAL ' 90 days' AND response_payload IS NOT NULL
COMMENCE PAR UPDATE ? bool(true)
```

  Et le chemin `select()` de Laravel (`prepare` + `execute` + `fetchAll`)
  écrit bien, démonstration reproductible hors de toute base du projet :

```
$ php -r '$pdo=new PDO("sqlite::memory:"); … $st=$pdo->prepare("UPDATE t SET payload = NULL WHERE id = 1"); $st->execute(); …'
APRES le chemin select() : payload efface ? int(1)
```

- **Témoin négatif** : le même contrôle, appliqué aux **deux autres** tâches de
  la même boucle (les `DELETE`), montre que la réécriture fonctionne pour
  elles. Mon contrôle isole donc un cas, il ne condamne pas la mécanique en
  bloc :

```
DELETE   -> requete envoyee a selectOne() commence par : SELECT
UPDATE   -> requete envoyee a selectOne() commence par : UPDATE
```

- **Impact** : `php artisan retention:purge --dry-run` efface définitivement
  `response_payload` et `payload_path` de toutes les lignes `scraper_runs` de
  plus de 90 jours, en annonçant à l'écran « … lignes **seraient** affectées ».
  Le compte affiché sera d'ailleurs `0` (un `UPDATE` sans `RETURNING` ne rend
  aucune colonne `c`), donc l'opérateur lit « 0 ligne seraient affectées »
  **après** que la destruction a eu lieu.
- **Élément aggravant, mesuré** : PHPStan a signalé ces deux lignes exactes, et
  les signalements sont **gelés** dans la baseline :

```
$ sed -n '201,212p' phpstan-baseline.neon
    message: '#^Parameter \#1 \$query of static method …\:\:selectOne\(\) expects string, string\|null given\.$#'
    path: app/Console/Commands/RetentionPurge.php
    message: '#^Parameter \#3 \$subject of function preg_replace expects …, string\|null given\.$#'
    path: app/Console/Commands/RetentionPurge.php
```

- **Correctif proposé** : ne pas réécrire du SQL à la `preg_replace` ; écrire
  la requête de comptage à côté de la requête d'écriture, comme le font déjà
  `media:sync-emissions-from-parent` et `media:link-to-companies` dans le même
  dépôt (voir témoin § 3.2). Coût : ~2 h avec un test qui prouve que
  `--dry-run` ne modifie aucune ligne.
- **Statut** : ouvert.

---

### [P6-AUTO-003] La rétention légale annoncée dans le code n'a aucun exécutant : `llm_usage` nulle part, `audit_logs` confiée à un `run_maintenance` que personne ne joue

- **Sévérité** : **S0** — non-conformité RGPD. Deux politiques de conservation
  sont écrites noir sur blanc dans l'en-tête de la commande de rétention ;
  aucune des deux n'existe en code.
- **Domaine** : conformité / backend
- **Référence** : `fix/a35-authentification` @ `23a0e5f`
- **Emplacement** : `backend/app/Console/Commands/RetentionPurge.php:9-15`
  (l'en-tête qui promet), `backend/database/migrations/2026_05_17_000011_setup_pg_partman_audit_logs.php:141-156`,
  `Dockerfile.postgres:37-51`
- **Constat** :
  1. **`llm_usage > 12 mois → archivage + suppression`** (ligne 13 de l'en-tête)
     n'est implémenté nulle part. La seule mention de `llm_usage` dans une
     opération de suppression, dans tout `app/`, est ce commentaire.
  2. **`audit_logs > 24 mois`** (ligne 10) est délégué à pg_partman
     (`retention = '24 months'`, `retention_keep_table = true`). Or la purge
     pg_partman n'a lieu que lorsque `partman.run_maintenance*` est appelé —
     et il ne l'est jamais par l'application. Le `Dockerfile.postgres` compile
     l'extension avec `NO_BGW=1`, en désignant explicitement le remplaçant :
     « *le cron de partition mgmt sera Laravel scheduler* ». `routes/console.php`
     ne contient aucune entrée de ce genre.
- **Preuve** :

```
$ grep -rn "llm_usage" app/ --include=*.php | grep -i "delete\|purge\|retention\|drop"
app/Console/Commands/RetentionPurge.php:13: * - llm_usage > 12 mois          → archivage + suppression
(rien d'autre)

$ grep -rn "run_maintenance" <racine du worktree>   (hors _AUDIT/ et _REPORTS/)
infra/runbooks/02-disk-full.md:23:  SELECT partman.run_maintenance('public.audit_logs', p_jobmon := false);
→ une seule occurrence, dans un runbook d'incident joué À LA MAIN.

$ sed -n '37,51p' Dockerfile.postgres
    make NO_BGW=1; \
# pg_partman a un BGW (background worker) optionnel. On le désactive via NO_BGW=1 build flag,
# car on n'utilise pas le maintenance worker (le cron de partition mgmt sera Laravel scheduler).

$ grep -n "partman\|maintenance" backend/routes/console.php
(aucune ligne)
```

- **Témoin négatif** : le même contrôle (`grep` d'un identifiant de politique
  de rétention dans `routes/console.php`) trouve bien les rétentions qui, elles,
  sont planifiées — `retention:purge` (l. 15), `retention:prune-scraper-runs`
  (l. 135). Il ne rend donc pas « absent » par construction.
- **Impact** : `audit_logs` — table qui porte IP, user-agent et empreintes de
  charge utile — croît sans borne et n'est jamais détachée ; `llm_usage` (coût
  et usage LLM par workspace) n'est jamais purgé. La commande de rétention,
  elle, sort en `SUCCESS` et son en-tête laisse croire que ces deux politiques
  s'appliquent. Le décalage entre ce que le code dit et ce qu'il fait est ici
  le vrai danger : il rend le contrôle de conformité impossible par lecture.
- **Correctif proposé** : soit planifier `partman.run_maintenance_proc()` et
  écrire la purge `llm_usage`, soit **corriger l'en-tête** pour qu'il ne décrive
  plus du code inexistant. Les deux sont acceptables ; laisser l'en-tête tel
  quel ne l'est pas. Coût : 1 j pour l'option complète.
- **Statut** : ouvert.

---

### [P6-AUTO-004] Une tâche planifiée quotidienne détruit des données de contact sans essai à blanc, sans plafond, sans confirmation et sans journal — alors que la garde existe dans le dépôt

- **Sévérité** : **S1** — la commande est destructive, elle tourne toute seule
  tous les jours à 05:05, et le dépôt contient déjà exactement la garde qui
  manque ici. Ce n'est pas un oubli théorique : c'est une exception à une règle
  que le projet s'est écrite.
- **Domaine** : backend
- **Référence** : `fix/a35-authentification` @ `23a0e5f`
- **Emplacement** : `backend/routes/console.php:142`
  (`Schedule::command('media:clean-emails --threshold=10')->dailyAt('05:05')`),
  `backend/app/Console/Commands/MediaCleanEmails.php:37-132`
- **Constat** : `media:clean-emails` met à `NULL` la colonne `media.email` de
  toutes les fiches dont l'adresse est jugée parasite ou « sur-partagée ». Le
  planificateur ne passe **pas** `--dry-run`. La commande n'utilise pas le trait
  `RefuseUneSuppressionMassive` (`app/Console/Concerns/`), qui existe dans ce
  même dépôt et impose les trois barrières — essai à blanc, plafond de
  proportion, confirmation. Aucune écriture dans la chaîne d'audit. Le seuil
  `--threshold=10` est le seul garde-fou, et il porte sur la *sélection*, pas
  sur le *volume détruit*.
- **Preuve** :

```
$ grep -n "clean-emails" routes/console.php
142:Schedule::command('media:clean-emails --threshold=10')->dailyAt('05:05')->withoutOverlapping()->onOneServer();
   → aucun --dry-run

$ grep -rln "RefuseUneSuppressionMassive" app/Console/
app/Console/Concerns/RefuseUneSuppressionMassive.php
app/Console/Commands/ProspectionPurgeNonDiffusible.php
app/Console/Commands/ProspectionPurgeNonCommercial.php
   → MediaCleanEmails.php n'y figure pas

$ grep -n "AuditHashChain\|audit->record" app/Console/Commands/MediaCleanEmails.php
(aucune ligne)
```

- **Témoin négatif** : la recherche du trait de garde **trouve** deux commandes
  qui l'utilisent — le contrôle sait donc reconnaître la présence de la garde,
  son silence sur `MediaCleanEmails` est un fait, pas un artefact.
- **Impact** : une régression du motif `PARASITE_PATTERNS` ou un domaine
  ajouté par erreur à `CONSUMER_DOMAINS` efface, en une nuit, les emails d'une
  part arbitraire de la base médias, sans qu'aucun plafond n'arrête la course
  et sans trace pour reconstituer l'avant. Second effet, plus discret :
  ligne 95-101, l'`UPDATE` ne filtre pas `deleted_at`, donc il touche aussi les
  fiches déjà supprimées logiquement.
- **Défaut de tenue en mémoire, dans la même commande** : lignes 57-66, la
  commande charge en mémoire PHP **toutes** les adresses non nulles de la table
  `media` (`->get()->pluck('email')`) avant de filtrer. Aucune borne, aucun
  `chunk`. Les autres commandes du même lot sont explicitement bornées par
  `--limit` « pour éviter la fuite mémoire » (cf. le commentaire de `routes/console.php:86-92`) ;
  celle-ci ne l'est pas.
- **Correctif proposé** : appliquer `RefuseUneSuppressionMassive`, planifier
  avec `--dry-run` tant que le comportement n'est pas mesuré, journaliser le
  bilan dans la chaîne d'audit, remplacer le `->get()` par un `chunkById`.
  Coût : ~3 h.
- **Statut** : ouvert.

---

### [P6-AUTO-005] Dix commandes — dont six planifiées — choisissent leur espace de travail de destination par « le plus ancien créé »

- **Sévérité** : **S1** — dans un CRM à univers cloisonnés (business /
  vivier-candidats), la destination d'un import est une décision, pas un effet
  de bord de l'ordre de création des lignes. Le projet écrit lui-même cette
  doctrine — puis ne l'applique pas à ses propres automatismes.
- **Domaine** : sécurité / backend
- **Référence** : `fix/a35-authentification` @ `23a0e5f`
- **Emplacement** — les six **planifiées** :
  `app/Console/Commands/GenerateMediaRedactionEmails.php:60`,
  `ImportMediaBlogs.php:42`, `ImportMediaEmissionsFromWikidata.php:81`,
  `ImportMediaFromArcom.php:84`, `ImportMediaFromOpendatasoft.php:73`,
  `MediaTagEmissionsStatus.php:51`.
  Les quatre autres, lancées à la main ou par un workflow GitHub :
  `ImportMediaPressKit.php:60`, `ImportRpps.php:40`,
  `ProspectionCollect.php:48`, `ProspectionEnrich.php:35`.
- **Constat** : le motif est identique partout :
  `$this->option('workspace') ?: Workspace::query()->orderBy('created_at')->value('id')`
  (ou sa variante `DB::table('workspaces')->orderBy('created_at')->value('id')`).
  Le planificateur ne passe **jamais** `--workspace`, donc en production c'est
  **toujours** la branche de repli qui s'applique : l'espace de travail le plus
  anciennement créé.
- **Preuve** :

```
$ grep -rln "orderBy('created_at')->value('id')" app/Console/Commands/*.php | wc -l
10

$ grep -c "workspace" routes/console.php
0
   → le mot n'apparait pas une seule fois dans les 170 lignes du planificateur :
     aucune des 33 entrees ne passe --workspace.

$ for c in media:generate-redaction-emails media:import-blogs media:import-emissions-wikidata \
           media:import-arcom media:import-opendatasoft media:import-press-kit rpps:import \
           media:tag-emissions-status prospection:collect prospection:enrich; do
      grep -q "Schedule::command('$c" routes/console.php && echo "PLANIFIEE    : $c" || echo "non planifiee: $c"; done
PLANIFIEE    : media:generate-redaction-emails
PLANIFIEE    : media:import-blogs
PLANIFIEE    : media:import-emissions-wikidata
PLANIFIEE    : media:import-arcom
PLANIFIEE    : media:import-opendatasoft
non planifiee: media:import-press-kit
non planifiee: rpps:import
PLANIFIEE    : media:tag-emissions-status
non planifiee: prospection:collect
non planifiee: prospection:enrich
```

  La doctrine que ce repli contredit, écrite dans `config/crm.php` :

  > « le site n'a PAS à connaître les workspaces du CRM : la destination est une
  > décision du CRM, pas une donnée du payload (sinon un appelant compromis
  > choisirait l'univers d'atterrissage de ses fiches, vivier compris) ».

- **Témoin négatif** : le même contrôle, appliqué à
  `ExtractMediaFromCompanies.php:37`, montre une commande qui fait
  correctement les choses — elle propage `c.workspace_id` depuis la ligne
  source au lieu d'en deviner un. Mon contrôle discrimine donc bien.
- **Impact** : (a) si l'ordre de création des espaces de travail change — base
  reconstruite, workspace supprimé puis recréé, jeu de démonstration semé avant
  l'espace réel — les six imports planifiés (CPPAP/SPEL/agences via
  OpenDataSoft, ARCOM, Wikidata, blogs curés, statut des émissions, emails de
  rédaction) basculent d'univers sans qu'aucune alerte ne se déclenche ;
  (b) rien dans le code ni dans les tests
  n'énonce l'invariant « le plus ancien workspace est l'espace business » — il
  n'est ni vérifié ni vérifiable.
- **Correctif proposé** : résoudre la destination par `slug` explicite (le
  dépôt a déjà `Taxonomy::VIVIER_WORKSPACE_SLUG` et le slug `axion-ia`), et
  échouer bruyamment si le slug est introuvable. Coût : ~2 h + tests.
- **Statut** : ouvert.

---

### [P6-AUTO-006] Aucune des 33 tâches planifiées ne pose de contexte d'espace de travail — la ceinture applicative ne couvre que 4 modèles sur 16

- **Sévérité** : **S1** — le code lui-même décrit ce piège comme « le pire des
  échecs » et l'a outillé (`RunsInWorkspace`, `WorkspaceContext::run`,
  `MissingWorkspaceContextException`). L'outillage existe ; il n'est branché
  presque nulle part dans le périmètre des automatismes.
- **Domaine** : sécurité / backend
- **Référence** : `fix/a35-authentification` @ `23a0e5f`
- **Emplacement** : `backend/routes/console.php` (l'ensemble),
  `app/Jobs/` (5 jobs sur 6), `app/Models/Concerns/BelongsToWorkspace.php`
- **Constat** :
  1. Sur les 33 commandes planifiées, **deux seulement** appellent
     `WorkspaceContext::run` : `rgpd:purge-vivier` et
     `rgpd:purge-business-prospects` — et ces deux-là sont sautées par le
     planificateur (drapeau `CRM_PURGE_ENABLED` à `false`, cf. [P6-AUTO-007]).
     Autrement dit : **en production, aucune tâche planifiée ne pose de
     contexte.**
  2. Sur 6 jobs de file, un seul utilise le trait `RunsInWorkspace`
     (`EnrichCompanyJob`). `RefreshAudienceChunkJob`, `LaunchCampaignJob`,
     `LaunchZoneScrapingJob`, `MonitorCampaignProgressJob`, `DispatchScrapeJob`
     ne le font pas — alors que `AppServiceProvider.php:92` **efface** le
     contexte avant chaque job (`Queue::looping`), donc ils démarrent
     systématiquement sans contexte.
  3. La ceinture Eloquent (`BelongsToWorkspace` + `WorkspaceScope`) est posée
     sur **4 modèles** : `Candidate`, `Company`, `Contact`, `Tag`. Seize
     modèles mentionnent `workspace_id` ; douze n'ont donc pas la ceinture —
     `AudienceMember`, `AuditLog`, `EmailAudience`, `HealthPractitioner`,
     `Journalist`, `LlmUseCase`, `Media`, `ProxyProvider`, `RgpdRequest`,
     `ScraperRun`, `ScrapingCampaign`, `User`. Deux d'entre eux sortent
     légitimement du compte (`User` ne porte que `current_workspace_id`,
     `LlmUseCase` est la seule table à lignes globales assumée par la migration
     de durcissement) ; **dix restent sans ceinture sans justification écrite**,
     dont `Media` — la table qu'écrivent 16 tâches planifiées.
- **Preuve** :

```
$ grep -rn "WorkspaceContext::" app/Console/Commands/ | grep -v "^.*://"
app/Console/Commands/ProspectionEnrich.php:42          (non planifiee)
app/Console/Commands/RgpdPurgeBusinessProspects.php:54 (planifiee mais SAUTEE)
app/Console/Commands/RgpdPurgeVivier.php:52            (planifiee mais SAUTEE)
app/Console/Commands/ScrapingBackfillSrcTags.php:98    (non planifiee)

$ grep -rln "RunsInWorkspace" app/Jobs/
app/Jobs/Concerns/RunsInWorkspace.php
app/Jobs/EnrichCompanyJob.php

$ grep -rl "workspace_id" app/Models/*.php | wc -l     → 16
$ grep -rl "BelongsToWorkspace" app/Models/*.php | wc -l → 4
   Candidate.php  Company.php  Contact.php  Tag.php
```

- **Témoin négatif** : le contrôle **trouve** les quatre appels existants et le
  seul job équipé. Il n'est donc pas aveugle : c'est bien l'absence ailleurs
  qui est le fait.
- **Impact — conditionnel, et c'est ce qui le rend dangereux** : tant que
  `CRM_DB_APP_ROLE_ENABLED` est à `false`, l'application se connecte avec un
  rôle `SUPERUSER`/`BYPASSRLS` et rien ne se voit. Le jour de la bascule
  (migration `2026_08_14_000001_harden_workspace_isolation.php` : policy stricte
  `workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), '')`,
  **sans** repli permissif, en `FORCE`), toutes les tâches sans contexte
  basculent en deux comportements :
  - **lectures et suppressions** → 0 ligne visible, sortie `SUCCESS`.
    `retention:prune-scraper-runs` annoncera « 0 lignes purgées » ;
    `media:*` annoncera « 0 média traité » ; les 16 tâches `media:*` de la chaîne médias
    deviennent des crons verts qui ne font rien ;
  - **insertions** → violation de `WITH CHECK`, donc échec bruyant
    (`RefreshAudienceChunkJob`, `ScraperRun::create` de `LaunchCampaignJob`).
  Le premier comportement est exactement celui que `config/crm.php` décrit
  comme inacceptable : « *Un cron vert qui ne purge rien est le pire des
  échecs — on exige donc un échec BRUYANT.* » L'exigence est écrite ; elle
  n'est pas tenue pour les automatismes.
- **Correctif proposé** : soit `WorkspaceContext::run($id, …)` dans chaque
  commande/job scopé, soit un `runWithoutScope('<justification>')` explicite
  quand la tâche est réellement transverse (`retention:prune-scraper-runs`,
  `anomaly:detect`, `coverage:refresh-matrix` le sont probablement). Le point
  n'est pas le choix, c'est qu'il soit **écrit**. Coût : 1 à 2 j pour les 33.
- **Statut** : ouvert.

---

### [P6-AUTO-007] Les purges RGPD par univers sont planifiées mais inertes ; la purge INSEE « non diffusible » n'est planifiée par rien

- **Sévérité** : **S1**. Je ne la place pas en S0 parce que je **ne peux pas
  mesurer** si les tables concernées contiennent des données en production
  (voir §4) : si le vivier est vide, l'échéance CNIL n'est pas encore due. Mais
  le dispositif est aujourd'hui à l'arrêt et rien n'annonce l'échéance.
- **Domaine** : conformité
- **Référence** : `fix/a35-authentification` @ `23a0e5f`
- **Emplacement** : `backend/routes/console.php:148-158`,
  `app/Console/Commands/RgpdPurgeVivier.php:36-40`,
  `app/Console/Commands/ProspectionPurgeNonDiffusible.php`
- **Constat** :
  1. `rgpd:purge-vivier` (doctrine CNIL CVthèque : 2 ans après la dernière
     interaction, refusés à J+90) et `rgpd:purge-business-prospects` sont
     planifiées mensuellement **avec un `skip()`** conditionné à
     `config('crm.purges_enabled')`, dont le défaut est `false`
     (`config/crm.php`, clé `purges_enabled` ← `CRM_PURGE_ENABLED`). La
     commande elle-même refuse également (double verrou, ligne 36-40). Donc
     **aucune purge du vivier n'a lieu**, et le consentement v2 promet
     « pendant 2 ans » (commentaire d'en-tête de `RgpdPurgeVivier.php`).
  2. `prospection:purge-non-diffusible` — suppression des fiches `[ND]`, les
     entreprises ayant demandé à l'INSEE que leurs données ne soient pas
     diffusées — n'apparaît dans **aucune** entrée du planificateur. C'est une
     obligation de diffusion, pas un confort ; elle repose entièrement sur un
     geste manuel.
- **Preuve** :

```
$ grep -n "purges_enabled\|purge-non-diffusible" routes/console.php config/crm.php
routes/console.php:152: ->skip(fn (): bool => ! filter_var(config('crm.purges_enabled', false), FILTER_VALIDATE_BOOLEAN));
routes/console.php:158: ->skip(fn (): bool => ! filter_var(config('crm.purges_enabled', false), FILTER_VALIDATE_BOOLEAN));
config/crm.php:    'purges_enabled' => env('CRM_PURGE_ENABLED', false),
   → aucune entree "purge-non-diffusible" dans routes/console.php
```

- **Témoin négatif** : le même `grep` d'un nom de commande dans
  `routes/console.php` trouve bien `prospection:score-email-confidence` et
  `retention:prune-scraper-runs` — il détecte donc la planification quand elle
  existe.
- **Impact** : les fiches candidats dépassant deux ans ne sont pas effacées ;
  les fiches `[ND]` restent en base sans échéance. Aucun automatisme n'alerte
  sur l'approche de ces échéances. À noter aussi : `rgpd:purge-vivier`
  supprimera, au premier run après activation, **tout** le stock éligible en une
  transaction, sans plafond ni `RefuseUneSuppressionMassive` — le jour de la
  bascule mérite un `--dry-run` préalable, qui, lui, est correctement
  implémenté (ligne 64-70).
- **Correctif proposé** : décision de bascule à porter par le dirigeant ; en
  attendant, planifier au minimum un **compteur d'échéance** (`--dry-run`
  mensuel dont la sortie est journalisée) pour que l'inaction soit visible.
  Coût : ~2 h.
- **Statut** : ouvert — reste Will pour la bascule.

---

### [P6-AUTO-008] L'anonymisation des IP et la vérification de la chaîne d'audit se contredisent par construction

- **Sévérité** : **S2** — défaut de conception, sans contournement simple. Il
  est aujourd'hui masqué par [P6-AUTO-001] : la tâche d'anonymisation échoue
  avant d'avoir pu casser quoi que ce soit. Réparer 001 sans traiter 008 rendra
  la chaîne d'audit rouge pour toujours.
- **Domaine** : conformité / backend
- **Référence** : `fix/a35-authentification` @ `23a0e5f`
- **Emplacement** : `app/Services/Audit/AuditHashChain.php:144-180` et `:192-207`,
  `app/Console/Commands/AnonymizeOldIps.php:25-29`,
  `routes/console.php:14 et :16`
- **Constat** : la colonne `ip` fait partie de la représentation canonique
  hachée (`canonical()`, ligne 200 : `'ip' => self::normalizeText($row['ip'] …)`).
  `rgpd:anonymize-ips` (04:30) réécrit `ip` sur toutes les lignes de plus de
  30 jours. `audit:verify-chain` (03:00) recalcule
  `sha256(prev_hash || canonical(row) || secret)` pour **chaque** ligne.
  Une IP tronquée = un `current_hash` qui ne correspond plus.
- **Preuve** :

```
$ grep -n "'ip' =>" app/Services/Audit/AuditHashChain.php
129:            'ip' => $row['ip'] ?? null,          (au moment d'ecrire)
200:            'ip' => self::normalizeText($row['ip'] ?? null),   (au moment de verifier)

$ grep -n "SET ip =" app/Console/Commands/AnonymizeOldIps.php
26:                UPDATE audit_logs
27:                SET ip = (host(network(ip::cidr / …)))::inet
```

- **Second défaut du même contrôle, indépendant** : `verifyChain()` repart
  **toujours** de `GENESIS_PREV_HASH` (ligne 164) et parcourt toute la table.
  Or `audit_logs` est censée perdre ses partitions de plus de 24 mois
  (cf. [P6-AUTO-003]). Le jour où une première partition est détachée, la plus
  ancienne ligne restante portera un `prev_hash` ≠ GENESIS et la vérification
  rendra `false` **définitivement**, en affichant « *possible falsification
  détectée* ». Un contrôle d'intégrité qui rougit pour toujours n'est plus lu.
- **Témoin négatif** : le même dépôt montre qu'il sait poser une borne quand il
  le veut — `verifyChain(?int $maxRows)` accepte une limite, et la commande
  expose `--max`. Le planificateur, lui, ne la passe pas
  (`routes/console.php:14` : `Schedule::command('audit:verify-chain')`). Mon
  contrôle voit donc la borne quand elle est là.
- **Impact** : (a) toute anonymisation d'IP invalide la chaîne d'audit sur
  l'intégralité de l'historique ; (b) même sans anonymisation, la vérification
  deviendra fausse dès la première rotation de partition ; (c) coût :
  `verify-chain` lit la table d'audit entière chaque nuit, sans borne.
- **Correctif proposé** : sortir `ip` de la représentation canonique (et
  décider que la chaîne protège l'événement, pas l'adresse), **ou** rendre
  l'anonymisation productrice d'un nouveau maillon signé. Et ancrer la
  vérification sur le premier maillon **encore présent** plutôt que sur GENESIS.
  Coût : ~1 j, décision d'architecture à prendre.
- **Statut** : ouvert.

---

### [P6-AUTO-009] Deux tâches planifiées sont des coquilles vides qui rendent `SUCCESS`

- **Sévérité** : **S2** — la surveillance de délivrabilité est annoncée par le
  planificateur et n'existe pas. Un contrôle absent est honnête ; un contrôle
  qui dit « tout va bien » sans rien mesurer ne l'est pas.
- **Domaine** : backend / conformité
- **Référence** : `fix/a35-authentification` @ `23a0e5f`
- **Emplacement** : `app/Console/Commands/BlacklistsCheck.php:18-27`
  (planifiée **toutes les heures**, `routes/console.php:13`),
  `app/Console/Commands/SignalsNightlyScan.php:18-27`
  (planifiée à 02:00, `routes/console.php:18`)
- **Constat** : les deux commandes n'ont que deux branches, et **les deux**
  rendent `self::SUCCESS` sans effectuer aucune opération. La branche « réelle »
  se contente d'un `$this->warn('Implémentation réelle prévue Sprint 8 …')`.
- **Preuve** : le corps intégral de `BlacklistsCheck::handle()` tient en 9
  lignes ; aucune requête DNSBL, aucune écriture, aucun appel réseau :

```
    public function handle(): int
    {
        if (env('MOCK_MODE', true)) {
            $this->info('MOCK_MODE — blacklists check skipped, all IPs assumed clean.');
            return self::SUCCESS;
        }
        $this->warn('Implémentation réelle prévue Sprint 8 — DNSBL queries Spamhaus + Barracuda + SORBS.');
        return self::SUCCESS;
    }
```

- **Témoin négatif** : le même contrôle appliqué à `anomaly:detect`
  (`AnomalyDetect.php:27-55`) montre une commande planifiée qui, elle, exécute
  bien deux requêtes réelles (`scraper_runs`, `llm_usage`) — mon contrôle
  distingue donc une coquille vide d'une commande active.
- **Impact** : le CRM envoie des emails ; la surveillance horaire du placement
  en liste noire des IP sortantes est annoncée dans le planificateur et
  n'existe pas. Le jour où une IP est listée, rien ne le dira. `anomaly:detect`
  souffre du même mal en plus discret : ligne 66, le seul canal de
  notification est en commentaire (`// Sprint 11 : send TelegramAlert…`) — les
  anomalies détectées sont écrites sur la sortie standard d'un conteneur que
  personne ne lit.
- **Correctif proposé** : implémenter, ou retirer du planificateur et ouvrir
  une tâche. Une entrée de planificateur qui ne fait rien est une dette qui se
  déguise en couverture. Coût : décision, puis 1 j pour les DNSBL.
- **Statut** : ouvert.

---

### [P6-AUTO-010] Le correctif `MOCK_MODE` du 19 août n'a pas été propagé : quatre fichiers du périmètre décident encore du mode simulacre avec `env()` au moment de l'exécution

- **Sévérité** : **S2**. Le même mécanisme a déjà produit un incident de
  production documenté dans `config/crm.php` (les courriels d'authentification
  jamais envoyés). Le correctif a créé `config('crm.mock_mode')` — et n'a
  déplacé **qu'un** appel.
- **Domaine** : backend
- **Référence** : `fix/a35-authentification` @ `23a0e5f`
- **Emplacement** : `app/Console/Commands/BlacklistsCheck.php:20`,
  `app/Console/Commands/SignalsNightlyScan.php:20`,
  `app/Jobs/LaunchCampaignJob.php:80`, `app/Jobs/LaunchZoneScrapingJob.php:191`
  (et, hors périmètre mais même famille : `MockServicesProvider.php:57-60`,
  `WaterfallOrchestrator.php:406`)
- **Constat** : ces quatre points lisent `env('MOCK_MODE', true)` ou
  `env('MOCK_SCRAPERS', true)` **au moment de l'exécution**, avec la valeur par
  défaut `true` — c'est-à-dire « simulacre ». Or le seul lecteur de
  `config('crm.mock_mode')` dans tout le dépôt est le couple
  `MagicLinkService` / `PasswordResetController` : le correctif s'est arrêté à
  l'authentification.
- **Preuve** :

```
$ grep -rn "crm.mock_mode" app/
app/Http/Controllers/Api/Auth/PasswordResetController.php:54
app/Services/Auth/MagicLinkService.php:61
   → 2 lecteurs seulement

$ grep -rn "env('MOCK_" app/Console/ app/Jobs/
app/Console/Commands/BlacklistsCheck.php:20:        if (env('MOCK_MODE', true)) {
app/Console/Commands/SignalsNightlyScan.php:20:        if (env('MOCK_MODE', true)) {
app/Jobs/LaunchCampaignJob.php:80:        $mockScrapers = (bool) env('MOCK_SCRAPERS', true);
app/Jobs/LaunchZoneScrapingJob.php:191:        if ((bool) env('MOCK_SCRAPERS', true)) {
```

  L'outil **a vu** ces appels — ils sont gelés dans la baseline PHPStan :

```
$ grep -n -B6 "BlacklistsCheck.php\|SignalsNightlyScan.php\|LaunchCampaignJob.php\|LaunchZoneScrapingJob.php" phpstan-baseline.neon | grep -E "identifier|path"
identifier: larastan.noEnvCallsOutsideOfConfig   path: app/Console/Commands/BlacklistsCheck.php
identifier: larastan.noEnvCallsOutsideOfConfig   path: app/Console/Commands/SignalsNightlyScan.php
identifier: larastan.noEnvCallsOutsideOfConfig   path: app/Jobs/LaunchCampaignJob.php
identifier: larastan.noEnvCallsOutsideOfConfig   path: app/Jobs/LaunchZoneScrapingJob.php

$ grep -c "message:" phpstan-baseline.neon        → 211 entrees gelees
$ grep -c "noEnvCallsOutsideOfConfig" phpstan-baseline.neon → 30+
```

- **Témoin négatif** : la règle larastan est bien **active** (`phpstan.neon`,
  niveau 8, `reportUnmatchedIgnoredErrors: true`) et la CI est bloquante
  (`.github/workflows/ci.yml`, en-tête : « aucune étape de qualité n'est
  neutralisée »). Le contrôle fonctionne donc ; c'est la baseline qui l'a
  muselé sur ces quatre lignes précises.
- **Impact** : `LaunchZoneScrapingJob:191` est le point exact où l'on décide
  d'appeler, ou non, les collecteurs Node en production. Si la valeur n'atteint
  pas `env()` au moment du job, la valeur par défaut `true` fait retourner un
  tableau vide **silencieusement** (`return [];`, ligne 195) et le run est
  compté `success`. Une campagne de collecte entière peut ainsi rendre zéro
  résultat sans qu'aucune erreur n'apparaisse.
- **Ce que je n'affirme pas** : je n'ai **pas** mesuré si, dans le conteneur de
  production, `env()` rend ou non la bonne valeur (voir §4). Le défaut que je
  constate est la **non-propagation du correctif**, qui est un fait de code ;
  pas l'état d'une variable en production, que je n'ai pas le droit d'aller lire.
- **Correctif proposé** : déplacer les quatre lectures vers
  `config('crm.mock_mode')` / une clé `crm.mock_scrapers`, et retirer les
  entrées correspondantes de la baseline dans le même commit. Coût : ~2 h.
- **Statut** : ouvert.

---

### [P6-AUTO-011] Aucune tâche planifiée ne porte de traitement d'échec

- **Sévérité** : **S2** — c'est le multiplicateur de tous les constats
  ci-dessus. [P6-AUTO-001] a pu échouer chaque nuit depuis la création de la
  commande sans que personne ne l'apprenne.
- **Domaine** : backend / tests
- **Référence** : `fix/a35-authentification` @ `23a0e5f`
- **Emplacement** : `backend/routes/console.php` (fichier entier, 170 lignes)
- **Constat** : aucune des 33 entrées ne porte `->onFailure()`,
  `->emailOutputOnFailure()`, `->pingOnFailure()`, `->sendOutputTo()` ni
  `->onSuccess()`. La seule sortie d'une tâche en échec est une trace dans le
  canal de journalisation du conteneur `scheduler`.
- **Preuve** :

```
$ grep -rn "onFailure\|emailOutputOnFailure\|pingOnFailure\|thenPing\|sendOutputTo" routes/console.php app/
(aucune ligne — 0 occurrence)

$ grep -n "scheduler" docker-compose.prod.yml
90:  scheduler:
   healthcheck:
     disable: true       ← aucun controle de sante non plus
```

- **Témoin négatif** : le même `grep`, élargi à `withoutOverlapping`, trouve
  **30** occurrences dans le même fichier — le contrôle lit donc bien les
  décorateurs de planification, il ne rate pas `onFailure` par erreur de motif :

```
$ grep -c "withoutOverlapping" routes/console.php   → 30
$ grep -c "onFailure\|emailOutputOnFailure\|pingOnFailure\|sendOutputTo" routes/console.php → 0
```
- **Impact** : `sentry/sentry-laravel` est bien présent dans `composer.json`
  (`^4.10`), donc une exception *peut* remonter — mais uniquement si le DSN est
  configuré, ce que je ne peux pas vérifier (§4). Une tâche qui rend un code de
  sortie non nul **sans lever d'exception** (`audit:verify-chain` rend
  `self::FAILURE`) ne produit, elle, strictement aucun signal.
- **Correctif proposé** : un `->onFailure(fn () => …)` commun (journal + canal
  d'alerte) posé sur toutes les entrées, et un contrôle de santé sur le
  conteneur `scheduler` fondé sur la date du dernier run réussi. Coût : ~4 h.
- **Statut** : ouvert.

---

### [P6-AUTO-012] 23 des 33 commandes planifiées n'ont aucun test

- **Sévérité** : **S2** — c'est la cause racine mesurable de [P6-AUTO-001] et
  [P6-AUTO-002] : les deux défauts les plus graves de ce rapport sont dans des
  commandes que rien n'exécute jamais en dehors de la production.
- **Domaine** : tests
- **Référence** : `fix/a35-authentification` @ `23a0e5f`
- **Preuve** — comparaison des noms planifiés et des noms joués dans `tests/` :

```
$ grep -ohE "Schedule::command\('[^' ]+" routes/console.php | sed "s/Schedule::command('//" | sort -u > /tmp/sched.txt
$ grep -rhoE "artisan\('[a-z0-9:_-]+'" tests/ | sed "s/artisan('//;s/'//" | sort -u > /tmp/tested.txt
$ wc -l < /tmp/sched.txt   → 33
$ comm -23 /tmp/sched.txt /tmp/tested.txt
anomaly:detect
audiences:full-refresh
audit:verify-chain
blacklists:check
campaigns:start-scheduled
companies:retry-google-places
coverage:refresh-matrix
media:backfill-periodicity
media:clean-emails
media:enrich
media:extract-from-companies
media:find-websites
media:import-arcom
media:import-blogs
media:import-opendatasoft
media:link-emissions-to-channels
media:score-confidence
media:sync-from-companies
prospection:score-email-confidence
retention:prune-scraper-runs
retention:purge
rgpd:anonymize-ips
signals:nightly-scan
$ comm -23 /tmp/sched.txt /tmp/tested.txt | wc -l   → 23
```

- **Témoin négatif** : le complément de la même comparaison rend les 10
  commandes réellement testées (`companies:rescrape-archives`,
  `crm:flush-outbound`, `journalists:scrape-ours`,
  `media:generate-redaction-emails`, `media:import-emissions-wikidata`,
  `media:link-to-companies`, `media:sync-emissions-from-parent`,
  `media:tag-emissions-status`, `rgpd:purge-vivier`,
  `rgpd:purge-business-prospects`). Le contrôle sait donc reconnaître un test
  quand il existe.
- **Impact** : les trois commandes de rétention/RGPD (`retention:purge`,
  `retention:prune-scraper-runs`, `rgpd:anonymize-ips`) sont **toutes les
  trois** dans la liste des non testées.
- **Statut** : ouvert.

---

### [P6-AUTO-013] La chaîne médias écrit dans `media` exclusivement par le constructeur de requêtes, sans jamais passer par le modèle

- **Sévérité** : **S3** — cohérence. Aucun symptôme aujourd'hui ; un piège
  posé pour la suite.
- **Domaine** : backend
- **Référence** : `fix/a35-authentification` @ `23a0e5f`
- **Emplacement** : `app/Models/Media.php:60-77` vs les 16 commandes `media:*` planifiées
- **Constat** : le modèle `Media` déclare `SoftDeletes`, des casts
  (`socials` → array, `enriched_at` → datetime) et un `$fillable` de 26
  colonnes. Deux colonnes réelles manquent au `$fillable` alors qu'elles
  figurent dans le bloc `@property` qui se présente comme « colonnes réelles » :
  `website_method` et `website_checked_at`. Par ailleurs `website_checked_at`
  est documentée `?Carbon` sans cast correspondant.
  Aujourd'hui c'est sans effet parce que **toutes** les écritures des
  automatismes passent par `DB::table('media')`, qui ignore `$fillable`, les
  casts et `SoftDeletes`.
- **Preuve** :

```
$ grep -n "website_method\|website_checked_at" app/Models/Media.php
38: * @property ?string $website_method
39: * @property ?Carbon $website_checked_at
   → absentes de $fillable (l. 66-72)

$ grep -rn "website_method" app/Console/Commands/ | head -3
app/Console/Commands/ImportMediaBlogs.php:90:     'website_method' => 'curated',      (via DB::table()->insert)
app/Console/Commands/MediaFindWebsites.php:106:  website_method = v.method,          (SQL brut)
app/Console/Commands/MediaSyncFromCompanies.php:34: website_method = COALESCE(...)    (SQL brut)
```

- **Témoin négatif** : le contrôle **trouve** bien les colonnes présentes dans
  `$fillable` (`email_confidence`, ajoutée par la migration
  `2026_07_14_000003`, y figure) — il ne signale donc pas toute colonne par
  défaut.
- **Impact** : le jour où une de ces commandes passera à Eloquent, les deux
  colonnes seront **silencieusement ignorées** à l'écriture (protection de
  masse), sans erreur. Effet immédiat, plus concret : `MediaCleanEmails`
  n'exclut pas `deleted_at` de son `UPDATE` (voir [P6-AUTO-004]) — un oubli
  qui ne serait pas possible via le modèle.
- **Correctif proposé** : compléter `$fillable` et les casts, et énoncer la
  règle (« la chaîne médias écrit en SQL ensembliste, par choix de volume »)
  dans l'en-tête du modèle. Coût : ~1 h.
- **Statut** : ouvert.

---

### [P6-AUTO-014] Un garde-fou du planificateur est devenu trompeur : la commande qu'il protège existe désormais

- **Sévérité** : **S3** — finition.
- **Emplacement** : `backend/routes/console.php:33-42` et `:44-51`
- **Constat** : le commentaire annonce « *la commande `companies:rescrape-archives`
  est codée dans le Sprint Hardening (H6). En attendant, le schedule est posé
  mais s'auto-skip si la commande n'existe pas* ». Or
  `app/Console/Commands/RescrapeArchivesCommand.php` existe et déclare bien
  `companies:rescrape-archives`. Le `skip()` ne saute donc plus rien, et le
  commentaire décrit un état révolu.
- **Preuve** :

```
$ grep -n "signature" app/Console/Commands/RescrapeArchivesCommand.php
23:    protected $signature = 'companies:rescrape-archives
$ grep -n "signature" app/Console/Commands/RetryGooglePlacesCommand.php
25:    protected $signature = 'companies:retry-google-places
   → les deux commandes protegees par un skip() « si elle n'existe pas » existent
```

- **Impact** : un lecteur du planificateur croit ces deux tâches inertes. Elles
  s'exécutent réellement, le 1er de chaque mois à 02:00 et 03:00.
- **Statut** : ouvert.

---

## 3. TÉMOINS NÉGATIFS — les contrôles que j'ai joués et qui n'ont RIEN trouvé

Un « rien trouvé » ne vaut que si le contrôle aurait vu le problème. Pour
chacun, je donne la preuve que le contrôle discrimine.

### 3.1 Aucune tâche planifiée ne pointe vers une commande inexistante

```
$ grep -ohE "Schedule::command\('[^' ]+" routes/console.php | sed "s/Schedule::command('//" | sort -u > /tmp/s.txt
$ grep -rhoE "\$signature = '[a-z0-9:_-]+" app/Console/Commands/ | sed "s/.*= '//" | sort -u > /tmp/c.txt
$ comm -23 /tmp/s.txt /tmp/c.txt
(aucune)
```

**Témoin** — j'injecte un nom bidon dans la liste planifiée et rejoue le même
contrôle :

```
$ (cat /tmp/s.txt; echo "media:commande-qui-nexiste-pas") | sort -u > /tmp/s2.txt
$ comm -23 /tmp/s2.txt /tmp/c.txt
media:commande-qui-nexiste-pas
```

Le contrôle **aurait** vu une tâche orpheline. Il n'y en a pas.

### 3.2 Un seul `--dry-run` écrit — les autres sont corrects

J'ai relu la branche « essai à blanc » de chaque commande destructive ou
mutante qui en propose une : `rgpd:anonymize-ips` (ternaire, jamais l'UPDATE),
`rgpd:purge-vivier` (l. 64-70, retour anticipé), `rgpd:purge-business-prospects`
(l. 72), `media:link-to-companies` (l. 46), `media:sync-emissions-from-parent`
(l. 37), `media:link-emissions-to-channels` (l. 49 et 98),
`media:tag-emissions-status` (l. 58/112/142), `media:import-blogs` (l. 72),
`media:import-emissions-wikidata` (l. 88/284), `media:import-press-kit`
(l. 113), `crm:flush-outbound` (l. 92), `prospection:purge-non-diffusible` et
`prospection:purge-non-commercial` (via `RefuseUneSuppressionMassive:53-57`).
**Toutes** sortent avant l'écriture. Seul `retention:purge` ne le fait pas
([P6-AUTO-002]) — et le témoin du § P6-AUTO-002 montre que mon contrôle sait
distinguer les deux cas dans le *même* fichier.

### 3.3 Aucune migration ne lance `CREATE INDEX CONCURRENTLY` dans une transaction

```
$ grep -rln "CONCURRENTLY" database/migrations/     → 10 fichiers
$ grep -rn  "withinTransaction" database/migrations/ →  8 fichiers avec `public $withinTransaction = false;`
```

**Témoin** — les 2 fichiers en écart ont été ouverts un par un ; dans les deux,
`CONCURRENTLY` n'apparaît **que** dans un commentaire :

```
$ grep -n "CONCURRENTLY" database/migrations/2026_08_14_000002_crm_socle_taxonomie_business.php
39: * - Les index sont créés dans une migration SÉPARÉE, en CONCURRENTLY (cf.
$ grep -n "CONCURRENTLY" database/migrations/2026_08_15_120001_companies_entites_sans_siren.php
35: * - L'index unique partiel est créé CONCURRENTLY dans la migration suivante
```

Le contrôle a produit un écart, l'écart a été levé par lecture. Aucun défaut.

### 3.4 Les 59 migrations déclarent toutes une méthode `down()`

```
$ for f in database/migrations/*.php; do grep -q "function down" "$f" || echo "PAS DE down(): $f"; done
(aucune sortie)
```

**Témoin** — le même contrôle sur un fichier connu pour ne pas en avoir :

```
down() ABSENT  : app/Console/Commands/RetentionPurge.php
down() PRESENT : database/migrations/2026_05_18_000005_add_updated_at_error_message_to_scraper_runs.php
```

Nuance honnête : `down()` **existe** partout, mais rien ne l'exécute jamais
(aucun `migrate:rollback` en CI ni en déploiement) — sa justesse n'est donc pas
vérifiée. Plusieurs `down()` sont d'ailleurs des `// No-op` assumés.

### 3.5 Les tables visées par les tâches de rétention existent bien

`retention:purge` cite `email_validations`, `notifications`, `scraper_runs`.
Les trois sont créées par des migrations du dépôt
(`2026_05_16_000006` pour les deux premières, `2026_05_16_000003` pour la
troisième). Le SQL de la commande ne peut donc pas échouer pour cause de table
manquante — le défaut [P6-AUTO-002] est bien un défaut de réécriture, pas de
schéma.

**Témoin** — le même contrôle sur `error_message`, colonne annoncée par le
**nom** d'une migration (`2026_05_18_000005_add_updated_at_error_message_to_scraper_runs`),
montre qu'elle n'est **jamais** ajoutée par le corps du fichier ni lue par
`ScraperRun` (`$fillable` porte `error`, pas `error_message`). Le contrôle sait
donc trouver un écart nom/schéma quand il y en a un ; ici l'écart est purement
cosmétique (le nom du fichier ment, le code est cohérent).

### 3.6 `audit_logs_old` n'est pas une table résiduelle

La migration de partitionnement copie `audit_logs` dans `audit_logs_old` avant
de recréer la table. J'ai vérifié qu'elle la supprime bien :

```
$ grep -rn "audit_logs_old" database/migrations/
…:80:  CREATE TABLE audit_logs_old AS TABLE audit_logs;
…:176: INSERT INTO audit_logs SELECT * FROM audit_logs_old;
…:185: DROP TABLE audit_logs_old;
```

Le `DROP` est dans le même bloc `DO $$ … END$$`, donc dans la même transaction
implicite que la copie. Pas de copie du journal d'audit laissée en base.

### 3.7 Le job de suivi de campagne n'est pas une boucle infinie

`MonitorCampaignProgressJob` se re-planifie toutes les 60 s. J'ai cherché la
borne : elle existe, et elle est en temps de mur, pas en compteur de runs —
`ScrapingCampaign::shouldAutoPause()` (l. 186) compare
`elapsed_minutes` (dérivé de `started_at`, `getElapsedMinutesAttribute`, l. 131)
à `max_duration_minutes`. Une campagne dont aucun run ne démarre s'arrête donc
quand même. Pas de défaut.

### 3.8 La reconstructibilité de la base est réellement exercée

`.github/workflows/ci.yml` démarre une image PostgreSQL 16 munie de PostGIS,
pgvector et pg_partman, puis joue Pest ; 86 fichiers de tests utilisent
`RefreshDatabase`, ce qui rejoue les 59 migrations à partir du vide. L'en-tête
du workflow atteste que plus aucune étape n'est neutralisée (« aucune étape de
qualité n'est neutralisée »), et le job `deploy` a `needs: [ci]`. **Une
migration inatteignable ferait donc rougir la CI.** Je n'ai pas de constat de
non-reconstructibilité à porter, et j'explique pourquoi : le contrôle existe et
il est bloquant.

### 3.9 Les tables créées après la migration de durcissement portent bien la policy stricte

Le durcissement (`2026_08_14_000001`) découvre dynamiquement les tables à
`workspace_id` — mais il ne peut voir que celles qui existent au moment où il
tourne. J'ai donc vérifié chaque migration **postérieure** :
`candidates` / `candidate_tag` (`2026_08_14_000003:210-216`) posent bien
`ENABLE` + `FORCE` + policy stricte elles-mêmes ; `scraping_sources`,
`crm_outbound_events`, `crm_activites`, `crm_motifs`, `email_suppressions` sont
des tables **globales sans `workspace_id`**, ce que leurs en-têtes énoncent et
que le `grep` confirme. Pas de trou.

**Témoin** — le contrôle **trouve** l'ancienne policy permissive
(`workspace_id IS NULL OR NULLIF(current_setting(…),'') IS NULL OR …`) partout
où elle subsiste (`2026_05_18_000001:75-86`, `2026_05_18_000008:47-58`, et le
`down()` du durcissement) : il sait reconnaître les deux formes de policy et ne
confond pas « stricte » avec « permissive ».

---

## 4. CE QUE JE N'AI PAS PU MESURER, ET POURQUOI

Une couverture bornée qui se tait passe pour une couverture complète. Voici
mes bornes.

1. **Aucun état de production.** Interdiction explicite de toucher l'hôte
   Hetzner. Je n'ai donc mesuré **aucune** ligne réelle. Conséquences directes :
   - je ne sais pas si `candidates` contient des fiches, donc je ne sais pas si
     l'échéance CNIL de deux ans est déjà due ([P6-AUTO-007]) — c'est pourquoi
     je l'ai classée S1 et non S0 ;
   - je ne sais pas combien de lignes `retention:purge --dry-run` détruirait
     ([P6-AUTO-002]) ; le défaut est certain, son volume ne l'est pas ;
   - je ne sais pas si `partman.create_parent` a réussi ou s'il est retombé sur
     la partition `DEFAULT` (la migration avale l'échec en `RAISE WARNING`) ;
   - je ne sais pas quel est l'espace de travail le plus ancien, donc où
     atterrissent réellement les imports ([P6-AUTO-005]).
2. **Aucune exécution de la suite de tests.** Interdiction explicite sur le
   conteneur `a35r`, où un autre agent mesure. Je n'ai donc **pas** vu un test
   rouge puis vert ; tous mes constats sont statiques ou reposent sur des
   contrôles joués hors du projet (PHP en ligne de commande, PostgreSQL sur un
   conteneur tiers).
3. **`env()` en production.** [P6-AUTO-010] : je constate la non-propagation du
   correctif, un fait de code. Je **n'affirme pas** que `MOCK_SCRAPERS` est mal
   lu en production : cela dépend de `variables_order` du PHP du conteneur, du
   fait que `config:cache` a réellement tourné, et de la façon dont Docker
   injecte `env_file`. Ces trois éléments se mesurent **dans** le conteneur de
   production, ce que je n'ai pas le droit de faire.
4. **Sentry.** `sentry/sentry-laravel` est dans `composer.json`. Je n'ai pas pu
   vérifier que le DSN est posé, ni qu'une exception de tâche planifiée y
   remonte effectivement. [P6-AUTO-011] tient sans cela (une commande qui rend
   un code de sortie non nul sans exception ne remonte nulle part), mais
   l'ampleur du silence dépend de ce point.
5. **Le SQL des tâches n'a pas été exécuté sur le vrai schéma.** J'ai pu prouver
   qu'un opérateur PostgreSQL n'existe pas ([P6-AUTO-001]) parce que c'est une
   propriété du moteur, indépendante des données. Je **n'ai pas** rejoué les
   requêtes ensemblistes de `media:link-to-companies`,
   `media:extract-from-companies`, `media:sync-*` sur un schéma complet : je les
   ai lues. Un défaut de nom de colonne ou de jointure dans ces requêtes
   m'aurait échappé.
6. **`down()` non exercé.** § 3.4 : je constate leur présence, pas leur
   justesse. Aucun `migrate:rollback` n'est joué nulle part dans ce dépôt.
7. **Concurrence et volume.** `withoutOverlapping()` apparaît 30 fois,
   mais son verrou repose sur le cache : je n'ai pas vérifié quel magasin de
   cache tourne en production, ni ce qui se passe si deux conteneurs
   `scheduler` démarrent (les entrées `onOneServer()` supposent un cache
   partagé). Deux tâches sont planifiées à la même minute
   (`retention:purge` et `audiences:full-refresh`, toutes deux à 04:00) sans
   que j'aie pu mesurer leur interaction.
8. **Les 6 jobs de file** : j'ai lu leur code, mais je n'ai pas pu observer un
   worker Horizon réel, donc je n'ai pas mesuré les reprises, les délais, ni le
   comportement de `SerializesModels` sous charge.

---

## 5. RÉCAPITULATIF

| Id | Sévérité | Titre |
|---|---|---|
| P6-AUTO-001 | **S0** | `rgpd:anonymize-ips` : SQL invalide (`cidr / integer`), l'anonymisation RGPD n'a jamais tourné |
| P6-AUTO-002 | **S0** | `retention:purge --dry-run` exécute réellement l'`UPDATE` destructif |
| P6-AUTO-003 | **S0** | Rétention `llm_usage` (12 mois) inexistante ; rétention `audit_logs` (24 mois) sans exécutant |
| P6-AUTO-004 | S1 | `media:clean-emails` : destruction quotidienne sans essai à blanc, plafond, confirmation ni journal |
| P6-AUTO-005 | S1 | 10 commandes (dont 6 planifiées) choisissent leur workspace par « le plus ancien créé » |
| P6-AUTO-006 | S1 | Zéro contexte d'espace de travail dans les 33 tâches ; ceinture posée sur 4 modèles sur 16 |
| P6-AUTO-007 | S1 | Purges RGPD par univers inertes ; purge INSEE `[ND]` non planifiée |
| P6-AUTO-008 | S2 | Anonymisation des IP et vérification de la chaîne d'audit se contredisent |
| P6-AUTO-009 | S2 | `blacklists:check` et `signals:nightly-scan` : coquilles vides qui rendent `SUCCESS` |
| P6-AUTO-010 | S2 | Correctif `MOCK_MODE` non propagé à 4 fichiers ; signalements gelés en baseline |
| P6-AUTO-011 | S2 | Aucune tâche planifiée n'a de traitement d'échec (0 occurrence) |
| P6-AUTO-012 | S2 | 23 des 33 commandes planifiées n'ont aucun test |
| P6-AUTO-013 | S3 | La chaîne médias écrit hors modèle ; `$fillable` incomplet |
| P6-AUTO-014 | S3 | Garde-fou de planification devenu trompeur (commandes désormais existantes) |

Aucun fichier autre que celui-ci n'a été créé ou modifié. Aucun correctif n'a
été appliqué : cette passe constate.
