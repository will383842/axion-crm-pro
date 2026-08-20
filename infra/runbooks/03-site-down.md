# Runbook — Site down (5xx persistant)

**Symptômes :** alerte `ApiDown`, Uptime Kuma rouge, utilisateurs reportent 502/504.

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
docker compose pull
docker compose up -d --force-recreate api caddy
```

## 6. Si origin down > 5 min
Activer la maintenance page via Caddy : `cp infra/caddy/Caddyfile.maintenance /etc/caddy/Caddyfile && docker compose restart caddy`.
