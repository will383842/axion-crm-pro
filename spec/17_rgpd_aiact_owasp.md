# 17 — RGPD + AI Act + OWASP

> **Conformité dès jour 1.** Pas de "on s'occupera plus tard".
> Tout est documenté + audité + automatisé.

---

## §1 — Registre RGPD (article 30)

### Base légale principale

**Article 6.1.f RGPD** — Intérêt légitime pour prospection B2B sur emails pros nominatifs.

### Table `data_processing_log` seed

```sql
INSERT INTO data_processing_log (workspace_id, processing_purpose, legal_basis, data_categories, data_subjects, recipients, retention_period_days, security_measures) VALUES

-- Traitement 1 : Scraping & enrichissement
((SELECT id FROM workspaces WHERE slug='axion-ia'),
 'prospection_b2b_email',
 'legitimate_interest_b2b',
 ARRAY['professional_email','full_name','position','company_data','phone','linkedin_url'],
 ARRAY['legal_director','c_level','employee'],
 ARRAY['internal_marketing_team','internal_sales_team'],
 90,
 ARRAY['encryption_at_rest','encryption_in_transit','access_control_rbac','audit_log_hashchain','2fa_mandatory','rls_postgres']),

-- Traitement 2 : Cold email (Phase 2)
((SELECT id FROM workspaces WHERE slug='axion-ia'),
 'cold_email_outreach',
 'legitimate_interest_b2b',
 ARRAY['professional_email','full_name','position','open_click_data'],
 ARRAY['c_level','manager','employee'],
 ARRAY['internal_sales_team'],
 180,
 ARRAY['encryption_at_rest','encryption_in_transit','access_control_rbac','audit_log_hashchain','opt_out_global','double_optin_link_unsubscribe']),

-- Traitement 3 : Audit + sécurité
((SELECT id FROM workspaces WHERE slug='axion-ia'),
 'security_audit_log',
 'legal_obligation',
 ARRAY['ip_address','user_agent','session_id'],
 ARRAY['platform_user'],
 ARRAY['internal_security_team','external_auditor_if_required'],
 730,
 ARRAY['encryption_at_rest','hash_chain','immutable_append_only']);
```

### Conservation

- Données scraping : 90 jours après dernière utilisation (auto-purge job nightly `app:purge-stale-records`)
- Audit logs : 24 mois (partitionnement pg_partman retention)
- Email verifications : 365 jours après validated_at
- Données utilisateurs plateforme : durée du contrat + 6 mois

### Sous-processeurs documentés (P0 audit v1.1)

Chaque appel LLM = transfert PII potentiel (extrait HTML de site corporate peut contenir noms employés). Documentation obligatoire RGPD article 28.

| Sous-processeur | Service | Hébergement | DPA disponible | Statut Axion CRM Pro |
|------------------|---------|--------------|-----------------|------------------------|
| **Anthropic, PBC** | API Claude (Haiku 4.5, Sonnet 4.6) | US (data centers AWS US-East/West) | ✅ via Trust Center anthropic.com/legal/trust-center | À signer avant prod |
| **OpenAI, LLC** | API GPT-4o (optionnel, fallback) | US (data centers Azure US) | ✅ via openai.com/policies/data-processing-addendum | À signer si OpenAI activé |
| **Mistral AI SAS** | API mistral-small-latest | UE (France/Suède) | ✅ via mistral.ai/terms#data-processing-addendum | À signer — préférable UE-centric |
| **OpenRouter, Inc.** | Umbrella API multi-providers | US (routing) | ⚠️ peut router vers providers tiers non-DPA — UTILISER UNIQUEMENT pour use cases non-PII | Restriction : pas pour `extract_team_from_page`, `business_signal_detection` |
| **2captcha, Ltd** | Solving CAPTCHA externe | Russie (⚠️ hors UE, hors zones adéquates) | ⚠️ pas de DPA standard | À évaluer juridiquement : envoie image captcha (généralement sans PII), risque RGPD limité mais à documenter |
| **Webshare Proxies, LLC** | Proxies datacenter | US (passage traffic) | ✅ via webshare.io/dpa | À signer |
| **IPRoyal Inc.** | Proxies résidentiels | US (entité juridique) | ✅ via iproyal.com/legal/dpa | À signer |
| **Hetzner Online GmbH** | Hébergement infra | UE (Frankfurt) | ✅ inclus contrat standard | ✅ |
| **Cloudflare, Inc.** | CDN / WAF / DNS | US (mais infra europe) | ✅ via cloudflare.com/cloudflare-customer-dpa | À signer |
| **Backblaze, Inc.** | Backups offsite | US | ✅ via backblaze.com/company/dpa | À signer |

