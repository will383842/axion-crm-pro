# Production Activation Runbook
**Cible** : passer Axion CRM Pro de "staging-ready 🟠" à "prod live 🟢".

> Document maintenu à jour à chaque activation de source. Voir aussi `_AUDIT/MONITORING.md`, `_AUDIT/DEPLOY-PIPELINE.md`.

## Pré-requis humains (à fournir par Will)

| Secret | Source | Où le coller |
|---|---|---|
| INSEE_API_KEY | api.insee.fr (gratuit, créer compte) | `.env` serveur |
| FRANCE_TRAVAIL_CLIENT_ID + SECRET | francetravail.io | `.env` serveur |
| MISTRAL_API_KEY | console.mistral.ai | `.env` serveur |
| WEBSHARE_PROXY_USER + PASS | webshare.io (~$5/mo) | `.env` serveur |
| TWOCAPTCHA_API_KEY | 2captcha.com (~$5/mo) | `.env` serveur |
| SENTRY_DSN + VITE_SENTRY_DSN | sentry.io ou GlitchTip self-hosted | `.env` serveur |
| REVERB_APP_KEY + SECRET + ID | générer via `openssl rand -base64 32` | `.env` serveur |

## Activation source par source (gradual)

Recommandé : activer 1 source, smoke test 24h, monitor, puis suivante.

### Phase A (gratuit, low risk) — semaine 1

1. **INSEE** :
   ```bash
   ssh root@axion-crm-pro
   cd /opt/axion-crm-pro
   sed -i 's/^MOCK_INSEE=.*/MOCK_INSEE=false/' .env
   docker compose restart api horizon
   # Smoke test :
   docker compose exec api php artisan tinker --execute='dispatch(new \App\Jobs\LaunchZoneScrapingJob("workspace-uuid", "75", null, null, 10));'
   docker compose logs -f horizon | grep -i insee
   ```
2. **Annuaire Entreprises** : `MOCK_ANNUAIRE_ENTREPRISES=false`, restart api+horizon, idem
3. **BODACC** : `MOCK_BODACC=false`, idem
4. **BAN (géocodage)** : `MOCK_BAN=false`, idem
5. **France Travail** : `MOCK_FRANCE_TRAVAIL=false`, secrets fournis

### Phase B (Playwright + proxies) — semaine 2

1. **Configure Webshare** :
   ```bash
   echo "WEBSHARE_PROXY_USER=xxx" >> .env
   echo "WEBSHARE_PROXY_PASS=xxx" >> .env
   docker compose restart worker-pages-jaunes worker-google-maps worker-google-search
   ```
2. **Pages Jaunes** : `MOCK_SCRAPERS=false` puis test sur 1 département
3. **Google Maps** : idem (2captcha actif obligatoire)
4. **Google Search** : idem
5. **Direction Finder** : idem
6. **Website scraper** : idem

## POC charge (avant prod full)

Script : `backend/scripts/load-poc.sh`

- Départements test : 75, 92, 69, 13, 33 (5 départements variés)
- Limit : 50 entreprises par département
- Monitoring : `docker stats axion-crm-app axion-crm-api axion-crm-postgres axion-crm-redis`
- Validation :
  - Taux succès >95%
  - Latence p95 <30s par entreprise
  - Zéro 5xx sur `/api/coverage/launch`
  - Pas d'OOM sur Postgres ni Redis

## Activation Sentry alerting

1. Crée un projet Sentry/GlitchTip (axionia/axion-crm-pro)
2. Copie DSN dans `.env` :
   ```
   SENTRY_DSN=https://...@sentry.io/...
   VITE_SENTRY_DSN=https://...@sentry.io/...
   ```
3. Rebuild app pour propager `VITE_SENTRY_DSN` au bundle :
   ```bash
   docker compose build app && docker compose up -d app
   ```
