# Runbook — Restauration disaster recovery

> ## 🔴 CE DOCUMENT A ÉTÉ RÉÉCRIT LE 2026-08-20. LIRE CE CADRE D'ABORD.
>
> Jusqu'à cette date, ce runbook décrivait une reprise **qui ne correspondait à
> aucune sauvegarde existante**. Il prescrivait un outil de récupération objet
> qui **n'est pas installé sur le serveur**, un rapatriement depuis un dépôt
> distant qui n'a jamais reçu une seule archive, une reprise à un instant
> arbitraire adossée à un archivage continu des journaux de transaction qui
> n'est configuré nulle part (`archive_mode` et `archive_command` n'apparaissent
> dans aucun fichier du dépôt), et un second hébergeur hors-site qui n'existe
> pas. Il annonçait une sauvegarde toutes les heures. Et il ne nommait **jamais**
> le seul chemin de restauration qui fonctionne.
>
> Le même défaut avait déjà été trouvé et réparé le 2026-08-16 dans
> `infra/scripts/dr-drill.sh` — dont l'en-tête le raconte encore. **Le correctif
> n'avait pas été porté ici.** C'est le document que quelqu'un suit à 3 heures
> du matin, après un sinistre, sans le temps de vérifier.
>
> Garde : `backend/tests/Feature/Infra/RunbookRestaurationDrTest.php`. Elle
> vérifie que ce runbook ne nomme **que** des outils invoqués ailleurs dans le
> dépôt, et **que** des chemins qui existent.

**Cible affichée :** RPO ≤ 1 h, RTO ≤ 4 h.

⚠️ **La cible RPO n'est PAS tenue, et il faut le savoir avant de promettre quoi
que ce soit à qui que ce soit.** La sauvegarde qui existe est **quotidienne**
(cron 03:00 UTC). La perte maximale réelle est donc de **24 heures**, pas d'une
heure. `infra/scripts/dr-drill.sh` a d'ailleurs été réaligné sur cette réalité
le 2026-08-16 : il tolère 36 h (`RPO_CIBLE_S=129600`), le temps d'absorber un
décalage d'exécution sans crier au loup.

**Le RTO, lui, est tenu et mesuré :** 21 min pour 16 Go le 2026-08-16, pour une
cible de 4 h.

Fermer l'écart de RPO suppose de mettre en place un archivage continu des
journaux de transaction — ce qui est un chantier, pas une ligne de runbook. Tant
qu'il n'est pas fait, **ce document annonce 24 h**.

> ## 🔴 AVANT TOUTE COMMANDE DE CE RUNBOOK — l'overlay de production
>
> **Sur l'hôte de production, exporte ceci une fois, dans le shell d'où tu joues
> ce runbook :**
>
> ```bash
> cd /opt/axion-crm-pro
> export COMPOSE_FILE="docker-compose.yml:docker-compose.prod.yml"
> ```
>
> **Pourquoi ce n'est pas un détail.** `docker-compose.yml` publie Postgres sur
> `55432` et Redis sur `56379` — le confort du poste de développement. C'est
> l'overlay `docker-compose.prod.yml` qui retire ces publications, avec
> `ports: !override []`.
>
> Un `docker compose up -d` lancé **sans** l'overlay repart du seul fichier de
> base : Compose voit une configuration différente de celle des conteneurs en
> place, et **il les recrée — ports compris**. Ces deux ports ont été trouvés
> ouverts depuis l'extérieur le 2026-08-19, et Redis n'a **aucun mot de passe**.
>
> Et le piège se referme même si tu ne nommes pas la base : `api`, `horizon` et
> `scheduler` portent tous `depends_on: [postgres, redis]`, et Compose monte les
> dépendances sauf si on lui dit `--no-deps`.
>
> Constat `F38-007` (S0). Un runbook qui prescrit le défaut le reproduira aussi
> sûrement qu'un script qui l'exécute.

---

## 0. Ce qui existe réellement

Une seule chaîne, cinq fichiers. **Lis-les si tu doutes d'une ligne de ce
document** — eux font foi, pas ce texte.

