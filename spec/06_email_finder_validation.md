# 06 — Email finder + validation SMTP cascade

> **Cœur business :** sans email validé, pas de cold email Phase 2.
> **Cible qualité :** ≥ 70% taux validation des emails inférés (cible Phase 1).
> **Cascade 5 niveaux** : syntaxe → DNS MX → SMTP handshake → catch-all detection → scoring final.
> **TTL revalidation** : 30 jours par défaut, configurable par workspace (`scraping_sources.ttl_revalidation_days`).

---

## §1 — Patterns email (15+ variantes)

### Variables tokens

| Token | Valeur | Exemple "Marie-Anne LE GALL" |
|-------|--------|------------------------------|
| `{first}` | prénom normalisé | `marieanne` (composé fusionné) ou `marie-anne` selon variante |
| `{last}` | nom normalisé | `legall` |
| `{f}` | initiale prénom | `m` |
| `{l}` | initiale nom | `l` |
| `{first_short}` | prénom court (1er du composé) | `marie` |
| `{last_short}` | nom court (sans particule) | `gall` |
| `{domain}` | domaine entreprise | `axion-ia.com` |

### Normalisation FR

```php
function normalize_local_part(string $input): string
{
    $s = mb_strtolower($input, 'UTF-8');
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);  // é→e, ô→o
    $s = preg_replace('/\s+/', '', $s);                  // remove all spaces
    $s = preg_replace('/[^a-z0-9.\-_]/', '', $s);        // keep only safe chars
    return $s;
}

function strip_particles(string $lastname): string
{
    // 'de la Tour' → 'tour' / 'le Gall' → 'gall' / 'd\'Artagnan' → 'artagnan'
    $particles = ['de','du','des','le','la','les','d','l','von','van','der','el','al'];
    $words = preg_split('/[\s\-\']+/', $lastname);
    $kept = array_filter($words, fn($w) => !in_array(mb_strtolower($w), $particles));
    return implode('', $kept);
}
```

### 15+ patterns supportés

| # | Pattern | Exemple `Marie-Anne LE GALL @ axion-ia.com` |
|---|---------|---------------------------------------------|
| 1  | `{first}.{last}@{domain}`           | `marie-anne.legall@axion-ia.com` |
| 2  | `{first}{last}@{domain}`            | `marie-annelegall@axion-ia.com` |
| 3  | `{first}-{last}@{domain}`           | `marie-anne-legall@axion-ia.com` |
| 4  | `{first}_{last}@{domain}`           | `marie-anne_legall@axion-ia.com` |
| 5  | `{f}{last}@{domain}`                | `mlegall@axion-ia.com` |
| 6  | `{f}.{last}@{domain}`               | `m.legall@axion-ia.com` |
| 7  | `{f}-{last}@{domain}`               | `m-legall@axion-ia.com` |
| 8  | `{last}.{first}@{domain}`           | `legall.marie-anne@axion-ia.com` |
| 9  | `{last}{first}@{domain}`            | `legallmarie-anne@axion-ia.com` |
| 10 | `{last}.{f}@{domain}`               | `legall.m@axion-ia.com` |
| 11 | `{last}{f}@{domain}`                | `legallm@axion-ia.com` |
| 12 | `{first}@{domain}`                  | `marie-anne@axion-ia.com` |
| 13 | `{first_short}.{last}@{domain}`     | `marie.legall@axion-ia.com` |
| 14 | `{first_short}{last}@{domain}`      | `marielegall@axion-ia.com` |
| 15 | `{f}{l}@{domain}`                   | `ml@axion-ia.com` |
| 16 | `{last_short}.{first}@{domain}`     | `gall.marie-anne@axion-ia.com` |
| 17 | `{first}{l}@{domain}`               | `marie-annel@axion-ia.com` |
| 18 | `{f}_{last}@{domain}`               | `m_legall@axion-ia.com` |

### Détection pattern entreprise