**Mitigation transferts hors UE :** clauses contractuelles types (CCT 2021) annexées à chaque contrat. Pour les use cases LLM les plus sensibles (extract_team_from_page), routing prioritaire vers **Mistral (UE)** dans la `fallback_chain` `llm_use_cases`.

**Restriction concrète v1.1 :** dans `07_llm_router.md` § 9, `extract_team_from_page` voit son provider principal basculé d'Anthropic vers Mistral en cas de demande client UE-stricte (configurable workspace).

---

### DPIA — Analyse d'impact sur la protection des données (P0 audit v1.1)

> **Produire avant promotion prod publique.** Modèle CNIL gratuit. Effort 4-8h rédaction.

**Pourquoi obligatoire ici** : article 35 RGPD impose une DPIA pour les traitements susceptibles d'engendrer un risque élevé. Critères atteints :
1. **Profilage automatisé à grande échelle** (200 k personnes/mois classifiées maturité IA + offre Axion-IA).
2. **Croisement de données** issues de multiples sources (INSEE + annuaire + Google Maps + sites + LinkedIn + presse).
3. **Données traitées de personnes vulnérables** : non applicable ici (B2B uniquement).

**Plan de la DPIA** (à produire dans `_DOCS/DPIA-2026.md` séparé) :

1. Description systématique du traitement
   - Finalités : prospection commerciale B2B
   - Catégories de données : nom, fonction, email pro, téléphone pro, URL LinkedIn publique
   - Catégories de personnes : dirigeants légaux + C-level d'entreprises FR
   - Durées de conservation : cf. § Conservation
   - Sous-processeurs : cf. tableau ci-dessus
2. Évaluation nécessité et proportionnalité
   - Base légale : intérêt légitime art. 6.1.f
   - Test de mise en balance (LIA) : produit dans annexe DPIA
   - Mesures de minimisation : pas d'emails personnels, pas de scraping LinkedIn direct, opt-out global
3. Évaluation des risques pour les droits et libertés
   - Risque 1 : profilage erroné menant à ciblage inapproprié → mitigation : override humain UI + transparency notice + LLM bornes documentées
   - Risque 2 : fuite cross-workspace → mitigation : RLS + audit hash chain
   - Risque 3 : violation données → mitigation : chiffrement at-rest + in-transit + backups encrypted + procédure incident < 72h
4. Mesures envisagées pour faire face aux risques
   - Toutes les mesures techniques + organisationnelles de la § 7-8
   - Procédure exercice droits art. 15-22 : cf. § 2
5. Consultation
   - DPO Axion-IA (Williams Jullin) — consultation interne
   - Pas de consultation CNIL préalable nécessaire (pas de high-risk caractérisé après mesures)

**Statut :** DPIA à produire avant **S12 promotion prod**. Bloquant.

---

## §2 — Droit d'accès & suppression (procédure)

### Workflow 5 étapes

1. **Réception** : email à `contact@axion-ia.com` → INSERT `gdpr_requests` (manuel ou auto si formulaire dédié)
2. **Vérification identité** : envoi pièce d'identité (manuel par DPO)
3. **Recherche données** : query cross-tables sur email/SIREN/nom
4. **Action** : export JSON (accès/portabilité) OU suppression atomique (erasure)
5. **Réponse** : courrier PDF + email avec confirmation

