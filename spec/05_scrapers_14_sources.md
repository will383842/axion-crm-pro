# 05 — SCRAPERS : 14 SOURCES

> **Architecture plugin.** Chaque source implémente l'interface `ScraperPlugin` (PHP) ou `WorkerPlugin` (TypeScript pour Node Playwright). Ajouter une 15e source = créer 1 fichier ~300 lignes + 1 ligne dans `scraping_sources`.
>
> **Anti-pattern interdit :** plafond à 20 pages pour les scrapers paginés. Tous les scrapers paginés continuent jusqu'à la **fin réelle** des résultats (checkpoint dans `scraper_targets.last_page_scraped` + `pagination_meta`).

---

## Interface commune

### PHP — `App\Modules\Sources\Contracts\ScraperPlugin`

```php
namespace App\Modules\Sources\Contracts;

use App\Modules\Sources\Dto\ScrapeRequest;
use App\Modules\Sources\Dto\ScrapeResult;

interface ScraperPlugin
{
    public function key(): string;                       // 'insee', 'annu_ent', 'bodacc', ...
    public function category(): string;                  // 'official_api' | 'public_scraping' | 'third_party_paid'
    public function needsProxy(): bool;
    public function needsPlaywright(): bool;
    public function ttlDays(): int;
    public function rateLimitPerMin(): int;

    /** Une seule unité de scraping (1 SIREN / 1 URL / 1 page d'une zone). */
    public function execute(ScrapeRequest $req): ScrapeResult;
}
```

### TS — `workers/src/plugins/Plugin.ts`

```ts
export interface ScrapeRequest {
  workspaceId: number;
  sourceKey: string;
  targetId?: number;
  payload: Record<string, unknown>;
  paginationMeta?: { page: number; cursor?: string };
}

export interface ScrapeResult {
  status: 'ok' | 'skipped' | 'rate_limited' | 'banned' | 'error' | 'dead_letter';
  contactsFound: number;
  contactsNew: number;
  emailsFound: number;
  emailsValidated: number;
  costEurMicro: number;
  durationMs: number;
  pagination?: { nextPage?: number; hasMore: boolean; cursor?: string };
  payload: Record<string, unknown>;
  errorMessage?: string;
}

export interface WorkerPlugin {
  key: string;
  execute(req: ScrapeRequest): Promise<ScrapeResult>;
}
```

---

## SOURCE 1 — INSEE Sirene API

