# Phase 6 — Sécurité forensique (OWASP Top 10)

> Sub-agent Explore brut : **72 / 100**.

## Synthèse OWASP

| Item | Statut | Détails |
|------|--------|---------|
| A01 Broken Access Control | ✅ | RLS 27 tables + NULLIF edge case + workspace policies Eloquent |
| A02 Cryptographic Failures | 🟡 | bcrypt 12 ✅, TOTP encrypted ✅, MAIS `SESSION_SECURE_COOKIE=false` par défaut |
| A03 Injection | 🟡 | Eloquent ORM ✅, validation Spatie Data, MAIS `sanitizeExternalInputs` regex faible |
| A04 Insecure Design | ✅ | Rate limiting (magic-link 3/10min), cost cap LLM, opt-out global, dedup 6 niveaux |
| A05 Security Misconfiguration | 🟡 | CSP **avec `unsafe-inline'`** dans Caddyfile — XSS reflected possible |
| A06 Vulnerable Components | ❌ | Pas de Dependabot, pas de `composer audit` / `pnpm audit` automatisés CI |
| A07 Auth Failures | 🟡 | 2FA ✅, throttle magic-link ✅, **MAIS pas de throttle sur AuthController.login** |
| A08 Software & Data Integrity | ✅ | Audit hash chain SHA-256 + verifyChain() |
| A09 Logging Failures | ✅ | Monolog stack stderr + Loki + GlitchTip configurés |
| A10 SSRF | 🟡 | SsrfGuard côté PHP ✅ + 5 clients HTTP, **MAIS workers Node Playwright sans guard** |

## Findings spécifiques

### Forces

1. **SsrfGuard.php complet** — DENY_HOSTS (5 metadata cloud) + DENY_CIDR (10/8, 172.16/12,
   192.168/16, 127/8, 169.254/16, 100.64/10, multicast), DNS A+AAAA check, fail-closed.
2. **AuditHashChain** — sha256(prev || canonical || secret), verifyChain itère cursor PG.
3. **GdprErasureService transaction atomique** — 5 tables (contacts, email_validations,
   rgpd_requests, notifications, magic_links) + audit + opt-out cascade.
4. **GdprPortabilityService** — Crypt::encryptString AES-256, token sha256 hashé, TTL 7j.
5. **2FA TOTP RFC 6238** — pragmarx/google2fa + 10 recovery codes hashés bcrypt + cast
   `encrypted:array`.

### Faiblesses critiques

1. **CSP `script-src 'unsafe-inline'`** — Caddyfile L29 + nginx/frontend.conf L41. XSS reflected
   → session hijacking direct.
2. **website.playwright.ts:21** — `new URL(req.target_url)` sans validation SSRF côté Node.
   AWS metadata 169.254.169.254 accessible.
3. **AuthController.login** sans RateLimiter — brute-force 1000 attempts/min possible
   (locked_until 24h après 10 fails = mitigation partielle mais lente).
4. **`SESSION_SECURE_COOKIE=false`** par défaut .env — dépend prod env vars, pas hardcoded.
5. **HIBP password check** documenté dans OwnerUserSeeder mais **pas implémenté**.

### P0 bloquants prod

1. **CSP `unsafe-inline`** — exploit XSS direct si une injection passe la validation Spatie Data.
2. **SSRF côté Playwright workers** — exfiltration AWS metadata possible si scrape URL malveillante.
3. **AuthController login sans throttle** — brute-force réaliste avant le lock 24h.

## Score Phase 6 : **72 / 100** (pondéré ×1.5 → 108/150)
