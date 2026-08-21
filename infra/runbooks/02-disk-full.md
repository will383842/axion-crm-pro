# Runbook — Disque plein

**Symptômes :** alerte `DiskSpaceLow`, `INSERT failed: disk full`, conteneurs en restart loop.

## 1. Diagnostic
```bash
df -h
docker system df
docker exec axion-crm-postgres df -h /var/lib/postgresql/data
```

## 2. Nettoyage Docker

> ### 🔴 `docker logs --tail=0` NE TRONQUE RIEN. Constat F39-011 (S1), 2026-08-20.
>
> Ce runbook prescrivait ici :
>
> ```bash
> docker logs axion-crm-api --tail=0       # tronque logs verbeux
> ```
>
> **Le commentaire est faux.** `docker logs` est une commande de LECTURE :
> `--tail=0` demande d'afficher zéro ligne, il n'efface pas un octet. Mesuré sur
> un conteneur réel le 2026-08-20 :
>
> ```
> $ docker logs axion-crm-caddy | wc -c            # 461154
> $ docker logs axion-crm-caddy --tail=0 | wc -c   # 0   (affichage, pas suppression)
> $ docker logs axion-crm-caddy | wc -c            # 461154   ← INCHANGÉ
> ```
>
> En incident, un opérateur qui suit cette ligne croit avoir libéré de la place
> et n'en a libéré **aucune**. C'est pire qu'une étape manquante : c'est une
> étape qui rassure.

```bash
# a) Voir OÙ est la place. `docker system df` ne montre PAS les journaux des
#    conteneurs : ils vivent dans /var/lib/docker/containers/ et n'entrent dans
#    aucune de ses lignes. On les compte séparément.
docker system df
du -sh /var/lib/docker/containers/*/*-json.log 2>/dev/null | sort -h | tail -10

# b) Images et couches orphelines : c'est là que se trouvent les gigaoctets.
#    Le déploiement reconstruit les images à chaque livraison (`--build`) et ne
#    purge rien ; chaque build laisse l'image précédente en suspens.
docker image prune -af                    # images non référencées, sans toucher aux volumes
docker builder prune -af                  # cache de construction

# c) Purge totale — ATTENTION : `--volumes` DÉTRUIT postgres-data et redis-data.
#    Confirme avec Will d'abord, et jamais sans sauvegarde vérifiée.
# docker system prune -a --volumes -f

# d) VRAIMENT tronquer le journal d'un conteneur (le geste que la ligne fausse
#    ci-dessus prétendait faire). On écrit dans le fichier, on ne le supprime
#    pas : Docker garde son descripteur ouvert, un `rm` ne rendrait rien.
truncate -s 0 "$(docker inspect --format='{{.LogPath}}' axion-crm-api)"
```

> ### Le plafond, pour ne plus revenir ici
>
> Depuis le 2026-08-20, `docker-compose.yml` porte une ancre `x-journal`
> (`max-size: 10m`, `max-file: 5`) sur chacun de ses services : **50 Mio par
> conteneur, 400 Mio pour la pile, et ce total ne bouge plus.** Avant, il n'y
> avait aucun plafond — `docker inspect` rendait `{"Type":"json-file","Config":{}}`
> sur tous les conteneurs mesurés.
>
> ⚠️ Le plafond s'applique à la **création** du conteneur. `caddy` n'est recréé
> par aucun déploiement : si `docker inspect --format '{{json .HostConfig.LogConfig}}'
> axion-crm-caddy` rend un `Config` vide, c'est qu'il attend encore d'être
> recréé une fois, à la main :
>
> ```bash
> cd /opt/axion-crm-pro
> # 🔴 L'overlay de production n'est PAS optionnel : sans lui, Compose repart du
> # seul docker-compose.yml, qui PUBLIE Postgres sur 55432 et Redis sur 56379.
> # C'est la faille du 2026-08-19 (constat F38-007).
> export COMPOSE_FILE="docker-compose.yml:docker-compose.prod.yml"
> docker compose up -d --no-deps caddy
> ```

## 3. Postgres bloat