Quand on a `N` emails nominatifs trouvés sur le site + membres équipe identifiés :

```php
public function detectPattern(array $emails, array $teamMembers, string $domain): ?DetectedPattern
{
    $hits = [];
    foreach ($emails as $email) {
        if ($this->classify($email) !== 'nominative') continue;
        [$local, $emailDomain] = explode('@', strtolower($email));
        if ($emailDomain !== $domain) continue;
        foreach ($teamMembers as $m) {
            foreach (PATTERNS_15 as $patternTpl) {
                $candidate = $this->materializePattern($patternTpl, $m['first'], $m['last']);
                if ($candidate === $local) {
                    $hits[$patternTpl] ??= ['count' => 0, 'evidence' => []];
                    $hits[$patternTpl]['count']++;
                    $hits[$patternTpl]['evidence'][] = $email;
                    break;
                }
            }
        }
    }
    if (empty($hits)) return null;
    arsort($hits);
    $bestPattern = array_key_first($hits);
    $occurrences = $hits[$bestPattern]['count'];
    return new DetectedPattern(
        pattern: $bestPattern,
        domain: $domain,
        confidence: min(100, 40 + $occurrences * 15),
        evidence: $hits[$bestPattern]['evidence'],
    );
}
```

### Sauvegarde

INSERT dans `email_patterns` avec `(workspace_id, company_id, pattern, domain, confidence, evidence_emails)`.

---

## §2 — Génération variantes pour un contact

Quand on a un contact (legal director ou C-level Direction Finder) et qu'on connaît le pattern entreprise :

```php
public function generateCandidates(Contact $c, Company $company): array
{
    $patterns = EmailPattern::where('company_id', $company->id)
        ->orderByDesc('confidence')
        ->limit(3)
        ->get();

    $domain = $company->main_email
        ? Str::after($company->main_email, '@')
        : parse_url($company->website_url, PHP_URL_HOST);
    $domain = preg_replace('/^www\./', '', $domain ?? '');

    $candidates = [];
    if ($patterns->isNotEmpty()) {
        // Pattern connu : on génère seulement le meilleur
        foreach ($patterns as $p) {
            $candidates[] = [
                'email' => $this->materializePattern($p->pattern, $c->first_name, $c->last_name, $domain),
                'pattern' => $p->pattern,
                'priority' => $p->confidence,
            ];
        }
    } else {
        // Pas de pattern connu : on génère TOUS, validation cascade pour scorer
        foreach (PATTERNS_15 as $tpl) {
            $candidates[] = [
                'email' => $this->materializePattern($tpl, $c->first_name, $c->last_name, $domain),
                'pattern' => $tpl,
                'priority' => 50,
            ];
        }
    }
    return array_unique($candidates, SORT_REGULAR);
}
```

---

## §3 — Cascade SMTP validation (N1 → N5)

### N1 — Syntaxe RFC 5322

```php
function validateSyntax(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        && strlen($email) <= 254
        && !preg_match('/\.\./', $email);  // no consecutive dots
}
```

Si N1 échoue → `validation_status = 'invalid'`, score = 0. Stop cascade.

### N2 — DNS MX records

```php
function lookupMxRecords(string $domain): array
{
    $records = dns_get_record($domain, DNS_MX);
    if (empty($records)) return [];
    usort($records, fn($a, $b) => $a['pri'] <=> $b['pri']);
    return array_map(fn($r) => $r['target'], $records);
}
```

Si MX absent → `validation_status = 'invalid'`, score = 5. Stop.

### N3 — SMTP handshake (probe RCPT TO)

