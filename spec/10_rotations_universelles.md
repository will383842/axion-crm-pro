# 10 — ROTATIONS UNIVERSELLES (5 DIMENSIONS)

## Vue d'ensemble

Axion CRM Pro doit éviter à tout prix d'apparaître comme un seul acteur qui frappe les sources des milliers de fois par jour. La parade : **rotation universelle** sur 5 dimensions, appliquée de manière coordonnée. Chaque dimension fait varier un paramètre identifiant : si seuls les proxies tournent, le user-agent reste le même → fingerprint trahit. Si seul le user-agent tourne, l'IP reste la même → bannissement.

Les 5 dimensions :

1. **Rotation Proxies** — IPs varient (cf fichier 09)
2. **Rotation User-Agents + Fingerprints** — empreintes navigateur varient
3. **Rotation Cibles géo + sectorielles** — on ne tape pas Paris 75 + NAF "Informatique" toute la journée
4. **Rotation Comptes LinkedIn** — Sales Nav usage répartis sur 3 comptes minimum
5. **Rotation LLM Providers** — fallback chain LLM Router (cf fichier 07)

---

## 1. Rotation Proxies

Cf. fichier 09 complet. Récapitulatif :

- Interface `ProxyProvider` + 4 implémentations + `ProxyRouter` intelligent
- Sélection par domaine cible + budget + santé + historique
- Cool-down automatique 24h sur ban / captcha / 429 persistant
- Tables : `proxy_providers`, `proxies`, `proxy_health_checks`, `proxy_usage_log` (partitionnée jour)

---

## 2. Rotation User-Agents + Fingerprints

### Pool de 50+ user-agents réalistes

Pool maintenu manuellement avec :
- Chrome 120-130 desktop (Windows, macOS, Linux)
- Firefox 119-125 desktop (Windows, macOS)
- Safari 17+ desktop (macOS)
- Edge 120+ desktop (Windows)
- Chrome mobile (Android 13+)
- Safari mobile (iOS 17+)

Refresh **mensuel** via script qui scrappe `https://www.useragents.me/` ou `https://github.com/microlinkhq/top-user-agents` et purge ceux qui apparaissent < 1 % d'usage global (donc suspects).

### Fingerprint cohérent

Chaque user-agent vient avec un fingerprint cohérent stocké en `user_agents.fingerprint` (JSONB) :

```jsonc
{
  "viewport": { "width": 1920, "height": 1080 },
  "screen": { "width": 1920, "height": 1080, "depth": 24 },
  "device_pixel_ratio": 1.0,
  "languages": ["fr-FR", "fr"],
  "platform": "Win32",
  "hardware_concurrency": 8,
  "device_memory": 8,
  "color_gamut": "srgb",
  "timezone_offset": -120,                 // Europe/Paris en hiver
  "webgl_vendor": "Google Inc. (NVIDIA)",
  "webgl_renderer": "ANGLE (NVIDIA, NVIDIA GeForce RTX 3060 Direct3D11 vs_5_0 ps_5_0)",
  "fonts": ["Arial", "Helvetica", "Calibri", "Segoe UI", "..."],
  "headers": {
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,...",
    "Accept-Language": "fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7",
    "Accept-Encoding": "gzip, deflate, br, zstd",
    "Sec-Fetch-Site": "none",
    "Sec-Fetch-Mode": "navigate",
    "Sec-Fetch-Dest": "document"
  }
}
```

### Application via playwright-extra stealth — fingerprint cohérent avec proxy country

