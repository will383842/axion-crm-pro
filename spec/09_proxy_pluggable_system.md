# 09 — SYSTÈME DE PROXIES PLUGGABLE

## Vue d'ensemble

Axion CRM Pro utilise un système de proxies **pluggable** : chaque provider de proxies (Webshare, IPRoyal, Smartproxy, BrightData) est implémenté comme un plugin séparé respectant une interface PHP commune `ProxyProvider`. Ajouter un nouveau provider = écrire **un seul fichier ~150 lignes** + ajouter une ligne dans la table `proxy_providers`.

Au-dessus des providers, un **routeur intelligent** (`ProxyRouter`) sélectionne pour chaque requête le meilleur proxy à utiliser en fonction de 4 critères : (1) **domaine cible** (certains domaines exigent résidentiel), (2) **budget restant** sur le mois, (3) **santé actuelle** des proxies disponibles, (4) **historique de succès** sur ce domaine.

Le système gère les **bans** et **rate limits** via cooldowns automatiques, et expose une UI admin pour ajouter/désactiver des providers, configurer leur budget mensuel, et voir les métriques temps réel.

---

## 1. Interface PHP `ProxyProvider`

```php
namespace App\Modules\Proxies\Contracts;

use App\Modules\Proxies\Dto\ProxyLease;
use App\Modules\Proxies\Dto\ProxyStats;

interface ProxyProvider
{
    public function key(): string;                       // 'webshare', 'iproyal', 'smartproxy', 'brightdata'
    public function type(): string;                      // 'datacenter' | 'residential' | 'mobile' | 'isp'

    /** Liste les proxies disponibles via l'API du provider (sync DB) */
    public function listProxies(): array;

    /** Récupère un proxy pour un domaine cible */
    public function lease(string $targetDomain, array $constraints = []): ?ProxyLease;

    /** Notifie au provider qu'un proxy a échoué (pour rotation interne du provider si supporté) */
    public function reportFailure(int $proxyId, string $reason): void;

    /** Stats consommation du mois courant */
    public function monthlyStats(): ProxyStats;
}

final class ProxyLease
{
    public function __construct(
        public readonly int $proxyId,
        public readonly string $ip,
        public readonly int $port,
        public readonly string $protocol,
        public readonly ?string $username,
        public readonly ?string $password,
        public readonly string $providerKey,
        public readonly string $type,
    ) {}

    public function toPlaywrightProxyOption(): array
    {
        return [
            'server' => "{$this->protocol}://{$this->ip}:{$this->port}",
            'username' => $this->username,
            'password' => $this->password,
        ];
    }
}
```

---

## 2. Les 4 implémentations détaillées

### 2.1 `WebshareProvider` (datacenter, économique)

- **Coût démarrage :** 10 $/mois pour 100 IPs.
- **Type :** datacenter (rapide, banni des sites premium type LinkedIn / Crunchbase).
- **API :** `https://proxy.webshare.io/api/v2/proxy/list/` avec Bearer token.

```php
final class WebshareProvider implements ProxyProvider
{
    public function __construct(private HttpClient $http, private SecretsVault $vault, private ProxyRepository $repo) {}

    public function key(): string { return 'webshare'; }
    public function type(): string { return 'datacenter'; }

    public function listProxies(): array
    {
        $token = $this->vault->get('kv/proxies/webshare/api_token');
        $r = $this->http->get('https://proxy.webshare.io/api/v2/proxy/list/', [
            'headers' => ['Authorization' => "Token {$token}"],
            'query' => ['mode' => 'direct', 'page_size' => 100],
        ]);
        return collect($r->json()['results'])->map(fn($p) => [
            'ip' => $p['proxy_address'],
            'port' => $p['port'],
            'protocol' => 'http',
            'username' => $p['username'],
            'password' => $p['password'],
            'country_code' => $p['country_code'],
        ])->all();
    }

    public function lease(string $targetDomain, array $constraints = []): ?ProxyLease
    {
        // Webshare = pas d'API de lease. On pioche dans notre DB synchronisée.
        $proxy = $this->repo->pickHealthy($this->key(), $constraints);
        if (!$proxy) return null;
        return new ProxyLease(
            proxyId: $proxy->id,
            ip: $proxy->ip,
            port: $proxy->port,
            protocol: $proxy->protocol,
            username: $proxy->username,
            password: $proxy->password_plain ?? null,
            providerKey: $this->key(),
            type: $this->type(),
        );
    }

    public function reportFailure(int $proxyId, string $reason): void
    {
        $this->repo->incrementFailure($proxyId, $reason);
    }

    public function monthlyStats(): ProxyStats
    {
        // Webshare API ne donne pas la consommation. On agrège depuis proxy_usage_log local.
        return $this->repo->monthlyStatsFromUsageLog($this->key());
    }
}
```