### Transaction multi-tables atomique (erasure)

```php
class GdprErasureService
{
    public function execute(GdprRequest $req): array
    {
        $email = $req->requester_email;
        $personNameNorm = $req->requester_name ? normalize_name($req->requester_name) : null;

        $impact = $this->previewImpact($email, $personNameNorm);

        DB::beginTransaction();
        try {
            // 1. Audit log AVANT
            AuditLog::record('gdpr.erasure.start', $req, ['email' => $email, 'impact' => $impact]);

            // 2. Anonymisation contacts (PAS suppression dure, sinon FK casse)
            $contacts = Contact::query()
                ->where(fn($q) => $q
                    ->where('primary_email', $email)
                    ->orWhereRaw('full_name_normalized = ?', [$personNameNorm])
                )->get();
            foreach ($contacts as $c) {
                $c->update([
                    'first_name' => null,
                    'last_name' => '[REDACTED]',
                    'primary_email' => null,
                    'primary_phone' => null,
                    'linkedin_url' => null,
                    'twitter_handle' => null,
                    'discovery_url' => null,
                    'notes' => null,
                    'deleted_at' => now(),
                ]);
            }

            // 3. Suppression email_verifications (data sensitive)
            EmailVerification::where('email', $email)->delete();
            EmailVerification::whereIn('contact_id', $contacts->pluck('id'))->delete();

            // 4. Suppression scraper_runs avec target_id contact_id
            ScraperRun::whereIn('target_id', $contacts->pluck('id'))->update([
                'metadata' => DB::raw("metadata - 'email' - 'full_name'"),
            ]);

            // 5. Linkedin URL searches
            LinkedinUrlSearch::whereIn('contact_id', $contacts->pluck('id'))->delete();

            // 6. INSERT opt_out global (irrévocable)
            OptOut::create([
                'email' => $email,
                'email_hash' => hash('sha256', $email),
                'person_name_norm' => $personNameNorm,
                'reason' => 'cnil_request',
                'source' => 'gdpr_erasure',
                'expires_at' => null,
            ]);

            // 7. Update request status
            $req->update([
                'status' => 'completed',
                'handled_at' => now(),
                'affected_records' => $impact,
                'response_sent_at' => now(),
            ]);

            // 8. Audit log APRÈS
            AuditLog::record('gdpr.erasure.complete', $req, [
                'contacts_anonymized' => $contacts->count(),
                'email_verifications_deleted' => $impact['email_verifications'],
                'opt_out_added' => true,
            ]);

            DB::commit();
            return ['ok' => true, 'impact' => $impact];
        } catch (\Throwable $e) {
            DB::rollBack();
            AuditLog::record('gdpr.erasure.failed', $req, ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function previewImpact(string $email, ?string $nameNorm): array
    {
        return [
            'contacts' => Contact::where('primary_email', $email)->orWhereRaw('full_name_normalized = ?', [$nameNorm])->count(),
            'email_verifications' => EmailVerification::where('email', $email)->count(),
            'companies_with_email' => CompanyEmail::where('email', $email)->count(),
            'scraper_runs' => ScraperRun::whereJsonContains('metadata->email', $email)->count(),
        ];
    }
}
```

### Export portabilité (accès / portabilité)