- **Objectif :** identification + filtrage de masse (SIREN + raison sociale + adresse + NAF + tranche d'effectif + date création).
- **Méthode :** API officielle REST.
- **URL base :** `https://api.insee.fr/entreprises/sirene/V3.11`
- **Auth :** OAuth2 client credentials. Token via `https://api.insee.fr/token`, scope `sirene-v3`.
- **Rate limits :** **30 req/min** (palier gratuit), 60 req/min (palier confirmé après 30 jours).
- **Proxies :** Non requis.
- **Champs récupérés :** `siren`, `siret_head`, `denomination` (raison sociale), `adresseEtablissement.*`, `activitePrincipaleEtablissement` (NAF), `trancheEffectifsEtablissement` (code 00..53), `categorieEntreprise` (TPE/PME/ETI/GE), `dateCreationEtablissement`.
- **Pagination :** `?nombre=1000&debut=0` puis `?debut=1000`, etc. **Sans limite.** Sortie quand `header.nombre` ≥ total.
- **Gestion erreurs :** HTTP 429 → backoff exponentiel 2^n secondes (max 5 min), HTTP 5xx → retry 3× puis dead_letter.
- **Mapping DB :** `companies` (INSERT ou UPDATE sur conflict `siren`).
- **Pseudo-code PHP :**

```php
namespace App\Modules\Sources\Plugins;

final class InseeSirenePlugin implements ScraperPlugin
{
    public function key(): string { return 'insee'; }
    public function category(): string { return 'official_api'; }
    public function needsProxy(): bool { return false; }
    public function needsPlaywright(): bool { return false; }
    public function ttlDays(): int { return 30; }
    public function rateLimitPerMin(): int { return 30; }

    public function __construct(
        private InseeOAuthClient $oauth,
        private HttpClient $http,
        private CompanyUpserter $upserter
    ) {}

    public function execute(ScrapeRequest $req): ScrapeResult
    {
        $token = $this->oauth->token();
        $criteria = $req->payload['siren_query'] ?? $req->payload['naf_zip_filter'];
        $page = $req->paginationMeta->page ?? 0;
        $limit = 1000;

        $response = $this->http->get('https://api.insee.fr/entreprises/sirene/V3.11/siret', [
            'headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'],
            'query'   => ['q' => $criteria, 'nombre' => $limit, 'debut' => $page * $limit],
        ]);

        if ($response->status() === 429) {
            return ScrapeResult::rateLimited(retryAfter: $response->header('Retry-After'));
        }

        $body = $response->json();
        $hasMore = ($page + 1) * $limit < $body['header']['total'];

        foreach ($body['etablissements'] as $etab) {
            $this->upserter->upsertFromInsee($req->workspaceId, $etab);
        }

        return ScrapeResult::ok(
            contactsFound: 0,
            payload: ['siren_count' => count($body['etablissements'])],
            pagination: ['nextPage' => $page + 1, 'hasMore' => $hasMore]
        );
    }
}
```

---

## SOURCE 2 — annuaire-entreprises.data.gouv.fr (REMPLACE PAPPERS)

- **Objectif :** dirigeants légaux + CA + bilans + bénéficiaires effectifs.
- **Méthode :** API officielle (gratuite) + scraping HTML cheerio pour les pages produits si besoin.
- **URL base :** `https://annuaire-entreprises.data.gouv.fr/api/`
- **Auth :** aucune.
- **Rate limits :** auto-imposés 60 req/min.
- **Proxies :** non requis (mais user-agent rotation recommandée).
- **Champs récupérés :** `nom_complet`, `dirigeants[]` (nom + qualité), `bilans[]` (CA, résultat, effectif), `beneficiaires_effectifs[]`, `etat_administratif`.
- **Pagination :** N/A (1 SIREN = 1 fetch).
- **Mapping DB :** `companies.revenue_eur`, `companies.revenue_year`, `contacts` (dirigeants), `company_business_signals` (changement état admin).
- **Pseudo-code PHP :**

```php
$response = $this->http->get("https://annuaire-entreprises.data.gouv.fr/api/recherche?q={$siren}");
$body = $response->json();
foreach ($body['results'][0]['dirigeants'] as $d) {
    $this->contactRepo->upsert([
        'company_id' => $companyId,
        'workspace_id' => $req->workspaceId,
        'full_name' => $d['nom_complet'],
        'position_title' => $d['qualite'],
        'is_legal_representative' => true,
        'source' => 'annu_ent',
        'source_url' => "https://annuaire-entreprises.data.gouv.fr/entreprise/{$siren}",
    ]);
}
```

---

## SOURCE 3 — Infogreffe (FALLBACK)

- **Objectif :** bilans détaillés (compte de résultat 4 dernières années).
- **Méthode :** scraping Playwright (anti-bot léger).
- **URL base :** `https://www.infogreffe.fr/societes/entreprise-societe/`
- **Auth :** aucune.
- **Rate limits :** 30 req/min (auto-imposés).
- **Proxies :** datacenter Webshare.
- **Pagination :** N/A.
- **Anti-bot :** Cloudflare bot challenge léger → playwright-extra stealth suffit.
- **TS Worker :**

```ts
import { chromium } from 'playwright-extra';
import stealth from 'puppeteer-extra-plugin-stealth';
chromium.use(stealth());

export const infogreffeScraper: WorkerPlugin = {
  key: 'infogreffe',
  async execute(req) {
    const browser = await chromium.launch({ proxy: await getProxy(req) });
    const ctx = await browser.newContext({ userAgent: await getUA() });
    const page = await ctx.newPage();
    await page.goto(`https://www.infogreffe.fr/societes/entreprise-societe/${req.payload.siren}-info.html`);
    const bilans = await page.$$eval('table.tbl-bilan tr', rows => rows.map(r => r.innerText));
    await browser.close();
    return { status: 'ok', contactsFound: 0, contactsNew: 0, emailsFound: 0, emailsValidated: 0,
             costEurMicro: 0, durationMs: 0, payload: { bilans } };
  }
};
```

---

## SOURCE 4 — Societe.com (FALLBACK)

- **Objectif :** fallback dirigeants si annuaire-entreprises est insuffisant.
- **Méthode :** **double stack** : `curl-impersonate` Node pour requêtes HTML simples (bypass TLS fingerprint JA3/JA4), Playwright stealth + ghost-cursor pour pages JS-rendered avec captcha.
- **URL base :** `https://www.societe.com/societe/`
- **Rate limits :** 20 req/min (anti-bot agressif → backoff).
- **Proxies :** **résidentiel obligatoire** (datacenter banni 100 %). IPRoyal ou Smartproxy + session sticky 30 min.
- **Anti-bot :** Cloudflare BM + DataDome + reCAPTCHA conditionnel → stratégie 5 couches :
  1. **TLS fingerprint bypass** via `curl-impersonate-chrome` ou lib Node `tls-client` (Playwright Chromium expose un JA3 hash détectable, on l'évite pour les fetch HTML simples)
  2. **Cookie jar persistant inter-sessions** (`storageState` Playwright sauvegardé Redis 24h par session sticky)
  3. **Playwright stealth + fingerprint cohérent** (timezone + locale + headers selon `proxies.country_code` — cf fichier 10 §2)
  4. **Mouse humanization** via `ghost-cursor` + scroll velocity progressive
  5. **CapSolver** pour reCAPTCHA si déclenché — résolution automatique
- **Mapping DB :** `contacts` (avec `source = 'societe'`).
- **PoC empirique avant Sprint 7** : 50 SIREN connus, mesurer taux succès. Si > 20 % d'échecs persistants → escalation BrightData mobile.

---

## SOURCE 5 — BODACC API

- **Objectif :** **Signaux business** (changements de dirigeants, levées de fonds, redressements, créations, radiations).
- **Méthode :** API officielle JSON via DILA.
- **URL base :** `https://bodacc-datadila.opendatasoft.com/api/v2/catalog/datasets/annonces-commerciales/records`
- **Auth :** aucune.
- **Rate limits :** 100 req/min.
- **Champs récupérés :** type d'annonce, date publication, SIREN, description structurée, montants.
- **Mapping DB :** `company_business_signals` (`signal_type` = `change_dirigeant` / `leve_fonds` / `redressement` / `create` / `radiation`).
- **Critère sévérité :** levée > 1M€ → `critical`, changement DSI/DAF/CEO → `high`, autres → `medium`.

```php
$response = $this->http->get('https://bodacc-datadila.opendatasoft.com/api/v2/catalog/datasets/annonces-commerciales/records', [
    'query' => ['where' => "registre = '{$siren}'", 'limit' => 100, 'order_by' => 'dateparution DESC']
]);
foreach ($response->json()['records'] as $rec) {
    $this->signalRepo->insertIfFresh([...]);
}
```

---

## SOURCE 6 — Google Maps

- **Objectif :** téléphone + site web + horaires + avis + photos + coordonnées GPS.
- **Méthode :** **Playwright stealth + CapSolver (captcha solving) + timezone aligné proxy** (pas d'API officielle accessible sans clé payante).
- **URL pattern :** `https://www.google.com/maps/search/{query}/@{lat},{lng},14z`
- **Rate limits :** 1-3 req/min/IP (très strict). **Proxies résidentiels obligatoires.**
- **Proxies :** Smartproxy résidentiel premium (rotation agressive, session sticky 30 min).
- **Pagination :** SANS LIMITE. On scroll jusqu'à "Aucun résultat supplémentaire" (Google retourne ~120 résultats max/recherche). **Checkpoint** : on stocke `last_page_scraped` + dernière coordonnée vue.
- **Anti-bot :** stratégie 4 couches :
  1. **Playwright stealth + cohérence fingerprint** (timezone, locale, viewport, accept-language alignés sur `proxies.country_code` — cf fichier 10 §2bis)
  2. **Mouse humanization** via `ghost-cursor` (mouvements courbes Bezier, vitesse variable 200-800 ms)
  3. **Scroll velocity progressive** (5-15 px/frame variable, pas `scrollIntoViewIfNeeded` brut)
  4. **CapSolver pour reCAPTCHA v2/v3 + hCaptcha** — résolution automatique au lieu d'abandon
- **CapSolver intégration :** API `https://api.capsolver.com/createTask`. Coût ~0,5 $/1000 captchas ≈ **30 €/mois budget initial**, plafonné à 100 €/mois via env `CAPSOLVER_MONTHLY_HARD_CAP_EUR`. Si dépassement → bascule en mode `banned` + cooldown zone (comportement V0 conservé en mode dégradé).
- **PoC empirique obligatoire avant Sprint 4** : tester 100 résultats "Boulangerie Paris 75" sur 1 worker pendant 2 jours. Go/no-go : taux captcha < 5 %, taux ban IP < 1 %, latence p95 < 12 s. Si échec, voir fichier `AUDIT_v1.md` §10 plan de repli (Apify Actor Google Maps ~50 $/mois).
- **TS Worker :**

```ts
import { createCursor } from 'ghost-cursor';
import { CapSolver } from '@/lib/capsolver-client';
import { getTimezoneForCountry } from '@/lib/timezone-by-country';

export const gmapsScraper: WorkerPlugin = {
  key: 'gmaps',
  async execute(req) {
    const { searchQuery, city } = req.payload;
    const proxy = await getProxy({ residential: true, stickySession: true });
    const ua = await getUA();
    const tz = getTimezoneForCountry(proxy.countryCode); // ex: 'America/New_York' si proxy US
    const locale = localeForCountry(proxy.countryCode);  // ex: 'en-US' si proxy US

    const browser = await chromium.launch({ proxy: proxy.toPlaywrightOption() });
    const ctx = await browser.newContext({
      userAgent: ua.uaString,
      locale,
      timezoneId: tz,                                    // 🔑 doit matcher le pays du proxy
      viewport: ua.fingerprint.viewport,
      extraHTTPHeaders: ua.fingerprint.headers,
    });
    const page = await ctx.newPage();
    const cursor = createCursor(page, { x: 100, y: 100 }); // mouvements humains

    await page.goto(`https://www.google.com/maps/search/${encodeURIComponent(searchQuery)}+${encodeURIComponent(city)}`,
      { waitUntil: 'domcontentloaded' });

    // 1. Détection + résolution captcha automatique
    const captchaSelector = '#captcha-form, [src*="recaptcha"], iframe[src*="hcaptcha"]';
    if (await page.$(captchaSelector)) {
      const solved = await CapSolver.solveOnPage(page, {
        type: 'recaptchav2',
        siteKey: await page.$eval('[data-sitekey]', el => el.getAttribute('data-sitekey')),
        url: page.url(),
      });
      if (!solved) {
        await browser.close();
        return { status: 'banned', payload: { reason: 'captcha_solve_failed' } } as ScrapeResult;
      }
    }

    // 2. Scroll humain progressif (pas instantané)
    const businesses = [];
    let lastCount = 0, stableIterations = 0;
    while (stableIterations < 3) {
      const cards = await page.$$('[role="feed"] > div > div[jsaction]');
      if (cards.length === lastCount) { stableIterations++; }
      else { stableIterations = 0; lastCount = cards.length; }

      // scroll progressif via cursor (5-15 px/frame, vitesse variable)
      await cursor.scroll({ x: 0, y: 300 + Math.random() * 400, durationMs: 800 + Math.random() * 1200 });
      await page.waitForTimeout(1500 + Math.random() * 2000); // pause humaine variable
    }

    for (const card of await page.$$('[role="feed"] > div > div[jsaction]')) {
      // mouseover via cursor humain avant lecture (mimick interaction)
      await cursor.moveTo(await card.boundingBox());
      businesses.push({
        name: await card.$eval('.fontHeadlineSmall', el => el.textContent),
        phone: await card.$eval('[aria-label^="Téléphone"]', el => el.textContent).catch(() => null),
        website: await card.$eval('a[aria-label^="Site Web"]', el => el.getAttribute('href')).catch(() => null),
        rating: await card.$eval('[role="img"][aria-label*="étoile"]', el => el.getAttribute('aria-label')).catch(() => null),
      });
    }
    await browser.close();
    return { status: 'ok', contactsFound: 0, contactsNew: 0, emailsFound: 0, emailsValidated: 0,
             costEurMicro: 0, durationMs: 0, payload: { businesses } };
  }
};
```

---

## SOURCE 7 — Pages Jaunes

- **Objectif :** backup Google Maps (téléphone + adresse + site web).
- **Méthode :** Playwright stealth.
- **URL pattern :** `https://www.pagesjaunes.fr/recherche/{ville}/{activite}?page={n}`
- **Pagination :** SANS LIMITE, jusqu'à bouton "suivant" absent.
- **Proxies :** datacenter Webshare suffit (anti-bot léger).
- **Anti-bot :** Akamai léger + reCAPTCHA conditionnel. Stealth + cookies persistants.