### 2.2 `IPRoyalProvider` (résidentiel, milieu de gamme)

- **Coût démarrage :** ~50-80 $/mois pour pool résidentiel rotatif.
- **Type :** résidentiel (bypasse anti-bots agressifs : Cloudflare BM, DataDome, etc.).
- **API :** `https://dashboard.iproyal.com/api/...`

Particularité : IPRoyal expose un **endpoint unique** (`p.iproyal.com:12321`) qui rotate les IPs automatiquement. Le `lease()` retourne donc toujours le même endpoint mais avec un username différent (ex: `axion-session-<uuid>:password`) pour forcer une nouvelle IP.

```php
public function lease(string $targetDomain, array $constraints = []): ?ProxyLease
{
    $sessionId = 'axion-' . bin2hex(random_bytes(6));
    return new ProxyLease(
        proxyId: 0, // proxy "virtuel" géré par IPRoyal
        ip: 'p.iproyal.com',
        port: 12321,
        protocol: 'http',
        username: $this->credentials['user'] . "-session-{$sessionId}",
        password: $this->credentials['password'],
        providerKey: $this->key(),
        type: 'residential',
    );
}
```

### 2.3 `SmartproxyProvider` (résidentiel premium)

- **Coût démarrage :** ~75-150 $/mois (volumes croissants).
- **Type :** résidentiel premium (IPs résidentielles US/UK/FR validées + rotation intelligente).
- **API :** session sticky possible (`session-<id>-time-30`) pour 30 minutes de stabilité d'IP.
- **Best for :** Crunchbase, Société.com, scraping sites premium.

### 2.4 `BrightDataProvider` (massif)

- **Coût démarrage :** ~300-500 $/mois (volumes massifs / mobile).
- **Type :** datacenter + résidentiel + mobile + ISP. Le provider le plus complet (ex Luminati).
- **API :** endpoint dédié par zone géographique + type.
- **Best for :** scale ≥ 500k requêtes/mois. À activer en S11+ seulement si volumétrie justifie.

---

## 3. Routeur intelligent `ProxyRouter`