```php
class SmtpValidator
{
    public function probe(string $email, array $mxHosts): SmtpProbeResult
    {
        [$local, $domain] = explode('@', $email);
        $result = new SmtpProbeResult();

        foreach ($mxHosts as $mx) {
            try {
                $socket = fsockopen($mx, 25, $errNo, $errStr, 10);
                if (!$socket) continue;

                stream_set_timeout($socket, 5);
                $banner = fgets($socket);            // 220
                fputs($socket, "EHLO axion-validator.com\r\n");
                $ehlo = $this->readMultiline($socket);
                if (!str_starts_with($ehlo, '250')) { fclose($socket); continue; }

                fputs($socket, "MAIL FROM:<validator@axion-pro.com>\r\n");
                $mailFromResp = fgets($socket);
                if (!str_starts_with($mailFromResp, '250')) { fclose($socket); continue; }

                fputs($socket, "RCPT TO:<{$email}>\r\n");
                $rcptResp = fgets($socket);
                $result->mxUsed = $mx;
                $result->rcptCode = (int) substr($rcptResp, 0, 3);
                $result->rcptMessage = trim(substr($rcptResp, 4));

                fputs($socket, "QUIT\r\n");
                fclose($socket);

                if ($result->rcptCode >= 200 && $result->rcptCode < 300) {
                    $result->status = 'valid_probable';
                } elseif ($result->rcptCode === 550 || $result->rcptCode === 551) {
                    $result->status = 'invalid';
                } elseif ($result->rcptCode === 252) {
                    $result->status = 'cannot_verify';  // catch-all suspect
                } else {
                    $result->status = 'unknown';
                }
                return $result;
            } catch (\Throwable $e) {
                continue;
            }
        }

        $result->status = 'unreachable';
        return $result;
    }
}
```

