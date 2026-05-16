# 09 — Système de proxies pluggable

> **Doctrine :** Ajouter un nouveau provider proxy = coder 1 fichier (~150 lignes) implémentant `ProxyProvider`. Aucune modification du core.
> **Démarrage :** Webshare datacenter (~10 $/mois). Croissance : + IPRoyal résidentiel (~30-50 $/mois). Scale : + Smartproxy ou BrightData.
> **Routeur intelligent :** sélection par domaine cible + budget restant + santé + historique succès.

---

## §1 — Interface `ProxyProvider`

```php
// app/Contracts/ProxyProvider.php
namespace App\Contracts;

use App\Data\ProxyData;
use App\Data\ProxyHealthData;

interface ProxyProvider
{
    public function slug(): string;                 // 'webshare'|'iproyal'|'smartproxy'|'brightdata'
    public function label(): string;
    public function type(): string;                 // 'datacenter'|'residential'|'mobile'|'isp'

    /** Liste toutes les IPs disponibles du provider (refresh quotidien) */
    public function listAvailable(): array;         // array of ProxyData

    /** Acquire 1 proxy pour un domaine cible (selon stratégie sticky/rotating) */
    public function acquire(string $targetDomain, array $hints = []): ProxyData;

    /** Health check d'un proxy */
    public function healthCheck(ProxyData $p): ProxyHealthData;

    /** Quota mensuel restant (GB) */
    public function remainingQuota(): ?float;

    /** Coût mensuel actuel */
    public function monthlySpent(): float;
}
```

### DTOs

```php
class ProxyData extends Data
{
    public function __construct(
        public string $providerSlug,
        public string $proxyUrl,                    // 'http://user:pass@host:port'
        public ?string $externalIp,
        public ?string $country,
        public ?string $city,
        public int $proxyId,
        public array $tags = [],                    // ['residential','geo-fr']
    ) {}
}

class ProxyHealthData extends Data
{
    public function __construct(
        public bool $isAlive,
        public ?int $latencyMs,
        public ?string $ipObserved,
        public ?string $error,
    ) {}
}
```

---

## §2 — 4 implémentations

### Webshare (datacenter, démarrage)

```php
// app/Services/Proxies/Providers/WebshareProvider.php
class WebshareProvider implements ProxyProvider
{
    public function slug(): string { return 'webshare'; }
    public function type(): string { return 'datacenter'; }

    public function listAvailable(): array
    {
        $resp = Http::withToken($this->apiKey)
            ->get('https://proxy.webshare.io/api/v2/proxy/list/?page_size=100');
        return collect($resp->json('results'))->map(fn($p) => new ProxyData(
            providerSlug: 'webshare',
            proxyUrl: "http://{$p['username']}:{$p['password']}@{$p['proxy_address']}:{$p['port']}",
            externalIp: $p['proxy_address'],
            country: $p['country_code'],
            city: $p['city_name'] ?? null,
            proxyId: 0,
            tags: ['datacenter'],
        ))->toArray();
    }

    public function acquire(string $targetDomain, array $hints = []): ProxyData
    {
        // Stratégie : round-robin sur les proxies actifs cooldown_until <= now()
        return $this->pickRoundRobin($targetDomain);
    }

    public function healthCheck(ProxyData $p): ProxyHealthData
    {
        $start = microtime(true);
        try {
            $resp = Http::withOptions(['proxy' => $p->proxyUrl])
                ->timeout(8)
                ->get('https://api.ipify.org?format=json');
            return new ProxyHealthData(
                isAlive: $resp->ok(),
                latencyMs: (int)((microtime(true) - $start) * 1000),
                ipObserved: $resp->json('ip'),
            );
        } catch (\Throwable $e) {
            return new ProxyHealthData(isAlive: false, latencyMs: null, ipObserved: null, error: $e->getMessage());
        }
    }

    public function remainingQuota(): ?float { /* GET /api/v2/subscription/ */ return null; }
    public function monthlySpent(): float { /* depuis API ou DB */ return $this->spent; }
}
```

### IPRoyal (résidentiels)