```php
class GdprPortabilityService
{
    public function export(GdprRequest $req): string
    {
        $email = $req->requester_email;

        $data = [
            'metadata' => [
                'request_id' => $req->id,
                'requester_email' => $email,
                'generated_at' => now()->toIso8601String(),
                'data_controller' => 'Axion-IA OÜ',
                'dpo_contact' => 'contact@axion-ia.com',
            ],
            'contacts' => Contact::where('primary_email', $email)->get()->toArray(),
            'email_verifications' => EmailVerification::where('email', $email)->get(),
            'company_emails' => CompanyEmail::where('email', $email)->get(),
            'opt_out' => OptOut::where('email', $email)->get(),
            'linkedin_searches' => LinkedinUrlSearch::whereIn('contact_id', $contactIds)->get(),
        ];

        $jsonPath = storage_path("app/gdpr_exports/{$req->id}.json");
        file_put_contents($jsonPath, json_encode($data, JSON_PRETTY_PRINT));

        // Encrypt before sending
        $encrypted = encrypt_file($jsonPath, $req->requester_email);  // ZIP avec password = empreinte SHA derived from email

        return $encrypted;
    }
}
```

### UI workflow (cf. `13_ui_admin_phase1.md` §14)

---

## §3 — Audit log hash chain

Cf. `15_auth_multitenant_rbac.md` § 6.

### Verification command

```bash
php artisan audit:verify-chain
# → Genesis → row 1 hash OK → row 2 hash OK → ... → row N
# Si tampering : log error + slack alert + halt
```

Job nightly `app:audit-chain-verify` → si erreur détecté, alerte critique + bloque écritures audit (uniquement reads autorisés).

---

## §4 — AI Act register (article 50, profilage)

### Use cases LLM profiling (Annexe III)

```sql
INSERT INTO ai_act_register (workspace_id, ai_system_name, use_case_slug, purpose, risk_category, is_profiling, human_oversight, accuracy_metrics, transparency_notice) VALUES

-- v1.1 : ia_maturity_scoring + axion_offer_match mergés en classify_company_axion
((SELECT id FROM workspaces WHERE slug='axion-ia'),
 'classify_company_axion',
 'classify_company_axion',
 'Estimer la maturité IA + match offre Axion-IA + priorité de prospection en 1 appel LLM (Haiku 4.5)',
 'limited',
 true,
 'Humain peut override scores et priorité via UI admin (champs priority_override + axion_offer_match_code editable). Algorithme transparent (LLM + prompt versionné). Logs des décisions tracés dans llm_usage + audit_logs.',
 '{"avg_human_override_rate":"15%","prompt_version":1}',
 'Score basé sur indices publics (site web, recrutements, signaux business) — pas de discrimination protégée. Profilage d''entité morale (entreprise), pas de personne physique sauf croisement avec contacts.'),

((SELECT id FROM workspaces WHERE slug='axion-ia'),
 'extract_team_from_page',
 'extract_team_from_page',
 'Extraire la liste des membres d''équipe depuis HTML scrapé (Direction Finder)',
 'limited',
 true,
 'LLM ne crée pas de scoring de personne, juste extraction structurée. Données vérifiables par retour à la source (page corporate publique).',
 '{}',
 'Extraction de données publiquement disponibles sur pages corporate'),

((SELECT id FROM workspaces WHERE slug='axion-ia'),
 'auto_tag_generation',
 'auto_tag_generation',
 'Génération auto de tags business pour catégorisation prospection',
 'minimal',
 false,
 'Tags suggérés modifiables manuellement. Complément des règles déterministes auto_tag_definitions.',
 '{}',
 'Catégorisation technique des fiches entreprises');

-- Note v1.1 : fiche_quality_scoring (anciennement listé) RETIRÉ.
-- Le scoring qualité 🟢/🟡/🔴 est calculé par la fonction SQL déterministe recompute_company_quality_score()
-- (cf. 03_db_schema_phase1.md § 11ter), donc PAS un système d'IA au sens AI Act.
```

### Transparency notice (UI)

Sur la fiche entreprise, mention :

> 🤖 **Profilage automatisé (AI Act)** : Cette fiche utilise des scores générés par IA (maturité IA, offre recommandée). Vous pouvez les contester ou les override via les champs ci-dessous. Détails : [DPO contact@axion-ia.com]

---

## §5 — Opt-out global cross-workspace

Cf. `03_db_schema_phase1.md` § 5 + `12_coverage_matrix_deduplication.md` § 2 niveau 6.

