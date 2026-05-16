# 10 — Rotations universelles (5 dimensions)

> **Doctrine :** ne jamais répéter la même IP, même UA, même zone, même moteur, même LLM trop souvent. Toutes les rotations sont **weighted round-robin** avec health checks + auto-disable.

---

## §1 — Dimension 1 : Proxies

Couvert dans `09_proxy_pluggable_system.md` (interface ProxyProvider, routeur intelligent, 4 implémentations).

---

## §2 — Dimension 2 : User-Agents + fingerprints

### Pool de 50+ User-Agents

```sql
-- Seed `user_agents` (extrait)
INSERT INTO user_agents (user_agent, browser_family, browser_version, os, device_category) VALUES
  ('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', 'chrome', '130', 'Windows 10', 'desktop'),
  ('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', 'chrome', '129', 'Windows 10', 'desktop'),
  ('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', 'chrome', '130', 'macOS', 'desktop'),
  ('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', 'chrome', '130', 'Linux', 'desktop'),
  ('Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:130.0) Gecko/20100101 Firefox/130.0', 'firefox', '130', 'macOS', 'desktop'),
  ('Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0', 'firefox', '130', 'Windows 10', 'desktop'),
  ('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15', 'safari', '17', 'macOS', 'desktop'),
  ('Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1', 'safari', '17', 'iOS', 'mobile'),
  ('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36', 'chrome', '130', 'Android 14', 'mobile'),
  -- ... 41 autres User-Agents (Edge, mobile Android variants, Firefox mobile, etc.)
;
```

Mise à jour mensuelle via job `app:refresh-user-agents` qui fetch les UAs les plus populaires depuis `https://www.useragents.me/` (open data).

### Sélection (weighted)

```php
class UserAgentSelector
{
    public function pickFor(string $domain, ?string $deviceHint = null): UserAgent
    {
        return UserAgent::query()
            ->where('is_active', true)
            ->when($deviceHint, fn($q) => $q->where('device_category', $deviceHint))
            ->inRandomOrder()
            ->orderByRaw('
                (success_count + 1)::float / (usage_count + 1)::float DESC,
                last_used_at NULLS FIRST
            ')
            ->limit(20)
            ->get()
            ->random();
    }
}
```

### Fingerprinting cohérent

Le User-Agent doit être cohérent avec le reste du fingerprint Playwright (locale, timezone, écran, plugins).

```typescript
// workers/src/scrapers/utils/browser-context.ts
export function buildBrowserContext(ua: UserAgent, locale = 'fr-FR') {
  const isMobile = ua.deviceCategory === 'mobile'
  return {
    userAgent: ua.userAgent,
    locale,
    timezoneId: 'Europe/Paris',
    viewport: isMobile ? { width: 390, height: 844 } : { width: 1920, height: 1080 },
    deviceScaleFactor: isMobile ? 3 : 1,
    isMobile,
    hasTouch: isMobile,
    screen: isMobile ? { width: 390, height: 844 } : { width: 1920, height: 1080 },
    extraHTTPHeaders: {
      'Accept-Language': `${locale},en-US;q=0.9,en;q=0.8`,
      'Sec-Ch-Ua': computeSecChUa(ua),       // cohérent avec UA brand/version
      'Sec-Ch-Ua-Platform': mapPlatform(ua.os),
      'Sec-Ch-Ua-Mobile': isMobile ? '?1' : '?0',
    },
  }
}
```

---

## §3 — Dimension 3 : Cibles géo + sectorielles

### Stratégie

Round-robin équitable sur (département × NAF subclass × tranche effectif). Évite de marteler une seule zone (Google peut détecter pattern).

### Table `scraper_rotation_state`

```sql
-- dimension = 'zone_naf_dept'
-- last_value = '75-6201Z-12'  (Paris × Programmation × 20-49 sal)
-- cursor = { "current_index": 1247, "total": 18000 }
```

### Algorithme « prochaine zone »

```php
// app/Services/Rotations/ZoneRotator.php
class ZoneRotator
{
    public function nextZone(string $workspaceId): ?TargetZone
    {
        return DB::transaction(function () use ($workspaceId) {
            // Advisory lock pour parallel safety
            DB::statement("SELECT pg_advisory_xact_lock(hashtext('zone_rotation:'||?))", [$workspaceId]);

            $state = ScraperRotationState::firstOrCreate(
                ['workspace_id' => $workspaceId, 'dimension' => 'zone_naf_dept'],
                ['cursor' => ['current_index' => 0]]
            );

            $candidates = TargetZone::query()
                ->where('workspace_id', $workspaceId)
                ->where('is_active', true)
                ->orderBy('id')
                ->skip($state->cursor['current_index'])
                ->take(50)
                ->get();

            foreach ($candidates as $zone) {
                $cell = CoverageMatrixCell::firstWhere([
                    'workspace_id'    => $workspaceId,
                    'department_code' => $zone->department_code,
                    'naf_subclass_code' => $zone->naf_subclass,
                    'size_category'   => $zone->size_category,
                ]);

                $lastEnriched = $cell?->last_enriched_at;
                if ($lastEnriched && $lastEnriched->gt(now()->subHours(24))) {
                    continue;  // cooldown 24h
                }

                // Update cursor
                $newIndex = $state->cursor['current_index'] + array_search($zone->id, $candidates->pluck('id')->toArray()) + 1;
                if ($newIndex >= TargetZone::where('workspace_id', $workspaceId)->where('is_active', true)->count()) {
                    $newIndex = 0;  // boucle
                }
                $state->update(['cursor' => ['current_index' => $newIndex], 'last_value' => "{$zone->department_code}-{$zone->naf_subclass}-{$zone->effectif_range}", 'updated_at' => now()]);
                return $zone;
            }
            return null;
        });
    }
}
```