> ### 🔴 NE PAS DETACHER les partitions de `audit_logs`. Jamais en incident.
>
> **Constat B16-009 (audit 360, 2026-08-20).** Ce runbook prescrivait, à cet
> endroit précis, un `partman.run_maintenance('public.audit_logs')` présenté
> comme un simple geste de ménage. Il ne l'est pas : il détruit pour toujours
> la valeur probante du journal d'audit.
>
> **Pourquoi.** `audit_logs` est une chaîne cryptographique :
> `hash_n = sha256(hash_(n-1) || ligne_n || secret)`. La vérification
> (`AuditHashChain::verifyChain()`, `backend/app/Services/Audit/AuditHashChain.php`)
> part de la sentinelle GENESIS — 64 zéros — et **exige que la première ligne
> lue porte ce `prev_hash`**. Détacher les partitions anciennes retire les
> **premiers** maillons : la première ligne restante pointe alors vers un
> condensé qui n'existe plus dans la table, et la vérification s'arrête là.
>
> **Ce que ça coûte, mesuré.** `php artisan audit:verify-chain` répondra
> `INVALIDE` **à chaque passage, pour toujours**, sans qu'aucune falsification
> n'ait eu lieu — et rien ne le répare : les écritures suivantes s'ajoutent
> derrière la cassure, elles ne la referment pas. Mesure jouée dans
> `backend/tests/Feature/Infra/RunbookDisquePleinTest.php` (« retirer les
> PREMIERS maillons casse la chaine DEFINITIVEMENT »).
>
> **Et depuis le 2026-08-20, ça réveille quelqu'un.** La tâche planifiée de
> 03:00 alerte désormais en niveau critique quand la chaîne est rompue
> (constat B16-006, `routes/console.php` + `AuditVerifyChain`). Détacher une
> partition, c'est donc s'infliger une alerte critique **toutes les nuits**,
> que personne ne pourra plus éteindre — et qui masquera la vraie.
>
> **Ce qu'il faut faire à la place, dans cet ordre :**
>
> 1. **Étape 2 (Docker) et étape 4 (payloads de scraping)** : c'est là que se
>    trouve la place, pas dans `audit_logs`. Les images et les
>    `scraper_runs.response_payload` pèsent des ordres de grandeur de plus.
> 2. `VACUUM` (voir ci-dessous) sur les tables volumineuses.
> 3. **Étape 5 — agrandir le volume.** Sur un journal d'audit, on achète du
>    disque ; on ne coupe pas la preuve.
>
> **Si le détachement est malgré tout inévitable** (disque à 100 %, base en
> arrêt, aucune autre option) : c'est une décision qui se prend avec Will,
> **pas** un geste de runbook. Avant de l'exécuter :
> `pg_dump` des partitions concernées (elles restent la seule preuve de leur
> propre intégrité), relever le `current_hash` de la dernière ligne conservée,
> consigner l'opération, sa date et son auteur — et **savoir qu'à partir de là,
> `audit:verify-chain` ne pourra plus jamais répondre OK sur cette base.**

```bash
# VACUUM : récupère l'espace mort SANS toucher au contenu des tables.
docker exec -it axion-crm-postgres psql -U axion -d axion_crm -c "VACUUM FULL ANALYZE;"
```

⚠️ `VACUUM FULL` prend un verrou exclusif : la table est **inaccessible** le
temps de l'opération. Sur les grosses tables, préférer `VACUUM (ANALYZE)` sans
`FULL`, ou `pg_repack`.

> La commande de détachement qui figurait ici — un
> `SELECT partman.run_maintenance('public.audit_logs', p_jobmon := false);` —
> a été **retirée volontairement**. Voir l'encadré ci-dessus : ce n'est pas un
> oubli, et ce n'est pas à remettre.

## 4. Vider les payloads scraping anciens
```bash
# C'est ICI que se trouve la place : les payloads de scraping se comptent en Go,
# les lignes d'audit en Mo.
docker exec -it axion-crm-api php artisan retention:purge --all-workspaces --force
```

⚠️ `retention:purge` exige désormais une portée explicite (`--workspace=<uuid|slug>`
ou `--all-workspaces`) et l'aveu écrit `--force` en contexte non interactif
(constat B11-003). Sans portée, la commande refuse — c'est voulu.

## 5. Escalade si > 90 %
Scaler le disque Hetzner (`hcloud volume resize axion-crm-data --size 320`) puis redémarrer Postgres.

C'est la sortie normale de cet incident si les étapes 2 et 4 n'ont pas suffi.
Sur une base qui porte un journal d'audit à valeur probante, agrandir le volume
coûte moins cher que perdre la preuve.
