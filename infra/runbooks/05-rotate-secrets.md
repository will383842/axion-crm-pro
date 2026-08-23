# Runbook — Rotation des secrets

## Périodicité minimum
- `APP_KEY` Laravel : tous les 90 jours OU compromission suspectée
- `AUDIT_HASH_CHAIN_SECRET` : tous les 365 jours (rolling)
- Tokens API providers (Anthropic, Mistral, Webshare, IPRoyal, 2captcha) : tous les 180 jours
- `OWNER_INITIAL_PASSWORD` : jamais réutilisé (one-shot bootstrap)

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


## Procédure rotation APP_KEY

> ## 🔴 `docker compose restart` NE RELIT PAS `env_file`
>
> **Ce runbook prescrivait `restart` à trois endroits. Un secret réputé tourné ne
> l'était pas** — le conteneur redémarrait avec l'ANCIENNE valeur, et l'opérateur
> repartait convaincu du contraire. C'est la pire des situations : pas une panne,
> une fausse assurance. Mesuré le 2026-08-19 (audit 360, A07-003, S0).
>
> `restart` relance le processus **dans le conteneur existant**, dont
> l'environnement a été figé à la création. Seul `up -d` **recrée** le conteneur
> et relit `env_file`.
>
> **Partout dans ce document : `docker compose up -d <service>`, jamais `restart`.**
> Et on VÉRIFIE, on ne suppose pas :
> ```bash
> docker inspect <conteneur> --format '{{range .Config.Env}}{{println .}}{{end}}' | grep '^LA_CLE='
> ```
> Si la commande rend l'ancienne valeur, la rotation n'a pas eu lieu.
>
> ⚠️ **Et sur ce dépôt, `deploy-direct-ssh.yml` ne recrée que `api app horizon
> scheduler`, avec `--no-deps`.** Une variable qui concerne `postgres`, `redis` ou
> `reverb` n'est donc PAS appliquée par un déploiement : ces trois-là se recréent
> à la main.

```bash
# 1. Générer nouvelle clé
docker exec -it axion-crm-api php artisan key:generate --show

# 2. Conserver l'ancienne via APP_PREVIOUS_KEYS (déchiffrage transitoire)
APP_PREVIOUS_KEYS=<ancien-key>,...
APP_KEY=base64:<nouveau>

# 3. RECRÉER les conteneurs — `restart` ne relirait pas le nouveau .env
docker compose up -d api horizon scheduler

# 3 bis. VÉRIFIER que la nouvelle valeur est réellement dans le conteneur
docker inspect axion-crm-api --format '{{range .Config.Env}}{{println .}}{{end}}' | grep '^APP_KEY='

# 4. Les colonnes chiffrées s'appellent `totp_secret` et `totp_recovery_codes` ;
#    `two_factor_recovery_codes` n'a jamais existé (A07-001).
#
#    🔴 PAS DE COMMANDE — voir l'encadré ci-dessous. Ne cherche pas
#    `model:rotate-keys` : elle n'existe pas sur ce dépôt.
```

> ### ⚠️ Pas 4 : le ré-chiffrement N'EST PAS OUTILLÉ
>
> **Mesure du 2026-08-22 (audit 360, B16-014).** Ce runbook prescrivait une
> commande `model:rotate-keys --tables=users`. **Elle n'existe pas** :
> aucune classe de `backend/app/Console/Commands/` ne la déclare, et ce n'est pas
> non plus une commande du framework. Un opérateur qui a déjà changé `APP_KEY`
> — donc qui ne peut plus revenir en arrière — se retrouvait bloqué sur un
> `Command "model:rotate-keys" is not defined`, au pire moment.
>
> **Ce qui tient lieu de filet en attendant :** `backend/config/app.php:78` lit
> `APP_PREVIOUS_KEYS`. Tant que l'ancienne clé y figure, les colonnes chiffrées
> avec elle restent déchiffrables — le pas 2 ci-dessus n'est donc pas un confort,
> c'est **ce qui empêche la perte**. Ne retire jamais l'ancienne clé de
> `APP_PREVIOUS_KEYS` avant qu'un ré-chiffrement ait réellement eu lieu.
>
> **Ce qui reste à faire, et que ce runbook ne décide pas :** écrire la commande
> de ré-chiffrement (elle touche des secrets TOTP, ça se conçoit, ça ne
> s'improvise pas à 3 heures du matin).

## Procédure rotation AUDIT_HASH_CHAIN_SECRET
ATTENTION : casse la vérifiabilité historique. À utiliser uniquement en cas de fuite suspectée.

1. Snapshot Postgres : `pg_dump audit_logs > backup-pre-rotation.sql`
2. Décrire le justificatif d'incident dans `audit_logs` final hash
3. `AUDIT_HASH_CHAIN_SECRET=<nouveau>` puis **`docker compose up -d api`** (jamais `restart`), et vérifier par `docker inspect` que la valeur est bien celle attendue
4. **Marquer la rupture — PAS OUTILLÉ.** `audit:checkpoint` n'existe pas
   (mesure du 2026-08-22, B16-014 : aucune classe de
   `backend/app/Console/Commands/` ne la déclare). Ne la cherche pas, tu perdrais
   du temps après avoir déjà changé le secret. La seule commande d'audit réelle
   est `docker exec -it axion-crm-api php artisan audit:verify-chain` : joue-la
   juste après la rotation. **Elle rendra rouge, et c'est normal** — les maillons
   d'avant la rotation ont été hachés avec l'ancien secret et ne peuvent plus
   être revérifiés. Note l'`id` de la dernière ligne d'avant rotation dans le
   ticket d'incident : c'est lui, la borne, tant qu'aucune commande ne la pose.

## Procédure révocation clé provider compromise
1. Révoquer côté console provider (Anthropic console / Webshare dashboard)
2. Mettre à jour `.env` ou Doppler
3. **`docker compose up -d api horizon`** (jamais `restart` : il ne relit pas `env_file`), puis vérifier par `docker inspect`
4. **Vérifier 5 min après — PAS OUTILLÉ.** `llm:smoke-test` n'existe pas non plus
   (même mesure, B16-014). À défaut : déclencher un vrai appel provider depuis le
   produit et regarder les logs du conteneur
   (`docker compose logs --tail=100 api horizon`). Un `401`/`403` du provider
   signifie que la nouvelle clé n'est pas celle qui est utilisée — retour au
   pas 3, `docker inspect` à l'appui.