| Ce que ça fait | Fichier |
| --- | --- |
| Produit l'archive et l'envoie hors-site | `infra/scripts/backup-postgres.sh` |
| **Restaure une archive** | `infra/scripts/restore-postgres.sh` |
| Répond à « la sauvegarde a-t-elle eu lieu ? » | `infra/scripts/verifier-sauvegarde.sh` |
| Exercice de restauration complet | `infra/scripts/dr-drill.sh` |
| Pose le cron et le dossier distant (une fois) | `infra/scripts/setup-backup.sh` |

**La forme de la sauvegarde**, telle que `backup-postgres.sh` la produit :

- un `pg_dump` **plain-text gzippé**, une fois par jour à **03:00 UTC** ;
- environ **692 Mo** compressés, pour une base d'environ **16 Go** ;
- nommé `axion_crm_<AAAAMMJJ>T<HHMMSS>Z.sql.gz` — horodatage UTC, donc triable
  dans l'ordre alphabétique, et les scripts s'en servent ;
- **copie locale** : `/var/backups/axion-crm`, rétention 7 jours ;
- **copie hors-site** : Storage Box Hetzner `u595329.your-storagebox.de`, port
  **23**, dossier `/home/axion-crm-backups`, transfert **SFTP** via `sshpass`.
  Rétention 30 jours. C'est la **seule** copie hors-site : il n'y en a pas de
  deuxième, et aucune réplication vers un autre hébergeur.

**Ce que l'archive contient**, dans cet ordre — trois sections, et la deuxième
est la plus récente et la moins évidente :

1. neuf `CREATE EXTENSION IF NOT EXISTS` (dont `postgis` et `vector`) ;
2. **les rôles du cluster** (`pg_dumpall --globals-only`), encadrés par deux
   marqueurs textuels `-- >>> AXION-GLOBALS-DEBUT` / `-- >>> AXION-GLOBALS-FIN` ;
3. le schéma, les données **et les `GRANT`**.

> ⚠️ **Une archive antérieure au 2026-08-20 n'a ni la section 2 ni les `GRANT`.**
> Constat A08-008 : `pg_dump` était appelé avec `--no-acl`, et rien n'appelait
> `pg_dumpall`. Restaurée, elle rend une base pleine et une application
> **aveugle** — le rôle applicatif `axion_app` est non-propriétaire, sans `GRANT`
> il ne lit rien, et sans la section des rôles il n'existe même pas.
> `restore-postgres.sh` le détecte et sort en **code 6**. Si tu tombes dessus,
> saute au §7.3.

**La surveillance :** `.github/workflows/surveillance-sauvegarde.yml` interroge
la Storage Box tous les jours à 05:00 UTC et ouvre une issue si l'archive
manque, est trop vieille (> 36 h) ou trop petite (< 100 Mo). Il tourne **depuis
GitHub, pas depuis le serveur** — une surveillance hébergée sur la machine
qu'elle surveille se tait exactement quand elle devrait crier.

---

## 1. Première question : de quand date la dernière sauvegarde ?

**Avant de reconstruire quoi que ce soit.** Ça décide de tout le reste, et ça
prend trente secondes.

Si le serveur répond encore :

```bash
bash /opt/axion-crm-pro/infra/scripts/verifier-sauvegarde.sh
```

Le script interroge la **copie hors-site** — pas la locale — et dit son âge et
sa taille. Sortie 0 = elle est là, récente et plausible. Sortie 1 = le message
dit lequel des deux contrôles a lâché, et si l'écart porte sur l'envoi ou sur le
dump lui-même.

Si le serveur est perdu, interroge la Storage Box directement depuis n'importe
quelle machine ayant `sshpass` et le mot de passe (variable `SB_PASSWORD` du
`.env` de production) :

```bash
sshpass -p "$SB_PASSWORD" sftp -P 23 -o StrictHostKeyChecking=accept-new u595329@u595329.your-storagebox.de
```

Une fois la session ouverte : `cd /home/axion-crm-backups` puis `ls -1`.

