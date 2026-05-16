# Runbook — Rotation des secrets

## Périodicité minimum
- `APP_KEY` Laravel : tous les 90 jours OU compromission suspectée
- `AUDIT_HASH_CHAIN_SECRET` : tous les 365 jours (rolling)
- Tokens API providers (Anthropic, Mistral, Webshare, IPRoyal, 2captcha) : tous les 180 jours
- `OWNER_INITIAL_PASSWORD` : jamais réutilisé (one-shot bootstrap)

## Procédure rotation APP_KEY
```bash
# 1. Générer nouvelle clé
docker exec -it axion-crm-api php artisan key:generate --show

# 2. Conserver l'ancienne via APP_PREVIOUS_KEYS (déchiffrage transitoire)
APP_PREVIOUS_KEYS=<ancien-key>,...
APP_KEY=base64:<nouveau>

# 3. Redémarrer
docker compose restart api horizon scheduler

# 4. Re-chiffrer les colonnes encrypted (totp_secret, two_factor_recovery_codes)
docker exec -it axion-crm-api php artisan model:rotate-keys --tables=users
```

## Procédure rotation AUDIT_HASH_CHAIN_SECRET
ATTENTION : casse la vérifiabilité historique. À utiliser uniquement en cas de fuite suspectée.

1. Snapshot Postgres : `pg_dump audit_logs > backup-pre-rotation.sql`
2. Décrire le justificatif d'incident dans `audit_logs` final hash
3. `AUDIT_HASH_CHAIN_SECRET=<nouveau>` + redémarrer api
4. Marquer le breakpoint via `audit:checkpoint` artisan command

## Procédure révocation clé provider compromise
1. Révoquer côté console provider (Anthropic console / Webshare dashboard)
2. Mettre à jour `.env` ou Doppler
3. `docker compose restart api horizon` workers
4. Vérifier 5 min après : `php artisan llm:smoke-test` doit passer