### Cooldown 24h obligatoire par cellule

Tracé via `coverage_matrix_cells.last_enriched_at`.

---

## §4 — Dimension 4 : Moteurs de recherche

Couvert partiellement dans `05_scrapers_14_sources.md` § Google Search Wrapper.

### États moteur (`search_engines.state`)

| État | Description | Action |
|------|-------------|--------|
| `active` | Utilisable | Sélection normale |
| `rate_limited` | 429 détecté | Cooldown 15 min (`cooldown_until = now()+15min`) |
| `cooldown` | Manual disable temporaire | Skip |
| `captcha_challenge` | Captcha détecté | Cooldown 30 min |
| `disabled` | Désactivé admin | Skip permanent |

### Rotation

```php
class SearchEngineRotator
{
    public function pick(string $workspaceId): SearchEngine
    {
        $candidates = SearchEngine::where('workspace_id', $workspaceId)
            ->where('is_enabled', true)
            ->where('state', 'active')
            ->where(fn($q) => $q->whereNull('cooldown_until')->orWhere('cooldown_until', '<', now()))
            ->orderByDesc('priority')
            ->orderByDesc('success_rate_24h')
            ->get();

        if ($candidates->isEmpty()) throw new NoSearchEngineAvailableException();
        return $candidates->first();
    }

    public function markCaptcha(SearchEngine $e): void
    {
        $e->update(['state' => 'captcha_challenge', 'cooldown_until' => now()->addMinutes(30)]);
        RotationEvent::create([
            'workspace_id' => $e->workspace_id,
            'dimension'    => 'search_engine',
            'entity_id'    => $e->slug,
            'from_state'   => 'active',
            'to_state'     => 'captcha_challenge',
            'reason'       => 'captcha_detected',
        ]);
        event(new SearchEngineCaptchaDetected($e));
    }
}
```

### Seed `search_engines`

```sql
INSERT INTO search_engines (workspace_id, slug, label, base_url, priority) VALUES
  (?, 'google',     'Google',     'https://www.google.com/search', 100),
  (?, 'bing',       'Bing',       'https://www.bing.com/search',    70),
  (?, 'duckduckgo', 'DuckDuckGo', 'https://duckduckgo.com/',         50),
  (?, 'brave',      'Brave',      'https://search.brave.com/search', 40),
  (?, 'startpage',  'Startpage',  'https://www.startpage.com/do/search', 30);
```

---

## §5 — Dimension 5 : LLM providers

Couvert dans `07_llm_router.md` (fallback chain par use case).

---

## §6 — Weighted round-robin générique

Algorithme commun utilisable pour toutes les rotations :

```php
class WeightedRoundRobin
{
    /**
     * @param array<array{id: mixed, weight: float, last_used_at: \DateTime|null}> $items
     * @return mixed
     */
    public function pick(array $items): mixed
    {
        // Smooth Weighted Round Robin (Nginx-style)
        $current = null;
        $totalWeight = 0;
        foreach ($items as &$item) {
            $item['current_weight'] = ($item['current_weight'] ?? 0) + $item['weight'];
            $totalWeight += $item['weight'];
            if ($current === null || $item['current_weight'] > $current['current_weight']) {
                $current = &$item;
            }
        }
        if ($current === null) return null;
        $current['current_weight'] -= $totalWeight;
        return $current['id'];
    }
}
```

---

## §7 — Dashboard temps réel rotations

### Page admin "Rotations dashboard"

5 colonnes (1 par dimension) avec :

- **Proxies** : 4 cartes provider, IPs actives/cooldown, success rate live (sparkline 1h)
- **User-Agents** : top 10 UAs utilisés 24h, success rate par UA, bouton "Désactiver"
- **Cibles** : carte de chaleur France coverage_matrix_cells, zone "in progress" highlighted
- **Search engines** : 5 chips engine + état (active/captcha/cooldown), bascules manuelles, événements 24h
- **LLM providers** : 5 cartes provider, budget gauge, success rate, fallback events

### Stream temps réel

WebSocket Laravel Reverb broadcast `rotation.event` quand une `RotationEvent` est créée.

---

## §8 — Auto-disable

### Critères

| Dimension | Critère auto-disable |
|-----------|-----------------------|
| Proxy | < 50% success sur 100 dernières requêtes |
| User-Agent | < 30% success sur 50 dernières utilisations |
| Search engine | 3 captcha consécutifs (cooldown 30min, après 5 captcha sur 1h → cooldown 6h) |
| LLM | 3 timeouts consécutifs OU rate-limit > 50% sur 100 requêtes |
| Zone | last_enriched_at < 24h (cooldown obligatoire 24h) |

### Reactivation

- **Auto** : après cooldown si health check OK
- **Manuel** : admin clique "Réactiver"

Tout changement d'état logué en `rotation_events`.

---

## §9 — Métriques agrégées

```
axion_crm_rotation_events_total{dimension,from_state,to_state}
axion_crm_rotation_active_entities{dimension,workspace}
axion_crm_rotation_cooldown_entities{dimension,workspace}
axion_crm_rotation_disabled_entities{dimension,workspace}
```

---

## Lecture suivante

→ `11_carte_france_interactive.md` (MapLibre + OpenFreeMap + composant React 3 modes).
