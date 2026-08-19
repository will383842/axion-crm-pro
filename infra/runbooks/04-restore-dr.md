# Runbook — Restauration disaster recovery

**Cible :** RPO ≤ 1h, RTO ≤ 4h.

## Sources de backup
1. **Hetzner Object Storage** (chiffré AES-256) — chaque heure full + WAL streaming
2. **Backblaze B2 off-site** (rule 3-2-1) — réplication asynchrone toutes les 6h

## 1. Provisionner le serveur de remplacement
```bash
hcloud server create --type cpx42 --image ubuntu-24.04 --location fsn1 --name axion-crm-dr
# Installer docker + restaurer infra : git clone, PUIS :
#
#   🔴 export COMPOSE_FILE="docker-compose.yml:docker-compose.prod.yml"
#   docker compose up -d
#
# L'export N'EST PAS FACULTATIF. Sans lui, `docker compose` ne lit que
# `docker-compose.yml`, qui PUBLIE 55432:5432 (Postgres) et 56379:6379 (Redis)
# sur l'interface publique — avec le mot de passe ecrit dans ce depot PUBLIC,
# sur un role SUPERUSER + BYPASSRLS, et un Redis sans mot de passe.
# C'est la faille du 2026-08-19. Elle est refermee par `ports: !override []`
# dans `docker-compose.prod.yml`, qui n'est charge QUE par cet export.
# Ce runbook s'execute sur une machine NEUVE, dans l'urgence : c'est
# exactement la situation ou personne ne verifiera. (Audit 360, P5.)
# Verification apres coup : `bash infra/scripts/verifier-ports-publies.sh`
```

## 2. Restaurer Postgres
```bash
# Récupérer le dernier full + WAL
s3cmd get s3://axion-crm-backups/postgres/$(date +%F)/full.tar.gz - | tar xz -C /tmp/restore
docker exec axion-crm-postgres pg_basebackup -D /tmp/restore -X stream

# Point-in-time recovery jusqu'à T-5min de l'incident
echo "restore_command = 's3cmd get s3://axion-crm-backups/wal/%f %p'" >> postgresql.conf
echo "recovery_target_time = 'YYYY-MM-DD HH:MM:00'" >> recovery.signal
docker compose restart postgres
```

## 3. Restaurer Redis (cache + queues)
Redis est volatile par design — ne pas restaurer, laisser warm-up naturel.
Toutefois, les `magic_links` actifs et `email_validations` non expirés sont perdus → broadcast user (Sprint 8).

## 4. Réindex + warm caches
```bash
docker exec -it axion-crm-api php artisan coverage:refresh-matrix --concurrent
docker exec -it axion-crm-api php artisan cache:clear
```

## 5. Vérification post-restore
```bash
# Vérif chaîne audit
docker exec axion-crm-api php artisan audit:verify-chain

# Vérif RLS effective
docker exec axion-crm-postgres psql -U axion -d axion_crm -c "
  SET app.current_workspace_id = '00000000-0000-0000-0000-000000000000';
  SELECT COUNT(*) FROM companies;  -- doit retourner 0
"
```

## 6. Bascule DNS Cloudflare
```bash
cf-cli zone:set-record axion-crm-pro.com A <new-ip> --proxied
```

**Test trimestriel obligatoire** via `infra/scripts/dr-drill.sh`.
