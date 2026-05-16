# 05 — Scrapers (14 sources)

> **Scope :** Spec exhaustive des 14 sources de données, ZÉRO abonnement payant.
> **Plugin interface :** chaque source implémente `ScraperPlugin` (cf. fin du fichier) pour pouvoir ajouter une 15ᵉ source sans toucher au core.
> **Sortie standard :** chaque scrape produit un `ScraperResult` typé + INSERT/UPDATE dans les tables `companies`, `contacts`, `company_*`, `scraper_runs`, `email_verifications`, etc.

---

## Interface `ScraperPlugin` (commune)

### Côté Laravel (orchestrateur)

```php
// app/Contracts/ScraperPlugin.php
namespace App\Contracts;

use Spatie\LaravelData\Data;
use App\Data\ScraperContextData;
use App\Data\ScraperResultData;

interface ScraperPlugin
{
    public function slug(): string;                          // 'insee'|'google_maps'|...
    public function label(): string;
    public function requiresProxy(): bool;
    public function requiresPlaywright(): bool;
    public function defaultTtlDays(): int;
    public function defaultRateLimit(): array;               // ['per_minute' => 60, 'per_hour' => 1000]

    public function canHandle(ScraperContextData $ctx): bool;
    public function dispatch(ScraperContextData $ctx): string;  // dispatch job, return run_id
}
```

### Côté Node workers (TypeScript)

```typescript
// workers/src/scrapers/types.ts
import { z } from 'zod'

export const ScraperContextSchema = z.object({
  workspaceId: z.string().uuid(),
  source: z.string(),
  targetType: z.enum(['company','zone','contact','url']),
  targetRef: z.string(),
  runId: z.string(),
  proxy: z.object({ id: z.string(), url: z.string() }).nullable(),
  userAgent: z.string(),
  settings: z.record(z.unknown()),
})

export type ScraperContext = z.infer<typeof ScraperContextSchema>

export type ScraperResult = {
  status: 'ok' | 'failed' | 'skipped'
  data: Record<string, unknown>
  metrics: {
    durationMs: number
    contactsFound: number
    emailsFound: number
    tokensConsumed?: number
    bytesIn: number
    bytesOut: number
  }
  error?: { code: string; message: string; stack?: string }
}

export interface ScraperPlugin {
  slug: string
  scrape(ctx: ScraperContext): Promise<ScraperResult>
}
```

---

## §1 — Source 1 : INSEE Sirene API

### Identité

- **Source officielle** : `https://api.insee.fr/entreprises/sirene/V3/`
- **Auth** : OAuth2 client credentials (token JWT, refresh manuel)
- **Quota** : 30 req/min anonymous → 500 req/min authentifié → 17 000 req/jour avec contrat
- **Coût** : 0 € (gratuit)
- **Type** : API REST JSON
- **Format réponse** : JSON structuré

### Objectif

Identification primaire des entreprises françaises. Filtrage par NAF, département, tranche effectif. Source de SIREN + raison sociale + adresse siège + NAF + effectif tranche.

### Champs récupérés (mapping → `companies`)

| Champ INSEE | Champ DB |
|-------------|----------|
| `siren` | `siren` |
| `siretSiege` | `siret_siege` |
| `denominationUniteLegale` ou `nomUniteLegale + prenomUsuelUniteLegale` | `legal_name` |
| `categorieJuridiqueUniteLegale` | `legal_form_code` |
| `activitePrincipaleUniteLegale` | `naf_subclass_code` |
| `dateCreationUniteLegale` | `creation_date` |
| `trancheEffectifsUniteLegale` | `effectif_range_code` |
| `etatAdministratifUniteLegale` | (filtre : 'A' = actif) |
| `adresseEtablissement.*` | `company_addresses` |

### Endpoints utilisés

```
GET /entreprises/sirene/V3/siret?q={query}&nombre=1000&debut=0&date={date}
   query examples:
   - "trancheEffectifsUniteLegale:(03 OR 11 OR 12) AND codeCommuneEtablissement:75056"
   - "activitePrincipaleUniteLegale:6201Z AND categorieEntreprise:PME"
   - "siretSiege:14000005700083"

GET /entreprises/sirene/V3/siren/{siren}
GET /entreprises/sirene/V3/siret/{siret}
```

### Pseudo-code (PHP Laravel)

```php
// app/Services/Scrapers/InseeSirenScraper.php
namespace App\Services\Scrapers;

use Illuminate\Http\Client\Factory as HttpFactory;
use App\Data\InseeQueryData;

class InseeSirenScraper implements ScraperPlugin
{
    public function __construct(
        private HttpFactory $http,
        private InseeTokenManager $tokenMgr,
    ) {}

    public function slug(): string { return 'insee'; }
    public function requiresProxy(): bool { return false; }
    public function defaultTtlDays(): int { return 180; }

    public function searchByZoneAndSize(InseeQueryData $q): array
    {
        $token = $this->tokenMgr->getValid();
        $page = 0;
        $allResults = [];
        do {
            $resp = $this->http
                ->withToken($token)
                ->retry(3, 1000)
                ->timeout(30)
                ->get('https://api.insee.fr/entreprises/sirene/V3/siret', [
                    'q'      => $this->buildQuery($q),
                    'nombre' => 1000,
                    'debut'  => $page * 1000,
                ]);

            if ($resp->status() === 429) {
                sleep(2);
                continue;
            }
            if ($resp->failed()) {
                throw new InseeScrapingException($resp->status(), $resp->body());
            }

            $data  = $resp->json();
            $items = $data['etablissements'] ?? [];
            $total = $data['header']['total'] ?? 0;
            foreach ($items as $item) {
                $allResults[] = $this->mapToCompany($item);
            }
            $page++;
            if ($page * 1000 >= $total) break;
        } while (true);

        return $allResults;
    }

    private function buildQuery(InseeQueryData $q): string
    {
        $parts = [];
        if ($q->naf)        $parts[] = "activitePrincipaleUniteLegale:{$q->naf}";
        if ($q->department) $parts[] = "codeDepartementEtablissement:{$q->department}";
        if ($q->effectif)   $parts[] = "trancheEffectifsUniteLegale:({$q->effectif})";
        $parts[] = "etatAdministratifUniteLegale:A";        // actif uniquement
        return implode(' AND ', $parts);
    }
}
```

### Gestion erreurs

- **401** → Refresh token + retry une fois
- **429** → Backoff exponentiel 2 → 4 → 8 sec (max 3 retries)
- **500/502/503/504** → Retry 3x avec 5s sleep
- **Réponse vide** → `status: 'ok'`, `contactsFound: 0` (zone non couverte)

### Anti-doublon

Vérification systématique avant INSERT :
```php
$existing = Company::query()
    ->where('workspace_id', $workspaceId)
    ->where('siren', $siren)
    ->first();
if ($existing) {
    return $existing->update($newData); // soft merge
}
```

---

## §2 — Source 2 : annuaire-entreprises.data.gouv.fr (remplace Pappers)

### Identité

- **Source** : `https://annuaire-entreprises.data.gouv.fr/`
- **API JSON** : `https://recherche-entreprises.api.gouv.fr/search?q={...}` (gratuit, 7 req/s)
- **Type** : API REST JSON (recommandé) + scraping HTML (fallback)
- **Coût** : 0 €
- **Quota** : 7 req/s, 1000 req/min recommandé

### Objectif

Dirigeants légaux + bilans + CA + bénéficiaires effectifs. Remplace Pappers (99 € HT/mois).

### Endpoint principal

```
GET https://recherche-entreprises.api.gouv.fr/search
    ?q={raison sociale ou SIREN}
    &page=1
    &per_page=10
    &include=dirigeants,beneficiaires_effectifs,etablissements_complementaires
```