```php
namespace App\Modules\Proxies;

use App\Modules\Proxies\Contracts\ProxyProvider;
use App\Modules\Proxies\Dto\ProxyLease;
use Illuminate\Support\Collection;

final class ProxyRouter
{
    /** @var Collection<ProxyProvider> */
    private Collection $providers;

    public function __construct(
        ProxyRegistry $registry,
        private ProxyHealthService $health,
        private ProxyBudgetService $budget,
        private DomainProfileService $profiles,
    ) {
        $this->providers = $registry->enabledProviders();
    }

    /**
     * Sélectionne le meilleur proxy pour le domaine cible donné.
     * @param array $opts ['preferred_type' => 'residential', 'workspace_id' => 1, 'scraper_run_id' => 12345]
     */
    public function leaseFor(string $targetDomain, array $opts = []): ?ProxyLease
    {
        $profile = $this->profiles->forDomain($targetDomain);
        // 1. Filtrer par type compatibles
        $eligible = $this->providers->filter(function (ProxyProvider $p) use ($profile, $opts) {
            if (isset($opts['preferred_type']) && $p->type() !== $opts['preferred_type']) return false;
            if ($profile->forbidsType($p->type())) return false;            // ex: societe.com interdit datacenter
            if ($profile->requiresType() && $p->type() !== $profile->requiresType()) return false;
            return true;
        });

        // 2. Filtrer par budget mensuel restant
        $eligible = $eligible->filter(fn ($p) => $this->budget->hasRemainingBudget($p->key()));

        // 3. Trier par score = (success_rate × 0.5) + (low_latency × 0.2) + (low_usage × 0.2) + (budget_left × 0.1)
        $scored = $eligible->map(fn ($p) => [
            'provider' => $p,
            'score' => $this->scoreProvider($p, $targetDomain),
        ])->sortByDesc('score');

        foreach ($scored as $entry) {
            $lease = $entry['provider']->lease($targetDomain, $opts);
            if ($lease !== null) {
                $this->budget->recordLease($entry['provider']->key(), $targetDomain);
                return $lease;
            }
        }
        return null;
    }

    public function reportSuccess(ProxyLease $lease, string $domain, int $latencyMs, int $bytes): void
    {
        $this->health->recordSuccess($lease, $domain, $latencyMs, $bytes);
    }

    public function reportFailure(ProxyLease $lease, string $domain, string $reason): void
    {
        $this->health->recordFailure($lease, $domain, $reason);
        $provider = $this->providers->firstWhere(fn($p) => $p->key() === $lease->providerKey);
        $provider?->reportFailure($lease->proxyId, $reason);
    }

    private function scoreProvider(ProxyProvider $p, string $domain): float
    {
        $stats = $this->health->statsForProviderAndDomain($p->key(), $domain);
        $successRate = $stats->successRate24h / 100;       // 0..1
        $latencyScore = max(0, 1 - ($stats->avgLatencyMs / 5000));
        $usageScore = 1 - ($stats->usage24h / max(1, $stats->capacity));
        $budgetLeft = $this->budget->remainingPct($p->key()) / 100;
        return ($successRate * 0.5) + ($latencyScore * 0.2) + ($usageScore * 0.2) + ($budgetLeft * 0.1);
    }
}
```

---

## 4. Profile par domaine (`DomainProfileService`)

Stocke des règles par domaine cible. Configurable via UI admin (table `scraping_sources.config.proxy_profile`).

```php
final class DomainProfile
{
    public function __construct(
        public readonly string $domain,
        public readonly ?string $requiresType,
        public readonly array $forbidsTypes,
        public readonly bool $stickySessionRequired,
    ) {}

    public function forbidsType(string $type): bool { return in_array($type, $this->forbidsTypes); }
}

const DOMAIN_PROFILES = [
    'societe.com'           => ['requires' => 'residential', 'forbids' => ['datacenter']],
    'pagesjaunes.fr'        => ['requires' => null,           'forbids' => []],
    'google.com'            => ['requires' => 'residential', 'forbids' => ['datacenter']],
    'crunchbase.com'        => ['requires' => 'residential', 'forbids' => ['datacenter']],
    'linkedin.com'          => ['requires' => null,           'forbids' => []],   // utilisé via PB, pas proxies direct
    'default'               => ['requires' => null,           'forbids' => []],
];
```

---

## 5. Health checks périodiques

Job `BatchProxyHealthCheckJob` toutes les 15 min :

```php
final class BatchProxyHealthCheckJob implements ShouldQueue
{
    public function handle(ProxyHealthService $health): void
    {
        // Pour chaque proxy actif dans DB, lancer 3 checks parallèles sur cibles HTTPS rapides
        $proxies = Proxy::query()->where('status', 'active')->get();
        $proxies->each(function ($proxy) use ($health) {
            dispatch(new ProxyHealthCheckJob($proxy->id, 'https://httpbin.org/ip'))->onQueue('proxy-health');
            dispatch(new ProxyHealthCheckJob($proxy->id, 'https://ipinfo.io/json'))->onQueue('proxy-health');
            dispatch(new ProxyHealthCheckJob($proxy->id, 'https://api64.ipify.org/?format=json'))->onQueue('proxy-health');
        });
    }
}
```

Chaque check insère une ligne dans `proxy_health_checks`. Le `success_rate_24h` de chaque proxy est recalculé par `UpdateProxyHealthScoreJob` (hourly).

---

## 6. Auto-disable / cool-down sur ban

