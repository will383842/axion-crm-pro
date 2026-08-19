# Agent 39 — Sauvegardes et observabilité

> **Référence** : dépôt CRM, `main = e8924b8` (relu par `git log` le 2026-08-19 à 11:12 UTC,
> **et non** `c0c453d` : trois PR ont été poussées pendant l'audit).
> **Production** : `46.62.248.239`, accédée **en lecture seule** par SSH. Aucune écriture,
> aucune suppression, aucune restauration n'a été faite sur la production.
> **Restauration** : faite **en local**, sur la base jetable `axion_crm_a39`.
> Sorties brutes : `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/agent-39/`.

---

## 0. La mission centrale, en une phrase

**Une sauvegarde de production a été restaurée pour de vrai, en local, et les lignes ont été
comptées table par table.** Le détail est au §2. Ce qui suit ne remplace pas cette mesure : il
la complète.

---

## 1. Tableau de grille

| Objet du périmètre | Existe ? | Fait-il ce qu'il annonce ? | Vu rouge ? | Mesure | Constat |
|---|---|---|---|---|---|
| `infra/scripts/backup-postgres.sh` | oui | **oui** — dump quotidien 03:00 UTC, 692 Mio gz, envoi SFTP, rotation locale 7 j / distante 30 j | n/a | 6 archives locales, 6 hors-site, dernière `20260819T030001Z` | F39-004 (CRLF), F39-009 (profondeur réelle 3 j) |
| `infra/scripts/verifier-sauvegarde.sh` | oui | **partiellement** — vérifie existence + âge + taille, **jamais la restaurabilité** | **oui**, 2 fois le 2026-08-17 (`SEUIL_AGE_H=0` et `SEUIL_TAILLE_MO=999999`) | 3 contrôles sur 4 possibles | **F39-002** |
| `infra/scripts/dr-drill.sh` | oui | **jamais joué automatiquement** — aucune CI, aucun cron, aucune trace d'exécution depuis le 2026-08-16 | non | 0 occurrence dans `.github/workflows/*` hors texte d'issue | **F39-003** |
| `infra/scripts/setup-backup.sh` | oui | oui (installation unique, déjà jouée) | n/a | cron présent et actif | F39-004 (CRLF) |
| `infra/scripts/restore-postgres.sh` | oui | oui, **mais sa cible par défaut est `axion_crm`**, le nom de la base de production | non | ligne 18 : `TARGET_DB="${2:-axion_crm}"` | **F39-010** |
| `.github/workflows/surveillance-sauvegarde.yml` | oui | oui, et **il a vraiment rougi** | **oui** (2 échecs volontaires, 1 issue #141 ouverte puis fermée) | 6 exécutions, 3 planifiées vertes | F39-002 (ce qu'il ne regarde pas) |
| Migration `fixer_search_path_des_fonctions` | oui | **oui aujourd'hui** — les 7 fonctions de production portent bien `search_path` | non | 7/7 avec `proconfig` en production ; restauration réussie | **F39-005** (aucune garde) |
| `Api/ObservabilityController` + `GET /observability/summary` | oui | rend des **chiffres réels** (business_events 1 286 229, scraper_runs 7 608 196) — mais **7 des 8 rubriques renvoient 0 en avalant l'exception** | non | `try/catch` retournant 0 dans 7 méthodes privées | **F39-007** |
| Sentry | **DSN configuré en production** (`o4510557298294784.ingest.de.sentry.io`) | **non** — `withExceptions()` est vide, aucune exception non rattrapée n'est envoyée | non | `bootstrap/app.php:44-46` vide ; README du paquet 4.27.0 exige `Integration::handles()` | **F39-006** |
| `docker-compose.observability.yml` | oui (8 services) | **aucun n'est déployé en production** | non | `docker ps` sur 46.62.248.239 : 13 conteneurs, 0 d'observabilité | **F39-001** |
| `infra/monitoring/prometheus/alerts.yml` | oui (8 règles) | inapplicable : pas de Prometheus, **et les cibles n'existent pas** (`/metrics` absent du code, exporters absents des compose) | non | 0 route `/metrics`, 0 `postgres-exporter`/`redis-exporter` | **F39-001** |
| `infra/monitoring/alertmanager/alertmanager.yml` | oui (Slack + Telegram) | inapplicable, et `SLACK_WEBHOOK_URL` / `ALERTMANAGER_INTERNAL_TOKEN` absents du `.env` de production | non | `grep -E "^(SLACK|TELEGRAM|ALERTMANAGER)" .env` → 0 ligne | **F39-001** |
| `infra/monitoring/{grafana,loki,promtail,tempo}` | oui | inapplicable ; en outre `promtail.yml` surveille `/var/log/axion-crm/api/*.log`, **chemin inexistant** | non | le journal réel est dans le conteneur, `/var/www/html/storage/logs/` | F39-001 |
| `infra/runbooks/04-restore-dr.md` | oui | **non** — décrit S3, `s3cmd`, WAL streaming, Backblaze B2, PITR : **rien de tout cela n'existe** | non | la sauvegarde réelle est un `pg_dump` quotidien vers une Storage Box SFTP | **F39-008** |
| `infra/runbooks/{01,02,03}` | oui | s'appuient sur des alertes (`HorizonQueueBacklog`, `DiskSpaceLow`, `ApiDown`, Uptime Kuma) qui **ne peuvent pas se déclencher** ; `DiskSpaceLow` **n'existe même pas** dans `alerts.yml` | non | `grep -c DiskSpaceLow alerts.yml` → **0** | **F39-011** |
| Tâches planifiées (35) | oui | **aucune ne prévient personne quand elle échoue** | non | 0 occurrence de `onFailure`/`emailOutputOnFailure`/`pingOnFailure` dans tout `backend/` | **F39-006** |
| RPO annoncé (`Makefile`, runbook 04) | « ≤ 1 h » | **faux d'un facteur 24** — la sauvegarde est quotidienne ; `dr-drill.sh` lui-même tolère 36 h | n/a | RPO mesuré : **24 h** (8 h 46 au moment de la mesure) | **F39-009** |
| RTO annoncé (`Makefile`, runbook 04) | « ≤ 4 h » | tenu, mais la marge n'est pas celle qu'on croit | n/a | RTO mesuré : voir §3 | F39-009 |
| Trajectoire du disque de production | 25 Go libres | **rien ne la surveille** | non | consommation nette **511 Mio/j** mesurée sur 900 s | **F39-011** |

---

## 2. « Une sauvegarde a été restaurée pour de vrai » — la mesure

### 2.1 Ce qui a été fait, geste par geste

1. `ssh root@46.62.248.239` — lecture seule. Inventaire de `/var/backups/axion-crm/` :
   6 archives, la plus récente `axion_crm_20260819T030001Z.sql.gz`, **724 926 343 octets**.
2. `scp` de cette archive vers le poste : **40,3 s** (`time scp`), soit ≈ 17 Mio/s.
3. Empreinte comparée : `sha256sum` sur le serveur et sur le poste →
   **`521b7ab6f796b9b0456c46975c02e4466f7d127506f28325c53cc02c853338de`** des deux côtés.
   `gzip -t` : OK. L'archive rapatriée est **bit à bit** celle du serveur.
4. Base jetable créée : `CREATE DATABASE axion_crm_a39 OWNER axion` sur le Postgres **local**
   (16.9, PostGIS + vector disponibles).
5. `gzip -dc … | psql -q` dans `axion_crm_a39`.
6. Comptages exacts (`count(*)`, pas d'estimation `n_live_tup`) comparés à la production.

### 2.2 Comptages obtenus

> **Référence de production** relevée deux fois en lecture seule, à 11:11 UTC puis à 12:00 UTC —
> identiques pour toutes les tables sauf `audit_logs` (63 → 64). La production n'ingérait pas de
> `companies` pendant la fenêtre de mesure, ce qui rend la comparaison valide ce jour-là.

<!-- COMPTAGES -->

### 2.3 Ce que la restauration prouve, et ce qu'elle ne prouve pas

- Elle prouve que **l'archive du 2026-08-19 03:00 UTC est restaurable** et **complète**.
- Elle prouve que le correctif `fixer_search_path_des_fonctions` **tient encore aujourd'hui** :
  aucune erreur `function unaccent(text) does not exist`, `companies` et `contacts` restaurées
  avec leurs colonnes générées.
- Elle **ne prouve pas** que la copie **hors-site** est restaurable : la comparaison d'empreinte
  entre la copie locale du serveur et la copie de la Storage Box exige d'écrire ≈ 692 Mio sur le
  disque de production, ce que mon mandat interdit. **Ce que j'ai pu établir en lecture seule :
  les deux copies ont exactement la même taille en octets** (724 926 343). `dr-drill.sh` fait,
  lui, la vraie comparaison d'empreinte — mais il n'est jamais joué (F39-003).

---

## 3. RPO et RTO — mesurés, pas recopiés

| | Annoncé (`Makefile:140`, `infra/runbooks/04-restore-dr.md:3`) | Mesuré |
|---|---|---|
| **RPO** | « ≤ 1 h » | **24 h** — la sauvegarde est quotidienne (cron `0 3 * * *`). Au moment de la mesure (12:00 UTC), la dernière sauvegarde datait de **8 h 57**. `dr-drill.sh` lui-même code `RPO_CIBLE_S=129600`, soit **36 h** : le script contredit le `Makefile` qui l'appelle. |
| **RTO** | « ≤ 4 h » | voir ci-dessous |

<!-- RTO -->

---

## 4. Ce qui, dans ce produit, alerterait réellement un humain

Réponse courte : **une seule chose** — le workflow GitHub `surveillance-sauvegarde.yml`.
Il tourne hors de la machine qu'il surveille, il ouvre une issue étiquetée `sauvegarde`, et
**il a réellement rougi** : deux exécutions en échec le 2026-08-17 (seuils poussés à
`SEUIL_AGE_H=0` puis `SEUIL_TAILLE_MO=999999`), qui ont ouvert l'issue **#141**, depuis fermée.
C'est la seule garde de ce périmètre dont on ait la preuve qu'elle sait crier.

Tout le reste est muet :

| Dispositif | État réel |
|---|---|
| Prometheus / Alertmanager / Grafana / Loki / Tempo / GlitchTip / Uptime Kuma | **aucun déployé en production** |
| Les 8 règles de `alerts.yml` | inapplicables — et leurs cibles (`/metrics`, `postgres-exporter`, `redis-exporter`) **n'existent nulle part dans le dépôt** |
| Slack `#axion-crm-alerts`, Telegram d'astreinte | `SLACK_WEBHOOK_URL` et `ALERTMANAGER_INTERNAL_TOKEN` **absents du `.env` de production** |
| Sentry | DSN **présent** en production et vu par le conteneur, mais `withExceptions()` est **vide** : aucune exception non rattrapée ne part. Seuls **8 appels explicites** `\Sentry\captureException()` (tous dans le scraping/enrichissement) rapportent. |
| `audit:verify-chain`, quotidien à 03:00 | écrit `Audit hash chain INVALIDE` sur la sortie standard d'un conteneur, et rend `FAILURE`. Le code porte le commentaire `// En prod : envoi Slack/Telegram + ouverture incident.` — **ce n'est pas implémenté**. |
| Les 35 tâches planifiées | **0** occurrence de `onFailure`, `emailOutputOnFailure`, `pingOnFailure` dans tout `backend/` |
| `GET /observability/summary` | rend des chiffres réels **sur un écran qu'il faut ouvrir**. Ce n'est pas une alerte. Et 7 de ses 8 rubriques renvoient `0` en avalant l'exception : « aucun problème » et « je n'ai pas pu regarder » y ont la même apparence. |
| La saturation du disque | **rien**. Pas d'alerte disque dans `alerts.yml` (0 occurrence de `DiskSpaceLow`, que le runbook 02 cite pourtant comme symptôme d'entrée). |

---

## 5. Constats

### [F39-001] Les huit services d'observabilité et les douze règles d'alerte n'existent que dans le dépôt : aucun n'est déployé, et leurs cibles de mesure n'existent pas
- Sévérité      : **S1**
- Domaine       : exploitation / observabilité
- Référence     : `main e8924b8` ; production `46.62.248.239` au 2026-08-19 12:00 UTC
- Emplacement   : `docker-compose.observability.yml`, `infra/monitoring/prometheus/{prometheus.yml,alerts.yml}`, `infra/monitoring/alertmanager/alertmanager.yml`
- Constat       : les 8 services (Prometheus, Alertmanager, Grafana, Loki, Promtail, Tempo, GlitchTip, Uptime Kuma) ne tournent sur aucun hôte de production ; les 6 jobs de scrape visent 8 cibles dont `laravel-api:80/metrics`, `postgres-exporter:9187` et `redis-exporter:9121`, **qui n'existent ni dans le code ni dans aucun `docker-compose*.yml`** ; et les deux destinations d'alerte (Slack, Telegram) reposent sur des variables absentes du `.env` de production.
- Preuve        : `04_PREUVES/agent-39/03_observabilite-alertes.txt`
  ```
  $ ssh root@46.62.248.239 'docker ps --format "{{.Names}}"'
    axion-crm-{api,app,horizon,scheduler,caddy,postgres,redis} + 5 conteneurs staging
    → aucun prometheus / grafana / alertmanager / loki / tempo / glitchtip / uptime-kuma
  $ grep -rn "'/metrics'|\"/metrics\"" backend/routes/      → 0 résultat
  $ grep -rn "postgres-exporter\|redis-exporter" docker-compose*.yml → 0 résultat
  $ ssh root@… 'grep -E "^(SLACK|TELEGRAM|ALERTMANAGER)" /opt/axion-crm-pro/.env' → 0 ligne
  ```
- Témoin négatif: le même `docker ps` **trouve** bien les 13 conteneurs réels, et le même `grep` **trouve** bien `SENTRY_LARAVEL_DSN` dans le même `.env` — le contrôle voit ce qui existe.
- Impact        : tous les runbooks entrent par une alerte (`ApiDown`, `HorizonQueueBacklog`, `DiskSpaceLow`, « Uptime Kuma rouge ») qui ne peut pas se déclencher. En exploitation, la première information d'un incident vient d'un utilisateur, pas du système. La règle `ApiDown` serait de surcroît **rouge en permanence** si Prometheus était démarré tel quel, la cible n'exposant rien.
- Reproduction  : `ssh root@46.62.248.239 'docker ps'` ; puis les trois `grep` ci-dessus depuis la racine du dépôt.
- Correctif     : soit déployer la pile et **créer les cibles manquantes** (route `/metrics`, deux exporters, une alerte disque) — compter 1 à 2 j ; soit assumer l'absence et **retirer du dépôt** ce qui prétend surveiller (compose + alerts + les entrées de runbook qui s'y adossent) — 2 h. Le pire est l'état actuel : la documentation affirme une surveillance qui n'existe pas.
- Statut        : ouvert

### [F39-002] La surveillance des sauvegardes vérifie qu'un fichier existe, qu'il est récent et qu'il est gros — jamais qu'il est restaurable
- Sévérité      : **S2**
- Domaine       : exploitation / sauvegarde
- Référence     : `main e8924b8`
- Emplacement   : `infra/scripts/verifier-sauvegarde.sh:79-153`, `.github/workflows/surveillance-sauvegarde.yml:67-117`
- Constat       : le contrôle quotidien fait exactement trois choses — la copie hors-site existe, elle a moins de 36 h, elle pèse plus de 100 Mio. Il ne décompresse rien, ne vérifie aucune empreinte, ne restaure rien.
- Preuve        : lecture du script (les trois blocs `--- Inventaire hors-site`, `--- Âge`, `--- Taille`) ; `04_PREUVES/agent-39/03_observabilite-alertes.txt` pour l'historique des 6 exécutions.
- Témoin négatif: la garde **a été vue rouge**, deux fois, le 2026-08-17 (`SEUIL_AGE_H=0`, puis `SEUIL_TAILLE_MO=999999`), et elle a bien ouvert l'issue #141. Elle fonctionne : c'est sa **portée** qui est le sujet, pas sa santé.
- Impact        : le seuil de taille est à **100 Mio** alors que le dump réel pèse **692 Mio**. Une sauvegarde amputée de 85 % de ses lignes passerait au vert. Et c'est précisément le scénario déjà vécu le 2026-08-16 : le dump se terminait « sans erreur » et rendait une base **sans `companies` ni `contacts`** — un défaut que ni l'existence, ni l'âge, ni un seuil à 100 Mio n'auraient attrapé. Piège 19 du dossier : la garde est irréprochable, et elle mesure le mauvais objet.
- Reproduction  : lire les trois contrôles du script ; comparer `SEUIL_TAILLE_MO=100` à la taille réelle `724 926 343` octets.
- Correctif     : deux gestes peu coûteux. (1) porter le seuil de taille à ≈ 80 % de la dernière taille connue plutôt qu'à une constante — 30 min ; (2) ajouter un `gzip -t` **et** un contrôle de présence des marqueurs `COPY public.companies` / `COPY public.contacts` dans le flux décompressé — 1 h, sans écrire sur le disque. La restauration complète reste le travail de `dr-drill.sh` (cf. F39-003).
- Statut        : ouvert

### [F39-003] L'exercice de restauration n'est déclenché par rien : ni CI, ni cron, ni tâche planifiée
- Sévérité      : **S2**
- Domaine       : exploitation / sauvegarde
- Référence     : `main e8924b8`
- Emplacement   : `infra/scripts/dr-drill.sh` ; `Makefile:140` ; `infra/runbooks/04-restore-dr.md` (dernière ligne : « Test trimestriel obligatoire »)
- Constat       : `dr-drill.sh` n'apparaît dans aucun workflow GitHub autrement que **dans le corps de texte de l'issue d'alerte**, et dans aucun `crontab` de production. Le seul déclencheur est un humain qui tape `make dr-drill`.
- Preuve        :
  ```
  $ grep -rn "dr-drill" .github/workflows/
    surveillance-sauvegarde.yml:175:  './infra/scripts/dr-drill.sh' \   ← texte d'une issue
  $ ssh root@46.62.248.239 'crontab -l'
    0 3 * * * /opt/axion-crm-pro/infra/scripts/backup-postgres.sh >> /var/log/axion-backup.log 2>&1
    (une seule ligne)
  ```
- Témoin négatif: le même `grep` trouve bien les **deux** appels réels à `verifier-sauvegarde.sh` dans le même fichier — il sait distinguer un appel d'une mention.
- Impact        : la seule vérification qui prouve quelque chose (la restauration + les comptages) dépend de la mémoire d'une personne. C'est exactement le mécanisme qui a laissé la sauvegarde échouer **91 fois sur 91**, et c'est le mécanisme que le workflow de surveillance a été écrit pour supprimer — sur l'existence du fichier, mais pas sur sa restaurabilité. Il n'existe par ailleurs **aucune trace archivée** d'un exercice réussi : `_REPORTS/AIPD_2026-08-18.md:688` le dit lui-même — « rien ne prouve qu'il ait été joué ».
- Reproduction  : les deux commandes ci-dessus.
- Correctif     : un workflow GitHub mensuel `workflow_dispatch` + `schedule`, qui joue `dr-drill.sh` sur un runner disposant de la place, et ouvre une issue en cas d'échec — le squelette existe déjà dans `surveillance-sauvegarde.yml`, à recopier. Coût ≈ 3 h. ⚠️ Attention : `dr-drill.sh` compare le dump de 03:00 aux comptages **vivants** de la production ; sur une journée où la prospection ingère, il échouera à tort (`exit 4`). Le correctif doit relever la référence **à l'instant du dump**, pas à l'instant du contrôle.
- Statut        : ouvert

### [F39-004] Les trois scripts de sauvegarde de la copie de travail sont syntaxiquement invalides sous Linux — mais ceux qui tournent en production ne le sont pas
- Sévérité      : **S2**
- Domaine       : exploitation
- Référence     : `main e8924b8` ; production `46.62.248.239`
- Emplacement   : `infra/scripts/{backup-postgres.sh,dr-drill.sh,verifier-sauvegarde.sh,setup-backup.sh}`, `infra/docker/entrypoint-prod.sh`
- Constat       : **approfondissement de A-003, avec une nuance qui change la conclusion.** Les copies de travail portent bien des octets `0x0d` (181, 205, 155, 116, 51) et **échouent `bash -n` sous Linux**. Mais **le blob git est en LF pur (0 octet `0x0d`)**, le déploiement se fait par `git fetch --all --prune` + reset (`deploy-direct-ssh.yml:153`), et **les copies présentes sur le serveur de production sont en LF pur, donc exécutables**. Le danger n'est donc pas « la sauvegarde de production est cassée » — elle tourne, et elle a produit une archive restaurable ce matin. Le danger est le **chemin `scp` depuis la copie de travail**, celui-là même qui a déjà cassé le 2026-08-19 (`verifier-ports-publies.sh: line 39: $'\r': command not found`, cité en tête de `.gitattributes`).
- Preuve        : `04_PREUVES/agent-39/01_fins-de-ligne.txt`
  ```
  # méthode validée sur témoins
  témoin pur LF   : 0 octet 0x0d (attendu 0)
  témoin pur CRLF : 3 octets 0x0d (attendu 3)

  # copie de travail Windows            # blob git HEAD       # serveur de production
  181  backup-postgres.sh                0                     0
  205  dr-drill.sh                       0                     0
  155  verifier-sauvegarde.sh            0                     0
  116  setup-backup.sh                   0                     —
   51  entrypoint-prod.sh                0                     0

  # exécution réelle
  Git Bash (Windows), script CRLF : TEMOIN-EXECUTE          ← TOLÉRÉ, c'est pourquoi personne ne le voit
  Linux,               script CRLF : set: pipefail: invalid option name
  Linux, bash -n dr-drill.sh (copie Windows)          : code=2, « syntax error near unexpected token `|' » l.154
  Linux, bash -n verifier-sauvegarde.sh (copie Win.)  : code=2, « unexpected EOF » l.82
  Linux, bash -n backup-postgres.sh (copie Windows)   : code=2, « unexpected EOF » l.145
  Linux, bash -n backup-postgres.sh DEPUIS LE BLOB GIT: code=0     ← témoin négatif
  ```
- Témoin négatif: le même `bash -n`, dans le même conteneur Linux, rend **code=0** sur la version LF issue du blob git. Le contrôle sait distinguer.
- Impact        : (1) `make dr-drill` depuis le poste Windows **fonctionne** (Git Bash tolère CR) — le défaut est donc parfaitement invisible à celui qui l'exécute ; (2) tout envoi direct d'un de ces scripts vers un hôte Linux (dépannage, nouveau serveur, `scp` en urgence) produit un script inexécutable, **le jour où l'on en a besoin** ; (3) `git config core.autocrlf = true` continue de re-salir chaque fichier réécrit tant que la copie de travail n'est pas renormalisée.
- Reproduction  : `git ls-files '*.sh'` puis, pour chaque fichier, `od -An -tx1 f | tr ' ' '\n' | grep -c '^0d'` ; puis `docker cp f <conteneur-linux>:/tmp/f && docker exec <c> sh -c 'bash -n /tmp/f'`.
- Correctif     : `git add --renormalize .` puis un commit — 15 min. Une garde CI qui refuse tout `.sh` suivi contenant un octet `0x0d` — 30 min. **Sans la garde, `core.autocrlf=true` ramènera le défaut.**
- Statut        : ouvert

### [F39-005] Le correctif qui a rendu la sauvegarde restaurable repose sur une liste de sept noms écrits en dur, sans aucune garde : la huitième fonction rejouera la panne
- Sévérité      : **S1**
- Domaine       : backend / sauvegarde
- Référence     : `main e8924b8` ; production `46.62.248.239`
- Emplacement   : `backend/database/migrations/2026_08_16_200000_fixer_search_path_des_fonctions.php:54-62`
- Constat       : **confirmation mesurée de B10-010, plus la mesure qui manquait.** La migration énumère 7 signatures en dur. En production, il y a **exactement 7 fonctions** dans `public` hors extensions, et **7/7 portent bien `proconfig = {search_path=public, pg_catalog}`** — le correctif est donc effectif *aujourd'hui*, et la restauration que j'ai jouée le prouve. Mais **aucun test, aucune migration, aucune règle CI ne vérifie qu'une fonction nouvelle en hérite** : `grep -rn "proconfig" .` ne rend qu'une occurrence, dans un commentaire de la migration elle-même.
- Preuve        :
  ```
  $ ssh root@… 'docker exec axion-crm-postgres psql -U axion -d axion_crm -c "
      SELECT p.proname, p.proconfig FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
      WHERE n.nspname='public' AND p.prokind='f'
        AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid=p.oid AND d.deptype='e')"'
    7 lignes, toutes avec {"search_path=public, pg_catalog"}

  $ grep -rn "proconfig\|pg_get_function_identity_arguments" --include=*.php --include=*.yml --include=*.sh .
    1 seul résultat : un COMMENTAIRE dans la migration
  ```
  et la restauration du §2 : 0 erreur `function unaccent(text) does not exist`, `companies` et `contacts` restaurées.
- Témoin négatif: la même requête, sur la même base, **trouve** bien les 7 fonctions et leurs valeurs — elle rendrait `proconfig` à `NULL` pour une huitième non traitée. Et l'historique fournit le témoin positif ultime : le 2026-08-16, avec `proconfig` vide, la restauration rendait une base **sans `companies` ni `contacts`**.
- Impact        : la prochaine migration qui crée une fonction SQL référençant un objet non qualifié (`unaccent`, une table, un opérateur PostGIS) réintroduit **exactement** la panne du 2026-08-16 : dump vert, `verifier-sauvegarde.sh` vert (le fichier est gros et récent), et une base non restaurable. Le défaut ne se voit **qu'au moment de restaurer**, c'est-à-dire au pire moment.
- Reproduction  : les deux commandes ci-dessus.
- Correctif     : un test Pest de dix lignes qui échoue si une fonction de `public` hors extension a `proconfig IS NULL` — 30 min, et il rougit immédiatement sur la huitième fonction. Une alternative plus robuste : `ALTER DATABASE axion_crm SET search_path = public, pg_catalog` ne suffit **pas** (`pg_dump` neutralise le `search_path` de session) — c'est bien au niveau de la fonction qu'il faut agir.
- Statut        : ouvert

### [F39-006] Le DSN Sentry est configuré en production, mais aucune exception non rattrapée ne lui parvient : le branchement obligatoire du paquet n'a pas été fait
- Sévérité      : **S1**
- Domaine       : exploitation / observabilité
- Référence     : `main e8924b8` ; production `46.62.248.239`
- Emplacement   : `backend/bootstrap/app.php:44-46` ; `backend/config/logging.php` ; `backend/routes/console.php`
- Constat       : **rectification et extension de B16-006.** Contrairement à ce qui a été écrit, `SENTRY_LARAVEL_DSN` **est présent dans le `.env` de production** (`https://***@o4510557298294784.ingest.de.sentry.io/4511361744175184`) et **est bien vu par le conteneur `axion-crm-api`** ; `sentry/sentry-laravel` 4.27.0 est installé. Seul le `.env.example` l'ignore. Mais le branchement n'est pas fait : `->withExceptions(function (Exceptions $exceptions) { // })` est **vide**, alors que le README du paquet installé impose `Integration::handles($exceptions);` ; et `config/logging.php` ne définit **aucun canal `sentry`**. Les seuls envois sont **8 appels explicites** `\Sentry\captureException()`, tous dans des services de scraping/enrichissement.
- Preuve        : `04_PREUVES/agent-39/03_observabilite-alertes.txt`
  ```
  $ ssh root@… 'docker exec axion-crm-api sh -c "printenv | grep -E \"^SENTRY\""'
    SENTRY_LARAVEL_DSN=https://***@o4510557298294784.ingest.de.sentry.io/4511361744175184
  $ sed -n '44,46p' backend/bootstrap/app.php
        ->withExceptions(function (Exceptions $exceptions) {
            //
        })->create();
  $ sed -n '55,63p' backend/vendor/sentry/sentry-laravel/README.md
        ->withExceptions(function (Exceptions $exceptions) {
            Integration::handles($exceptions);
        })->create();
  $ grep -ci sentry backend/config/logging.php   → 0
  $ grep -rn "Sentry\\captureException" backend/app | wc -l → 8
  ```
