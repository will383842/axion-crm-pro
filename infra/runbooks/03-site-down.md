# Runbook — Site down (5xx persistant)

**Symptômes :** alerte `ApiDown`, Uptime Kuma rouge, utilisateurs reportent 502/504.

## 1. Vérifier l'état des conteneurs
```bash
docker compose ps
docker compose logs --tail=200 api caddy
```

## 2. Vérifier les dépendances
```bash
docker exec axion-crm-postgres pg_isready -U axion
docker exec axion-crm-redis redis-cli ping
curl -fsS http://localhost/up   # depuis Caddy
```

## 3. Diagnostic Laravel
```bash
docker exec -it axion-crm-api php artisan tinker
> \DB::connection()->getPdo();
> \Cache::store('redis')->put('k', 'v', 10);
> \Cache::store('redis')->get('k');
```

## 4. Recharger config en cas de drift
```bash
docker exec axion-crm-api php artisan config:clear
docker exec axion-crm-api php artisan route:clear
docker exec axion-crm-api php artisan cache:clear
```

## 5. Redéploiement immédiat
```bash
# 🔴 SANS cet export, `docker compose` relit `docker-compose.yml` seul : cible
# `dev`, et surtout le montage bind qui MASQUE le `vendor` de l'image — la
# production repartirait sur un `vendor` obsolete (mesure : 3 mois de retard le
# 2026-08-16). Ajoute par l'audit 360, P5 : le meme correctif etait deja pose
# dans `deploy-direct-ssh.yml` et n'avait pas ete porte ici.
export COMPOSE_FILE="docker-compose.yml:docker-compose.prod.yml"
docker compose pull
docker compose up -d --force-recreate api caddy
```

## 6. Si origin down > 5 min
Activer la maintenance page via Caddy : `cp infra/caddy/Caddyfile.maintenance /etc/caddy/Caddyfile && docker compose restart caddy`.
