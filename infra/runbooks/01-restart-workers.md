# Runbook — Redémarrer les workers

**Symptômes :** Horizon dashboard montre des jobs `failed` ou `pending` > 1000 stagnant, alerte `HorizonQueueBacklog`.

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
```

## 3. Vider la queue si elle est gelée
```bash
docker exec axion-crm-redis redis-cli -n 1 DEL bull:scrape:google-maps:waiting
# Re-dispatcher les jobs via : php artisan queue:retry all
```

## 4. Vérification
- Horizon UI `/horizon` → throughput remonte
- Pas d'erreur 5xx sur `/internal/scraper-result` dans les logs
