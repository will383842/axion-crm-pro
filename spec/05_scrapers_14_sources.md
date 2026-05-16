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
- **Méthode :** scraping Playwright stealth.
- **URL base :** `https://www.societe.com/societe/`
- **Rate limits :** 20 req/min (anti-bot agressif → backoff).
- **Proxies :** **résidentiel obligatoire** (datacenter banni). IPRoyal ou Smartproxy.
- **Anti-bot :** Cloudflare + DataDome → stealth + résidentiel + simulation human-like (mouse moves, scrolls).
- **Mapping DB :** `contacts` (avec `source = 'societe'`).

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
- **Méthode :** **Playwright stealth** (pas d'API officielle accessible sans clé payante).
- **URL pattern :** `https://www.google.com/maps/search/{query}/@{lat},{lng},14z`
- **Rate limits :** 1-3 req/min/IP (très strict). **Proxies résidentiels obligatoires.**
- **Proxies :** Smartproxy résidentiel premium (rotation aggressive).
- **Pagination :** SANS LIMITE. On scroll jusqu'à "Aucun résultat supplémentaire" (Google retourne ~120 résultats max/recherche). **Checkpoint** : on stocke `last_page_scraped` + dernière coordonnée vue.
- **Anti-bot :** captcha reCAPTCHA → si détecté, dead_letter le run, cooldown la zone 24h.
- **TS Worker :**

```ts
export const gmapsScraper: WorkerPlugin = {
  key: 'gmaps',
  async execute(req) {
    const { searchQuery, city } = req.payload;
    const proxy = await getProxy({ residential: true });
    const browser = await chromium.launch({ proxy });
    const ctx = await browser.newContext({ userAgent: await getUA(), locale: 'fr-FR' });
    const page = await ctx.newPage();
    await page.goto(`https://www.google.com/maps/search/${encodeURIComponent(searchQuery)}+${encodeURIComponent(city)}`);
    if (await page.$('#captcha-form, [src*="recaptcha"]')) {
      await browser.close();
      return { status: 'banned', payload: { reason: 'captcha' } } as ScrapeResult;
    }
    const businesses = [];
    let lastCount = 0;
    while (true) {
      const cards = await page.$$('[role="feed"] > div > div[jsaction]');
      if (cards.length === lastCount) break; // fin pagination réelle
      lastCount = cards.length;
      await cards[cards.length - 1].scrollIntoViewIfNeeded();
      await page.waitForTimeout(2000 + Math.random() * 1500);
    }
    for (const card of await page.$$('[role="feed"] > div > div[jsaction]')) {
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
- **URL d'entrée :** `companies.website`.
- **Profondeur de crawl :** 2-3 niveaux sur le domaine principal.
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
- **Méthode :** **Scraping prudent Playwright stealth** (pas d'API gratuite Crunchbase pour FR).
- **URL pattern :** `https://www.crunchbase.com/discover/funding_round/f.location_country_code=FR/f.announced_on=last_90_days`
- **Rate limits :** 1 req/min/IP, anti-bot agressif (Cloudflare Bot Management).
- **Proxies :** **résidentiel premium** Smartproxy/BrightData.
- **Pagination :** SANS LIMITE (scroll infini + checkpoint).
- **Anti-pattern :** Crunchbase a déjà banni des scrapers — accepter run quotidien limité (~50 levées/jour).
- **Mapping DB :** `company_business_signals` avec `signal_type = 'leve_fonds'`, montant, investisseurs.

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