### Insertion automatique

- Hard bounce → trigger `trg_bounce_hard_to_optout`
- Unsubscribe click → trigger `trg_unsubscribe_to_optout`
- Spam complaint → manual ops
- CNIL request → exécution erasure

### Insertion manuelle

UI admin "Opt-out" → ajout par email/domaine/nom.

---

## §6 — Minimisation PII

### Champ par champ

| Champ | PII niveau | Justification | Action |
|-------|------------|---------------|--------|
| `users.email` | Direct | Auth obligatoire | OK, retention durée contrat |
| `contacts.primary_email` | Direct | Cold email | OK, base intérêt légitime B2B |
| `contacts.linkedin_url` | Direct | URL publique | OK |
| `audit_logs.ip_address` | Direct | Sécurité | Hash après 30j en `inet` masquant 4ᵉ octet IPv4 |
| `audit_logs.user_agent` | Indirect | Debug | Conservé 24 mois |
| `email_verifications.smtp_response` | Indirect | Debug | Purgé après 30j (sauf erreurs) |

### IP anonymization

Job nightly `app:anonymize-old-ips` :

```sql
UPDATE audit_logs
SET ip_address = host(network(ip_address::cidr, 24))::inet
WHERE created_at < now() - INTERVAL '30 days'
  AND family(ip_address) = 4;
```

---

## §7 — Chiffrement

### At rest

- Postgres : encryption at rest via Hetzner Volume (LUKS) — activé par défaut sur Hetzner Cloud
- Backups : pgbackrest avec encryption-key (AES-256-CBC)
- Secrets (TOTP, API keys) : `Crypt::encryptString()` Laravel (AES-256-GCM, key = `APP_KEY`)

### In transit

- TLS 1.3 obligatoire (Caddy + Cloudflare Full strict)
- HSTS preload 12 mois (après 3 mois de prod stable)
- Connexions intra-cluster Postgres : SSL forcé même intra-vSwitch
- Redis : `requirepass` + TLS si exposé (intra-vSwitch only par défaut)

---

## §8 — OWASP Top 10 (2021) — checklist

### A01:2021 — Broken Access Control

- ✅ RLS PostgreSQL (défense en profondeur)
- ✅ Middleware workspace check
- ✅ Policies Eloquent (Spatie Permission)
- ✅ Authorization explicite par route (`->middleware('can:...')`)
- ✅ Audit log de toute action sensible
- ✅ Test E2E "user A cannot access workspace B"

### A02:2021 — Cryptographic Failures

- ✅ TLS 1.3 forced + HSTS
- ✅ Bcrypt rounds 12 pour passwords
- ✅ TOTP secrets chiffrés (AES-256-GCM)
- ✅ Sessions HttpOnly + Secure + SameSite=lax
- ✅ APP_KEY rotation procedure documentée

### A03:2021 — Injection

- ✅ Eloquent ORM (parameterized queries)
- ✅ Spatie Query Builder (whitelisted filters)
- ✅ Validation Spatie Data (rules par DTO)
- ✅ Twig auto-escape `false` pour prompts (mais inputs sanitized upstream)
- ✅ Cheerio + DomCrawler (pas de innerHTML)

### A04:2021 — Insecure Design

- ✅ Threat modeling (cf. `22_risques_mitigations.md`)
- ✅ Rate limiting (login, scraping, LLM test)
- ✅ Cost cap (kill-switch LLM)
- ✅ Opt-out global avant scraping

### A05:2021 — Security Misconfiguration

- ✅ Debug `false` en prod (`APP_DEBUG=false`)
- ✅ Headers sécurité Caddy (HSTS, X-Frame-Options, CSP, etc.)
- ✅ CORS strict (origin = crm.axion-pro.com)
- ✅ Default Postgres user `axion_app` (NOT superuser)
- ✅ Secrets jamais commit Git (Doppler/Infisical)

### A06:2021 — Vulnerable & Outdated Components