```php
class IPRoyalProvider implements ProxyProvider
{
    public function slug(): string { return 'iproyal'; }
    public function type(): string { return 'residential'; }

    public function listAvailable(): array
    {
        // IPRoyal = gateway unique avec rotation auto serveur-side
        // Configuration via username extension (country, city, session)
        return [new ProxyData(
            providerSlug: 'iproyal',
            proxyUrl: "http://{$this->user}_country-fr:{$this->pass}@geo.iproyal.com:12321",
            externalIp: null,
            country: 'FR',
            city: null,
            proxyId: 0,
            tags: ['residential','geo-fr','rotating'],
        )];
    }

    public function acquire(string $targetDomain, array $hints = []): ProxyData
    {
        // Sticky session si scraping multi-page (linkedin search) sinon rotating
        $session = $hints['session'] ?? null;
        $sessionExt = $session ? "_session-{$session}_lifetime-30m" : '';
        $country = $hints['country'] ?? 'fr';
        return new ProxyData(
            providerSlug: 'iproyal',
            proxyUrl: "http://{$this->user}_country-{$country}{$sessionExt}:{$this->pass}@geo.iproyal.com:12321",
            externalIp: null,
            country: strtoupper($country),
            city: null,
            proxyId: 0,
            tags: ['residential', "geo-{$country}", $session ? 'sticky' : 'rotating'],
        );
    }
}
```

### Smartproxy (résidentiels premium)

Similaire IPRoyal — gateway unique + extension username pour geo/session.

### BrightData (résidentiels premium, scale)

Similaire IPRoyal. Coût élevé (~12-15 $/GB), activé seulement si BrightData < 30% du budget total.

---

## §3 — Routeur intelligent

```php
// app/Services/Proxies/ProxyRouter.php
class ProxyRouter
{
    public function __construct(
        private array $providers,                       // injected via container
        private array $domainProviderMap,               // {'google.com' => ['iproyal','smartproxy'], '*' => ['webshare']}
    ) {}

    public function acquireForJob(ScraperJob $job): ProxyData
    {
        $targetDomain = $this->extractDomain($job->targetUrl);
        $eligibleProviders = $this->selectEligibleProviders($targetDomain, $job);

        foreach ($eligibleProviders as $providerSlug) {
            $provider = $this->providers[$providerSlug];

            // 1. Budget check
            if ($provider->monthlySpent() >= $this->configForProvider($providerSlug)['monthly_budget_eur']) continue;

            // 2. Quota check
            if (($remaining = $provider->remainingQuota()) !== null && $remaining < 0.1) continue;

            // 3. Health-based weighted pick
            $candidates = Proxy::where('provider_slug', $providerSlug)
                ->where('is_active', true)
                ->where(fn($q) => $q->whereNull('cooldown_until')->orWhere('cooldown_until', '<', now()))
                ->orderByRaw('
                    (1.0 - LEAST(1.0, captcha_count::float / NULLIF(success_count + failure_count + captcha_count, 0))) *
                    (1.0 - LEAST(1.0, failure_count::float / NULLIF(success_count + failure_count + 1, 0)))
                    DESC
                ')
                ->limit(5)
                ->get();

            if ($candidates->isEmpty()) continue;

            // 4. Pick weighted
            $pick = $candidates->random();   // simple ; pondéré possible

            return new ProxyData(
                providerSlug: $providerSlug,
                proxyUrl: $pick->proxy_url,
                externalIp: $pick->external_ip,
                country: $pick->country,
                city: $pick->city,
                proxyId: $pick->id,
                tags: [],
            );
        }

        throw new NoProxyAvailableException("No proxy available for {$targetDomain}");
    }

    private function selectEligibleProviders(string $domain, ScraperJob $job): array
    {
        // 1. Domaine spécifique
        foreach ($this->domainProviderMap as $pattern => $providers) {
            if ($pattern === '*') continue;
            if (fnmatch($pattern, $domain)) return $providers;
        }
        // 2. Wildcard
        return $this->domainProviderMap['*'] ?? [];
    }
}
```

### Mapping domaine → providers (RUNTIME-CONFIG dans `scraping_sources.proxy_pool`)

> **P0 audit v1.1** : la stratégie a basculé vers « datacenter dominant + résidentiel ciblé ». Le résidentiel ne sert QUE pour les 3 sources les plus protégées. Tous les sites corporate (Direction Finder Phase 1, sites web entreprises) → datacenter Webshare.

| Domaine | Providers prioritaires | Type | Justification |
|---------|------------------------|------|---------------|
| `google.com`, `google.fr` | IPRoyal résidentiel sticky → Smartproxy résidentiel | Résidentiel | Google bannit aggro datacenter |
| `google.com/maps`, `maps.google.com` | IPRoyal résidentiel sticky → Smartproxy | Résidentiel | Même protection que Google Search |
| `bing.com` | Webshare datacenter → IPRoyal résidentiel | Mixed | Bing plus tolérant DC |
| `duckduckgo.com` | Webshare datacenter | DC | DDG très permissif |
| `pagesjaunes.fr` | Webshare datacenter → IPRoyal si bloqué | Mixed | Cloudflare modéré |
| `crunchbase.com` | IPRoyal résidentiel sticky | Résidentiel | Cloudflare strict |
| **`*` sites corporate ETI/Grandes (Direction Finder)** | **Webshare datacenter** | **DC** | **Sites publics, WAF rare, datacenter suffisant 95 %. P0 audit corrigé.** |
| `*.fr`, `*` (sites web TPE/PME) | Webshare datacenter | DC | 95 % suffit |
| `infogreffe.fr`, `societe.com` | Webshare datacenter → IPRoyal fallback | Mixed | Cloudflare présent mais pas strict |
| `*.linkedin.com` | (INTERDIT Phase 1 — uniquement URLs publiques via Google Search) | — | Doctrine |
| `bodacc-datafluide.echanges.dila.gouv.fr` | (pas de proxy, API officielle) | — | |
| `api.francetravail.io`, `recherche-entreprises.api.gouv.fr` | (pas de proxy, API officielle) | — | |

