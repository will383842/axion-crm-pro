# Runbook — Disque plein

**Symptômes :** alerte `DiskSpaceLow`, `INSERT failed: disk full`, conteneurs en restart loop.

## 1. Diagnostic
```bash
df -h
docker system df
docker exec axion-crm-postgres df -h /var/lib/postgresql/data
```

## 2. Nettoyage Docker
```bash
docker system prune -a --volumes -f       # ATTENTION : confirme avec Will d'abord
docker logs axion-crm-api --tail=0       # tronque logs verbeux
```

## 3. Postgres bloat
```bash
docker exec -it axion-crm-postgres psql -U axion -d axion_crm -c "VACUUM FULL ANALYZE;"
# Détacher anciennes partitions audit_logs
docker exec -it axion-crm-postgres psql -U axion -d axion_crm -c "
  SELECT partman.run_maintenance('public.audit_logs', p_jobmon := false);
"
```

## 4. Vider les payloads scraping anciens
```bash
docker exec -it axion-crm-api php artisan retention:purge
```

## 5. Escalade si > 90 %
Scaler le disque Hetzner (`hcloud volume resize axion-crm-data --size 320`) puis redémarrer Postgres.