- ✅ Dependabot weekly
- ✅ Trivy scan images Docker en CI
- ✅ Composer audit nightly
- ✅ pnpm audit nightly
- ✅ LTS versions (Node 22, PHP 8.3, Postgres 16)

### A07:2021 — Identification & Auth Failures

- ✅ 2FA TOTP obligatoire
- ✅ Brute force protection (5 fails → lock 15 min + fail2ban IP)
- ✅ HIBP password check
- ✅ Magic link token SHA-256 hashé, expire 15 min, single-use

### A08:2021 — Software & Data Integrity Failures

- ✅ CI/CD signed commits (gitsign Sigstore)
- ✅ Docker image content trust (DCT)
- ✅ Audit log hash chain (integrity vérifiable)

### A09:2021 — Security Logging & Monitoring Failures

- ✅ Logs structurés (JSON) → Loki 90j
- ✅ Audit log immuable (hash chain)
- ✅ Alertes critiques Slack + Telegram
- ✅ GlitchTip error tracking

### A10:2021 — Server-Side Request Forgery (SSRF) — durci P0 audit v1.1

> **Problème v1.0** : la spec disait "whitelist URLs" mais le scraping de sites web entreprises (source 8) prend l'URL depuis `companies.website_url` qui vient elle-même de Google Maps (source externe). Donc URL = *user-fed indirect*. Risque SSRF si Google Maps retourne `http://10.0.0.30:5432/...` ou `http://169.254.169.254/latest/meta-data/` (AWS metadata endpoint).

**Protection v1.1 — résolution DNS + check IP avant fetch :**

```typescript
// workers/src/scrapers/utils/ssrf-guard.ts
import { resolve4, resolve6 } from 'node:dns/promises'
import { isIP } from 'node:net'

const BLOCKED_CIDRS = [
  '10.0.0.0/8',         // RFC1918
  '172.16.0.0/12',      // RFC1918
  '192.168.0.0/16',     // RFC1918
  '127.0.0.0/8',        // loopback
  '169.254.0.0/16',     // link-local + cloud metadata (AWS/GCP/Azure)
  '100.64.0.0/10',      // shared address space CGNAT
  '0.0.0.0/8',          // current network
  '224.0.0.0/4',        // multicast
  '240.0.0.0/4',        // reserved
  '::1/128',            // IPv6 loopback
  'fc00::/7',           // IPv6 ULA
  'fe80::/10',          // IPv6 link-local
]

export async function ssrfGuard(url: string): Promise<{ allowed: boolean; reason?: string }> {
  let parsed: URL
  try { parsed = new URL(url) } catch { return { allowed: false, reason: 'invalid_url' } }

  if (!['http:', 'https:'].includes(parsed.protocol)) return { allowed: false, reason: 'bad_protocol' }

  // Bloquer IP littérale en hostname
  if (isIP(parsed.hostname)) {
    if (isBlockedIp(parsed.hostname)) return { allowed: false, reason: 'private_ip_literal' }
    // IP publique en littéral : suspect mais autorisé avec warning
  }

  // Résoudre le hostname
  let addresses: string[] = []
  try {
    const v4 = await resolve4(parsed.hostname).catch(() => [])
    const v6 = await resolve6(parsed.hostname).catch(() => [])
    addresses = [...v4, ...v6]
  } catch { return { allowed: false, reason: 'dns_failure' } }

  if (addresses.length === 0) return { allowed: false, reason: 'no_dns_resolution' }

  // Tous les A/AAAA records doivent être publics
  for (const ip of addresses) {
    if (isBlockedIp(ip)) return { allowed: false, reason: `private_ip_resolved:${ip}` }
  }

  return { allowed: true }
}

function isBlockedIp(ip: string): boolean {
  // Implémentation via lib 'ip-cidr' ou check manuel
  return BLOCKED_CIDRS.some(cidr => ipInCidr(ip, cidr))
}
```