### Budget impact (P0 audit v1.1)

**Volume mensuel réel ventilé :**

| Source | Bandwidth/mois | Provider | Coût €/mois |
|--------|----------------|----------|--------------|
| Google Search Wrapper (résidentiel sticky) | ~50 GB | IPRoyal | 175-300 |
| Google Maps (résidentiel) | ~120 GB | IPRoyal | 420-700 |
| Crunchbase (résidentiel, volume faible) | ~5 GB | IPRoyal | 20-35 |
| Direction Finder pages corporate (**DC, P0 audit**) | ~80 GB | Webshare DC | 10-25 |
| Direction Finder PDFs rapports annuels (DC) | ~30 GB | Webshare DC | 5-15 |
| Pages Jaunes (DC) | ~40 GB | Webshare DC | 5-10 |
| Sites web TPE/PME (DC) | ~200 GB | Webshare DC | 10-30 |
| Infogreffe / Societe.com (DC) | ~20 GB | Webshare DC | 5 |
| **Total réaliste** | **~545 GB** | | **~650-1100 €/mois** |

**Mitigation P0 audit :** sampling 80/20 stricte au démarrage = top 50k entreprises ciblées (vs 200k discoverables). Budget proxies cible : **300-500 €/mo**.

---

## §4 — Health checks

### Job nightly `app:proxy-health-check`

Pour chaque proxy actif :
1. `healthCheck(proxy)` → INSERT `proxy_health_checks`
2. Si `is_alive = false` pendant 3 checks consécutifs → `cooldown_until = now() + 2h`
3. Si `latency_ms > 5000` pendant 3 checks → cooldown 1h
4. Mise à jour `proxies.success_rate_30d` (computed sur health_checks)

### Auto-disable

Si un provider a < 50% success rate sur 24h → email alerte + désactivation provider entier (admin re-active manuellement).

---

## §5 — UI admin (référencé `13_ui_admin_phase1.md`)

### Page "Proxy Providers"

- Liste des 4 providers + bouton "Ajouter"
- Par provider : gauge budget €, gauge quota GB, success rate 7j/30j, latence p50/p95
- Tabs détail : Active proxies (table), Health checks 7 jours (graph), Usage by domain (table)
- Bouton "Tester" qui acquire 1 proxy et fait health check
- Bouton "Cooldown ce proxy 1h", "Désactiver provider"

### Page "Add new provider" (formulaire dynamique)

- Choix type (datacenter/residential/mobile)
- API credentials (chiffrés via secret manager, jamais stockés en clair DB)
- Budget mensuel, priorité
- Test connexion avant save

---

## §6 — Stratégie évolutive coûts

| Phase | Provider(s) | Coût mensuel | Note |
|-------|-------------|--------------|------|
| **S1-S5** | Webshare 100 proxies datacenter | ~10 $/mois | Suffisant TPE/PME 95% des sources |
| **S6+** | + IPRoyal 1-2 GB résidentiel/mois | +30-50 $/mois | Google Search Wrapper + Direction Finder |
| **An 1 scale 500k+** | + Smartproxy 3-5 GB résidentiel | +75-150 $/mois | Si IPRoyal sature |
| **Massif (an 2+)** | + BrightData | +100-300 $/mois | Si volume > 1M/mois |

**Budget cible Phase 1 :** 30-50 $/mois (Webshare + IPRoyal).

---

## §7 — Logs & métriques

```
axion_crm_proxy_acquire_total{provider,domain}
axion_crm_proxy_failures_total{provider,error}
axion_crm_proxy_health_check_total{provider,status}
axion_crm_proxy_bandwidth_bytes_total{provider}
axion_crm_proxy_cost_eur_total{provider}
axion_crm_proxy_latency_ms_histogram{provider}
```

---

## Lecture suivante

→ `10_rotations_universelles.md` (5 dimensions de rotation + weighted round-robin + dashboard temps réel).