> ⚠️ **`ls -1`, jamais `ls`.** `ls` affiche en colonnes complétées d'espaces.
> C'est exactement ce qui a cassé la rotation distante **en silence** pendant
> trois mois (#139) : aucune ligne ne se terminait par `.sql.gz`, le filtre ne
> retenait rien, et pas un seul fichier n'a jamais été supprimé.

**Le nom porte la date.** `axion_crm_20260820T030000Z.sql.gz` = 20 août 2026,
03:00 UTC. C'est ça, ton point de reprise : il n'y en a pas d'autre, et **on ne
peut pas rejouer les transactions postérieures.**

> ⚠️ **Une archive présente n'est pas une sauvegarde.** Pendant trois mois, la
> Storage Box a hébergé un `axion_crm_*.sql.gz` de **18 497 octets** — 91
> exécutions du cron, 91 échecs. **Regarde la taille** : elle doit être de
> l'ordre de 690 Mo, pas de 18 Ko.

---

## 2. Rapatrier l'archive

```bash
mkdir -p /tmp/restore
sshpass -p "$SB_PASSWORD" scp -P 23 -o StrictHostKeyChecking=accept-new u595329@u595329.your-storagebox.de:/home/axion-crm-backups/axion_crm_20260820T030000Z.sql.gz /tmp/restore/
```

Puis **vérifie que le transfert n'a pas tronqué le fichier**. Si la copie locale
du serveur existe encore, compare les empreintes ; sinon, contrôle au moins que
l'archive se déroule jusqu'au bout :

```bash
sha256sum /tmp/restore/axion_crm_20260820T030000Z.sql.gz
gunzip -t /tmp/restore/axion_crm_20260820T030000Z.sql.gz
```

C'est ce que fait `infra/scripts/dr-drill.sh` à son étape 1, et c'est le seul
moyen de distinguer « archive corrompue » de « restauration ratée » — deux
diagnostics très différents à 3 heures du matin.

---

## 3. Remonter la pile

Si le serveur est perdu, il faut d'abord une machine. Le dépôt décrit deux
chemins, et **aucun des deux n'est un raccourci d'une ligne** :

- `infra/terraform/` — l'infrastructure déclarée : réseau, pare-feu, serveurs,
  IP flottante, enregistrements DNS Cloudflare. C'est la source de vérité de la
  topologie ;
- `infra/scripts/setup-hetzner-cpx22.sh` — le chemin réellement joué en
  production sur une Ubuntu 24.04 neuve : Docker, pare-feu, clone du dépôt,
  génération du `.env`, montée de la pile, migrations.

Une fois la machine prête, dans `/opt/axion-crm-pro` :

```bash
cd /opt/axion-crm-pro
export COMPOSE_FILE="docker-compose.yml:docker-compose.prod.yml"
docker compose up -d postgres
```

> 🔴 **La machine est NEUVE : aucun shell n'y a l'export du préambule.** Il doit
> être refait **ici**, sinon la reprise après sinistre remonte la pile avec
> Postgres et Redis publiés sur internet — en urgence, et sans que personne ne
> le regarde. C'est le pire moment pour rouvrir `F38-007`.

> ⚠️ **L'image Postgres n'est pas une image standard.** C'est
> `ghcr.io/will383842/axion-crm-pro-postgres:16-3.5-vector-partman`, avec
> PostGIS, `pgvector` et `pg_partman` — cf. `.github/workflows/build-postgres-image.yml`.
> Une restauration dans une image standard meurt au premier `CREATE EXTENSION` :
> c'est l'erreur exacte que l'ancien `dr-drill.sh` commettait, découverte le
> 2026-08-16 (`function unaccent(text) does not exist`).

Il faut aussi de la **place** : la base restaurée pèse ~16 Go, et
`infra/scripts/dr-drill.sh` refuse de tourner sous 25 Go libres sur le volume de
données du conteneur. Vérifie avant, pas au milieu :

```bash
docker exec axion-crm-postgres sh -c 'df -h /var/lib/postgresql/data'
```

---

## 4. Restaurer Postgres

**C'est ici que passe tout le travail, et il tient en une commande.**

```bash
bash /opt/axion-crm-pro/infra/scripts/restore-postgres.sh /tmp/restore/axion_crm_20260820T030000Z.sql.gz axion_crm
```

Le script fait cinq étapes, et **chacune est là parce qu'elle a manqué un jour** :

1. crée la base si elle n'existe pas — elle n'est pas dans l'archive, c'est
   voulu ;
2. applique **les rôles du cluster** — la section entre marqueurs — à part et
   **hors** `ON_ERROR_STOP` : sur un cluster où `axion` existe déjà,
   `CREATE ROLE axion;` est une erreur attendue et bénigne ;
3. déroule la charge utile en `--single-transaction -v ON_ERROR_STOP=1`, la
   section des rôles retirée du flux ;
4. compte les tables du schéma `public` — moins de 10 ⇒ échec ;
5. **interroge les droits du rôle applicatif** avec `has_table_privilege`.

**Codes de sortie :** `1` = usage ou restauration ratée · **`6` = les données
sont là mais les droits manquent.** Un `6` n'est pas un échec de restauration :
c'est une archive trop ancienne. Va au §7.3.

> ⚠️ **Ne restaure pas « à la main » avec un tube `zcat | psql`.** Tu perdrais
> les deux choses que ce script fait et qu'un tube ne fait pas : appliquer la
> section des rôles séparément, et vérifier les droits à la fin. Une base
> restaurée sans un seul `GRANT` porte exactement le même nombre de tables
> qu'une base saine — un contrôle qui ne peut pas échouer sur le défaut est pire
> que pas de contrôle : il rassure.

Puis remonte le reste de la pile :

```bash
cd /opt/axion-crm-pro
export COMPOSE_FILE="docker-compose.yml:docker-compose.prod.yml"
docker compose up -d
```

---

## 5. Redis (cache + files)

**Redis n'est pas restauré, et c'est délibéré** : il est volatil par
construction, on laisse le cache se reconstituer.

Ce qui est **perdu pour de bon**, en revanche, et qu'il faut annoncer :

- les `magic_links` actifs — les utilisateurs devront en redemander un ;
- les `email_validations` non expirées.

Prévois un message aux utilisateurs. Ce n'est pas une gêne technique : c'est la
seule chose qu'ils verront de tout ce runbook.

---

## 6. Réindexer et réchauffer

```bash
docker exec axion-crm-api php artisan coverage:refresh-matrix --concurrent
docker exec axion-crm-api php artisan cache:clear
```

---

## 7. Vérification post-restauration

### 7.1 — La chaîne d'audit

```bash
docker exec axion-crm-api php artisan audit:verify-chain
```

### 7.2 — L'isolation par workspace mord-elle encore ?

> ## 🔴 CE CONTRÔLE SE JOUE AVEC `axion_app`, JAMAIS AVEC `axion`.
>
> **Ce runbook prescrivait ici `psql -U axion` et annonçait « doit retourner 0 ».
> C'était faux.** Mesure du 2026-08-20 sur le cluster :
>
> ```
> SELECT rolname, rolsuper, rolbypassrls FROM pg_roles WHERE rolcanlogin;
>  axion      | t | t
>  axion_app  | f | f
> ```
>
> `axion` est **superutilisateur** et porte **BYPASSRLS** : il ignore la Row
> Level Security, y compris le `FORCE ROW LEVEL SECURITY` posé par la migration
> `2026_08_14_000001_harden_workspace_isolation`. Le comptage annoncé « doit
> retourner 0 » retourne **toute la table**.
>
> **Ce que ça coûtait :** l'opérateur, après un sinistre, lit un chiffre non nul,
> conclut que l'isolation multi-tenant est cassée, et part en chasse au fantôme
> la nuit où il a le moins de temps.
>
> C'est le même défaut que `infra/scripts/dr-drill.sh` portait à son étape 4 —
> comptages joués en `-U axion` —, corrigé le 2026-08-20 par le constat A08-008.
> **Encore un site jumeau non porté.**

```bash
docker exec axion-crm-postgres psql -U axion_app -d axion_crm -c "SET app.current_workspace_id = '00000000-0000-0000-0000-000000000000'; SELECT COUNT(*) FROM companies;"
```

Attendu : **zéro ligne**. Le workspace nul n'existe pas, la policy stricte n'a
aucune ligne à laisser passer, et `axion_app` y est soumis.

Si un mot de passe est demandé, c'est celui de `DB_APP_PASSWORD` dans le `.env`.
Si le rôle n'existe pas, l'archive est antérieure au correctif A08-008 : §7.3.

### 7.3 — Le rôle applicatif peut-il lire ?

C'est la question que `restore-postgres.sh` pose à son étape 5. Pour la reposer
seul, ou après un `GRANT` manuel :

```bash
docker exec axion-crm-postgres psql -U axion -d axion_crm -tAc "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind IN ('r','p') AND NOT has_table_privilege('axion_app', c.oid, 'SELECT')"
```

Attendu : **aucune table illisible**.

> Ici `-U axion` est **légitime**, et la différence avec le §7.2 est tout le
> sujet : on n'interroge pas des données, on interroge le catalogue avec
> `has_table_privilege`, qui répond sur les droits **d'un autre rôle**. La
> réponse ne dépend ni de qui pose la question, ni de la RLS.

**S'il reste des tables illisibles** — ou si `restore-postgres.sh` est sorti en
code 6 —, l'archive a été produite avec `--no-acl`. Remède immédiat, en tant que
propriétaire :

```bash
docker exec axion-crm-postgres psql -U axion -d axion_crm -c "GRANT USAGE ON SCHEMA public TO axion_app; GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO axion_app; GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO axion_app; ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO axion_app;"
```

Puis **rejoue 7.3**, et seulement ensuite ouvre le service.

### 7.4 — Les ports de la base ne sont pas publiés

Le sinistre est le moment où l'on remonte la pile vite. C'est aussi celui où
`F38-007` se rouvre sans témoin.

```bash
bash /opt/axion-crm-pro/infra/scripts/verifier-ports-publies.sh
```

---

## 8. Bascule DNS

Le DNS est **déclaré**, pas piloté à la main : `infra/terraform/main.tf` porte
les enregistrements `A` proxifiés de la racine et de `api`, tous deux pointés
sur l'IP flottante de l'edge.

Deux chemins, dans cet ordre de préférence :

1. **réattacher l'IP flottante** à la nouvelle machine — le DNS pointe déjà
   dessus, il n'y a alors rien à changer et aucune propagation à attendre ;
2. sinon, modifier l'enregistrement — par la console Cloudflare en urgence, puis
   **reporter le changement dans `infra/terraform/main.tf`**, sans quoi la
   prochaine application du plan le défera.

> ⚠️ Ce runbook prescrivait ici un utilitaire en ligne de commande **qui
> n'existe nulle part dans le dépôt**. Il ne pouvait produire qu'un
> « command not found », au pire moment possible.

---

## 9. Remettre la sauvegarde en marche

**Une reprise n'est pas finie tant que la nouvelle machine ne sauvegarde pas.**
C'est le piège classique : le service est debout, tout le monde souffle, et il
n'existe plus aucune copie depuis douze heures.

```bash
grep -c '^SB_' /opt/axion-crm-pro/.env
bash /opt/axion-crm-pro/infra/scripts/setup-backup.sh
crontab -l
```

`setup-backup.sh` vérifie `sshpass` et `SB_PASSWORD`, teste l'accès à la Storage
Box, crée le dossier distant, **lance une première sauvegarde**, puis installe le
cron quotidien de 03:00 UTC vers `/var/log/axion-backup.log`.

Le lendemain, confirme :

```bash
bash /opt/axion-crm-pro/infra/scripts/verifier-sauvegarde.sh
tail -50 /var/log/axion-backup.log
```

---

## 10. Exercice trimestriel

```bash
bash /opt/axion-crm-pro/infra/scripts/dr-drill.sh
```

Il rapatrie la copie hors-site, compare son empreinte à la copie locale,
restaure dans une base jetable, compare les comptages à la production, vérifie
les droits du rôle applicatif et mesure le RTO. Il refuse de tourner sous 25 Go
libres — **il ne se joue pas sur le serveur de production**, dont il ne reste
que 14 Go. `--no-cleanup` conserve la base restaurée si tu veux l'inspecter.

> ⚠️ **L'exercice est la seule chose qui prouve quoi que ce soit.** Ce runbook a
> décrit pendant des mois une infrastructure imaginaire, et personne ne s'en est
> aperçu parce que personne n'avait essayé de le suivre. Le jour où on l'a
> essayé — le 2026-08-16 — on a découvert du même coup que la sauvegarde de
> production n'avait **jamais** tourné.