> **🔑 RÈGLE CRITIQUE (audit P0 #1) :** la `timezoneId`, la `locale`, et le `Accept-Language` doivent **TOUJOURS** matcher le pays du proxy utilisé. Un proxy résidentiel US + timezone `Europe/Paris` = anomalie détectée à 100 % par Google/DataDome/PerimeterX.

```ts
import { chromium } from 'playwright-extra';
import stealth from 'puppeteer-extra-plugin-stealth';
chromium.use(stealth());

/** Mapping country_code → timezone + locale par défaut. */
export function getTimezoneForCountry(cc: string): string {
  return {
    FR: 'Europe/Paris', BE: 'Europe/Brussels', CH: 'Europe/Zurich',
    DE: 'Europe/Berlin', GB: 'Europe/London', ES: 'Europe/Madrid',
    IT: 'Europe/Rome', NL: 'Europe/Amsterdam',
    US: 'America/New_York', CA: 'America/Toronto', UK: 'Europe/London',
  }[cc.toUpperCase()] ?? 'UTC';
}

export function localeForCountry(cc: string): string {
  return {
    FR: 'fr-FR', BE: 'fr-BE', CH: 'de-CH', DE: 'de-DE', GB: 'en-GB',
    ES: 'es-ES', IT: 'it-IT', NL: 'nl-NL', US: 'en-US', CA: 'en-CA',
  }[cc.toUpperCase()] ?? 'en-US';
}

export function acceptLanguageForCountry(cc: string): string {
  return {
    FR: 'fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
    BE: 'fr-BE,fr;q=0.9,nl-BE;q=0.8,en;q=0.7',
    DE: 'de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7',
    US: 'en-US,en;q=0.9',
    GB: 'en-GB,en;q=0.9',
  }[cc.toUpperCase()] ?? 'en-US,en;q=0.9';
}

export async function buildContext(uaConfig: UserAgentConfig, proxy: ProxyLease) {
  const cc = proxy.countryCode ?? 'FR';
  const timezoneId = getTimezoneForCountry(cc);
  const locale = localeForCountry(cc);
  const acceptLanguage = acceptLanguageForCountry(cc);

  const browser = await chromium.launch({
    proxy: proxy.toPlaywrightOption(),
    headless: true,
    args: [
      '--disable-blink-features=AutomationControlled',  // bypass navigator.webdriver detection
      '--disable-features=IsolateOrigins,site-per-process',
    ],
  });
  const ctx = await browser.newContext({
    userAgent: uaConfig.uaString,
    viewport: uaConfig.fingerprint.viewport,
    locale,                                            // 🔑 cohérent avec proxy country
    timezoneId,                                        // 🔑 cohérent avec proxy country
    extraHTTPHeaders: {
      ...uaConfig.fingerprint.headers,
      'Accept-Language': acceptLanguage,               // 🔑 cohérent avec proxy country
    },
    deviceScaleFactor: uaConfig.fingerprint.device_pixel_ratio,
  });
  // Override navigator.* + WebGL/Canvas randomization
  await ctx.addInitScript((fp) => {
    Object.defineProperty(navigator, 'languages', { get: () => fp.languages });
    Object.defineProperty(navigator, 'hardwareConcurrency', { get: () => fp.hardware_concurrency });
    Object.defineProperty(navigator, 'deviceMemory', { get: () => fp.device_memory });
    // Canvas fingerprint noise (anti-detection)
    const origGetContext = HTMLCanvasElement.prototype.getContext;
    HTMLCanvasElement.prototype.getContext = function(...args) {
      const ctx = origGetContext.apply(this, args);
      if (args[0] === '2d' && ctx) {
        const origFillText = ctx.fillText;
        ctx.fillText = function(...a) { return origFillText.apply(this, a); }; // hookable for noise injection
      }
      return ctx;
    };
  }, uaConfig.fingerprint);
  return { browser, ctx };
}
```

### Sélection par weighted round-robin

```php
final class UserAgentRotator
{
    public function pick(): UserAgent
    {
        // Pondéré par 'weight' qui reflète la fréquence d'usage réelle dans le web (Chrome 60%, Firefox 5%, Safari 25%, Edge 8%, autres 2%)
        $candidates = UserAgent::query()->where('enabled', true)->get();
        $totalWeight = $candidates->sum('weight');
        $rand = random_int(1, $totalWeight);
        $cumulative = 0;
        foreach ($candidates as $ua) {
            $cumulative += $ua->weight;
            if ($rand <= $cumulative) {
                $ua->update(['last_used_at' => now()]);
                return $ua;
            }
        }
        return $candidates->first();
    }
}
```

---

## 3. Rotation Cibles géo + sectorielles

### Pourquoi ?

Si le scraper Google Maps frappe `Paris 75 + NAF 6201Z` 200 fois dans la même heure, Google détecte un pattern et bannit. La solution : rotation des cibles entre les scrapers, avec **cooldown 24h par couple (zone, secteur)**.

### Table `scraper_rotation_state`

```sql
-- Voir fichier 03 — colonnes : id, workspace_id, scraper_key, current_zone JSONB, cooldown_until, last_target_id, ...
```

### Algorithme de sélection prochaine cible

```php
final class TargetRotator
{
    /** Sélectionne la prochaine cible (zone × secteur) pour un scraper donné. */
    public function next(string $scraperKey, int $workspaceId): ?ScraperTarget
    {
        return DB::transaction(function () use ($scraperKey, $workspaceId) {
            // 1. Sélection optimiste avec FOR UPDATE SKIP LOCKED pour éviter race condition
            $target = ScraperTarget::query()
                ->where('workspace_id', $workspaceId)
                ->where('source_key', $scraperKey)
                ->where('state', 'pending')
                ->where(fn ($q) => $q->whereNull('cooldown_until')->orWhere('cooldown_until', '<', now()))
                ->where(fn ($q) => $q->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', now()))
                ->orderByDesc('priority')
                ->orderBy('scheduled_for')
                ->lockForUpdate()           // SELECT FOR UPDATE SKIP LOCKED
                ->first();
            if (!$target) return null;
            $target->update([
                'state' => 'running',
                'attempts' => $target->attempts + 1,
            ]);
            // 2. Mettre la zone en cooldown 24h pour ce scraper
            ScraperRotationState::updateOrCreate(
                ['workspace_id' => $workspaceId, 'scraper_key' => $scraperKey],
                [
                    'current_zone' => $target->target_payload,
                    'last_target_id' => $target->id,
                    'cooldown_until' => now()->addDay(),
                    'state' => 'running',
                    'last_run_at' => now(),
                ]
            );
            return $target;
        });
    }
}
```

### Priorisation

Le champ `scraper_targets.priority` (0..100) permet à l'admin de booster certaines zones :
- Paris + NAF Tech → priority 90
- Zone "ETI 250-2000 + maturité en_cours" → priority 85
- Petites communes < 5000 hab → priority 30 (skip puisque `cities.scrape_eligible = FALSE`)

---

## 4. Rotation Comptes LinkedIn

### Pourquoi 3+ comptes minimum

LinkedIn impose ~80 profils/jour/compte sur Sales Navigator avant rate-limit. Avec 1 seul compte, on plafonne à ~2400 profils/mois. Avec 3 comptes → ~7200/mois.

### États du compte (`linkedin_accounts.status`)

| État | Description | Transition auto |
|---|---|---|
| `active` | Utilisable | → `rate_limited` si daily_used = daily_limit |
| `rate_limited` | Quota du jour épuisé | → `active` à minuit (reset daily_used) |
| `cooldown` | Pause forcée 6-24h après alerte | → `active` après cooldown_until |
| `suspicious` | Captcha détecté lors PB → besoin re-login manuel | → manuel `active` après vérif Will |
| `banned` | Compte définitivement perdu | terminal — créer un nouveau compte |

### Rotation weighted round-robin

```php
final class LinkedInAccountRotator
{
    public function pickActive(int $workspaceId): ?LinkedInAccount
    {
        return LinkedInAccount::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->where('daily_used', '<', DB::raw('daily_limit'))
            ->orderBy('last_used_at', 'asc')      // round-robin LRU
            ->first();
    }

    public function incrementUsage(int $accountId): void
    {
        $account = LinkedInAccount::find($accountId);
        $account->increment('daily_used');
        $account->last_used_at = now();
        if ($account->daily_used >= $account->daily_limit) {
            $account->status = 'rate_limited';
        }
        $account->save();
    }
}
```

### Reset quotidien

Job nightly `ResetLinkedInDailyUsageJob` à 00:05 :

```php
public function handle(): void
{
    LinkedInAccount::query()
        ->where('status', 'rate_limited')
        ->update(['daily_used' => 0, 'status' => 'active']);
}
```

### Health checks comptes LinkedIn

Job hourly `LinkedInAccountHealthJob` :
- Pour chaque compte `active`, appeler PhantomBuster "lite check" (1 visite profil random) → si captcha détecté → state `suspicious`
- Insertion `linkedin_account_health` avec evidence (screenshot Phantom)
- Notif Telegram à Will : "Compte LinkedIn 'axion-pierre' suspicious — re-login requis"

---

## 5. Rotation LLM Providers

Géré par `LlmRouterOrchestrator` (cf fichier 07). Récapitulatif :

- Chaque use case a un `primary_provider` + `fallback_chain` configurable runtime
- En cas d'erreur 5xx ou rate limit du primary, on bascule sur le suivant
- Cost tracking + alerte si fallback rate > 10 % sur un use case

---

## 6. Dashboard "Rotations en cours" (wireframe)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  ROTATIONS DASHBOARD                                                          │
├─────────────────────────────────────────────────────────────────────────────┤
│  [Proxies]  [User-Agents]  [Cibles géo]  [LinkedIn]  [LLMs]                  │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │ PROXIES (Webshare + IPRoyal)                                            │ │
│  │  ┌────────────────────┬──────────────────┐                              │ │
│  │  │  Webshare datacenter│  IPRoyal résidentiel                            │ │
│  │  │  IPs actives : 87/100│  Sessions actives : 12                          │ │
│  │  │  Success 24h : 92.4% │  Success 24h : 88.1%                            │ │
│  │  │  Latence p95 : 250ms │  Latence p95 : 680ms                            │ │
│  │  │  Budget : 8/10€      │  Budget : 64/80€                                │ │
│  │  └────────────────────┴──────────────────┘                              │ │
│  │  [Voir détail proxies] [Ajouter provider]                                │ │
│  └────────────────────────────────────────────────────────────────────────┘ │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │ USER-AGENTS                                                              │ │
│  │  52 user-agents actifs   Refresh dernier : 2026-05-01                   │ │
│  │  Distribution effective dernières 24h :                                 │ │
│  │  ┌─────────┬─────────┐                                                  │ │
│  │  │ Chrome desktop   58.2%  ███████████████████                          │ │
│  │  │ Safari mobile    19.4%  ██████                                       │ │
│  │  │ Firefox desktop   8.1%  ██                                            │ │
│  │  │ Edge desktop      7.8%  ██                                            │ │
│  │  │ Chrome mobile     4.9%  █                                             │ │
│  │  └─────────┴─────────┘                                                  │ │
│  │  [Forcer refresh pool] [Voir détail]                                    │ │
│  └────────────────────────────────────────────────────────────────────────┘ │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │ CIBLES GÉOGRAPHIQUES + SECTORIELLES                                     │ │
│  │  scraper gmaps      | en cooldown jusque 2026-05-17 14:23  zone: Paris  │ │
│  │  scraper pj         | running                                    zone: Lyon  │ │
│  │  scraper website    | running x12 parallèle                              │ │
│  │  scraper linkedin   | en cooldown LinkedIn account axion-marie  jusque demain │ │
│  │  [Voir queue détaillée]                                                  │ │
│  └────────────────────────────────────────────────────────────────────────┘ │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │ COMPTES LINKEDIN (3)                                                     │ │
│  │  axion-pierre@... | active   | 24/80 profils aujourd'hui  | ✓           │ │
│  │  axion-marie@...  | rate_lim | 80/80 profils  reset minuit                 │ │
│  │  axion-jean@...   | suspicious | ⚠️ captcha détecté    [Re-login]        │ │
│  │  [Ajouter compte] [Voir health log]                                       │ │
│  └────────────────────────────────────────────────────────────────────────┘ │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │ LLM PROVIDERS                                                            │ │
│  │  Anthropic Claude  | OK  | 234 calls/h | p95 1.2s   | fallback rate 0%   │ │
│  │  OpenAI            | OK  | 18 calls/h  | p95 800ms  | fallback rate 0%   │ │
│  │  Mistral           | OK  | 502 calls/h | p95 350ms  | fallback rate 0%   │ │
│  │  OpenRouter        | OFF |                                                 │ │
│  │  Ollama (local)    | OFF | activation manuelle Sprint 11                 │ │
│  └────────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 7. Algorithme weighted round-robin pondéré par santé

Pattern récurrent dans Axion CRM Pro :

```php
final class WeightedRoundRobin
{
    /**
     * @param array $items chaque item = ['id' => int, 'weight' => int, 'health' => 0..100]
     */
    public function pick(array $items): mixed
    {
        $effectiveWeights = collect($items)->map(fn ($i) => [
            'id' => $i['id'],
            'effective' => $i['weight'] * max(0.1, $i['health'] / 100),  // health 0% → poids effectif 10%
        ]);
        $total = $effectiveWeights->sum('effective');
        $rand = mt_rand(1, (int) ($total * 100)) / 100;
        $cumul = 0;
        foreach ($effectiveWeights as $item) {
            $cumul += $item['effective'];
            if ($rand <= $cumul) return $item['id'];
        }
        return $effectiveWeights->last()['id'];
    }
}
```

Utilisé pour : sélection proxies, sélection user-agents, sélection comptes LinkedIn.

---

## 8. Tables référencées

Synthèse des tables impliquées dans les rotations (déjà définies fichier 03) :

| Table | Rôle |
|---|---|
| `proxies` | Pool d'IPs, weight + status |
| `proxy_health_checks` | Pings périodiques |
| `proxy_usage_log` | Trace par requête (partitionnée jour) |
| `user_agents` | Pool 50+ user-agents + fingerprints |
| `scraper_rotation_state` | État courant par scraper × workspace |
| `linkedin_accounts` | Comptes Sales Nav + état |
| `linkedin_account_health` | Historique captchas/bans |
| `rotation_events` | Audit log toutes rotations |

---

## 9. Critères de done (S10)

- [ ] User-agent pool stocké 52 user-agents avec fingerprints cohérents
- [ ] Rotation user-agents : aucune même UA utilisée 2 fois consécutives sur le même worker
- [ ] Cool-down de 24h respecté sur cibles déjà scrapées (zéro double scraping détecté en 7 jours d'analyse)
- [ ] 3 comptes LinkedIn rotent automatiquement sans dépassement quota
- [ ] Dashboard "Rotations" affiche état temps réel sans refresh manuel
- [ ] Health checks proxies réussissent sur 95 %+ des IPs actives

---

## 10. Anti-patterns interdits

- ❌ User-agent unique stocké en `.env` (= fingerprint stable détectable)
- ❌ Scraper qui ignore le cooldown 24h sur les cibles (re-scrape illimité)
- ❌ Compte LinkedIn unique qui frappe 200+ profils/jour (ban garanti)
- ❌ Lock contention sur `scraper_targets` (utiliser SKIP LOCKED)
- ❌ Update direct `proxies.status` sans passer par le service (perte audit)

---

## Prochaine étape

→ Lire `11_carte_france_interactive.md` pour la carte France interactive 100 % gratuite.