### Champs récupérés

| Champ API | Mapping |
|-----------|---------|
| `dirigeants[]` | `contacts` avec `is_legal_director = true`, `seniority_level = 'c_level'` |
| `dirigeants[].nom + prenoms` | `contacts.first_name + last_name` |
| `dirigeants[].qualite` | `contacts.position_label` (ex: 'PRESIDENT', 'GERANT') |
| `finances[].ca` (millésime le plus récent) | `companies.revenue_eur` + `revenue_year` |
| `finances[].resultat_net` | metadata JSONB |
| `effectifs[].annee + tranche` | `companies.effectif_range_code` |
| `beneficiaires_effectifs[]` | metadata JSONB (info RGPD sensible) |

### Pseudo-code (PHP)

```php
// app/Services/Scrapers/AnnuaireEntreprisesScraper.php
class AnnuaireEntreprisesScraper implements ScraperPlugin
{
    public function slug(): string { return 'annuaire_entreprises'; }
    public function requiresProxy(): bool { return false; }    // API officielle
    public function defaultTtlDays(): int { return 365; }

    public function fetchBySiren(string $siren): array
    {
        $resp = Http::baseUrl('https://recherche-entreprises.api.gouv.fr')
            ->retry(3, 500)
            ->timeout(20)
            ->get('/search', [
                'q'        => $siren,
                'per_page' => 1,
                'include'  => 'dirigeants,beneficiaires_effectifs,etablissements_complementaires,finances',
            ]);

        if ($resp->failed()) throw new ScrapingException("annuaire-entreprises {$resp->status()}");

        $data = $resp->json('results.0');
        if (!$data) return ['found' => false];

        return [
            'found'       => true,
            'dirigeants'  => $this->mapDirigeants($data['dirigeants'] ?? []),
            'revenue'     => $this->extractLatestRevenue($data['finances'] ?? []),
            'effectif'    => $data['tranche_effectif_salarie'] ?? null,
            'beneficiaires' => $this->mapBeneficiaires($data['beneficiaires_effectifs'] ?? []),
        ];
    }

    private function mapDirigeants(array $dirigeants): array
    {
        return collect($dirigeants)->map(fn($d) => [
            'first_name'       => $d['prenoms'] ?? null,
            'last_name'        => $d['nom'] ?? '',
            'position_label'   => $d['qualite'] ?? 'Dirigeant',
            'position_normalized' => $this->normalizePosition($d['qualite']),
            'is_legal_director'=> true,
            'seniority_level'  => 'c_level',
            'discovery_source' => 'legal_director',
            'discovery_url'    => 'https://annuaire-entreprises.data.gouv.fr/entreprise/' . ($d['siren'] ?? ''),
            'discovery_confidence' => 95,
        ])->toArray();
    }
}
```

### Fallback HTML (si API change format)

Scraping de `https://annuaire-entreprises.data.gouv.fr/entreprise/{siren}` avec Playwright. Sélecteurs CSS dans `config/scrapers/annuaire_entreprises.php` (RUNTIME-CONFIG, modifiable depuis admin si HTML change).

### Anti-bot

API officielle — aucune protection bot. Pas de proxy nécessaire.

### Mapping tables DB

- `companies` (UPDATE : revenue, effectif, brand_name si dispo)
- `contacts` (UPSERT dirigeants)
- `scraper_runs` (log)

---

## §3 — Source 3 : infogreffe.fr (fallback bilans)

### Identité

- **Source** : `https://www.infogreffe.fr/`
- **Type** : Scraping HTML (Playwright)
- **Coût** : 0 € pour lecture publique
- **Quota** : Pas de quota officiel, ban IP si trop agressif (estimé > 30 req/min)

### Objectif

Fallback bilans détaillés (compte de résultat, bilan financier complet) quand annuaire-entreprises n'a pas la donnée millésime récente.

### Pages cibles

```
https://www.infogreffe.fr/entreprise/{siren}/
https://www.infogreffe.fr/entreprise/{siren}/comptes-annuels
```

### Pseudo-code (TypeScript Playwright)

```typescript
// workers/src/scrapers/infogreffe.ts
import { chromium } from 'playwright-extra'
import stealth from 'puppeteer-extra-plugin-stealth'
import type { ScraperPlugin, ScraperContext, ScraperResult } from './types'

chromium.use(stealth())

export const InfogreffePlugin: ScraperPlugin = {
  slug: 'infogreffe',
  async scrape(ctx: ScraperContext): Promise<ScraperResult> {
    const start = Date.now()
    const browser = await chromium.launch({
      proxy: ctx.proxy ? { server: ctx.proxy.url } : undefined,
    })
    try {
      const ctxPw = await browser.newContext({ userAgent: ctx.userAgent })
      const page = await ctxPw.newPage()
      const siren = ctx.targetRef
      await page.goto(`https://www.infogreffe.fr/entreprise/${siren}/`, {
        waitUntil: 'domcontentloaded',
        timeout: 25_000,
      })
      // Cookie banner
      await page.locator('[data-testid="accept-cookies"]').click({ timeout: 3000 }).catch(() => {})

      const financials = await page.evaluate(() => {
        const rows = document.querySelectorAll('table.financials tr')
        return Array.from(rows).map(r => Array.from(r.querySelectorAll('td')).map(td => td.textContent?.trim() ?? ''))
      })

      return {
        status: 'ok',
        data: { financials_raw: financials },
        metrics: {
          durationMs: Date.now() - start,
          contactsFound: 0,
          emailsFound: 0,
          bytesIn: 0, bytesOut: 0,
        },
      }
    } catch (e: any) {
      return {
        status: 'failed',
        data: {},
        metrics: { durationMs: Date.now() - start, contactsFound: 0, emailsFound: 0, bytesIn: 0, bytesOut: 0 },
        error: { code: e.code ?? 'unknown', message: e.message, stack: e.stack },
      }
    } finally {
      await browser.close()
    }
  }
}
```

### Anti-bot

Cookies banner + nav humanizée (mouvements de souris). Si bloqué : rotation proxy + UA.

---

## §4 — Source 4 : societe.com (fallback dirigeants)

### Identité & objectif

- **Source** : `https://www.societe.com/`
- **Type** : Scraping HTML (Playwright)
- **Coût** : 0 €
- **Objectif** : Fallback dirigeants quand annuaire-entreprises est incomplet (ex: SIREN très vieux, holdings)

### URL pattern

```
https://www.societe.com/societe/{slug}-{siren}.html
```

Slug à construire : `Str::slug($legalName)`.

### Pseudo-code (TypeScript) — pattern similaire à Infogreffe, sélecteurs CSS adaptés. RUNTIME-CONFIG.

---

## §5 — Source 5 : BODACC API (signaux business)

### Identité

- **Source** : `https://bodacc-datafluide.echanges.dila.gouv.fr/api/explore/v2.1/catalog/datasets/annonces-commerciales/records`
- **Type** : API JSON (open data)
- **Coût** : 0 €
- **Quota** : Pas de limite officielle, ~10 req/s safe

### Objectif

Détecter signaux business : changements de dirigeants, créations, modifications de capital, redressements, levées, dissolutions.

### Endpoint exemple

```
GET /api/explore/v2.1/catalog/datasets/annonces-commerciales/records
    ?where=registre LIKE "%SIREN%"
    &order_by=dateparution DESC
    &limit=20
```

### Mapping → `company_business_signals`

```php
match($annonce['type_avis']) {
    'creation'      => signal_type: 'company_creation',
    'modification'  => signal_type: 'leadership_change',
    'redressement'  => signal_type: 'redressement',
    'liquidation'   => signal_type: 'liquidation',
    'depot_compte'  => signal_type: 'financial_filing',
    default         => signal_type: 'other',
}
```