**Important** :
- Le `MAIL FROM` doit utiliser un domaine résolvable avec une boîte légitime (pour éviter d'être catégorisé spammer).
- Connexion depuis **IPs dédiées validation** (cf. §6) pour ne pas griller la réputation des IPs de prod cold email.
- Timeouts stricts : 10s connect, 5s par command.

### N4 — Catch-all detection

Pour chaque domaine validé positif (status `valid_probable`), tester un email aléatoire :

```php
public function detectCatchAll(string $domain, array $mxHosts): bool
{
    $randomLocal = Str::random(16);  // ex: 'k3jx9zPm9vfdNQwL'
    $probeResult = $this->probe("{$randomLocal}@{$domain}", $mxHosts);
    return in_array($probeResult->status, ['valid_probable','cannot_verify'], true);
}
```

Cache résultat catch-all par domaine (TTL 7j) dans Redis `email_verif:catchall:{domain}`.

### N5 — Scoring final 0-100

```php
public function computeScore(SmtpProbeResult $r, bool $isCatchAll, bool $isDisposable, bool $isRoleBased, int $patternConfidence): int
{
    if ($r->status === 'invalid')      return 0;
    if ($r->status === 'unreachable')  return 20;
    if ($isDisposable)                 return 5;

    $score = match($r->status) {
        'valid_probable' => 80,
        'cannot_verify'  => 50,
        'unknown'        => 30,
        default          => 25,
    };

    if ($isCatchAll)    $score -= 20;       // catch-all = incertitude
    if ($isRoleBased)   $score -= 10;       // info@, contact@ : moins idéal nominatif
    if ($patternConfidence >= 90) $score += 10;
    if ($patternConfidence >= 70 && $patternConfidence < 90) $score += 5;

    return max(0, min(100, $score));
}
```

**Mapping score → validation_status** :

| Score | Status | Action cold email |
|-------|--------|-------------------|
| ≥ 80  | `valid` | OK, send |
| 60-79 | `valid` (catch-all OK) | OK avec warning |
| 40-59 | `unknown` | À skip si fiche 🟢 strict |
| 20-39 | `unknown` | Skip |
| 0-19  | `invalid` | Hard skip + opt_out global |

---

## §4 — Disposable & role-based detection

### Listes embarquées

```php
// app/Services/EmailValidation/DisposableDomains.php
const DISPOSABLE_DOMAINS = [
    'mailinator.com','guerrillamail.com','tempmail.com','10minutemail.com',
    'throwaway.email','yopmail.com','dispostable.com','sharklasers.com',
    // ... liste mise à jour mensuellement depuis https://github.com/disposable/disposable
];

// app/Services/EmailValidation/RoleBased.php
const ROLE_BASED_LOCALS = [
    'contact','info','infos','hello','bonjour','sales','vente','rh','hr',
    'support','admin','webmaster','direction','recrutement','careers','jobs',
    'presse','press','marketing','commercial','dpo','legal','accounting','compta','help'
];
```

### Mise à jour mensuelle

Job Laravel `app:update-disposable-list` (1er du mois 02:00 UTC) fetch GitHub puis dump dans `storage/app/disposable_domains.json`.

---

## §5 — Workflow complet (orchestration)

```php
// app/Services/EmailFinder/EmailFinderService.php
class EmailFinderService
{
    public function findEmailsForContact(Contact $contact): EmailFinderResult
    {
        $company = $contact->company;

        // 1. Opt-out global check FIRST
        if ($this->isOptedOut($contact)) {
            return EmailFinderResult::optedOut();
        }

        // 2. Generate candidates
        $candidates = $this->generator->generateCandidates($contact, $company);
        if (empty($candidates)) {
            return EmailFinderResult::noCandidates();
        }

        // 3. Reuse cache (TTL 30j)
        $alreadyVerified = $this->fetchCachedVerifications($candidates, $contact->workspace_id);
        $toVerify = collect($candidates)
            ->reject(fn($c) => $alreadyVerified->contains('email', $c['email']))
            ->all();

        // 4. SMTP validation pipeline
        $mxCache = [];
        $results = [];
        foreach ($toVerify as $cand) {
            $email = $cand['email'];
            $domain = Str::after($email, '@');
            $mx = $mxCache[$domain] ??= $this->mxLookup->lookupMxRecords($domain);

            if (!$this->syntax->validate($email)) {
                $results[] = $this->store($contact, $email, 'invalid', 0, [], $domain, false, $cand);
                continue;
            }
            if (empty($mx)) {
                $results[] = $this->store($contact, $email, 'invalid', 5, [], $domain, false, $cand);
                continue;
            }

            $probe = $this->smtp->probe($email, $mx);

            $isCatchAll = $this->catchAllCache->get($domain) ?? $this->smtp->detectCatchAll($domain, $mx);
            $this->catchAllCache->put($domain, $isCatchAll, ttl: 7 * 86400);

            $isDisposable = in_array($domain, DISPOSABLE_DOMAINS, true);
            $isRoleBased  = in_array(Str::before($email, '@'), ROLE_BASED_LOCALS, true);

            $score = $this->scoring->computeScore($probe, $isCatchAll, $isDisposable, $isRoleBased, $cand['priority'] ?? 50);
            $status = $this->scoring->mapStatus($score, $probe, $isCatchAll);

            $results[] = $this->store($contact, $email, $status, $score, $mx, $domain, $isCatchAll, $cand);

            // Stop early si on a un valide ≥ 80
            if ($score >= 80 && $status === 'valid') break;
        }

        // 5. Pick best
        $best = collect($results)->merge($alreadyVerified)->sortByDesc('score')->first();
        if ($best && $best['score'] >= 60) {
            $contact->update([
                'primary_email'        => $best['email'],
                'primary_email_status' => $best['validation_status'],
                'primary_email_score'  => $best['score'],
            ]);
        }

        return new EmailFinderResult(
            verified: count($results),
            best: $best,
            allResults: $results,
        );
    }

    private function store(Contact $c, string $email, string $status, int $score, array $mx, string $domain, bool $catchAll, array $cand): array
    {
        return EmailVerification::updateOrCreate([
            'workspace_id' => $c->workspace_id,
            'email'        => $email,
        ], [
            'contact_id'        => $c->id,
            'company_id'        => $c->company_id,
            'pattern_used'      => $cand['pattern'] ?? null,
            'validation_status' => $status,
            'score'             => $score,
            'smtp_response'     => $cand['smtp_response'] ?? [],
            'mx_records'        => $mx,
            'is_catch_all'      => $catchAll,
            'is_role_based'     => in_array(Str::before($email, '@'), ROLE_BASED_LOCALS),
            'is_disposable'     => in_array($domain, DISPOSABLE_DOMAINS),
            'smtp_provider'     => $this->detectSmtpProvider($mx),
            'verified_via'      => 'inhouse',
            'validated_at'      => now(),
            'expires_at'        => now()->addDays(30),
        ])->toArray();
    }
}
```

---

## §6 — IPs dédiées validation

### Architecture

- **2 IPs supplémentaires Hetzner** dédiées validation SMTP (rDNS distinct)
- Pas utilisées pour le cold email envoi (séparation préserve réputation)
- Servies par container `validator-smtp` sur worker-2

### rDNS

```
validator-1.axion-pro.com    →    <ip1>
validator-2.axion-pro.com    →    <ip2>
```

### Rate limiting

- Max 30 SMTP probes/min/IP
- Max 1 probe/domaine/min (politesse + évite ban)
- Cool-down 5 min après 429 ou bloc fournisseur

### Détection blacklist

Job hourly `app:check-validator-ip-blacklist` :
- Probe Spamhaus, Barracuda, SORBS, Surriel, MAILSPIKE
- Si blacklist hit → notification Slack + désactivation IP

---

## §7 — Cache & TTL

### Cache validation 30 jours

Avant chaque probe :
```php
$cached = EmailVerification::where('workspace_id', $workspaceId)
    ->where('email', $email)
    ->where('validated_at', '>', now()->subDays(30))
    ->first();

if ($cached) return $cached;  // skip probe SMTP
```

### Cache MX lookup 1 heure

Redis : `email_validation:mx:{domain}` TTL 3600s.

### Cache catch-all 7 jours

Redis : `email_validation:catchall:{domain}` TTL 604800s.

### Cache disposable check (in-memory, jamais expiré sauf reload)

---

## §8 — Bounces handling (Phase 2 ready)

Quand Phase 2 démarre :
1. Hard bounce sur un email → trigger SQL `trg_bounce_hard_to_optout` (cf. `04_db_schema_phase2_scaffold.md`) insère dans `opt_out` global
2. La prochaine fois qu'on essaie de valider/scraper cet email → `validation_status = 'invalid'` immédiat sans probe

---

## §9 — Métriques exposées (Prometheus)

```
axion_crm_email_validations_total{status="valid|invalid|catch_all|unknown",workspace="..."}
axion_crm_email_validation_score_histogram{le="0|20|40|60|80|100"}
axion_crm_email_validation_duration_ms_histogram
axion_crm_email_validation_cache_hits_total{type="db_30d|mx|catchall"}
axion_crm_email_validation_smtp_errors_total{error_code="timeout|conn_refused|rate_limit|blacklist"}
axion_crm_email_pattern_detected_total{confidence="lt40|40_70|gte70"}
```

---

## §10 — Limites et améliorations futures

### Limites Phase 1

- Pas de probe Microsoft Office 365 (Microsoft a stoppé les probes SMTP en 2024). Workaround : scoring 50 par défaut + fallback Hunter.io API si activé.
- Catch-all reste flou : impossible de distinguer "boîte existe" vs "tout est accepté"
- Greylisting : faux négatif possible si serveur retourne 451 (temp fail). Mitigation : retry 4h plus tard.

### Phase 2 — providers payants optionnels

Lorsque budget le permet, activation via UI admin :
- **MillionVerifier** (~0.40$/1k emails) — fallback pour score < 60
- **Kickbox** (~0.40$/1k) — alternative
- **Hunter.io** (~50€/mois) — pour Office 365

Architecture pluggable : interface `EmailVerifierProvider` similaire à `ProxyProvider`.

---

## Lecture suivante

→ `07_llm_router.md` (interface LLMClient + 5 providers + fallback + cost tracking).
