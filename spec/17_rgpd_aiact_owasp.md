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

((SELECT id FROM workspaces WHERE slug='axion-ia'),
 'ia_maturity_scoring',
 'ia_maturity_scoring',
 'Estimer la maturité IA d''une entreprise pour prioriser la prospection',
 'limited',
 true,
 'Humain peut override scores via UI admin. Algorithme transparent (LLM Haiku 4.5 + prompt versionné). Logs des décisions tracés.',
 '{"avg_human_override_rate":"15%","prompt_version":3}',
 'Score basé sur indices publics (site web, recrutements, signaux) — pas de discrimination protégée'),

((SELECT id FROM workspaces WHERE slug='axion-ia'),
 'fiche_quality_scoring',
 'fiche_quality_scoring',
 'Classifier les fiches entreprises en complète/partielle/basique',
 'minimal',
 false,
 'Score calculé par formule SQL déterministe sur données collectées. Pas de profilage personne.',
 '{}',
 'Score qualité technique de la fiche'),

((SELECT id FROM workspaces WHERE slug='axion-ia'),
 'axion_offer_match',
 'axion_offer_match',
 'Recommander une offre Axion-IA selon profil entreprise',
 'limited',
 true,
 'Humain peut override match. Recommendation indicative, pas décisionnelle.',
 '{}',
 'Recommandation B2B, pas profilage individuel');
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

### A10:2021 — Server-Side Request Forgery (SSRF)

- ✅ Whitelist URLs scrapées (regex pattern matching)
- ✅ Pas de fetch user-supplied URL côté serveur (les seules requests sortantes sont via proxies whitelistés)
- ✅ Outbound firewall (Hetzner Cloud Firewall, only known providers)

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