### Pseudo-code (PHP)

```php
public function fetchSignalsForSiren(string $siren): Collection
{
    $resp = Http::get('https://bodacc-datafluide.echanges.dila.gouv.fr/api/explore/v2.1/catalog/datasets/annonces-commerciales/records', [
        'where'    => "registre like \"{$siren}\"",
        'order_by' => 'dateparution DESC',
        'limit'    => 50,
    ]);
    return collect($resp->json('results'))->map(fn($r) => CompanyBusinessSignal::firstOrCreate([
        'company_id'  => $companyId,
        'source'      => 'bodacc',
        'source_url'  => "https://www.bodacc.fr/annonce/detail/{$r['id']}",
        'detected_at' => $r['dateparution'],
    ], [
        'signal_type' => $this->mapSignalType($r['type_avis']),
        'signal_score'=> $this->scoreSignal($r),
        'metadata'    => $r,
    ]));
}
```

---

## §6 — Source 6 : Google Maps (téléphone, site, horaires)

### Identité

- **Source** : `https://www.google.com/maps/`
- **Type** : Scraping Playwright (NO API officielle Maps gratuite suffisante)
- **Proxies** : OBLIGATOIRES (résidentiels recommandés)
- **Quota** : Rotation IPs + cool-down 30s entre searches même IP

### Objectif

Téléphone + site web + horaires + avis + photos + adresse exacte par établissement.

### URL pattern

```
https://www.google.com/maps/search/{raison sociale}+{ville}
```

### Pseudo-code (TypeScript Playwright)

```typescript
// workers/src/scrapers/google-maps.ts
export const GoogleMapsPlugin: ScraperPlugin = {
  slug: 'google_maps',
  async scrape(ctx) {
    const start = Date.now()
    const browser = await chromium.launch({
      proxy: { server: ctx.proxy!.url },
      args: ['--lang=fr-FR'],
    })
    const ctxPw = await browser.newContext({
      userAgent: ctx.userAgent,
      locale: 'fr-FR',
      timezoneId: 'Europe/Paris',
    })
    const page = await ctxPw.newPage()
    try {
      const company = JSON.parse(ctx.targetRef)  // { legalName, city, siren }
      const q = encodeURIComponent(`${company.legalName} ${company.city}`)
      await page.goto(`https://www.google.com/maps/search/${q}`, { waitUntil: 'networkidle', timeout: 30_000 })

      // Click first result
      await page.locator('a.hfpxzc').first().click({ timeout: 10_000 }).catch(() => {})
      await page.waitForTimeout(2000)

      const phone   = await page.locator('button[data-item-id^="phone:"]').first().getAttribute('aria-label').catch(() => null)
      const website = await page.locator('a[data-item-id="authority"]').first().getAttribute('href').catch(() => null)
      const addr    = await page.locator('button[data-item-id="address"]').first().getAttribute('aria-label').catch(() => null)
      const rating  = await page.locator('div.F7nice span').first().textContent().catch(() => null)
      const reviewsCountText = await page.locator('div.F7nice span').nth(1).textContent().catch(() => null)
      const hours   = await page.locator('div[role="region"][aria-label*="horaires"] tr').allTextContents().catch(() => [])

      return {
        status: 'ok',
        data: { phone, website, addr, rating, reviewsCountText, hours },
        metrics: { durationMs: Date.now() - start, contactsFound: 0, emailsFound: 0, bytesIn: 0, bytesOut: 0 },
      }
    } finally { await browser.close() }
  }
}
```

### Anti-bot

- Proxies résidentiels rotatifs
- User-Agent pool 50+ (desktop + mobile)
- Mouvements souris simulés (~30s par recherche)
- Cool-down 30s entre searches même IP

### Pagination (cas où multiples établissements)

Scroll de la liste latérale jusqu'à fin des résultats. Pas de limite à 20.

---

## §7 — Source 7 : Pages Jaunes (backup Google Maps)

### Identité & objectif

- **Source** : `https://www.pagesjaunes.fr/`
- **Type** : Scraping Playwright + API JSON XHR interne
- **Proxies** : OBLIGATOIRES
- **Objectif** : Backup Google Maps. Données similaires (tél, site, horaires, avis).

### URL pattern

```
https://www.pagesjaunes.fr/recherche/{ville}/{type-activite}/{raison-sociale}
```

### Pseudo-code (TypeScript) — pattern similaire Google Maps, sélecteurs adaptés. RUNTIME-CONFIG dans `config/scrapers/pages_jaunes.json`.

### Pagination

Pages Jaunes paginé classique. Scrape jusqu'à fin. Checkpoint après chaque page dans `scraper_runs.metadata.last_page`.

---

## §8 — Source 8 : Sites web entreprises (SOURCE PRINCIPALE emails)

### Identité

- **Source** : Le site web de chaque entreprise (URL depuis `companies.website_url`)
- **Type** : Scraping Playwright + cheerio HTML statique
- **Proxies** : Recommandés (résidentiels)
- **Quota** : 5 req/s/domaine max (politesse)

### Objectif

**Source principale d'emails.** Extraction exhaustive emails + équipe + comptes sociaux + mots-clés stratégiques + pattern email.

### Pages cibles (crawl 2-3 niveaux)

```
/contact, /contacts, /contact-us, /nous-contacter
/equipe, /team, /our-team, /notre-equipe, /membres
/about, /a-propos, /qui-sommes-nous, /who-we-are
/mentions-legales, /legal, /mentions
/leadership, /management, /direction, /governance
/staff, /collaborateurs
/services, /produits, /solutions   (pour mots-clés strat)
```

### Extraction emails

#### Sources d'emails dans une page

1. **HTML brut** : regex `[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}`
2. **`href="mailto:..."` attribute**
3. **Obfusqués** : `[at]`, `(at)`, `&#64;`, `&commat;`, JavaScript-rendered (déobfuscation post-load)
4. **Email images** : OCR optionnel (Tesseract) si activé

#### Classification automatique

```typescript
function classifyEmail(email: string): 'nominative' | 'role_based' | 'generic' | 'no_reply' {
  const local = email.split('@')[0].toLowerCase()
  const NO_REPLY = ['no-reply','noreply','donotreply','do-not-reply','postmaster','mailer-daemon','bounce']
  const ROLE_BASED = ['contact','info','infos','hello','bonjour','sales','vente','rh','hr','support','admin','webmaster','direction','recrutement','careers','jobs','presse','press','marketing','commercial','dpo','legal','accounting','compta','help']
  if (NO_REPLY.some(n => local === n || local.startsWith(n + '.'))) return 'no_reply'
  if (ROLE_BASED.some(r => local === r || local.startsWith(r + '.') || local.startsWith(r + '-'))) return 'role_based'
  if (/[a-z]+[._-][a-z]+/.test(local)) return 'nominative'
  return 'generic'
}
```

#### Détection pattern email entreprise

À partir de tous les emails nominatifs trouvés + liste des employés détectés :

