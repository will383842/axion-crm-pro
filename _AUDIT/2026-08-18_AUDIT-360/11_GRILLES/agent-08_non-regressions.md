# AGENT 8 — Auditeur des non-régressions

> Périmètre : la liste « **Ce qui a été réparé récemment et ne doit pas régresser** » du **§A.1**
> du cahier des charges v2 (`axion-ia-crm-cahier-des-charges-fonctionnel-v2.md`, ligne 58).
> Consigne : ces points sont **REJOUÉS**, pas relus.

---

## 0. Référence — relue, pas recopiée

Le dossier commun annonce `main = c0c453d`. **C'est faux au moment de cet audit.** Relu moi-même :

```
$ git log --oneline -1
1145473 docs(rgpd): registre des violations, notification non retenue (#188)
$ git worktree list
C:/Users/willi/Documents/Projets/Axion-CRM-Pro      1145473 [main]
C:/Users/willi/Documents/Projets/crmpro-wt-etape0   702253c [chore/etape-0-prealables]
C:/Users/willi/Documents/Projets/crmpro-wt-etape1a  1145473 [travail]   ⛔ non touché
```

**Toute cette grille est mesurée sur `main = 1145473`.** Deux commits avaient déjà atterri après
`c0c453d` (#187, #188) avant que je commence.

⚠️ **`main` a continué d'avancer pendant que je mesurais.** Relu en fin d'exercice :

```
$ git log --oneline -1 origin/main
e8924b8 fix(rgpd+acces): rectification du registre + acces CRM rendu (#189)
```

`#189` a été fusionnée le 2026-08-19 à 10:07 UTC, au milieu de mes mesures. Le dépôt n'est donc pas
figé pendant cet audit : **tout SHA cité par un agent doit être daté**, et le mien l'est. Aucun de
mes constats ne porte sur du code touché par `#189` (registre RGPD et accès), mais un agent qui
relira `1145473` ne verra pas le même arbre que celui qui relira `HEAD`.

Autres références mesurées :

| Objet | Valeur relevée | Commande |
|---|---|---|
| Dépôt site | `eb754332` | `git log -1` dans `Axion-IA/axionia` |
| Production API | `https://api.axion-crm-pro.com` (lecture seule + 2 sondes 401) | — |
| Serveur | `root@46.62.248.239` (`axion-crm-edge`) | `ssh` |
| Base restaurée | `axion_crm_dr_a08` sur `axion-crm-postgres` **local** | jetable, supprimée en fin d'exercice |

**Aucune écriture en production.** Les seules traces laissées : trois lignes de journal
(`site-sync rejeté (signature invalide)` × 2, un 405) et deux scripts de lecture déposés dans
`/tmp` puis **supprimés et vérifiés absents** (preuve `21_prod-chronologie.txt`).

---

## 1. La liste réelle du §A.1 — ce que le texte dit vraiment

Le prompt d'audit résume la liste en cinq mots-clés. Le §A.1 (ligne 58 du cahier des charges) écrit :

> **« Ce qui a été réparé récemment et ne doit pas régresser »** : sauvegardes de production
> restaurables (exercice du 16 août, zéro erreur, surveillance quotidienne) ; décalage horaire des
> dates du site corrigé ; intégration continue réelle (avant le 13 août, tout passait en `|| true`) ;
> isolation par espace durcie ; formulaire du site réparé. **Chacun de ces points est un test qui
> doit rester vert.**

Ce sont donc **cinq points**, et non quatre ni six. Le résumé du prompt (« horodatage UTC ») est
**inexact** : le correctif retenu par le produit n'est pas « tout en UTC » mais
`DB_TIMEZONE = APP_TIMEZONE = Europe/Paris`, la session Postgres alignée sur le fuseau applicatif.
J'ai mesuré le **comportement** (l'instant survit-il à l'aller-retour), pas le mot.

---

## 2. Grille — une ligne par point, une colonne par exigence

### Point 1 — Sauvegardes de production restaurables

| Colonne | Contenu |
|---|---|
| **Énoncé §A.1** | « sauvegardes de production restaurables (exercice du 16 août, zéro erreur, surveillance quotidienne) » |
| **Comment REJOUÉ** | ① `verifier-sauvegarde.sh` exécuté sur le serveur de production (contrôle hors-site réel, SFTP Storage Box). ② **Restauration réelle** : l'archive de production `axion_crm_20260819T030001Z.sql.gz` (691 Mo) streamée par SSH → `zcat` → `psql` dans une base **jetable locale** `axion_crm_dr_a08`, puis comptage des cinq tables de référence de `dr-drill.sh` et comparaison aux comptages de production relevés le même jour. ③ Historique des 8 dernières exécutions du workflow de surveillance. |
| **Sortie brute** | `04_PREUVES/agent-08/01_verifier-sauvegarde.txt`, `02_prod-reference.txt`, `03_restauration.log`, `03_restauration-meta.txt`, `26_comptages-restauration.txt`, `27_schema-restaure.txt`, `28_ordre-rls-dans-le-dump.txt` |
| **Témoin négatif** | ① `SEUIL_AGE_H=0` → **rouge, code 1** ; `SEUIL_TAILLE_MO=999999` → **rouge, code 1**. La garde rougit dans les deux sens, sur l'objet qui casse. ② Les mêmes comptages joués **pendant** la restauration rendaient `companies=0 / contacts=0 / …` (`22_temoin-negatif-restauration-partielle.txt`) : le contrôle sait distinguer une base vide d'une base restaurée — c'est exactement le symptôme du 2026-08-16 qu'il doit attraper. ③ Le bit d'exécution — la cause des 91 échecs — est bien à `100755` **dans git** pour les quatre scripts de la chaîne, et la lecture qui le vérifie n'est pas constante : elle rend `100644` pour `README.md` et `install-terraform-windows.ps1` du même dossier (`29_bit-execution-git.txt`). |
| **Chaîne complète** | Les cinq pièces exigées par `ACQUIS 2 (suite)` existent et sont exécutables : `backup-postgres.sh`, `restore-postgres.sh`, `verifier-sauvegarde.sh`, `dr-drill.sh` (tous `100755`) et `surveillance-sauvegarde.yml`. La surveillance quotidienne **tourne réellement** : 3 exécutions planifiées consécutives vertes (2026-08-17 05:32, 08-18 05:25, 08-19 05:26 UTC), et l'historique porte **3 échecs délibérés** le 2026-08-17 vers 02:10 — la trace des essais qui ont prouvé que la garde savait rougir avant d'être mise en service. ⚠️ Mais le job `scripts-executables` **ne bloque aucune fusion** (A08-005). |
| **Verdict** | ✅ **TENU** — voir §3 pour les nombres exacts. |
| **Réserve écrite** | La restauration a été faite depuis la **copie locale du serveur**, pas depuis la copie **hors-site**. L'identité des deux (`sha256sum`) que vérifie `dr-drill.sh` **n'a pas été rejouée** : la rapatrier exige d'écrire un fichier de 691 Mo dans `/tmp` **sur la production**, ce que la consigne interdit. Ce qui a été vérifié du hors-site : **présence, âge (6 h) et taille (691 Mo, 724 926 343 octets)**, par `verifier-sauvegarde.sh`. Pour compléter : lancer `./infra/scripts/dr-drill.sh` depuis un poste autorisé à écrire dans `/tmp` du serveur. |

### Point 2 — Décalage horaire des dates du site corrigé

| Colonne | Contenu |
|---|---|
| **Énoncé §A.1** | « décalage horaire des dates du site corrigé » |
| **Comment REJOUÉ** | ① Mesure sur **données réelles de production** : les 3 `form_submission` réellement arrivées par le canal du site, écart `created_at − occurred_at`, et écart `occurred_at − payload->pending_match->>'consent_at'`. ② Inventaire des colonnes `timestamp without time zone` en production. ③ Relevé du fuseau de session Postgres et des variables `APP_TIMEZONE` / `DB_TIMEZONE` du conteneur `api` de production. ④ Le verrou `HorodatagesFuseauTest` + `NeDoitPasRegresserTest §ACQUIS 3` **vus s'exécuter** dans le journal du run CI 32238995760 (non `skipped`). |
| **Sortie brute** | `05_prod-colonnes-timestamp.txt`, `06_prod-canal-site.txt`, `07_prod-horodatage-reel.txt`, `09_prod-consent-at.txt`, `04_prod-env-isolation-tz.txt` |
| **Mesure décisive** | Les 3 événements réels : `delta_s(created_at − occurred_at)` = **1, 0, 1 seconde**. `delta_s(occurred_at − consent_at)` = **0, 0, 0**. Un décalage de 2 h vaudrait ±7200. |
| **Témoin négatif** | Le contrôle sait montrer un écart : sur la même colonne, l'écart `created_at − occurred_at` de l'événement 1203 vaut 1 s et non 0 — la requête discrimine bien à la seconde. Et le verrou `ACQUIS 3 — le réglage qui rend le verrou capable de rougir est toujours en place` vérifie que `DB_TIMEZONE` figure dans **les deux** fichiers `phpunit*.xml` : retirer le réglage d'un seul désarmerait `HorodatagesFuseauTest` — la garde garde sa propre capacité à rougir. |
| **Verdict** | ✅ **TENU**. 164 colonnes `timestamptz` contre **1** `timestamp` (`failed_jobs.failed_at`, table Laravel héritée, hors périmètre métier). |

### Point 3 — Intégration continue réelle

| Colonne | Contenu |
|---|---|
| **Énoncé §A.1** | « intégration continue réelle (avant le 13 août, tout passait en `|| true`) » |
| **Comment REJOUÉ** | ① Journal complet du dernier run CI **téléchargé et lu** (`gh api …/logs`), pas le YAML. ② Chronométrage étape par étape via l'API GitHub. ③ Recherche de `\|\| true` et `continue-on-error` dans les 16 workflows. ④ **Confrontation des jobs réellement exécutés aux contextes exigés par la protection de branche.** |
| **Sortie brute** | `ci-logs/` (6 journaux de jobs), `23_ci-gates-vs-protection.txt` |
| **Mesure décisive** | Pest **s'exécute pour de vrai** : `Tests: 780 passed (6503 assertions) — Duration: 36.72s`. PHPStan 16 s. **Aucun test `skipped`** dans le run. Les 9 tests `NeDoitPasRegresserTest` (`ACQUIS 1` à `4`) sont **tous verts et tous exécutés**, `ACQUIS 2` (dump + restauration réelle) inclus, en 2,24 s. Les seuls `\|\| true` subsistants sont `composer audit` / `pnpm audit` (décision écrite en tête de `ci.yml`) et deux usages défensifs de `grep`. |
| **Témoin négatif** | Le run **32229447530** du 2026-08-19 07:47 est **rouge** (`feat/preproduction`), suivi d'un vert après correctif : la CI sait rougir sur ce dépôt, ce n'est pas un vert de complaisance. |
| **Verdict** | ⚠️ **TENU sur l'exécution, DÉFAILLANT sur le câblage** → **A08-005**. La CI n'est plus décorative : elle exécute. Mais **3 de ses 6 jobs ne bloquent aucune fusion**. |

### Point 4 — Isolation par espace durcie

| Colonne | Contenu |
|---|---|
| **Énoncé §A.1** | « isolation par espace durcie » |
| **Comment REJOUÉ** | ① **Qui est réellement connecté** à la base de production (`pg_stat_activity`), pas ce que dit un fichier. ② État RLS (`relrowsecurity` / `relforcerowsecurity`) de **toutes** les relations portant `workspace_id`, en production **et** en local. ③ Confrontation de l'inventaire calculé par la garde (`EtancheiteWorkspace::inventaireBrut`) au schéma **réel de production**. ④ Lecture du journal applicatif de production sur 3 jours : ce que l'armement a cassé. |
| **Sortie brute** | `04_prod-env-isolation-tz.txt`, `10_prod-isolation.txt`, `11_relkind-prod-vs-local.txt`, `12_prod-audit-logs-partitions.txt`, `17_prod-logs-rls.txt`, `18_prod-erreurs-rls.txt`, `19_prod-matview-cassee.txt`, `20_prod-erreurs-toutes.txt`, `21_prod-chronologie.txt` |
| **Mesure décisive** | La barrière est **armée en production** — et c'est une bonne nouvelle que la documentation du dépôt ignore : `CRM_DB_APP_ROLE_ENABLED=true`, et `pg_stat_activity` montre **5 sessions ouvertes sous `axion_app`** (`rolsuper=false`, `rolbypassrls=false`). **38 des 41** tables à `workspace_id` ont RLS **activée ET forcée**. Les 3 restantes (`sessions`, `user_workspaces`, `audit_logs`) sont exclues avec des motifs écrits — **mais le motif d'`audit_logs` est faux en production** (A08-004). |
| **Témoin négatif** | La requête d'inventaire sait montrer les deux états : elle rend `t/t` pour 38 relations et `f/f` pour 3. Elle n'est pas aveugle. Et jouée sur le **local**, la même requête rend un résultat **différent** (`audit_logs` = `relkind p`) — c'est ce qui a révélé la divergence. |
| **Verdict** | ⚠️ **NON RÉGRESSÉ sur la barrière, RÉGRESSÉ sur ce qui tourne derrière** → **A08-001**, **A08-002**, **A08-003**, **A08-004**. La barrière est plus étanche qu'avant. Son armement a cassé **71 exécutions sur 71** d'une tâche planifiée et **1 import nocturne**, sans que rien ne le voie pendant 71 heures. |

### Point 5 — Formulaire du site réparé

| Colonne | Contenu |
|---|---|
| **Énoncé §A.1** | « formulaire du site réparé » |
| **Objet identifié** | `0baf0783` — `fix(contact): le formulaire refusait de partir sans rien dire (#707)`, 2026-08-17, dépôt `Axion-IA/axionia`. Cause : `unified-contact-schema.ts` refusait les menus déroulants laissés vides du bloc « Aller plus loin », sans message. |
| **Comment REJOUÉ** | ① Le correctif est-il toujours dans `main` du site : `git merge-base --is-ancestor 0baf0783 HEAD` → **oui**. ② **Le test du correctif rejoué** : `npx vitest run src/lib/schemas/__tests__/unified-contact-schema.test.ts`. ③ La porte d'entrée du CRM est-elle vivante en production : sondes HTTP non destructives. ④ **La preuve la plus forte : le canal a livré un événement réel** — un envoi de formulaire réellement arrivé le **2026-08-19 à 03:25:47 UTC**, ingéré 1 s plus tard. |
| **Sortie brute** | `15_test-formulaire-site.txt`, `16_prod-endpoint-site-sync.txt`, `06_prod-canal-site.txt`, `08_prod-canal-detail.txt` |
| **Mesure décisive** | `Test Files 1 passed (1) — Tests 5 passed (5)`. Production : `POST /api/internal/site-sync` sans signature → **401**, signature bidon → **401**, `GET` → **405** (donc la route existe) contre **404** sur une route inexistante. Et 3 `form_submission` réellement ingérées (2026-08-16, 08-18, 08-19). **Deux de ces trois ingestions (08-18 et 08-19) sont postérieures à l'armement du rôle applicatif du 2026-08-16 14:00** : le canal d'entrée écrit donc bien en base sous `axion_app`, RLS active. C'est la contre-épreuve de A08-001/A08-002 — le durcissement n'a pas cassé la porte d'entrée, seulement des tâches planifiées qui écrivaient sans contexte. |
| **Témoin négatif** | ① La sonde sait distinguer une route vivante d'une route absente : **405 vs 404** sur le même hôte. ② La garde HMAC sait refuser : **401** sur deux formes d'échec distinctes. ③ Le journal CI montre les refus de contrat réellement exercés par les tests : `signature invalide`, `horodatage hors fenêtre`, `unknown_field`, `unknown_event_type`, `invalid_person_key`, `invalid_siren`, `ungoverned_tag_namespace`. |
| **Verdict** | ✅ **TENU**. |
| **Réserve écrite** | Je n'ai **pas** soumis le formulaire sur le site en production (ce serait une écriture). Le point faible du canal n'est pas le formulaire mais ce qu'il devient : les **3** événements ingérés sont tous restés en `pending_match`, `contact_id` NULL, et **0 des 1 319 567 contacts de production ne porte de `person_key` ni de `consent_at`**. C'est le comportement prévu (pas de SIREN ⇒ file d'arbitrage), mais il signifie que l'acquis n°4 du test « export RGPD par empreinte » n'a, en production, **aucune donnée sur laquelle mordre**. Signalé pour l'agent RGPD, non compté comme régression. |

---

## 3. La restauration réelle — les nombres

**Une sauvegarde de production a bien été restaurée pour de vrai**, sur une base jetable locale.

- Archive : `axion_crm_20260819T030001Z.sql.gz`, **724 926 343 octets** (691 Mo), produite par le cron
  de 03:00 UTC, âgée de 6 h au moment du contrôle.
- Chemin : `ssh root@46.62.248.239 "cat …"` → `zcat` → `docker exec -i axion-crm-postgres psql -U axion -d axion_crm_dr_a08`
- Conteneur cible : image `ghcr.io/will383842/axion-crm-pro-postgres:16-3.5-vector-partman`,
  **PostgreSQL 16.9 — identique à la production** (vérifié par `select version()` des deux côtés).

Comptages **exacts** (`count(*)`, jamais l'estimation `pg_stat`), preuves
`26a_comptages-partiels.txt` (10:33:41Z) et `26b_scraper-runs.txt` (11:16:25Z) :

| Table | Production (lecture seule, 2026-08-19T09:48:50Z) | Restaurée localement | Verdict |
|---|---|---|---|
| `companies` | 4 295 349 | **4 295 349** | ✅ identique |
| `contacts` | 1 319 567 | **1 319 567** | ✅ identique |
| `company_tag` | 7 501 969 | **7 501 969** | ✅ identique |
| `journalists` | 1 257 | **1 257** | ✅ identique |
| `scraper_runs` | 7 608 196 | **7 608 196** | ✅ identique |
| **Total** | **20 726 338** | **20 726 338** | ✅ **écart nul** |

**Réponse à la question posée : oui — une sauvegarde de production a été réellement restaurée, et
les cinq tables de référence de `dr-drill.sh` sont revenues au nombre exact : 20 726 338 lignes,
écart nul.** Aucune erreur dans le journal `psql` de la restauration. Ce n'est donc pas une
restauration « sans erreur mais vide » : c'est le symptôme du 2026-08-16 qui ne se reproduit pas.

Durée observée pour la phase de données : **≈ 1 h 27** (début 09:49:29Z, dernier comptage de table
11:16:25Z), sur une archive de 691 Mo pour ~16 Go restaurés. Le facteur limitant est le transport
`ssh | zcat | docker exec -i` depuis un poste Windows, pas la base : `dr-drill.sh` mesure 21 min
quand l'archive est déjà locale. Le RTO cible (4 h) reste tenu dans les deux cas.

⚠️ **Ce n'est pas le `dr-drill.sh` complet**, et la différence est écrite plus haut : la restauration
part de la copie **locale du serveur**, pas de la copie **hors-site**, dont seules la présence,
l'âge et la taille ont été vérifiées.

⚠️ **Ce que ce relevé ne dit pas.** Au moment de figer ce rapport, la restauration était entrée dans
sa phase d'index (`CREATE INDEX idx_companies_denomination_…`) et n'avait **pas encore atteint** les
instructions `ENABLE ROW LEVEL SECURITY` / `CREATE POLICY`, qui closent le flux (§3.1). **La survie
de la barrière RLS à une restauration n'est donc PAS mesurée ici** — elle l'est en CI, sur une base
de test, par `NeDoitPasRegresserTest §ACQUIS 2 (c)`, vu vert. Voir la liste du §5.

> **Consolidation, si le flux va à son terme : `04_PREUVES/agent-08/26_comptages-restauration.txt`
> et `31_grants-apres-restauration.txt`.**

### 3.1 La barrière d'isolation survit-elle à la restauration ?

C'est la question que `NeDoitPasRegresserTest §ACQUIS 2` pose, et elle mérite d'être posée sur la
**vraie** archive de production, pas sur un dump de test.

**Un piège a failli produire un faux constat, et il est consigné ici parce qu'il resservira.**
Mesurée **pendant** la restauration, la base restaurée montrait `relforcerowsecurity = t` sur 39
tables mais `relrowsecurity = f` partout et **0 policy** — de quoi conclure à tort que la
restauration perd la barrière. C'est faux : `pg_dump -Fp` n'émet pas ces instructions au même
endroit. Mesuré sur la même recette (`28_ordre-rls-dans-le-dump.txt`) :

```
ALTER TABLE ONLY … FORCE ROW LEVEL SECURITY   → ligne  1 125   (avant les données)
dernier COPY                                   → ligne  6 403
ALTER TABLE … ENABLE ROW LEVEL SECURITY        → ligne 10 613   (après les données)
CREATE POLICY …                                → ligne 10 619
```

`FORCE` est posé en section pré-données, `ENABLE` et les policies en section post-données. Toute
mesure de la barrière prise avant la fin du flux est donc **structurellement fausse**, et fausse
dans le sens alarmiste. Le résultat retenu est celui pris **après** la fin de la restauration
(`26_comptages-restauration.txt`, `27_schema-restaure.txt`).

---

## 4. Constats

### [A08-001] `coverage:refresh-matrix` échoue 71 fois sur 71 en production depuis l'armement du rôle applicatif
- Sévérité      : **S1**
- Domaine       : backend / sécurité (effet collatéral du durcissement)
- Référence     : main `1145473` ; production `api.axion-crm-pro.com`
- Emplacement   : `backend/routes/console.php:12` ; `backend/app/Console/Commands/CoverageRefreshMatrix.php:24-25`
- Constat       : la tâche planifiée horaire `coverage:refresh-matrix` échoue à chaque exécution depuis le 2026-08-16 14:00 avec `SQLSTATE[42501] : must be owner of materialized view coverage_matrix_cells`, parce que la production s'est mise à se connecter sous le rôle non-propriétaire `axion_app` (`CRM_DB_APP_ROLE_ENABLED=true`) alors que la vue matérialisée appartient toujours à `axion`.
- Preuve        : `18_prod-erreurs-rls.txt`, `19_prod-matview-cassee.txt`, `20_prod-erreurs-toutes.txt`, `21_prod-chronologie.txt`
  ```
  occurrences=142 (71 exceptions × 2 lignes)
  premiere=[2026-08-16 14:00:00]   derniere=[2026-08-19 12:00:00]
  71 production.ERROR: Scheduled command [… artisan coverage:refresh-matrix] failed with exit code [1].
  relname=coverage_matrix_cells  proprietaire=axion  relispopulated=t
  pg_stat_activity : axion_app → 5 sessions
  ```
- Témoin négatif: la même lecture de journal **trouve** d'autres échecs planifiés (`media:import-*`, `rgpd:anonymize-ips`) et **ne trouve rien** pour les tâches qui réussissent — le filtre n'est pas aveugle. Et le mécanisme est vérifiable hors journal : `pg_get_userbyid(relowner)` = `axion` ≠ `axion_app`, or `REFRESH MATERIALIZED VIEW` exige la propriété.
- Impact        : la matrice de couverture est **figée depuis 71 heures** et le restera. Tout écran qui la lit affiche un état périmé en le présentant comme courant. Aucune alerte : l'échec ne vit que dans `storage/logs/laravel.log`, exactement le schéma de la sauvegarde qui a échoué 91 fois sur 91 sans témoin.
- Reproduction  : `ssh root@46.62.248.239 "docker exec axion-crm-api sh -c 'grep -c \"must be owner of materialized view\" storage/logs/laravel.log'"`
- Correctif     : `ALTER MATERIALIZED VIEW coverage_matrix_cells OWNER TO axion_app;` dans une migration (≈ 15 min) **ou** exécuter la commande sur la connexion `pgsql_owner` (≈ 30 min, plus sûr : la vue reste propriété du propriétaire). Puis une garde : faire remonter les échecs de tâches planifiées ailleurs que dans un fichier que personne ne lit — le patron de `surveillance-sauvegarde.yml` est déjà écrit et éprouvé (≈ 2 h).
- Statut        : ouvert

### [A08-002] L'import de médias est refusé par la RLS en production, même cause, même silence
- Sévérité      : **S2**
- Domaine       : backend / sécurité
- Référence     : main `1145473` ; production
- Emplacement   : `backend/routes/console.php` (tâches `media:import-opendatasoft`, `media:import-blogs`)
- Constat       : le 2026-08-17 à 03:30:04, l'import de médias a été refusé par `SQLSTATE[42501] : new row violates row-level security policy for table "media"`, et les quatre tâches `media:import-*` de cette nuit-là ont échoué.
- Preuve        : `20_prod-erreurs-toutes.txt`, `21_prod-chronologie.txt`
  ```
  [2026-08-17 03:30:04] production.ERROR: SQLSTATE[42501]: … new row violates row-level security
    policy for table "media" (… SQL: insert into "media" ("workspace_id", "name", "media_type", …
  1 Scheduled command [… media:import-opendatasoft spel]  failed with exit code [1].
  1 Scheduled command [… media:import-opendatasoft cppap] failed with exit code [1].
  1 Scheduled command [… media:import-opendatasoft agences] failed with exit code [1]
  1 Scheduled command [… media:import-blogs] failed with exit code [1].
  ```
- Témoin négatif: c'est **le symptôme exact** que `RlsTest` fige déjà pour les tags (« sans contexte, un tag EXISTANT est invisible et son insertion est REFUSÉE — le symptôme du backfill du 2026-08-15 »). Le test existe, il est vert, et il n'a pas empêché la même panne de se reproduire sur une autre table : il mesure `tags`, pas les commandes d'import.
- Impact        : la banque de médias n'est plus alimentée. Une commande d'écriture qui ne pose pas de contexte d'espace de travail échoue désormais **en production seulement** — jamais en CI (voir A08-003).
- Reproduction  : recherche de `row-level security policy` dans `storage/logs/laravel.log` du conteneur `axion-crm-api` de production.
- Correctif     : envelopper chaque commande d'écriture planifiée dans `WorkspaceContext::run(...)` — le patron existe déjà (`ScrapingBackfillSrcTags` le documente en commentaire). Recenser les commandes concernées et les traiter (≈ 3-4 h). Un test par commande, joué **avec** `CRM_DB_APP_ROLE_ENABLED=true`, sinon il ne prouve rien.
- Statut        : ouvert

### [A08-003] La suite de tests ne s'exécute jamais dans la configuration de la production, et deux tests affirment le contraire de ce qui y tourne
- Sévérité      : **S1**
- Domaine       : tests / sécurité
- Référence     : main `1145473`
- Emplacement   : `backend/phpunit.xml`, `backend/phpunit-ci.xml`, `.github/workflows/ci.yml:391-400`, `backend/tests/Feature/RlsTest.php:301-321`
- Constat       : `CRM_DB_APP_ROLE_ENABLED` vaut **`true` en production** et n'est posé **nulle part** dans `phpunit.xml`, `phpunit-ci.xml` ni le job `Pest` de `ci.yml` — les 780 tests tournent donc sous le rôle propriétaire `axion` (SUPERUSER, BYPASSRLS), c'est-à-dire dans une configuration que la production n'a plus.
- Preuve        : `04_prod-env-isolation-tz.txt`, `10_prod-isolation.txt`, et :
  ```
  # production
  CRM_DB_APP_ROLE_ENABLED=true
  pg_stat_activity : axion_app → 5 sessions   |  axion → 1 (mon psql de lecture)
  # dépôt
  $ grep -n "CRM_DB_APP_ROLE" backend/phpunit.xml backend/phpunit-ci.xml
  backend/phpunit.xml:40:  (mention en COMMENTAIRE uniquement)
  # ci.yml, étape « Pest (BLOQUANT) » : DB_USERNAME: axion — aucun drapeau
  ```
  Deux tests verts affirment l'inverse de la production :
  ```php
  // RlsTest.php:301  test('drapeaux par défaut : aucun durcissement actif')
  expect(config('crm.db_app_role'))->toBeFalsy();
  // RlsTest.php:318  test('drapeau db_app_role à OFF : la connexion par défaut reste le rôle historique')
  expect(config('database.connections.pgsql.username'))->toBe(config('…pgsql_owner.username'));
  ```
  Le commentaire d'en-tête de `EtancheiteParTableTest.php:30-35` l'écrit noir sur blanc et se trompe :
  « *tant que `CRM_DB_APP_ROLE_ENABLED` reste à false — sa valeur par défaut, **et sa valeur en production*** ».
- Témoin négatif: la lecture d'environnement sait montrer les deux états — elle rend `false` sur le conteneur `api` **local** et `true` sur celui de **production**, avec la même commande `docker inspect`. Ce n'est pas une commande qui répond toujours la même chose.
- Impact        : c'est la cause commune de A08-001 et A08-002, et elle est structurelle. Toute écriture sans contexte d'espace de travail passe en CI et casse en production. La CI ne peut **pas** attraper cette classe de défaut, et deux tests la déclarent explicitement hors sujet. Piège 19 du dossier commun, en vrai : gardes irréprochables, mauvais objet.
- Reproduction  : `ssh root@… "docker inspect axion-crm-api --format '{{range .Config.Env}}{{println .}}{{end}}'" | grep CRM_DB_APP_ROLE` puis `grep -rn CRM_DB_APP_ROLE backend/phpunit*.xml .github/workflows/ci.yml`
- Correctif     : ajouter au job `Pest` un **second passage** avec `CRM_DB_APP_ROLE_ENABLED=true` et `DB_USERNAME=axion_app` (le rôle et ses GRANT existent déjà dans le conteneur Postgres de CI). Réécrire les deux tests d'inertie de `RlsTest` pour qu'ils décrivent la configuration **réellement déployée**, et corriger le commentaire d'en-tête de `EtancheiteParTableTest`. Coût : ≈ 4 h + le temps de faire passer les tests qui rougiront — et ceux qui rougiront sont précisément les défauts déjà en production.
- Statut        : ouvert

### [A08-004] Le schéma de production diffère de celui de la CI : `audit_logs` y est une table ordinaire à `workspace_id`, sans RLS, que la garde d'étanchéité exclut « par construction »
- Sévérité      : **S2**
- Domaine       : sécurité / tests
- Référence     : main `1145473`
- Emplacement   : `backend/tests/Support/EtancheiteWorkspace.php:70-82` ; `backend/database/migrations/2026_05_16_000001_create_extensions_and_helpers.php:56-57`
- Constat       : en production, `audit_logs` a `relkind = 'r'` (table ordinaire, non partitionnée), porte `workspace_id`, et a `relrowsecurity = f` / `relforcerowsecurity = f` ; en local et en CI, la même table a `relkind = 'p'` avec une partition `audit_logs_default`. La garde l'exclut de son périmètre au motif écrit « *Table PARTITIONNÉE par pg_partman (relkind='p')* » — motif **vrai en CI, faux en production**.
- Preuve        : `11_relkind-prod-vs-local.txt`, `12_prod-audit-logs-partitions.txt`, `10_prod-isolation.txt`
  ```
  PRODUCTION                                  LOCAL / CI
  audit_logs         | r | f | f              audit_logs         | p | f | f
  (aucune partition)                          audit_logs_default | r | f | f
  relations relkind='p' en production : 0
  pg_available_extensions : pg_partman 5.1.0 installee=NON, vector 0.8.0 installee=NON
  (les deux sont DISPONIBLES dans l'image, elles ne sont pas INSTALLÉES)
  ```
  La cause est le `DO $$ … skip silencieusement` de la migration `2026_05_16_000001` : elle a tourné
  quand l'image ne fournissait pas `pg_partman`, n'a rien fait, et **ne rejouera jamais**.
- Témoin négatif: la même requête, exécutée sur les deux bases, rend deux résultats **différents** — elle n'est pas constante. Et elle sait montrer l'état sain : `coverage_matrix_cells` ressort `m` des deux côtés, `sessions` et `user_workspaces` ressortent `r/f/f` des deux côtés.
- Impact        : le journal d'audit — la table qui trace *qui a fait quoi sur quel espace* — n'a aucune barrière SQL en production, alors que le rôle applicatif y est armé. 54 lignes et un seul espace de travail aujourd'hui, donc pas de fuite constatée ; mais l'exigence §A.1 n°9 (« *chaque table cloisonnée re-vérifiée par un test qui rougit quand le contexte manque* ») **n'est pas tenue pour `audit_logs` en production**, et la garde ne peut structurellement pas s'en apercevoir. Effet second : `infra/scripts/backup-postgres.sh` injecte `CREATE EXTENSION … "vector"` mais **pas** `pg_partman` — le jour où la production sera partitionnée, la recette de restauration ne le reposera pas.
- Reproduction  : la requête d'inventaire de `EtancheiteWorkspace::inventaireBrut` jouée telle quelle contre la base de production, puis contre la base locale.
- Correctif     : trancher explicitement — soit installer `pg_partman` en production et partitionner (la migration ne rejouant pas, il faut une migration neuve), soit poser RLS sur `audit_logs` en production et retirer l'exclusion. Dans les deux cas, faire calculer l'inventaire de la garde **contre un schéma issu du dump de production** plutôt que d'une base fraîchement migrée. Coût : ≈ 1 j pour la décision + la migration ; ≈ 3 h pour rendre la garde capable de voir la divergence.
- Statut        : ouvert

### [A08-005] Trois des six jobs de la CI ne bloquent aucune fusion — dont les deux gardes nées des deux incidents les plus graves du produit
- Sévérité      : **S2**
- Domaine       : tests / conformité
- Référence     : main `1145473` ; `repos/will383842/axion-crm-pro/branches/main/protection`
- Emplacement   : protection de branche GitHub (hors dépôt) vs `.github/workflows/ci.yml`
- Constat       : la CI exécute 6 jobs ; la protection de `main` n'en exige que 4, et deux des exigences ne correspondent à aucun job de `ci.yml` mais à `security.yml`. Les trois jobs non exigés sont `La config de production ne publie que 80 et 443`, `Les scripts d'infra sont-ils exécutables ?` et `Le Caddyfile est-il valide ?`.
- Preuve        : `23_ci-gates-vs-protection.txt`
  ```
  JOBS EXÉCUTÉS (run 32238995760)          CONTEXTES EXIGÉS
  Backend Laravel (…PHPStan+Pint+Pest)  →  Backend Laravel (…PHPStan+Pint+Pest)
  Frontend React/Vite                   →  Frontend React/Vite
  Workers Node + Playwright             →  Workers Node + Playwright
  Le Caddyfile est-il valide ?          →  (aucun)
  Les scripts d'infra sont-ils exéc. ?  →  (aucun)
  La config de production ne publie…    →  (aucun)
                                           Secrets scan (Gitleaks)  [security.yml]

  enforce_admins=false   required_pull_request_reviews=ABSENT   strict=false
  ```
- Témoin négatif: la comparaison sait apparier — elle apparie correctement les 3 jobs qui **sont** exigés, et ne laisse dans l'écart que ceux qui ne le sont pas. Une comparaison cassée aurait rendu les 6.
- Impact        : `Les scripts d'infra sont-ils exécutables ?` est la garde née des **91 sauvegardes échouées sur 91** (bit d'exécution perdu dans git) ; `La config de production ne publie que 80 et 443` est celle née de la **base de production exposée sur internet** le 2026-08-19. Une PR qui les fait rougir reste **fusionnable**. Elle bloquerait ensuite le déploiement (`deploy-direct-ssh.yml` a `needs: [ci]` sur le workflow entier), donc le défaut n'atteint pas la production — mais il atteint `main`, et il s'y installe en bloquant tous les déploiements suivants. `enforce_admins=false` et l'absence de revue exigée signifient par ailleurs qu'aucune de ces gardes ne contraint le seul committeur du dépôt.
- Reproduction  : `gh api repos/:owner/:repo/branches/main/protection --jq '.required_status_checks.contexts[]'` et `gh api repos/:owner/:repo/actions/runs/<id>/jobs --jq '.jobs[].name'`
- Correctif     : ajouter les 3 noms de job aux `required_status_checks.contexts` (10 min, un appel d'API) ; activer `enforce_admins` est une décision de gouvernance qui appartient au dirigeant, pas à l'audit.
- Statut        : ouvert

### [A08-008] La sauvegarde restaure les données mais pas les droits : une restauration de secours livre une application incapable de lire quoi que ce soit
- Sévérité      : **S1**
- Domaine       : sécurité / conformité (reprise après sinistre)
- Référence     : main `1145473` ; production
- Emplacement   : `infra/scripts/backup-postgres.sh:97-104` ; `infra/scripts/restore-postgres.sh:38-56`
- Constat       : la recette de sauvegarde utilise `pg_dump --no-acl`, qui n'écrit **aucun `GRANT`** dans l'archive ; `restore-postgres.sh` n'en repose aucun et déclare « `Restore complet. DB prête.` » dès que la base compte au moins 10 tables. Or la production se connecte depuis le 2026-08-16 sous le rôle **non-propriétaire** `axion_app`, dont tous les droits viennent d'une migration et non du dump.
- Preuve        : `30_grants-et-recette-dump.txt`, et la mesure sur la base **réellement restaurée** dans `26_comptages-restauration.txt` / `31_grants-apres-restauration.txt`
  ```
  # recette de sauvegarde
  pg_dump -U … -Fp --no-owner --no-acl --clean --if-exists "$DB_NAME"
                            ^^^^^^^^^  ← aucun GRANT dans l'archive

  # production aujourd'hui (droits posés par migration, PAS par le dump)
  companies SELECT=true   companies INSERT=true   contacts SELECT=true
  CRM_DB_APP_ROLE_ENABLED=true   →   l'application se connecte en axion_app

  # restore-postgres.sh, seule vérification finale :
  if [ "$TABLE_COUNT" -lt 10 ]; then … fi
  log "Restore complet. DB $TARGET_DB prête."
  ```
- Témoin négatif: `has_table_privilege()` sait rendre `true` — il le rend sur la base de **production** pour les trois privilèges testés. Le `false` obtenu sur la base restaurée n'est donc pas un artefact de la mesure. Par ailleurs le contrôle sait aussi rendre `true` sur une base restaurée : il suffirait que le dump porte les `GRANT`.
- Impact        : **le jour du sinistre, la restauration « réussit » et le service reste mort.** L'exploitant voit « Restore complet », les comptages sont bons, et l'application ne peut rien lire jusqu'à ce que quelqu'un rejoue à la main la migration de droits. C'est le pire moment pour découvrir une étape manquante. À noter : ce défaut est **déjà écrit** dans `NeDoitPasRegresserTest §ACQUIS 2 (d)`, sous forme d'attente **inversée** assortie de la prédiction « *le jour où le drapeau passe à true, une restauration livre une application qui ne peut plus rien lire* ». Ce jour est arrivé le 2026-08-16 ; l'attente inversée est toujours verte, donc le trou est toujours ouvert et rien n'a signalé la bascule.
- Reproduction  : restaurer une archive de production dans une base neuve, puis `SELECT has_table_privilege('axion_app','companies','SELECT')` → `false`.
- Correctif     : deux options. ① Retirer `--no-acl` de `backup-postgres.sh` (5 min) — mais le dump embarque alors des `GRANT` référençant des rôles qui peuvent ne pas exister sur la machine de secours. ② **Préférable** : ajouter à `restore-postgres.sh` une étape finale qui rejoue la migration de droits (ou un `GRANT` idempotent) et **vérifie** `has_table_privilege('axion_app', …)` avant de déclarer la base prête (≈ 2 h). Dans les deux cas, retourner l'attente inversée de `ACQUIS 2 (d)` pour qu'elle exige désormais `true`, et faire échouer `restore-postgres.sh` si le droit manque. Ajouter aussi un contrôle de **comptage** à `restore-postgres.sh`, qui aujourd'hui ne vérifie que le nombre de tables — une base restaurée à 0 ligne passerait sa vérification.
- Statut        : ouvert

### [A08-006] La tâche RGPD d'anonymisation des IP n'a jamais fonctionné : son SQL ne compile pas
- Sévérité      : **S1**
- Domaine       : conformité
- Référence     : main `1145473` ; production
- Emplacement   : `backend/app/Console/Commands/AnonymizeOldIps.php:27`
- Constat       : `rgpd:anonymize-ips`, planifiée tous les jours à 04:30, échoue à chaque exécution avec `SQLSTATE[42883] : operator does not exist: cidr / integer`. Ni les IP de `audit_logs` ni celles de `sessions` ne sont anonymisées — l'échec survient avant le second traitement.
- Preuve        : `20_prod-erreurs-toutes.txt`, `25_prod-rgpd-ips.txt`
  ```
  [2026-08-17 04:30:00]  [2026-08-18 04:30:00]  [2026-08-19 04:30:00]   ← 3 exécutions, 3 échecs
  production.ERROR: SQLSTATE[42883]: Undefined function: 7 ERROR:  operator does not exist: cidr / integer
  LINE 2: SET ip = (host(network(ip::cidr / CASE WHEN family(ip) = 4 T...

  production : audit_a_anonymiser=25   audit_avec_ip=58   plus_ancienne=2026-05-17 12:37:43+00
  ```
- Témoin négatif: joué à la main sur Postgres 16.9, le contrôle distingue bien l'expression fausse de l'expression juste :
  ```
  SELECT host(network('192.168.42.123'::cidr / 24))            → ERROR: operator does not exist: cidr / integer
  SELECT host(network(set_masklen('192.168.42.123'::cidr,24))) → 192.168.42.0
  ```
- Impact        : la rétention de 30 jours annoncée dans l'en-tête de la commande n'est pas appliquée. 25 lignes de `audit_logs` conservent aujourd'hui une adresse IP en clair au-delà du délai, la plus ancienne depuis le 2026-05-17 — soit 94 jours. Le volume est faible, le mécanisme est totalement inopérant et le sera à mesure que la table grossit. Aucun test ne couvre cette commande.
- Reproduction  : `docker exec axion-crm-postgres psql -U axion -d postgres -c "SELECT host(network('192.168.42.123'::cidr / 24))"`
- Correctif     : remplacer `ip::cidr / N` par `set_masklen(ip::cidr, N)` (5 min) et ajouter un test qui insère une IP de plus de 30 jours, joue la commande et vérifie le résultat (≈ 1 h). Vérifier au passage que la commande tourne sous une connexion autorisée à écrire dans `audit_logs` (cf. A08-003).
- Statut        : ouvert

### [A08-007] Le journal de production est inexploitable comme canal de surveillance : 99 % de bruit
- Sévérité      : **S2**
- Domaine       : backend
- Référence     : main `1145473` ; production
- Emplacement   : conteneur `axion-crm-api`, `storage/logs/laravel.log`
- Constat       : `laravel.log` pèse **253 Mo** pour **3 jours** de production (2026-08-16 13:34 → 2026-08-19 12:18). Sur ses **23 858** lignes `ERROR`/`CRITICAL`, **23 658 (99,2 %)** sont le même défaut : `SQLSTATE[42P01] : relation "telescope_entries" does not exist` — Telescope est actif en production sans ses tables.
- Preuve        : `24_prod-journal-composition.txt`, `20_prod-erreurs-toutes.txt`
  ```
  taille laravel.log : 253.4M   (3 jours)
  lignes ERROR/CRITICAL totales : 23858
  dont telescope_entries        : 165810 lignes au total (piles comprises)
  ```
- Témoin négatif: le comptage sait discriminer — il rend 23 658 pour un motif et 71, 12, 5, 3, 1 pour les autres. Il ne compte pas tout.
- Impact        : c'est **la raison mécanique** pour laquelle A08-001 (71 échecs horaires) et A08-006 (3 échecs RGPD) sont passés inaperçus pendant trois jours. Le produit a déjà payé ce schéma une fois — 91 sauvegardes échouées dans un journal que personne ne lit — et l'a corrigé pour la sauvegarde seule, par une surveillance **hébergée ailleurs**. Le même angle mort subsiste pour toutes les autres tâches planifiées.
- Reproduction  : `ssh root@… "docker exec axion-crm-api sh -c 'du -h storage/logs/laravel.log; grep -c telescope_entries storage/logs/laravel.log'"`
- Correctif     : désactiver Telescope en production **ou** poser ses tables (30 min) ; poser une rotation sur `laravel.log` (30 min) ; étendre le patron de `surveillance-sauvegarde.yml` à l'échec des tâches planifiées (≈ 2 h). Les trois sont indépendants.
- Statut        : ouvert

---

## 5. Ce que je n'ai PAS pu vérifier, et pourquoi

Cette liste est un livrable.

1. **L'identité `sha256` de la copie hors-site avec la copie locale.** `dr-drill.sh` la vérifie en
   rapatriant l'archive depuis la Storage Box **vers `/tmp` du serveur de production** — une écriture
   en production, interdite ici. Ce qui a été vérifié du hors-site : présence, âge (6 h), taille
   (724 926 343 octets). **Ce qu'il faudrait** : lancer `./infra/scripts/dr-drill.sh` depuis un poste
   autorisé, ou obtenir `SB_PASSWORD` pour rapatrier l'archive directement sur un poste local.
2. **Un envoi réel du formulaire du site en production.** Ce serait une écriture. Contourné par une
   preuve plus forte mais indirecte : un envoi **d'un vrai visiteur** a traversé le canal le
   2026-08-19 à 03:25:47 UTC. **Ce qu'il faudrait** : rejouer le parcours complet sur la
   préproduction (`staging.axion-crm-pro.com`), qui vient d'être montée.
3. **Un aller-retour signé HMAC de bout en bout sur `POST /api/internal/site-sync`.** Le poste local
   n'a ni `SITE_SYNC_HMAC_SECRET` ni `CRM_INGEST_ENABLED` dans son `.env` : la porte locale répond
   401 puis 503 quoi qu'on envoie. Le contrôle a donc porté sur les **refus** (401 sur deux formes
   d'échec) et sur les **ingestions réelles** en production. **Ce qu'il faudrait** : jouer le
   scénario sur la préproduction, où le secret peut être posé sans risque.
4. **La preuve qu'une PR rouge sur un job non exigé est bien fusionnable.** La démontrer exigerait
   d'ouvrir une PR délibérément cassée sur le dépôt. Le constat A08-005 repose donc sur la
   **configuration mesurée** (liste des contextes exigés vs liste des jobs exécutés), pas sur une
   fusion effectivement réalisée.
5. **Le rejeu du correctif du formulaire du site à l'envers.** Je n'ai pas vérifié que
   `unified-contact-schema.test.ts` rougirait sur la version d'avant `0baf0783` : cela demandait un
   worktree du dépôt du site et une bascule de fichier. Le test a été **exécuté et il passe** (5/5) ;
   sa capacité à rougir n'a pas été prouvée.
6. **L'étanchéité en profondeur.** Hors périmètre : traitée par l'agent 11. Je n'ai mesuré que la
   **non-régression** (la barrière tient-elle toujours, et qu'a cassé son armement).
7. **Les 23 658 erreurs Telescope**, les 12 `Stream is already at the end [tcp://redis:6379]` et les
   5 `Route [login] not defined` (déjà A-001) n'ont pas été creusés : hors de mon périmètre, signalés
   pour qui de droit.
8. **La survie de la barrière RLS à la restauration d'une archive de PRODUCTION.** Les 20 726 338
   lignes sont revenues exactes, mais le flux était encore en phase d'index quand j'ai figé ce
   rapport : les `ENABLE ROW LEVEL SECURITY` et les 39 `CREATE POLICY` sont émis **après** les index
   (§3.1), et je ne les ai donc pas vus atterrir. Ce que je sais : ces instructions **sont** dans le
   dump (mesuré sur la même recette, `28_ordre-rls-dans-le-dump.txt`), et `ACQUIS 2 (c)` le vérifie
   en CI sur une base de test. **Ce qu'il faudrait** : laisser le flux finir, puis
   `SELECT count(*) FROM pg_policies WHERE schemaname='public'` → doit rendre **39**, et
   `relrowsecurity` → doit rendre **38**. Le script prêt à l'emploi est
   `scratchpad/a08_final.sh`.
9. **A08-008 vérifié sur pièces, pas sur la base restaurée.** Le constat repose sur trois faits
   mesurés — `pg_dump --no-acl` dans la recette, `restore-postgres.sh` qui ne repose aucun droit et
   ne vérifie que le nombre de tables, et `CRM_DB_APP_ROLE_ENABLED=true` en production — et sur
   l'attente inversée que le dépôt a lui-même écrite. **Ce qu'il faudrait** pour le clouer :
   `SELECT has_table_privilege('axion_app','companies','SELECT')` sur la base restaurée une fois le
   flux terminé → attendu `false`.

---

## 6. Nettoyage

**État exact à la clôture de ce rapport** (2026-08-19, ≈ 11:45 UTC) : la restauration est entrée
dans sa phase d'index et travaille sur `CREATE INDEX idx_companies_signals ON public.companies`
(base à 7 698 Mo, cible ~16 Go). Les **données** sont toutes chargées et vérifiées (§3) ; les index
et les policies ne le sont pas encore.

La base jetable `axion_crm_dr_a08` a été créée sur le conteneur **local**
`axion-crm-postgres` pour cet exercice. **Elle doit être supprimée** une fois le flux terminé :

```sh
docker exec axion-crm-postgres psql -U axion -d postgres \
  -c "DROP DATABASE IF EXISTS axion_crm_dr_a08 WITH (FORCE);"
```

Rien d'autre n'a été créé, ni en local ni en production. Les deux scripts de lecture déposés dans
`/tmp` du serveur ont été supprimés et leur absence vérifiée.