4. Configure alert rules Sentry :
   - "Error rate > 5/min for 5min" → email Will
   - "P95 latency > 30s on /api/coverage/launch" → email Will
   - "Worker queue depth > 1000" → email Will

## Rate limiting actif (Sprint 19.6)

- `POST /coverage/launch` : 10/min/user (env `SCRAPER_LAUNCH_PER_MINUTE`)
- `POST /scraper-runs/{run}/cancel` : 10/min/user
- `POST /scraper-runs/{run}/retry` : 10/min/user
- `GET /scraper-runs` : 60/min/user (env `SCRAPER_LIST_PER_MINUTE`)

Override en cas d'opération bulk (à reverter après) :
```bash
echo "SCRAPER_LAUNCH_PER_MINUTE=100" >> .env
docker compose restart api
```

## Rollback

Si une source explose les quotas ou produit du junk :

```bash
# Retour immédiat aux mocks
ssh root@axion-crm-pro
cd /opt/axion-crm-pro
sed -i 's/^MOCK_<SOURCE>=.*/MOCK_<SOURCE>=true/' .env
docker compose restart api horizon
```

Si pollution DB :
```bash
docker compose exec api php artisan tinker
# >>> \App\Models\Company::where('discovery_source', 'coverage_launch')
# >>>     ->where('created_at', '>=', '2026-05-17 14:00:00')
# >>>     ->delete();  // soft-delete (deleted_at)
```

## Vite mode prod (Sprint 19.6)

Le service `app` build le SPA via `pnpm build` et le sert via Caddy 2 alpine sur `:5173`.
- Aucun client HMR n'est injecté dans le navigateur (plus de warnings WebSocket).
- Rollback dev (HMR) : `TARGET_FRONTEND=dev docker compose up -d --build app`.
- Variables `VITE_API_BASE_URL` et `VITE_SENTRY_DSN` doivent être dans `.env` au moment du `docker compose build app` (sinon valeur vide compilée dans le bundle).

## Checklist GO/NO-GO production

- [ ] Tous les secrets Phase A fournis et chargés
- [ ] Phase A activée + smoke 24h vert
- [ ] Backup quotidien Storage Box vérifié (cron 3h UTC OK)
- [ ] Restore test du backup réussi (au moins 1×)
- [ ] Sentry alerting configuré + 1 alerte test reçue
- [ ] Rate limiting actif (10 launch/min/user) — testé via `curl` × 11
- [ ] Endpoints cancel/retry implémentés (était 501) + smoke OK
- [ ] POC charge passé (5 départements × 50 entreprises)
- [ ] Cloudflare Proxied (nuage orange) activé sur `app.axion-crm-pro.com`
- [ ] CRM password changé + 2FA TOTP actif
- [ ] Hetzner root password régénéré
- [ ] SSH key régénérée
- [ ] `MOCK_MODE` global à `false` dans `.env` serveur
- [ ] Logs Horizon montrent 0 exception sur 24h
- [ ] Frontend bundle ne contient plus de référence `/@vite/client` (vérifier via DevTools Network sur `https://app.axion-crm-pro.com`)

## Order recommandé d'exécution (séquentiel)

1. **J+0** : rebuild + deploy mode prod Vite (`feat/infra:caddy:2-alpine`)
2. **J+0** : Sentry DSN posé + rebuild app
3. **J+1** : activer INSEE (mock=false)
4. **J+2** : activer Annuaire Entreprises + BODACC + BAN
5. **J+3** : activer France Travail
6. **J+4-7** : monitoring Phase A, ajustement quotas si nécessaire
7. **J+8** : Webshare + 2captcha activés
8. **J+9** : activer Pages Jaunes (1 dépt pilote 24h)
9. **J+10** : étendre Google Maps + Google Search
10. **J+11** : Direction Finder + Website scraper
11. **J+12** : POC charge 5 dépts
12. **J+13** : GO/NO-GO checklist signée → Cloudflare Proxied ON