```typescript
function detectEmailPattern(emails: string[], teamMembers: Array<{first: string, last: string}>): EmailPattern | null {
  for (const email of emails.filter(e => classifyEmail(e) === 'nominative')) {
    const local = email.split('@')[0].toLowerCase()
    const domain = email.split('@')[1]
    for (const member of teamMembers) {
      const f = normalize(member.first)
      const l = normalize(member.last)
      const fi = f[0]
      // Test 15+ patterns
      const tests: Array<[string, string]> = [
        [`${f}.${l}`,      '{first}.{last}@{domain}'],
        [`${f}${l}`,       '{first}{last}@{domain}'],
        [`${f}-${l}`,      '{first}-{last}@{domain}'],
        [`${f}_${l}`,      '{first}_{last}@{domain}'],
        [`${fi}${l}`,      '{f}{last}@{domain}'],
        [`${fi}.${l}`,     '{f}.{last}@{domain}'],
        [`${fi}-${l}`,     '{f}-{last}@{domain}'],
        [`${l}.${f}`,      '{last}.{first}@{domain}'],
        [`${l}${f}`,       '{last}{first}@{domain}'],
        [`${l}${fi}`,      '{last}{f}@{domain}'],
        [`${l}.${fi}`,     '{last}.{f}@{domain}'],
        [`${f}`,           '{first}@{domain}'],
        [`${l}`,           '{last}@{domain}'],
        [`${fi}${l[0]}`,   '{f}{l}@{domain}'],
      ]
      for (const [candidate, pattern] of tests) {
        if (local === candidate) {
          return { pattern, domain, confidence: 90, evidence: [email] }
        }
      }
    }
  }
  return null
}
```

### Extraction équipe (use case LLM `extract_team_from_page`)

Page HTML est plutôt structurée (cards photo + nom + titre) OU non structurée (paragraphe).

```typescript
async function extractTeam(html: string, url: string): Promise<TeamMember[]> {
  // 1. Structured first (cheerio CSS selectors)
  const $ = cheerio.load(html)
  const structured = $('.team-member, .member-card, [class*="team"] [class*="member"]').map((i, el) => {
    const name = $(el).find('h2, h3, .name, .member-name').first().text().trim()
    const title = $(el).find('.title, .position, .role, .member-position').first().text().trim()
    return { name, title }
  }).get().filter(m => m.name)
  if (structured.length > 0) return structured.map(parseNameTitle)

  // 2. LLM fallback for unstructured pages
  const llmResult = await llmClient.complete({
    useCase: 'extract_team_from_page',
    variables: { html_excerpt: html.slice(0, 12000), url },
  })
  return JSON.parse(llmResult.text)
}
```

### Crawl 2-3 niveaux profondeur

```typescript
async function crawlSite(rootUrl: string, depth: number = 2): Promise<CrawlResult> {
  const visited = new Set<string>()
  const queue: Array<{url: string, level: number}> = [{ url: rootUrl, level: 0 }]
  const results: CrawlResult = { emails: [], team: [], socials: [], pagesVisited: 0 }

  const TARGET_PATHS = ['/contact','/equipe','/team','/about','/mentions','/leadership','/management','/direction']

  while (queue.length > 0 && results.pagesVisited < 30) {
    const { url, level } = queue.shift()!
    if (visited.has(url)) continue
    visited.add(url)

    const page = await fetchAndParse(url)
    results.pagesVisited++

    results.emails.push(...extractEmails(page.html))
    results.socials.push(...extractSocialHandles(page.html))

    if (TARGET_PATHS.some(p => url.includes(p))) {
      results.team.push(...await extractTeam(page.html, url))
    }

    if (level < depth) {
      for (const link of extractInternalLinks(page.html, rootUrl)) {
        if (!visited.has(link) && TARGET_PATHS.some(p => link.includes(p))) {
          queue.push({ url: link, level: level + 1 })
        }
      }
    }
  }
  return results
}
```

### Mapping DB

- `companies.website_url` (set si trouvé)
- `company_emails` (UPSERT par email, classifié)
- `email_patterns` (UPSERT pattern détecté)
- `contacts` (UPSERT employés trouvés avec `discovery_source = 'website_team_page'`)
- `company_social_handles` (UPSERT)
- `company_strategic_keywords` (UPSERT mots-clés détectés)

---

## §9 — Source 9 : Google Search Wrapper (URLs LinkedIn) [CRITIQUE]

### Identité

- **Sources** : `google.com`, `bing.com`, `duckduckgo.com` (rotation)
- **Type** : Scraping Playwright des SERPs
- **Proxies** : OBLIGATOIRES (résidentiels fortement recommandés)
- **Coût** : Gratuit (uniquement bandwidth proxies)

### Objectif

**REMPLACE PHANTOMBUSTER.** Récupère les URLs LinkedIn publiques retournées par les moteurs de recherche. Aucun scraping du contenu LinkedIn lui-même.

### Stratégie 3 moteurs avec rotation

