# Patches P0 — Bloquants production

> Cible : 0 P0 ouvert avant GO PROD. Liste prioritisée par ordre d'impact.

## P0-1 — CSP `unsafe-inline` exploitable XSS

**Fichier :** `infra/caddy/Caddyfile:29` + `infra/nginx/frontend.conf:41`
**Problème :** `script-src 'self' 'unsafe-inline'` → XSS reflected → session hijack.
**Correction :** remplacer `'unsafe-inline'` par `'strict-dynamic' 'nonce-{request-id}'`
côté Caddy avec template directive, et ajouter le nonce dans les scripts inline Vite via
`vite-plugin-csp-guard` ou similar. **À défaut** : générer le bundle SANS scripts inline
(Vite déjà OK en build, mais Tailwind 4 + sonner peuvent injecter du style/script inline →
publier les CSS extraits seuls).
**Effort :** 4-6 h
**Risque non corrigé :** session hijack en 1 XSS

## P0-2 — SSRF côté Playwright workers Node

**Fichier :** `workers/src/scrapers/website.playwright.ts:22`
**Problème :** `await page.goto(req.target_url, ...)` sans validation. URL malicieuse
peut accéder `169.254.169.254` (AWS/GCP metadata).
**Correction :**
```ts
import { ssrfGuard } from '../utils/ssrf-guard';
// ...
async scrape(req: ScrapeRequestJob) {
  await ssrfGuard(req.target_url);  // throw si IP privée
  // ...
}
```
Créer `workers/src/utils/ssrf-guard.ts` équivalent au `SsrfGuard.php` PHP (DENY_CIDR +
DNS A/AAAA check).
**Effort :** 3 h
**Risque non corrigé :** exfiltration metadata cloud + scan interne réseau Hetzner

## P0-3 — Email finder absent du Waterfall (étape 7)

**Fichier :** `backend/app/Services/Waterfall/WaterfallOrchestrator.php` — pas de méthode
`step7_email_finder()`.
**Problème :** Phase 1 doit produire des contacts avec emails validés (badge 🟢 complète exige
email validé ≥70). Sans étape 7, aucun contact n'aura jamais d'email → quality_score plafonné.
**Correction :** Ajouter dans `enrich()` :
```php
$this->step7_email_finder($company);
```
Implémenter `step7` qui itère sur les contacts du company avec last_name présent, appelle
`EmailFinderService::find($firstName, $lastName, $domain)`, met à jour
`contacts.email + email_status + email_score`.
**Effort :** 6 h
**Risque non corrigé :** Phase 1 livre 0 contacts qualifiés cold-email-ready

## P0-4 — Couverture tests catastrophique

**Fichier :** `backend/tests/`, `frontend/tests/`, `workers/tests/`
**Problème :** 14 tests Pest + 1 Vitest + 0 workers + 4 describe E2E = ~5 % couverture.
Cible spec ≥ 280 tests, on est à 19. Déploiement aveugle → regression spiral.
**Correction prioritaire (top 20 tests critiques) :**
- `tests/Feature/Auth/LoginTest.php` (5 tests : success, wrong_pw, locked, throttle, 2fa_required)
- `tests/Feature/CompaniesTest.php` (5 tests : index_paginated, store_validates_siren,
  enrich_calls_waterfall, RLS_cross_workspace_blocked, bulk_enrich_max_500)
- `tests/Feature/RgpdTest.php` (4 tests : erasure_atomic, portability_token, export_expired,
  cascade_optout)
- `tests/Feature/AuditChainTest.php` (3 tests : insert_chain, verify_valid, verify_tampered)
- `tests/Feature/SsrfGuardTest.php` (3 tests : blocks_aws_metadata, blocks_rfc1918, allows_public)
**Effort :** 12-16 h
**Risque non corrigé :** régression silencieuse en prod

## P0-5 — CompaniesListPage non virtualisée

**Fichier :** `frontend/src/features/companies/CompaniesListPage.tsx:102-130`
**Problème :** `<table>` HTML brute. À 50k entreprises (cible volume mois 1 = 200k traitées),
OOM browser garanti.
**Correction :** utiliser `@tanstack/react-virtual` (déjà déclarée dans package.json mais
non utilisée) avec `useVirtualizer({ count, getScrollElement, estimateSize })`. Garder
pagination serveur per_page=50 pour limiter payload.
**Effort :** 3 h
**Risque non corrigé :** crash UI à 10k+ rows

## P0-6 — AuthController.login sans RateLimiter

**Fichier :** `backend/app/Http/Controllers/Api/Auth/AuthController.php:13`
**Problème :** Le throttle est dans `AuthService::attemptLogin` via `RateLimiter::tooManyAttempts`
mais après `LoginRequest` validation. Brute-force réaliste possible (5 req/s = 18000/h)
avant que lock 24h ne tombe (à 10 fails).
**Correction :** ajouter `->middleware('throttle:5,1')` dans `routes/api.php:42` :
```php
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
```
**Effort :** 30 min
**Risque non corrigé :** brute-force credentials

## P0-7 — Aucune validation runtime exécutée

**Problème :** L'autopilot a livré 22 commits sans `composer install`, `pnpm install`,
`docker compose up`, `migrate:fresh`, `composer test` exécutés. Des bugs syntax/import/SQL
peuvent rester.
**Correction :** Will doit lancer `_REPORTS/VALIDATION_PLAN.md` 8 niveaux séquentiels.
**Effort :** 4 h (Will)
**Risque non corrigé :** déploiement avec bugs runtime non détectés

## Récap

7 P0 totaux. Effort total estimé : **30-35 h** (~ 1 sprint 5 j).
