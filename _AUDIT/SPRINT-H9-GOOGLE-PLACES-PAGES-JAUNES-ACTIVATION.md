# Sprint H9 — Activation Google Places API + Pages Jaunes

Date : 2026-05-18. Doctrine : pas de scraping Google Maps direct (légal/stable), API officielle Google Places (New) à la place.

## Ce qui est livré côté code (Sprint H9)

- ✅ `App\Services\Scraping\GooglePlacesClient` — wrapper officiel API Places (New)
  - Endpoint `https://places.googleapis.com/v1/places:searchText`
  - Cache Redis 30 jours par query
  - Retry sur ConnectionException uniquement
  - Sentry capture sur 4xx/5xx + exceptions
  - Graceful : pas de `GOOGLE_PLACES_API_KEY` → retourne null sans crash
  - Helper `flatten()` pour extraction structurée (phone, website, lat/lon, rating, horaires)

- ✅ `WaterfallOrchestrator::step3d_google_places` — nouvelle étape sync :
  - Appelée après `step3c_mentions_legales`
  - Backfill phone + website + address + lat/lon UNIQUEMENT si vides (pas d'écrasement)
  - Stocke payload complet dans `signals.google_places` (rating, types, horaires) pour exploitation UI

- ✅ `step4_dispatch_node_scrapes` allégé : retire `google-maps` (remplacé par H9 server-side), garde `pages-jaunes`, `website`, `google-search`.

- ✅ 8 nouveaux tests Pest (`backend/tests/Unit/Scraping/GooglePlacesClientTest.php`).

- ✅ `config/services.php` : section `services.google.places.api_key` + `cache_ttl_days`.

## Activation côté Will — Google Places API

### Étape 1 — Créer un projet GCP dédié

1. Va sur https://console.cloud.google.com/
2. Menu projets en haut → **Nouveau projet** → nom : `axion-crm-pro` → créer
3. Sélectionner ce projet comme actif

### Étape 2 — Activer Places API (New)

1. Menu **APIs & Services** → **Library**
2. Cherche **"Places API (New)"** (attention : choisir New, pas l'ancienne)
3. Clic **Enable**
4. Si demandé : activer le billing (carte de crédit obligatoire)

### Étape 3 — Profiter du crédit gratuit Maps Platform

Google offre **$200/mois de crédit gratuit** sur les APIs Maps Platform.
À $17/1000 requêtes Places Details, ça représente ~12 000 lookups gratuits/mois.
**Tant que tu restes sous ce seuil, tu paies 0 €.**

### Étape 4 — Créer une clé API

1. Menu **APIs & Services** → **Credentials** → **Create Credentials** → **API Key**
2. Copier la clé (commence par `AIzaSy…`, ~39 chars)
3. **Restrictions recommandées sur la clé** (clic Edit après création) :
   - **Application restrictions** : "IP addresses" → ajouter `46.62.248.239` (IP serveur Hetzner). Empêche que quelqu'un d'autre utilise ta clé s'il la vole.
   - **API restrictions** : "Restrict key" → cocher uniquement **"Places API (New)"**. Empêche d'utiliser la clé pour Maps embed, geocoding, etc.

### Étape 5 — Poser la clé sur le serveur Hetzner

SSH dans le serveur :
```bash
ssh root@46.62.248.239
cd /opt/axion-crm-pro
nano .env
```

Ajouter en fin de fichier :
```
GOOGLE_PLACES_API_KEY=AIzaSy…ta-clé…
```

Sauvegarder + appliquer :
```bash
docker compose exec -T api php artisan config:clear
docker compose restart api horizon
```

### Étape 6 — Vérifier en prod

```bash
docker compose exec -T api php artisan tinker --execute="dump(app(App\Services\Scraping\GooglePlacesClient::class)->searchText('Boulangerie Dupont Paris'));"
```

Doit retourner un array avec `displayName`, `formattedAddress`, etc. Si null → vérifier la clé + activation Places API dans GCP.

## Activation côté Will — Pages Jaunes (Webshare proxy)

### Étape 1 — Créer un compte Webshare

1. https://www.webshare.io/ → Sign up (email + password)
2. **Plan recommandé** : "Residential Premium" → **$30/mois flat**
3. Vérification mail + carte de crédit

### Étape 2 — Récupérer les credentials

Dashboard Webshare → **Proxy** → **Endpoint** :
- Endpoint : ex `p.webshare.io:80`
- Username : ex `wjullin-rotate`
- Password : ex `xy12abc34`

### Étape 3 — Poser les credentials sur le serveur

```bash
nano /opt/axion-crm-pro/.env
```

Ajouter :
```
WEBSHARE_ENABLED=true
WEBSHARE_USERNAME=wjullin-rotate
WEBSHARE_PASSWORD=xy12abc34
WEBSHARE_ENDPOINT=p.webshare.io:80
MOCK_SCRAPERS=false
```

Appliquer :
```bash
docker compose exec -T api php artisan config:clear
docker compose restart api horizon worker-pages-jaunes
```

### Étape 4 — Tester

```bash
docker compose exec -T api php artisan tinker --execute="dump(app(App\Services\Http\ProxiedHttpClient::class)->isProxyEnabled());"
```

Doit retourner `true`.

## Coûts mensuels après activation

| Service | Coût | Volume couvert |
|---|---|---|
| Google Places API | ~$0/mois (crédit gratuit Google) | ~12 000 lookups/mois |
| Webshare Residential | $30/mois flat | illimité bande passante |
| **Total** | **~30 €/mois** | 12K entreprises enrichies Google Maps + Pages Jaunes |

Au-delà de 12K entreprises Places/mois : ~$17 par tranche de 1000 lookups supplémentaire.

## Anti-detection : ce qui est déjà en place

Renforcé via sprints H1 + H9 :
- ✅ User-Agent rotation (4 navigateurs Chrome/Safari/Firefox/Linux) — sprint H1
- ✅ Random delays 200-800ms entre requêtes (prod/staging only) — sprint H1
- ✅ Retry uniquement sur ConnectionException — sprint H1
- ✅ ProxiedHttpClient route via Webshare quand WEBSHARE_ENABLED=true — sprint H1
- ✅ Cache 30j Google Places → ne re-paie pas les mêmes lookups — sprint H9
- ✅ IP rotation automatique Webshare (chaque requête = nouvelle IP résidentielle)

## Rollback rapide (si problème)

Pour désactiver tout :
```bash
# .env
GOOGLE_PLACES_API_KEY=        # vide → skip silencieux step3d
WEBSHARE_ENABLED=false        # → ProxiedHttpClient ne route plus via proxy
MOCK_SCRAPERS=true            # → step4_dispatch_node_scrapes skip
```

Puis `docker compose exec -T api php artisan config:clear && docker compose restart api horizon`.

## Recommandations Will

1. **Activer Google Places API d'abord** (gratuit jusqu'à 12K/mois) → 1 semaine de prod sain
2. **Activer Webshare ensuite** uniquement si tu veux Pages Jaunes data → $30/mois
3. **Monitorer dans Sentry** les tag `service=google-places` ou `service=pages-jaunes` → alertes si error rate > seuil
4. **Dashboard observability** (`/admin/observability`) affichera bientôt un compteur Places API usage si on l'ajoute (sprint H+1)