```typescript
class GoogleSearchWrapper {
  private engines: SearchEngine[] = []
  private currentIdx = 0

  constructor(engines: SearchEngine[]) {
    this.engines = engines.filter(e => e.state === 'active').sort((a,b) => b.priority - a.priority)
  }

  async searchLinkedIn(target: 'company' | 'person' | 'c_level_drh' | 'c_level_daf' | 'c_level_dsi' | 'c_level_cmo' | 'c_level_cco', query: SearchQuery): Promise<SearchResult> {
    for (let attempt = 0; attempt < 3; attempt++) {
      const engine = this.engines[this.currentIdx]
      if (engine.state !== 'active') {
        this.rotateToNext()
        continue
      }
      try {
        const result = await this.executeOnEngine(engine, query, target)
        if (result.captchaDetected) {
          await this.markEngineCaptcha(engine)
          this.rotateToNext()
          continue
        }
        return result
      } catch (e) {
        if (e.code === 'rate_limit') {
          await this.markEngineRateLimit(engine)
          this.rotateToNext()
          continue
        }
        throw e
      }
    }
    throw new Error('all_engines_failed')
  }

  private async executeOnEngine(engine: SearchEngine, query: SearchQuery, target: string): Promise<SearchResult> {
    const browser = await chromium.launch({ proxy: { server: query.proxy.url } })
    try {
      const ctx = await browser.newContext({ userAgent: query.userAgent, locale: 'fr-FR' })
      const page = await ctx.newPage()

      const searchQ = this.buildQuery(target, query)
      const url = engine.slug === 'google'
        ? `https://www.google.com/search?q=${encodeURIComponent(searchQ)}&hl=fr&num=10`
        : engine.slug === 'bing'
        ? `https://www.bing.com/search?q=${encodeURIComponent(searchQ)}&setlang=fr`
        : `https://duckduckgo.com/?q=${encodeURIComponent(searchQ)}&kl=fr-fr`

      await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 25_000 })

      // Captcha detection
      const isCaptcha = await page.locator('form[action*="captcha"], #captcha, .g-recaptcha').isVisible({ timeout: 1000 }).catch(() => false)
      if (isCaptcha) {
        return { captchaDetected: true, results: [], engine: engine.slug }
      }

      const results = await this.parseSerps(page, engine.slug)
      return { captchaDetected: false, results, engine: engine.slug }
    } finally { await browser.close() }
  }

  private buildQuery(target: string, q: SearchQuery): string {
    switch (target) {
      case 'company':
        return `"${q.companyName}" site:linkedin.com/company/`
      case 'person':
        return `"${q.firstName} ${q.lastName}" "${q.companyName}" site:linkedin.com/in/`
      case 'c_level_drh':
        return `site:linkedin.com/in/ "DRH" OR "Directrice Ressources Humaines" OR "CHRO" "${q.companyName}"`
      case 'c_level_daf':
        return `site:linkedin.com/in/ "DAF" OR "Directeur Administratif Financier" OR "CFO" "${q.companyName}"`
      case 'c_level_dsi':
        return `site:linkedin.com/in/ "DSI" OR "Directeur Systèmes Information" OR "CIO" "${q.companyName}"`
      case 'c_level_cmo':
        return `site:linkedin.com/in/ "Directeur Marketing" OR "CMO" "${q.companyName}"`
      case 'c_level_cco':
        return `site:linkedin.com/in/ "Directeur Commercial" OR "CCO" "${q.companyName}"`
      default: throw new Error('unknown target')
    }
  }

  private async parseSerps(page: Page, engineSlug: string): Promise<SerpResult[]> {
    const selector = engineSlug === 'google' ? 'div.g' : engineSlug === 'bing' ? 'li.b_algo' : 'article[data-testid="result"]'
    return await page.locator(selector).evaluateAll((els, eng) => {
      return els.map(el => {
        const titleEl = el.querySelector(eng === 'google' ? 'h3' : eng === 'bing' ? 'h2' : 'h2 a')
        const linkEl  = el.querySelector('a')
        const snipEl  = el.querySelector(eng === 'google' ? '.VwiC3b' : eng === 'bing' ? '.b_caption p' : 'div[data-result="snippet"]')
        return {
          title: titleEl?.textContent?.trim() ?? '',
          url: linkEl?.getAttribute('href') ?? '',
          snippet: snipEl?.textContent?.trim() ?? '',
        }
      }).filter(r => r.url.includes('linkedin.com'))
    }, engineSlug)
  }
}
```

### Scoring de matching — règles déterministes puis LLM fallback (P0 audit v1.1)

> **Problème v1.0** : 600 k appels LLM/mois pour scorer 3 résultats × 200 k entreprises = ~480 €/mois (dépasse cap LLM workspace).
> **Correction v1.1** : règles déterministes d'abord (50-70 % des cas résolus), LLM uniquement pour les cas ambigus (estimé 200 k appels/mois, ~160 €/mois économisés).

```typescript
async function scoreLinkedinMatch(target: SearchQuery, result: SerpResult): Promise<number> {
  // === Phase 1 : règles déterministes (zéro coût LLM) ===
  let score = 0
  const urlSegment = result.url.toLowerCase()
  const snippet = result.snippet.toLowerCase()
  const companyLow = normalize(target.companyName)
  const companyTokens = companyLow.split(/\s+/).filter(t => t.length > 3)

  // 1. Company exact match dans snippet → +40
  if (snippet.includes(companyLow)) score += 40
  // 1b. Tokens entreprise dans snippet (au moins 2 tokens significatifs)
  else if (companyTokens.filter(t => snippet.includes(t)).length >= 2) score += 25

  // 2. Person name in URL
  if (target.firstName && urlSegment.includes(normalize(target.firstName))) score += 15
  if (target.lastName && urlSegment.includes(normalize(target.lastName))) score += 25

  // 3. Person name in snippet
  if (target.lastName && snippet.includes(normalize(target.lastName))) score += 10

  // 4. City in snippet (if available)
  if (target.city && snippet.includes(normalize(target.city))) score += 5

  // === Cas évident : 85+ ou ≤30 → décision sans LLM ===
  if (score >= 85) return Math.min(100, score)         // match certain
  if (score <= 30) return score                          // rejet certain
  if (target.target === 'company' && score >= 70) return score  // company match plus tolerant

  // === Phase 2 : LLM uniquement pour la zone grise (35-84) ===
  // Use case Mistral Small + cache fort. Estimé 30-40 % des résultats.
  const llmScore = await llmClient.complete({
    useCaseSlug: 'linkedin_url_matching_scoring',
    variables: { target, result, deterministic_score: score },
    bypassCache: false,
  })
  const adjust = parseInt(llmScore.text) - 50   // LLM retourne 0-100, on convertit en delta -50..+50
  score = Math.max(0, Math.min(100, score + adjust * 0.3))   // bornage et atténuation

  return score
}

function normalize(s: string): string {
  return s.toLowerCase()
    .normalize('NFD').replace(/[̀-ͯ]/g,'')   // unaccent
    .replace(/[^\w\s]/g,'')
    .replace(/\s+/g,' ')
    .trim()
}
```

**Impact budget LLM (P0 audit) :**
- Avant : 600 k appels Mistral Small × ~0.0008 € = 480 €/mois
- Après : ~200 k appels (35 % zone grise) = 160 €/mois
- **Économie : 320 €/mois.**

### Anti-bot durci (P0 audit v1.1)

> **Problème v1.0** : la spec ne mentionnait que stealth + UA rotation + cool-down. Insuffisant face à Google qui utilise Canvas/WebGL/Audio fingerprinting + reCAPTCHA v3 invisible + "unusual traffic" interstitiels non-captcha.

**Configuration browser context durcie :**

```typescript
// workers/src/scrapers/utils/google-context.ts
import { chromium } from 'playwright-extra'
import stealth from 'puppeteer-extra-plugin-stealth'
import recaptcha from 'puppeteer-extra-plugin-recaptcha'
import anonymizeUA from 'puppeteer-extra-plugin-anonymize-ua'
import fingerprintRandomizer from 'puppeteer-extra-plugin-fingerprint-randomizer'

chromium.use(stealth())
chromium.use(anonymizeUA())
chromium.use(fingerprintRandomizer({
  canvas: { randomize: true },          // P0 audit : Canvas FP
  webgl:  { randomize: true },          // P0 audit : WebGL FP
  audio:  { randomize: true },          // P0 audit : Audio FP
}))
chromium.use(recaptcha({
  provider: { id: '2captcha', token: process.env.CAPTCHA_TOKEN },
  visualFeedback: false,
}))

export async function buildGoogleContext(ua: UserAgent, proxy: ProxyData, sessionId: string) {
  const browser = await chromium.launch({ proxy: { server: proxy.proxyUrl } })

  // P0 audit : cookie warehouse persistance par sticky session (30 min IPRoyal)
  const storagePath = `/app/cookie-warehouse/${sessionId}.json`
  const storageState = await fileExists(storagePath)
    ? JSON.parse(await fs.readFile(storagePath, 'utf8'))
    : undefined

  return await browser.newContext({
    userAgent: ua.userAgent,
    locale: 'fr-FR',
    timezoneId: 'Europe/Paris',
    viewport: { width: 1920, height: 1080 },
    storageState,                         // restaure cookies si dispo
    extraHTTPHeaders: {
      'Accept-Language': 'fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
      'Sec-Ch-Ua-Platform': '"Windows"',
      'Sec-Fetch-Site': 'none',
      'Sec-Fetch-Mode': 'navigate',
      'Sec-Fetch-User': '?1',
      'Sec-Fetch-Dest': 'document',
      'Upgrade-Insecure-Requests': '1',
      'DNT': '1',
    },
  })
}

// Mouse movements simulés avant click
export async function humanClick(page: Page, selector: string): Promise<void> {
  const box = await page.locator(selector).first().boundingBox()
  if (!box) throw new Error('selector_not_visible')
  const target = { x: box.x + box.width / 2, y: box.y + box.height / 2 }
  // Mouvement en 20-30 étapes avec micro-pauses
  await page.mouse.move(target.x + 200, target.y - 150)
  await page.waitForTimeout(rand(80, 200))
  await page.mouse.move(target.x, target.y, { steps: rand(20, 35) })
  await page.waitForTimeout(rand(50, 150))
  await page.mouse.click(target.x, target.y)
}