- Témoin négatif: le même `grep -ci` **trouve** bien 8 canaux dans `logging.php` (`single`, `daily`, `stderr`, `syslog`, `errorlog`, `null`, `emergency`, `stack`) — il verrait un canal `sentry` s'il existait. Et le même `printenv` **trouve** bien `GLITCHTIP_DSN` (vide) à côté : il ne rate pas une variable.
- Impact        : l'anomalie la plus courante de ce produit — **A-001, toute route `auth:sanctum` qui répond 500 au lieu de 401, en production** — passe par le gestionnaire d'exceptions. Elle ne produit **aucun événement Sentry**. De même pour les ≈ 5,5 erreurs/minute de A-007. Le corollaire tient pour tout le reste : `audit:verify-chain`, la vérification quotidienne de l'intégrité du journal d'audit, écrit `Audit hash chain INVALIDE — possible falsification détectée` sur la sortie standard d'un conteneur et rend `FAILURE` ; son propre code porte le commentaire `// En prod : envoi Slack/Telegram + ouverture incident.` — non implémenté. Et **aucune** des 35 tâches planifiées n'a de `onFailure` / `emailOutputOnFailure` / `pingOnFailure` (`grep` → 0 dans tout `backend/`). Une falsification du journal d'audit **n'avertirait personne**.
- Reproduction  : les cinq commandes ci-dessus.
- Correctif     : (1) une ligne — `Integration::handles($exceptions);` dans `withExceptions` — 5 min, mais ⚠️ **à ne pas poser avant d'avoir corrigé A-001** : ≈ 8 000 événements/jour videraient le quota Sentry en quelques heures. (2) `->onFailure(fn () => …)` sur `audit:verify-chain` au minimum — 1 h. (3) ajouter `SENTRY_LARAVEL_DSN=` à `.env.example` — 2 min.
- Statut        : ouvert