---

## SOURCE 8 — SITES WEB (source principale emails + équipe)

- **Objectif :** **EMAILS** (nominatifs + génériques classifiés exhaustivement) + équipe + comptes sociaux + mots-clés stratégiques + pattern email entreprise.
- **Méthode :** Playwright (pages JS-rendered) + cheerio (HTML statique pour speed).
- **URL d'entrée :** `companies.website` — **DOIT être validée par `UrlSsrfGuard` avant tout fetch** (cf §SSRF ci-dessous).
- **Profondeur de crawl :** 2-3 niveaux sur le domaine principal.

### 🛡️ SSRF Protection (audit P0 #3 — OWASP A10)

> **Risque :** `companies.website` peut être saisi manuellement par un admin (ou injecté via une source scraping compromise). Sans validation, un attaquant peut faire scraper `http://10.20.0.30:5432` (Postgres interne), `http://localhost:9090/metrics` (Prometheus), `http://169.254.169.254/` (metadata cloud), etc.

**Validation côté API Laravel (avant écriture DB) :**

```php
namespace App\Modules\Security;

final class UrlSsrfGuard
{
    private const BLOCKED_HOSTS = ['localhost', '127.0.0.1', '0.0.0.0', '::1'];
    private const BLOCKED_TLDS = ['local', 'internal', 'lan', 'corp'];

    /**
     * @throws SsrfBlockedException
     */
    public function validate(string $url): string
    {
        // 1. Scheme : https only (http accepté pour TPE rares mais loggé)
        $parsed = parse_url($url);
        if (!$parsed || !in_array($parsed['scheme'] ?? '', ['https', 'http'])) {
            throw new SsrfBlockedException('Only https/http schemes allowed');
        }
        // 2. Host blocklist
        $host = strtolower($parsed['host'] ?? '');
        if (in_array($host, self::BLOCKED_HOSTS)) {
            throw new SsrfBlockedException('Blocked host');
        }
        // 3. TLD blocklist
        $tld = pathinfo($host, PATHINFO_EXTENSION);
        if (in_array($tld, self::BLOCKED_TLDS)) {
            throw new SsrfBlockedException('Blocked TLD');
        }
        // 4. DNS résolution : refuser IPs privées RFC1918 + loopback + link-local + cloud metadata
        $ips = gethostbynamel($host) ?: [];
        foreach ($ips as $ip) {
            if ($this->isPrivateIp($ip)) {
                throw new SsrfBlockedException("Host resolves to private IP {$ip}");
            }
        }
        // 5. Port restriction (80/443 only, refuse 5432/6379/22 etc.)
        $port = $parsed['port'] ?? null;
        if ($port !== null && !in_array($port, [80, 443])) {
            throw new SsrfBlockedException("Port {$port} not allowed");
        }
        return $url;
    }

    private function isPrivateIp(string $ip): bool
    {
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
```