// Sauvegarde cookies en fin de session (cookie warehouse)
export async function persistCookies(ctx: BrowserContext, sessionId: string) {
  const state = await ctx.storageState()
  await fs.writeFile(`/app/cookie-warehouse/${sessionId}.json`, JSON.stringify(state))
}
```

**Détection "unusual traffic" (P0 audit) :**

```typescript
async function detectAntiBot(page: Page): Promise<'ok'|'captcha_v2'|'captcha_v3'|'unusual_traffic'|'cf_challenge'> {
  if (await page.locator('form[action*="captcha"]').isVisible({timeout:500}).catch(()=>false))   return 'captcha_v2'
  if (await page.locator('.g-recaptcha[data-size="invisible"]').count() > 0)                       return 'captcha_v3'
  if (await page.locator('text=Our systems have detected unusual traffic').isVisible({timeout:500}).catch(()=>false)) return 'unusual_traffic'
  if (await page.locator('text=Trafic inhabituel détecté').isVisible({timeout:500}).catch(()=>false))                 return 'unusual_traffic'
  if (await page.locator('.cf-browser-verification, #cf-challenge').isVisible({timeout:500}).catch(()=>false))        return 'cf_challenge'
  return 'ok'
}
```

**Si `unusual_traffic` détecté :** marque moteur en `captcha_challenge` immédiatement + cooldown 60 min (vs 30 min captcha v2). Le pattern revient toutes les ~50 requêtes par IP, donc nécessite IP fraîche.

**2captcha intégration OBLIGATOIRE (P0 audit) :**
- Budget estimé : 30-50 €/mois (vs "optionnel" v1.0)
- Auto-résolution reCAPTCHA v2 visible (90 % cases)
- reCAPTCHA v3 invisible : pas résolu par 2captcha, seule rotation IP fonctionne

### États moteur (`search_engines.state`)

- `active` — utilisable
- `captcha_v2_solving` — captcha v2 en cours résolution 2captcha (~30s)
- `captcha_v3_blocked` — captcha v3 invisible détecté, cooldown 60 min
- `unusual_traffic` — interstitiel Google, cooldown 60 min
- `cf_challenge` — Cloudflare JS challenge, fallback proxy résidentiel premium
- `rate_limited` — 429 détecté, cooldown 15 min
- `cooldown` — manual disable
- `disabled` — disabled forever

### Mapping DB

- `linkedin_url_searches` (1 row par recherche, raw results + best URL + confiance)
- `companies.linkedin_url` (UPDATE si target='company' et confidence > 70)
- `contacts.linkedin_url` (UPDATE si target='person' et confidence > 70)

---

## §10 — Source 10 : France Travail API (signaux recrutement)

### Identité

- **Source** : `https://api.francetravail.io/partenaire/offresdemploi/v2/offres`
- **Auth** : OAuth2 client credentials
- **Coût** : 0 €
- **Quota** : 1 req/s, jusqu'à 5000/jour

### Objectif

Détecter signaux d'achat = entreprises qui recrutent (a fortiori sur métiers IA/Data/Digital). Recrutement = budget = potentiel client.

### Endpoint

```
GET /partenaire/offresdemploi/v2/offres/search
    ?qualification=Cadre
    &experience=2
    &nature_contrat=E1
    &range=0-149
```

### Détection signal

Pour chaque offre :
- Si SIREN entreprise = SIREN dans nos `companies` → INSERT `company_business_signals` avec `signal_type = 'hiring_surge'`, score basé sur nombre d'offres récentes

### Pseudo-code (PHP)

```php
public function detectHiringSignals(): Collection
{
    $token = $this->oauthMgr->get('france_travail');
    $now = now();
    $offers = Http::withToken($token)
        ->get('https://api.francetravail.io/partenaire/offresdemploi/v2/offres/search', [
            'qualification' => 'Cadre',
            'publieeDepuis' => 7,                       // 7 derniers jours
            'range'         => '0-149',
        ])->json('resultats');

    return collect($offers)
        ->groupBy('entreprise.siret')
        ->filter(fn($group, $siret) => $siret)
        ->map(function ($offers, $siret) use ($now) {
            $siren = substr($siret, 0, 9);
            $company = Company::firstWhere('siren', $siren);
            if (!$company) return null;

            return CompanyBusinessSignal::firstOrCreate([
                'company_id'  => $company->id,
                'signal_type' => 'hiring_surge',
                'detected_at' => $now->toDateString(),
                'source'      => 'france_travail',
            ], [
                'signal_score' => min(100, count($offers) * 10),
                'metadata'     => ['offers_count' => count($offers), 'titles' => $offers->pluck('intitule')->take(5)],
            ]);
        })
        ->filter();
}
```

---

## §11 — Source 11 : MESRI/ONISEP/data.gouv (écoles + universités + CFA)

### Identité

- **Sources** :
  - `https://data.enseignementsup-recherche.gouv.fr/api/explore/v2.1/catalog/datasets/fr-esr-principaux-etablissements-enseignement-superieur/records`
  - `https://api-lookup.onisep.fr/` (recherche établissements)
  - `https://data.education.gouv.fr/api/explore/v2.1/catalog/datasets/fr-en-annuaire-education/records`
- **Type** : API JSON (open data)
- **Coût** : 0 €

### Objectif

Identifier les **écoles, universités, IUT, CFA, CNAM, écoles d'ingénieur et de commerce** pour proposer des formations IA opérationnelle Axion-IA.

### Mapping → `schools`

```php
$row = [
    'uai'             => $r['uai'],
    'name'            => $r['nom'],
    'type'            => $this->mapType($r['type_etablissement']),
    'ministry'        => $r['ministere_tutelle'],
    'city_insee'      => $r['code_commune'],
    'department_code' => $r['code_departement'],
    'region_code'     => $r['code_region'],
    'website_url'     => $r['url_site_etablissement'],
    'main_email'      => $r['mail'],
    'main_phone'      => $r['telephone'],
    'student_count'   => $r['effectif_etudiants'],
    'source'          => 'mesri',
];
```

### Pagination

Sources MESRI/data.gouv paginées par 100 records. Scrape **sans limite arbitraire** jusqu'à fin des résultats (peut être > 50 pages).

---

## §12 — Source 12 : Crunchbase (levées de fonds Tech)

### Identité

- **Source** : `https://www.crunchbase.com/` (lecture publique limitée)
- **Type** : Scraping Playwright PRUDENT (cooldown 60s entre searches)
- **Proxies** : OBLIGATOIRES (résidentiels)
- **Quota** : ~10 req/h/IP (très restrictif)

### Objectif

Levées de fonds Tech FR → signal d'achat fort (entreprises avec budget IA).

### URL pattern

```
https://www.crunchbase.com/organization/{slug}
```

### Stratégie

Cherche d'abord via Google : `"raison sociale" site:crunchbase.com/organization`. Si URL trouvée → scrape la page Crunchbase elle-même (mais limiter à entreprises **détectées comme Tech** dans `companies.naf_subclass_code` SECTION J).

### Mapping → `company_business_signals`

`signal_type = 'fundraising'`, score = `min(100, amount_usd / 100000)` (ex: 1 M$ → score 10, 10 M$ → score 100).

---

## §13 — Source 13 : api-adresse.data.gouv.fr (BAN, géocodage)

### Identité

- **Source** : `https://api-adresse.data.gouv.fr/search/`
- **Type** : API JSON (BAN officielle Etalab)
- **Coût** : 0 €
- **Quota** : Pas de limite officielle, ~50 req/s safe

### Objectif

Géocoder précisément l'adresse siège de chaque entreprise pour la carte. Officiel, gratuit, illimité.

### Endpoint

```
GET /search/?q={adresse}&limit=1&autocomplete=0
```

### Pseudo-code (PHP)

```php
public function geocode(string $addr): ?array
{
    $resp = Http::timeout(5)->get('https://api-adresse.data.gouv.fr/search/', [
        'q'           => $addr,
        'limit'       => 1,
        'autocomplete'=> 0,
    ]);
    $best = $resp->json('features.0');
    if (!$best || $best['properties']['score'] < 0.75) return null;
    return [
        'lat'   => $best['geometry']['coordinates'][1],
        'lon'   => $best['geometry']['coordinates'][0],
        'score' => $best['properties']['score'],
        'city_insee' => $best['properties']['citycode'],
    ];
}
```