### [F39-007] Sept des huit rubriques du résumé d'observabilité renvoient zéro en avalant l'exception : « rien à signaler » et « je n'ai pas pu regarder » y ont la même apparence
- Sévérité      : **S2**
- Domaine       : backend / observabilité
- Référence     : `main e8924b8`
- Emplacement   : `backend/app/Http/Controllers/Api/ObservabilityController.php:56-217`
- Constat       : `siteSyncReceptions`, `outboundBacklog`, `googlePlacesQuotaSummary`, `countHunterMonth`, `countAudienceFailures7d`, `recentBusinessEvents` — et implicitement `countArchiveReasons` — enveloppent leur requête dans un `try { … } catch (\Throwable $e) { return 0 / [] / null; }` **sans journaliser quoi que ce soit**. Seul `countWaterfallErrors24h` n'a pas de filet.
- Preuve        : lecture du contrôleur (7 blocs `catch (\Throwable $e)` retournant une valeur neutre) ; et les données réelles de production, qui montrent que les chiffres **ne sont pas structurellement nuls** :
  ```
  business_events = 1 286 229   scraper_runs = 7 608 196   activities = 649
  crm_outbound_events = 0       email_verification_logs = 0   audit_logs = 64
  ```
- Témoin négatif: la même méthode `recentBusinessEvents` rend bien 50 lignes réelles quand la table répond — le zéro n'est donc pas un artefact de lecture, c'est un choix de code.
- Impact        : une table absente, un droit retiré, une RLS mal posée (le produit en pose : `CRM_DB_APP_ROLE_ENABLED=true` en production, `false` en local, cf. B11-010) rendent **exactement le même écran vert** qu'une situation saine. Un tableau de bord d'observabilité qui ne sait pas dire « je n'ai pas pu mesurer » est le contraire d'une observabilité.
- Reproduction  : lire les 7 blocs `catch` ; comparer avec `countWaterfallErrors24h`, qui n'en a pas.
- Correctif     : conserver le filet (l'écran ne doit pas tomber) mais **journaliser l'exception** et renvoyer `null` plutôt que `0`, l'écran affichant alors « non mesurable » — 2 h, front compris.
- Statut        : ouvert

### [F39-008] Le runbook de restauration décrit un dispositif qui n'existe pas — S3, WAL streaming, Backblaze B2, `s3cmd`, PITR
- Sévérité      : **S1**
- Domaine       : exploitation / documents
- Référence     : `main e8924b8`
- Emplacement   : `infra/runbooks/04-restore-dr.md`
- Constat       : le runbook annonce deux sources de sauvegarde — « Hetzner Object Storage, chaque heure full + WAL streaming » et « Backblaze B2, réplication toutes les 6 h » — et donne une procédure à base de `s3cmd get s3://axion-crm-backups/…`, `pg_basebackup` et `recovery_target_time`. **Le dispositif réel est un `pg_dump` quotidien à 03:00 UTC envoyé par SFTP sur une Storage Box Hetzner.** Il n'y a ni S3, ni B2, ni WAL, ni PITR, et `s3cmd` n'est pas installé sur le serveur.
- Preuve        : `04_PREUVES/agent-39/02_sauvegardes-production.txt` (cron, `/var/backups/axion-crm`, inventaire SFTP) ; et l'en-tête de `infra/scripts/dr-drill.sh:9-18`, qui documente **la même erreur** dans la version précédente du script : « elle lisait `s3cmd ls s3://axion-crm-backups/` […] `s3cmd` n'est pas installé sur le serveur ».
- Témoin négatif: le script `dr-drill.sh` **a été corrigé** de ce défaut précis le 2026-08-16, et son en-tête le raconte. Le runbook, lui, ne l'a pas été : la preuve que le contrôle savait voir le problème est que quelqu'un l'a déjà vu — ailleurs.
- Impact        : c'est le document qu'on ouvre le jour où la production est perdue. Il envoie chercher des sauvegardes horaires qui n'existent pas, sur un stockage qui n'existe pas, avec un outil qui n'est pas installé. Il promet aussi un **RPO de 1 h** que le dispositif réel ne peut pas tenir (cf. F39-009). Le temps perdu à ce moment-là se paie en indisponibilité.
- Reproduction  : lire `infra/runbooks/04-restore-dr.md` ; puis `ssh root@46.62.248.239 'crontab -l; ls /var/backups/axion-crm; command -v s3cmd'`.
- Correctif     : réécrire le runbook sur le dispositif réel — il suffit de décrire ce que fait déjà `dr-drill.sh`, qui est juste. Compter 2 h. Y inscrire les valeurs mesurées du §3 plutôt que des cibles.
- Statut        : ouvert

### [F39-009] Le RPO annoncé est faux d'un facteur 24, et la profondeur réelle des sauvegardes est de trois jours, pas trente
- Sévérité      : **S2**
- Domaine       : exploitation / sauvegarde
- Référence     : `main e8924b8` ; production `46.62.248.239` au 2026-08-19 12:00 UTC
- Emplacement   : `Makefile:140` (« DR drill (RPO ≤ 1h, RTO ≤ 4h) ») ; `infra/runbooks/04-restore-dr.md:3` ; à comparer à `infra/scripts/dr-drill.sh:60` (`RPO_CIBLE_S=129600`, soit 36 h)
- Constat       : la sauvegarde est **quotidienne** (`0 3 * * *`). Le RPO réel est donc de **24 h**, et non de 1 h. Le script appelé par la cible `dr-drill` du `Makefile` code lui-même une tolérance de **36 h** : le `Makefile` et le script qu'il lance annoncent deux valeurs différentes, toutes deux différentes du dispositif. Par ailleurs, la rétention distante est réglée à 30 jours, mais **l'archive la plus ancienne date du 2026-08-16** : la profondeur réelle de récupération est de **3 jours**, parce que le dispositif ne produisait rien avant cette date.
- Preuve        : `04_PREUVES/agent-39/02_sauvegardes-production.txt`
  ```
  $ crontab -l  →  0 3 * * * …/backup-postgres.sh >> /var/log/axion-backup.log 2>&1
  $ ls -l /home/axion-crm-backups (Storage Box, SFTP)
    axion_crm_20260816T181456Z.sql.gz … axion_crm_20260819T030001Z.sql.gz   (6 archives, du 16 au 19)
  $ df -h (Storage Box) → 1.0 TB, 4.0 GB utilisés
  ```
- Témoin négatif: la Storage Box a **1 To** et n'est remplie qu'à 0,4 % : la faible profondeur n'est pas un manque de place, c'est un manque d'historique.
- Impact        : une donnée corrompue ou supprimée par erreur il y a plus de trois jours **n'est pas récupérable**, alors que deux documents promettent trente jours. Et une perte de la base coûte jusqu'à **une journée entière** de prospection, pas une heure.
- Reproduction  : les trois commandes ci-dessus.
- Correctif     : (1) corriger les deux documents pour y écrire 24 h / 3 j — 15 min ; (2) si un RPO d'une heure est réellement voulu, il faut de l'archivage WAL continu, ce qui est un chantier (≈ 3 à 5 j) et **doit être décidé, pas supposé** ; (3) la profondeur se rétablira d'elle-même d'ici le 2026-09-15 si le cron continue de tourner — c'est justement ce que F39-002 et F39-003 doivent garantir.
- Statut        : ouvert

### [F39-010] Le script de restauration a pour cible par défaut la base de production
- Sévérité      : **S2**
- Domaine       : exploitation / sécurité des données
- Référence     : `main e8924b8`
- Emplacement   : `infra/scripts/restore-postgres.sh:18`
- Constat       : `TARGET_DB="${2:-axion_crm}"`. Lancé sans deuxième argument — `bash restore-postgres.sh /var/backups/axion-crm/dernier.sql.gz` — sur le serveur de production, le script écrase la base de production, le dump portant `--clean --if-exists`.
- Preuve        : lecture du script, lignes 15-40 ; et l'en-tête de `backup-postgres.sh:102` confirme les options `--clean --if-exists` du dump.
- Témoin négatif: `dr-drill.sh`, écrit plus tard, ne commet pas cette faute : sa base cible est `BASE_DRILL="${BASE_DRILL:-axion_crm_dr}"`, un nom qui ne peut pas être celui de la production. Le bon patron existe donc déjà dans le même répertoire.
- Impact        : un geste d'exploitation en situation de stress — restaurer « la dernière sauvegarde » — détruit la base vivante si l'opérateur oublie un argument. Le mode de défaillance est silencieux : le script affiche « Restore complet. DB axion_crm prête. »
- Reproduction  : lire la ligne 18 ; comparer à `dr-drill.sh:53`.
- Correctif     : rendre le deuxième argument **obligatoire**, et refuser explicitement la valeur `axion_crm` sans un `--je-sais-ce-que-je-fais` — 20 min.
- Statut        : ouvert

### [F39-011] Le disque de production se remplit de 511 Mio par jour et sature vers le 6 octobre 2026 ; aucune garde ne regarde cette trajectoire
- Sévérité      : **S1**
- Domaine       : exploitation
- Référence     : production `46.62.248.239`, mesuré en lecture seule le 2026-08-19 entre 11:11 et 12:05 UTC
- Emplacement   : `/dev/sda1` (75 Go) ; `infra/monitoring/prometheus/alerts.yml` ; `/etc/docker/daemon.json` (absent) ; `backend/config/logging.php`
- Constat       : **prolongement chiffré de A-007 et du constat de l'agent 40.** Le disque a **24,25 Gio libres** et se remplit à **511 Mio/jour** : il sature dans **48 jours**, soit vers le **2026-10-06**. Aucune alerte disque n'existe : `grep -c DiskSpaceLow infra/monitoring/prometheus/alerts.yml` rend **0**, alors que `infra/runbooks/02-disk-full.md` cite cette alerte comme **symptôme d'entrée** du runbook. Et il n'y a aucune règle mentionnant `disk`, `filesystem` ou `node_` dans tout `alerts.yml`.
- Preuve        : `04_PREUVES/agent-39/04_trajectoire-disque.txt`
  ```
  # fenêtre 900 s (13:35 → 13:50 heure locale, 11:35 → 11:50 UTC)
  libre 25 425 224 Kio → 25 419 772 Kio ; delta 5 452 Kio
  → 511 Mio/j ; 48 jours avant saturation

  # fenêtre 180 s, contrôle indépendant
  → 493 Mio/j ; 50 jours          ← les deux fenêtres concordent

  # décomposition, même fenêtre 180 s
  laravel.log                             : 179 627 o /180 s →  82 Mio/j
  /var/lib/docker/containers (json-file)  : 185 474 o /180 s →  84 Mio/j
  → 166 Mio/j de journaux, soit 32 % de la consommation

  # bornes
  /etc/systemd/journald.conf : [Journal] seul → défauts ; journalctl --disk-usage = 3.9 G (plafond 4 G, stable)
  /etc/docker/daemon.json    : No such file or directory  → journaux de conteneurs NON bornés
  LOG_* du .env de prod      : LOG_CHANNEL=stack, LOG_LEVEL=debug, LOG_STACK absent
                               → défaut config/logging.php = 'single,stderr'
                               → le même flot est écrit DEUX fois (fichier + journal du conteneur)
  /var/backups/axion-crm     : 4,1 Go, sur le disque même qu'il sauvegarde
  ```
- Témoin négatif: le même `grep -in "disk\|filesystem\|node_"` sur `alerts.yml` **trouve** bien les 8 règles (`ApiDown`, `PostgresDown`, `RedisDown`, `HorizonQueueBacklog`, `ApiLatencyP95High`, `ScrapingFailureRateHigh`, `LLMCostNearCap`, `EmailValidationInvalidRateHigh`…) — il verrait une règle disque si elle existait. Et `journalctl --disk-usage` prouve que le journal systemd, lui, **est** borné à 4 Go et n'entre pas dans la trajectoire : le problème n'est pas partout, il est là où rien ne borne.
- Impact        : à disque plein, Postgres refuse toute écriture et le service s'arrête. La date est dans **sept semaines**. `journald` est plafonné et ne bougera plus ; ce qui monte est (a) la croissance légitime de `postgres-data` (18,21 Go) et (b) **166 Mio/jour de journaux que personne ne lit et que rien ne borne** — dont A-007 a montré que **100 % des entrées sont la même erreur Telescope**. Le nettoyage de A-007 rendrait à lui seul ≈ 1/3 de la trajectoire. Et si le disque se remplit, la **sauvegarde s'arrête aussi** : `/var/backups/axion-crm` est sur le même volume, et c'est le mode de défaillance que le workflow de surveillance liste explicitement (« `df -h /` — un disque plein arrête le dump sans bruit »).
- Reproduction  : `ssh root@46.62.248.239` puis mesurer `df -k /` à 900 s d'intervalle ; `du -sh /var/lib/docker /var/backups /var/log /opt` ; `cat /etc/docker/daemon.json` ; `grep -c DiskSpaceLow infra/monitoring/prometheus/alerts.yml`.
- Correctif     : par ordre de rendement. (1) `TELESCOPE_ENABLED=false` (A-007) — supprime l'essentiel des 166 Mio/j, 5 min. (2) `/etc/docker/daemon.json` avec `log-opts max-size=50m, max-file=3` puis redémarrage du démon — 15 min, borne définitivement les journaux de conteneurs. (3) `LOG_CHANNEL=daily` + `LOG_LEVEL=warning` en production — 10 min. (4) Une garde qui **crie** : le patron de `surveillance-sauvegarde.yml` s'applique tel quel — un workflow GitHub quotidien qui lit `df` par SSH et ouvre une issue sous 20 % libres. ≈ 2 h, et c'est la seule des quatre qui empêche la prochaine trajectoire silencieuse.
- Statut        : ouvert

---

## 6. Ce que je n'ai PAS pu vérifier, et pourquoi

1. **La restaurabilité de la copie HORS-SITE.** Comparer son empreinte à celle de la copie locale
   exige d'écrire ≈ 692 Mio sur le disque de production (c'est ce que fait `dr-drill.sh`, dans
   `/tmp`). Mon mandat l'interdit. J'ai tenté un contournement en flux vers `/dev/stdout` :
   il a rendu l'empreinte de la **chaîne vide** (`e3b0c442…`), donc **il n'a rien mesuré** — je
   ne conclus rien de ce résultat. Ce que j'ai pu établir : les deux copies ont **exactement la
   même taille en octets** (724 926 343), et la copie locale est restaurable (§2).
   **Ce qu'il faudrait** : jouer `dr-drill.sh` avec l'accord de Will, ou disposer d'un second
   hôte capable de télécharger la copie hors-site.