```php
final class ProxyBanDetector
{
    public function detect(ProxyLease $lease, string $domain, array $signals): bool
    {
        $bansDetected = collect($signals)->filter(fn($s) => in_array($s, [
            'captcha_recaptcha', 'cloudflare_403', 'http_429_persistent', 'soft_block_redirect',
            'datadome_block', 'akamai_bot_management',
        ]))->count();
        return $bansDetected > 0;
    }

    public function applyCooldown(ProxyLease $lease, string $domain): void
    {
        $proxy = Proxy::find($lease->proxyId);
        if (!$proxy) return;
        $proxy->update([
            'status' => 'cooldown',
            'cooldown_until' => now()->addHours(24),
        ]);
        RotationEvent::create([
            'workspace_id' => $proxy->workspace_id,
            'rotation_type' => 'proxy',
            'event' => 'ban_detected',
            'entity_type' => 'proxy',
            'entity_id' => $proxy->id,
            'detail' => ['domain' => $domain, 'cooldown_until' => $proxy->cooldown_until->toIso8601String()],
        ]);
    }
}
```

Après 24h, status repassé `active` automatiquement par job nightly `RestoreCooledProxiesJob`. Si 3 cooldowns successifs sur le même proxy → status `disabled` permanent (humaine intervention).

---

## 7. UI admin gestion proxies

Voir fichier 13 pour le wireframe complet de la page "Proxy providers". Fonctionnalités principales :

- **Liste des providers** (Webshare, IPRoyal, Smartproxy, BrightData) avec status enabled/disabled
- **Métriques temps réel par provider** : nombre d'IPs actives, success rate 24h, latence moyenne, coût mensuel actuel vs budget
- **Budget mensuel éditable** (€ / mois) avec alerte à 80 %, 95 %, 100 %
- **Bouton "Ajouter un nouveau provider"** → modal demandant `provider_key` + `api_endpoint` + `api_key` (stocké en vault) → instancie une nouvelle ligne `proxy_providers`
- **Bouton "Tester maintenant"** sur un provider → lance health check + 3 requêtes test sur httpbin
- **Per-proxy drill-down** : pour Webshare, table des 100 IPs avec status (active/cooldown/disabled/banned), success rate, dernière utilisation, country, ASN, bouton "Désactiver"
- **Per-domain stats** : sur quels domaines un provider performe bien/mal

---

## 8. Stratégie évolutive

| Phase | Volume scraping | Stack proxies | Coût €/mois |
|---|---|---|---|
| **Démarrage S1-S5** | 10k-50k req/jour | Webshare seul | ~10 € |
| **Croissance S6-S8** | 50k-200k req/jour | + IPRoyal résidentiel | ~60-90 € |
| **Scale S9-S12** | 200k-500k req/jour | + Smartproxy premium | ~140-240 € |
| **Massif Phase 2+** | 500k+ req/jour | + BrightData mobile | ~440-740 € |

Le routeur intelligent rend la migration totalement **transparente** : ajouter Smartproxy ne nécessite **aucun changement de code**, juste un POST `/api/proxies/providers` depuis l'admin.

---

## 9. Tableau coûts détaillé V1 (200k req/jour cible)

| Provider | Type | IPs | Coût mois |
|---|---|---|---|
| Webshare | datacenter | 100 | 10 € |
| IPRoyal | résidentiel rotating | endpoint unique | 50-80 € |
| Smartproxy | résidentiel premium | endpoint sticky | optionnel, 75 € |
| BrightData | mobile | endpoint dédié | OFF en V1 |
| **Total V1** | | | **60-165 €/mois** |

---

## 10. Tests d'acceptance (S2 + S10)

- [ ] Ajouter un nouveau provider via admin sans redéploiement fonctionne
- [ ] Désactiver un provider en cours d'exécution → trafic re-routé vers les autres en < 30s
- [ ] Cooldown automatique fonctionne sur capture captcha
- [ ] Budget mensuel respecté à ±5 %
- [ ] Health check job tourne sans erreur sur 15 minutes
- [ ] Score routing favorise les providers en bonne santé

---

## 11. Anti-patterns interdits

- ❌ Hardcoder un proxy unique dans une variable env (par-source)
- ❌ Skipper le ProxyRouter pour appeler directement un provider
- ❌ Stocker des credentials proxies en clair en DB
- ❌ Ne pas log les `proxy_usage_log` (perte traçabilité)
- ❌ Désactiver les cooldowns "pour gagner du temps" (= ban du compte provider)

---

## Prochaine étape

→ Lire `10_rotations_universelles.md` pour les 5 dimensions de rotation.
