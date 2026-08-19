# Runbook — Rotation des secrets

## Périodicité minimum
- `APP_KEY` Laravel : tous les 90 jours OU compromission suspectée
- `AUDIT_HASH_CHAIN_SECRET` : tous les 365 jours (rolling)
- Tokens API providers (Anthropic, Mistral, Webshare, IPRoyal, 2captcha) : tous les 180 jours
- `OWNER_INITIAL_PASSWORD` : jamais réutilisé (one-shot bootstrap)

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

# 4. Re-chiffrer les colonnes chiffrées — elles s'appellent `totp_secret` et
#    `totp_recovery_codes` ; `two_factor_recovery_codes` n'a jamais existé (A07-001)
docker exec -it axion-crm-api php artisan model:rotate-keys --tables=users
```

## Procédure rotation AUDIT_HASH_CHAIN_SECRET
ATTENTION : casse la vérifiabilité historique. À utiliser uniquement en cas de fuite suspectée.

1. Snapshot Postgres : `pg_dump audit_logs > backup-pre-rotation.sql`
2. Décrire le justificatif d'incident dans `audit_logs` final hash
3. `AUDIT_HASH_CHAIN_SECRET=<nouveau>` puis **`docker compose up -d api`** (jamais `restart`), et vérifier par `docker inspect` que la valeur est bien celle attendue
4. Marquer le breakpoint via `audit:checkpoint` artisan command

## Procédure révocation clé provider compromise
1. Révoquer côté console provider (Anthropic console / Webshare dashboard)
2. Mettre à jour `.env` ou Doppler
3. **`docker compose up -d api horizon`** (jamais `restart` : il ne relit pas `env_file`), puis vérifier par `docker inspect`
4. Vérifier 5 min après : `php artisan llm:smoke-test` doit passer