2. **`GET /observability/summary` appelé pour de vrai.** L'appel exige un jeton `auth:sanctum`.
   En obtenir un en local impose d'écrire dans `axion_crm` (base de travail partagée), et
   l'atelier sert l'API par `php -S` mono-processus déjà saturé (A-009). En production, je n'ai
   pas de compte et je n'en créerai pas. J'ai donc analysé le contrôleur **et** relevé en
   lecture seule les comptages de production qui l'alimentent (§5, F39-007). **Ce qu'il
   faudrait** : un jeton de lecture de préproduction.
3. **Le fait qu'aucun événement n'arrive réellement à Sentry.** Le prouver demanderait de
   provoquer une erreur en production (interdit) ou d'avoir accès à la console Sentry du projet
   `o4510557298294784`. Ma démonstration est donc **statique mais complète** : DSN présent, paquet
   installé, `withExceptions` vide, aucun canal de log `sentry`, README du paquet exigeant le
   branchement. **Ce qu'il faudrait** : ouvrir la console Sentry et regarder si des événements
   autres que les 8 `captureException` explicites y figurent.
4. **La date de saturation au-delà de l'ordre de grandeur.** Les deux fenêtres (180 s et 900 s)
   concordent à 4 % près, mais elles mesurent une journée de faible activité de prospection.
   Un mois d'ingestion soutenue rapprocherait la date ; un nettoyage de A-007 la repousserait
   d'environ un tiers. **Ce qu'il faudrait** : une mesure sur 24 h, que seule une garde
   permanente peut donner — et c'est précisément ce qui manque.
5. **La reproduction du défaut CRLF sur la production elle-même.** Y déposer un script témoin est
   une écriture. J'ai donc reproduit le comportement dans un conteneur Linux local, avec témoin
   positif (LF) et témoin négatif (le même fichier depuis le blob git). La conclusion sur la
   production repose sur une mesure directe des octets des fichiers **présents** sur le serveur :
   0 octet `0x0d`.
6. **Les journaux d'exécution du cron avant le 2026-08-16.** `/var/log/axion-backup.log` ne
   contient plus que la période post-correctif ; je ne peux ni confirmer ni infirmer par
   moi-même les « 91 échecs sur 91 » rapportés par les documents.
