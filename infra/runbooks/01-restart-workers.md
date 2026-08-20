# Runbook — Redémarrer les workers

**Symptômes :** Horizon dashboard montre des jobs `failed` ou `pending` > 1000 stagnant, alerte `HorizonQueueBacklog`.

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


## 1. Diagnostic
```bash
docker compose ps | grep worker
docker compose logs --tail=200 worker-google-maps worker-pages-jaunes worker-google-search
```

Vérifier :
- Connexion Redis : `docker exec axion-crm-redis redis-cli ping` → `PONG`
- Mémoire conteneur : `docker stats --no-stream | grep worker`
- Playwright browsers présents : `docker exec axion-crm-worker-google-maps ls /ms-playwright/chromium-*`

## 2. Restart graceful
```bash
docker compose restart worker-google-maps worker-pages-jaunes worker-google-search

# ⚠️ `restart` convient ICI parce qu'on ne fait que relancer des processus.
# Si une VARIABLE D'ENVIRONNEMENT a change, `restart` ne la relit PAS :
# il faut `docker compose up -d <service>`, qui recree le conteneur.
```

## 3. Vider la queue si elle est gelée
```bash
docker exec axion-crm-redis redis-cli -n 1 DEL bull:scrape:google-maps:waiting
# Re-dispatcher les jobs via : php artisan queue:retry all
```

## 4. Vérification
- Horizon UI `/horizon` → throughput remonte
- Pas d'erreur 5xx sur `/internal/scraper-result` dans les logs