### Mapping DB

- `companies.headquarter_geom = ST_SetSRID(ST_MakePoint(lon, lat), 4326)`
- `company_addresses.geom`, `company_addresses.geocoding_score`

---

## §14 — Source 14 : Social light (handles X/YouTube/TikTok/Instagram/Facebook)

### Identité

- **Type** : Scraping Playwright + parsing sites web déjà scrappés
- **Proxies** : Recommandés
- **Objectif** : URL/handle uniquement. Aucun scraping de contenu.

### Stratégie

1. Lors du scraping site web (source 8) : extraction `<a href="https://twitter.com/..."`, `youtube.com`, etc.
2. Fallback : Google Search Wrapper avec `"raison sociale" site:youtube.com/c/` etc.

### Mapping DB

`company_social_handles` UPSERT avec platform + handle + url.

---

## MODULE DIRECTION FINDER (activé si effectif > 100)

### Activation conditionnelle

```php
if ($company->effectif_min >= 100 OR $company->size_category IN ('eti','ge')) {
    dispatch(new DirectionFinderJob($company));
}
```

### Workflow 4 sources combinées

```
DirectionFinderRun.started_at = now()
  │
  ├── Source 1 : pages corporate (/direction, /equipe, /leadership, ...)
  │   ├── 25+ URLs cibles FR + EN testées
  │   ├── Cache `corporate_pages_crawled` TTL 30j
  │   ├── Parser structuré (cards photo+nom+titre)
  │   └── Fallback LLM `extract_team_from_page` si page non structurée
  │
  ├── Source 2 : pages presse (/presse, /newsroom, /communiques)
  │   ├── Crawl 50 derniers communiqués
  │   ├── Use case LLM `business_signal_detection`
  │   └── Détection nominations C-level récentes
  │
  ├── Source 3 : rapport annuel PDF (URD pour cotées + Google `filetype:pdf`)
  │   ├── Source AMF si is_listed=true
  │   ├── Téléchargement PDF
  │   ├── Parsing `pdf-parse` + extraction pages "Organes de direction"
  │   └── LLM si texte non structuré
  │
  └── Source 4 : Google Search Wrapper étendu C-level
      ├── 5 postes types × 2 variantes FR/EN = 10 requêtes par entreprise
      └── Récupération URLs LinkedIn publiques uniquement

  │
  ▼
Pour chaque C-level trouvé :
  1. Détection pattern email entreprise (depuis emails site web Source 8)
  2. Génération variantes email
  3. Validation SMTP cascade
  4. INSERT `contacts` avec discovery_source = 'direction_finder', seniority_level = 'c_level'
  5. UPDATE `direction_finder_runs.c_level_with_email +1` si valid
```

### URLs cibles pages corporate

```typescript
const DIRECTION_PATHS = [
  // FR
  '/direction', '/equipe-de-direction', '/notre-equipe', '/notre-direction',
  '/leadership', '/management', '/governance', '/gouvernance',
  '/dirigeants', '/comite-executif', '/comex', '/conseil-administration',
  '/cse', '/equipe',
  // EN
  '/leadership', '/management', '/about/leadership', '/about/team',
  '/about-us/leadership', '/about-us/team', '/our-team', '/team',
  '/board', '/board-of-directors', '/executive-team', '/executives',
  // Generic
  '/staff', '/people', '/membres', '/collaborateurs',
]
```

### Pseudo-code Direction Finder Worker (TypeScript complet)

```typescript
// workers/src/scrapers/direction-finder.ts
import { chromium } from 'playwright-extra'
import stealth from 'puppeteer-extra-plugin-stealth'
import pdfParse from 'pdf-parse'
import { llmClient } from '../llm/client'
import { googleSearch } from './google-search-wrapper'
import type { ScraperPlugin, ScraperContext, ScraperResult } from './types'

chromium.use(stealth())

const DIRECTION_PATHS = [/* cf. liste plus haut */]
const PRESS_PATHS = ['/presse','/newsroom','/communiques','/press-releases','/actualites','/news']
const C_LEVEL_TARGETS = ['c_level_drh','c_level_daf','c_level_dsi','c_level_cmo','c_level_cco']

export const DirectionFinderPlugin: ScraperPlugin = {
  slug: 'direction_finder',
  async scrape(ctx: ScraperContext): Promise<ScraperResult> {
    const start = Date.now()
    const company = JSON.parse(ctx.targetRef)  // { id, websiteUrl, legalName, isListed }
    const sourcesAttempted: string[] = []
    const sourcesSuccessful: string[] = []
    let cLevelFound: TeamMember[] = []
    let pagesCrawled = 0
    let llmTokens = 0
    let llmCostEur = 0

    const browser = await chromium.launch({
      proxy: ctx.proxy ? { server: ctx.proxy.url } : undefined,
    })

    try {
      // === Source 1 : pages corporate ===
      sourcesAttempted.push('corporate_pages')
      for (const path of DIRECTION_PATHS) {
        const url = new URL(path, company.websiteUrl).toString()
        try {
          const page = await browser.newPage({ userAgent: ctx.userAgent })
          await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 15_000 })
          pagesCrawled++
          const status = page.url() !== url && page.url() === company.websiteUrl ? 404 : 200
          if (status === 404) { await page.close(); continue }

          const html = await page.content()
          const sha = await sha256(html)
          await cacheUpsert(company.id, url, 'direction', sha, {})  // corporate_pages_crawled

          // Structured first
          const structured = await extractStructuredTeam(page)
          if (structured.length > 0) {
            cLevelFound.push(...filterCLevel(structured, url))
            sourcesSuccessful.push('corporate_pages')
            await page.close()
            break  // 1ère page direction trouvée suffit
          }

          // LLM fallback for unstructured
          const excerpt = html.slice(0, 12_000)
          const llmResp = await llmClient.complete({
            useCase: 'extract_team_from_page',
            variables: { html_excerpt: excerpt, url },
          })
          llmTokens += llmResp.tokens
          llmCostEur += llmResp.costEur
          const parsed = JSON.parse(llmResp.text) as TeamMember[]
          if (parsed.length > 0) {
            cLevelFound.push(...filterCLevel(parsed, url))
            sourcesSuccessful.push('corporate_pages')
            await page.close()
            break
          }
          await page.close()
        } catch (e) { /* swallow */ }
      }

      // === Source 2 : pages presse ===
      if (cLevelFound.length < 3) {
        sourcesAttempted.push('press_releases')
        for (const pp of PRESS_PATHS) {
          const url = new URL(pp, company.websiteUrl).toString()
          try {
            const page = await browser.newPage()
            await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 15_000 })
            const articleLinks = await page.locator('a').evaluateAll(as => as.map(a => (a as HTMLAnchorElement).href).filter(h => h.includes('/communique') || h.includes('/news') || h.includes('/article')))
            await page.close()

            const recent = articleLinks.slice(0, 50)
            for (const link of recent) {
              const articlePage = await browser.newPage()
              await articlePage.goto(link, { timeout: 10_000 })
              const articleHtml = await articlePage.content()
              const llmResp = await llmClient.complete({
                useCase: 'business_signal_detection',
                variables: { html: articleHtml.slice(0, 6000), url: link },
              })
              llmTokens += llmResp.tokens
              llmCostEur += llmResp.costEur
              const result = JSON.parse(llmResp.text)
              if (result.nominations && result.nominations.length > 0) {
                cLevelFound.push(...result.nominations.map((n: any) => ({...n, foundUrl: link})))
                await indexPressRelease(company.id, link, result)
              }
              await articlePage.close()
              if (cLevelFound.length >= 5) break
            }
            if (cLevelFound.length > 0) { sourcesSuccessful.push('press_releases'); break }
          } catch (e) { /* swallow */ }
        }
      }

      // === Source 3 : rapport annuel PDF ===
      if (cLevelFound.length < 5 && company.isListed) {
        sourcesAttempted.push('annual_report')
        const amfUrl = await findUrdOnAmf(company.legalName)
        if (amfUrl) {
          const pdfBuffer = await downloadPdf(amfUrl)
          const pdfData = await pdfParse(pdfBuffer)
          const leadershipSection = extractLeadershipSection(pdfData.text)
          if (leadershipSection) {
            const llmResp = await llmClient.complete({
              useCase: 'extract_team_from_page',
              variables: { html_excerpt: leadershipSection, url: amfUrl },
            })
            llmTokens += llmResp.tokens
            llmCostEur += llmResp.costEur
            const parsed = JSON.parse(llmResp.text) as TeamMember[]
            cLevelFound.push(...filterCLevel(parsed, amfUrl))
            sourcesSuccessful.push('annual_report')
            await indexAnnualReport(company.id, amfUrl, parsed)
          }
        }
      }

      // === Source 4 : Google Search Wrapper étendu ===
      sourcesAttempted.push('google_search_extended')
      for (const target of C_LEVEL_TARGETS) {
        const sr = await googleSearch.searchLinkedIn(target, {
          companyName: company.legalName, proxy: ctx.proxy, userAgent: ctx.userAgent,
        })
        for (const r of sr.results.slice(0, 3)) {
          if (r.url && r.url.includes('linkedin.com/in/')) {
            const parsed = parseNameFromLinkedinUrl(r.url, r.snippet)
            if (parsed) {
              cLevelFound.push({
                ...parsed,
                position: mapTargetToPosition(target),
                discoveryUrl: r.url,
                linkedinUrl: r.url,
              })
              sourcesSuccessful.push('google_search_extended')
            }
          }
        }
      }

      // Déduplication finale
      cLevelFound = dedupCLevel(cLevelFound)

      return {
        status: 'ok',
        data: {
          c_level: cLevelFound,
          sources_attempted: sourcesAttempted,
          sources_successful: sourcesSuccessful,
          pages_crawled: pagesCrawled,
        },
        metrics: {
          durationMs: Date.now() - start,
          contactsFound: cLevelFound.length,
          emailsFound: 0,  // emails ajoutés en étape 7 (email finder)
          tokensConsumed: llmTokens,
          bytesIn: 0, bytesOut: 0,
        },
      }
    } catch (e: any) {
      return {
        status: 'failed',
        data: { sources_attempted: sourcesAttempted, sources_successful: sourcesSuccessful },
        metrics: { durationMs: Date.now() - start, contactsFound: 0, emailsFound: 0, bytesIn: 0, bytesOut: 0 },
        error: { code: e.code ?? 'unknown', message: e.message },
      }
    } finally {
      await browser.close()
    }
  }
}

function mapTargetToPosition(target: string): string {
  return {
    c_level_drh: 'Directeur Ressources Humaines',
    c_level_daf: 'Directeur Administratif et Financier',
    c_level_dsi: 'Directeur Systèmes d\'Information',
    c_level_cmo: 'Directeur Marketing',
    c_level_cco: 'Directeur Commercial',
  }[target] ?? 'C-level'
}

function dedupCLevel(found: TeamMember[]): TeamMember[] {
  const seen = new Set<string>()
  const out: TeamMember[] = []
  for (const m of found) {
    const key = `${normalize(m.firstName)}|${normalize(m.lastName)}`
    if (seen.has(key)) continue
    seen.add(key)
    out.push(m)
  }
  return out
}
```