**Usage obligatoire avant tout fetch externe non-API :**

```typescript
// Dans tous les scrapers sites web + Direction Finder
const guard = await ssrfGuard(targetUrl)
if (!guard.allowed) {
  logger.warn({ url: targetUrl, reason: guard.reason }, 'ssrf_blocked')
  Anomaly::create({ kind: 'ssrf_attempt_blocked', severity: 'warning', ... })
  return { status: 'failed', error: { code: 'ssrf_blocked', message: guard.reason } }
}
await page.goto(targetUrl, ...)
```

**Outbound firewall complémentaire :** Hetzner Cloud Firewall règles outbound bloquent le vSwitch privé `10.0.0.0/16` depuis les workers (sauf vers `10.0.0.30:6379/6380/5432/6432` pour Redis/Postgres et `10.0.0.20:80` pour endpoint /internal/scraper-result).

- ✅ Whitelist URLs scrapées (regex pattern matching)
- ✅ DNS resolution + IP check obligatoire avant fetch (P0 audit v1.1)
- ✅ Pas de fetch user-supplied URL côté serveur
- ✅ Outbound firewall Hetzner Cloud Firewall (only known providers + intra-vSwitch restreint)

---

## §9 — Content Security Policy

```caddyfile
# CSP strict pour console admin
header Content-Security-Policy "
  default-src 'self';
  script-src 'self' 'unsafe-inline' https://api.openfreemap.org;
  style-src 'self' 'unsafe-inline';
  img-src 'self' data: https://tiles.openfreemap.org;
  connect-src 'self' https://api.axion-pro.com https://tiles.openfreemap.org https://api-adresse.data.gouv.fr;
  frame-ancestors 'none';
  base-uri 'self';
  form-action 'self';
"
```

(L'`unsafe-inline` script reste un compromis tant que React 19 utilise des nonces complexes en SPA. À durcir avec nonces dès que possible.)

---

## §10 — Tests sécurité automatisés

### CI checks

```yaml
# .github/workflows/security.yml
jobs:
  semgrep:
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4
      - uses: returntocorp/semgrep-action@v1
        with: { config: 'p/owasp-top-ten p/laravel p/r2c-security-audit' }
  trivy:
    runs-on: ubuntu-24.04
    steps:
      - uses: aquasecurity/trivy-action@master
        with: { scan-type: fs, scanners: 'vuln,secret', severity: 'CRITICAL,HIGH' }
  composer_audit:
    runs-on: ubuntu-24.04
    steps:
      - run: composer audit
  pnpm_audit:
    runs-on: ubuntu-24.04
    steps:
      - run: pnpm audit --audit-level=high
```

### Annual penetration test

External penetration test annuel (budget ~5000 € HT, prestataire OSCP-certifié). Findings remediés < 60j.

---

## §11 — Plan d'incident sécurité

### Niveaux

- **P0** : breach confirmé (DB dump exfiltré, credentials leak, RCE)
- **P1** : suspicion forte (anomalie traffic, MFA bypass attempt)
- **P2** : vulnérabilité critique CVE dans dépendance utilisée

### Procédure P0

1. **0-15 min** : isolation infra (Cloudflare maintenance mode, firewall lock)
2. **15-60 min** : audit hash chain, snapshot logs, identification scope
3. **1-4 h** : notification interne + DPO + avocat
4. **4-72 h** : notification CNIL (article 33 RGPD)
5. **72 h+** : remédiation + post-mortem + amélioration

---

## §12 — Cookies & tracking

Console admin **interne** = pas de cookies tracking marketing. Uniquement :
- Session cookie (Laravel)
- CSRF cookie (Sanctum)
- Pref cookie (theme, lang, expanded sidebar)

Aucun consent banner nécessaire (cookies fonctionnels uniquement, exemption RGPD).

---

## Lecture suivante

→ `18_deploiement_hetzner.md` (infra IPs + docker-compose + GH Actions + backups + DR).