**Application :**
- **API Laravel** : Form Request `UpdateCompanyRequest` valide `website` via `UrlSsrfGuard` → 422 si bloqué (cf fichier 14 §4)
- **Workers Node** : double check côté worker avant `chromium.goto(url)` via lib `ssrf-req-filter` ou ré-appel `GET /api/internal/url-ssrf-validate` (defense in depth)
- **Test fuzzing** dans CI : payloads `http://10.0.0.1`, `http://169.254.169.254`, `http://localhost:5432` → tous DOIVENT être rejetés
- **Pages prioritaires :** `/contact`, `/equipe`, `/team`, `/about`, `/a-propos`, `/mentions-legales`, `/qui-sommes-nous`, `/who-we-are`, `/leadership`, `/management`, `/direction`, `/staff`, `/our-team`, `/notre-equipe`, `/founders`, `/people`, `/cgu`, `/legal`.
- **Pagination :** N/A (ce n'est pas un annuaire).
- **Proxies :** datacenter Webshare.

### Extraction emails EXHAUSTIVE

Pour chaque page, extraire **TOUS** les emails trouvés via :
- Regex RFC 5322 simplifiée : `[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}`
- Détection emails obfusqués : `[at]`, `(at)`, `&#64;`, `&#x40;`, JavaScript-rendered (mailto:), CSS rendering reverse, image-based (skip)
- Pour chaque email trouvé, **classifier automatiquement** :
  - `nominative` : matche `^[a-z]+\.[a-z]+@`, `^[a-z]\.[a-z]+@`, `^[a-z]+[a-z]@` ou contient prénom+nom détectables
  - `role_based` : matche `^(rh|hr|dsi|cto|daf|cfo|presse|press|commercial|sales|partenariats|recrutement|recruit|ventes|marketing|comm|press|legal|finance)@`
  - `generic` : matche `^(info|contact|hello|hi|bonjour|accueil|salut|admin|noreply|email)@` (sauf no_reply)
  - `no_reply` : matche `^(no.?reply|donotreply|noreply|do.not.reply|notification)@` → **systématiquement exclu** (`is_excluded = true`)
- Stocker en `company_emails` avec `email_type`, `is_validated = FALSE` (validation en source 6 via cascade SMTP), `source_url` = URL exacte où trouvé.

### Extraction équipe

- Parse les pages `/equipe` `/about` `/leadership` pour extraire les blocs personnes (nom + titre + photo + linkedin URL si présent).
- Use case LLM `extract_team_from_page` (Claude Haiku 4.5) sur le HTML rendered pour structurer en JSON.
- Output : `contacts[]` avec `position_title`, `is_executive = true` si C-level détecté.

### Détection comptes sociaux

- Regex sur attributs `href` : `linkedin.com/company/`, `twitter.com/`, `x.com/`, `youtube.com/(@|channel/|c/)`, `tiktok.com/@`, `instagram.com/`, `facebook.com/`, `github.com/`.
- Stocker dans `company_social_handles`.

### Mots-clés stratégiques

- Scanner tout le texte rendered (≥ 5000 chars typiquement) contre la table `strategic_keywords`.
- Compter occurrences par keyword.
- Stocker dans `company_strategic_keywords`.

### Détection pattern email entreprise

- Si ≥ 2 emails nominatifs trouvés sur le même domaine, déduire le pattern via use case LLM `detect_email_pattern` ou heuristique :
  - `john.doe@ + jane.smith@` → `{first}.{last}`
  - `jdoe@ + jsmith@` → `{f}{last}`
- Stocker dans `email_patterns(domain, pattern, confidence)`.

### TS Worker (extrait)

```ts
export const websiteCrawler: WorkerPlugin = {
  key: 'website',
  async execute(req) {
    const { website } = req.payload;
    const queue = [website];
    const visited = new Set<string>();
    const emails = new Set<string>();
    const team = [];
    while (queue.length > 0 && visited.size < 60) {
      const url = queue.shift()!;
      if (visited.has(url)) continue;
      visited.add(url);
      const html = await fetchWithProxy(url);
      const $ = cheerio.load(html);
      // Emails
      const text = $.root().text();
      const found = text.match(/[\w.+-]+@[\w-]+\.[\w.-]+/g) || [];
      for (const e of found) emails.add(e.toLowerCase());
      // Obfusqués
      const obf = text.match(/[\w.+-]+\s*\[at\]\s*[\w-]+\s*\[dot\]\s*[\w.-]+/gi) || [];
      for (const e of obf) emails.add(e.replace(/\[at\]/i, '@').replace(/\[dot\]/gi, '.').toLowerCase());
      // Liens internes prioritaires
      $('a[href]').each((_, a) => {
        const href = $(a).attr('href')!;
        if (PRIORITY_PATHS.some(p => href.includes(p))) {
          const abs = new URL(href, url).toString();
          if (abs.startsWith(website) && !visited.has(abs)) queue.unshift(abs);
        }
      });
    }
    return { status: 'ok', contactsFound: 0, contactsNew: 0,
             emailsFound: emails.size, emailsValidated: 0,
             costEurMicro: 0, durationMs: 0,
             payload: { emails: Array.from(emails), team } };
  }
};
```

### Mapping DB

- `company_emails` (1 ligne par email + classification + source_url)
- `contacts` (1 ligne par membre équipe + position détectée)
- `company_social_handles`
- `company_strategic_keywords`
- `email_patterns`

---

## SOURCE 9 — LinkedIn via PhantomBuster

- **Objectif :** dirigeants C-level NON LÉGAUX (DRH, DAF, DSI, Marketing, Commercial) — invisibles sur les sources légales.
- **Méthode :** PhantomBuster (SaaS tiers) avec 3 comptes Sales Navigator en rotation.
- **Coût :** ~370$/mois (PhantomBuster + 3× Sales Nav 75€/mois).
- **Auth :** API key PhantomBuster + session cookies Sales Nav chiffrés.
- **Rate limits :** 80 profils/jour/compte LinkedIn (rate limit imposé par LinkedIn).
- **Anti-pattern interdit :** scraping LinkedIn direct (ban immédiat).
- **Mapping DB :** `contacts` (avec `source = 'linkedin_pb'`, `linkedin_url`, `position_function`).
- **Rotation comptes :** géré par `linkedin_accounts` + état (`active`/`rate_limited`/`cooldown`/`suspicious`/`banned`).
- **Pseudo-code PHP :**

```php
$account = $this->linkedinRotator->pickActive($req->workspaceId);
$phantomId = $this->phantombuster->launch('linkedin-search-export', [
    'sessionCookie' => $account->phantombuster_session_id,
    'searches'      => [$searchUrl],
]);
// Long polling jusqu'à terminé
$result = $this->phantombuster->waitForResult($phantomId, timeout: 300);
foreach ($result['profiles'] as $p) {
    $this->contactRepo->upsert([
        'company_id'      => $companyId,
        'workspace_id'    => $req->workspaceId,
        'full_name'       => $p['fullName'],
        'position_title'  => $p['headline'],
        'position_function' => $this->detectFunction($p['headline']),  // LLM ou keywords
        'linkedin_url'    => $p['profileUrl'],
        'source'          => 'linkedin_pb',
    ]);
}
$this->linkedinRotator->incrementUsage($account->id);
```

---

## SOURCE 10 — France Travail API

- **Objectif :** **Signal d'achat** — détection de recrutements C-level (DSI, DAF, DRH, Marketing, Commercial).
- **Méthode :** API officielle REST.
- **URL base :** `https://api.francetravail.io/partenaire/offresdemploi/v2/`
- **Auth :** OAuth2 client credentials (gratuit, inscription pole-emploi.io).
- **Rate limits :** 4 req/sec.
- **Champs récupérés :** offres avec `entreprise.siret` matchant un SIREN en base + filtres titre poste contenant DSI/DAF/Directeur/Chief.
- **Mapping DB :** `company_business_signals` avec `signal_type = 'recrut_clevel'`, `signal_severity = 'high'`.

---

## SOURCE 11 — MESRI / ONISEP / data.gouv (Écoles + Universités)

- **Objectif :** écoles + universités + CFA + lycées + collèges.
- **Méthode :** **API + open data CSV** (pas de scraping nécessaire).
- **Sources :**
  - `https://data.enseignementsup-recherche.gouv.fr/api/explore/v2.1/catalog/datasets/fr-esr-principaux-etablissements-enseignement-superieur/records`
  - `https://www.onisep.fr/opendata` (CSV par académie)
- **Rate limits :** 100 req/min.
- **Mapping DB :** `schools`.
- **Pagination :** SANS LIMITE (loop sur `?limit=100&offset=N` jusqu'à `total_count`).

---

## SOURCE 12 — Crunchbase

- **Objectif :** levées de fonds Tech (signal d'achat majeur).
- **Statut :** ⚠️ **SOURCE SECONDAIRE** — la source PRIMAIRE pour les levées Tech FR est désormais le scraping news (frenchweb.fr + maddyness.com RSS — cf fichier 20 §5). Crunchbase est gardé en complément optionnel.
- **Méthode :** **Scraping prudent multi-couches** (Cloudflare Bot Management + PerimeterX + JS challenge sont combinés en 2026 → playwright-extra stealth seul NE SUFFIT PAS).
- **URL pattern :** `https://www.crunchbase.com/discover/funding_round/f.location_country_code=FR/f.announced_on=last_90_days`
- **Rate limits :** 1 req/min/IP, anti-bot agressif (Cloudflare BM + PerimeterX + JS challenge).
- **Proxies :** **résidentiel premium** Smartproxy/BrightData mobile (rotation agressive + session sticky 30 min).
- **Pagination :** SANS LIMITE (scroll infini + checkpoint).
- **Stratégie anti-bot Crunchbase :**
  1. TLS fingerprint bypass via `curl-impersonate-chrome` pour les requêtes API JSON
  2. Cookie jar persistant + session sticky proxy 30 min
  3. Playwright stealth + ghost-cursor + scroll progressive
  4. CapSolver pour Cloudflare Turnstile + reCAPTCHA + PerimeterX
  5. **Plan B chemin critique** : si scraping bloqué > 3 jours consécutifs → bascule auto sur **news scraping** (cf fichier 20) qui couvre 80 %+ des levées FR Tech > 500 k€
- **Plan C (escalation max)** : Crunchbase API Pro officielle ~50 $/mois (à activer SI les sources gratuites sont insuffisantes — décision Will après S13)
- **Mapping DB :** `company_business_signals` avec `signal_type = 'leve_fonds'`, montant, investisseurs.
- **Anti-pattern :** ne JAMAIS dépendre de Crunchbase comme source unique levées Tech. Le news scraping FR est désormais la SSOT levées de fonds.

---

## SOURCE 13 — api-adresse.data.gouv.fr (BAN — géocodage)

- **Objectif :** géocodage officiel (lat/lng) des adresses entreprises.
- **Méthode :** API officielle gratuite illimitée.
- **URL :** `https://api-adresse.data.gouv.fr/search/?q={adresse}&postcode={cp}&limit=1`
- **Auth :** aucune.
- **Rate limits :** 50 req/sec officiellement, mais soft-throttled si abus → auto-impose 20 req/sec.
- **Mapping DB :** UPDATE `companies.geom_point` = ST_SetSRID(ST_MakePoint(lng, lat), 4326).
- **Use case LLM `geocoding_disambiguation`** : si l'API retourne plusieurs candidats avec scores proches, LLM tranche en se basant sur le contexte (région, NAF, etc.).

---

## SOURCE 14 — Enrichissement social light

- **Objectif :** **identification** des handles X/YouTube/TikTok/Instagram/Facebook (pas de scraping de contenus — juste handle + URL + followers count).
- **Méthode :** Playwright stealth sur pages publiques.
- **Rate limits :** 1 req/profil/30 jours TTL.
- **Mapping DB :** `company_social_handles` (UPSERT).
- **Anti-pattern interdit :** scraping de contenus, DMs, followers list. Uniquement IDENTIFICATION.

---

## Tableau récapitulatif

| # | Source | Type | Coût | TTL | Proxy | Playwright | Rate |
|---|---|---|---|---|---|---|---|
| 1 | INSEE Sirene | API | 0€ | 30j | ❌ | ❌ | 30/min |
| 2 | annuaire-entreprises | API+HTML | 0€ | 365j | ❌ | ❌ | 60/min |
| 3 | Infogreffe | Scraping | 0€ | 365j | ✅ datacenter | ✅ | 30/min |
| 4 | Societe.com | Scraping | 0€ | 365j | ✅ résidentiel | ✅ | 20/min |
| 5 | BODACC | API | 0€ | 7j | ❌ | ❌ | 100/min |
| 6 | Google Maps | Scraping | proxies | 90j | ✅ résidentiel | ✅ | 1-3/min |
| 7 | Pages Jaunes | Scraping | proxies | 90j | ✅ datacenter | ✅ | 5/min |
| 8 | Sites web | Crawl | proxies | 30j | ✅ datacenter | ⚠️ mixte | 30/min/domaine |
| 9 | LinkedIn via PB | API tierce | 370€/mois | 60j | ❌ | ❌ | 80/jour/compte |
| 10 | France Travail | API | 0€ | 7j | ❌ | ❌ | 4/sec |
| 11 | MESRI/ONISEP | API+CSV | 0€ | 365j | ❌ | ❌ | 100/min |
| 12 | Crunchbase | Scraping | proxies | 7j | ✅ résidentiel | ✅ | 1/min |
| 13 | BAN | API | 0€ | ∞ | ❌ | ❌ | 20/sec |
| 14 | Social light | Scraping | proxies | 30j | ✅ datacenter | ✅ | 10/min |

---

## Gestion globale des erreurs et retries

Toutes les implémentations partagent le même handler d'erreur :

```php
final class ScraperRetryHandler
{
    public function shouldRetry(ScrapeResult $r, int $attempts): bool
    {
        if ($attempts >= 5) return false;
        return in_array($r->status, ['rate_limited', 'error']);
    }

    public function backoffSeconds(int $attempts): int
    {
        return min(2 ** $attempts + random_int(0, 30), 600);  // max 10 min
    }
}
```

Status `banned` → cooldown 24h sur la zone + alerte Telegram. Status `dead_letter` → push vers queue `dead-letter` pour inspection manuelle dans la console.

---

## Prochaine étape

→ Lire `06_email_finder_validation.md` pour le détail Email Finder + validation SMTP cascade.