### Mapping DB

- `direction_finder_runs` (1 row par run, agrégé)
- `corporate_pages_crawled` (cache, TTL 30 j)
- `press_releases_indexed` (1 par communiqué)
- `annual_reports_indexed` (1 par rapport)
- `contacts` (UPSERT C-level trouvés, `discovery_source = 'direction_finder'`)
- `linkedin_url_searches` (transitif via Google Search Wrapper)

### Robustesse durcie (P0/P1 audit v1.1)

> **P0** : utiliser **Webshare datacenter** pour les sites corporate (95 % cas suffisant, sites publics). Résidentiel uniquement pour les Source 4 (Google Search étendu). Économie ~700 €/mo proxies.

> **P0** : cap download PDF rapport annuel à **10 MB** (`Content-Length` header check, abort si dépasse). Évite de tirer un PDF de 200 MB qui plombe la bande passante.

```typescript
async function downloadPdf(url: string): Promise<Buffer | null> {
  const head = await undici.fetch(url, { method: 'HEAD' })
  const len = parseInt(head.headers.get('content-length') ?? '0')
  if (len > 10 * 1024 * 1024) {
    logger.warn({ url, sizeBytes: len }, 'pdf_too_large_skipped')
    return null
  }
  const resp = await undici.fetch(url)
  return Buffer.from(await resp.arrayBuffer())
}
```

> **P1** : fallback explicite « no directory page found ». Si les 25 paths corporate testés retournent tous 404 ou page sans `.team-member` parsable, marquer `direction_finder_runs.status = 'ok'` avec `metadata.fallback_used = 'no_directory_page'` et passer directement à la Source 4 (Google Search étendu). Marquer l'entreprise `needs_manual_review = true` si même Source 4 ne trouve rien.

### Taux de succès attendu (à valider POC #3)

| Taille | Sans DF | Avec DF (cible v1.0 optimiste) | Avec DF (cible v1.1 prudente) |
|--------|---------|--------------------------------|-------------------------------|
| ETI 250-4999 | 5-15% | 25-40% | **20-30%** |
| Grandes 5000+ | 2-8% | 8-15% | **5-12%** |

> **POC #3 obligatoire** (cf. `AUDIT_v1.md` § 13) : valider taux réel sur 20 ETI test avant chiffrer.

---

## Récap mapping sources → tables DB

| Source | Tables principales modifiées |
|--------|------------------------------|
| INSEE Sirene | `companies` (INSERT/UPDATE), `company_addresses` |
| annuaire-entreprises | `companies` (UPDATE revenue, effectif), `contacts` (legal director) |
| Infogreffe | `companies` (metadata financière) |
| societe.com | `contacts` (fallback legal directors) |
| BODACC | `company_business_signals` |
| Google Maps | `companies.main_phone`, `companies.website_url`, `company_phones` |
| Pages Jaunes | idem Google Maps (backup) |
| Sites web | `company_emails`, `email_patterns`, `contacts` (team), `company_social_handles`, `company_strategic_keywords` |
| Google Search Wrapper | `linkedin_url_searches`, `companies.linkedin_url`, `contacts.linkedin_url` |
| France Travail | `company_business_signals` (hiring_surge) |
| MESRI/ONISEP | `schools` |
| Crunchbase | `company_business_signals` (fundraising) |
| BAN | `companies.headquarter_geom`, `company_addresses.geom` |
| Social light | `company_social_handles` |
| Direction Finder | `direction_finder_runs`, `corporate_pages_crawled`, `press_releases_indexed`, `annual_reports_indexed`, `contacts` (C-level) |

---

## Pagination sans limite

**TOUS les scrapers paginés** (Google Maps liste, Pages Jaunes, MESRI/ONISEP, BODACC, sites web crawl) continuent jusqu'à fin réelle des résultats. **Aucune limite arbitraire 20 pages.**

Checkpoint à chaque page validée dans `scraper_runs.metadata.last_page_processed`. Reprise sur erreur depuis le checkpoint.

---

## Lecture suivante

→ `06_email_finder_validation.md` (algorithme patterns + cascade SMTP N1→N5).
